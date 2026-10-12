<?php
/**
 * inc/social_render.php — the social post templates (Nikki, 2026-10-11;
 * reworked 2026-10-12 to her notes).
 *
 * One function turns a normalised post (see social_post_from_request() in
 * inc/social.php) into a complete HTML document sized exactly
 * SOCIAL_W x SOCIAL_H. The SAME document is the live preview on the portal
 * (an iframe's srcdoc) and the page headless Chromium screenshots for the
 * PNG, so what the agent sees is what they download. There is no second
 * render path: never draw the post in JavaScript.
 *
 * This file is deliberately standalone (no portal.php, no database) so a
 * harness can render sample data locally: tests/render_social_sample.php.
 *
 * The post (2026-10-12): a photo on top with an optional label pill; below
 * it a short rule, a HEADLINE, then up to four SUB LINES each in a style the
 * agent picks (town, price, details, note, plain), blank lines simply
 * absent so nothing leaves a gap; the Mont Haus logo bottom-right. No agent
 * row (Nikki, 2026-10-12: no headshot or contact details). Open House adds a
 * date and a time line above the headline. Two looks: dark (ink) and light
 * (the brand's cream). The photo is framed by zoom + focus (drag on the
 * portal): the image box is zoom x the frame, object-fit cover inside it,
 * object-position = focus, and the box is pulled by (zoom - 1) x focus so
 * focus 50/50 stays centred at any zoom. The framing widget on the portal
 * mirrors exactly this CSS, which is the only place the post is "drawn"
 * twice, and only as a control.
 *
 * Format: Instagram portrait, 1080 x 1440. Assets by absolute URL under
 * $base: self-hosted fonts (assets/fonts/mh), the vertical-stack logo in
 * white or black, the photo through the public site's img.php or (an
 * upload) the portal's own asset endpoint; for the export the upload is
 * read straight from disk (file://).
 */

const SOCIAL_W = 1080;
const SOCIAL_H = 1440;

/** The templates an agent can pick, in menu order. */
function social_templates(): array {
    return [
        'featured'  => ['name' => 'Featured Listing', 'blurb' => 'One photo, a label, the address and the lines you choose.'],
        'openhouse' => ['name' => 'Open House',       'blurb' => 'The date and time front and centre, then the listing.'],
    ];
}

/** The two looks. */
function social_themes(): array {
    return ['dark' => 'Dark', 'light' => 'Light'];
}

/** The ready-made labels for the pill on the photo (the form adds "No label" and "Custom"). */
function social_labels(): array {
    return ['Featured Listing', 'Just Listed', 'New Price', 'Under Contract', 'Just Sold', 'Featured Rental', 'Coming Soon', 'Open House'];
}

/** The styles a sub line can take: key => what the dropdown says. */
function social_line_styles(): array {
    return [
        'town'    => 'Town line (small capitals, gold)',
        'price'   => 'Price (large serif)',
        'details' => 'Details (light)',
        'note'    => 'Note (italic serif)',
        'plain'   => 'Plain',
    ];
}

/** The default style of each of the four sub lines. */
function social_line_defaults(): array {
    return ['town', 'price', 'details', 'note'];
}

/** The photo's height for a template; the text block has the rest. */
function social_photo_height(string $type): int {
    return $type === 'openhouse' ? 900 : 960;
}

/** "$9,500,000" from a decimal, '' for none. */
function social_price_text($v): string {
    if ($v === null || $v === '' || (float)$v <= 0) return '';
    return '$' . number_format((float)$v);
}

/** "3 Bed · 3.5 Bath · 2,969 Sq Ft", skipping what the board left blank. */
function social_specs_text($beds, $baths, $sqft): string {
    $parts = [];
    if ($beds !== null && (float)$beds > 0)   $parts[] = social_num($beds) . ' Bed';
    if ($baths !== null && (float)$baths > 0) $parts[] = social_num($baths) . ' Bath';
    if ($sqft !== null && (int)$sqft > 0)     $parts[] = number_format((int)$sqft) . ' Sq Ft';
    return implode(' · ', $parts);
}

/** 3.0 → "3", 3.5 → "3.5". */
function social_num($v): string {
    $f = (float)$v;
    return $f == floor($f) ? (string)(int)$f : rtrim(rtrim(number_format($f, 1), '0'), '.');
}

/** "Saturday, October 18" from Y-m-d, '' when it does not parse. */
function social_date_text(string $ymd): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($ymd));
    return $d ? $d->format('l, F j') : '';
}

