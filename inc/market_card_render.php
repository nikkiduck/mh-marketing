<?php
/**
 * inc/market_card_render.php — the Market Snapshot post (2026-10-18;
 * redrawn twice that day to Nikki's notes).
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
 * a ghosted triangle (or M mark) and a stock photo behind the figures. No
 * logo at all (Nikki, 2026-10-18 evening: the watermark carries the brand). One place and one
 * property type per card; the average sale price large (the median until 2026-10-20, Jon's decision); three figures in rows, each
 * with its label and comparison centred on its number. Nikki, 2026-10-18,
 * evening: nothing under 30 px, the place name a step smaller, the month
 * line and the median's label larger, the median itself smaller, the rows'
 * words larger and centred, and no logo (the space is worth more). 2026-10-19
 * (Nikki): the title is the one location name, no town names beneath it (the
 * month line is month and property type only), and that line is 50 px. A story keeps a safe area of about 190 px at the top and 170 at
 * the bottom, as the Instagram story does.
 *
 * The post's height budget: 1350 less 74 + 84 padding = 1192 for the head
 * (about 290), the hero (about 290) and three rows (about 410 at the post's
 * scale); the two .gap spacers take whatever is left (about 70 px each on a
 * post since 2026-10-20), and all of the story's extra height. The story
 * keeps Nikki's InDesign sizes of 2026-10-19; the post runs one step smaller
 * (eyebrow 34, hero 150, change 42, rows 92 / 36 / 42, row padding 22). Measured on every card in both looks on
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
 *        backdrop '' or an image URL / file:// path. No logo.
 * $export = true inlines the fonts and marks as data URIs (Chromium opens the
 * file with no session and no cross-origin fonts).
 */
