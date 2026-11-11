<?php
/**
 * inc/ads_render.php — the digital ad templates (PROTOTYPE, Nikki,
 * 2026-11-09: "let's prototype it"): a property ad for the newspapers' web
 * sites (Vail Daily, Aspen Daily News) as an animated GIF of two or three
 * slides, built the way the Instagram tools work: one HTML document per
 * slide at the ad's exact pixel size, the SAME document as the live preview
 * and as the page headless Chromium screenshots. There is no second render
 * path: never draw the ad in JavaScript.
 *
 * The design follows Nikki's current ads (Advertising/Nov Current Ads,
 * InDesign): the listing photo fills the left half, a charcoal panel fills
 * the right with a ghosted grayscale mountain texture, a two-line headline,
 * the address and the beds / baths / SF in spaced capitals, and the white
 * stacked logo lower right. The second slide repeats the form with a second
 * headline and the price; the third is "Presented by": the broker's headshot,
 * name and phone. Recoloured to the website's Slate scheme (the charcoal-blue
 * footer tone for the panel, light slate for the capitals, Jost throughout)
 * in place of the pure black and Avenir of the InDesign files.
 *
 * Seven sizes since 2026-11-11 (Nikki: "let's see the other sizes before I
 * make any more design decisions"), each arranged as her InDesign set
 * arranges it, because a 320 x 50 cannot hold what a marquee holds: three
 * layouts (`side`: photo left, panel right; `top`: the panel across the top,
 * the photo under it; `tri`: a photo either side of the panel) and, per
 * size, its own type sizes, which lines a slide carries, where the logo
 * sits and how the Presented by slide is built. All of it is ads_sizes().
 *
 * Standalone (no portal.php, no database) so a harness can render sample
 * data locally: tests/render_ads_sample.php.
 */
require_once __DIR__ . '/social_render.php';   // social_font_css(), social_short_address(), social_num(), social_price_text()

/** The GIF has to come in under this (the newspapers' 1 MB cap); the export drops colours until it does. */
const ADS_GIF_MAX = 1000000;

/**
 * The ad sizes. Per size: `layout` (side | top | tri), `pw` the photo's width
 * (side, tri; 0 = from the Photo width choice, the marquee alone) or
 * `panel_h` (top); `pad_l` and `text_r`, the words' left padding and the
 * room kept clear on their right (the logo, when it sits beside them);
 * `valign` top (with `top` px) or mid; `hl` the headline (size, weight, line
 * height, 1 or 2 lines; `steps` = the marquee's own size steps, else the
 * size is fitted to the column by estimate); `caps` the spaced capitals;
 * `price` the price line; `lines` what follows the headline on each slide
 * (addr = short address + city, addr0 = without the city, specs, specs_a +
 * specs_b = the specs on two lines, specs_short, price = the price or, when
 * the price already leads, the specs); `price_leads` = the price slide's
 * headline is always the price (no room for a second headline); `logo`
 * (height; br = lower right, mr = middle right); `agent` and `two` the
 * Presented by slide for one broker and for two (`head_photo` = the
 * headshot takes the photo's place; `inline` = name and phone on one line;
 * `logo` full | mark | none on a two-broker slide).
 */
