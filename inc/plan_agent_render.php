<?php
/**
 * inc/plan_agent_render.php — the Featured Agent (Broker Feature) designs,
 * from a clean sheet (Nikki, 2026-11-02 evening: "scrap what you have now";
 * keep the face, shoulders to the top of the head, completely unobscured,
 * and the Slate colour scheme). Nothing is ever drawn over the photo: the
 * headshot sits in its own WINDOW on the ground colour, positioned from the
 * top (object-position 50% 0 by default, so the head is never cut), and the
 * badge, logo and words live around it. Four styles, so Nikki can compare
 * them with real headshots in the tool and keep what works:
 *
 *   editorial  a band with the badge left and the logo right; the photo in
 *              an inset window with a hairline frame; the words below,
 *              left-aligned beside a vertical accent bar
 *   arch       the label as a small-caps eyebrow; the photo in a tall arch
 *              with an outlined stroke; the words centred under it; the
 *              logo centred at the foot
 *   card       the photo edge to edge in the upper part; a light card
 *              (dark on the light look) rises over the chest line with the
 *              badge, the hook and the name; the logo on the ground below
 *   headline   the hook first, large, at the top; the photo under it; a
 *              solid strip at the foot with the firm line and the logo
 *   cutout     (2026-11-02, late) the broker CUT OUT of the photo, in colour,
 *              standing in front of Slate geometry: two triangles after the
 *              brand mark at the lower right, a slanted slate band behind
 *              the shoulders carrying the name, the hook above the figure;
 *              the figure is anchored to the foot of the frame and never
 *              covered. Needs a cutout PNG (agent-photos/<slug>-cutout.png,
 *              made with Adobe's background removal; plan_agents() reports
 *              it as cutout_url); with an ordinary photo the style still
 *              draws, with the photo's own background.
 *
 * Shares social_palette(), social_font_css() and social_logo_options() with
 * the other renderers; the logo colour, size, nudge and shadow sliders
 * apply; the watermark (mark) is drawn on the ground, behind everything.
 * Standalone (no portal.php, no database). The post:
 *   style, format post|square|story, theme, mark, logo top|none, logo_kind,
 *   logo_* options, badge (left | right | body: body = the label as part of
 *   the words), label, photo_url / photo_file, zoom, focus_x, focus_y,
 *   headline (the hook; Enter = a new line), lines (text + style).
 */
require_once __DIR__ . '/social_render.php';

/** The styles, in menu order. */
function plan_agent_styles(): array {
    return ['cutout' => 'Cutout', 'editorial' => 'Editorial', 'arch' => 'Arch', 'card' => 'Card', 'headline' => 'Headline first'];
}

/**
 * The photo window per style and format: [left, top, width, height]. The
 * framing box on the page follows the window's aspect; the geometry below is
 * what the renderer draws, so the two agree.
 */
function plan_agent_window(string $style, string $format): array {
    $W = SOCIAL_W; $H = social_format_height($format); [$top, $bot] = social_format_insets($format);
    $g = plan_agent_geometry($style, $format);
    return [$g['wl'], $g['wt'], $g['ww'], $g['wh']];
}

