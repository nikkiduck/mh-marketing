<?php
/**
 * inc/video_export.php — the Video tool's MP4 (2026-11-26), standalone (no
 * portal.php, no database) so the background worker (cron/video_job.php) and
 * tests/render_video_sample.php run it from the command line. Needs
 * inc/video_render.php, Chromium (the layers) and ffmpeg (Debian's package,
 * installed 2026-11-26 with Nikki's go-ahead).
 *
 * The server has under 1 GB of memory and no swap, and that shapes all of
 * this. A single ffmpeg command that cross-fades five photos buffers the
 * later ones while it waits for them and was killed at 680 MB on the first
 * try. So the video is made in small pieces, each well under 350 MB:
 *
 *   1. each layer and the closing card: a transparent PNG from Chromium;
 *   2. each segment by itself (a moving photo, the clip cut to shape, the
 *      closing card) as an all-keyframe working file, seg-N.mkv;
 *   3. each cross-fade by itself, from the last frames of one segment and
 *      the first of the next, x-N.mkv;
 *   4. the pieces read in order through ffmpeg's concat list (segment minus
 *      its faded ends, cross-fade, segment ...), the layers faded over them,
 *      the sound added: the one quality encode, out.mp4.
 *
 * Do not fold these back into one command. One video is made at a time
 * (the worker's lock); the working files are deleted at the end.
 */
require_once __DIR__ . '/video_render.php';

if (!defined('SOCIAL_CHROMIUM')) define('SOCIAL_CHROMIUM', '/usr/bin/chromium');
const VIDEO_FFMPEG  = '/usr/bin/ffmpeg';
const VIDEO_FFPROBE = '/usr/bin/ffprobe';
const VIDEO_CLIP_MAX_SECONDS = 60;   // the longest stretch of a clip one video takes
const VIDEO_CLIP_MIN_SECONDS = 3;

/** The working files' encode: fast, near lossless, every frame a keyframe so a piece can be cut anywhere. */
function video_work_args(): array {
    return ['-an', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '12', '-g', '1', '-pix_fmt', 'yuv420p', '-threads', '2'];
}

/** Run one ffmpeg command (arguments, not a shell string), its output appended to $log. True when it succeeded. */
function video_ffmpeg(array $args, string $log, int $timeout = 600): bool {
    $cmd = '/usr/bin/timeout ' . (int)$timeout . ' /usr/bin/nice -n 10 ' . escapeshellarg(VIDEO_FFMPEG) . ' -hide_banner -loglevel error -nostdin -y';
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    @file_put_contents($log, "\n$ " . $cmd . "\n", FILE_APPEND);
    exec($cmd . ' >> ' . escapeshellarg($log) . ' 2>&1', $o, $rc);
    if ($rc !== 0) @file_put_contents($log, "[exit {$rc}]\n", FILE_APPEND);
    return $rc === 0;
}

/**
 * What a video file is: ['duration' => seconds, 'w', 'h' (as it plays, a
 * phone's rotation applied), 'audio' => bool, 'hdr' => bool], or null when
 * ffprobe cannot read a picture in it.
 */
function video_probe(string $file): ?array {
    exec('/usr/bin/timeout 30 ' . escapeshellarg(VIDEO_FFPROBE) . ' -v error -print_format json -show_format -show_streams ' . escapeshellarg($file) . ' 2>/dev/null', $o, $rc);
    $j = $rc === 0 ? json_decode(implode("\n", $o), true) : null;
    if (!is_array($j)) return null;
    $vs = null; $audio = false;
    foreach ((array)($j['streams'] ?? []) as $s) {
        if (($s['codec_type'] ?? '') === 'video' && !$vs && empty($s['disposition']['attached_pic'])) $vs = $s;
        if (($s['codec_type'] ?? '') === 'audio') $audio = true;
    }
    if (!$vs) return null;
    $w = (int)($vs['width'] ?? 0); $h = (int)($vs['height'] ?? 0);
    $rot = (int)($vs['tags']['rotate'] ?? 0);
    foreach ((array)($vs['side_data_list'] ?? []) as $sd) if (isset($sd['rotation'])) $rot = (int)$sd['rotation'];
    if (abs($rot) % 180 === 90) [$w, $h] = [$h, $w];
    $dur = (float)($j['format']['duration'] ?? ($vs['duration'] ?? 0));
    if ($w < 16 || $h < 16 || $dur <= 0) return null;
    return ['duration' => round($dur, 2), 'w' => $w, 'h' => $h, 'audio' => $audio,
            'hdr' => in_array((string)($vs['color_transfer'] ?? ''), ['arib-std-b67', 'smpte2084'], true)];
}

