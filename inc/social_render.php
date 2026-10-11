<?php
/**
 * inc/social_render.php — the social post templates (Nikki, 2026-10-11).
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
 * Templates:  featured   one photo, a label, the address, price and details
 *             openhouse  the date and time first, then the listing
 * Format:     Instagram portrait, 1080 x 1440 (Nikki, 2026-10-11).
 * Assets:     fonts (assets/fonts/mh, self-hosted so the render never waits on
 *             Google), the white vertical-stack logo, the agent's square
 *             headshot and the listing photo through the public site's
 *             img.php proxy, all by absolute URL under $base.
 */

const SOCIAL_W = 1080;
const SOCIAL_H = 1440;

/** The templates an agent can pick, in menu order. */
function social_templates(): array {
    return [
        'featured'  => ['name' => 'Featured Listing', 'blurb' => 'One photo, the address, price and details, and you.'],
        'openhouse' => ['name' => 'Open House',       'blurb' => 'The date and time front and centre, then the listing and you.'],
    ];
}

/** The label on the Featured Listing photo (a dropdown; the first is the default). */
function social_labels(): array {
    return ['Featured Listing', 'Just Listed', 'New Price', 'Under Contract', 'Just Sold', 'Featured Rental', 'Coming Soon'];
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

/** "(970) 948-2766" from whatever the profile holds; other shapes are left alone. */
function social_phone_text(string $p): string {
    $d = preg_replace('/\D+/', '', $p);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    if (strlen($d) === 10) return sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6));
    return trim($p);
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

/** The complete HTML document for one post. */
function social_render(array $p, ?string $base = null): string {
    $base  = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $fonts = $base . '/assets/fonts/mh';
    $logo  = $base . '/assets/images/MH_vert-stack_white.svg';
    $mark  = $base . '/assets/images/M-mark_white.svg';
    $W = SOCIAL_W; $H = SOCIAL_H;

    $oh     = ($p['type'] ?? 'featured') === 'openhouse';
    $photoH = $oh ? 860 : 900;
    $fx     = max(0, min(100, (int)($p['focus_x'] ?? 50)));
    $fy     = max(0, min(100, (int)($p['focus_y'] ?? 50)));
    $photo  = (string)($p['photo_url'] ?? '');
    $label  = $oh ? 'Open House' : (string)($p['label'] ?? 'Featured Listing');

    $agent    = $p['agent'] ?? [];
    $headshot = !empty($p['show_headshot']) ? (string)($agent['headshot'] ?? '') : '';
    $aname    = (string)($agent['name'] ?? '');
    $ameta    = implode('  ·  ', array_filter([(string)($agent['title'] ?? ''), (string)($agent['phone'] ?? '')], fn($s) => $s !== ''));
    if ($oh) $ameta = (string)($agent['phone'] ?? '');

    $css = <<<CSS
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url({$fonts}/CormorantGaramond-normal.woff2) format('woff2')}
@font-face{font-family:'Cormorant Garamond';font-style:italic;font-weight:300 700;src:url({$fonts}/CormorantGaramond-italic.woff2) format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:100 900;src:url({$fonts}/Jost-normal.woff2) format('woff2')}
html,body{margin:0;padding:0}
body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:#141210;color:#fff;
     font-family:'Jost','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased}
