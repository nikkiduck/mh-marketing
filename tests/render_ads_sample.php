<?php
/**
 * tests/render_ads_sample.php — renders the digital ad slides with sample
 * data, for design work. No database, no sign-in: inc/ads_render.php and
 * inc/ads_export.php alone.
 *
 *   php tests/render_ads_sample.php [outdir] [asset base] [--png] [--gif] [--all] [--styles [--classic]] [--size=970x250]
 *
 * Always: one HTML file per size and slide (ad-{size}-{n}-{kind}.html, plus
 * the two-broker slide as -agent2). --png (on the server, where Chromium
 * is): the same as PNGs. --gif: each size's animated GIF with its weight,
 * and the marquee's 2x slides ZIP. --all: the all-sizes ZIP as the page's
 * Download all sizes makes it. --styles: the other layouts (inc/ads_styles.php)
 * at the sizes they are built for, instead of the above. --size limits it to one size.
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

// 970 Powder Lane, Aspen: the listing of Nikki's own Impact ad, its photos through the public site's img.php.
$listing = ['address' => '970 Powder Lane', 'city' => 'Aspen', 'beds' => 9, 'baths' => 12, 'sqft' => 9768, 'list_price' => 0];
$agent   = ['name' => 'Jonathan Boxer', 'phone' => '9709484802', 'headshot_url' => $base . '/agent-photos/jonathan-boxer-30-square.jpg', 'headshot_file' => null];
$agent2  = ['name' => 'Scott Weber', 'phone' => '9709482766', 'headshot_url' => $base . '/agent-photos/scott-weber-21-square.jpg', 'headshot_file' => null];
$photos = array_map(fn($h) => $pic('https://photos.anpcdn.com/base/57be43_' . $h . '.jpg'), ['c7740ee55c47f9a59989998541ef55da', 'cd57ef7309c5211ef2f5a497b64b5acd', 'ca47d1eefe0f5073e66a86b447a6adf7',
           'a92026aa456fc422db993b217da08084', 'a981778e47129ce9a588b2f4eba91d25', 'b156b5e0e4ab94a67384c9bf0463afe0']);
$ad = [
    'size' => '970x250', 'style' => 'classic', 'share' => ADS_SHARE_DEFAULT, 'texture' => ADS_TEXTURE_DEFAULT, 'hold' => 4, 'prefix' => 'ADN', 'listing' => $listing, 'agents' => [$agent],
    'frames' => [
        ['kind' => 'headline', 'headline' => "A Rare Ski-In Sanctuary\non Aspen Mountain", 'photo_url' => $photos[0], 'photo2_url' => $photos[3]],
        ['kind' => 'price',    'headline' => "Nine Bedrooms,\nSteps from the Slopes", 'price' => 'Offered at $58,000,000', 'photo_url' => $photos[1], 'photo2_url' => $photos[4]],
        ['kind' => 'agent',    'photo_url' => $photos[2], 'photo2_url' => $photos[5]],
    ],
];
// --styles: every other layout at the sizes it is built for (ad-{style}-{size}-{slide}.png), nothing else.
if (in_array('--styles', $flags, true)) {
    foreach (ads_styles() as $style => $label) {
        if ($style === 'classic' && !in_array('--classic', $flags, true)) continue;
        foreach (($style === 'classic' ? ['970x250', '300x250'] : ads_style_sizes($style)) as $size) {
            if ($only !== '' && $only !== $size) continue;
            $S = ads_size($size);
            $a = ['size' => $size, 'style' => $style, 'name' => "ADN_970-Powder-Ln_{$style}_{$size}"] + $ad;
            $duo = ['agents' => [$agent, $agent2]] + $a;
            $blank = $a; $blank['frames'][0]['headline'] = '';
            foreach (['1-headline' => [$a, 0], '2-price' => [$a, 1], '3-agent' => [$a, 2], '3-agent2' => [$duo, 2], '1-blank' => [$blank, 0]] as $tag => [$x, $i]) {
                file_put_contents("$out/ad-$style-$size-$tag.html", ads_render_frame($x, $i, $base, true));
                if ($png) file_put_contents("$out/ad-$style-$size-$tag.png", (string)ads_export_png(ads_render_frame($x, $i, null, true), $S['w'], $S['h'], 1));
            }
            echo "$label $size" . ($png ? ' (html + png)' : ' (html)') . "\n";
            if ($gif) { $g = ads_export_gif($a); if ($g) printf("  %s, %s, fits=%s\n", ads_bytes_text($g['bytes']), $g['how'], $g['fits'] ? 'yes' : 'NO'); }
        }
    }
    exit;
}
foreach (ads_sizes() as $size => $S) {
    if ($only !== '' && $only !== $size) continue;
    $a = ['size' => $size, 'name' => 'ADN_970-Powder-Ln_' . $size] + $ad;
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
    $a = ['name' => 'ADN_970-Powder-Ln_970x250'] + $ad;
    $zip = ads_export_slides_zip($a);
    if ($zip !== null) { file_put_contents("$out/" . ads_filename($a, 'zip', 'slides-2x'), $zip); echo '  ' . ads_filename($a, 'zip', 'slides-2x') . ': ' . ads_bytes_text(strlen($zip)) . "\n"; }
}
if (in_array('--all', $flags, true)) {
    $t = microtime(true);
    $zip = ads_export_all_zip(['name_base' => 'ADN_970-Powder-Ln'] + $ad);
    if ($zip === null) { fwrite(STDERR, "all-sizes zip failed\n"); exit(1); }
    file_put_contents("$out/ADN_970-Powder-Ln_all-sizes.zip", $zip);
    printf("all-sizes.zip: %s, %.1f s\n", ads_bytes_text(strlen($zip)), microtime(true) - $t);
}
