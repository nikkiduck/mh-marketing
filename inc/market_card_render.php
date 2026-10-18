<?php
/**
 * inc/market_card_render.php — the Market Snapshot post (2026-10-18).
 *
 * One function turns a card model (market_card_model() in inc/market.php:
 * the words and figures, no HTML) into a complete HTML document sized
 * 1080 x 1350 (a 4:5 post) or 1080 x 1920 (a story). The SAME document is the
 * live preview on the portal (an iframe's srcdoc) and the page headless
 * Chromium screenshots for the PNG, as for the Instagram posts: never draw
 * the card in JavaScript.
 *
 * Standalone on purpose (no portal.php, no database): tests/render_market_sample.php
 * renders every card from the data files alone.
 *
 * The look is the Instagram templates' (inc/social_render.php), not the
 * artifact's: Cormorant Garamond on the title and the big figures, Jost on
 * the labels, the champagne gold rule and small-caps labels, the dark ink or
 * the cream light ground, the ghosted triangle (or M mark) lower right, the
 * vertical-stack logo top right or bottom left, and an optional stock photo
 * as a backdrop under a heavy shade (Nikki, 2026-10-18: "dress up the look a
 * little"). Three layouts: hero (one county: a big median, four tiles),
 * metro (REcolorado's own figures in the hero layout) and list (two or three
 * counties, the median large on each row). A story keeps a safe area of
 * about 190 px at the top and 170 at the bottom, as the Instagram story does.
 */

const MARKET_CARD_W = 1080;

/** The two heights: a 4:5 post and a 9:16 story. */
function market_card_height(string $format): int { return $format === 'story' ? 1920 : 1350; }

/** [top, bottom] insets in px: the story's safe area, nothing on a post. */
function market_card_insets(string $format): array { return $format === 'story' ? [190, 170] : [0, 0]; }

