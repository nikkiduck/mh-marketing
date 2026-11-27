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

// ── Team logos (the quick version, Nikki 2026-11-26) ─────────────────────────
//
// A team (the site's list, mk_site_teams(): Weber Boxer Group, Coates Horn
// Team) can stand in Presented by, with its logo where a headshot goes. The
// logo is uploaded on the Video page and kept, one a team, in
// CREATIVES_DIR/video/team-logos/{key}.{ext} beside {key}.json
// ({ext, dark}). `dark` is said by the person uploading, never worked out
// from the picture: a dark logo is shown in white on the card. This store is
// a stopgap for a Teams screen that would hold a team's logo once for every
// template; move it there when that is built.

const VIDEO_LOGO_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
const VIDEO_LOGO_MAX_BYTES = 2 * 1048576;

function video_team(string $key): ?array {
    foreach (mk_site_teams() as $t) if ($t['key'] === $key) return $t;
    return null;
}

function video_team_logo_dir(bool $make = false): string {
    if (!defined('CREATIVES_DIR')) return '';
    $dir = rtrim(CREATIVES_DIR, '/') . '/video/team-logos';
    if (!is_dir($dir) && (!$make || !@mkdir($dir, 02775, true))) return '';
    return $dir;
}

/** A team's logo: ['file', 'ext', 'dark', 'v' (changes when the file does)], or null. */
function video_team_logo(string $key): ?array {
    if (!video_team($key) || ($dir = video_team_logo_dir()) === '') return null;
    $m = is_readable("{$dir}/{$key}.json") ? json_decode((string)file_get_contents("{$dir}/{$key}.json"), true) : null;
    $ext = is_array($m) ? (string)($m['ext'] ?? '') : '';
    if (!isset(VIDEO_LOGO_TYPES[$ext]) || !is_file("{$dir}/{$key}.{$ext}")) return null;
    return ['file' => "{$dir}/{$key}.{$ext}", 'ext' => $ext, 'dark' => !empty($m['dark']), 'v' => (int)filemtime("{$dir}/{$key}.{$ext}")];
}