function ads_sizes(): array {
    $strip = [   // the two 90-high strips share everything but their widths
        'pad_l' => 20, 'text_r' => 100, 'valign' => 'mid',
        'hl' => ['size' => 20, 'weight' => 300, 'lh' => 1.15, 'lines' => 1],
        'caps' => ['size' => 10, 'ls' => .14, 'gap' => 5, 'gap2' => 1], 'price' => ['size' => 11, 'gap' => 3],
        'lines' => ['headline' => ['addr0', 'specs'], 'price' => ['addr0', 'price']],
        'logo' => ['h' => 56, 'pos' => 'mr', 'r' => 14],
        'agent' => ['label' => false, 'head' => 66, 'gap' => 14, 'name' => 19, 'nweight' => 300, 'tel' => 10.5, 'tel_ls' => .16, 'tel_gap' => 4, 'site' => 9.5],
        'two' => ['label' => false, 'head' => 36, 'gap' => 9, 'row_gap' => 5, 'name' => 14, 'nweight' => 400, 'tel' => 9.5, 'tel_ls' => .14, 'site' => 0, 'logo' => 'full'],
    ];
    return [
        '970x250' => ['w' => 970, 'h' => 250, 'name' => 'Marquee', 'layout' => 'side', 'pw' => 0, 'words_w' => 350,
            'pad_l' => 30, 'text_r' => 24, 'valign' => 'top', 'top' => 32,
            'hl' => ['size' => 31, 'weight' => 300, 'lh' => 1.18, 'lines' => 2, 'steps' => [46 => 28, 62 => 25, 78 => 22]],
            'caps' => ['size' => 12.5, 'ls' => .16, 'gap' => 15, 'gap2' => 3, 'steps' => [30 => 11.5, 37 => 10.5]], 'price' => ['size' => 15, 'gap' => 12],
            'lines' => ['headline' => ['addr', 'specs'], 'price' => ['addr', 'price']],
            'logo' => ['h' => 74, 'pos' => 'br', 'r' => 24, 'b' => 20],
            'agent' => ['label' => true, 'head' => 100, 'gap' => 22, 'name' => 26, 'nweight' => 300, 'tel' => 14, 'tel_ls' => .2, 'tel_gap' => 8, 'site' => 12.5, 'mid' => 48],
            'two' => ['label' => true, 'head' => 70, 'gap' => 16, 'row_gap' => 10, 'name' => 23, 'nweight' => 300, 'tel' => 13, 'tel_ls' => .18, 'site' => 11.5, 'logo' => 'mark', 'mid' => 50]],
        '300x250' => ['w' => 300, 'h' => 250, 'name' => 'Rectangle', 'layout' => 'top', 'panel_h' => 92,
            'pad_l' => 14, 'text_r' => 88, 'valign' => 'mid',
            'hl' => ['size' => 17, 'weight' => 400, 'lh' => 1.16, 'lines' => 2],
            'caps' => ['size' => 9, 'ls' => .1, 'gap' => 6, 'gap2' => 1], 'price' => ['size' => 10, 'gap' => 4],
            'lines' => ['headline' => ['addr0', 'specs'], 'price' => ['addr0', 'price']],
            'logo' => ['h' => 52, 'pos' => 'mr', 'r' => 11],
            'agent' => ['label' => false, 'head' => 62, 'gap' => 10, 'name' => 16, 'nweight' => 400, 'tel' => 10, 'tel_ls' => .12, 'tel_gap' => 4, 'site' => 9],
            'two' => ['label' => false, 'head' => 36, 'gap' => 8, 'row_gap' => 6, 'name' => 13, 'nweight' => 400, 'tel' => 9, 'tel_ls' => .1, 'site' => 0, 'logo' => 'full']],
        '728x90' => ['w' => 728, 'h' => 90, 'name' => 'Leaderboard', 'layout' => 'side', 'pw' => 276] + $strip,
        '970x90' => ['w' => 970, 'h' => 90, 'name' => 'Super leaderboard', 'layout' => 'tri', 'pw' => 278, 'pad_l' => 18, 'text_r' => 96] + $strip,
        '320x100' => ['w' => 320, 'h' => 100, 'name' => 'Mobile, large', 'layout' => 'side', 'pw' => 150,
            'pad_l' => 12, 'text_r' => 10, 'valign' => 'top', 'top' => 11,
            'hl' => ['size' => 13.5, 'weight' => 400, 'lh' => 1.18, 'lines' => 2],
            'caps' => ['size' => 8.5, 'ls' => .08, 'gap' => 5, 'gap2' => 0], 'price' => ['size' => 9.5, 'gap' => 5],
            'lines' => ['headline' => ['specs_a', 'specs_b'], 'price' => ['price']],
            'logo' => ['h' => 30, 'pos' => 'br', 'r' => 8, 'b' => 8],
            'agent' => ['label' => false, 'head' => 46, 'gap' => 8, 'name' => 12.5, 'nweight' => 500, 'wrap' => true, 'tel' => 10, 'tel_ls' => .04, 'tel_gap' => 2, 'site' => 0, 'top' => 12],
            'two' => ['label' => false, 'head' => 0, 'gap' => 0, 'row_gap' => 6, 'name' => 11.5, 'nweight' => 500, 'tel' => 9.5, 'tel_ls' => .04, 'site' => 0, 'logo' => 'full', 'top' => 12]],
        '320x50' => ['w' => 320, 'h' => 50, 'name' => 'Mobile', 'layout' => 'side', 'pw' => 118,
            'pad_l' => 9, 'text_r' => 60, 'valign' => 'mid', 'price_leads' => true,
            'hl' => ['size' => 13, 'weight' => 500, 'lh' => 1.14, 'lines' => 2],
            'caps' => ['size' => 8, 'ls' => .05, 'gap' => 2, 'gap2' => 0], 'price' => ['size' => 9, 'gap' => 2],
            'lines' => ['headline' => [], 'price' => ['specs_short']],
            'logo' => ['h' => 34, 'pos' => 'mr', 'r' => 7],
            'agent' => ['label' => false, 'head_photo' => true, 'head' => 0, 'gap' => 0, 'name' => 13, 'nweight' => 500, 'tel' => 12.5, 'tel_ls' => .02, 'tel_gap' => 1, 'site' => 0],
            'two' => ['label' => false, 'head_photo' => true, 'head' => 0, 'gap' => 0, 'row_gap' => 3, 'name' => 10.5, 'nweight' => 500, 'tel' => 10.5, 'tel_ls' => .02, 'inline' => true, 'site' => 0, 'logo' => 'full']],
        '1920x400' => ['w' => 1920, 'h' => 400, 'name' => 'Impact', 'layout' => 'tri', 'pw' => 650,
            'pad_l' => 36, 'text_r' => 36, 'valign' => 'top', 'top' => 38,
            'hl' => ['size' => 46, 'weight' => 300, 'lh' => 1.16, 'lines' => 2],
            'caps' => ['size' => 19, 'ls' => .16, 'gap' => 20, 'gap2' => 4], 'price' => ['size' => 22, 'gap' => 16],
            'lines' => ['headline' => ['addr', 'specs'], 'price' => ['addr', 'price']],
            'logo' => ['h' => 100, 'pos' => 'br', 'r' => 36, 'b' => 34],
            'agent' => ['label' => true, 'head' => 150, 'gap' => 28, 'name' => 44, 'nweight' => 300, 'tel' => 20, 'tel_ls' => .2, 'tel_gap' => 10, 'site' => 17, 'mid' => 44],
            'two' => ['label' => false, 'head' => 104, 'gap' => 24, 'row_gap' => 18, 'name' => 40, 'nweight' => 300, 'tel' => 19, 'tel_ls' => .18, 'site' => 0, 'logo' => 'full', 'top' => 30]],
    ];
}
function ads_size(string $k): array { return ads_sizes()[$k] ?? ads_sizes()['970x250']; }