.photo{position:absolute;left:0;top:0;width:{$W}px;height:{$photoH}px;overflow:hidden;background:#26221d}
.photo img.pic{width:100%;height:100%;object-fit:cover;object-position:{$fx}% {$fy}%;display:block}
.photo .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
.photo .none img{width:260px;opacity:.18}
.photo::after{content:'';position:absolute;left:0;right:0;bottom:0;height:240px;
     background:linear-gradient(to bottom,rgba(20,18,16,0) 0%,rgba(20,18,16,.62) 100%)}
.pill{position:absolute;left:64px;top:64px;z-index:2;padding:17px 28px 15px;background:rgba(20,18,16,.74);color:#fff;
     font-size:22px;font-weight:500;letter-spacing:.32em;text-transform:uppercase;line-height:1}
.body{position:absolute;left:0;top:{$photoH}px;width:{$W}px;height:calc({$H}px - {$photoH}px);padding:50px 64px 0;box-sizing:border-box}
.rule{width:72px;height:3px;background:#C3A561;margin:0 0 26px}
.addr{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;line-height:1.02;margin:0 0 14px;white-space:nowrap;overflow:hidden}
.city{font-size:27px;font-weight:400;letter-spacing:.08em;text-transform:uppercase;color:#C3A561;margin:0 0 30px;white-space:nowrap;overflow:hidden}
.row{display:flex;align-items:baseline;gap:30px;margin:0 0 22px;white-space:nowrap;overflow:hidden}
.price{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:54px;line-height:1}
.specs{font-size:26px;font-weight:300;color:#d9d2c3;letter-spacing:.03em}
.note{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-size:31px;line-height:1.2;color:#e8e2d6;margin:0;white-space:nowrap;overflow:hidden}
.when{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:66px;line-height:1.02;margin:0 0 12px;white-space:nowrap;overflow:hidden}
.time{font-size:30px;font-weight:400;letter-spacing:.14em;text-transform:uppercase;color:#C3A561;margin:0 0 30px;white-space:nowrap;overflow:hidden}
.oh-addr{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;line-height:1.05;margin:0 0 8px;white-space:nowrap;overflow:hidden}
.oh-city{font-size:22px;font-weight:400;letter-spacing:.08em;text-transform:uppercase;color:#cfc6b3;margin:0 0 10px;white-space:nowrap;overflow:hidden}
.oh-row{font-size:23px;font-weight:300;color:#d9d2c3;letter-spacing:.03em;margin:0;white-space:nowrap;overflow:hidden}
.oh-row b{font-weight:500;color:#fff}
.agent{position:absolute;left:64px;right:64px;bottom:56px;display:flex;align-items:center;gap:26px;
     border-top:1px solid rgba(195,165,97,.38);padding-top:34px}
.agent .hs{width:92px;height:92px;border-radius:50%;object-fit:cover;border:2px solid #C3A561;flex:none}
.agent .who{flex:1;min-width:0}
.agent .by{font-size:15px;font-weight:500;letter-spacing:.3em;text-transform:uppercase;color:#C3A561;margin:0 0 7px}
.agent .name{font-size:29px;font-weight:500;letter-spacing:.02em;line-height:1.1;white-space:nowrap;overflow:hidden}
.agent .meta{font-size:21px;font-weight:300;color:#cfc6b3;margin-top:5px;white-space:nowrap;overflow:hidden}
.agent .logo{height:100px;flex:none}
CSS;

    $photoHtml = $photo !== ''
        ? '<img class="pic" src="' . $e($photo) . '" alt="">'
        : '<div class="none"><img src="' . $e($mark) . '" alt=""></div>';

    if ($oh) {
        $addrSize = social_fit_size((string)$p['headline'], 46, [30 => 40, 38 => 34, 48 => 28]);
        $whenSize = social_fit_size((string)$p['date_text'], 66, [24 => 56, 30 => 48]);
        $rowBits  = array_filter([(string)$p['price'] !== '' ? '<b>' . $e($p['price']) . '</b>' : '', $e($p['specs'])], fn($s) => $s !== '');
        $body = '<div class="rule"></div>'
              . '<h1 class="when" style="font-size:' . $whenSize . 'px">' . $e($p['date_text']) . '</h1>'
              . ((string)$p['time_text'] !== '' ? '<p class="time">' . $e($p['time_text']) . '</p>' : '<p class="time" style="margin:0 0 18px"></p>')
              . '<h2 class="oh-addr" style="font-size:' . $addrSize . 'px">' . $e($p['headline']) . '</h2>'
              . ((string)$p['subline'] !== '' ? '<p class="oh-city">' . $e($p['subline']) . '</p>' : '')
              . ($rowBits ? '<p class="oh-row">' . implode('&nbsp;&nbsp;·&nbsp;&nbsp;', $rowBits) . '</p>' : '');
        $by = 'Hosted by';
    } else {
        $h1Size = social_fit_size((string)$p['headline'], 74, [26 => 62, 32 => 54, 40 => 46, 50 => 38]);
        $body = '<div class="rule"></div>'
              . '<h1 class="addr" style="font-size:' . $h1Size . 'px">' . $e($p['headline']) . '</h1>'
              . ((string)$p['subline'] !== '' ? '<p class="city">' . $e($p['subline']) . '</p>' : '')
              . '<div class="row">'
              . ((string)$p['price'] !== '' ? '<span class="price">' . $e($p['price']) . '</span>' : '')
              . ((string)$p['specs'] !== '' ? '<span class="specs">' . $e($p['specs']) . '</span>' : '')
              . '</div>'
              . ((string)$p['note'] !== '' ? '<p class="note">' . $e($p['note']) . '</p>' : '');
        $by = '';
    }

    $agentHtml = '<div class="agent">'
               . ($headshot !== '' ? '<img class="hs" src="' . $e($headshot) . '" alt="">' : '')
               . '<div class="who">'
               . ($by !== '' ? '<p class="by">' . $e($by) . '</p>' : '')
               . '<div class="name">' . $e($aname) . '</div>'
               . ($ameta !== '' ? '<div class="meta">' . $e($ameta) . '</div>' : '')
               . '</div>'
               . '<img class="logo" src="' . $e($logo) . '" alt="Mont Haus International Realty">'
               . '</div>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($p['headline'] ?? 'Post') . '</title><style>' . $css . '</style></head><body>'
         . '<div class="photo">' . $photoHtml . '</div>'
         . '<div class="pill">' . $e($label) . '</div>'
         . '<div class="body">' . $body . $agentHtml . '</div>'
         . '</body></html>';
}

/** A small page for the preview frame when there is nothing to render yet. */
function social_placeholder_html(string $msg): string {
    $W = SOCIAL_W; $H = SOCIAL_H;
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{width:' . $W . 'px;height:' . $H . 'px;'
         . 'display:flex;align-items:center;justify-content:center;background:#141210;color:#cfc6b3;font:300 40px/1.4 Jost,Arial,sans-serif;text-align:center;padding:0 120px;box-sizing:border-box}'
         . '</style></head><body>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</body></html>';
}
