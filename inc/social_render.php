<?php
/**
 * inc/social_render.php — the social post templates (Nikki, 2026-10-11;
 * reworked 2026-10-12 to her notes, full-photo family added the same day).
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
 * Two families, each with a plain and an Open House member:
 *   featured / openhouse        photo on top, a text block below it
 *   photo / photo_openhouse     the photo fills the whole post, the words sit
 *                               over a fade at the bottom, fewer lines (two)
 * Every post: an optional LABEL pill on the photo (solid gold, big enough to
 * read in a fast scroll: Nikki, 2026-10-12), a short rule, a HEADLINE, then
 * SUB LINES each in a style the agent picks (small capitals gold, large
 * serif, light, italic serif, plain), blank lines simply absent so nothing
 * leaves a gap; the Mont Haus logo bottom-right. No agent row (Nikki,
 * 2026-10-12: no headshot or contact details). Open House adds a date and a
 * time line above the headline. Two looks: dark (ink) and light (the
 * brand's cream). The photo is framed by zoom + focus (drag on the portal):
 * the image box is zoom x the frame, object-fit cover inside it,
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

/**
 * Every template, in menu order. `offered` = on the picker. The split
 * family (photo on top, text block below) is kept but not offered since
 * 2026-10-12 (Nikki: keep the full-photo versions, hide the others for now).
 */
function social_templates(): array {
    return [
        'photo'           => ['name' => 'Full Photo',            'blurb' => 'The photo fills the post; a headline and up to two lines over it.', 'offered' => true],
        'photo_openhouse' => ['name' => 'Full Photo Open House', 'blurb' => 'The same, with the date and time above the headline.',             'offered' => true],
        'featured'        => ['name' => 'Featured Listing',      'blurb' => 'Photo on top, a text block below: headline and up to four lines.', 'offered' => false],
        'openhouse'       => ['name' => 'Open House',            'blurb' => 'Photo on top; the date and time lead the text block.',            'offered' => false],
    ];
}

/** The templates on the picker. */
function social_templates_offered(): array {
    return array_filter(social_templates(), fn($t) => !empty($t['offered']));
}

/** True for the two Open House templates. */
function social_is_openhouse(string $type): bool {
    return str_contains($type, 'openhouse');
}

/** True for the two full-photo templates. */
function social_is_full(string $type): bool {
    return str_starts_with($type, 'photo');
}

/** How many sub lines a template shows (the form hides the rest). */
function social_line_count(string $type): int {
    return social_is_full($type) ? 2 : 4;
}

/** The two looks. */
function social_themes(): array {
    return ['dark' => 'Dark', 'light' => 'Light'];
}

/** The ready-made labels for the pill on the photo (the form adds "No label" and "Custom"). */
function social_labels(): array {
    return ['Featured Listing', 'Just Listed', 'New Price', 'Under Contract', 'Just Sold', 'Featured Rental', 'Coming Soon', 'Open House'];
}

/** The styles a sub line can take: key => what the dropdown says (short descriptors: Nikki, 2026-10-12). */
function social_line_styles(): array {
    return [
        'town'    => 'Small Caps Gold',
        'price'   => 'Large Serif',
        'details' => 'Light',
        'note'    => 'Italic Serif',
        'plain'   => 'Plain',
    ];
}

/** The default style of each of the four sub lines. */
function social_line_defaults(): array {
    return ['town', 'price', 'details', 'note'];
}