/**
 * The boxes of a size: [photo w, photo h, panel x, panel y, panel w, panel h,
 * second photo x or null]. $share is the marquee's Photo width choice.
 */
function ads_geometry(array $S, int $share): array {
    $W = $S['w']; $H = $S['h'];
    if ($S['layout'] === 'top') return [$W, $H - $S['panel_h'], 0, 0, $W, $S['panel_h'], null];
    $pw = $S['pw'] > 0 ? $S['pw'] : (int)round($W * $share / 100);
    if ($S['layout'] === 'tri') return [$pw, $H, $pw, 0, $W - 2 * $pw, $H, $W - $pw];
    return [$pw, $H, $pw, 0, $W - $pw, $H, null];
}

/** What the page needs to know about each size: {name, w, h, layout, pw, ph} (pw 0 = from the Photo width choice). */
function ads_size_boxes(): array {
    $out = [];
    foreach (ads_sizes() as $k => $S) {
        [$pw, $ph] = ads_geometry($S, (int)ADS_SHARE_DEFAULT);
        $out[$k] = ['name' => $S['name'], 'w' => $S['w'], 'h' => $S['h'], 'layout' => $S['layout'], 'pw' => ($S['layout'] !== 'top' && $S['pw'] === 0) ? 0 : $pw, 'ph' => $ph];
    }
    return $out;
}

/** A font size that fits $chars characters into $width px: $size, or smaller down to $floor of it. $per = a character's width in em. */
function ads_fit(float $size, int $chars, float $width, float $per, float $floor = .68): float {
    if ($chars < 1 || $width <= 0) return $size;
    $est = $chars * $size * $per;
    return $est <= $width ? $size : round(max($size * $floor, $size * $width / $est), 1);
}

/** How much of the width the photo takes (Nikki, 2026-11-09: "more of the photo, don't just split in half"). */
function ads_shares(): array { return ['50' => 'Half', '58' => 'More photo', '65' => 'Most photo']; }
const ADS_SHARE_DEFAULT = '58';

/** The slide kinds. */
function ads_frame_kinds(): array { return ['headline' => 'Headline', 'price' => 'Headline + price', 'agent' => 'Presented by']; }

