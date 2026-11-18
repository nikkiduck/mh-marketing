<?php
/**
 * inc/ads_styles.php — the digital ads' other LAYOUTS (Nikki, 2026-11-18:
 * "try creating some new layouts for the property ads ... think outside the
 * box design wise"). CONCEPTS, built for the two sizes a direction is judged
 * at, the marquee (970 x 250) and the rectangle (300 x 250); the other five
 * sizes follow once she picks. Classic (inc/ads_render.php's own layouts,
 * her InDesign set) stays the default and the only layout with every size.
 *
 *   bleed   Full Bleed    the photo is the whole ad; the words sit on a soft
 *                         scrim in the website's serif, the logo in a corner
 *   band    Lower Third   the photo is the whole ad; one slim translucent
 *                         band along the bottom carries everything
 *   paper   Paper         the photo is the whole ad; a card in the website's
 *                         light scheme (off-white, slate type, black logo)
 *                         floats on it
 *   diag    Diagonal      photo and panel meet on the angle of the M (30
 *                         degrees off vertical), a light slate stripe along it
 *   mosaic  Mosaic        three photos a slide: one large, two small
 *   mono    Monogram      the photo in full color through a giant M mark,
 *                         darkened and gray everywhere else
 *
 * Same contract as ads_render_frame(): one HTML document per slide, exactly
 * w x h, the preview and the export alike. ads_render_frame() hands a slide
 * here when the ad's `style` is one of these and its size is built for it
 * (ads_style_for()); anything else is drawn Classic.
 */

/** The layouts, in menu order. */
function ads_styles(): array {
    return ['classic' => 'Classic', 'bleed' => 'Full Bleed', 'band' => 'Lower Third', 'paper' => 'Paper', 'diag' => 'Diagonal', 'mosaic' => 'Mosaic', 'mono' => 'Monogram'];
}

/** The sizes a layout is built for. */
function ads_style_sizes(string $style): array {
    return ($style === 'classic' || !isset(ads_styles()[$style])) ? array_keys(ads_sizes()) : ['970x250', '300x250'];
}

/** The layout a slide is really drawn in: the ad's, when its size is built for it; else Classic. */
function ads_style_for(array $ad): string {
    $style = (string)($ad['style'] ?? 'classic');
    return (isset(ads_styles()[$style]) && in_array((string)($ad['size'] ?? ''), ads_style_sizes($style), true)) ? $style : 'classic';
}

/** The M mark as a clip-path polygon (assets/images/M-mark_white.svg's points as percentages), so no mask file is fetched. */
const ADS_M_CLIP = 'polygon(99.87% 0,85.68% 0,50.17% 64.35%,43.02% 51.66%,71.49% 0,0 0,0 100%,16.99% 100%,16.99% 37.08%,50.01% 90.55%,82.9% 36.72%,83.01% 100%,100% 100%)';

/**
 * A slide's words, worked out once for every layout: `kind`; `head` (the
 * headline's lines, two at most; the address or the price when none was
 * typed); `rows` (what follows it: [class, text], class c = capitals, p =
 * the price) and `rows0` (the same without the city, for a layout that
 * shows the city on its own); `city`; `agents` (one or two).
 */
function ads_content(array $ad, int $i): array {
    $f = $ad['frames'][$i] ?? ($ad['frames'][0] ?? []);
    $kind = isset(ads_frame_kinds()[(string)($f['kind'] ?? '')]) ? (string)$f['kind'] : 'headline';
    $l = (array)($ad['listing'] ?? []);
    $headline = trim(str_replace("\r", '', (string)($f['headline'] ?? '')));
    $price = trim((string)($f['price'] ?? ''));
    $short = social_short_address((string)($l['address'] ?? ''));
    $city = mb_strtoupper(trim((string)($l['city'] ?? '')));
    $addr = ads_address_caps($l); $addr0 = mb_strtoupper($short); $specs = ads_specs_caps($l);
    $cityLine = $city !== '' ? $city . ', COLORADO' : '';
    if ($kind !== 'agent' && $headline === '') {
        // No headline typed: the address leads (the price on the price slide), and the capitals below do not repeat it.
        if ($kind === 'price' && $price !== '') { $headline = $price; $price = ''; }
        else { $headline = $short; $addr = $cityLine; $addr0 = ''; }
    }
    $last = $kind === 'price' ? ($price !== '' ? ['p', $price] : ['c', $specs]) : ['c', $specs];
    $rows = array_values(array_filter([['c', $addr], $last], fn($r) => $r[1] !== ''));
    $rows0 = array_values(array_filter([['c', $addr0], $last], fn($r) => $r[1] !== ''));
    $head = array_slice(array_values(array_filter(array_map('trim', preg_split('/\n+/', $headline) ?: []), fn($x) => $x !== '')), 0, 2);
    $agents = array_slice(array_values(array_filter((array)($ad['agents'] ?? []))), 0, 2);
    return ['kind' => $kind, 'f' => $f, 'head' => $head, 'rows' => $rows, 'rows0' => $rows0, 'city' => $cityLine, 'agents' => $agents];
}