/** Every number a style needs for a format. */
function plan_agent_geometry(string $style, string $format): array {
    $W = SOCIAL_W; $H = social_format_height($format); [$top, $bot] = social_format_insets($format);
    $sq = $format === 'square'; $story = $format === 'story';
    $m = 64;                                   // the side margin
    $logo = $sq ? 84 : 110;                    // the logo's height
    switch ($style) {
        case 'cutout':
            $bandY = $top + ($sq ? 48 : 56);
            $hookTop = $bandY + ($sq ? 60 : 70) + ($story ? 110 : ($sq ? 48 : 80));   // scooted down (Nikki, 2026-11-02)
            // The figure: its height, where its left edge sits, and the slate band behind the shoulders.
            $figH = $story ? 1180 : ($sq ? 640 : 900);
            $figLeft = $story ? -140 : ($sq ? -60 : -80);
            $bandTop = $story ? 1080 : ($sq ? 620 : 800);
            $bandH = $story ? 190 : ($sq ? 130 : 170);
            $bandLeft = $story ? 300 : ($sq ? 200 : 260);
            return ['bandY' => $bandY, 'hookTop' => $hookTop, 'figH' => $figH, 'figLeft' => $figLeft, 'figTop' => $H - $figH,
                    'sbTop' => $bandTop, 'sbH' => $bandH, 'sbLeft' => $bandLeft, 'logo' => $logo,
                    'wl' => 0, 'wt' => 0, 'ww' => $W, 'wh' => $H];   // the framing box shows the whole canvas
        case 'arch':
            $eyebrowY = $top + ($sq ? 48 : 72);
            $ww = $story ? 760 : ($sq ? 560 : 700);
            $wh = $story ? 800 : ($sq ? 470 : 690);   // shorter than first drawn: the words under it need the room (a three-line hook)
            $wt = $eyebrowY + ($sq ? 70 : 90);
            $logoY = $H - $bot - $m - $logo;    // the logo centred at the foot
            return ['wl' => (int)(($W - $ww) / 2), 'wt' => $wt, 'ww' => $ww, 'wh' => $wh, 'eyebrowY' => $eyebrowY,
                    'textTop' => $wt + $wh + ($sq ? 40 : 56), 'textBottom' => $logoY - ($sq ? 24 : 40), 'logo' => $logo, 'logoY' => $logoY];
        case 'card':
            $wt = $top + ($sq ? 32 : 40);
            $wh = (int)round($H * 0.62) - $wt;
            $overlap = $sq ? 90 : 120;
            $logoY = $H - $bot - $m - $logo;
            return ['wl' => 0, 'wt' => $wt, 'ww' => $W, 'wh' => $wh, 'cardTop' => $wt + $wh - $overlap, 'cardBottom' => $logoY - ($sq ? 28 : 40),
                    'logo' => $logo, 'logoY' => $logoY];
        case 'headline':
            $bandY = $top + ($sq ? 48 : 56);
            $strip = $sq ? 120 : 150;           // the solid strip at the foot
            return ['bandY' => $bandY, 'textTop' => $bandY + ($sq ? 60 : 70) + ($sq ? 28 : 36), 'strip' => $strip, 'stripTop' => $H - $bot - $strip,
                    'wl' => 0, 'ww' => $W, 'wt' => 0, 'wh' => 0, 'logo' => $sq ? 70 : 90];   // wt / wh depend on the words: set in the renderer
        default: // editorial
            $bandY = $top + ($sq ? 48 : 56);
            $wt = $bandY + $logo + ($sq ? 32 : 40);
            $wh = $story ? 960 : ($sq ? 500 : 720);
            return ['wl' => $m, 'wt' => $wt, 'ww' => $W - 2 * $m, 'wh' => $wh, 'bandY' => $bandY, 'logo' => $logo,
                    'textTop' => $wt + $wh + ($sq ? 40 : 56), 'textBottom' => $H - $bot - $m];
    }
}

