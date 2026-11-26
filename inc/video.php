<?php
/**
 * inc/video.php — the Video tool (Nikki, 2026-11-26: "is it possible to
 * include video in our Admin and Agent social templates so we can build
 * video stories and reels? ... Let's start with Admin-only for right now"),
 * super admin only, a page of its own so the picture templates are not
 * touched. On the Social Media Plan's foundation (inc/plan.php: the gate,
 * the listings in the feed, the brokers, the photo tiles and the photo
 * check), with its own drawing (inc/video_render.php) and its own export
 * (inc/video_export.php: Chromium for the layers, ffmpeg for the rest).
 *
 *   template_video.php  the page (inc/video_body.php): the preview at the
 *                       left, the controls at the right
 *   video_render.php    POST → the preview, one page that plays the video
 *   video_export.php    POST → starts the MP4 in the background, JSON {job};
 *                       GET ?job= → JSON progress; GET ?job=&file=1 → the MP4
 *   video_clip.php      an uploaded clip: POST its bytes in pieces, GET it
 *                       back for the preview, POST delete= to remove it
 *   cron/video_job.php  the worker one export runs in (started by
 *                       video_job_start(), never by cron)
 *
 * Two kinds of video: `photos` (a listing's photos, each moving slowly, with
 * cross-fades) and `clip` (one uploaded video cut to the frame). Both carry
 * the logo, the words and an optional closing card.
 *
 * WHY A BACKGROUND JOB: a video takes one to several minutes and the box has
 * under 1 GB of memory, so the page never waits on the request. The export
 * request writes job.json and starts the worker; the page asks for progress
 * every two seconds. One video is made at a time (a lock in the worker).
 *
 * Everything is kept under CREATIVES_DIR/video/admin-<user id>/: jobs/<id>/
 * (job.json, status.json, out.mp4, the logs; a week) and clips/ (the
 * uploads; thirty days).
 */
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/ads.php';            // the brokers' phones, the address slug
require_once __DIR__ . '/video_export.php';   // includes video_render.php

const VIDEO_CLIP_MAX_BYTES = 600 * 1024 * 1024;   // one uploaded clip
const VIDEO_CHUNK_BYTES    = 4 * 1024 * 1024;     // a piece of an upload (PHP's post_max_size here is 8M)
const VIDEO_JOB_DAYS  = 7;
const VIDEO_CLIP_DAYS = 30;

/** This person's video folder (made when missing), or '' when CREATIVES_DIR is not set up. */
function video_root(array $ctx, string $sub = ''): string {
    if (!defined('CREATIVES_DIR')) return '';
    $dir = rtrim(CREATIVES_DIR, '/') . '/video/admin-' . (int)$ctx['user']['id'] . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) { if (!@mkdir($dir, 02775, true)) return ''; social_open_dirs($dir); }
    return $dir;
}

// ── Uploaded clips ───────────────────────────────────────────────────────────

/** A clip's id is 16 hex characters, nothing else. */
function video_clip_id_ok(string $id): bool { return (bool)preg_match('/^[a-f0-9]{16}$/', $id); }

/** One uploaded clip: ['id', 'file', 'name', 'duration', 'w', 'h', 'audio', 'hdr', 'bytes'], or null. */
function video_clip(array $ctx, string $id): ?array {
    if (!video_clip_id_ok($id) || ($dir = video_root($ctx, 'clips')) === '') return null;
    $file = "{$dir}/{$id}.clip";
    $meta = is_file("{$dir}/{$id}.json") ? json_decode((string)file_get_contents("{$dir}/{$id}.json"), true) : null;
    if (!is_file($file) || !is_array($meta)) return null;
    return ['id' => $id, 'file' => $file, 'bytes' => (int)filesize($file)] + $meta;
}