/**
 * A page screenshotted by headless Chromium at 1080 x 1920 on a TRANSPARENT
 * ground (the layers) into $png. $dir is Chromium's home and profile: the
 * worker runs from the command line, where the web exports' /tmp/mh-social
 * is not its own.
 */
function video_shot(string $html, string $png, string $dir): bool {
    $in = $dir . '/shot-' . bin2hex(random_bytes(4)) . '.html';
    if (file_put_contents($in, $html) === false) return false;
    $cmd = 'HOME=' . escapeshellarg($dir) . ' /usr/bin/timeout 60 ' . escapeshellarg(SOCIAL_CHROMIUM)
         . ' --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --no-first-run'
         . ' --disable-extensions --mute-audio --force-device-scale-factor=1 --default-background-color=00000000'
         . ' --window-size=' . VIDEO_W . ',' . VIDEO_H . ' --virtual-time-budget=10000'
         . ' --user-data-dir=' . escapeshellarg($dir . '/chromium')
         . ' --screenshot=' . escapeshellarg($png) . ' ' . escapeshellarg('file://' . $in) . ' 2>&1';
    exec($cmd, $lines, $rc);
    @unlink($in);
    if (!is_file($png) || filesize($png) < 100) { error_log('video_shot: chromium rc=' . $rc . ' ' . implode(' | ', array_slice($lines, -3))); return false; }
    return true;
}

/** A photo brought into the working folder: a file on disk copied, a URL downloaded. The path, or null. */
function video_fetch_photo(array $photo, string $to): ?string {
    [$url, $file] = [($photo[2] ?? '') !== '' ? $photo[2] : ($photo[0] ?? ''), $photo[1] ?? null];   // [preview url, file on disk, export url]
    if ($file && is_file($file)) { if (!@copy($file, $to)) return null; }
    else {
        if (!preg_match('~^https://~', (string)$url)) return null;
        $ch = curl_init($url);
        $fh = fopen($to, 'w');
        if (!$fh) return null;
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'MH-Marketing-Video/1.0']);
        $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch); fclose($fh);
        if (!$ok || $code !== 200) { @unlink($to); return null; }
    }
    $info = @getimagesize($to);
    return ($info && $info[0] > 0 && $info[1] > 0) ? $to : null;
}

/** The filter that turns one still into a moving segment of $F frames. */
function video_photo_filter(array $m, int $F): string {
    $W = VIDEO_W; $H = VIDEO_H; $W2 = $W * 2; $H2 = $H * 2; $fps = VIDEO_FPS;
    $last = max(1, $F - 1);
    $tail = "scale=out_color_matrix=bt709:out_range=tv,format=yuv420p";
    if ($m['type'] === 'pan') {
        $t2 = video_pan_travel($F) * 2; $max = VIDEO_PAN_MAX;
        // A drift of video_pan_travel() centered on the photo, never more than VIDEO_PAN_MAX of its spare width. The photo at twice the frame's height (never wider than two heights), held, a window slid across it, then halved: the half pixels keep a slow pan smooth.
        return "crop='min(iw,ih*2)':ih,scale=-2:{$H2}:flags=bicubic,format=gbrp,loop=loop={$F}:size=1:start=0,setpts=N/({$fps}*TB),"
             . "crop={$W2}:{$H2}:x='(iw-{$W2})/2+({$m['dir']})*min({$t2},{$max}*(iw-{$W2}))*(n/{$last}-0.5)':y=0,scale={$W}:{$H}:flags=bicubic,fps={$fps},{$tail}";
    }
    $d = $m['to'] - $m['from'];
    return "scale={$W2}:{$H2}:force_original_aspect_ratio=increase:flags=bicubic,crop={$W2}:{$H2},format=gbrp,"
         . "zoompan=z='{$m['from']}+({$d})*on/{$last}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$F}:s={$W}x{$H}:fps={$fps},{$tail}";
}

/** The filter that cuts a clip to the frame: filled (cropped), or whole on a blurred copy of itself. HDR (an iPhone's) is brought to ordinary color first. */
function video_clip_filter(array $clip): string {
    $W = VIDEO_W; $H = VIDEO_H; $fps = VIDEO_FPS;
    $sdr = !empty($clip['hdr']) ? "zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,tonemap=tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p," : '';
    if (($clip['fit'] ?? 'fill') === 'blur') {
        return "fps={$fps},scale={$W}:{$H}:force_original_aspect_ratio=decrease:flags=bicubic,{$sdr}split[fg][b0];"
             . "[b0]scale=270:480:force_original_aspect_ratio=increase,crop=270:480,boxblur=12:2,eq=brightness=-0.10,scale={$W}:{$H}:flags=bilinear[bg];"
             . "[bg][fg]overlay=(W-w)/2:(H-h)/2,scale=out_color_matrix=bt709:out_range=tv,format=yuv420p";
    }
    return "fps={$fps},scale={$W}:{$H}:force_original_aspect_ratio=increase:flags=bicubic,crop={$W}:{$H},{$sdr}scale=out_color_matrix=bt709:out_range=tv,format=yuv420p";
}

