<?php
/**
 * inc/market_card_render.php — the Market Snapshot post (2026-10-18;
 * redrawn the same day to Nikki's notes).
 *
 * One function turns a card model (market_card_model() in inc/market_data.php:
 * the words and figures, no HTML) into a complete HTML document sized
 * 1080 x 1350 (a 4:5 post) or 1080 x 1920 (a story). The SAME document is the
 * live preview on the portal (an iframe's srcdoc) and the page headless
 * Chromium screenshots for the PNG, as for the Instagram posts: never draw
 * the card in JavaScript.
 *
 * Standalone on purpose (no portal.php, no database): tests/render_market_sample.php
 * renders every card from the data files alone.
 *
 * The look is the Instagram templates' (inc/social_render.php): Cormorant
 * Garamond on the title and the figures, Jost on the labels, the champagne
 * gold rule and small-caps labels, the dark ink or the cream light ground,
 * the vertical-stack logo upper right or lower left, a ghosted triangle (or
 * M mark) and a stock photo behind the figures. Nikki, 2026-10-18, after
 * the first version: one place and one property type per card, the figures
 * large enough to read on a phone (the median, then three figures in rows: closed sales, days on
 * market, homes for sale),
 * no second-type line, no source block, the photo brighter and the
 * watermark fainter. The title has the full width: with the logo upper
 * right, the logo shares the eyebrow row and the title sits under both.
 * A story keeps a safe area of about 190 px at the top and 170 at the
 * bottom, as the Instagram story does.
 *
 * The post's height budget (logo upper right): 1350 less 74 + 84 padding =
 * 1192 for the head (about 370), the hero (about 370) and three rows
 * (about 430); the two .gap spacers take whatever is left, and all of the
 * story's extra height. With the logo lower left the type steps down to
 * 0.9 to make room for it. Measured on every card in both looks on
 * 2026-10-18 (tests/render_market_sample.php, then the browser).
 */

const MARKET_CARD_W = 1080;

/** The two heights: a 4:5 post and a 9:16 story. */
function market_card_height(string $format): int { return $format === 'story' ? 1920 : 1350; }

/** [top, bottom] insets in px: the story's safe area, nothing on a post. */
function market_card_insets(string $format): array { return $format === 'story' ? [190, 170] : [0, 0]; }

/** A font size that keeps a line to one line, by length: the first threshold the text exceeds wins. */
function market_card_fit(string $text, int $size, array $steps): int {
    $n = mb_strlen($text);
    foreach ($steps as $chars => $smaller) if ($n > $chars) $size = $smaller;
    return $size;
}

/**
 * The complete HTML document for one card.
 * $opts: format post|story, theme dark|light, mark triangle|mmark|none,
 *        logo top|bottom, backdrop '' or an image URL / file:// path.
 * $export = true inlines the fonts and marks as data URIs (Chromium opens the
 * file with no session and no cross-origin fonts).
 */
function market_card_render(array $card, array $opts = [], ?string $base = null, bool $export = false): string {
    $base   = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W      = MARKET_CARD_W;
    $format = ($opts['format'] ?? 'post') === 'story' ? 'story' : 'post';
    $theme  = ($opts['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $mark   = in_array((string)($opts['mark'] ?? ''), ['triangle', 'mmark', 'none'], true) ? (string)$opts['mark'] : 'triangle';
    $logo   = ($opts['logo'] ?? 'top') === 'bottom' ? 'bottom' : 'top';
    $bd     = (string)($opts['backdrop'] ?? '');
    $H      = market_card_height($format);
    [$top, $bot] = market_card_insets($format);

    // Assets by URL for the preview, inlined for the export (see social_render()).
    $asset = function (string $rel, string $mime) use ($base, $export): string {
        $disk = __DIR__ . '/../' . $rel;
        if ($export && is_file($disk)) return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($disk));
        return $base . '/' . $rel;
    };
    $fCormorant = $asset('assets/fonts/mh/CormorantGaramond-normal.woff2', 'font/woff2');
    $fJost = $asset('assets/fonts/mh/Jost-normal.woff2', 'font/woff2');

    // The two looks, the Instagram posts' palettes. The shade over the photo
    // is lighter at the top so the picture shows, darker where the figures sit.
    $c = $theme === 'light'
        ? ['bg' => '#F1ECE2', 'text' => '#3A2E23', 'accent' => '#8A7350', 'muted' => '#4E4034', 'soft' => '#4A3D31', 'line' => 'rgba(58,46,35,.22)',
           'shade' => 'rgba(241,236,226,.55) 0%, rgba(241,236,226,.80) 42%, rgba(241,236,226,.94) 100%', 'ghost' => '.05',
           'logo' => $asset('assets/images/MH_vert-stack_brown.svg', 'image/svg+xml'),
           'tri'  => $asset('assets/images/triangle_mark-dark.svg', 'image/svg+xml'), 'm' => $asset('assets/images/M-mark_black.svg', 'image/svg+xml')]
        : ['bg' => '#141210', 'text' => '#ffffff', 'accent' => '#C3A561', 'muted' => '#e4ddcf', 'soft' => '#ece6da', 'line' => 'rgba(255,255,255,.22)',
           'shade' => 'rgba(20,18,16,.42) 0%, rgba(20,18,16,.72) 42%, rgba(20,18,16,.90) 100%', 'ghost' => '.08',
           'logo' => $asset('assets/images/MH_vert-stack_white.svg', 'image/svg+xml'),
           'tri'  => $asset('assets/images/triangle_mark-white.svg', 'image/svg+xml'), 'm' => $asset('assets/images/M-mark_white.svg', 'image/svg+xml')];

    $title = (string)($card['title'] ?? '');
    $h     = (array)($card['hero'] ?? []);
    $heroVal = (string)($h['value'] ?? '');
    $sub   = (string)($card['sub'] ?? '');
    // With the logo lower left there is less height for the figures: the type steps down a size.
    $k = $logo === 'bottom' ? 0.9 : 1.0;
    $sz = fn(int $px) => (int)round($px * $k);
    $titleSize = $sz(market_card_fit($title, 132, [12 => 116, 17 => 100, 22 => 84, 28 => 70]));   // one line across the full width
    $heroSize  = $sz(market_card_fit($heroVal, 196, [6 => 182, 8 => 160, 10 => 140]));
    $subSize   = $sz(market_card_fit($sub, 32, [50 => 29, 58 => 26]));
    $padTop = 74 + $top; $padBot = 84 + $bot;

    $css = <<<CSS
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url({$fCormorant}) format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:100 900;src:url({$fJost}) format('woff2')}
html,body{margin:0;padding:0}
body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['bg']};color:{$c['text']};
     font-family:'Jost','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased;font-variant-numeric:lining-nums tabular-nums}
