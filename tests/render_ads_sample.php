<?php
/**
 * tests/render_ads_sample.php — renders the digital ad slides with sample
 * data to HTML files, for design work and a headless Chromium screenshot.
 * No database, no sign-in: inc/ads_render.php alone.
 *
 *   php tests/render_ads_sample.php [outdir] [asset base] [--gif]
 *
 * --gif (on the server, where Chromium and Imagick are): also the PNG of
 * each slide, the animated GIF and the 2x slides ZIP, through inc/ads_export.php.
 *
 * The asset base defaults to the live portal (the logo and the texture are
 * fetched from it; the fonts are embedded as the export embeds them). The
 * photo is a live listing photo through the public site's img.php.
 */
require __DIR__ . '/../inc/ads_export.php';   // includes ads_render.php

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--gif'));
$gif  = in_array('--gif', $argv, true);
$out  = rtrim($args[0] ?? sys_get_temp_dir(), '/');
$base = $args[1] ?? 'https://marketing.monthaus.com';
$pic  = fn(string $u) => 'https://monthaus.com/img.php?u=' . rawurlencode($u) . '&w=1600';

$listing = ['address' => '1100 N Frontage Road W #1513', 'city' => 'Vail', 'beds' => 2, 'baths' => 2, 'sqft' => 1116, 'list_price' => 1725000];
$agent   = ['name' => 'Allison Decent', 'phone' => '9704458144', 'headshot_url' => $base . '/agent-photos/allison-decent-4-square.jpg', 'headshot_file' => null];
$agent2  = ['name' => 'Allison Byford', 'phone' => '9703900000', 'headshot_url' => $base . '/agent-photos/allison-byford-19-square.jpg', 'headshot_file' => null];
$photos  = [
    $pic('https://photos.anpcdn.com/base/57be43_e1f413326388da72af2e955330ca24d3.jpg'),
    $pic('https://photos.anpcdn.com/base/57be43_e1f413326388da72af2e955330ca24d3.jpg'),
    $pic('https://photos.anpcdn.com/base/57be43_e1f413326388da72af2e955330ca24d3.jpg'),
];
$ad = [
    'size' => '970x250', 'texture' => ADS_TEXTURE_DEFAULT, 'hold' => 4, 'prefix' => 'VD', 'name' => 'VD_1100-N-Frontage-Rd-W-1513_970x250', 'share' => ADS_SHARE_DEFAULT, 'listing' => $listing, 'agents' => [$agent],
    'frames' => [
        ['kind' => 'headline', 'headline' => "Beautifully Renovated\nat Simba Run", 'photo_url' => $photos[0], 'zoom' => 1, 'fx' => 50, 'fy' => 50],
        ['kind' => 'price',    'headline' => "Desirable Location,\nPriced to Sell", 'price' => 'Now $1,725,000', 'photo_url' => $photos[1], 'zoom' => 1.2, 'fx' => 40, 'fy' => 50],
        ['kind' => 'agent',    'photo_url' => $photos[2], 'zoom' => 1, 'fx' => 50, 'fy' => 50],
    ],
];
foreach ($ad['frames'] as $i => $f) {
    file_put_contents("$out/ad-970x250-" . ($i + 1) . "-{$f['kind']}.html", ads_render_frame($ad, $i, $base, true));
    echo "$out/ad-970x250-" . ($i + 1) . "-{$f['kind']}.html\n";
}
// Two brokers on the Presented by slide, and the three photo widths.
$duo = $ad; $duo['agents'] = [$agent, $agent2];
file_put_contents("$out/ad-970x250-3-agent-two.html", ads_render_frame($duo, 2, $base, true));
foreach (ads_shares() as $sh => $lbl) { $w = $ad; $w['share'] = $sh; file_put_contents("$out/ad-970x250-share-$sh.html", ads_render_frame($w, 0, $base, true)); }
echo "$out/ad-970x250-3-agent-two.html + share variants\n";
// The blank-headline forms: the address leads, the price leads.
$blank = $ad; $blank['frames'][0]['headline'] = ''; $blank['frames'][1]['headline'] = '';
file_put_contents("$out/ad-970x250-blank-1.html", ads_render_frame($blank, 0, $base, true));
file_put_contents("$out/ad-970x250-blank-2.html", ads_render_frame($blank, 1, $base, true));
echo "$out/ad-970x250-blank-1.html\n$out/ad-970x250-blank-2.html\n";

if ($gif) {
    $t = microtime(true);
    $pngs = ads_frame_pngs($ad, 1);
    if ($pngs === null) { fwrite(STDERR, "frames failed\n"); exit(1); }
    foreach ($pngs as $i => $png) { file_put_contents("$out/ad-frame-" . ($i + 1) . ".png", $png); echo "$out/ad-frame-" . ($i + 1) . ".png (" . strlen($png) . " bytes)\n"; }
    $S = ads_size($ad['size']);
    file_put_contents("$out/ad-frame-3-two.png", (string)ads_export_png(ads_render_frame($duo, 2, null, true), $S['w'], $S['h'], 1));
    foreach (ads_shares() as $sh => $lbl) { $w = $ad; $w['share'] = $sh; file_put_contents("$out/ad-share-$sh.png", (string)ads_export_png(ads_render_frame($w, 0, null, true), $S['w'], $S['h'], 1)); }
    printf("frames: %.1f s\n", microtime(true) - $t);
    $t = microtime(true);
    $g = ads_export_gif($ad);
    if ($g === null) { fwrite(STDERR, "gif failed\n"); exit(1); }
    file_put_contents("$out/" . ads_filename($ad, 'gif'), $g['gif']);
    printf("%s: %s at %d colours, fits=%s, %.1f s\n", ads_filename($ad, 'gif'), ads_bytes_text($g['bytes']), $g['colors'], $g['fits'] ? 'yes' : 'NO', microtime(true) - $t);
    $t = microtime(true);
    $zip = ads_export_slides_zip($ad);
    if ($zip === null) { fwrite(STDERR, "slides failed\n"); exit(1); }
    file_put_contents("$out/" . ads_filename($ad, 'zip', 'slides-2x'), $zip);
    printf("%s: %s, %.1f s\n", ads_filename($ad, 'zip', 'slides-2x'), ads_bytes_text(strlen($zip)), microtime(true) - $t);
}