/** The complete HTML document for one Featured Agent picture. */
function plan_render_agent(array $p, ?string $base = null, bool $export = false): string {
    $base   = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W      = SOCIAL_W;
    $style  = isset(plan_agent_styles()[(string)($p['style'] ?? '')]) ? (string)$p['style'] : 'editorial';
    $format = isset(social_formats()[(string)($p['format'] ?? '')]) ? (string)$p['format'] : 'story';
    $theme  = ($p['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $H      = social_format_height($format);
    [$top, $bot] = social_format_insets($format);
    $sq = $format === 'square'; $story = $format === 'story';
    $k  = $sq ? 0.84 : 1.0;
    $sz = fn(int $px) => (int)round($px * $k);
    $g  = plan_agent_geometry($style, $format);
    $c  = social_palette($theme, $base);
    $opt = social_logo_options($p);
    if ($opt['color'] !== 'auto') { $c['logo'] = $base . '/assets/images/MH_vert-stack_' . $opt['color'] . '.svg'; $c['mark'] = $base . '/assets/images/M-mark_' . $opt['color'] . '.svg'; }
    // The card and the strip take the OTHER tone, so they read against the ground.
    $alt = $theme === 'light'
        ? ['bg' => '#2B3038', 'text' => '#F4F5F7', 'accent' => '#AEBBCC', 'muted' => '#C2C8D1', 'soft' => '#DDE2E8']
        : ['bg' => '#F6F7F9', 'text' => '#1F242C', 'accent' => '#55637A', 'muted' => '#434B57', 'soft' => '#3A414C'];
    $fontCss = social_font_css($base, $export);
    $logoKind = ($p['logo_kind'] ?? 'full') === 'mark' ? 'mark' : 'full';
    $logoOn   = ($p['logo'] ?? 'top') !== 'none';
    $logoSrc  = $logoKind === 'mark' ? $c['mark'] : $c['logo'];
    $logoH    = (int)round($g['logo'] * ($logoKind === 'mark' ? 0.8 : 1) * $opt['scale']);
    $logoFx   = (($opt['dx'] || $opt['dy']) ? "transform:translate({$opt['dx']}px,{$opt['dy']}px);" : '')
              . ($opt['logo_shadow'] > 0 ? "filter:drop-shadow(" . social_shadow_css($opt['logo_shadow']) . ");" : '');
    $textShadow = $opt['text_shadow'] !== null ? social_shadow_css($opt['text_shadow']) : 'none';
    $mark  = isset(social_marks()[(string)($p['mark'] ?? '')]) ? (string)$p['mark'] : 'triangle';
    $label = trim((string)($p['label'] ?? ''));
    $badge = in_array((string)($p['badge'] ?? 'left'), ['left', 'right', 'body'], true) ? (string)$p['badge'] : 'left';

    // The words.
    $styles = social_line_styles();
    $lines = [];
    foreach ((array)($p['lines'] ?? []) as $ln) {
        $t = trim((string)($ln['text'] ?? ''));
        if ($t !== '') $lines[] = ['text' => $t, 'style' => isset($styles[$ln['style'] ?? '']) ? $ln['style'] : 'plain'];
    }
    $headline = trim(str_replace("\r", '', (string)($p['headline'] ?? '')));
    $hlines = array_values(array_filter(array_map('trim', preg_split('/\n/', $headline)), fn($x) => $x !== ''));
    $hchars = mb_strlen($headline);
    // The hook wraps on its own (text-wrap balance); its size comes from its length, three sizes per format.
    $hsize = match ($format) {
        'story'  => $hchars > 70 ? 56 : ($hchars > 44 ? 64 : 74),
        'square' => $hchars > 70 ? 40 : ($hchars > 44 ? 46 : 54),
        default  => $hchars > 70 ? 50 : ($hchars > 44 ? 58 : 66),
    };
    if ($style === 'arch') $hsize = (int)round($hsize * 0.86);   // centred under the arch, with the name and firm line still to fit
    $hHtml = $hlines ? '<h1 class="hook">' . implode('<br>', array_map($e, $hlines)) . '</h1>' : '';
    $lHtml = '';
    foreach (array_slice($lines, 0, 3) as $ln) $lHtml .= '<p class="ln ln-' . $ln['style'] . '">' . $e($ln['text']) . '</p>';
    $pillHtml = $label !== '' ? '<div class="pill">' . $e($label) . '</div>' : '';

    // The photo in its window: zoom x the window, cover, object-position = focus; the default focus (50, 0) keeps the head.
    $zoom = max(1.0, min(3.0, (float)($p['zoom'] ?? 1)));
    $fx   = max(0, min(100, (int)($p['focus_x'] ?? 50)));
    $fy   = max(0, min(100, (int)($p['focus_y'] ?? 0)));
    $zpct = round($zoom * 100, 2);
    $photo = ($export && !empty($p['photo_file'])) ? 'file://' . $p['photo_file'] : (string)($p['photo_url'] ?? '');
    $pic = fn(int $ww, int $wh) => $photo !== ''
        ? '<img class="pic" src="' . $e($photo) . '" alt="" style="width:' . $zpct . '%;height:' . $zpct . '%;object-position:' . $fx . '% ' . $fy . '%;transform:translate(' . (-round(($zoom - 1) * $fx / 100 * $ww, 1)) . 'px,' . (-round(($zoom - 1) * $fy / 100 * $wh, 1)) . 'px)">'
        : '<div class="none"><img src="' . $e($c['mark']) . '" alt=""></div>';
    $ghostHtml = match ($mark) {
        'mmark' => '<img class="ghost m" src="' . $e($base . ($theme === 'light' ? '/assets/images/M-mark_black.svg' : '/assets/images/M-mark_white.svg')) . '" alt="">',
        'none'  => '',
        default => '<img class="ghost" src="' . $e($base . ($theme === 'light' ? '/assets/images/triangle_mark-dark.svg' : '/assets/images/triangle_mark-white.svg')) . '" alt="">',
    };

    $common = <<<CSS
{$fontCss}
html,body{margin:0;padding:0}
body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['bg']};color:{$c['text']};font-family:'Jost','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased}
.ghost{position:absolute;right:-220px;bottom:0;width:970px;z-index:0;opacity:{$c['ghost']}}
.ghost.m{right:0;width:745px}
.win{position:absolute;overflow:hidden;background:#2D333D;z-index:1}
.win .pic{display:block;object-fit:cover}
.win .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}.win .none img{width:200px;opacity:.16}
.pill{display:inline-block;padding:{$sz(19)}px {$sz(30)}px {$sz(17)}px;background:{$c['pill']};color:{$c['pilltext']};font-size:{$sz(26)}px;font-weight:600;letter-spacing:.26em;text-transform:uppercase;line-height:1;white-space:nowrap;box-shadow:0 4px 18px rgba(0,0,0,.22)}
.logo{position:absolute;height:{$logoH}px;z-index:3;{$logoFx}}
.words{position:absolute;z-index:2;box-sizing:border-box;text-shadow:{$textShadow}}
.hook{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$hsize}px;line-height:1.08;margin:0 0 {$sz(22)}px;text-wrap:balance;overflow-wrap:anywhere}
.ln{margin:0 0 {$sz(10)}px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ln-town{font-size:{$sz(31)}px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:{$c['accent']}}
.ln-price{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$sz(50)}px;line-height:1.18}
.ln-details{font-size:{$sz(26)}px;font-weight:300;color:{$c['muted']};letter-spacing:.04em}
.ln-note{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-size:{$sz(31)}px;line-height:1.3;color:{$c['soft']}}
.ln-plain{font-size:{$sz(28)}px;font-weight:400}
CSS;

    $css = ''; $html = $ghostHtml;
    switch ($style) {
        case 'cutout':
            // Zoom scales the figure (1 to 1.6 is useful); dragging across moves it (focus_x: 50 = the style's own place).
            $scale = max(0.6, min(2.0, $zoom));
            $figH2 = (int)round($g['figH'] * $scale);
            $figLeft2 = $g['figLeft'] + (int)round(($fx - 50) / 100 * 700);
            $figTop2 = $H - $figH2 + (int)round($fy / 100 * 300);   // dragging down drops the figure off the foot
            $hookW = $W - 128;
            // The hook's sentences each on their own line with air between them (Nikki, 2026-11-02), unless the admin broke the lines.
            $sentences = count($hlines) > 1 ? $hlines : array_values(array_filter(array_map('trim', preg_split('/(?<=[.?!])\s+/u', $headline)), fn($x) => $x !== ''));
            $hHtml = '';
            foreach ($sentences as $sn) $hHtml .= '<p class="hook">' . $e($sn) . '</p>';
            $css = ".band{position:absolute;left:64px;top:{$g['bandY']}px;z-index:4}"
                 . ".logo{right:64px;top:{$g['bandY']}px;z-index:5}"
                 . ".words{left:64px;width:{$hookW}px;top:{$g['hookTop']}px;z-index:4}"
                 . ".words .hook{margin:0 0 {$sz(26)}px}"
                 // The real Mont Haus triangle, ghosted, large at the lower right (the watermark choice picks the mark; None leaves the ground bare).
                 . ".ghost{right:" . ($story ? -300 : ($sq ? -200 : -260)) . "px;bottom:0;width:" . ($story ? 1320 : ($sq ? 800 : 1100)) . "px;opacity:" . ($theme === 'light' ? '.16' : '.24') . "}"
                 . ".ghost.m{right:0;width:" . ($story ? 900 : ($sq ? 560 : 760)) . "px}"
                 // The slate band behind the shoulders, slanted at its left end, carrying the name and firm line right-aligned.
                 . ".sband{position:absolute;left:{$g['sbLeft']}px;right:0;top:{$g['sbTop']}px;height:{$g['sbH']}px;background:#55637A;z-index:2;clip-path:polygon(" . ($sq ? 60 : 90) . "px 0,100% 0,100% 100%,0 100%);"
                 . "display:flex;flex-direction:column;justify-content:center;align-items:flex-end;padding:0 64px 0 140px;box-sizing:border-box;color:#FFFFFF;text-align:right}"
                 . ".sband .ln{margin:0 0 8px;color:#FFFFFF}.sband .ln-town{color:#FFFFFF;font-size:{$sz(32)}px}.sband .ln-details{color:#EEF1F5;font-size:{$sz(30)}px;font-weight:400}.sband .ln-plain{font-size:{$sz(30)}px;color:#EEF1F5}"
                 . ".fig{position:absolute;left:{$figLeft2}px;top:{$figTop2}px;height:{$figH2}px;z-index:3}"
                 . ".fig img{display:block;height:100%;width:auto;filter:drop-shadow(0 24px 40px rgba(0,0,0,.35))}";
            $figHtml = $photo !== '' ? '<div class="fig"><img src="' . $e($photo) . '" alt=""></div>' : '';
            $html .= '<div class="band">' . ($label !== '' ? $pillHtml : '') . '</div>'
                   . ($logoOn ? '<img class="logo" src="' . $e($logoSrc) . '" alt="Mont Haus International Realty">' : '')
                   . '<div class="words">' . $hHtml . '</div>'
                   . '<div class="sband">' . $lHtml . '</div>'
                   . $figHtml;
            break;
        case 'arch':
            $r = (int)($g['ww'] / 2);
            $textLeft = 96; $textWidth = $W - 192;
            $css = ".eyebrow{position:absolute;left:0;right:0;top:{$g['eyebrowY']}px;text-align:center;font-size:{$sz(28)}px;font-weight:600;letter-spacing:.3em;text-transform:uppercase;color:{$c['accent']};z-index:2}"
                 . ".arch{position:absolute;left:{$g['wl']}px;top:{$g['wt']}px;width:{$g['ww']}px;height:{$g['wh']}px;border-radius:{$r}px {$r}px 0 0;box-sizing:border-box;padding:16px;border:3px solid {$c['accent']};z-index:1}"
                 . ".arch .win{position:absolute;left:16px;top:16px;right:16px;bottom:16px;border-radius:" . ($r - 16) . "px " . ($r - 16) . "px 0 0;background:#2D333D}"
                 . ".words{left:{$textLeft}px;width:{$textWidth}px;top:{$g['textTop']}px;height:" . ($g['textBottom'] - $g['textTop']) . "px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;text-align:center;overflow:hidden}"
                 . ".words .pill{margin:0 0 {$sz(24)}px}.rule{width:72px;height:3px;background:{$c['accent']};margin:0 0 {$sz(22)}px}"
                 . ".logo{left:50%;top:{$g['logoY']}px;transform:translateX(-50%);" . (($opt['dx'] || $opt['dy']) ? "transform:translate(calc(-50% + {$opt['dx']}px),{$opt['dy']}px);" : '') . "}";
            $html .= ($label !== '' && $badge !== 'body' ? '<div class="eyebrow">' . $e($label) . '</div>' : '')
                   . '<div class="arch"><div class="win">' . $pic($g['ww'] - 32, $g['wh'] - 32) . '</div></div>'
                   . '<div class="words">' . ($label !== '' && $badge === 'body' ? $pillHtml : '') . '<div class="rule"></div>' . $hHtml . $lHtml . '</div>'
                   . ($logoOn ? '<img class="logo" src="' . $e($logoSrc) . '" alt="Mont Haus International Realty">' : '');
            break;
        case 'card':
            $cardH = $g['cardBottom'] - $g['cardTop'];
            $css = ".win{left:0;top:{$g['wt']}px;width:{$W}px;height:{$g['wh']}px}"
                 . ".card{position:absolute;left:64px;right:64px;top:{$g['cardTop']}px;height:{$cardH}px;background:{$alt['bg']};color:{$alt['text']};z-index:2;box-sizing:border-box;padding:{$sz(48)}px {$sz(56)}px;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.28)}"
                 . ".card .pill{margin:0 0 {$sz(26)}px}.card .ln-town{color:{$alt['accent']}}.card .ln-details{color:{$alt['muted']}}.card .ln-note{color:{$alt['soft']}}.card .rule{width:72px;height:3px;background:{$alt['accent']};margin:0 0 {$sz(22)}px}"
                 . ".logo{right:64px;top:{$g['logoY']}px}";
            $html .= '<div class="win">' . $pic($W, $g['wh']) . '</div>'
                   . '<div class="card">' . ($label !== '' ? $pillHtml : '<div class="rule"></div>') . $hHtml . $lHtml . '</div>'
                   . ($logoOn ? '<img class="logo" src="' . $e($logoSrc) . '" alt="Mont Haus International Realty">' : '');
            break;
        case 'headline':
            // The words first; the window takes what is left down to the strip.
            $estLines = max(1, (int)ceil($hchars * $hsize * 0.46 / ($W - 128)));
            $wordsH = (int)round($estLines * $hsize * 1.08) + $sz(22) + (count($lines) ? $sz(31) * 1.3 + $sz(10) : 0);
            $wt = (int)($g['textTop'] + $wordsH + $sz(48));
            $wh = $g['stripTop'] - $wt;
            $firm = count($lines) > 1 ? $lines[1]['text'] : 'Mont Haus International Realty';
            if ($opt['color'] === 'auto') $logoSrc = $base . '/assets/images/' . ($logoKind === 'mark' ? 'M-mark_' : 'MH_vert-stack_') . ($theme === 'light' ? 'white' : 'black') . '.svg';   // on the strip, the strip's tone
            $css = ".band{position:absolute;left:64px;top:{$g['bandY']}px;z-index:2}"
                 . ".words{left:64px;right:64px;top:{$g['textTop']}px}"
                 . ".win{left:0;top:{$wt}px;width:{$W}px;height:{$wh}px}"
                 . ".strip{position:absolute;left:0;right:0;top:{$g['stripTop']}px;height:{$g['strip']}px;background:{$alt['bg']};color:{$alt['text']};z-index:2;display:flex;align-items:center;padding:0 64px;box-sizing:border-box;font-size:{$sz(24)}px;font-weight:500;letter-spacing:.14em;text-transform:uppercase}"
                 . ".strip span{color:{$alt['accent']}}"
                 . ".logo{right:64px;top:" . ($g['stripTop'] + (int)(($g['strip'] - $logoH) / 2)) . "px}";
            $firstLine = count($lines) ? '<p class="ln ln-' . $lines[0]['style'] . '">' . $e($lines[0]['text']) . '</p>' : '';
            $html .= '<div class="band">' . ($label !== '' ? $pillHtml : '') . '</div>'
                   . '<div class="words">' . $hHtml . $firstLine . '</div>'
                   . '<div class="win">' . $pic($W, max(200, $wh)) . '</div>'
                   . '<div class="strip"><span>' . $e($firm) . '</span></div>'
                   . ($logoOn ? '<img class="logo" src="' . $e($logoSrc) . '" alt="Mont Haus International Realty">' : '');
            break;
        default: // editorial
            $css = ".band{position:absolute;left:64px;right:64px;top:{$g['bandY']}px;height:{$g['logo']}px;display:flex;align-items:center;justify-content:space-between;z-index:2}"
                 . ".band .pill{align-self:flex-start}"
                 . ".frame{position:absolute;left:" . ($g['wl'] - 14) . "px;top:" . ($g['wt'] - 14) . "px;width:" . ($g['ww'] + 28) . "px;height:" . ($g['wh'] + 28) . "px;border:1px solid {$c['accent']};opacity:.55;box-sizing:border-box;z-index:1}"
                 . ".win{left:{$g['wl']}px;top:{$g['wt']}px;width:{$g['ww']}px;height:{$g['wh']}px}"
                 . ".words{left:64px;right:64px;top:{$g['textTop']}px;height:" . ($g['textBottom'] - $g['textTop']) . "px;padding-left:{$sz(30)}px;border-left:4px solid {$c['accent']};overflow:hidden}"
                 . ".words .pill{margin:0 0 {$sz(22)}px}"
                 . ".logo{position:static;height:{$logoH}px}";
            $html .= '<div class="band">' . ($label !== '' && $badge !== 'body' ? $pillHtml : '<span></span>') . ($logoOn ? '<img class="logo" src="' . $e($logoSrc) . '" alt="Mont Haus International Realty">' : '') . '</div>'
                   . '<div class="frame"></div><div class="win">' . $pic($g['ww'], $g['wh']) . '</div>'
                   . '<div class="words">' . ($label !== '' && $badge === 'body' ? $pillHtml : '') . $hHtml . $lHtml . '</div>';
            break;
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($hlines[0] ?? 'Featured Agent') . '</title><style>' . $common . $css . '</style></head><body>' . $html . '</body></html>';
}
