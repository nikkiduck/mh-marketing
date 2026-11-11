<?php
/**
 * tests/render_ads_sample.php — renders the digital ad slides with sample
 * data, for design work. No database, no sign-in: inc/ads_render.php and
 * inc/ads_export.php alone.
 *
 *   php tests/render_ads_sample.php [outdir] [asset base] [--png] [--gif] [--all] [--size=970x250]
 *
 * Always: one HTML file per size and slide (ad-{size}-{n}-{kind}.html, plus
 * the two-broker slide as -agent2). --png (on the server, where Chromium
 * is): the same as PNGs. --gif: each size's animated GIF with its weight,
 * and the marquee's 2x slides ZIP. --all: the all-sizes ZIP as the page's
 * Download all sizes makes it. --size limits it to one size.
 *
 * The asset base defaults to the live portal (the logo and the texture are
 * read from disk on the export path; the fonts are embedded). The photos are
 * a live listing's through the public site's img.php.
 */
require __DIR__ . '/../inc/ads_export.php';   // includes ads_render.php

$flags = array_values(array_filter(array_slice($argv, 1), fn($a) => str_starts_with($a, '--')));
$args  = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$png = in_array('--png', $flags, true); $gif = in_array('--gif', $flags, true);
$only = ''; foreach ($flags as $fl) if (str_starts_with($fl, '--size=')) $only = substr($fl, 7);
$out  = rtrim($args[0] ?? sys_get_temp_dir(), '/');
$base = $args[1] ?? 'https://marketing.monthaus.com';
$pic  = fn(string $u) => 'https://monthaus.com/img.php?u=' . rawurlencode($u) . '&w=1600';

$listing = ['address' => '1100 N Frontage Road W #1513', 'city' => 'Vail', 'beds' => 2, 'baths' => 2, 'sqft' => 1116, 'list_price' => 1725000];
$agent   = ['name' => 'Allison Decent', 'phone' => '9704458144', 'headshot_url' => $base . '/agent-photos/allison-decent-4-square.jpg', 'headshot_file' => null];
$agent2  = ['name' => 'Allison Byford', 'phone' => '9703900000', 'headshot_url' => $base . '/agent-photos/allison-byford-19-square.jpg', 'headshot_file' => null];
// Six different pictures (stock, standing in for a listing's): the sizes with a photo either side of the panel take two a slide.
$st = fn(string $n) => $base . '/assets/stock/' . $n . '.jpg';
$photos = [$pic('https://photos.anpcdn.com/base/57be43_e1f413326388da72af2e955330ca24d3.jpg'), $st('vail-village-aerial-winter-321843452'), $st('aspen-aerial-fall-1110909378'),
           $st('telluride-aerial-main-street-fall-1111462754'), $st('breckenridge-town-winter-dusk-382409211'), $st('crested-butte-town-snodgrass-summer-309032886')];
$ad = [
    'size' => '970x250', 'share' => ADS_SHARE_DEFAULT, 'texture' => ADS_TEXTURE_DEFAULT, 'hold' => 4, 'prefix' => 'VD', 'listing' => $listing, 'agents' => [$agent],
    'frames' => [
        ['kind' => 'headline', 'headline' => "Beautifully Renovated\nat Simba Run", 'photo_url' => $photos[0], 'photo2_url' => $photos[3]],
        ['kind' => 'price',    'headline' => "Desirable Location,\nPriced to Sell", 'price' => 'Now $1,725,000', 'photo_url' => $photos[1], 'photo2_url' => $photos[4]],
        ['kind' => 'agent',    'photo_url' => $photos[2], 'photo2_url' => $photos[5]],
    ],
];
foreach (ads_sizes() as $size => $S) {
    if ($only !== '' && $only !== $size) continue;
    $a = ['size' => $size, 'name' => 'VD_1100-N-Frontage-Rd-W-1513_' . $size] + $ad;
    $duo = ['agents' => [$agent, $agent2]] + $a;
    $blank = $a; $blank['frames'][0]['headline'] = ''; $blank['frames'][1]['headline'] = '';
    $set = [];
    foreach ($a['frames'] as $i => $f) $set[($i + 1) . '-' . $f['kind']] = [$a, $i];
    $set['3-agent2'] = [$duo, 2];
    $set['1-blank'] = [$blank, 0]; $set['2-blank'] = [$blank, 1];
    foreach ($set as $tag => [$x, $i]) {
        file_put_contents("$out/ad-$size-$tag.html", ads_render_frame($x, $i, $base, true));
        if ($png) file_put_contents("$out/ad-$size-$tag.png", (string)ads_export_png(ads_render_frame($x, $i, null, true), $S['w'], $S['h'], 1));
    }
    echo "$size: " . count($set) . " slides" . ($png ? ' (html + png)' : ' (html)') . "\n";
    if ($gif) {
        $t = microtime(true);
        $g = ads_export_gif($a);
        if ($g === null) { fwrite(STDERR, "$size: gif failed\n"); continue; }
        file_put_contents("$out/" . ads_filename($a, 'gif'), $g['gif']);
        printf("  %s: %s, %s, fits=%s, %.1f s\n", ads_filename($a, 'gif'), ads_bytes_text($g['bytes']), $g['how'] ?? ($g['colors'] . ' colours'), $g['fits'] ? 'yes' : 'NO', microtime(true) - $t);
    }
}
if ($gif && ($only === '' || $only === '970x250')) {
    $a = ['name' => 'VD_1100-N-Frontage-Rd-W-1513_970x250'] + $ad;
    $zip = ads_export_slides_zip($a);
    if ($zip !== null) { file_put_contents("$out/" . ads_filename($a, 'zip', 'slides-2x'), $zip); echo '  ' . ads_filename($a, 'zip', 'slides-2x') . ': ' . ads_bytes_text(strlen($zip)) . "\n"; }
}
if (in_array('--all', $flags, true)) {
    $t = microtime(true);
    $zip = ads_export_all_zip(['name_base' => 'VD_1100-N-Frontage-Rd-W-1513'] + $ad);
    if ($zip === null) { fwrite(STDERR, "all-sizes zip failed\n"); exit(1); }
    file_put_contents("$out/VD_1100-N-Frontage-Rd-W-1513_all-sizes.zip", $zip);
    printf("all-sizes.zip: %s, %.1f s\n", ads_bytes_text(strlen($zip)), microtime(true) - $t);
}
