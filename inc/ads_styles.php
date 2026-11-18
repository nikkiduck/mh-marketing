<?php
/**
 * inc/ads_styles.php — the digital ads' second LAYOUT, Full Bleed (Nikki,
 * 2026-11-18: "try creating some new layouts for the property ads ... think
 * outside the box design wise"; of six concepts shown that day she chose
 * this one: "Let's go with the Full Bleed. Some adjustments may have to be
 * made for readability on the really small ads"). The five she passed on
 * (Lower Third, Paper, Diagonal, Mosaic, Monogram) are in this file's git
 * history, commit d2f1be9.
 *
 *   classic   her InDesign set's layouts, inc/ads_render.php's own
 *   bleed     Full Bleed: the photo is the whole ad; the words sit on a soft
 *             scrim in the website's serif (Cormorant Garamond) over a thin
 *             slate rule and the spaced capitals; the white logo in a corner
 *
 * Full Bleed has all seven sizes; ads_bleed_sizes() is the whole design per
 * size. What changes as the ad shrinks, for readability: the serif goes from
 * weight 500 to 600, the scrim from a fade that is gone by two thirds of the
 * width to one that reaches further across (the two mobile sizes keep about
 * a quarter of it at the far edge), the lines under the headline drop away
 * (address, then specs) as Classic's do, and the rule goes. The serif is set
 * with lining figures at every size.
 *
 * Same contract as ads_render_frame(): one HTML document per slide, exactly
 * w x h, the preview and the export alike. ads_render_frame() hands a slide
 * here when the ad's `style` is bleed.
 */

/** The layouts, in menu order. */
function ads_styles(): array { return ['bleed' => 'Full Bleed', 'classic' => 'Classic']; }
const ADS_STYLE_DEFAULT = 'bleed';

/** The sizes a layout is built for (both have every size; kept so a layout in progress can have fewer). */
function ads_style_sizes(string $style): array { return array_keys(ads_sizes()); }

/** The layout a slide is really drawn in: the ad's, when its size is built for it; else Classic. */
function ads_style_for(array $ad): string {
    $style = (string)($ad['style'] ?? 'classic');
    return (isset(ads_styles()[$style]) && in_array((string)($ad['size'] ?? ''), ads_style_sizes($style), true)) ? $style : 'classic';
}

/**
 * Full Bleed, per size. `mode` where the words sit: mid (left, centred on the
 * height), bottom (lower left: the rectangle). `left` their inset, `tw` the
 * column's width. `h` the headline: size, weight, 1 or 2 lines. `rule` the
 * slate rule under it [width, gap above, gap below] or null. `caps` size and
 * letter-spacing; `gap` the space above the first line of capitals when there
 * is no rule, `gap2` between lines. `rows` which lines follow the headline:
 * full (address + city, then specs or price), strip (address, then specs or
 * price), last (specs or price alone), none; `price_leads` = the price
 * slide's headline is the price and the short specs follow (320 x 50).
 * `scrim` the fade behind the words, [opacity, % across] stops running right
 * (up, in bottom mode); `lscrim` the small one behind the logo [direction,
 * opacity, reach %]. `logo` [corner (br | tr | mr), right, bottom or top,
 * height]. `ag` one broker [headshot, name, phone, label, site, gap, room for
 * the name] (label / site 0 = not shown); `ag2` two [headshot, name, phone,
 * room] and `ag2_row` = the two side by side; `head_flush` = the headshots
 * sit flush left at the ad's full height, name and phone on one line each.
 */