/**
 * The photos a slide can draw on, the slide's own first, then its right-hand
 * photo, then the other slides', no picture twice: each [src, zoom, fx, fy].
 * The Mosaic takes three; every other layout the first.
 */
function ads_slide_photos(array $ad, int $i, bool $export): array {
    $n = count($ad['frames']); $out = []; $seen = [];
    for ($k = 0; $k < $n; $k++) {
        $f = $ad['frames'][($i + $k) % $n];
        foreach (['', '2'] as $sfx) {
            $src = ($export && !empty($f['photo' . $sfx . '_file'])) ? 'file://' . $f['photo' . $sfx . '_file'] : (string)($f['photo' . $sfx . '_url'] ?? '');
            if ($src === '' || isset($seen[$src])) continue;
            $seen[$src] = true;
            $out[] = ['src' => $src, 'zoom' => max(1.0, min(3.0, (float)($f['zoom' . $sfx] ?? 1))), 'fx' => max(0, min(100, (int)($f['fx' . $sfx] ?? 50))), 'fy' => max(0, min(100, (int)($f['fy' . $sfx] ?? 50)))];
        }
    }
    return $out;
}

/** One slide in one of the new layouts, as a complete HTML document. */
function ads_render_style(array $ad, int $i, ?string $base = null, bool $export = false): string {
    $base  = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $style = ads_style_for($ad);
    $S = ads_size((string)$ad['size']); $W = $S['w']; $H = $S['h']; $big = $W >= 900;
    $x = ads_content($ad, $i);
    $kind = $x['kind']; $agents = $x['agents']; $two = $kind === 'agent' && count($agents) >= 2;
    $pics = ads_slide_photos($ad, $i, $export);
    $pal = ads_palette($base);
    $ink = '22,26,33';   // the scrims' near-black slate
    $asset = function (string $rel) use ($base, $export): string { $f = realpath(__DIR__ . '/../' . $rel); return ($export && $f) ? 'file://' . $f : $base . '/' . $rel; };
    $logoW = $asset('assets/images/MH_vert-stack_white.svg'); $logoB = $asset('assets/images/MH_vert-stack_black.svg');
    $tex = (string)($ad['texture'] ?? '');
    $texHtml = ($tex !== '' && is_file(__DIR__ . '/../assets/stock/' . $tex . '.jpg')) || ($tex !== '' && !$export)
        ? '<img class="tex" src="' . $e($asset('assets/stock/' . $tex . '.jpg')) . '" alt=""><div class="veil"></div>' : '';

    // A photo framed in a box: zoom and focus as the Instagram tools' CSS (social_render.php's header), inline.
    $img = function (?array $p, int $bw, int $bh) use ($e): string {
        if (!$p) return '';
        $z = round($p['zoom'] * 100, 2);
        return '<img src="' . $e($p['src']) . '" alt="" style="display:block;width:' . $z . '%;height:' . $z . '%;object-fit:cover;object-position:' . $p['fx'] . '% ' . $p['fy'] . '%;'
             . 'transform:translate(' . -round(($p['zoom'] - 1) * $p['fx'] / 100 * $bw, 1) . 'px,' . -round(($p['zoom'] - 1) * $p['fy'] / 100 * $bh, 1) . 'px)">';
    };
    $p0 = $pics[0] ?? null;

    // The words: the headline fitted to its column (the longest line as typed, or about 0.58 of one long line that
    // will break in two), the capitals one size fitted to the longest.
    $head = $x['head'];
    $fitH = function (float $size, float $width, float $per, bool $oneLine = false) use ($head): float {
        $lines = $oneLine ? [implode(' ', $head)] : $head;
        $long = max(array_map('mb_strlen', $lines ?: ['']));
        if (!$oneLine && count($lines) === 1 && $long * $size * $per > $width) $long = (int)ceil($long * .58);
        return ads_fit($size, $long, $width, $per, .66);
    };
    $fitC = function (float $size, array $rows, float $width, float $ls): float {
        foreach ($rows as [$cls, $text]) $size = min($size, ads_fit($size, mb_strlen($text), $width, .54 + $ls, .72));
        return $size;
    };
    // Two typed lines stay two lines (never a third); one long line breaks where the browser balances it.
    $hHtml = fn(bool $oneLine = false) => '<h1 class="h"' . (count($head) === 2 && !$oneLine ? ' style="white-space:nowrap"' : '') . '>' . ($oneLine ? $e(implode(' ', $head)) : implode('<br>', array_map($e, $head))) . '</h1>';
    $rowsHtml = function (array $rows) use ($e): string { $o = ''; foreach ($rows as [$cls, $text]) $o .= '<p class="' . $cls . '">' . $e($text) . '</p>'; return $o; };

    // The Presented by block: one broker = headshot beside label, name, phone, site; two = a row each under the label.
    $hs = fn(array $a) => ($export && !empty($a['headshot_file'])) ? 'file://' . $a['headshot_file'] : (string)($a['headshot_url'] ?? '');
    $tel = fn(array $a) => trim((string)($a['phone'] ?? '')) !== '' ? ads_phone_text((string)$a['phone']) : '';
    $agHtml = function () use ($agents, $two, $hs, $tel, $e): string {
        $one = function (array $a, bool $extra) use ($hs, $tel, $e): string {
            return ($hs($a) !== '' ? '<img class="hd" src="' . $e($hs($a)) . '" alt="">' : '') . '<div>' . ($extra ? '<p class="lbl">Presented by</p>' : '')
                 . '<p class="nm">' . $e((string)($a['name'] ?? '')) . '</p>' . ($tel($a) !== '' ? '<p class="tl">' . $e($tel($a)) . '</p>' : '') . ($extra ? '<p class="st">monthaus.com</p>' : '') . '</div>';
        };
        if (!$two) return '<div class="ag">' . $one($agents[0] ?? [], true) . '</div>';
        $o = '<div class="ag two"><p class="lbl">Presented by</p>';
        foreach ($agents as $a) $o .= '<div class="row">' . $one($a, false) . '</div>';
        return $o . '</div>';
    };
    $fitN = function (float $size, float $width, float $per) use ($agents): float {
        foreach ($agents as $a) $size = min($size, ads_fit($size, mb_strlen((string)($a['name'] ?? '')), $width, $per, .7));
        return $size;
    };
    // The type the layouts share: n = [headshot, name, phone, label, site] sizes for one broker, m = [headshot, name, phone] for two.
    $agCss = function (array $n, array $m, string $nameFont, string $nameColor, string $telColor, string $soft, int $gap) use ($two): string {
        [$hd, $nm, $tl, $lb, $st] = $n; [$hd2, $nm2, $tl2] = $m;
        return ".ag{display:flex;align-items:center;gap:{$gap}px}.ag.two{display:block}"
             . ".hd{flex:none;display:block;width:" . ($two ? $hd2 : $hd) . "px;height:" . ($two ? $hd2 : $hd) . "px;object-fit:cover;object-position:50% 18%}"
             . ".lbl{font-size:{$lb}px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:{$soft};line-height:1.4;margin-bottom:" . round($lb * .45) . "px;white-space:nowrap}"
             . ".nm{{$nameFont};font-size:" . ($two ? $nm2 : $nm) . "px;line-height:1.08;color:{$nameColor};white-space:nowrap}"
             . ".tl{font-size:" . ($two ? $tl2 : $tl) . "px;font-weight:500;letter-spacing:.16em;color:{$telColor};line-height:1.35;margin-top:" . round(($two ? $tl2 : $tl) * ($two ? .25 : .5)) . "px;white-space:nowrap}"
             . ".st{font-size:{$st}px;font-weight:500;letter-spacing:.2em;color:{$soft};line-height:1.4;margin-top:" . round($st * .3) . "px}"
             . ".ag.two .lbl{margin-bottom:" . round($hd2 * .14) . "px}.row{display:flex;align-items:center;gap:" . round($gap * .7) . "px;margin-top:" . round($hd2 * .13) . "px}.row:first-of-type{margin-top:0}";
    };
    $serif = "font-family:'Cormorant Garamond',Georgia,serif;font-weight:500";
    $sans3 = "font-weight:300"; $sans4 = "font-weight:400";

    $css = "html,body{margin:0;padding:0;width:{$W}px;height:{$H}px;overflow:hidden;background:{$pal['panel']}}\n" . social_font_css($base, $export) . "\n"
         . ".ad{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;font-family:'Jost','Helvetica Neue',Helvetica,Arial,sans-serif;color:#fff;-webkit-font-smoothing:antialiased}"
         . "p,h1{margin:0}.ph{position:absolute;overflow:hidden;background:{$pal['photo_bg']}}.full{left:0;top:0;width:{$W}px;height:{$H}px}"
         . ".h{text-wrap:balance}.c,.p{font-weight:500;text-transform:uppercase;letter-spacing:.16em;line-height:1.5;white-space:nowrap}"
         . ".tex{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;object-position:50% 40%;filter:grayscale(1) brightness(.78) contrast(1.05);opacity:.34}"
         . ".veil{position:absolute;left:0;top:0;right:0;bottom:0;background:linear-gradient(to right,rgba({$pal['panel_rgb']},1) 0%,rgba({$pal['panel_rgb']},.9) 34%,rgba({$pal['panel_rgb']},.5) 72%,rgba({$pal['panel_rgb']},.3) 100%)}\n";
    $body = '';
    $words = fn(array $rows, bool $rule = false, bool $oneLine = false) => $hHtml($oneLine) . ($rule ? '<div class="rule"></div>' : '') . $rowsHtml($rows);

    if ($style === 'bleed') {
        // The photo is the whole ad. The words sit on a scrim that is deep where they are and gone by the middle; a
        // second, small scrim in the far corner carries the logo.
        if ($big) {
            $tw = 430; $hs_ = $fitH(40, $tw, .45); $cs = $fitC(11.5, $x['rows'], $tw, .16);
            $css .= ".scrim{position:absolute;left:0;top:0;right:0;bottom:0;background:linear-gradient(to right,rgba({$ink},.93) 0%,rgba({$ink},.84) 26%,rgba({$ink},.35) 52%,rgba({$ink},0) 68%),linear-gradient(to top left,rgba({$ink},.72) 0%,rgba({$ink},0) 24%)}"
                  . ".w,.ag{position:absolute;left:38px;top:50%;transform:translateY(-50%)}.w{width:{$tw}px}"
                  . ".h{{$serif};font-size:{$hs_}px;line-height:1.04}.rule{width:40px;height:1px;background:{$pal['accent']};margin:14px 0 12px}"
                  . ".c{font-size:{$cs}px;color:#d6dee8}.c+.c,.c+.p{margin-top:2px}.p{font-size:" . ($cs + 2) . "px}"
                  . ".logo{position:absolute;right:24px;bottom:18px;height:62px}"
                  . $agCss([96, $fitN(34, 300, .45), 13.5, 10.5, 10.5], [66, $fitN(27, 330, .45), 12], $serif, '#fff', '#fff', '#d6dee8', 20);
        } else {
            $tw = 268; $hs_ = $fitH(25, $tw, .45); $cs = $fitC(8.5, $x['rows'], $tw, .16);
            $css .= ".scrim{position:absolute;left:0;top:0;right:0;bottom:0;background:linear-gradient(to top,rgba({$ink},.95) 0%,rgba({$ink},.84) 34%,rgba({$ink},.22) 62%,rgba({$ink},0) 76%),linear-gradient(to bottom left,rgba({$ink},.62) 0%,rgba({$ink},0) 30%)}"
                  . ".w,.ag{position:absolute;left:16px;bottom:14px}.w{right:16px}"
                  . ".h{{$serif};font-size:{$hs_}px;line-height:1.05}.rule{width:28px;height:1px;background:{$pal['accent']};margin:8px 0 7px}"
                  . ".c{font-size:{$cs}px;color:#d6dee8}.c+.c,.c+.p{margin-top:1px}.p{font-size:" . ($cs + 1.5) . "px}"
                  . ".logo{position:absolute;right:12px;top:12px;height:40px}"
                  . $agCss([58, $fitN(21, 190, .45), 9.5, 8, 8], [38, $fitN(17, 210, .45), 8.5], $serif, '#fff', '#fff', '#d6dee8', 11);
        }
        $body = '<div class="ph full">' . $img($p0, $W, $H) . '</div><div class="scrim"></div>'
              . ($kind === 'agent' ? $agHtml() : '<div class="w">' . $words($x['rows'], true) . '</div>')
              . '<img class="logo" src="' . $e($logoW) . '" alt="">';
    } elseif ($style === 'band') {
        // The photo is the whole ad; one slim translucent band along the bottom carries the words and the logo.
        if ($big) {
            $subW = 0; foreach ($x['rows'] as [$cls, $text]) $subW = max($subW, mb_strlen($text) * 11 * .7);
            $tw = $W - 56 - 75 - 22 * 3 - $subW; $hs_ = $fitH(26, $tw, .44, true);
            $css .= ".band{position:absolute;left:0;right:0;bottom:0;height:82px;box-sizing:border-box;background:rgba(31,36,44,.88);border-top:1px solid rgba(174,187,204,.55);display:flex;align-items:center;gap:22px;padding:0 26px 0 30px}"
                  . ".w{display:flex;align-items:center;gap:22px;min-width:0}.h{{$sans3};font-size:{$hs_}px;line-height:1.1;white-space:nowrap}"
                  . ".sub{border-left:1px solid rgba(174,187,204,.5);padding-left:22px}.c{font-size:11px;color:{$pal['accent']}}.p{font-size:12.5px}"
                  . ".logo{margin-left:auto;flex:none;height:56px}"
                  . $agCss([58, $fitN(25, 330, .44), 13, 9.5, 10], [54, $fitN(20, 210, .44), 11], $sans3, '#fff', '#fff', $pal['accent'], 16)
                  . ".ag.two{display:flex;gap:30px}.ag.two .lbl,.ag .st{display:none}.ag.two .row{margin-top:0}";
        } else {
            $tw = 300 - 14 - 12 - 61 - 10; $hs_ = $fitH(16, $tw, .46); $cs = $fitC(8.5, $x['rows0'] ?: $x['rows'], $tw, .12);
            $css .= ".band{position:absolute;left:0;right:0;bottom:0;height:88px;box-sizing:border-box;background:rgba(31,36,44,.88);border-top:1px solid rgba(174,187,204,.55);display:flex;align-items:center;gap:10px;padding:0 12px 0 14px}"
                  . ".w{flex:1;min-width:0}.h{{$sans4};font-size:{$hs_}px;line-height:1.15}.sub{margin-top:5px}.c{font-size:{$cs}px;letter-spacing:.12em;color:{$pal['accent']}}.p{font-size:" . ($cs + 1) . "px;letter-spacing:.12em}"
                  . ".logo{margin-left:auto;flex:none;height:46px}"
                  . $agCss([50, $fitN(15, 140, .46), 9.5, 7.5, 7.5], [32, $fitN(12.5, 150, .46), 8.5], $sans4, '#fff', '#fff', $pal['accent'], 10)
                  . ".ag.two .lbl{display:none}";
        }
        $rows = $big ? $x['rows'] : ($x['rows0'] ?: $x['rows']);
        $body = '<div class="ph full">' . $img($p0, $W, $H) . '</div><div class="band">'
              . ($kind === 'agent' ? $agHtml() : '<div class="w">' . $hHtml($big) . '<div class="sub">' . $rowsHtml($rows) . '</div></div>')
              . '<img class="logo" src="' . $e($logoW) . '" alt=""></div>';
    } elseif ($style === 'paper') {
        // The photo is the whole ad; a card in the website's light scheme floats on it: off-white, slate type, the black logo.
        $slate = '#55637a'; $dark = '#1f242c';
        if ($big) {
            $tw = 352 - 48; $hs_ = $fitH(32, $tw, .45); $cs = $fitC(10.5, $x['rows0'], $tw - 64, .16);
            $css .= ".card{position:absolute;right:16px;top:16px;bottom:16px;width:352px;box-sizing:border-box;padding:22px 24px;background:#f6f7f9;color:{$dark};box-shadow:0 8px 28px rgba(0,0,0,.3)}"
                  . ".eb{font-size:10px;font-weight:500;letter-spacing:.3em;text-transform:uppercase;color:{$slate};line-height:1.4;margin-bottom:8px}"
                  . ".h{{$serif};font-size:{$hs_}px;line-height:1.06;color:{$dark}}.rule{width:34px;height:1px;background:{$slate};margin:12px 0 10px}"
                  . ".c{font-size:{$cs}px;color:{$slate}}.c+.c,.c+.p{margin-top:2px}.p{font-size:" . ($cs + 2) . "px;color:{$dark}}"
                  . ".w{width:{$tw}px}.logo{position:absolute;right:20px;bottom:18px;height:46px}.ag{margin-top:4px}"
                  . $agCss([84, $fitN(28, 190, .45), 12.5, 10, 10], [58, $fitN(23, 160, .45), 11.5], $serif, $dark, $dark, $slate, 16);
        } else {
            $tw = 280 - 28 - 58; $hs_ = $fitH(21, $tw, .45); $cs = $fitC(8.5, $x['rows0'], $tw, .14);
            $css .= ".card{position:absolute;left:10px;right:10px;bottom:10px;height:104px;box-sizing:border-box;padding:0 14px;background:#f6f7f9;color:{$dark};box-shadow:0 6px 20px rgba(0,0,0,.3);display:flex;flex-direction:column;justify-content:center}"
                  . ".eb,.rule{display:none}.h{{$serif};font-size:{$hs_}px;line-height:1.06;color:{$dark};margin-bottom:5px}"
                  . ".c{font-size:{$cs}px;letter-spacing:.14em;color:{$slate}}.p{font-size:" . ($cs + 1.5) . "px;letter-spacing:.14em;color:{$dark}}"
                  . ".w{width:{$tw}px}.logo{position:absolute;right:13px;top:50%;transform:translateY(-50%);height:42px}"
                  . $agCss([54, $fitN(19, 130, .45), 9.5, 7.5, 7.5], [34, $fitN(15, 150, .45), 8.5], $serif, $dark, $dark, $slate, 10);
        }
        $body = '<div class="ph full">' . $img($p0, $W, $H) . '</div><div class="card">'
              . ($kind === 'agent' ? $agHtml()
                                   : '<div class="w">' . ($x['city'] !== '' ? '<p class="eb">' . $e($x['city']) . '</p>' : '') . $words($x['rows0'], true) . '</div>')
              . '<img class="logo" src="' . $e($logoB) . '" alt=""></div>';
    } elseif ($style === 'diag') {
        // Photo and panel meet on the angle of the M (30 degrees off vertical on the marquee), a light slate stripe along the cut.
        if ($big) {
            $tw = 262; $hs_ = $fitH(28, $tw, .44); $cs = $fitC(11, $x['rows'], $tw, .16);
            $css .= ".p1{left:0;top:0;width:652px;height:250px}"
                  . ".stripe,.panel{position:absolute;top:0;width:466px;height:250px;clip-path:polygon(146px 0,100% 0,100% 100%,0 100%)}.stripe{left:497px;background:{$pal['accent']}}.panel{left:504px;background:{$pal['panel']};overflow:hidden}"
                  . ".w{position:absolute;left:180px;top:34px;width:{$tw}px}.h{{$sans3};font-size:{$hs_}px;line-height:1.18}"
                  . ".c{font-size:{$cs}px;color:{$pal['accent']};margin-top:14px}.c+.c,.c+.p{margin-top:2px}.p{font-size:" . ($cs + 2) . "px;margin-top:2px}"
                  . ".logo{position:absolute;right:24px;bottom:20px;height:68px}"
                  . ".ag{position:absolute;left:126px;top:" . ($two ? 44 : 46) . "%;transform:translateY(-50%)}"
                  . $agCss([86, $fitN(25, 200, .44), 13, 10.5, 10.5], [60, $fitN(21, 210, .44), 11.5], $sans3, '#fff', '#fff', $pal['accent'], 18);
            $pw = 652; $ph_ = 250;
        } else {
            $tw = 300 - 14 - 12 - 58 - 8; $hs_ = $fitH(16.5, $tw, .46); $cs = $fitC(8.5, $x['rows0'] ?: $x['rows'], $tw, .12);
            $css .= ".p1{left:0;top:0;width:300px;height:162px}"
                  . ".stripe,.panel{position:absolute;left:0;width:300px;height:126px;clip-path:polygon(0 34px,100% 0,100% 100%,0 100%)}.stripe{top:120px;background:{$pal['accent']}}.panel{top:124px;background:{$pal['panel']};overflow:hidden}"
                  . ".w{position:absolute;left:14px;top:42px;width:{$tw}px}.h{{$sans4};font-size:{$hs_}px;line-height:1.15}"
                  . ".c{font-size:{$cs}px;letter-spacing:.12em;color:{$pal['accent']};margin-top:6px}.c+.c,.c+.p{margin-top:0}.p{font-size:" . ($cs + 1) . "px;letter-spacing:.12em;margin-top:0}"
                  . ".logo{position:absolute;right:12px;bottom:12px;height:44px}"
                  . ".ag{position:absolute;left:14px;top:" . ($two ? 40 : 46) . "px}"
                  . $agCss([52, $fitN(15.5, 140, .46), 9.5, 7.5, 7.5], [32, $fitN(12.5, 150, .46), 8.5], $sans4, '#fff', '#fff', $pal['accent'], 10);
            $pw = 300; $ph_ = 162;
        }
        $rows = $big ? $x['rows'] : ($x['rows0'] ?: $x['rows']);
        $body = '<div class="ph p1">' . $img($p0, $pw, $ph_) . '</div><div class="stripe"></div><div class="panel">' . $texHtml
              . ($kind === 'agent' ? $agHtml() : '<div class="w">' . $words($rows) . '</div>')
              . '<img class="logo" src="' . $e($logoW) . '" alt=""></div>';
    } elseif ($style === 'mosaic') {
        // Three photos a slide: one large, two small, hairline gaps in the panel's own tone.
        $pA = $pics[0] ?? null; $pB = $pics[1] ?? $pA; $pC = $pics[2] ?? $pB;
        if ($big) {
            $tw = 376 - 28 - 24; $hs_ = $fitH(29, $tw, .44); $cs = $fitC(12, $x['rows'], $tw, .16);
            $css .= ".pa{left:0;top:0;width:396px;height:250px}.pb{left:400px;top:0;width:190px;height:123px}.pc{left:400px;top:127px;width:190px;height:123px}"
                  . ".panel{position:absolute;left:594px;top:0;width:376px;height:250px;background:{$pal['panel']};overflow:hidden}"
                  . ".w{position:absolute;left:28px;top:32px;width:{$tw}px}.h{{$sans3};font-size:{$hs_}px;line-height:1.18}"
                  . ".c{font-size:{$cs}px;color:{$pal['accent']};margin-top:14px}.c+.c,.c+.p{margin-top:2px}.p{font-size:" . ($cs + 2) . "px;margin-top:2px}"
                  . ".logo{position:absolute;right:22px;bottom:18px;height:70px}"
                  . ".ag{position:absolute;left:28px;top:" . ($two ? 44 : 46) . "%;transform:translateY(-50%)}"
                  . $agCss([90, $fitN(25, 210, .44), 13, 10.5, 10.5], [62, $fitN(21, 150, .44), 11.5], $sans3, '#fff', '#fff', $pal['accent'], 18);
            $boxes = [['pa', $pA, 396, 250], ['pb', $pB, 190, 123], ['pc', $pC, 190, 123]];
        } else {
            $tw = 300 - 12 - 10 - 61 - 8; $hs_ = $fitH(15.5, $tw, .46); $cs = $fitC(8, $x['rows0'] ?: $x['rows'], $tw, .1);
            $css .= ".pa{left:0;top:78px;width:300px;height:104px}.pb{left:0;top:186px;width:148px;height:64px}.pc{left:152px;top:186px;width:148px;height:64px}"
                  . ".panel{position:absolute;left:0;top:0;width:300px;height:74px;background:{$pal['panel']};overflow:hidden}"
                  . ".w{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:{$tw}px}.h{{$sans4};font-size:{$hs_}px;line-height:1.14}"
                  . ".c{font-size:{$cs}px;letter-spacing:.1em;color:{$pal['accent']};margin-top:4px;line-height:1.4}.c+.c,.c+.p{margin-top:0}.p{font-size:" . ($cs + 1) . "px;letter-spacing:.1em;margin-top:0;line-height:1.4}"
                  . ".logo{position:absolute;right:10px;top:50%;transform:translateY(-50%);height:46px}"
                  . ".ag{position:absolute;left:12px;top:50%;transform:translateY(-50%)}"
                  . $agCss([50, $fitN(15, 140, .46), 9.5, 7, 7], [28, $fitN(12, 160, .46), 8.5], $sans4, '#fff', '#fff', $pal['accent'], 10)
                  . ".ag .lbl,.ag .st{display:none}";
            $boxes = [['pa', $pA, 300, 104], ['pb', $pB, 148, 64], ['pc', $pC, 148, 64]];
        }
        $rows = $big ? $x['rows'] : ($x['rows0'] ?: $x['rows']);
        foreach ($boxes as [$cls, $p, $bw, $bh]) $body .= '<div class="ph ' . $cls . '">' . $img($p, $bw, $bh) . '</div>';
        $body .= '<div class="panel">' . $texHtml . ($kind === 'agent' ? $agHtml() : '<div class="w">' . $words($rows) . '</div>')
               . '<img class="logo" src="' . $e($logoW) . '" alt=""></div>';
    } else {   // mono
        // The photo in full color through a giant M mark; everywhere else it is gray and deep under a slate veil. Both
        // layers are the same picture framed the same way, so the M reads as a window, not a second photo.
        if ($big) {
            $mx = 34; $my = 12; $mw = 240; $mh = 226; $tw = 430; $hs_ = $fitH(38, $tw, .45); $cs = $fitC(11.5, $x['rows'], $tw, .16);
            $css .= ".w,.ag{position:absolute;left:318px;top:50%;transform:translateY(-50%)}.w{width:{$tw}px}"
                  . ".h{{$serif};font-size:{$hs_}px;line-height:1.04}.rule{width:40px;height:1px;background:{$pal['accent']};margin:14px 0 12px}"
                  . ".c{font-size:{$cs}px;color:#d6dee8}.c+.c,.c+.p{margin-top:2px}.p{font-size:" . ($cs + 2) . "px}"
                  . ".logo{position:absolute;right:24px;bottom:18px;height:60px}"
                  . $agCss([92, $fitN(33, 290, .45), 13.5, 10.5, 10.5], [64, $fitN(26, 320, .45), 12], $serif, '#fff', '#fff', '#d6dee8', 20);
        } else {
            $mx = 16; $my = 12; $mw = 134; $mh = 126; $tw = 268; $hs_ = $fitH(22, $tw, .45); $cs = $fitC(8.5, $x['rows'], $tw, .14);
            $css .= ".w,.ag{position:absolute;left:16px;bottom:13px}.w{right:16px}"
                  . ".h{{$serif};font-size:{$hs_}px;line-height:1.05;margin-bottom:6px}.rule{display:none}"
                  . ".c{font-size:{$cs}px;letter-spacing:.14em;color:#d6dee8}.c+.c,.c+.p{margin-top:0}.p{font-size:" . ($cs + 1.5) . "px;letter-spacing:.14em}"
                  . ".logo{position:absolute;right:14px;top:14px;height:40px}"
                  . $agCss([54, $fitN(20, 190, .45), 9.5, 8, 8], [36, $fitN(16, 210, .45), 8.5], $serif, '#fff', '#fff', '#d6dee8', 11);
        }
        $css .= ".dim img{filter:grayscale(1) brightness(.55) contrast(1.08)}.tint{position:absolute;left:0;top:0;right:0;bottom:0;background:rgba({$pal['panel_rgb']},.52)}"
              . ".m{position:absolute;left:{$mx}px;top:{$my}px;width:{$mw}px;height:{$mh}px;clip-path:" . ADS_M_CLIP . "}.mi{position:absolute;left:" . -$mx . "px;top:" . -$my . "px;width:{$W}px;height:{$H}px;overflow:hidden}";
        $body = '<div class="ph full dim">' . $img($p0, $W, $H) . '</div><div class="tint"></div>'
              . '<div class="m"><div class="mi">' . $img($p0, $W, $H) . '</div></div>'
              . ($kind === 'agent' ? $agHtml() : '<div class="w">' . $words($x['rows'], true) . '</div>')
              . '<img class="logo" src="' . $e($logoW) . '" alt="">';
    }

    return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Ad</title><style>{$css}</style></head><body><div class=\"ad\">{$body}</div></body></html>";
}
