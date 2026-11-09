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
 * One size in the prototype, 970 x 250 (the marquee, the one most of the
 * current set is built at); ads_sizes() is where the others go, each with
 * its own layout rules, because a 320 x 50 cannot hold what a marquee holds.
 *
 * Standalone (no portal.php, no database) so a harness can render sample
 * data locally: tests/render_ads_sample.php.
 */
require_once __DIR__ . '/social_render.php';   // social_font_css(), social_short_address(), social_num(), social_price_text()

/** The GIF has to come in under this (the newspapers' 1 MB cap); the export drops colours until it does. */
const ADS_GIF_MAX = 1000000;

/** The ad sizes, each with its layout numbers: the photo's width, the panel padding, the type sizes. */
function ads_sizes(): array {
    return [
        '970x250' => ['w' => 970, 'h' => 250, 'name' => 'Marquee 970 x 250', 'pad' => 30, 'fade' => 110, 'logo_h' => 74, 'words_w' => 350,
                      'h_size' => 31, 'h_steps' => [46 => 28, 62 => 25, 78 => 22], 'caps' => 12.5, 'head_px' => 100, 'head2_px' => 70],
    ];
}
function ads_size(string $k): array { return ads_sizes()[$k] ?? ads_sizes()['970x250']; }

/** How much of the width the photo takes (Nikki, 2026-11-09: "more of the photo, don't just split in half"); its right edge fades into the panel. */
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

/**
 * One slide as a complete HTML document, exactly w x h. $ad is the normalised
 * ad (ads_from_request() in inc/ads.php, or a harness): size, share (the
 * photo's width in %), texture, listing (address, city, beds, baths, sqft),
 * agents (one or two: name, phone, headshot url + file) and frames, each
 * {kind, headline, price, photo_url,
 * photo_file, zoom, fx, fy}. $export reads the photo, the texture and the
 * headshot from disk (file://) where a file is known and embeds the fonts.
 */