function ads_bleed_sizes(): array {
    return [
        '970x250' => ['mode' => 'mid', 'left' => 38, 'tw' => 430, 'h' => [40, 500, 2], 'rule' => [40, 14, 12], 'caps' => [11.5, .16], 'gap' => 12, 'gap2' => 2, 'rows' => 'full',
            'scrim' => [[.93, 0], [.84, 26], [.35, 52], [0, 68]], 'lscrim' => ['to top left', .72, 24], 'logo' => ['br', 24, 18, 62],
            'ag' => [96, 34, 13.5, 10.5, 10.5, 20, 300], 'ag2' => [66, 27, 12, 330]],
        '300x250' => ['mode' => 'bottom', 'left' => 16, 'tw' => 268, 'h' => [25, 500, 2], 'rule' => [28, 8, 7], 'caps' => [8.5, .16], 'gap' => 7, 'gap2' => 1, 'rows' => 'full',
            'scrim' => [[.95, 0], [.84, 34], [.22, 62], [0, 76]], 'lscrim' => ['to bottom left', .62, 30], 'logo' => ['tr', 12, 12, 40],
            'ag' => [58, 21, 9.5, 8, 8, 11, 190], 'ag2' => [38, 17, 8.5, 210]],
        '728x90' => ['mode' => 'mid', 'left' => 22, 'tw' => 470, 'h' => [25, 600, 1], 'rule' => null, 'caps' => [9.5, .14], 'gap' => 5, 'gap2' => 0, 'rows' => 'strip',
            'scrim' => [[.94, 0], [.88, 40], [.45, 66], [0, 82]], 'lscrim' => ['to left', .78, 20], 'logo' => ['mr', 16, 0, 54],
            'ag' => [62, 23, 11, 9, 0, 14, 300], 'ag2' => [52, 18, 10, 150], 'ag2_row' => true],
        '970x90' => ['mode' => 'mid', 'left' => 26, 'tw' => 560, 'h' => [27, 600, 1], 'rule' => null, 'caps' => [10, .14], 'gap' => 5, 'gap2' => 0, 'rows' => 'strip',
            'scrim' => [[.94, 0], [.86, 36], [.4, 58], [0, 72]], 'lscrim' => ['to left', .78, 16], 'logo' => ['mr', 18, 0, 56],
            'ag' => [62, 24, 11, 9, 0, 14, 360], 'ag2' => [52, 19, 10, 180], 'ag2_row' => true],
        '320x100' => ['mode' => 'mid', 'left' => 13, 'tw' => 226, 'h' => [18, 600, 2], 'rule' => null, 'caps' => [8.5, .1], 'gap' => 5, 'gap2' => 0, 'rows' => 'last',
            'scrim' => [[.95, 0], [.9, 45], [.7, 68], [.26, 100]], 'lscrim' => ['to top left', .62, 34], 'logo' => ['br', 9, 9, 32],
            'ag' => [52, 17, 10.5, 0, 0, 9, 160], 'ag2' => [34, 14, 9, 170]],
        '320x50' => ['mode' => 'mid', 'left' => 10, 'tw' => 244, 'h' => [15.5, 600, 2], 'rule' => null, 'caps' => [8, .06], 'gap' => 2, 'gap2' => 0, 'rows' => 'none', 'price_leads' => true,
            'scrim' => [[.95, 0], [.9, 50], [.7, 72], [.3, 100]], 'lscrim' => ['to left', .62, 26], 'logo' => ['mr', 8, 0, 34],
            'ag' => [50, 15, 12, 0, 0, 10, 190], 'ag2' => [50, 10.5, 10.5, 150], 'head_flush' => true],
        '1920x400' => ['mode' => 'mid', 'left' => 64, 'tw' => 780, 'h' => [68, 500, 2], 'rule' => [66, 24, 20], 'caps' => [19, .16], 'gap' => 20, 'gap2' => 4, 'rows' => 'full',
            'scrim' => [[.93, 0], [.84, 24], [.35, 48], [0, 62]], 'lscrim' => ['to top left', .72, 20], 'logo' => ['br', 44, 34, 104],
            'ag' => [156, 58, 23, 17, 17, 34, 520], 'ag2' => [108, 46, 20, 560]],
    ];
}

/**
 * A slide's words, worked out once: `kind`; `head` (the headline's lines, two
 * at most; the address or the price when none was typed); `rows` (what
 * follows it: [class, text], class c = capitals, p = the price) and `rows0`
 * (the same with the address alone, no city); `price` and `short` (the price
 * as typed or listed, and "2 BD | 2 BA | 1,116 SF", for the smallest size);
 * `agents` (one or two).
 */