/** The panel textures: stock photos shown grayscale and ghosted behind the words ('' = the plain panel). */
function ads_textures(): array {
    return [
        'vail-mountain-gore-range-winter-401309793'      => 'Gore Range, winter',
        'vail-summit-gore-range-summer-sunset-217581553' => 'Gore Range, summer',
        'ridgway-san-juans-fall-sunrise-256750697'       => 'San Juans, fall',
        'ridgway-mount-sneffels-summer-198784289'        => 'Mount Sneffels',
        'aspen-aerial-fall-1110909378'                   => 'Aspen from above',
        'snowmass-ski-slopes-winter-panorama-410667845'  => 'Snowmass slopes',
        'gondola-snowy-peak-bw-135623770'                => 'Gondola and peak',
        'alpine-chalets-hillside-bw-374512397'           => 'Alpine chalets',
        'telluride-town-overlook-summer-413597756'       => 'Telluride overlook',
        ''                                               => 'None (plain panel)',
    ];
}
const ADS_TEXTURE_DEFAULT = 'vail-mountain-gore-range-winter-401309793';

/** The Slate scheme as the ad draws it: the website's footer tone for the panel, its light slate for the capitals. */
function ads_palette(string $base = ''): array {
    return ['panel' => '#2b3038', 'panel_rgb' => '43,48,56', 'photo_bg' => '#1f242c', 'text' => '#ffffff', 'accent' => '#aebbcc', 'soft' => '#e3e8ee',
            'logo' => $base . '/assets/images/MH_vert-stack_white.svg'];
}

/** "9702741497" or "+19702741497" → "970.274.1497" (the ads' form); anything else as typed. */
function ads_phone_text(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) === 10 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6) : trim($phone);
}

/** "134 N BRETT TRL, EDWARDS": the short address and the city, in capitals. */
function ads_address_caps(array $l): string {
    $a = social_short_address(trim((string)($l['address'] ?? '')));   // "1100 N Frontage Rd W #1513", as the ads set it
    $c = trim((string)($l['city'] ?? ''));
    return mb_strtoupper(trim($a . ($a !== '' && $c !== '' ? ', ' : '') . $c));
}

/** "5 BEDS | 6 BATHS | 3,074 SF", skipping what the board left blank. */
function ads_specs_caps(array $l): string {
    $p = [];
    $beds = $l['beds'] ?? null; $baths = $l['baths'] ?? null; $sqft = $l['sqft'] ?? null;
    if ($beds !== null && (float)$beds > 0)   $p[] = social_num($beds) . ((float)$beds == 1 ? ' BED' : ' BEDS');
    if ($baths !== null && (float)$baths > 0) $p[] = social_num($baths) . ((float)$baths == 1 ? ' BATH' : ' BATHS');
    if ($sqft !== null && (int)$sqft > 0)     $p[] = number_format((int)$sqft) . ' SF';
    return implode('  |  ', $p);
}

/** The specs for the small sizes: ["2 BEDS | 2 BATHS", "1,116 SF"] on two lines, and "2 BD | 2 BA | 1,116 SF" on one. */
function ads_specs_parts(array $l): array {
    $beds = $l['beds'] ?? null; $baths = $l['baths'] ?? null; $sqft = $l['sqft'] ?? null;
    $bb = []; $short = [];
    if ($beds !== null && (float)$beds > 0)   { $bb[] = social_num($beds) . ((float)$beds == 1 ? ' BED' : ' BEDS'); $short[] = social_num($beds) . ' BD'; }
    if ($baths !== null && (float)$baths > 0) { $bb[] = social_num($baths) . ((float)$baths == 1 ? ' BATH' : ' BATHS'); $short[] = social_num($baths) . ' BA'; }
    $sf = ($sqft !== null && (int)$sqft > 0) ? number_format((int)$sqft) . ' SF' : '';
    if ($sf !== '') $short[] = $sf;
    return ['a' => implode('  |  ', $bb), 'b' => $sf, 'short' => implode(' | ', $short)];
}

/**
 * One slide as a complete HTML document, exactly w x h. $ad is the normalised
 * ad (ads_from_request() in inc/ads.php, or a harness): size, share (the
 * photo's width in %), texture, listing (address, city, beds, baths, sqft),
 * agents (one or two: name, phone, headshot url + file) and frames, each
 * {kind, headline, price, photo_url, photo_file, zoom, fx, fy, and for the
 * sizes with a photo either side of the panel photo2_url, photo2_file,
 * zoom2, fx2, fy2}. $export reads the photo, the texture and the
 * headshot from disk (file://) where a file is known and embeds the fonts.
 */
