<?php
/**
 * inc/ads_export.php — the digital ads' exports (prototype, 2026-11-09),
 * standalone (no portal.php, no database) so tests/render_ads_sample.php
 * can make a real GIF on the server: headless Chromium screenshots each
 * slide (the Instagram export's command with the ad's window size and a
 * device scale factor), Imagick quantises the frames, sets the hold, loops
 * for ever and trims each frame to what changed; or writes the slides as 2x
 * JPGs into a ZIP for Photoshop. Needs inc/ads_render.php.
 */
require_once __DIR__ . '/ads_render.php';

if (!defined('SOCIAL_CHROMIUM')) define('SOCIAL_CHROMIUM', '/usr/bin/chromium');
const ADS_SLIDE_SCALE = 2;   // the Photoshop slides: 2x (1940 x 500 for the marquee)

/** The GIF colour steps tried, in order, until the file fits ADS_GIF_MAX. */
function ads_color_steps(): array { return [256, 192, 128, 96, 64]; }

/**
 * One slide screenshotted by headless Chromium at $scale device pixels per
 * CSS pixel (1 for the GIF, 2 for the Photoshop slides); the Instagram
 * export's command (social_export_png()) with the ad's window size. Same
 * lock, so one Chromium runs at a time on the box.
 */
function ads_export_png(string $html, int $w, int $h, int $scale = 1): ?string {
    $dir = rtrim(sys_get_temp_dir(), '/') . '/mh-social';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { error_log('ads_export: cannot create ' . $dir); return null; }
    $id  = bin2hex(random_bytes(8));
    $in  = "{$dir}/{$id}.html";
    $out = "{$dir}/{$id}.png";
    if (file_put_contents($in, $html) === false) { error_log('ads_export: cannot write ' . $in); return null; }
    $lock = fopen("{$dir}/.lock", 'c');
    if ($lock) flock($lock, LOCK_EX);
    set_time_limit(120);
    $cmd = 'HOME=' . escapeshellarg($dir) . ' /usr/bin/timeout 60 ' . escapeshellarg(SOCIAL_CHROMIUM)
         . ' --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --no-first-run'
         . ' --disable-extensions --mute-audio --force-device-scale-factor=' . (int)$scale
         . ' --window-size=' . (int)$w . ',' . (int)$h . ' --virtual-time-budget=10000'
         . ' --user-data-dir=' . escapeshellarg("{$dir}/profile")
         . ' --screenshot=' . escapeshellarg($out) . ' ' . escapeshellarg('file://' . $in) . ' 2>&1';
    exec($cmd, $lines, $rc);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    $png = is_file($out) ? file_get_contents($out) : false;
    @unlink($in);
    @unlink($out);
    if ($png === false || $png === '') {
        error_log('ads_export: chromium rc=' . $rc . ' ' . implode(' | ', array_slice($lines, -3)));
        return null;
    }
    return $png;
}

/** Every slide as a PNG at $scale, in play order; null when any failed. */
function ads_frame_pngs(array $ad, int $scale = 1): ?array {
    $S = ads_size($ad['size']);
    $out = [];
    set_time_limit(300);
    foreach ($ad['frames'] as $i => $f) {
        $png = ads_export_png(ads_render_frame($ad, $i, null, true), $S['w'], $S['h'], $scale);
        if ($png === null) return null;
        $out[] = $png;
    }
    return $out;
}

/**
 * The PNG frames as one animated GIF: each frame quantised to $colors (with
 * dither), held $hold seconds, the loop endless, the frames trimmed to what
 * changed. Null when Imagick is missing or fails.
 */
function ads_build_gif(array $pngs, float $hold, int $colors): ?string {
    if (!class_exists('Imagick')) { error_log('ads_export: Imagick is not available'); return null; }
    try {
        $gif = new Imagick();
        $delay = max(10, (int)round($hold * 100));   // 1/100 s
        foreach ($pngs as $png) {
            $f = new Imagick();
            $f->readImageBlob($png);
            $f->setImageFormat('gif');
            $f->quantizeImage(max(8, min(256, $colors)), Imagick::COLORSPACE_RGB, 0, true, false);
            $f->setImageDelay($delay);
            $f->setImageIterations(0);
            $gif->addImage($f);
            $f->clear();
        }
        $gif->setFormat('gif');
        $opt = $gif->optimizeImageLayers();
        $opt->setFormat('gif');
        $opt->setFirstIterator();
        $opt->setImageIterations(0);
        $bytes = $opt->getImagesBlob();
        $opt->clear(); $gif->clear();
        return $bytes !== '' ? $bytes : null;
    } catch (Throwable $e) {
        error_log('ads_export: imagick ' . $e->getMessage());
        return null;
    }
}

/**
 * The ad as a GIF under ADS_GIF_MAX where possible: the frames once, then
 * each colour step until it fits. ['gif' => bytes, 'bytes' => n, 'colors' =>
 * n, 'fits' => bool, 'frames' => n], or null when the frames could not be made.
 */
function ads_export_gif(array $ad): ?array {
    $pngs = ads_frame_pngs($ad, 1);
    if ($pngs === null) return null;
    $best = null;
    foreach (ads_color_steps() as $colors) {
        $gif = ads_build_gif($pngs, (float)$ad['hold'], $colors);
        if ($gif === null) return null;
        $best = ['gif' => $gif, 'bytes' => strlen($gif), 'colors' => $colors, 'fits' => strlen($gif) <= ADS_GIF_MAX, 'frames' => count($pngs)];
        if ($best['fits']) break;
    }
    return $best;
}

/**
 * The slides as a ZIP of JPGs at ADS_SLIDE_SCALE (quality 92, no metadata),
 * named {name}_01.jpg …, for Photoshop. Null when a frame failed or Imagick
 * or ZipArchive is missing.
 */
function ads_export_slides_zip(array $ad): ?string {
    if (!class_exists('ZipArchive')) { error_log('ads_export: ZipArchive is not available'); return null; }
    if (!class_exists('Imagick')) { error_log('ads_export: Imagick is not available'); return null; }
    $pngs = ads_frame_pngs($ad, ADS_SLIDE_SCALE);
    if ($pngs === null) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'mh-ad-');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
    try {
        foreach ($pngs as $i => $png) {
            $im = new Imagick();
            $im->readImageBlob($png);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(92);
            $im->stripImage();
            $zip->addFromString($ad['name'] . '_' . sprintf('%02d', $i + 1) . '.jpg', $im->getImageBlob());
            $im->clear();
        }
    } catch (Throwable $e) {
        error_log('ads_export: imagick jpeg ' . $e->getMessage());
        $zip->close(); @unlink($tmp); return null;
    }
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bytes !== '' ? $bytes : null;
}

/** "VD_1100-N-Frontage-Rd-W-1513_970x250.gif", or "…_slides-2x.zip". */
function ads_filename(array $ad, string $ext, string $suffix = ''): string {
    return $ad['name'] . ($suffix !== '' ? '_' . $suffix : '') . '.' . $ext;
}

/** "612 KB" / "1.02 MB". */
function ads_bytes_text(int $n): string {
    return $n >= 1000000 ? number_format($n / 1000000, 2) . ' MB' : number_format($n / 1000) . ' KB';
}