.bd{position:absolute;inset:0;z-index:0}
.bd img{display:block;width:100%;height:100%;object-fit:cover}
.bd::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,{$c['shade']})}
.ghost{position:absolute;right:-220px;bottom:0;width:970px;z-index:1;opacity:{$c['ghost']}}
.ghost.m{right:0;width:745px}
.card{position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;box-sizing:border-box;padding:{$padTop}px 94px {$padBot}px}
.gap{flex:1 1 0;min-height:0}
.toprow{display:flex;align-items:flex-start;justify-content:space-between;gap:40px}
.toprow .logo{height:118px;display:block;flex:none;margin-top:-4px}
.eyebrow{font-size:{$sz(27)}px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:{$c['accent']};line-height:1.3;padding-top:8px}
.title{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$titleSize}px;line-height:1.02;margin:12px 0 0;white-space:nowrap}
.sub{font-size:{$subSize}px;font-weight:300;color:{$c['muted']};margin-top:12px;line-height:1.3;white-space:nowrap}
.rule{width:90px;height:4px;background:{$c['accent']};margin-top:24px}
.hero{margin-top:{$sz(32)}px}
.hero .lab{font-size:{$sz(27)}px;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:{$c['accent']};line-height:1.3}
.hero .val{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$heroSize}px;line-height:1;letter-spacing:-.01em;margin-top:4px;white-space:nowrap}
.chg{font-size:{$sz(40)}px;font-weight:400;margin-top:10px;line-height:1.3}
.chg i{font-style:normal;color:{$c['accent']};font-size:.78em;margin-right:12px;vertical-align:.1em}
.stats{margin-top:{$sz(40)}px;display:flex;flex-direction:column}
.stat{display:flex;align-items:baseline;gap:36px;padding:{$sz(16)}px 0;border-top:1px solid {$c['line']}}
.stat:last-child{border-bottom:1px solid {$c['line']}}
.stat .val{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$sz(84)}px;line-height:1;min-width:{$sz(280)}px;white-space:nowrap}
.stat .txt{display:flex;flex-direction:column;gap:5px;min-width:0}
.stat .lab{font-size:{$sz(25)}px;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:{$c['muted']};line-height:1.25}
.stat .note{font-size:{$sz(31)}px;font-weight:300;color:{$c['soft']};line-height:1.3}
.foot{margin-top:auto;padding-top:28px}
.foot img{height:150px;display:block}
CSS;

    $bdHtml = $bd !== '' ? '<div class="bd"><img src="' . $e($bd) . '" alt=""></div>' : '';
    $ghostHtml = match ($mark) {
        'mmark' => '<img class="ghost m" src="' . $e($c['m']) . '" alt="">',
        'none'  => '',
        default => '<img class="ghost" src="' . $e($c['tri']) . '" alt="">',
    };
    $logoImg = '<img class="logo" src="' . $e($c['logo']) . '" alt="Mont Haus International Realty">';
    $arrow = fn(int $dir) => $dir > 0 ? '<i>▲</i>' : ($dir < 0 ? '<i>▼</i>' : '');

    $body  = '<header class="head">'
           . '<div class="toprow"><div class="eyebrow">' . $e($card['eyebrow'] ?? '') . '</div>' . ($logo === 'top' ? $logoImg : '') . '</div>'
           . '<h1 class="title">' . $e($title) . '</h1>'
           . ($sub !== '' ? '<div class="sub">' . $e($sub) . '</div>' : '')
           . '<div class="rule"></div></header>';
    $body .= '<div class="gap"></div>';
    $body .= '<section class="hero"><div class="lab">' . $e($h['label'] ?? '') . '</div>'
           . '<div class="val">' . $e($heroVal) . '</div>'
           . (($h['change'] ?? '') !== '' ? '<div class="chg">' . $arrow((int)($h['dir'] ?? 0)) . $e($h['change']) . '</div>' : '')
           . '</section><div class="gap"></div><section class="stats">';
    foreach ((array)($card['stats'] ?? []) as [$lab, $val, $note]) {
        $body .= '<div class="stat"><div class="val">' . $e($val) . '</div><div class="txt"><div class="lab">' . $e($lab) . '</div>'
               . ($note !== '' ? '<div class="note">' . $e($note) . '</div>' : '') . '</div></div>';
    }
    $body .= '</section>';
    if ($logo === 'bottom') $body .= '<div class="gap"></div><footer class="foot">' . $logoImg . '</footer>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($title . ' market snapshot') . '</title><style>' . $css . '</style></head><body>'
         . $bdHtml . $ghostHtml
         . '<div class="card">' . $body . '</div>'
         . '</body></html>';
}