/** Keep an uploaded logo ($_FILES entry) for a team. ['ok' => true] or ['error' => sentence]. */
function video_team_logo_store(string $key, array $up, bool $dark): array {
    if (!video_team($key)) return ['error' => 'Choose a team first.'];
    if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($up['tmp_name'] ?? ''))) {
        return ['error' => in_array($up['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large: a logo can be up to 2 MB.' : 'The logo did not arrive. Please try again.'];
    }
    if ((int)$up['size'] > VIDEO_LOGO_MAX_BYTES) return ['error' => 'That file is too large: a logo can be up to 2 MB.'];
    $tmp = (string)$up['tmp_name'];
    $info = @getimagesize($tmp);
    $ext = $info ? ([IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? '') : '';
    if ($ext === '') {
        // An SVG: text that opens as an svg and carries nothing that runs.
        $text = (string)file_get_contents($tmp);
        if (preg_match('/<svg[\s>]/i', substr($text, 0, 4000)) && !preg_match('/<script|\bon[a-z]+\s*=|javascript:|<foreignObject|<iframe/i', $text)) $ext = 'svg';
    }
    if ($ext === '') return ['error' => 'That file is not a logo this tool can use. PNG, JPG and SVG work.'];
    if (($dir = video_team_logo_dir(true)) === '') return ['error' => 'The logo could not be saved. Please try again.'];
    video_team_logo_remove($key);
    if (!move_uploaded_file($tmp, "{$dir}/{$key}.{$ext}")) return ['error' => 'The logo could not be saved. Please try again.'];
    @chmod("{$dir}/{$key}.{$ext}", 0664);
    file_put_contents("{$dir}/{$key}.json", json_encode(['ext' => $ext, 'dark' => $dark])); @chmod("{$dir}/{$key}.json", 0664);
    return ['ok' => true];
}

function video_team_logo_remove(string $key): void {
    if (!video_team($key) || ($dir = video_team_logo_dir()) === '') return;
    foreach (array_keys(VIDEO_LOGO_TYPES) as $ext) @unlink("{$dir}/{$key}.{$ext}");
    @unlink("{$dir}/{$key}.json");
}

/**
 * Who a listing is presented by, as the form's two dropdowns take it:
 * ['t:{team key}' | '{agent id}' | '0', same]. A team the listing names
 * stands alone; otherwise our brokers it names, in the order named, two at
 * most. (By name, as ads_agent_for_listing(): plan_listings() has already
 * cut the agent words down to our own side.)
 */
function video_listing_presenters(array $l, array $agents): array {
    $words = strtolower((string)($l['agent'] ?? ''));
    if ($words === '') return ['0', '0'];
    foreach (mk_site_teams() as $t) if (str_contains($words, strtolower($t['name']))) return ['t:' . $t['key'], '0'];
    $found = [];
    foreach ($agents as $a) { $at = strpos($words, strtolower($a['name'])); if ($at !== false) $found[(int)$a['id']] = $at; }
    asort($found);
    $ids = array_map('strval', array_slice(array_keys($found), 0, 2));
    return [$ids[0] ?? '0', $ids[1] ?? '0'];
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
    // The form's three sources (Nikki, 2026-11-26): listing (a listing's photos), mine (My Photos: the person's uploads
    // and the stock library, no listing needed) and clip. The first two are both the `photos` kind of video.
    $src = (string)($in['kind'] ?? 'listing');
    $kind = $src === 'clip' ? 'clip' : 'photos';
    $mine = $src === 'mine';
    $listing = plan_listing($conn, (string)($in['listing'] ?? ''));
    $one = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($in[$k] ?? ''))), 0, $max);

    $v = ['kind' => $kind, 'photos' => [], 'clip' => null];
    if ($kind === 'photos') {
        if (!$mine && !$listing) return 'Choose a listing to see the video.';
        // captions[] and zooms[] run beside photos[]: each photo's own text (three rows at most, blank for none), its own zoom (100 to 200) and whether it shows the logo (1 or 0).
        $ids = array_values(array_map('strval', (array)($in['photos'] ?? [])));
        $texts = array_values(array_map('strval', (array)($in['captions'] ?? [])));
        $zooms = array_values((array)($in['zooms'] ?? []));
        $logos = array_values((array)($in['logos'] ?? []));
        $v['captions'] = []; $v['zooms'] = []; $v['logos'] = [];
        foreach ($ids as $n => $id) {
            if ($id === '' || count($v['photos']) >= VIDEO_MAX_PHOTOS) continue;
            // My Photos takes an upload of this person's or a stock photo and nothing else; a listing may be chosen there only to fill the text in.
            if ($mine && !(str_starts_with($id, 'upload:') ? social_upload_file($ctx, substr($id, 7)) : (str_starts_with($id, 'stock:') ? social_stock_file(substr($id, 6)) : null))) continue;
            [$url, $file] = plan_photo($ctx, $id, $mine ? null : $listing);
            // A board photo has no file here: the preview takes the site's 1600 copy, the export its 2048 (the board's own file).
            $v['photos'][] = [$url, $file, $file ? '' : str_replace('&w=1600', '&w=2048', $url)];
            $rows = array_slice(array_values(array_filter(array_map(fn($r) => trim(preg_replace('/\s+/', ' ', $r)), explode("\n", str_replace("\r", '', $texts[$n] ?? ''))), 'strlen')), 0, 3);
            $v['captions'][] = mb_substr(implode("\n", $rows), 0, 140);
            $v['zooms'][] = max(1.0, min(2.0, round((float)($zooms[$n] ?? 100) / 100, 2)));
            $v['logos'][] = !empty($logos[$n]);
        }
        if (!$v['photos']) return 'Pick at least one photo. Click the photos in the order they should play.';
        $hold = (string)($in['hold'] ?? '3');
        $v['hold'] = isset(video_holds()[$hold]) ? (float)$hold : 3.0;
        $ch = (string)($in['cover_hold'] ?? '');
        $v['cover_hold'] = isset(video_card_holds()[$ch]) ? (float)$ch : VIDEO_COVER_SECONDS;
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
    // The cover text is on the cover alone, or kept for the whole video: one tick on the form, beside the photos' own text
    // boxes (which it hides) or, for a clip, in the clip's section.
    $v['words_show'] = !empty($in[$kind === 'clip' ? 'words_all_clip' : 'words_all']) ? 'all' : 'open';
    foreach (['logo_kind', 'mark', 'badge', 'badge_color'] as $k) $v[$k] = (string)($in[$k] ?? '');
    $v = array_merge($v, video_look($v));
    // The logo's size, nudge and shadow: the picture templates' sliders (the size a percentage, 130 to start).
    $v['logo_scale'] = round((int)($in['logo_scale'] ?? 130) / 100, 2);
    $v['logo_dx'] = (int)($in['logo_dx'] ?? 0); $v['logo_dy'] = (int)($in['logo_dy'] ?? 0); $v['logo_shadow'] = (int)($in['logo_shadow'] ?? 0);

    // The closing card. Everything on it is what the form holds (Nikki,
    // 2026-11-26: "make the contact details editable, as well as the choice
    // for headshot or no headshot"): the page fills the boxes from the listing
    // and the broker picked, and a cleared box leaves that part off, so a card
    // can be left bare for Instagram's own stickers. The broker picked only
    // supplies the headshot here.
    $v['end'] = !empty($in['end']);
    $eh = (string)($in['end_hold'] ?? '');
    $v['end_hold'] = isset(video_card_holds()[$eh]) ? (float)$eh : VIDEO_END_SECONDS;
    $v['end_address'] = '';
    $v['end_site'] = '';
    $v['end_extra'] = '';
    $v['agent'] = null;
    $v['agent2'] = null;
    if ($v['end']) {
        $v['end_address'] = mb_substr(trim(implode("\n", array_slice(array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string)($in['end_address'] ?? '')))), 'strlen')), 0, 2))), 0, 90);
        $v['end_site'] = $one('end_site', 40);
        $v['end_extra'] = $one('end_extra', 50);
        // Up to two presenters (Nikki, 2026-11-26: "the option for 2 agents ... Jackson Horn and Kim Coates"). Each is a
        // broker on the roster or a team ('t:{key}'); the choice supplies only the picture (a square headshot, or the
        // team's logo), the name and phone are what the boxes hold.
        $shots = !empty($in['end_headshot']);
        $who = function (string $pick, string $name, string $phone) use ($conn, $shots): ?array {
            $by = ['name' => $name, 'phone' => $phone, 'headshot_url' => '', 'headshot_file' => null, 'logo' => false, 'dark' => false];
            if ($shots && str_starts_with($pick, 't:')) {
                $key = substr($pick, 2);
                if ($lg = video_team_logo($key)) $by = ['headshot_url' => 'video_team_logo.php?team=' . rawurlencode($key) . '&v=' . $lg['v'], 'headshot_file' => $lg['file'], 'logo' => true, 'dark' => $lg['dark']] + $by;
            } elseif ($shots && ($a = plan_agents($conn)[(int)$pick] ?? null)) {
                $u = $a['face_url'] !== '' ? $a['face_url'] : $a['headshot_url'];
                $f = $u !== '' ? mk_photo_file($u) : '';
                $by['headshot_url'] = $u;
                $by['headshot_file'] = ($f !== '' && is_file($f)) ? (realpath($f) ?: $f) : null;
            }
            return ($by['name'] !== '' || $by['phone'] !== '' || $by['headshot_url'] !== '') ? $by : null;
        };
        $both = array_values(array_filter([$who((string)($in['agent'] ?? '0'), $one('end_name', 50), $one('end_phone', 40)),
                                           $who((string)($in['agent2'] ?? '0'), $one('end_name2', 50), $one('end_phone2', 40))]));
        $v['agent'] = $both[0] ?? null;
        $v['agent2'] = $both[1] ?? null;
    }
    $v['name'] = 'MH_' . ($listing && trim((string)$listing['address']) !== '' ? ads_slug((string)$listing['address']) : ($kind === 'clip' ? 'clip' : ($mine ? 'photos' : 'listing'))) . '_video';
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