function market_card_render(array $card, array $opts = [], ?string $base = null, bool $export = false): string {
    $base   = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W      = MARKET_CARD_W;
    $format = ($opts['format'] ?? 'post') === 'story' ? 'story' : 'post';
    $theme  = ($opts['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $mark   = in_array((string)($opts['mark'] ?? ''), ['triangle', 'faint', 'mmark', 'none'], true) ? (string)$opts['mark'] : 'triangle';   // faint = the triangle at half strength (2026-10-28)
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
        ? ['bg' => '#F6F7F9', 'text' => '#1F242C', 'accent' => '#55637A', 'muted' => '#434B57', 'soft' => '#3A414C', 'line' => 'rgba(31,36,44,.22)',   // Slate, deeper buttons (2026-10-28)
           'shade' => 'rgba(246,247,249,.64) 0%, rgba(246,247,249,.76) 50%, rgba(246,247,249,.86) 100%', 'ghost' => '.05',
           'tri'  => $asset('assets/images/triangle_mark-dark.svg', 'image/svg+xml'), 'm' => $asset('assets/images/M-mark_black.svg', 'image/svg+xml')]
        : ['bg' => '#1F242C', 'text' => '#ffffff', 'accent' => '#D6DEE8', 'muted' => '#E3E8EE', 'soft' => '#EEF1F5', 'line' => 'rgba(255,255,255,.22)',   // the light slate on the photo (2026-10-28)
           'shade' => 'rgba(31,36,44,.52) 0%, rgba(31,36,44,.64) 50%, rgba(31,36,44,.78) 100%', 'ghost' => '.08',   // eased 2026-10-19 and again 2026-10-28 (Nikki: more of the photo)
           'tri'  => $asset('assets/images/triangle_mark-white.svg', 'image/svg+xml'), 'm' => $asset('assets/images/M-mark_white.svg', 'image/svg+xml')];

    $title   = (string)($card['title'] ?? '');
    $h       = (array)($card['hero'] ?? []);
    $heroVal = (string)($h['value'] ?? '');
    $sub     = (string)($card['sub'] ?? '');
    // Sizes from Nikki's InDesign pass (2026-10-19, market-snapshot-story.pdf): eyebrow 41, title 105,
    // month line 50 regular, median label 36, median 162, change 46 in the text colour, rows 100 / 38 gold / 46.
    // The story (1920 tall) takes those sizes as they are. The post (1350) is 570 px shorter and the same type
    // filled it edge to edge (Nikki, 2026-10-20: "crammed to the upper text"), so the post runs one step
    // smaller throughout and keeps about 70 px of air above and below the big figure. $z picks per format.
    $story = $format === 'story';
    $z = fn(int|string $post, int|string $storyV) => $story ? $storyV : $post;
    $titleSize = market_card_fit($title, 105, [12 => 94, 17 => 82, 22 => 70, 28 => 60]);   // one line across the full width
    $heroSize  = $story ? market_card_fit($heroVal, 162, [6 => 150, 8 => 136, 10 => 120]) : market_card_fit($heroVal, 150, [6 => 140, 8 => 126, 10 => 112]);
    $subSize   = market_card_fit($sub, 50, [38 => 44, 46 => 40]);   // 50 px since 2026-10-19 (Nikki: larger); never under 30, a long one wraps instead
    $padTop = ($story ? 150 : 74) + $top; $padBot = 84 + $bot;   // the story starts lower (Nikki's PDF: eyebrow at 345)

    $ghostFaint = round((float)$c['ghost'] / 2, 3);   // Faint triangle: half the look's strength (2026-10-28)
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
.ghost.faint{opacity:{$ghostFaint}}
.card{position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;box-sizing:border-box;padding:{$padTop}px 94px {$padBot}px}
.gap{flex:1 1 0;min-height:0}
.eyebrow{font-size:{$z(34, 41)}px;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:{$c['accent']};line-height:1.3}
.title{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$titleSize}px;line-height:1.02;margin:18px 0 0;white-space:nowrap}
.sub{font-size:{$subSize}px;font-weight:400;color:{$c['muted']};margin-top:{$z(16, 20)}px;line-height:1.3;text-wrap:balance}
.rule{width:90px;height:4px;background:{$c['accent']};margin-top:{$z(26, 30)}px}
.hero{margin-top:32px}
.hero .lab{font-size:36px;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:{$c['accent']};line-height:1.3}
.hero .val{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$heroSize}px;line-height:1;letter-spacing:-.01em;margin-top:16px;white-space:nowrap}
.chg{font-size:{$z(42, 46)}px;font-weight:400;margin-top:{$z(22, 30)}px;line-height:1.3}
.chg i{font-style:normal;color:{$c['accent']};font-size:.78em;margin-right:12px;vertical-align:.1em}
.stats{margin-top:{$z(36, 40)}px;display:flex;flex-direction:column}
.stat{display:flex;align-items:center;gap:42px;padding:{$z(22, 30)}px 0}
.stat+.stat{border-top:1px solid {$c['line']}}   /* lines between the rows only (Nikki, 2026-10-19) */
.stat .val{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$z(92, 100)}px;line-height:1;min-width:280px;white-space:nowrap}
.stat .txt{display:flex;flex-direction:column;gap:6px;min-width:0}
.stat .lab{font-size:{$z(36, 38)}px;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:{$c['accent']};line-height:1.2}
.stat .note{font-size:{$z(42, 46)}px;font-weight:400;color:{$c['soft']};line-height:1.3}
CSS;

    $bdHtml = $bd !== '' ? '<div class="bd"><img src="' . $e($bd) . '" alt=""></div>' : '';
    $ghostHtml = match ($mark) {
        'mmark' => '<img class="ghost m" src="' . $e($c['m']) . '" alt="">',
        'none'  => '',
        'faint' => '<img class="ghost faint" src="' . $e($c['tri']) . '" alt="">',
        default => '<img class="ghost" src="' . $e($c['tri']) . '" alt="">',
    };
    $arrow = fn(int $dir) => $dir > 0 ? '<i>▲</i>' : ($dir < 0 ? '<i>▼</i>' : '');

    $body  = '<header class="head">'
           . '<div class="eyebrow">' . $e($card['eyebrow'] ?? '') . '</div>'
           . '<h1 class="title">' . $e($title) . '</h1>'
           . ($sub !== '' ? '<div class="sub">' . $e($sub) . '</div>' : '')
           . '<div class="rule"></div></header>';
    $body .= '<div class="gap"></div>';
    $body .= '<section class="hero"><div class="lab">' . $e($h['label'] ?? '') . '</div>'
           . '<div class="val">' . $e($heroVal) . '</div>'
           . (($h['change'] ?? '') !== '' ? '<div class="chg">' . $e($h['change']) . '</div>' : '')   // no arrow (Nikki's 2026-10-19 layout)
           . '</section><div class="gap"></div><section class="stats">';
    foreach ((array)($card['stats'] ?? []) as [$lab, $val, $note]) {
        $body .= '<div class="stat"><div class="val">' . $e($val) . '</div><div class="txt"><div class="lab">' . $e($lab) . '</div>'
               . ($note !== '' ? '<div class="note">' . $e($note) . '</div>' : '') . '</div></div>';
    }
    $body .= '</section>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($title . ' market snapshot') . '</title><style>' . $css . '</style></head><body>'
         . $bdHtml . $ghostHtml
         . '<div class="card">' . $body . '</div>'
         . '</body></html>';
}
