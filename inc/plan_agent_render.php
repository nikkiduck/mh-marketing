<?php
/**
 * inc/plan_agent_render.php — the Featured Agent (Broker Feature) designs,
 * from a clean sheet (Nikki, 2026-11-02 evening: "scrap what you have now";
 * keep the face, shoulders to the top of the head, completely unobscured,
 * and the Slate colour scheme). Nothing is ever drawn over the photo: the
 * headshot sits in its own WINDOW on the ground colour, positioned from the
 * top (object-position 50% 0 by default, so the head is never cut), and the
 * badge, logo and words live around it. ONE style since 2026-11-02 (Nikki:
 * "get rid of the other options, cutout is the only one working right now";
 * Editorial, Arch, Card and Headline first are in git before that commit):
 *
 *   cutout     the broker CUT OUT of the photo, in colour, standing in front
 *              of Slate geometry: the real triangle mark ghosted large at the
 *              lower right, the hook above the figure with the name and the
 *              service area right-aligned under it (the slate band behind
 *              the shoulders went on 2026-11-05), badge and logo
 *              in the clear band at the top; the figure is anchored to the
 *              foot of the frame and never covered. Needs a cutout PNG
 *              (agent-photos/<slug>-cutout.png, made with Adobe's background
 *              removal; plan_agents() reports it as cutout_url); with an
 *              ordinary photo the style still draws, with the photo's own
 *              background. A BACKDROP ("a subtle image in the background
 *              with the slate overlay"): backdrop_url / backdrop_file, a
 *              stock location photo or an upload, under a veil of the ground
 *              colour at backdrop_veil percent (default 82), behind the
 *              ghost and everything else. The hook's sentences each take a
 *              line; the sentences after the first (the call: "Call Allison
 *              Byford.") are set by `hook2` (plan_hook_styles(): serif,
 *              italic, or sans in caps tracked .5em; Nikki, 2026-11-02).
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

/** The styles, in menu order (one since 2026-11-02; the builder hides the switch while there is one). */
function plan_agent_styles(): array {
    return ['cutout' => 'Cutout'];
}

/** How the hook's second and later sentences are set (`hook2`): the serif as the first, the serif italic, or the sans. */
function plan_hook_styles(): array {
    return ['serif' => 'Serif', 'italic' => 'Italic', 'sans' => 'Sans'];
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
    // The one style, cutout (2026-11-02).
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
}