/** "11 AM", "2:30 PM" from H:i, '' when it does not parse. */
function social_time_text(string $hm): string {
    $t = DateTimeImmutable::createFromFormat('!H:i', trim($hm));
    if (!$t) return '';
    return $t->format('i') === '00' ? $t->format('g A') : $t->format('g:i A');
}

/** "11 AM to 2 PM", "From 11 AM", or ''. */
function social_time_range_text(string $start, string $end): string {
    $s = social_time_text($start); $e = social_time_text($end);
    if ($s !== '' && $e !== '') return "$s to $e";
    if ($s !== '') return "From $s";
    return '';
}

/** A font size that keeps a line on one line: the first threshold the text exceeds wins. */
function social_fit_size(string $text, int $size, array $steps): int {
    $n = mb_strlen($text);
    foreach ($steps as $chars => $smaller) if ($n > $chars) $size = $smaller;
    return $size;
}

/**
 * The complete HTML document for one post.
 * $export = true swaps an uploaded photo's URL for its file on disk, which
 * is how Chromium reads it (it has no portal session).
 */
function social_render(array $p, ?string $base = null, bool $export = false): string {
    $base  = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $fonts = $base . '/assets/fonts/mh';
    $W = SOCIAL_W; $H = SOCIAL_H;

    $type   = ($p['type'] ?? 'featured') === 'openhouse' ? 'openhouse' : 'featured';
    $oh     = $type === 'openhouse';
    $theme  = ($p['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $photoH = social_photo_height($type);
    $bodyH  = $H - $photoH;

    // The photo frame: zoom 1 to 3, focus 0 to 100 on each axis (see the header).
    $zoom = max(1.0, min(3.0, (float)($p['zoom'] ?? 1)));
    $fx   = max(0, min(100, (int)($p['focus_x'] ?? 50)));
    $fy   = max(0, min(100, (int)($p['focus_y'] ?? 50)));
    $tx   = -round(($zoom - 1) * $fx / 100 * $W, 1);
    $ty   = -round(($zoom - 1) * $fy / 100 * $photoH, 1);
    $zpct = round($zoom * 100, 2);
    $photo = ($export && !empty($p['photo_file'])) ? 'file://' . $p['photo_file'] : (string)($p['photo_url'] ?? '');

    // The two looks.
    $c = $theme === 'light'
        ? ['bg' => '#F1ECE2', 'text' => '#231F20', 'accent' => '#8A7350', 'muted' => '#4a443a', 'soft' => '#3b362e',
           'pillbg' => 'rgba(241,236,226,.88)', 'pilltext' => '#231F20', 'fade' => 'rgba(241,236,226,.42)',
           'logo' => $base . '/assets/images/MH_vert-stack_black.svg', 'mark' => $base . '/assets/images/M-mark_black.svg']
        : ['bg' => '#141210', 'text' => '#ffffff', 'accent' => '#C3A561', 'muted' => '#d9d2c3', 'soft' => '#e8e2d6',
           'pillbg' => 'rgba(20,18,16,.74)', 'pilltext' => '#ffffff', 'fade' => 'rgba(20,18,16,.62)',
           'logo' => $base . '/assets/images/MH_vert-stack_white.svg', 'mark' => $base . '/assets/images/M-mark_white.svg'];

    // Open House text sits under a date block, so its lines run a size smaller.
    $k = $oh ? 0.86 : 1.0;
    $sz = fn(int $px) => round($px * $k);

    $css = <<<CSS
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url({$fonts}/CormorantGaramond-normal.woff2) format('woff2')}
@font-face{font-family:'Cormorant Garamond';font-style:italic;font-weight:300 700;src:url({$fonts}/CormorantGaramond-italic.woff2) format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:100 900;src:url({$fonts}/Jost-normal.woff2) format('woff2')}
html,body{margin:0;padding:0}
body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['bg']};color:{$c['text']};
     font-family:'Jost','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased}
.photo{position:absolute;left:0;top:0;width:{$W}px;height:{$photoH}px;overflow:hidden;background:#26221d}
.photo img.pic{display:block;width:{$zpct}%;height:{$zpct}%;object-fit:cover;object-position:{$fx}% {$fy}%;transform:translate({$tx}px,{$ty}px)}
.photo .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:{$c['bg']}}
.photo .none img{width:260px;opacity:.16}
.photo::after{content:'';position:absolute;left:0;right:0;bottom:0;height:220px;
     background:linear-gradient(to bottom,rgba(0,0,0,0) 0%,{$c['fade']} 100%)}
.pill{position:absolute;left:64px;top:64px;z-index:2;padding:17px 28px 15px;background:{$c['pillbg']};color:{$c['pilltext']};
     font-size:22px;font-weight:500;letter-spacing:.32em;text-transform:uppercase;line-height:1;white-space:nowrap}
.body{position:absolute;left:0;top:{$photoH}px;width:{$W}px;height:{$bodyH}px;padding:50px 64px 0;box-sizing:border-box}
.rule{width:72px;height:3px;background:{$c['accent']};margin:0 0 26px}
.when{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;line-height:1.02;margin:0 0 12px;white-space:nowrap;overflow:hidden}
.time{font-size:30px;font-weight:400;letter-spacing:.14em;text-transform:uppercase;color:{$c['accent']};margin:0 0 28px;white-space:nowrap;overflow:hidden}
.addr{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;line-height:1.02;margin:0 0 14px;white-space:nowrap;overflow:hidden}
.lines{padding-right:220px}
.ln{margin:0 0 10px;white-space:nowrap;overflow:hidden;line-height:1.15}
.ln-town{font-size:{$sz(27)}px;font-weight:400;letter-spacing:.08em;text-transform:uppercase;color:{$c['accent']};margin-bottom:14px}
.ln-price{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$sz(54)}px;line-height:1.05;margin-bottom:12px}
.ln-details{font-size:{$sz(26)}px;font-weight:300;color:{$c['muted']};letter-spacing:.03em}
.ln-note{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-size:{$sz(31)}px;line-height:1.2;color:{$c['soft']}}
.ln-plain{font-size:{$sz(26)}px;font-weight:400;color:{$c['text']}}
.logo{position:absolute;right:64px;bottom:56px;height:120px}
CSS;

    $photoHtml = $photo !== ''
        ? '<img class="pic" src="' . $e($photo) . '" alt="">'
        : '<div class="none"><img src="' . $e($c['mark']) . '" alt=""></div>';

    $label = trim((string)($p['label'] ?? ''));
    $pill  = $label !== '' ? '<div class="pill">' . $e($label) . '</div>' : '';

    $body = '<div class="rule"></div>';
    if ($oh) {
        $date = (string)($p['date_text'] ?? ''); $time = (string)($p['time_text'] ?? '');
        if ($date !== '') $body .= '<h1 class="when" style="font-size:' . social_fit_size($date, 66, [24 => 56, 30 => 48]) . 'px">' . $e($date) . '</h1>';
        if ($time !== '') $body .= '<p class="time">' . $e($time) . '</p>';
    }
    $headline = (string)($p['headline'] ?? '');
    if ($headline !== '') {
        $size = $oh ? social_fit_size($headline, 46, [34 => 40, 42 => 34, 52 => 28])
                    : social_fit_size($headline, 74, [27 => 62, 33 => 52, 40 => 44, 50 => 36]);
        $body .= '<h' . ($oh ? '2' : '1') . ' class="addr" style="font-size:' . $size . 'px">' . $e($headline) . '</h' . ($oh ? '2' : '1') . '>';
    }
    $styles = social_line_styles();
    $lines  = '';
    foreach ((array)($p['lines'] ?? []) as $ln) {
        $t = trim((string)($ln['text'] ?? ''));
        if ($t === '') continue;                                   // a blank line takes no room
        $s = isset($styles[$ln['style'] ?? '']) ? $ln['style'] : 'plain';
        $lines .= '<p class="ln ln-' . $s . '">' . $e($t) . '</p>';
    }
    if ($lines !== '') $body .= '<div class="lines">' . $lines . '</div>';
    $body .= '<img class="logo" src="' . $e($c['logo']) . '" alt="Mont Haus International Realty">';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($headline !== '' ? $headline : 'Post') . '</title><style>' . $css . '</style></head><body>'
         . '<div class="photo">' . $photoHtml . '</div>' . $pill
         . '<div class="body">' . $body . '</div>'
         . '</body></html>';
}

/** A small page for the preview frame when there is nothing to render yet. */
function social_placeholder_html(string $msg): string {
    $W = SOCIAL_W; $H = SOCIAL_H;
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{width:' . $W . 'px;height:' . $H . 'px;'
         . 'display:flex;align-items:center;justify-content:center;background:#141210;color:#cfc6b3;font:300 40px/1.4 Jost,Arial,sans-serif;text-align:center;padding:0 120px;box-sizing:border-box}'
         . '</style></head><body>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</body></html>';
}