/** Every clip this person has uploaded, newest first; ones past VIDEO_CLIP_DAYS and abandoned uploads are removed on the way. */
function video_clips(array $ctx): array {
    if (($dir = video_root($ctx, 'clips')) === '') return [];
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $j) {
        $id = basename($j, '.json');
        if (filemtime($j) < time() - VIDEO_CLIP_DAYS * 86400) { video_clip_delete($ctx, $id); continue; }
        if ($c = video_clip($ctx, $id)) $out[$id] = $c + ['at' => filemtime($j)];
    }
    foreach (glob($dir . '/*.part') ?: [] as $p) if (filemtime($p) < time() - 86400) @unlink($p);
    uasort($out, fn($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

function video_clip_delete(array $ctx, string $id): void {
    if (!video_clip_id_ok($id) || ($dir = video_root($ctx, 'clips')) === '') return;
    foreach (['clip', 'json', 'part'] as $ext) @unlink("{$dir}/{$id}.{$ext}");
}

/**
 * One piece of an upload appended to <id>.part; the piece must begin where
 * the file ends, so a repeated or lost piece is refused, not written twice.
 * On the last piece the file is checked with ffprobe and kept, or thrown
 * away. ['ok' => true, 'done' => bool, 'clip' => {...} on the last] or
 * ['error' => sentence].
 */
function video_clip_chunk(array $ctx, string $id, int $offset, bool $last, string $name, string $bytes): array {
    if (!video_clip_id_ok($id) || ($dir = video_root($ctx, 'clips')) === '') return ['error' => 'The upload could not start. Reload the page and try again.'];
    $part = "{$dir}/{$id}.part";
    $have = is_file($part) ? (int)filesize($part) : 0;
    if ($offset !== $have) return ['error' => 'The upload lost its place. Please choose the file again.'];
    if ($have + strlen($bytes) > VIDEO_CLIP_MAX_BYTES) { @unlink($part); return ['error' => 'That file is over ' . (int)(VIDEO_CLIP_MAX_BYTES / 1048576) . ' MB. Trim it on your phone or computer first.']; }
    if (strlen($bytes) > VIDEO_CHUNK_BYTES + 1024 || file_put_contents($part, $bytes, FILE_APPEND | LOCK_EX) === false) return ['error' => 'A piece of the upload could not be saved. Please try again.'];
    if (!$last) return ['ok' => true, 'done' => false];
    $p = video_probe($part);
    if (!$p) { @unlink($part); return ['error' => 'That file is not a video this tool can read. MP4 and MOV from a phone or camera work.']; }
    if ($p['duration'] < VIDEO_CLIP_MIN_SECONDS) { @unlink($part); return ['error' => 'That clip is under ' . VIDEO_CLIP_MIN_SECONDS . ' seconds: too short to make a video from.']; }
    rename($part, "{$dir}/{$id}.clip"); @chmod("{$dir}/{$id}.clip", 0664);
    $name = mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', ' ', $name)), 0, 80) ?: 'Clip';
    file_put_contents("{$dir}/{$id}.json", json_encode($p + ['name' => $name])); @chmod("{$dir}/{$id}.json", 0664);
    return ['ok' => true, 'done' => true, 'clip' => video_clip_public(video_clip($ctx, $id))];
}

/** What the page is told about a clip (never its path). */
function video_clip_public(array $c): array {
    return ['id' => $c['id'], 'name' => $c['name'], 'duration' => $c['duration'], 'w' => $c['w'], 'h' => $c['h'], 'audio' => $c['audio'],
            'text' => $c['name'] . '  ·  ' . video_seconds_text((float)$c['duration']) . '  ·  ' . $c['w'] . ' × ' . $c['h'] . '  ·  ' . video_bytes_text((int)$c['bytes'])];
}

/** "0:42". */
function video_seconds_text(float $s): string { $s = (int)round($s); return intdiv($s, 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT); }

// ── The form ─────────────────────────────────────────────────────────────────

/**
 * The form → the normalised video (inc/video_render.php), or a sentence
 * saying what is missing. Every posted value is checked: the listing is one
 * in the feed, each photo one the site listed for it or one of this
 * person's uploads (plan_photo()), the clip one of this person's, the broker
 * one on the approved roster.
 */
function video_from_request(mysqli $conn, array $ctx, array $in): array|string {
    $kind = ($in['kind'] ?? 'photos') === 'clip' ? 'clip' : 'photos';
    $listing = plan_listing($conn, (string)($in['listing'] ?? ''));
    $one = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($in[$k] ?? ''))), 0, $max);

    $v = ['kind' => $kind, 'photos' => [], 'clip' => null];
    if ($kind === 'photos') {
        if (!$listing) return 'Choose a listing to see the video.';
        foreach (array_slice(array_values(array_filter(array_map('strval', (array)($in['photos'] ?? [])), 'strlen')), 0, VIDEO_MAX_PHOTOS) as $id) {
            [$url, $file] = plan_photo($ctx, $id, $listing);
            // A board photo has no file here: the preview takes the site's 1600 copy, the export its 2048 (the board's own file).
            $v['photos'][] = [$url, $file, $file ? '' : str_replace('&w=1600', '&w=2048', $url)];
        }
        if (!$v['photos']) return 'Pick at least one photo. Click the photos in the order they should play.';
        $hold = (string)($in['hold'] ?? '3');
        $v['hold'] = isset(video_holds()[$hold]) ? (float)$hold : 3.0;
        $v['motion'] = isset(video_motions()[(string)($in['motion'] ?? '')]) ? (string)$in['motion'] : 'mix';
    } else {
        $c = video_clip($ctx, (string)($in['clip'] ?? ''));
        if (!$c) return 'Upload a video clip, or choose one you uploaded before.';
        $start = max(0.0, min((float)$c['duration'] - VIDEO_CLIP_MIN_SECONDS, round((float)($in['clip_start'] ?? 0), 1)));
        $room = (float)$c['duration'] - $start - 0.2;   // never ask for frames the file does not have
        $len = max(VIDEO_CLIP_MIN_SECONDS, min(VIDEO_CLIP_MAX_SECONDS, $room, round((float)($in['clip_length'] ?? 15), 1)));
        if ($len > $room) return 'That clip is too short from that starting point. Start it earlier.';
        $v['clip'] = ['id' => $c['id'], 'file' => $c['file'], 'start' => $start, 'length' => $len, 'fit' => ($in['clip_fit'] ?? 'fill') === 'blur' ? 'blur' : 'fill',
                      'audio' => !empty($c['audio']), 'mute' => !empty($in['clip_mute']), 'hdr' => !empty($c['hdr']), 'w' => (int)$c['w'], 'h' => (int)$c['h']];
    }

    $v['label'] = $one('label', 28);
    $v['headline'] = mb_substr(trim(implode("\n", array_slice(array_map('trim', explode("\n", str_replace("\r", '', (string)($in['headline'] ?? '')))), 0, 2))), 0, 70);
    $v['lines'] = [$one('line1', 60), $one('line2', 60), $one('line3', 60)];
    $v['words_show'] = isset(video_words_shows()[(string)($in['words_show'] ?? '')]) ? (string)$in['words_show'] : 'open';

    $v['end'] = !empty($in['end']);
    $v['end_address'] = '';
    $v['agent'] = null;
    if ($v['end']) {
        if ($listing && trim((string)$listing['address']) !== '') $v['end_address'] = social_short_address((string)$listing['address']) . ($listing['town'] !== '' ? "\n" . $listing['town'] : '');
        $a = plan_agents($conn)[(int)($in['agent'] ?? 0)] ?? null;
        if ($a) {
            $u = $a['face_url'] !== '' ? $a['face_url'] : $a['headshot_url'];
            $f = $u !== '' ? mk_photo_file($u) : '';
            $v['agent'] = ['name' => $a['name'], 'phone' => ads_agent_phones($conn)[$a['id']] ?? '', 'headshot_url' => $u,
                           'headshot_file' => ($f !== '' && is_file($f)) ? (realpath($f) ?: $f) : null];
        }
    }
    $v['name'] = 'MH_' . ($listing && trim((string)$listing['address']) !== '' ? ads_slug((string)$listing['address']) : ($kind === 'clip' ? 'clip' : 'listing')) . '_video';
    return $v;
}