function ads_content(array $ad, int $i): array {
    $f = $ad['frames'][$i] ?? ($ad['frames'][0] ?? []);
    $kind = isset(ads_frame_kinds()[(string)($f['kind'] ?? '')]) ? (string)$f['kind'] : 'headline';
    $l = (array)($ad['listing'] ?? []);
    $headline = trim(str_replace("\r", '', (string)($f['headline'] ?? '')));
    $price = $price0 = trim((string)($f['price'] ?? ''));
    $short = social_short_address((string)($l['address'] ?? ''));
    $city = mb_strtoupper(trim((string)($l['city'] ?? '')));
    $addr = ads_address_caps($l); $addr0 = mb_strtoupper($short); $specs = ads_specs_caps($l);
    if ($kind !== 'agent' && $headline === '') {
        // No headline typed: the address leads (the price on the price slide), and the capitals below do not repeat it.
        if ($kind === 'price' && $price !== '') { $headline = $price; $price = ''; }
        else { $headline = $short; $addr = $city !== '' ? $city . ', COLORADO' : ''; $addr0 = $addr; }
    }
    $last = $kind === 'price' ? ($price !== '' ? ['p', $price] : ['c', $specs]) : ['c', $specs];
    $rows = array_values(array_filter([['c', $addr], $last], fn($r) => $r[1] !== ''));
    $rows0 = array_values(array_filter([['c', $addr0], $last], fn($r) => $r[1] !== ''));
    $head = array_slice(array_values(array_filter(array_map('trim', preg_split('/\n+/', $headline) ?: []), fn($x) => $x !== '')), 0, 2);
    $agents = array_slice(array_values(array_filter((array)($ad['agents'] ?? []))), 0, 2);
    return ['kind' => $kind, 'f' => $f, 'head' => $head, 'rows' => $rows, 'rows0' => $rows0, 'last' => $last[1] !== '' ? [$last] : [],
            'price' => $price0, 'short' => ads_specs_parts($l)['short'], 'agents' => $agents];
}