/** The complete HTML document for one Featured Agent picture. */
function plan_render_agent(array $p, ?string $base = null, bool $export = false): string {
    $base   = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W      = SOCIAL_W;
    $style  = isset(plan_agent_styles()[(string)($p['style'] ?? '')]) ? (string)$p['style'] : 'cutout';
    $hook2  = isset(plan_hook_styles()[(string)($p['hook2'] ?? '')]) ? (string)$p['hook2'] : 'serif';
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
    $lHtml = '';
    foreach (array_slice($lines, 0, 3) as $ln) $lHtml .= '<p class="ln ln-' . $ln['style'] . '">' . $e($ln['text']) . '</p>';
    $pillHtml = $label !== '' ? '<div class="pill">' . $e($label) . '</div>' : '';

    // The figure: zoom scales it, focus_x moves it across, focus_y drops it (see below).
    $zoom = max(1.0, min(3.0, (float)($p['zoom'] ?? 1)));
    $fx   = max(0, min(100, (int)($p['focus_x'] ?? 50)));
    $fy   = max(0, min(100, (int)($p['focus_y'] ?? 0)));
    $photo = ($export && !empty($p['photo_file'])) ? 'file://' . $p['photo_file'] : (string)($p['photo_url'] ?? '');
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

    $html = $ghostHtml;
    // Zoom scales the figure (1 to 1.6 is useful); dragging across moves it (focus_x: 50 = the style's own place).
    $scale = max(0.6, min(2.0, $zoom));
    $figH2 = (int)round($g['figH'] * $scale);
    $figLeft2 = $g['figLeft'] + (int)round(($fx - 50) / 100 * 700);
    $figTop2 = $H - $figH2 + (int)round($fy / 100 * 300);   // dragging down drops the figure off the foot
    $hookW = $W - 128;
    // The backdrop: a location photo under a veil of the ground colour, behind everything (2026-11-02).
    $bd = ($export && !empty($p['backdrop_file'])) ? 'file://' . $p['backdrop_file'] : (string)($p['backdrop_url'] ?? '');
    $veil = max(0.3, min(0.97, ((int)($p['backdrop_veil'] ?? 82)) / 100));
    $veilRgb = $theme === 'light' ? '246,247,249' : '31,36,44';
    // The hook's sentences each on their own line with air between them (Nikki, 2026-11-02), unless the admin broke the lines.
    $sentences = count($hlines) > 1 ? $hlines : array_values(array_filter(array_map('trim', preg_split('/(?<=[.?!])\s+/u', $headline)), fn($x) => $x !== ''));
    $hHtml = '';
    foreach ($sentences as $i => $sn) $hHtml .= '<p class="hook' . ($i > 0 ? ' h2' : '') . '">' . $e($sn) . '</p>';
    // The second and later sentences (the call) in the serif italic or in the sans (Nikki, 2026-11-02); the serif matches the first.
    $h2Css = match ($hook2) {
        'italic' => '.words .hook.h2{font-style:italic;font-weight:400}',
        'sans'   => ".words .hook.h2{font-family:'Jost','Helvetica Neue',Arial,sans-serif;font-weight:500;font-size:" . (int)round($hsize * 0.52) . "px;line-height:1.3;letter-spacing:.5em;text-transform:uppercase;margin-right:-.5em}",   // caps, tracked .5em (Nikki)
        default  => '',
    };
    // The backdrop photo is half desaturated (Nikki, 2026-11-05: the blue was dominant; the veil was already #1F242C, the sky showing through it was the blue), like the website's photo bands.
    $css = ".bd{position:absolute;inset:0;z-index:0}.bd img{display:block;width:100%;height:100%;object-fit:cover;filter:saturate(.5)}.bd::after{content:'';position:absolute;inset:0;background:rgba({$veilRgb},{$veil})}"
         . ".ghost{z-index:1}"
         . ".band{position:absolute;left:64px;top:{$g['bandY']}px;z-index:4}"
         . ".logo{right:64px;top:{$g['bandY']}px;z-index:5}"
         . ".words{left:64px;width:{$hookW}px;top:{$g['hookTop']}px;z-index:4}"
         . ".words .hook{margin:0 0 {$sz(26)}px}" . $h2Css
         // The real Mont Haus triangle, ghosted, large at the lower right (the watermark choice picks the mark; None leaves the ground bare).
         . ".ghost{right:" . ($story ? -300 : ($sq ? -200 : -260)) . "px;bottom:0;width:" . ($story ? 1320 : ($sq ? 800 : 1100)) . "px;opacity:" . ($theme === 'light' ? '.16' : '.24') . "}"
         . ".ghost.m{right:0;width:" . ($story ? 900 : ($sq ? 560 : 760)) . "px}"
         // The name and the service area, right-aligned under the hook, on the ground itself (the slate band behind the
         // shoulders went on 2026-11-05, Nikki: "not loving that element"); the figure never reaches up there, at any size.
         . ".who{margin-top:{$sz(44)}px;text-align:right;color:#FFFFFF}"
         . ".who .ln{margin:0 0 {$sz(10)}px;color:#FFFFFF;white-space:nowrap;overflow:visible}"
         . ".who .ln-town{color:#FFFFFF;font-size:{$sz(34)}px;letter-spacing:.16em}"
         . ".who .ln-details{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$sz(46)}px;line-height:1.15;letter-spacing:0;color:#EEF1F5}"
         . ".who .ln-plain{font-size:{$sz(30)}px;color:#EEF1F5}.who .ln-note{color:#EEF1F5}"
         . ".fig{position:absolute;left:{$figLeft2}px;top:{$figTop2}px;height:{$figH2}px;z-index:3}"
         . ".fig img{display:block;height:100%;width:auto;filter:drop-shadow(0 24px 40px rgba(0,0,0,.35))}";
    $figHtml = $photo !== '' ? '<div class="fig"><img src="' . $e($photo) . '" alt=""></div>' : '';
    $html = ($bd !== '' ? '<div class="bd"><img src="' . $e($bd) . '" alt=""></div>' : '') . $html
           . '<div class="band">' . ($label !== '' ? $pillHtml : '') . '</div>'
           . ($logoOn ? '<img class="logo" src="' . $e($logoSrc) . '" alt="Mont Haus International Realty">' : '')
           . '<div class="words">' . $hHtml . '<div class="who">' . $lHtml . '</div></div>'
           . $figHtml;

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($hlines[0] ?? 'Featured Agent') . '</title><style>' . $common . $css . '</style></head><body>' . $html . '</body></html>';
}