// ── The job ──────────────────────────────────────────────────────────────────

function video_job_id_ok(string $id): bool { return (bool)preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id); }

/** A job's folder, or '' when it is not this person's or does not exist. */
function video_job_dir(array $ctx, string $id): string {
    if (!video_job_id_ok($id) || ($root = video_root($ctx, 'jobs')) === '') return '';
    return is_dir("{$root}/{$id}") ? "{$root}/{$id}" : '';
}

/** Write a job's status so a reader never sees half a file. */
function video_status_write(string $dir, array $status): void {
    $tmp = "{$dir}/status.json." . getmypid();
    if (file_put_contents($tmp, json_encode($status + ['updated' => time()])) !== false) { @chmod($tmp, 0664); rename($tmp, "{$dir}/status.json"); }
}

/**
 * Start a video in the background: the folder, job.json, and the worker,
 * detached from this request. The job's id, or null. Jobs older than
 * VIDEO_JOB_DAYS are removed first.
 */
function video_job_start(array $ctx, array $v): ?string {
    if (($root = video_root($ctx, 'jobs')) === '') return null;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $old) {
        if (!video_job_id_ok(basename($old)) || filemtime($old) > time() - VIDEO_JOB_DAYS * 86400) continue;
        foreach (glob($old . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
        video_tidy($old); @rmdir($old);
    }
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $dir = "{$root}/{$id}";
    if (!@mkdir($dir, 02775)) return null;
    @chmod($dir, 02775);
    if (file_put_contents("{$dir}/job.json", json_encode($v, JSON_UNESCAPED_SLASHES)) === false) return null;
    video_status_write($dir, ['state' => 'queued', 'step' => 'Starting', 'pct' => 0, 'pid' => 0]);
    $worker = realpath(__DIR__ . '/../cron/video_job.php');
    exec('nohup /usr/bin/php ' . escapeshellarg((string)$worker) . ' ' . escapeshellarg($dir) . ' > ' . escapeshellarg("{$dir}/job.log") . ' 2>&1 &');
    return $id;
}

/**
 * Where a job stands, for the page: ['state' => queued|running|done|failed,
 * 'step', 'pct', and when done 'name', 'size', 'seconds'; when failed
 * 'error']. A worker that is gone without finishing (the box ran out of
 * memory, Apache restarted) reads as failed. Null when there is no such job.
 */
function video_job_status(array $ctx, string $id): ?array {
    if (($dir = video_job_dir($ctx, $id)) === '') return null;
    $s = json_decode((string)@file_get_contents("{$dir}/status.json"), true);
    if (!is_array($s)) return null;
    $state = (string)($s['state'] ?? 'queued');
    if (in_array($state, ['queued', 'running'], true)) {
        $pid = (int)($s['pid'] ?? 0);
        $gone = $pid > 0 ? !is_dir('/proc/' . $pid) : (time() - (int)($s['updated'] ?? 0) > 30);   // no pid yet: the worker never started
        if ($gone) {
            clearstatcache();
            $s2 = json_decode((string)@file_get_contents("{$dir}/status.json"), true);   // it may have finished between the two looks
            if (is_array($s2) && in_array((string)($s2['state'] ?? ''), ['done', 'failed'], true)) $s = $s2;
            else $s = ['state' => 'failed', 'error' => 'The video stopped before it was finished (the server was busy). Please try again in a minute.'];
            $state = $s['state'];
        }
    }
    $out = ['state' => $state, 'step' => (string)($s['step'] ?? ''), 'pct' => (int)($s['pct'] ?? 0)];
    if ($state === 'failed') $out['error'] = (string)($s['error'] ?? 'The video could not be made. Please try again in a minute.');
    if ($state === 'done' && is_file("{$dir}/out.mp4")) {
        $out += ['name' => (string)($s['name'] ?? 'MH_video') . '.mp4', 'size' => video_bytes_text((int)filesize("{$dir}/out.mp4")), 'seconds' => (float)($s['seconds'] ?? 0), 'took' => (int)($s['took'] ?? 0)];
    }
    return $out;
}

/**
 * A file sent with byte ranges honoured, which a <video> needs to seek (and
 * Safari needs to play at all). $download = a file name → sent as a download.
 */
function video_send_file(string $path, string $mime, ?string $download = null): never {
    $size = (int)filesize($path); $from = 0; $to = $size - 1;
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex');
    if ($download !== null) header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $download) . '"');
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $from = max(0, $size - (int)$m[2]); } else { $from = (int)$m[1]; if ($m[2] !== '') $to = min($to, (int)$m[2]); }
        if ($from > $to || $from >= $size) { http_response_code(416); header("Content-Range: bytes */{$size}"); exit; }
        http_response_code(206);
        header("Content-Range: bytes {$from}-{$to}/{$size}");
    }
    header('Content-Length: ' . ($to - $from + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    session_write_close();   // a long download must not hold the session: the page keeps asking for progress
    set_time_limit(0);
    $fh = fopen($path, 'rb'); fseek($fh, $from); $left = $to - $from + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) { $buf = fread($fh, (int)min(1048576, $left)); if ($buf === false) break; echo $buf; flush(); $left -= strlen($buf); }
    fclose($fh);
    exit;
}