/** The photo's height for a template; the text block has the rest (the full-photo family: all of it). */
function social_photo_height(string $type): int {
    if (social_is_full($type)) return SOCIAL_H;
    return social_is_openhouse($type) ? 900 : 960;
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
    $W = SOCIAL_W; $H = SOCIAL_H;

    // Fonts: by URL for the preview; EMBEDDED for the export. The export page is
    // a local file and web fonts are the one resource a browser fetches under
    // cross-origin rules, so from file:// they can silently fall back to
    // Liberation (the download had Times-like type, 2026-10-12). Base64 of the
    // three woff2 files is about 140 KB and needs no network at all.
    $fontUrl = function (string $file) use ($base, $export): string {
        $disk = __DIR__ . '/../assets/fonts/mh/' . $file;
        if ($export && is_file($disk)) return 'data:font/woff2;base64,' . base64_encode((string)file_get_contents($disk));
        return $base . '/assets/fonts/mh/' . $file;
    };
    $fCormorant = $fontUrl('CormorantGaramond-normal.woff2');
    $fCormorantI = $fontUrl('CormorantGaramond-italic.woff2');
    $fJost = $fontUrl('Jost-normal.woff2');

    $type   = isset(social_templates()[(string)($p['type'] ?? '')]) ? (string)$p['type'] : 'featured';
    $oh     = social_is_openhouse($type);
    $full   = social_is_full($type);
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

    // The two looks. The label pill is solid gold with ink text on both (it has to read in a fast scroll).
    $c = $theme === 'light'
        // Light: espresso brown rather than black for the words and the logo (Nikki, 2026-10-12;
        // MH_vert-stack_brown.svg is the black file with its fill changed), and a fade that
        // starts higher and lands almost solid cream, because the words were hard to read.
        ? ['bg' => '#F1ECE2', 'text' => '#3A2E23', 'accent' => '#8A7350', 'muted' => '#4E4034', 'soft' => '#4A3D31',
           'fade' => 'rgba(241,236,226,.42)', 'shadow' => 'none',
           'fullfade' => 'rgba(241,236,226,0) 0%, rgba(241,236,226,.55) 40%, rgba(241,236,226,.97) 100%', 'fullh' => '66%',
           'logo' => $base . '/assets/images/MH_vert-stack_brown.svg', 'mark' => $base . '/assets/images/M-mark_brown.svg']
        : ['bg' => '#141210', 'text' => '#ffffff', 'accent' => '#C3A561', 'muted' => '#e4ddcf', 'soft' => '#ece6da',
           'fade' => 'rgba(20,18,16,.62)', 'shadow' => '0 2px 16px rgba(0,0,0,.35)',
           'fullfade' => 'rgba(0,0,0,0) 0%, rgba(12,11,9,.86) 100%', 'fullh' => '58%',
           'logo' => $base . '/assets/images/MH_vert-stack_white.svg', 'mark' => $base . '/assets/images/M-mark_white.svg'];

    // Open House text under a date block runs a size smaller (the split layout only).
    $k  = ($oh && !$full) ? 0.86 : 1.0;
    $sz = fn(int $px) => round($px * $k);

    // The text block: under the photo (split family) or over its bottom (full family).
    $bodyCss = $full
        ? ".body{position:absolute;left:0;right:0;bottom:0;padding:0 64px 64px;box-sizing:border-box;text-shadow:{$c['shadow']}}"
        . ".photo::after{height:{$c['fullh']};background:linear-gradient(to bottom,{$c['fullfade']})}"
        . ".logo{bottom:64px;height:132px}.lines{padding-right:260px}.rule{margin-bottom:22px}"
        : ".body{position:absolute;left:0;top:{$photoH}px;width:{$W}px;height:{$bodyH}px;padding:50px 64px 0;box-sizing:border-box}";

    $css = <<<CSS
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url({$fCormorant}) format('woff2')}
@font-face{font-family:'Cormorant Garamond';font-style:italic;font-weight:300 700;src:url({$fCormorantI}) format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:100 900;src:url({$fJost}) format('woff2')}
html,body{margin:0;padding:0}
body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['bg']};color:{$c['text']};
     font-family:'Jost','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased}