/**
 * The whole video into $dir/out.mp4. $say(text, percent) is told each step.
 * ['ok' => true, 'file', 'frames', 'seconds'] or ['ok' => false, 'error'
 * (a sentence for the page)]. The detail of a failure is in $dir/ffmpeg.log.
 */
function video_build(array $v, string $dir, ?callable $say = null): array {
    $say = $say ?? function (string $t, int $p): void {};
    $log = $dir . '/ffmpeg.log';
    $fail = fn(string $why) => ['ok' => false, 'error' => $why];
    $T = video_timeline($v);
    $segs = $T['segs']; $n = count($segs); $fps = VIDEO_FPS; $XF = VIDEO_XF;
    $isClip = ($v['kind'] ?? 'photos') === 'clip';
    if (!$n) return $fail('There is nothing in the video yet.');

    // 1. The layers and the closing card.
    $say('Drawing the text and the logo', 2);
    $layers = video_layers($v);
    foreach (array_merge($layers, !empty($v['end']) ? ['end'] : []) as $key) {
        if (!video_shot(video_layer_document($v, $key), "{$dir}/layer-{$key}.png", $dir)) return $fail('The text could not be drawn. Please try again in a minute.');
    }

    // 2. Each segment by itself.
    $work = video_work_args();
    foreach ($segs as $k => $s) {
        $F = $s['frames']; $out = "{$dir}/seg-{$k}.mkv";
        $pct = 6 + (int)round(54 * $k / $n);
        if ($s['type'] === 'photo') {
            $say('Photo ' . ($s['i'] + 1) . ' of ' . count($v['photos']), $pct);
            $p = video_fetch_photo($v['photos'][$s['i']], "{$dir}/photo-{$s['i']}.img");
            if (!$p) return $fail('Photo ' . ($s['i'] + 1) . ' could not be read. Take it out, or pick another, and try again.');
            $info = getimagesize($p);
            $m = video_motion((string)($v['motion'] ?? 'mix'), $s['i'], video_is_wide((int)$info[0], (int)$info[1]));
            $ok = video_ffmpeg(array_merge(['-i', $p, '-vf', video_photo_filter($m, $F), '-frames:v', $F], $work, [$out]), $log);
            @unlink($p);
        } elseif ($s['type'] === 'clip') {
            $say('Cutting the clip to shape', $pct);
            $c = $v['clip'];
            $ok = video_ffmpeg(array_merge(['-ss', sprintf('%.3f', (float)$c['start']), '-t', sprintf('%.3f', $F / $fps + 0.5), '-i', $c['file'],
                                            '-filter_complex', '[0:v:0]' . video_clip_filter($c) . '[v]', '-map', '[v]', '-frames:v', $F], $work, [$out]), $log, 900);
        } else {
            $say('The closing card', $pct);
            $ok = video_ffmpeg(array_merge(['-i', "{$dir}/layer-end.png", '-vf',
                "format=gbrp,loop=loop={$F}:size=1:start=0,setpts=N/({$fps}*TB),fps={$fps},scale=out_color_matrix=bt709:out_range=tv,format=yuv420p", '-frames:v', $F], $work, [$out]), $log);
        }
        if (!$ok || !is_file($out)) return $fail($s['type'] === 'clip' ? 'The clip could not be read as video. Try another file.' : 'A piece of the video could not be made. Please try again in a minute.');
    }

    // 3. Each cross-fade by itself: the last frames of one segment into the first of the next.
    $eps = 0.004;   // a seek lands just ahead of the frame it wants
    for ($k = 0; $k < $n - 1; $k++) {
        $say('Blending', 60 + (int)round(8 * $k / max(1, $n - 1)));
        $FA = $segs[$k]['frames'];
        $ok = video_ffmpeg(array_merge(['-ss', sprintf('%.4f', ($FA - $XF) / $fps - $eps), '-i', "{$dir}/seg-{$k}.mkv", '-i', "{$dir}/seg-" . ($k + 1) . ".mkv", '-filter_complex',
            "[0:v]trim=end_frame={$XF},setpts=N/({$fps}*TB)[a];[1:v]trim=end_frame={$XF},setpts=N/({$fps}*TB)[b];[a][b]xfade=transition=fade:duration=" . sprintf('%.4f', $XF / $fps) . ":offset=0,format=yuv420p",
            '-frames:v', $XF], $work, ["{$dir}/x-{$k}.mkv"]), $log);
        if (!$ok) return $fail('A piece of the video could not be made. Please try again in a minute.');
    }

    // 4. The pieces in order, the layers over them, the sound: the one quality encode.
    $list = '';
    foreach ($segs as $k => $s) {
        $from = $k > 0 ? $XF : 0; $to = $k < $n - 1 ? $s['frames'] - $XF : $s['frames'];
        $list .= "file 'seg-{$k}.mkv'\n";
        if ($from > 0) $list .= sprintf("inpoint %.4f\n", $from / $fps - $eps);
        if ($to < $s['frames']) $list .= sprintf("outpoint %.4f\n", $to / $fps - $eps);
        $list .= sprintf("duration %.6f\n", ($to - $from) / $fps);
        if ($k < $n - 1) $list .= "file 'x-{$k}.mkv'\n" . sprintf("duration %.6f\n", $XF / $fps);
    }
    file_put_contents("{$dir}/list.txt", $list);
    $total = $T['total']; $secs = $total / $fps;
    $args = ['-f', 'concat', '-safe', '0', '-i', "{$dir}/list.txt"];
    $graph = "[0:v]setpts=N/({$fps}*TB)[b0]"; $last = 'b0'; $in = 1;
    foreach ($layers as $key) {
        [$a, $b] = $T['layers'][$key];
        $args[] = '-i'; $args[] = "{$dir}/layer-{$key}.png";
        $fade = "fade=t=in:st={$a}:d=" . VIDEO_FADE . ":alpha=1" . ($b !== null ? ",fade=t=out:st=" . round($b, 3) . ":d=" . VIDEO_FADE . ":alpha=1" : '');
        $until = $b !== null ? round($b + VIDEO_FADE, 3) : round($secs + 1, 3);
        $graph .= ";[{$in}:v]format=yuva420p,loop=loop=-1:size=1:start=0,setpts=N/({$fps}*TB),{$fade}[l{$in}];[{$last}][l{$in}]overlay=0:0:shortest=1:enable='between(t,{$a},{$until})'[b{$in}]";
        $last = "b{$in}"; $in++;
    }
    // The sound: the clip's own, faded out as the closing card arrives; else silence (some apps refuse a video with no sound track).
    if ($isClip && !empty($v['clip']['audio']) && empty($v['clip']['mute'])) {
        $c = $v['clip']; $len = $segs[0]['frames'] / $fps;
        array_push($args, '-ss', sprintf('%.3f', (float)$c['start']), '-t', sprintf('%.3f', $len), '-i', $c['file']);
        $graph .= ";[{$in}:a:0]aresample=48000,afade=t=in:st=0:d=0.2,afade=t=out:st=" . round($len - $XF / $fps, 3) . ":d=" . round($XF / $fps, 3) . ",apad[a]";
    } else {
        array_push($args, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo');
        $graph .= ";[{$in}:a]anull[a]";
    }
    $say('Finishing the video', 70);
    $out = "{$dir}/out.mp4";
    $ok = video_ffmpeg(array_merge($args, ['-filter_complex', $graph, '-map', "[{$last}]", '-map', '[a]', '-t', sprintf('%.4f', $secs),
        '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', $fps, '-x264-params', 'rc-lookahead=10',
        '-maxrate', '10M', '-bufsize', '20M', '-colorspace', 'bt709', '-color_primaries', 'bt709', '-color_trc', 'bt709',
        '-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-movflags', '+faststart', '-threads', '2', $out]), $log, 1200);
    video_tidy($dir);
    if (!$ok || !is_file($out) || filesize($out) < 1000) return $fail('The video could not be finished. Please try again in a minute.');
    return ['ok' => true, 'file' => $out, 'frames' => $total, 'seconds' => round($secs, 1)];
}

/** The working files gone: everything in the folder but the video, its record and its log. */
function video_tidy(string $dir): void {
    foreach (glob($dir . '/{seg-*.mkv,x-*.mkv,layer-*.png,photo-*.img,list.txt,shot-*.html}', GLOB_BRACE) ?: [] as $f) @unlink($f);
    $rm = function (string $d) use (&$rm): void {
        foreach (@scandir($d) ?: [] as $f) { if ($f === '.' || $f === '..') continue; $p = "$d/$f"; (is_dir($p) && !is_link($p)) ? $rm($p) : @unlink($p); }
        @rmdir($d);
    };
    foreach (['chromium', '.cache', '.config', '.local', '.pki'] as $d) if (is_dir("{$dir}/{$d}")) $rm("{$dir}/{$d}");   // Chromium's profile and what it leaves in its home
}

/** "12.4 MB". */
function video_bytes_text(int $n): string {
    return $n >= 1000000 ? number_format($n / 1000000, 1) . ' MB' : number_format($n / 1000) . ' KB';
}