function ads_render_frame(array $ad, int $i, ?string $base = null, bool $export = false): string {
    $base = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $S    = ads_size((string)($ad['size'] ?? '970x250'));
    $W = $S['w']; $H = $S['h']; $pad = $S['pad_l'];
    $share = isset(ads_shares()[(string)($ad['share'] ?? '')]) ? (int)$ad['share'] : (int)ADS_SHARE_DEFAULT;
    [$PW, $PH, $panX, $panY, $panW, $panH, $p2x] = ads_geometry($S, $share);
    $c    = ads_palette($base);
    $f    = $ad['frames'][$i] ?? ($ad['frames'][0] ?? []);
    $kind = isset(ads_frame_kinds()[(string)($f['kind'] ?? '')]) ? (string)$f['kind'] : 'headline';
    $l    = (array)($ad['listing'] ?? []);
    $agents = array_slice(array_values(array_filter((array)($ad['agents'] ?? (isset($ad['agent']) ? [$ad['agent']] : [])))), 0, 2);
    $two  = $kind === 'agent' && count($agents) >= 2;
    $A    = $two ? $S['two'] : $S['agent'];          // the Presented by slide's numbers for this many brokers
    $headPhoto = $kind === 'agent' && !empty($A['head_photo']);   // the headshot(s) take the photo's place (320 x 50)
    if ($headPhoto) { $PW = $H * count($agents); $panX = $PW; $panW = $W - $PW; }
    // The words' column (inside .panel, so its left is the padding). On the marquee the type scales down as the Photo
    // width choice narrows the column ($k); every other size has one width and $k is 1.
    $textW = $panW - $pad - $S['text_r'];
    $k = isset($S['words_w']) ? min(1.0, $textW / (int)$S['words_w']) : 1.0;
    $per = fn(int $weight) => $weight >= 500 ? .48 : ($weight >= 400 ? .46 : .44);   // a mixed-case character's width in em, by weight (measured on the marquee)

    // The photo frames: zoom 1 to 3, focus 0 to 100 on each axis, the Instagram tools' CSS (see social_render.php's header).
    $frameCss = function (string $cls, string $sfx, int $bw, int $bh) use ($f): string {
        $zoom = max(1.0, min(3.0, (float)($f['zoom' . $sfx] ?? 1)));
        $fx = max(0, min(100, (int)($f['fx' . $sfx] ?? 50))); $fy = max(0, min(100, (int)($f['fy' . $sfx] ?? 50)));
        return ".{$cls} img.pic{display:block;width:" . round($zoom * 100, 2) . "%;height:" . round($zoom * 100, 2) . "%;object-fit:cover;object-position:{$fx}% {$fy}%;"
             . "transform:translate(" . -round(($zoom - 1) * $fx / 100 * $bw, 1) . "px," . -round(($zoom - 1) * $fy / 100 * $bh, 1) . "px)}\n";
    };
    $src = fn(string $sfx) => ($export && !empty($f['photo' . $sfx . '_file'])) ? 'file://' . $f['photo' . $sfx . '_file'] : (string)($f['photo' . $sfx . '_url'] ?? '');
    $photo = $src('');
    $photo2 = $p2x !== null ? ($src('2') !== '' ? $src('2') : $photo) : '';

    // The texture behind the words: a stock photo, grayscale and ghosted, under a veil that is solid at the words' edge
    // and thins to the right, so the words sit on a clean ground and the ridge line shows behind the logo.
    $tex = (string)($ad['texture'] ?? '');
    $texUrl = '';
    if ($tex !== '') {
        $file = __DIR__ . '/../assets/stock/' . $tex . '.jpg';
        $texUrl = ($export && is_file($file)) ? 'file://' . (realpath($file) ?: $file) : $base . '/assets/stock/' . $tex . '.jpg';
    }

    // The words.
    $headline = trim(str_replace("\r", '', (string)($f['headline'] ?? '')));
    $price = trim((string)($f['price'] ?? ''));
    $short = social_short_address((string)($l['address'] ?? ''));
    $city  = trim((string)($l['city'] ?? ''));
    $sp    = ads_specs_parts($l);
    $tok   = ['addr' => ads_address_caps($l), 'addr0' => mb_strtoupper($short), 'specs' => ads_specs_caps($l), 'specs_a' => $sp['a'], 'specs_b' => $sp['b'], 'specs_short' => $sp['short']];
    if ($kind === 'price' && !empty($S['price_leads']) && $price !== '') $headline = '';   // no room for a second headline: the price leads
    if ($kind !== 'agent' && $headline === '') {
        // No headline typed: the address leads (the price on the price slide), and the capitals line below is not repeated.
        if ($kind === 'price' && $price !== '') { $headline = $price; $price = ''; }
        else { $headline = $short; $tok['addr'] = $city !== '' ? mb_strtoupper($city) . ', COLORADO' : ''; $tok['addr0'] = $tok['addr']; }
    }
    $Hc = $S['hl']; $Cc = $S['caps'];
    $hLines = array_values(array_filter(array_map('trim', preg_split('/\n+/', $headline) ?: []), fn($x) => $x !== ''));
    if ((int)$Hc['lines'] === 1) $hLines = [implode(' ', $hLines)];
    $hLines = array_slice($hLines, 0, 2);
    if (isset($Hc['steps'])) {
        $hsize = (int)round(social_fit_size(implode(' ', $hLines), (int)$Hc['size'], $Hc['steps']) * $k);
    } else {
        // Fitted to the column: the longest line as typed, or about 0.58 of one long line the browser will break in two.
        $long = max(array_map('mb_strlen', $hLines ?: ['']));
        if (count($hLines) === 1 && (int)$Hc['lines'] === 2 && $long * $Hc['size'] * $per((int)$Hc['weight']) > $textW) $long = (int)ceil($long * .58);
        $hsize = ads_fit((float)$Hc['size'], $long, $textW, $per((int)$Hc['weight']));
    }
    $hHtml = implode('<br>', array_map($e, $hLines));
    // The lines under the headline, each a [class, text]; the capitals are one size, fitted to the longest.
    $rows = [];
    foreach (($kind === 'agent' ? [] : ($S['lines'][$kind] ?? [])) as $t) {
        if ($t === 'price') { if ($price !== '') $rows[] = ['price', $price]; elseif ($tok['specs'] !== '') $rows[] = ['caps', $tok['specs']]; }
        elseif (($tok[$t] ?? '') !== '') $rows[] = ['caps', $tok[$t]];
    }
    $capsBase = round($Cc['size'] * $k, 1);
    if (isset($Cc['steps'])) {
        $capsSize = round(social_fit_size($tok['addr'], (int)($Cc['size'] * 2), array_map(fn($v) => (int)($v * 2), $Cc['steps'])) / 2 * $k, 1);   // 12.5 → 11.5 → 10.5 for a long address
    } else {
        $capsSize = (float)$Cc['size'];
        foreach ($rows as [$cls, $text]) if ($cls === 'caps') $capsSize = min($capsSize, ads_fit((float)$Cc['size'], mb_strlen($text), $textW, .54 + $Cc['ls'], .72));
    }
    $priceSize = round($S['price']['size'] * $k, 1);

    $fontCss = social_font_css($base, $export);
    $headSrc = fn(array $a) => ($export && !empty($a['headshot_file'])) ? 'file://' . $a['headshot_file'] : (string)($a['headshot_url'] ?? '');
    // The logo from disk for the export, like the texture (the https copy came up as a broken image in the first server render, 2026-11-09).
    // On the marquee's two-broker slide the full stack has no room beside two names at the wider photo widths, so the M
    // mark stands in, small, upper right; the sizes with room keep the stack where it is (`two.logo`).
    $twoLogo = $two ? (string)($A['logo'] ?? 'full') : 'full';
    $logoName = $twoLogo === 'mark' ? 'M-mark_white.svg' : 'MH_vert-stack_white.svg';
    $logoFile = realpath(__DIR__ . '/../assets/images/' . $logoName);
    $logoUrl = ($export && $logoFile) ? 'file://' . $logoFile : $base . '/assets/images/' . $logoName;
    $L = $S['logo'];
    $logoCss = $twoLogo === 'mark'
        ? ".logo{position:absolute;right:{$L['r']}px;top:22px;height:" . round($L['h'] * .56) . "px;width:auto}\n"
        : ($L['pos'] === 'mr' ? ".logo{position:absolute;right:{$L['r']}px;top:50%;transform:translateY(-50%);height:{$L['h']}px;width:auto}\n"
                              : ".logo{position:absolute;right:{$L['r']}px;bottom:{$L['b']}px;height:{$L['h']}px;width:auto}\n");
    // Where a block sits in the panel: from the top (px, scaled on the marquee) or centred on a line (% of the height).
    $place = function (array $cfg) use ($S, $k): string {
        if (isset($cfg['top'])) return 'top:' . round(($cfg['top'] - 2) * $k + 2) . 'px';
        if (isset($cfg['mid'])) return 'top:' . (int)$cfg['mid'] . '%;transform:translateY(-50%)';
        return $S['valign'] === 'top' ? 'top:' . round(($S['top'] - 2) * $k + 2) . 'px' : 'top:50%;transform:translateY(-50%)';
    };

    // The Presented by slide's sizes: the name fitted to the room beside the headshot.
    $hp = (int)$A['head'];
    $nameW = $textW - ($hp > 0 ? $hp + $A['gap'] * $k : 0);
    $nameSize = round($A['name'] * $k, 1);
    $telSize = round($A['tel'] * $k, 1);
    if ($kind === 'agent') {
        foreach ($agents as $a) {
            $n = mb_strlen((string)($a['name'] ?? ''));
            if (!empty($A['inline'])) $n += 1 + (int)round(mb_strlen(ads_phone_text((string)($a['phone'] ?? ''))) * 1.05);
            elseif (!empty($A['wrap'])) $n = (int)ceil($n * .62);
            $nameSize = min($nameSize, ads_fit($nameSize, $n, $nameW, $per((int)$A['nweight']) + (!empty($A['inline']) ? .03 : 0), .7));
        }
        if (!empty($A['inline'])) $telSize = $nameSize;
    }
    $siteSize = round(($A['site'] ?? 0) * $k, 1);

    $css = "html,body{margin:0;padding:0;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['panel']}}\n{$fontCss}\n"
         . ".ad{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;font-family:'Jost','Helvetica Neue',Helvetica,Arial,sans-serif;color:{$c['text']};-webkit-font-smoothing:antialiased}\n"
         // A clean edge between photo and panel (Nikki, 2026-11-09: a fade into the panel was tried the same day; "don't love the blur, hard line is fine").
         . ".photo{position:absolute;overflow:hidden;background:{$c['photo_bg']}}\n"
         . ".p1{left:0;top:" . ($S['layout'] === 'top' ? $panH : 0) . "px;width:{$PW}px;height:{$PH}px}\n"
         . ($p2x !== null ? ".p2{left:{$p2x}px;top:0;width:{$PW}px;height:{$PH}px}\n" : '')
         . ($headPhoto ? ".p1{display:flex}.p1 img.hp{flex:none;width:{$H}px;height:{$H}px;object-fit:cover;object-position:50% 18%}\n"
                       : $frameCss('p1', '', $PW, $PH))
         . ($p2x !== null ? $frameCss('p2', '2', $PW, $PH) : '')
         . ".panel{position:absolute;left:{$panX}px;top:{$panY}px;width:{$panW}px;height:{$panH}px;background:{$c['panel']};overflow:hidden}\n"
         . ".tex{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;object-position:50% 40%;filter:grayscale(1) brightness(.78) contrast(1.05);opacity:.34}\n"
         . ".veil{position:absolute;left:0;top:0;right:0;bottom:0;background:linear-gradient(to right,rgba({$c['panel_rgb']},1) 0%,rgba({$c['panel_rgb']},.9) 34%,rgba({$c['panel_rgb']},.5) 72%,rgba({$c['panel_rgb']},.3) 100%)}\n"
         . ".words{position:absolute;left:{$pad}px;width:{$textW}px;" . $place([]) . "}\n"
         . ".h{margin:0;font-weight:{$Hc['weight']};font-size:{$hsize}px;line-height:{$Hc['lh']};letter-spacing:.004em;text-wrap:balance" . ((int)$Hc['lines'] === 1 ? ';white-space:nowrap' : '') . "}\n"
         . ".caps{margin:" . round($Cc['gap'] * $k) . "px 0 0;font-size:{$capsSize}px;font-weight:500;letter-spacing:{$Cc['ls']}em;text-transform:uppercase;color:{$c['accent']};line-height:1.5" . (isset($Cc['steps']) ? '' : ';white-space:nowrap') . "}\n"
         . ".caps+.caps{margin-top:{$Cc['gap2']}px}\n"
         . ".price{margin:{$S['price']['gap']}px 0 0;font-size:{$priceSize}px;font-weight:500;letter-spacing:{$Cc['ls']}em;text-transform:uppercase;color:{$c['text']};line-height:1.4;white-space:nowrap}\n"
         . $logoCss
         // The Presented by slide: the headshot, then the words beside it.
         . ".pres{position:absolute;left:{$pad}px;width:{$textW}px;" . $place($A) . ";display:flex;align-items:center;gap:" . round($A['gap'] * $k) . "px}\n"
         . ".head{flex:none;width:{$hp}px;height:{$hp}px;object-fit:cover;object-position:50% 18%;background:{$c['photo_bg']};border:1px solid rgba(255,255,255,.18);box-sizing:border-box}\n"
         . ".lbl{margin:0 0 " . round(6 * max(1, $capsBase / 12.5)) . "px;font-size:{$capsBase}px;font-weight:500;letter-spacing:{$Cc['ls']}em;text-transform:uppercase;color:{$c['accent']};line-height:1.5}\n"
         . ".name{margin:0;font-weight:{$A['nweight']};font-size:{$nameSize}px;line-height:1.15" . (empty($A['wrap']) ? ';white-space:nowrap' : '') . "}\n"
         . ".tel{margin:" . ($A['tel_gap'] ?? 3) . "px 0 0;font-size:{$telSize}px;font-weight:500;letter-spacing:{$A['tel_ls']}em;color:{$c['text']};line-height:1.35;white-space:nowrap}\n"
         . ".name .tel{display:inline;margin:0 0 0 .5em;font-weight:400;color:{$c['accent']}}\n"
         . ".site{margin:" . round(4 * max(1, $siteSize / 12.5)) . "px 0 0;font-size:{$siteSize}px;font-weight:500;letter-spacing:.2em;color:{$c['accent']}}\n"
         // Two brokers (Nikki, 2026-11-09): an optional Presented by line, then a row per broker, headshot beside name and phone.
         . ".pres2{position:absolute;left:{$pad}px;width:{$textW}px;" . ($two ? $place($A) : 'top:0') . "}\n"
         . ".pres2 .lbl{margin-bottom:" . round(10 * $k) . "px}\n"
         . ".pres2 .row{display:flex;align-items:center;gap:" . round(($A['gap'] ?? 0) * $k) . "px;margin-top:" . round(($A['row_gap'] ?? 0) * $k) . "px}\n"
         . ".pres2 .row:first-child{margin-top:0}\n"
         . ".pres2 .site{margin-top:" . round(10 * $k) . "px}\n";

    $tel = fn(array $a) => trim((string)($a['phone'] ?? '')) !== '' ? ads_phone_text((string)$a['phone']) : '';
    $panel = '';
    if ($two) {
        $panel = '<div class="pres2">' . (!empty($A['label']) ? '<p class="lbl">Presented by</p>' : '');
        foreach ($agents as $a) {
            $hs = $headSrc($a);
            $panel .= '<div class="row">' . (($hp > 0 && $hs !== '') ? '<img class="head" src="' . $e($hs) . '" alt="">' : '') . '<div>'
                    . (!empty($A['inline'])
                        ? '<p class="name">' . $e((string)($a['name'] ?? '')) . ($tel($a) !== '' ? '<span class="tel">' . $e($tel($a)) . '</span>' : '') . '</p>'
                        : '<p class="name">' . $e((string)($a['name'] ?? '')) . '</p>' . ($tel($a) !== '' ? '<p class="tel">' . $e($tel($a)) . '</p>' : ''))
                    . '</div></div>';
        }
        $panel .= ($siteSize > 0 ? '<p class="site">monthaus.com</p>' : '') . '</div>';
    } elseif ($kind === 'agent') {
        $a = $agents[0] ?? [];
        $hs = $headSrc($a);
        $panel = '<div class="pres">' . (($hp > 0 && $hs !== '') ? '<img class="head" src="' . $e($hs) . '" alt="">' : '')
               . '<div>' . (!empty($A['label']) ? '<p class="lbl">Presented by</p>' : '') . '<p class="name">' . $e((string)($a['name'] ?? '')) . '</p>'
               . ($tel($a) !== '' ? '<p class="tel">' . $e($tel($a)) . '</p>' : '')
               . ($siteSize > 0 ? '<p class="site">monthaus.com</p>' : '') . '</div></div>';
    } else {
        $panel = '<div class="words"><h1 class="h">' . $hHtml . '</h1>';
        foreach ($rows as [$cls, $text]) $panel .= '<p class="' . $cls . '">' . $e($text) . '</p>';
        $panel .= '</div>';
    }

    $p1 = '';
    if ($headPhoto) { foreach ($agents as $a) if ($headSrc($a) !== '') $p1 .= '<img class="hp" src="' . $e($headSrc($a)) . '" alt="">'; }
    elseif ($photo !== '') $p1 = '<img class="pic" src="' . $e($photo) . '" alt="">';

    return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Ad</title><style>{$css}</style></head><body>"
         . '<div class="ad">'
         . '<div class="photo p1">' . $p1 . '</div>'
         . ($p2x !== null ? '<div class="photo p2">' . ($photo2 !== '' ? '<img class="pic" src="' . $e($photo2) . '" alt="">' : '') . '</div>' : '')
         . '<div class="panel">' . ($texUrl !== '' ? '<img class="tex" src="' . $e($texUrl) . '" alt="">' : '') . '<div class="veil"></div>'
         . $panel
         . ($twoLogo !== 'none' ? '<img class="logo" src="' . $e($logoUrl) . '" alt="">' : '')
         . '</div></div></body></html>';
}