.photo{position:absolute;left:0;top:0;width:{$W}px;height:{$photoH}px;overflow:hidden;background:#26221d}
.photo img.pic{display:block;width:{$zpct}%;height:{$zpct}%;object-fit:cover;object-position:{$fx}% {$fy}%;transform:translate({$tx}px,{$ty}px)}
.photo .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:{$c['bg']}}
.photo .none img{width:260px;opacity:.16}
.photo::after{content:'';position:absolute;left:0;right:0;bottom:0;height:220px;
     background:linear-gradient(to bottom,rgba(0,0,0,0) 0%,{$c['fade']} 100%)}
.pill{position:absolute;left:64px;top:64px;z-index:2;padding:21px 32px 19px;background:#C3A561;color:#141210;
     font-size:27px;font-weight:600;letter-spacing:.26em;text-transform:uppercase;line-height:1;white-space:nowrap;
     box-shadow:0 4px 18px rgba(0,0,0,.22)}
.rule{width:72px;height:3px;background:{$c['accent']};margin:0 0 26px}
.when{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;line-height:1.16;margin:0 0 8px;white-space:nowrap;overflow:hidden}
.time{font-size:30px;font-weight:400;letter-spacing:.14em;text-transform:uppercase;color:{$c['accent']};margin:0 0 28px;white-space:nowrap;overflow:hidden}
.whenline{font-size:28px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:{$c['accent']};margin:0 0 18px;white-space:nowrap;overflow:hidden}
.addr{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;line-height:1.16;margin:0 0 10px;white-space:nowrap;overflow:hidden}
.lines{padding-right:220px}
.ln{margin:0 0 10px;white-space:nowrap;overflow:hidden;line-height:1.25}
.ln-town{font-size:{$sz(31)}px;font-weight:400;letter-spacing:.08em;text-transform:uppercase;color:{$c['accent']};margin-bottom:14px}
.ln-price{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$sz(54)}px;line-height:1.18;margin-bottom:8px}
.ln-details{font-size:{$sz(26)}px;font-weight:300;color:{$c['muted']};letter-spacing:.03em}
.ln-note{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-size:{$sz(31)}px;line-height:1.3;color:{$c['soft']}}
.ln-plain{font-size:{$sz(30)}px;font-weight:400;color:{$c['text']}}
.logo{position:absolute;right:64px;bottom:56px;height:132px}
{$bodyCss}
CSS;

    $photoHtml = $photo !== ''
        ? '<img class="pic" src="' . $e($photo) . '" alt="">'
        : '<div class="none"><img src="' . $e($c['mark']) . '" alt=""></div>';

    $label = trim((string)($p['label'] ?? ''));
    $pill  = $label !== '' ? '<div class="pill">' . $e($label) . '</div>' : '';

    $body = '<div class="rule"></div>';
    if ($oh) {
        $date = (string)($p['date_text'] ?? ''); $time = (string)($p['time_text'] ?? '');
        if ($full) {
            // One gold line: "Saturday, October 18  ·  11 AM to 2 PM".
            $when = implode('  ·  ', array_filter([$date, $time], fn($s) => $s !== ''));
            if ($when !== '') $body .= '<p class="whenline">' . $e($when) . '</p>';
        } else {
            if ($date !== '') $body .= '<h1 class="when" style="font-size:' . social_fit_size($date, 66, [24 => 56, 30 => 48]) . 'px">' . $e($date) . '</h1>';
            if ($time !== '') $body .= '<p class="time">' . $e($time) . '</p>';
        }
    }
    $headline = (string)($p['headline'] ?? '');
    if ($headline !== '') {
        $size = ($oh && !$full) ? social_fit_size($headline, 46, [34 => 40, 42 => 34, 52 => 28])
                                : social_fit_size($headline, 76, [26 => 64, 32 => 54, 40 => 44, 50 => 36]);
        $tag  = ($oh && !$full) ? 'h2' : 'h1';
        $body .= "<{$tag} class=\"addr\" style=\"font-size:{$size}px\">" . $e($headline) . "</{$tag}>";
    }
    $styles = social_line_styles();
    $lines  = '';
    foreach (array_slice((array)($p['lines'] ?? []), 0, social_line_count($type)) as $ln) {   // the template's slots only
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
