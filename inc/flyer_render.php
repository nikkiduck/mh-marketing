<?php
/**
 * inc/flyer_render.php — the printed listing flyer (Nikki, 2026-10-13), after
 * the InDesign display_template.pdf in "Listings Display Flyer": US Letter,
 * a headline, the address, the price and a beds / baths / sq ft box at the
 * top; optional notes; up to six photos (one hero, then rows of two and
 * three); a footer with the logo, the agent's name and contact lines, and a
 * QR code.
 *
 * One function turns a normalised flyer (see flyer_post_from_request() in
 * inc/flyer.php) into a complete HTML document of exactly 612 x 792 pt. The
 * SAME document is the live preview on the portal (an iframe's srcdoc) and
 * the page headless Chromium prints to PDF, so what the agent sees is what
 * they download. Standalone (no portal.php, no database), like
 * inc/social_render.php.
 *
 * Photos: the hero at the site's 2048 rendition, the rest at 1600 (img.php),
 * an upload read from disk for the export (file://). Fonts: the Instagram
 * template's pair, Jost and Cormorant Garamond, self-hosted, by URL for the
 * preview and embedded for the export. The QR code is an inline
 * SVG made by qrencode (inc/flyer.php) with the Mont Haus triangle mark in
 * the centre, like the dynamic QR codes (mh-qr.js) and the template.
 */

const FLYER_W = 612;   // pt
const FLYER_H = 792;

/** The photo grid for N photos: the hero first, then rows of two and three (the template's six). */
function flyer_photo_layout(int $n): array {
    // Each entry: [grid-column, grid-row]; rows are 1-based in order of appearance.
    $rowsByRest = [
        0 => [],
        1 => [[1, 7]],
        2 => [[1, 4], [4, 7]],
        3 => [[1, 3], [3, 5], [5, 7]],
        4 => [[1, 4], [4, 7], [1, 4], [4, 7]],
        5 => [[1, 4], [4, 7], [1, 3], [3, 5], [5, 7]],
    ];
    $n = max(0, min(6, $n));
    if ($n === 0) return ['rows' => '', 'cells' => []];
    $rest = $rowsByRest[$n - 1];
    $cells = [['1 / 7', 1]];
    $rowHeights = [255];
    $row = 1; $inRow = 0; $perRow = null;
    foreach ($rest as $i => [$c0, $c1]) {
        $width = $c1 - $c0;                       // 6 = full, 3 = half, 2 = third
        if ($perRow === null || $inRow >= $perRow) {
            $row++; $inRow = 0; $perRow = $width === 6 ? 1 : ($width === 3 ? 2 : 3);
            $rowHeights[] = $width === 6 ? 200 : ($width === 3 ? 143 : 107);
        }
        $cells[] = ["{$c0} / {$c1}", $row];
        $inRow++;
    }
    return ['rows' => implode(' ', array_map(fn($h) => "{$h}fr", $rowHeights)), 'cells' => $cells];
}

/** The complete HTML document for one flyer. $export swaps uploads for their files and embeds the fonts. */
function flyer_render(array $p, ?string $base = null, bool $export = false): string {
    $base = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W = FLYER_W; $H = FLYER_H;

    $fontUrl = function (string $file) use ($base, $export): string {
        $disk = __DIR__ . '/../assets/fonts/mh/' . $file;
        if ($export && is_file($disk)) return 'data:font/woff2;base64,' . base64_encode((string)file_get_contents($disk));
        return $base . '/assets/fonts/mh/' . $file;
    };
    $fJost = $fontUrl('Jost-normal.woff2');
    $fCorm = $fontUrl('CormorantGaramond-normal.woff2');   // the Instagram template's serif, for the headline, price, figures and name (Nikki, 2026-10-13)
    $logo  = $base . '/assets/images/MH_vert-stack_black.svg';
    $mark  = $base . '/assets/images/triangle_mark-black.svg';

    $photos = array_values(array_filter((array)($p['photos'] ?? []), fn($ph) => (string)($ph['url'] ?? '') !== ''));
    $layout = flyer_photo_layout(count($photos));

    $css = <<<CSS
@font-face{font-family:'Jost';font-style:normal;font-weight:100 900;src:url({$fJost}) format('woff2')}
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url({$fCorm}) format('woff2')}
@page{size:{$W}pt {$H}pt;margin:0}
html,body{margin:0;padding:0}
body{width:{$W}pt;height:{$H}pt;overflow:hidden;background:#fff;color:#231F20;font-family:'Jost','Helvetica Neue',Arial,sans-serif;
     -webkit-font-smoothing:antialiased;box-sizing:border-box;padding:36pt 36pt 36pt;display:flex;flex-direction:column;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:24pt;flex:none}
