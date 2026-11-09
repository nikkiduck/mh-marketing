<?php
/**
 * ad_export.php — a digital ad as a download. POST (the builder form, with
 * csrf_token): `export` = gif → the animated GIF (Chromium frame by frame,
 * Imagick; under 1 MB where it can, see inc/ads.php); `export` = slides →
 * a ZIP of the slides as 2x JPGs for Photoshop. `probe` = 1 → JSON {bytes,
 * colors, frames, fits, text} with what the GIF would weigh, for the size
 * readout, nothing downloaded. A copy of each download is kept under
 * CREATIVES_DIR/social/admin-<user id>/. Super admin only.
 */
require_once __DIR__ . '/inc/ads.php';

$ctx = plan_gate($conn);
$probe = !empty($_POST['probe']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    if ($probe) { header('Content-Type: application/json'); echo json_encode(['error' => 'Please reload the page.']); exit; }
    portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and download again.');
}
$ad = ads_from_request($conn, $ctx, $_POST);
if (is_string($ad)) {
    if ($probe) { header('Content-Type: application/json'); echo json_encode(['error' => $ad]); exit; }
    portal_stop('Nothing to download yet', ph($ad));
}

if ($probe) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $g = ads_export_gif($ad);
    if ($g === null) { echo json_encode(['error' => 'The GIF could not be made. Try again in a minute.']); exit; }
    unset($g['gif']);
    $g['text'] = ads_bytes_text($g['bytes']) . ' at ' . $g['colors'] . ' colours, ' . $g['frames'] . ' slide' . ($g['frames'] === 1 ? '' : 's') . ' x ' . rtrim(rtrim(number_format($ad['hold'], 1), '0'), '.') . ' s'
               . ($g['fits'] ? '' : ' (over the 1 MB cap even at ' . $g['colors'] . ' colours: drop a slide or try a plainer texture)');
    echo json_encode($g);
    exit;
}

$what = (string)($_POST['export'] ?? 'gif');
if ($what === 'slides') {
    $zip = ads_export_slides_zip($ad);
    if ($zip === null) portal_stop('The slides could not be made', 'Something went wrong making the JPGs. Please try again in a minute.');
    $name = ads_filename($ad, 'zip', 'slides-' . ADS_SLIDE_SCALE . 'x');
    social_keep_copy($ctx, $name, $zip);
    header('Content-Type: application/zip');
    header('Content-Length: ' . strlen($zip));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo $zip;
    exit;
}

$g = ads_export_gif($ad);
if ($g === null) portal_stop('The GIF could not be made', 'Something went wrong making the frames. Please try again in a minute.');
$name = ads_filename($ad, 'gif');
social_keep_copy($ctx, $name, $g['gif']);
header('Content-Type: image/gif');
header('Content-Length: ' . strlen($g['gif']));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('X-Ad-Gif: ' . $g['bytes'] . ' bytes, ' . $g['colors'] . ' colors' . ($g['fits'] ? '' : ', over cap'));
echo $g['gif'];