function ads_render_frame(array $ad, int $i, ?string $base = null, bool $export = false): string {
    $base = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $S    = ads_size((string)($ad['size'] ?? '970x250'));
    $W = $S['w']; $H = $S['h']; $pad = $S['pad'];
    $share = isset(ads_shares()[(string)($ad['share'] ?? '')]) ? (int)$ad['share'] : (int)ADS_SHARE_DEFAULT;
    $PW   = (int)round($W * $share / 100);        // the photo's width; its last `fade` px melt into the panel colour
    $c    = ads_palette($base);
    $f    = $ad['frames'][$i] ?? ($ad['frames'][0] ?? []);
    $kind = isset(ads_frame_kinds()[(string)($f['kind'] ?? '')]) ? (string)$f['kind'] : 'headline';
    $l    = (array)($ad['listing'] ?? []);
    $agents = array_values(array_filter((array)($ad['agents'] ?? (isset($ad['agent']) ? [$ad['agent']] : []))));
    $two  = $kind === 'agent' && count($agents) >= 2;
    // The words' column (inside .panel, so its left is the padding): from the photo's edge to the panel's right padding;
    // the type scales down as the column narrows.
    $wordsW = $W - $PW - $pad - 24;
    $k = min(1.0, $wordsW / (int)$S['words_w']);

    // The photo frame: zoom 1 to 3, focus 0 to 100 on each axis, the Instagram tools' CSS (see social_render.php's header).
    $zoom = max(1.0, min(3.0, (float)($f['zoom'] ?? 1)));
    $fx   = max(0, min(100, (int)($f['fx'] ?? 50)));
    $fy   = max(0, min(100, (int)($f['fy'] ?? 50)));
    $tx   = -round(($zoom - 1) * $fx / 100 * $PW, 1);
    $ty   = -round(($zoom - 1) * $fy / 100 * $H, 1);
    $zpct = round($zoom * 100, 2);
    $photo = ($export && !empty($f['photo_file'])) ? 'file://' . $f['photo_file'] : (string)($f['photo_url'] ?? '');

    // The texture behind the words: a stock photo, grayscale and ghosted, under a veil that is solid at the photo's edge
    // and thins to the right, so the words sit on a clean ground and the ridge line shows behind the logo.
    $tex = (string)($ad['texture'] ?? '');
    $texUrl = '';
    if ($tex !== '') {
        $file = __DIR__ . '/../assets/stock/' . $tex . '.jpg';
        $texUrl = ($export && is_file($file)) ? 'file://' . (realpath($file) ?: $file) : $base . '/assets/stock/' . $tex . '.jpg';
    }

    // The words.
    $headline = trim(str_replace("\r", '', (string)($f['headline'] ?? '')));
    $addr  = ads_address_caps($l);
    $capsSize = social_fit_size($addr, (int)$S['caps'] * 2, [30 => (int)$S['caps'] * 2 - 2, 37 => (int)$S['caps'] * 2 - 4]) / 2;   // 12.5 → 11.5 → 10.5 for a long address
    $specs = ads_specs_caps($l);
    $price = trim((string)($f['price'] ?? ''));
    $short = social_short_address((string)($l['address'] ?? ''));
    if ($kind !== 'agent' && $headline === '') {
        // No headline typed: the address leads (the price on the price slide), and the capitals line below is not repeated.
        $headline = ($kind === 'price' && $price !== '') ? $price : $short;
        if ($kind === 'price' && $price !== '') { $price = ''; } else { $addr = trim((string)($l['city'] ?? '')) !== '' ? mb_strtoupper(trim((string)$l['city'])) . ', COLORADO' : ''; }
    }
    $hsize = (int)round(social_fit_size(preg_replace('/\s*\n\s*/', ' ', $headline), (int)$S['h_size'], $S['h_steps']) * $k);
    $capsSize = round($capsSize * $k, 1);
    $capsBase = round((int)$S['caps'] * $k, 1);
    $hHtml = implode('<br>', array_map($e, array_slice(preg_split('/\n+/', $headline) ?: [], 0, 2)));

    $fontCss = social_font_css($base, $export);
    $headSrc = fn(array $a) => ($export && !empty($a['headshot_file'])) ? 'file://' . $a['headshot_file'] : (string)($a['headshot_url'] ?? '');
    $hp = (int)($two ? $S['head2_px'] : $S['head_px']);
    // The logo from disk for the export, like the texture (the https copy came up as a broken image in the first server render, 2026-11-09).
    // On a two-broker slide the full stack has no room beside two names at the wider photo widths, so the M mark stands
    // in, small, upper right (the InDesign set's two-broker frame carried no logo at all).
    $logoName = $two ? 'M-mark_white.svg' : 'MH_vert-stack_white.svg';
    $logoFile = realpath(__DIR__ . '/../assets/images/' . $logoName);
    $logoUrl = ($export && $logoFile) ? 'file://' . $logoFile : $base . '/assets/images/' . $logoName;

    $css = "html,body{margin:0;padding:0;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['panel']}}\n{$fontCss}\n"
         . ".ad{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;font-family:'Jost','Helvetica Neue',Helvetica,Arial,sans-serif;color:{$c['text']};-webkit-font-smoothing:antialiased}\n"
         . ".photo{position:absolute;left:0;top:0;width:{$PW}px;height:{$H}px;overflow:hidden;background:{$c['photo_bg']}}\n"
         // The photo's right edge melts into the panel (Nikki, 2026-11-09: more of the photo, no hard split).
         . ".photo::after{content:'';position:absolute;right:0;top:0;bottom:0;width:{$S['fade']}px;background:linear-gradient(to right,rgba({$c['panel_rgb']},0) 0%,rgba({$c['panel_rgb']},.55) 55%,rgba({$c['panel_rgb']},1) 100%)}\n"
         . ".photo img.pic{display:block;width:{$zpct}%;height:{$zpct}%;object-fit:cover;object-position:{$fx}% {$fy}%;transform:translate({$tx}px,{$ty}px)}\n"
         . ".panel{position:absolute;left:{$PW}px;top:0;right:0;bottom:0;background:{$c['panel']};overflow:hidden}\n"
         . ".tex{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;object-position:50% 40%;filter:grayscale(1) brightness(.78) contrast(1.05);opacity:.34}\n"
         . ".veil{position:absolute;left:0;top:0;right:0;bottom:0;background:linear-gradient(to right,rgba({$c['panel_rgb']},1) 0%,rgba({$c['panel_rgb']},.9) 34%,rgba({$c['panel_rgb']},.5) 72%,rgba({$c['panel_rgb']},.3) 100%)}\n"
         . ".words{position:absolute;left:{$pad}px;top:" . round(30 * $k + 2) . "px;width:{$wordsW}px}\n"
         . ".h{margin:0;font-weight:300;font-size:{$hsize}px;line-height:1.18;letter-spacing:.004em;text-wrap:balance}\n"
         . ".caps{margin:" . round(15 * $k) . "px 0 0;font-size:{$capsSize}px;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:{$c['accent']};line-height:1.5}\n"
         . ".caps+.caps{margin-top:3px}\n"
         . ".price{margin:12px 0 0;font-size:" . ($capsBase + 2.5) . "px;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:{$c['text']};line-height:1.4}\n"
         // The logo lower right; on a two-broker slide it moves to the upper right, where the Presented by line leaves it room.
         . ($two ? ".logo{position:absolute;right:24px;top:22px;height:" . round($S['logo_h'] * .56) . "px;width:auto}\n"
                 : ".logo{position:absolute;right:24px;bottom:20px;height:{$S['logo_h']}px;width:auto}\n")
         // The Presented by slide: the headshot, then the words beside it.
         . ".pres{position:absolute;left:{$pad}px;top:48%;transform:translateY(-50%);width:{$wordsW}px;display:flex;align-items:center;gap:" . round(22 * $k) . "px}\n"
         . ".head{flex:none;width:{$hp}px;height:{$hp}px;object-fit:cover;object-position:50% 18%;background:{$c['photo_bg']};border:1px solid rgba(255,255,255,.18)}\n"
         . ".pres .caps{margin:0 0 6px;font-size:{$capsBase}px}.pres .name{margin:0;font-weight:300;font-size:" . round(($S['h_size'] - 5) * $k) . "px;line-height:1.15;white-space:nowrap}\n"
         . ".pres .tel{margin:8px 0 0;font-size:" . ($capsBase + 1.5) . "px;font-weight:500;letter-spacing:.2em;color:{$c['text']};line-height:1.4}\n"
         . ".pres .site{margin:4px 0 0;font-size:{$capsBase}px;font-weight:500;letter-spacing:.2em;color:{$c['accent']}}\n"
         // Two brokers (Nikki, 2026-11-09): the Presented by line, then a row per broker, headshot beside name and phone.
         . ".pres2{position:absolute;left:{$pad}px;top:50%;transform:translateY(-50%);width:{$wordsW}px}\n"
         . ".pres2 .caps{margin:0 0 " . round(10 * $k) . "px;font-size:{$capsBase}px}\n"
         . ".pres2 .row{display:flex;align-items:center;gap:" . round(16 * $k) . "px;margin-top:" . round(10 * $k) . "px}\n"
         . ".pres2 .name{margin:0;font-weight:300;font-size:" . round(($S['h_size'] - 8) * $k) . "px;line-height:1.15;white-space:nowrap}\n"
         . ".pres2 .tel{margin:3px 0 0;font-size:" . round(($capsBase + .5) * 10) / 10 . "px;font-weight:500;letter-spacing:.18em;color:{$c['text']};line-height:1.3}\n"
         . ".pres2 .site{margin:" . round(10 * $k) . "px 0 0;font-size:" . round(($capsBase - 1) * 10) / 10 . "px;font-weight:500;letter-spacing:.2em;color:{$c['accent']}}\n";

    $panel = '';
    if ($two) {
        $panel = '<div class="pres2"><p class="caps">Presented by</p>';
        foreach (array_slice($agents, 0, 2) as $a) {
            $src = $headSrc($a);
            $panel .= '<div class="row">' . ($src !== '' ? '<img class="head" src="' . $e($src) . '" alt="">' : '')
                    . '<div><p class="name">' . $e((string)($a['name'] ?? '')) . '</p>'
                    . (trim((string)($a['phone'] ?? '')) !== '' ? '<p class="tel">' . $e(ads_phone_text((string)$a['phone'])) . '</p>' : '') . '</div></div>';
        }
        $panel .= '<p class="site">monthaus.com</p></div>';
    } elseif ($kind === 'agent') {
        $a = $agents[0] ?? [];
        $src = $headSrc($a);
        $panel = '<div class="pres">' . ($src !== '' ? '<img class="head" src="' . $e($src) . '" alt="">' : '')
               . '<div><p class="caps">Presented by</p><p class="name">' . $e((string)($a['name'] ?? '')) . '</p>'
               . (trim((string)($a['phone'] ?? '')) !== '' ? '<p class="tel">' . $e(ads_phone_text((string)$a['phone'])) . '</p>' : '')
               . '<p class="site">monthaus.com</p></div></div>';
    } else {
        $panel = '<div class="words"><h1 class="h">' . $hHtml . '</h1>'
               . ($addr !== '' ? '<p class="caps">' . $e($addr) . '</p>' : '')
               . ($kind === 'price'
                    ? ($price !== '' ? '<p class="price">' . $e($price) . '</p>' : ($specs !== '' ? '<p class="caps">' . $e($specs) . '</p>' : ''))
                    : ($specs !== '' ? '<p class="caps">' . $e($specs) . '</p>' : ''))
               . '</div>';
    }

    return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Ad</title><style>{$css}</style></head><body>"
         . '<div class="ad">'
         . '<div class="photo">' . ($photo !== '' ? '<img class="pic" src="' . $e($photo) . '" alt="">' : '') . '</div>'
         . '<div class="panel">' . ($texUrl !== '' ? '<img class="tex" src="' . $e($texUrl) . '" alt="">' : '') . '<div class="veil"></div>'
         . $panel
         . '<img class="logo" src="' . $e($logoUrl) . '" alt="">'
         . '</div></div></body></html>';
}