.head .l{min-width:0;flex:1}
.title{font-family:'Cormorant Garamond',Georgia,serif;font-size:28pt;font-weight:500;line-height:1.1;margin:0}
.addr{font-size:10pt;font-weight:400;letter-spacing:.2em;text-transform:uppercase;margin:7pt 0 0;line-height:1.3}
.price{font-family:'Cormorant Garamond',Georgia,serif;font-size:27pt;font-weight:500;line-height:1.1;margin:10pt 0 0}
.stats{flex:none;border:.75pt solid #231F20;display:flex;height:57pt;min-width:204pt}
.stat{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:0 10pt;border-left:.75pt solid #231F20;white-space:nowrap}
.stat:first-child{border-left:0}
.stat b{font-family:'Cormorant Garamond',Georgia,serif;font-size:27pt;font-weight:500;line-height:1}
.stat small{font-size:9.5pt;letter-spacing:.2em;text-transform:uppercase;margin-top:3pt;color:#231F20}
.notes{flex:none;font-size:10.5pt;font-weight:300;line-height:1.5;margin:14pt 0 0;max-height:120pt;overflow:hidden;white-space:pre-line}
.photos{flex:1;min-height:0;display:grid;grid-template-columns:repeat(6,1fr);grid-template-rows:{$layout['rows']};gap:10pt;margin-top:18pt}
.photos .ph{min-height:0;min-width:0;overflow:hidden;background:#e8e2d6}
.photos img{display:block;width:100%;height:100%;object-fit:cover}
.foot{flex:none;border-top:.75pt solid #231F20;margin-top:18pt;padding-top:16pt;display:flex;align-items:center;gap:14pt;height:82pt;box-sizing:border-box}
.foot .logo{height:64pt;width:auto;flex:none}
.foot .who{flex:1;min-width:0;padding-left:12pt}
.name{font-family:'Cormorant Garamond',Georgia,serif;font-size:27pt;font-weight:500;line-height:1;white-space:nowrap;overflow:hidden}
.contact{font-size:10pt;letter-spacing:.2em;text-transform:uppercase;line-height:1.55;margin-top:5pt}
.qr{position:relative;width:67pt;height:67pt;flex:none}
.qr svg{display:block;width:100%;height:100%}
.qr .mark{position:absolute;left:50%;top:50%;width:19pt;height:19pt;transform:translate(-50%,-50%);background:#fff;padding:2.5pt;box-sizing:border-box}
.qr .mark img{display:block;width:100%;height:100%}
CSS;

    $title = trim((string)($p['title'] ?? ''));
    $addr  = trim((string)($p['address'] ?? ''));
    $head  = '<div class="head"><div class="l">';
    if ($title !== '') {
        $head .= '<h1 class="title">' . $e($title) . '</h1>';
        if ($addr !== '') $head .= '<p class="addr">' . $e($addr) . '</p>';
    } elseif ($addr !== '') {
        $head .= '<h1 class="title">' . $e($addr) . '</h1>';
    }
    if (trim((string)($p['price'] ?? '')) !== '') $head .= '<p class="price">' . $e($p['price']) . '</p>';
    $head .= '</div>';
    $stats = '';
    foreach ([['beds', 'Beds'], ['baths', 'Baths'], ['sqft', 'Sq Ft']] as [$k, $label]) {
        $v = trim((string)($p[$k] ?? ''));
        if ($v !== '') $stats .= '<div class="stat"><b>' . $e($v) . '</b><small>' . $label . '</small></div>';
    }
    if ($stats !== '') $head .= '<div class="stats">' . $stats . '</div>';
    $head .= '</div>';

    $notes = trim((string)($p['notes'] ?? ''));
    $notesHtml = $notes !== '' ? '<p class="notes">' . $e($notes) . '</p>' : '';

    $grid = '';
    foreach ($photos as $i => $ph) {
        [$col, $row] = $layout['cells'][$i];
        $src = ($export && !empty($ph['file'])) ? 'file://' . $ph['file'] : (string)$ph['url'];
        $grid .= '<div class="ph" style="grid-column:' . $col . ';grid-row:' . $row . '"><img src="' . $e($src) . '" alt=""></div>';
    }
    $gridHtml = $grid !== '' ? '<div class="photos">' . $grid . '</div>' : '<div class="photos"></div>';

    $a = (array)($p['agent'] ?? []);
    $lines = array_filter([(string)($a['phone'] ?? ''), 'monthaus.com', (string)($a['email'] ?? '')], fn($s) => trim($s) !== '');
    $foot  = '<div class="foot"><img class="logo" src="' . $e($logo) . '" alt="Mont Haus International Realty">'
           . '<div class="who"><div class="name">' . $e($a['name'] ?? '') . '</div>'
           . '<div class="contact">' . implode('<br>', array_map($e, $lines)) . '</div></div>';
    if ((string)($p['qr_svg'] ?? '') !== '') {
        $foot .= '<div class="qr">' . $p['qr_svg'] . '<span class="mark"><img src="' . $e($mark) . '" alt=""></span></div>';
    }
    $foot .= '</div>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($title !== '' ? $title : ($addr !== '' ? $addr : 'Flyer')) . '</title><style>' . $css . '</style></head><body>'
         . $head . $notesHtml . $gridHtml . $foot
         . '</body></html>';
}

/** A small page for the preview frame when there is nothing to render yet. */
function flyer_placeholder_html(string $msg): string {
    $W = FLYER_W; $H = FLYER_H;
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{width:' . $W . 'pt;height:' . $H . 'pt;'
         . 'display:flex;align-items:center;justify-content:center;background:#fff;color:#6f6857;font:300 18pt/1.4 Jost,Arial,sans-serif;text-align:center;padding:0 60pt;box-sizing:border-box}'
         . '</style></head><body>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</body></html>';
}