/** A font size that keeps the title to one line, by length: the first threshold the text exceeds wins. */
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
    $layout = (string)($card['layout'] ?? 'hero');

    // Assets by URL for the preview, inlined for the export (see social_render()).
    $asset = function (string $rel, string $mime) use ($base, $export): string {
        $disk = __DIR__ . '/../' . $rel;
        if ($export && is_file($disk)) return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($disk));
        return $base . '/' . $rel;
    };
    $fCormorant = $asset('assets/fonts/mh/CormorantGaramond-normal.woff2', 'font/woff2');
    $fCormorantI = $asset('assets/fonts/mh/CormorantGaramond-italic.woff2', 'font/woff2');
    $fJost = $asset('assets/fonts/mh/Jost-normal.woff2', 'font/woff2');

    // The two looks, the Instagram posts' palettes.
    $c = $theme === 'light'
        ? ['bg' => '#F1ECE2', 'text' => '#3A2E23', 'accent' => '#8A7350', 'muted' => '#4E4034', 'soft' => '#4A3D31', 'line' => 'rgba(58,46,35,.22)',
           'shade' => 'rgba(241,236,226,.84) 0%, rgba(241,236,226,.93) 40%, rgba(241,236,226,.985) 100%', 'ghost' => '.09',
           'logo' => $asset('assets/images/MH_vert-stack_brown.svg', 'image/svg+xml'),
           'tri'  => $asset('assets/images/triangle_mark-dark.svg', 'image/svg+xml'), 'm' => $asset('assets/images/M-mark_black.svg', 'image/svg+xml')]
        : ['bg' => '#141210', 'text' => '#ffffff', 'accent' => '#C3A561', 'muted' => '#e4ddcf', 'soft' => '#ece6da', 'line' => 'rgba(255,255,255,.22)',
           'shade' => 'rgba(20,18,16,.80) 0%, rgba(20,18,16,.92) 40%, rgba(20,18,16,.985) 100%', 'ghost' => '.16',
           'logo' => $asset('assets/images/MH_vert-stack_white.svg', 'image/svg+xml'),
           'tri'  => $asset('assets/images/triangle_mark-white.svg', 'image/svg+xml'), 'm' => $asset('assets/images/M-mark_white.svg', 'image/svg+xml')];

    $title = (string)($card['title'] ?? '');
    $titleSize = market_card_fit($title, 84, [16 => 76, 20 => 66, 26 => 58]);
    $padTop = 74 + $top; $padBot = 84 + $bot;
    $headRight = $logo === 'top' ? 230 : 0;    // room for the logo beside the title

    $css = <<<CSS
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url({$fCormorant}) format('woff2')}
@font-face{font-family:'Cormorant Garamond';font-style:italic;font-weight:300 700;src:url({$fCormorantI}) format('woff2')}
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
.head{padding-right:{$headRight}px}
.eyebrow{font-size:24px;font-weight:500;letter-spacing:.22em;text-transform:uppercase;color:{$c['accent']};line-height:1.25}
.title{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$titleSize}px;line-height:1.05;margin:14px 0 0;text-wrap:balance}
.sub{font-size:28px;font-weight:300;color:{$c['muted']};margin-top:12px;line-height:1.3}
.rule{width:72px;height:3px;background:{$c['accent']};margin-top:26px}
.logo{position:absolute;right:94px;top:{$padTop}px;height:120px;z-index:3}
.hero{margin-top:46px}
.hero .lab{font-size:24px;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:{$c['accent']};line-height:1.25}
.hero .val{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:176px;line-height:1;letter-spacing:-.01em;margin-top:6px;white-space:nowrap}
.chg{font-size:34px;font-weight:400;margin-top:12px;line-height:1.3}
.chg i{font-style:normal;color:{$c['accent']};font-size:.8em;margin-right:10px;vertical-align:.08em}
.stats{margin-top:48px;border-top:1px solid {$c['line']};padding-top:38px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:48px;row-gap:40px}
.stat .lab{font-size:22px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:{$c['muted']};line-height:1.25}
.stat .val{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:74px;line-height:1.05;margin-top:6px;white-space:nowrap}
.stat .note{font-size:26px;font-weight:300;color:{$c['soft']};margin-top:6px;line-height:1.3}
.rows{margin-top:34px;display:flex;flex-direction:column}
.row{padding:26px 0 24px;border-top:1px solid {$c['line']}}
.row:last-child{border-bottom:1px solid {$c['line']}}
.row .lab{font-size:24px;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:{$c['accent']};line-height:1.25}
.row .line{display:flex;align-items:baseline;justify-content:space-between;gap:24px;flex-wrap:wrap;margin-top:4px}
.row .val{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:100px;line-height:1.05;white-space:nowrap}
.row .chg{margin-top:0;font-size:32px}
.row .txt{font-size:26px;font-weight:300;color:{$c['soft']};margin-top:6px;line-height:1.3}
.foot{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-size:32px;line-height:1.3;color:{$c['soft']};margin:40px 0 0}
.credit{margin-top:auto;padding-top:36px;display:flex;align-items:flex-end;justify-content:space-between;gap:40px}
.credit img{height:120px;display:block;flex:none}
.credit p{margin:0;font-size:17px;font-weight:300;line-height:1.4;color:{$c['muted']};max-width:560px}
.credit.full p{max-width:none}
CSS;

    // Backdrop and watermark
    $bdHtml = $bd !== '' ? '<div class="bd"><img src="' . $e($bd) . '" alt=""></div>' : '';
    $ghostHtml = match ($mark) {
        'mmark' => '<img class="ghost m" src="' . $e($c['m']) . '" alt="">',
        'none'  => '',
        default => '<img class="ghost" src="' . $e($c['tri']) . '" alt="">',
    };
    $logoHtml = '<img class="logo" src="' . $e($c['logo']) . '" alt="Mont Haus International Realty">';
    $arrow = fn(int $dir) => $dir > 0 ? '<i>▲</i>' : ($dir < 0 ? '<i>▼</i>' : '');

    $body  = '<header class="head">'
           . '<div class="eyebrow">' . $e($card['eyebrow'] ?? '') . '</div>'
           . '<h1 class="title">' . $e($title) . '</h1>'
           . (($card['sub'] ?? '') !== '' ? '<div class="sub">' . $e($card['sub']) . '</div>' : '')
           . '<div class="rule"></div></header>';
    $body .= '<div class="gap"></div>';

    if ($layout === 'list') {
        $body .= '<section class="rows">';
        foreach ((array)($card['rows'] ?? []) as $r) {
            $body .= '<div class="row"><div class="lab">' . $e($r['title']) . '</div>'
                   . '<div class="line"><div class="val">' . $e($r['value']) . '</div>'
                   . (($r['change'] ?? '') !== '' ? '<div class="chg">' . $arrow((int)($r['dir'] ?? 0)) . $e($r['change']) . '</div>' : '') . '</div>';
            foreach ((array)($r['lines'] ?? []) as $ln) if ($ln !== '') $body .= '<div class="txt">' . $e($ln) . '</div>';
            $body .= '</div>';
        }
        $body .= '</section><div class="gap"></div>';   // the slack splits around the rows, not all above them
    } else {
        $h = (array)($card['hero'] ?? []);
        $body .= '<section class="hero"><div class="lab">' . $e($h['label'] ?? '') . '</div>'
               . '<div class="val">' . $e($h['value'] ?? '') . '</div>'
               . (($h['change'] ?? '') !== '' ? '<div class="chg">' . $arrow((int)($h['dir'] ?? 0)) . $e($h['change']) . '</div>' : '')
               . '</section><div class="gap"></div><section class="stats">';
        foreach ((array)($card['stats'] ?? []) as [$lab, $val, $note]) {
            $body .= '<div class="stat"><div class="lab">' . $e($lab) . '</div><div class="val">' . $e($val) . '</div>'
                   . ($note !== '' ? '<div class="note">' . $e($note) . '</div>' : '') . '</div>';
        }
        $body .= '</section>';
    }
    if (($card['foot'] ?? '') !== '') $body .= '<div class="gap"></div><p class="foot">' . $e($card['foot']) . '</p>';
    $body .= '<footer class="credit' . ($logo === 'top' ? ' full' : '') . '">'
           . ($logo === 'bottom' ? '<img src="' . $e($c['logo']) . '" alt="Mont Haus International Realty">' : '')
           . '<p>' . $e($card['credit'] ?? '') . '</p></footer>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($title . ' market snapshot') . '</title><style>' . $css . '</style></head><body>'
         . $bdHtml . $ghostHtml . ($logo === 'top' ? $logoHtml : '')
         . '<div class="card">' . $body . '</div>'
         . '</body></html>';
}