/** One slide in Full Bleed, as a complete HTML document. */
function ads_render_style(array $ad, int $i, ?string $base = null, bool $export = false): string {
    $base = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $size = (string)$ad['size'];
    $S = ads_size($size); $W = $S['w']; $H = $S['h'];
    $B = ads_bleed_sizes()[$size] ?? ads_bleed_sizes()['970x250'];
    $x = ads_content($ad, $i);
    $kind = $x['kind']; $f = $x['f']; $agents = $x['agents']; $two = $kind === 'agent' && count($agents) >= 2;
    $pal = ads_palette($base);
    $ink = '22,26,33';   // the scrim's near-black slate
    $logoFile = realpath(__DIR__ . '/../assets/images/MH_vert-stack_white.svg');
    $logo = ($export && $logoFile) ? 'file://' . $logoFile : $base . '/assets/images/MH_vert-stack_white.svg';   // from disk for the export, as Classic's

    // The photo, framed over the whole ad: zoom and focus as the Instagram tools' CSS (social_render.php's header).
    $src = ($export && !empty($f['photo_file'])) ? 'file://' . $f['photo_file'] : (string)($f['photo_url'] ?? '');
    $zoom = max(1.0, min(3.0, (float)($f['zoom'] ?? 1))); $fx = max(0, min(100, (int)($f['fx'] ?? 50))); $fy = max(0, min(100, (int)($f['fy'] ?? 50)));
    $photo = $src === '' ? '' : '<img src="' . $e($src) . '" alt="" style="display:block;width:' . round($zoom * 100, 2) . '%;height:' . round($zoom * 100, 2) . '%;object-fit:cover;object-position:' . $fx . '% ' . $fy . '%;'
           . 'transform:translate(' . -round(($zoom - 1) * $fx / 100 * $W, 1) . 'px,' . -round(($zoom - 1) * $fy / 100 * $H, 1) . 'px)">';

    // The words. On the smallest size the price slide has no room for a second headline: the price leads, the short specs follow.
    $head = $x['head'];
    $rows = match ($B['rows']) { 'full' => $x['rows'], 'strip' => $x['rows0'] ?: $x['rows'], 'last' => $x['last'], default => [] };
    if (!empty($B['price_leads']) && $kind === 'price') {
        if ($x['price'] !== '') $head = [$x['price']];
        $rows = $x['short'] !== '' ? [['c', $x['short']]] : [];
    }
    [$hSize, $hWeight, $hLines] = $B['h']; [$cSize, $ls] = $B['caps']; $tw = $B['tw'];
    $oneLine = $hLines === 1;
    $lines = $oneLine ? [implode(' ', $head)] : $head;
    // Fitted to the column: the longest line as typed, or about 0.58 of one long line the browser will break in two.
    // Cormorant runs at about .45 em a character at 500 and .47 at 600 (at .41 a headline broke mid-word).
    $per = $hWeight >= 600 ? .47 : .45;
    $long = max(array_map('mb_strlen', $lines ?: ['']));
    if (!$oneLine && count($lines) === 1 && $long * $hSize * $per > $tw) $long = (int)ceil($long * .58);
    $hs = ads_fit((float)$hSize, $long, $tw, $per, .66);
    $cs = (float)$cSize;
    foreach ($rows as [$cls, $text]) $cs = min($cs, ads_fit((float)$cSize, mb_strlen($text), $tw, .54 + $ls, .72));
    // Two typed lines stay two lines (never a third); one long line breaks where the browser balances it.
    $hHtml = '<h1 class="h"' . ((count($lines) === 2 || $oneLine) ? ' style="white-space:nowrap"' : '') . '>' . implode('<br>', array_map($e, $lines)) . '</h1>';
    $rule = $B['rule'];
    $wordsHtml = $hHtml . ($rule ? '<div class="rule"></div>' : '');
    foreach ($rows as [$cls, $text]) $wordsHtml .= '<p class="' . $cls . '">' . $e($text) . '</p>';

    // The Presented by block.
    $hsrc = fn(array $a) => ($export && !empty($a['headshot_file'])) ? 'file://' . $a['headshot_file'] : (string)($a['headshot_url'] ?? '');
    $tel = fn(array $a) => trim((string)($a['phone'] ?? '')) !== '' ? ads_phone_text((string)$a['phone']) : '';
    [$hd, $nm, $tl, $lb, $st, $gap, $room] = $B['ag']; [$hd2, $nm2, $tl2, $room2] = $B['ag2'];
    $flush = !empty($B['head_flush']);
    $nameSize = (float)($two ? $nm2 : $nm);
    foreach ($agents as $a) {
        $n = mb_strlen((string)($a['name'] ?? ''));
        if ($two && $flush) $n += 1 + (int)round(mb_strlen($tel($a)) * 1.15);   // name and phone share a line
        $nameSize = min($nameSize, ads_fit($nameSize, $n, (float)($two ? $room2 : $room), ($two && $flush) ? .5 : $per, .7));
    }
    $agHtml = '';
    if ($kind === 'agent') {
        $one = function (array $a, bool $extra, bool $img) use ($hsrc, $tel, $e, $lb, $st, $two, $flush): string {
            $inline = $two && $flush;
            return (($img && $hsrc($a) !== '') ? '<img class="hd" src="' . $e($hsrc($a)) . '" alt="">' : '') . '<div>' . (($extra && $lb > 0) ? '<p class="lbl">Presented by</p>' : '')
                 . '<p class="nm">' . $e((string)($a['name'] ?? '')) . ($inline && $tel($a) !== '' ? '<span class="tl">' . $e($tel($a)) . '</span>' : '') . '</p>'
                 . (!$inline && $tel($a) !== '' ? '<p class="tl">' . $e($tel($a)) . '</p>' : '') . (($extra && $st > 0) ? '<p class="st">monthaus.com</p>' : '') . '</div>';
        };
        if (!$two) $agHtml = '<div class="ag">' . $one($agents[0] ?? [], true, true) . '</div>';
        elseif ($flush) {   // the two headshots side by side at the ad's full height, then a line each
            $agHtml = '<div class="ag two">';
            foreach ($agents as $a) if ($hsrc($a) !== '') $agHtml .= '<img class="hd" src="' . $e($hsrc($a)) . '" alt="">';
            $agHtml .= '<div class="rows">';
            foreach ($agents as $a) $agHtml .= '<div class="row">' . $one($a, false, false) . '</div>';
            $agHtml .= '</div></div>';
        } else {
            $agHtml = '<div class="ag two">' . (($lb > 0 && empty($B['ag2_row'])) ? '<p class="lbl">Presented by</p>' : '');
            foreach ($agents as $a) $agHtml .= '<div class="row">' . $one($a, false, true) . '</div>';
            $agHtml .= '</div>';
        }
    }

    // The scrims: the one behind the words runs right from the left edge (up from the bottom on the rectangle); a small
    // one in the logo's corner where the first has already cleared.
    $stops = implode(',', array_map(fn($s) => "rgba({$ink},{$s[0]}) {$s[1]}%", $B['scrim']));
    $scrim = 'linear-gradient(' . ($B['mode'] === 'bottom' ? 'to top' : 'to right') . ',' . $stops . ')';
    if ($B['lscrim']) $scrim .= ",linear-gradient({$B['lscrim'][0]},rgba({$ink},{$B['lscrim'][1]}) 0%,rgba({$ink},0) {$B['lscrim'][2]}%)";
    [$lpos, $lr, $lv, $lh] = $B['logo'];
    $logoCss = match ($lpos) { 'tr' => "right:{$lr}px;top:{$lv}px", 'mr' => "right:{$lr}px;top:50%;transform:translateY(-50%)", default => "right:{$lr}px;bottom:{$lv}px" };
    $place = $B['mode'] === 'bottom' ? "left:{$B['left']}px;bottom:14px" : "left:{$B['left']}px;top:50%;transform:translateY(-50%)";
    $hdNow = $two ? $hd2 : $hd; $tlNow = $two ? $tl2 : $tl;
    // Lining figures: Cormorant's own numerals are old-style (a 0 the height of an o), which reads badly in an address or a
    // price at these sizes.
    $serif = "font-family:'Cormorant Garamond',Georgia,serif;font-variant-numeric:lining-nums;font-feature-settings:'lnum' 1";
    $soft = '#d6dee8';

    $css = "html,body{margin:0;padding:0;width:{$W}px;height:{$H}px;overflow:hidden;background:{$pal['panel']}}\n" . social_font_css($base, $export) . "\n"
         . ".ad{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;font-family:'Jost','Helvetica Neue',Helvetica,Arial,sans-serif;color:#fff;-webkit-font-smoothing:antialiased}"
         . "p,h1{margin:0}.ph{position:absolute;left:0;top:0;width:{$W}px;height:{$H}px;overflow:hidden;background:{$pal['photo_bg']}}"
         . ".scrim{position:absolute;left:0;top:0;right:0;bottom:0;background:{$scrim}}"
         . ".w{position:absolute;{$place};width:{$tw}px}"
         . ".h{{$serif};font-weight:{$hWeight};font-size:{$hs}px;line-height:" . ($hSize >= 30 ? 1.04 : 1.07) . ";text-wrap:balance}"
         . ($rule ? ".rule{width:{$rule[0]}px;height:1px;background:{$pal['accent']};margin:{$rule[1]}px 0 {$rule[2]}px}" : '')
         . ".c,.p{font-weight:500;text-transform:uppercase;letter-spacing:{$ls}em;line-height:1.5;white-space:nowrap}"
         . ".c{font-size:{$cs}px;color:{$soft}}.p{font-size:" . round($cs + max(1, $cSize * .17), 1) . "px}"
         . ($rule ? '' : ".h+.c,.h+.p{margin-top:{$B['gap']}px}") . ".c+.c,.c+.p{margin-top:{$B['gap2']}px}"
         . ".logo{position:absolute;{$logoCss};height:{$lh}px}"
         // Presented by.
         . ".ag{position:absolute;{$place};display:flex;align-items:center;gap:{$gap}px}"
         . ".hd{flex:none;display:block;width:{$hdNow}px;height:{$hdNow}px;object-fit:cover;object-position:50% 18%}"
         . ".lbl{font-size:{$lb}px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:{$soft};line-height:1.4;margin-bottom:" . round($lb * .45) . "px;white-space:nowrap}"
         . ".nm{{$serif};font-weight:{$hWeight};font-size:{$nameSize}px;line-height:1.08;white-space:nowrap}"
         . ".tl{font-size:{$tlNow}px;font-weight:500;letter-spacing:.14em;line-height:1.35;margin-top:" . round($tlNow * ($two ? .2 : .45)) . "px;white-space:nowrap}"
         . ".st{font-size:{$st}px;font-weight:500;letter-spacing:.2em;color:{$soft};line-height:1.4;margin-top:" . round($st * .3) . "px}";
    if ($two && $flush) {
        $css .= ".ag{left:0;gap:0}.rows{margin-left:{$gap}px}.row+.row{margin-top:3px}"
              . ".nm{font-family:inherit;font-weight:500;font-size:{$nameSize}px}.nm .tl{display:inline;margin:0 0 0 .5em;font-size:{$nameSize}px;font-weight:400;letter-spacing:.02em;color:{$soft}}";
    } elseif ($two && !empty($B['ag2_row'])) {
        $css .= ".ag{gap:" . round($gap * 1.9) . "px}.row{display:flex;align-items:center;gap:" . round($gap * .75) . "px}";
    } elseif ($two) {
        $css .= ".ag{display:block}.ag .lbl{margin-bottom:" . round($hd2 * .14) . "px}.row{display:flex;align-items:center;gap:" . round($gap * .7) . "px;margin-top:" . round($hd2 * .13) . "px}.row:first-of-type{margin-top:0}";
    } elseif ($flush) {
        $css .= ".ag{left:0}";
    }

    return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Ad</title><style>{$css}</style></head><body><div class=\"ad\">"
         . '<div class="ph">' . $photo . '</div><div class="scrim"></div>'
         . ($kind === 'agent' ? $agHtml : '<div class="w">' . $wordsHtml . '</div>')
         . '<img class="logo" src="' . $e($logo) . '" alt=""></div></body></html>';
}
