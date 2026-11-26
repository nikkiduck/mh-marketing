<?php
/**
 * tests/render_video_sample.php — makes a sample video with the Video tool's
 * own code, for design work and to check the server. No database, no
 * sign-in: inc/video_export.php alone. Run it ON THE SERVER (Chromium and
 * ffmpeg), as any user, into a folder of its own:
 *
 *   php tests/render_video_sample.php <outdir> [--photos=N] [--motion=mix|zoom|pan] [--all] [--no-end] [--captions[=all]] [--clip=/path/to/clip.mp4] [--blur] [--html]
 *
 * Writes <outdir>/out.mp4 (and preview.html, the page's preview, whose
 * photos load from the live portal), then prints the frame count against the
 * timeline's, the seconds it took and the file's size. The photos are the
 * first N of the stock library on disk (5 unless said). --clip makes the
 * uploaded-clip kind from a video file instead; --blur sets it whole on a
 * blurred copy of itself. --html writes the preview only.
 */
require __DIR__ . '/../inc/video_export.php';

$flags = []; $out = '';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) { [$k, $val] = array_pad(explode('=', substr($a, 2), 2), 2, '1'); $flags[$k] = $val; }
    else $out = rtrim($a, '/');
}
if ($out === '' || (!is_dir($out) && !mkdir($out, 0775, true))) { fwrite(STDERR, "usage: php tests/render_video_sample.php <outdir> [flags]\n"); exit(1); }
$base = 'https://marketing.monthaus.com';

$stock = array_slice(glob(__DIR__ . '/../assets/stock/*.jpg') ?: [], 0, max(1, min(VIDEO_MAX_PHOTOS, (int)($flags['photos'] ?? 5))));
$v = [
    'kind' => 'photos', 'hold' => 3, 'motion' => (string)($flags['motion'] ?? 'mix'),
    'photos' => array_map(fn($f) => [$base . '/assets/stock/' . basename($f), realpath($f)], $stock),
    'label' => 'New Listing', 'headline' => "970 Powder Lane", 'lines' => ['Aspen, Colorado', '$58,000,000', '9 Beds | 12 Baths | 9,768 SF'],
    'words_show' => isset($flags['all']) ? 'all' : 'open',
    'end' => !isset($flags['no-end']), 'end_address' => "970 Powder Lane\nAspen, Colorado",
    'agent' => ['name' => 'Jonathan Boxer', 'phone' => '970.948.4802', 'headshot_url' => $base . '/agent-photos/jonathan-boxer-30-square.jpg',
                'headshot_file' => realpath(__DIR__ . '/../agent-photos/jonathan-boxer-30-square.jpg') ?: null],
    'name' => 'MH_sample-video',
];
// --captions: a text of its own on every photo but the first (--captions=all: the first too, which drops the opening text).
if (isset($flags['captions'])) {
    $own = ['Morning light on the ridge', "Four minutes\nto the gondola", 'Room for everyone, and for the friends who turn up for the weekend', 'The view from the kitchen', 'Yours this winter'];
    foreach (array_keys($v['photos']) as $i) $v['captions'][$i] = ($i === 0 && $flags['captions'] !== 'all') ? '' : $own[$i % count($own)];
}
if (isset($flags['clip'])) {
    $p = video_probe((string)$flags['clip']);
    if (!$p) { fwrite(STDERR, "not a video: {$flags['clip']}\n"); exit(1); }
    echo 'clip: ' . json_encode($p) . "\n";
    $v['kind'] = 'clip'; $v['photos'] = [];
    $v['clip'] = ['file' => realpath((string)$flags['clip']), 'start' => 0, 'length' => min(12, $p['duration'] - 0.2), 'fit' => isset($flags['blur']) ? 'blur' : 'fill',
                  'audio' => $p['audio'], 'hdr' => $p['hdr'], 'w' => $p['w'], 'h' => $p['h']];
}
file_put_contents("$out/preview.html", video_preview_html($v, $base, $v['kind'] === 'clip' ? basename((string)$flags['clip']) : ''));
if (isset($flags['html'])) { echo "preview.html written\n"; exit(0); }

$t0 = microtime(true);
$r = video_build($v, $out, function (string $t, int $p) use ($t0) { printf("  %5.1fs %3d%%  %s\n", microtime(true) - $t0, $p, $t); });
if (!$r['ok']) { echo "FAILED: {$r['error']}\n" . implode('', array_slice(file("$out/ffmpeg.log") ?: [], -12)); exit(1); }
$probe = video_probe($r['file']);
exec(escapeshellarg(VIDEO_FFPROBE) . ' -v error -count_frames -select_streams v:0 -show_entries stream=nb_read_frames -of csv=p=0 ' . escapeshellarg($r['file']), $o);
printf("ok: %s, %s, %dx%d, %.2fs, frames %d (timeline %d), sound %s, made in %.0fs\n", $r['file'], video_bytes_text((int)filesize($r['file'])), $probe['w'], $probe['h'],
       $probe['duration'], (int)($o[0] ?? 0), $r['frames'], $probe['audio'] ? 'yes' : 'no', microtime(true) - $t0);
exit((int)($o[0] ?? 0) === $r['frames'] ? 0 : 2);
