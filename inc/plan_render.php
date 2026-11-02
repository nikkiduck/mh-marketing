<?php
/**
 * inc/plan_render.php — the FRAMED layout the Social Media Plan templates add
 * (2026-11-02, redrawn the same day to Nikki's notes): the ground colour
 * fills the canvas; a band at the top carries the label badge and the logo,
 * clear of Instagram's chrome; the photo sits in a full-width window UNDER
 * the band, so a headshot's face lands mid-frame instead of under the badge
 * or behind the story's top controls; the photo fades into the ground at
 * its foot; the words follow at once (the block is only as tall as the
 * words), and, for a Location Feature's "team in place", a row of the
 * brokers' faces sits above the headline. The first version put the photo
 * at the very top and the words in a fixed block at the bottom, which cut
 * the face and left a hole under the text (Nikki: "does not currently
 * work"). Everything full-photo or centred is still drawn by social_render()
 * (inc/social_render.php), whose palette (social_palette()), fonts
 * (social_font_css()) and logo options (social_logo_options()) this shares.
 *
 * Standalone on purpose (no portal.php, no database); it needs only
 * inc/social_render.php. tests/render_plan_sample.php renders sample data.
 *
 * The normalised post (plan_post_from_request() in inc/plan.php):
 *   format post|square|story, theme dark|light, mark triangle|mmark|none,
 *   logo top|none (logo_kind full|mark; logo_color, logo_scale, logo_dx,
 *   logo_dy, logo_shadow, text_shadow as social_logo_options()), badge
 *   left|right|body, label, photo_url / photo_file, zoom, focus_x, focus_y,
 *   headline (Enter = a new line), lines (text + style, as the Instagram
 *   posts), team ([name, first, url, file]).
 *
 * Geometry (band / photo window / words), top to bottom:
 *   story  1920: band 190+56 .. 430, photo 430 .. 1290 (860), words to 1750
 *   post   1440: band 56 .. 240,     photo 240 .. 1020 (780), words to 1440
 *   square 1080: band 48 .. 200,     photo 200 .. 720 (520),  words to 1080
 * The words' room: 460 / 420 / 360 less padding; a team row takes about 180,
 * the rule 25, a one-line headline about 80, so with a team one or two lines
 * fit and without one three or four; plan_render_split() trims to that.
 */
require_once __DIR__ . '/social_render.php';

/**
 * A photo frame of a property set (2026-11-02): the photo alone, framed by
 * zoom and focus as everywhere, and, when `show_logo`, the Mont Haus logo
 * lower right (the stack or the M mark, in the look's colour or the colour
 * forced by logo_color, sized by logo_scale, nudged by logo_dx / logo_dy,
 * with logo_shadow), clear of a story's bottom safe area. No words, no
 * fade, no watermark: Nikki asked for "just the photo", the logo on every
 * other one. $export = true reads the photo from disk and embeds nothing
 * (there is no type on the frame).
 */
function plan_render_photo_frame(array $p, ?string $base = null, bool $export = false): string {
    $base   = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W      = SOCIAL_W;
    $format = isset(social_formats()[(string)($p['format'] ?? '')]) ? (string)$p['format'] : 'post';
    $theme  = ($p['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $H      = social_format_height($format);
    [, $bot] = social_format_insets($format);
    $c      = social_palette($theme, $base);
    $opt    = social_logo_options($p);
    if ($opt['color'] !== 'auto') { $c['logo'] = $base . '/assets/images/MH_vert-stack_' . $opt['color'] . '.svg'; $c['mark'] = $base . '/assets/images/M-mark_' . $opt['color'] . '.svg'; }
    $logoKind = ($p['logo_kind'] ?? 'full') === 'mark' ? 'mark' : 'full';
    $logoH  = (int)round(($logoKind === 'mark' ? 118 : 152) * $opt['scale']);

    $zoom = max(1.0, min(3.0, (float)($p['zoom'] ?? 1)));
    $fx   = max(0, min(100, (int)($p['focus_x'] ?? 50)));
    $fy   = max(0, min(100, (int)($p['focus_y'] ?? 50)));
    $tx   = -round(($zoom - 1) * $fx / 100 * $W, 1);
    $ty   = -round(($zoom - 1) * $fy / 100 * $H, 1);
    $zpct = round($zoom * 100, 2);
    $photo = ($export && !empty($p['photo_file'])) ? 'file://' . $p['photo_file'] : (string)($p['photo_url'] ?? '');

    $logoCss = ".logo{position:absolute;right:64px;bottom:" . (64 + $bot) . "px;height:{$logoH}px;z-index:2"
             . (($opt['dx'] || $opt['dy']) ? ";transform:translate({$opt['dx']}px,{$opt['dy']}px)" : '')
             . ($opt['logo_shadow'] > 0 ? ";filter:drop-shadow(" . social_shadow_css($opt['logo_shadow']) . ")" : '') . "}";
    $css = "html,body{margin:0;padding:0}body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['bg']}}"
         . ".photo{position:absolute;inset:0;overflow:hidden;background:#2D333D}"
         . ".photo img.pic{display:block;width:{$zpct}%;height:{$zpct}%;object-fit:cover;object-position:{$fx}% {$fy}%;transform:translate({$tx}px,{$ty}px)}"
         . ".photo .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}.photo .none img{width:260px;opacity:.16}"
         . $logoCss;
    $photoHtml = $photo !== '' ? '<img class="pic" src="' . $e($photo) . '" alt="">' : '<div class="none"><img src="' . $e($c['mark']) . '" alt=""></div>';
    $logoHtml  = !empty($p['show_logo']) ? '<img class="logo" src="' . $e($logoKind === 'mark' ? $c['mark'] : $c['logo']) . '" alt="Mont Haus International Realty">' : '';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Photo</title><style>' . $css . '</style></head><body>'
         . '<div class="photo">' . $photoHtml . '</div>' . $logoHtml . '</body></html>';
}

/** [band top (where the badge and logo sit), photo top, photo bottom, logo height] per format. */
function plan_split_geometry(string $format): array {
    return match ($format) {
        'story'  => ['band' => 190 + 56, 'photo_top' => 430, 'photo_bottom' => 1290, 'logo' => 130, 'face' => 120],
        'square' => ['band' => 48,       'photo_top' => 200, 'photo_bottom' => 720,  'logo' => 100, 'face' => 92],
        default  => ['band' => 56,       'photo_top' => 240, 'photo_bottom' => 1020, 'logo' => 130, 'face' => 110],
    };
}

/** The photo window's height in the framed layout, per format (the framing box on the page follows it). */
function plan_split_photo_height(string $format): int {
    $g = plan_split_geometry($format);
    return $g['photo_bottom'] - $g['photo_top'];
}

/** The complete HTML document for one framed picture. $export = true reads the photo and the faces from disk and embeds the fonts. */
function plan_render_split(array $p, ?string $base = null, bool $export = false): string {
    $base   = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W      = SOCIAL_W;
    $format = isset(social_formats()[(string)($p['format'] ?? '')]) ? (string)$p['format'] : 'post';
    $theme  = ($p['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $H      = social_format_height($format);
    [$top, $bot] = social_format_insets($format);
    $g      = plan_split_geometry($format);
    $photoTop = $g['photo_top']; $photoH = $g['photo_bottom'] - $g['photo_top'];
    $blockTop = $g['photo_bottom']; $blockH = $H - $blockTop;
    $c      = social_palette($theme, $base);
    $opt    = social_logo_options($p);
    if ($opt['color'] !== 'auto') { $c['logo'] = $base . '/assets/images/MH_vert-stack_' . $opt['color'] . '.svg'; $c['mark'] = $base . '/assets/images/M-mark_' . $opt['color'] . '.svg'; }
    $textShadow = $opt['text_shadow'] !== null ? social_shadow_css($opt['text_shadow']) : 'none';   // words on the ground colour: none unless asked
    $fontCss = social_font_css($base, $export);
    $square = $format === 'square';
    $k  = $square ? 0.86 : 1.0;             // the square runs a size smaller throughout
    $sz = fn(int $px) => (int)round($px * $k);

    $logo     = ($p['logo'] ?? 'top') === 'none' ? 'none' : 'top';
    $logoKind = ($p['logo_kind'] ?? 'full') === 'mark' ? 'mark' : 'full';
    $badge    = in_array((string)($p['badge'] ?? 'left'), ['left', 'right', 'body'], true) ? (string)$p['badge'] : 'left';
    $label    = trim((string)($p['label'] ?? ''));
    $mark     = isset(social_marks()[(string)($p['mark'] ?? '')]) ? (string)$p['mark'] : 'triangle';
    $logoH    = (int)round($g['logo'] * ($logoKind === 'mark' ? 0.8 : 1) * $opt['scale']);

    // The photo frame, as social_render(): zoom x the window, cover, focus, the box pulled by (zoom - 1) x focus.
    $zoom = max(1.0, min(3.0, (float)($p['zoom'] ?? 1)));
    $fx   = max(0, min(100, (int)($p['focus_x'] ?? 50)));
    $fy   = max(0, min(100, (int)($p['focus_y'] ?? 50)));
    $tx   = -round(($zoom - 1) * $fx / 100 * $W, 1);
    $ty   = -round(($zoom - 1) * $fy / 100 * $photoH, 1);
    $zpct = round($zoom * 100, 2);
    $photo = ($export && !empty($p['photo_file'])) ? 'file://' . $p['photo_file'] : (string)($p['photo_url'] ?? '');

    // The team row: at most six faces, each a circle with the first name under it.
    $team = array_slice((array)($p['team'] ?? []), 0, 6);
    $face = $g['face'];
    $hasTeam = count($team) > 0;

    // The words that fit: the headline, then as many lines as the block has room for (blank ones dropped first).
    $styles = social_line_styles();
    $lines = [];
    foreach ((array)($p['lines'] ?? []) as $ln) {
        $t = trim((string)($ln['text'] ?? ''));
        if ($t === '') continue;
        $lines[] = ['text' => $t, 'style' => isset($styles[$ln['style'] ?? '']) ? $ln['style'] : 'plain'];
    }
    $headline = trim(str_replace("\r", '', (string)($p['headline'] ?? '')));
    $hlines = array_values(array_filter(array_map('trim', preg_split('/\n/', $headline)), fn($x) => $x !== ''));
    $longest = array_reduce($hlines, fn($m, $x) => mb_strlen($x) > mb_strlen($m) ? $x : $m, '');
    $hsize = match ($format) {
        'story'  => social_fit_size($longest, 72, [18 => 64, 24 => 54, 32 => 46, 42 => 40]),
        'square' => social_fit_size($longest, 54, [18 => 48, 24 => 42, 32 => 36, 42 => 32]),
        default  => social_fit_size($longest, 66, [18 => 58, 24 => 50, 32 => 44, 42 => 38]),
    };
    if (count($hlines) > 2) $hsize = (int)round($hsize * 0.86);   // three lines of headline: a step smaller
    $headRows = max(1, count($hlines));
    $max = $hasTeam ? ($headRows > 1 ? 1 : 2) : ($square ? 3 : 4);
    if ($badge === 'body') $max = max(1, $max - 1);
    $lines = array_slice($lines, 0, $max);

    // The band: the badge at one side, the logo at the other (the logo gives way when the badge wants its corner).
    $logoSide = $badge === 'right' ? 'left' : 'right';
    $bandY = $g['band'];
    $pillCss = $badge === 'right' ? ".pill{left:auto;right:64px}" : '';
    $logoCss = ".logo{position:absolute;top:{$bandY}px;{$logoSide}:64px;height:{$logoH}px;z-index:3"
             . (($opt['dx'] || $opt['dy']) ? ";transform:translate({$opt['dx']}px,{$opt['dy']}px)" : '')
             . ($opt['logo_shadow'] > 0 ? ";filter:drop-shadow(" . social_shadow_css($opt['logo_shadow']) . ")" : '') . "}";

    $css = <<<CSS
{$fontCss}
html,body{margin:0;padding:0}
body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['bg']};color:{$c['text']};
     font-family:'Jost','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased}
.photo{position:absolute;left:0;top:{$photoTop}px;width:{$W}px;height:{$photoH}px;overflow:hidden;background:#2D333D;z-index:1}
.photo img.pic{display:block;width:{$zpct}%;height:{$zpct}%;object-fit:cover;object-position:{$fx}% {$fy}%;transform:translate({$tx}px,{$ty}px)}
.photo .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:{$c['bg']}}
.photo .none img{width:260px;opacity:.16}
.photo::after{content:'';position:absolute;left:0;right:0;bottom:0;height:150px;background:linear-gradient(to bottom,rgba(0,0,0,0),{$c['bg']})}
.pill{position:absolute;left:64px;top:{$bandY}px;z-index:3;padding:21px 32px 19px;background:{$c['pill']};color:{$c['pilltext']};
     font-size:27px;font-weight:600;letter-spacing:.26em;text-transform:uppercase;line-height:1;white-space:nowrap;box-shadow:0 4px 18px rgba(0,0,0,.22)}
.pill.inbody{position:static;display:inline-block;margin:0 0 {$sz(22)}px;box-shadow:none;align-self:flex-start}
{$pillCss}
{$logoCss}
.ghost{position:absolute;right:-220px;bottom:0;width:970px;z-index:0;opacity:{$c['ghost']}}
.ghost.m{right:0;width:745px}
.block{position:absolute;left:0;top:{$blockTop}px;width:{$W}px;height:{$blockH}px;box-sizing:border-box;padding:{$sz(36)}px 64px {$e(56 + $bot)}px;z-index:2;
       display:flex;flex-direction:column;justify-content:flex-start;overflow:hidden;text-shadow:{$textShadow}}
.team{display:flex;gap:{$sz(16)}px;margin:0 0 {$sz(24)}px;align-items:flex-start}
.face{display:flex;flex-direction:column;align-items:center;width:{$face}px;flex:none}
.face img{display:block;width:{$face}px;height:{$face}px;border-radius:50%;object-fit:cover;object-position:50% 30%;background:#2D333D;border:3px solid {$c['accent']};box-sizing:border-box}
.face span{display:block;margin-top:10px;font-size:{$sz(22)}px;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:{$c['accent']};white-space:nowrap;line-height:1.2;max-width:{$face}px;overflow:hidden;text-overflow:ellipsis}
.rule{width:72px;height:3px;background:{$c['accent']};margin:0 0 {$sz(22)}px;flex:none}
.addr{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$hsize}px;line-height:1.1;margin:0 0 {$sz(20)}px;overflow-wrap:anywhere;text-wrap:balance}
.ln{margin:0 0 {$sz(10)}px;white-space:nowrap;overflow:hidden;line-height:1.25}
.ln-town{font-size:{$sz(31)}px;font-weight:400;letter-spacing:.08em;text-transform:uppercase;color:{$c['accent']};margin-bottom:{$sz(10)}px}
.ln-price{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:{$sz(54)}px;line-height:1.18;margin-bottom:{$sz(8)}px}
.ln-details{font-size:{$sz(26)}px;font-weight:300;color:{$c['muted']};letter-spacing:.03em}
.ln-note{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-size:{$sz(31)}px;line-height:1.3;color:{$c['soft']}}
.ln-plain{font-size:{$sz(30)}px;font-weight:400;color:{$c['text']}}
CSS;

    $photoHtml = $photo !== ''
        ? '<img class="pic" src="' . $e($photo) . '" alt="">'
        : '<div class="none"><img src="' . $e($c['mark']) . '" alt=""></div>';
    $pill  = ($label !== '' && $badge !== 'body') ? '<div class="pill">' . $e($label) . '</div>' : '';
    $logoSrc = $logoKind === 'mark' ? $c['mark'] : $c['logo'];
    $logoHtml = $logo === 'top' ? '<img class="logo" src="' . $e($logoSrc) . '" alt="Mont Haus International Realty">' : '';
    $ghostHtml = match ($mark) {
        'mmark' => '<img class="ghost m" src="' . $e($base . ($theme === 'light' ? '/assets/images/M-mark_black.svg' : '/assets/images/M-mark_white.svg')) . '" alt="">',
        'none'  => '',
        default => '<img class="ghost" src="' . $e($base . ($theme === 'light' ? '/assets/images/triangle_mark-dark.svg' : '/assets/images/triangle_mark-white.svg')) . '" alt="">',
    };

    $block = '';
    if ($hasTeam) {
        $block .= '<div class="team">';
        foreach ($team as $m) {
            $src = ($export && !empty($m['file'])) ? 'file://' . $m['file'] : (string)($m['url'] ?? '');
            $block .= '<div class="face"><img src="' . $e($src) . '" alt=""><span>' . $e($m['first'] ?? $m['name'] ?? '') . '</span></div>';
        }
        $block .= '</div>';
    }
    if ($label !== '' && $badge === 'body') $block .= '<div class="pill inbody">' . $e($label) . '</div>';
    $block .= '<div class="rule"></div>';
    if ($hlines) $block .= '<h1 class="addr">' . str_replace(' #', '&nbsp;#', implode('<br>', array_map($e, $hlines))) . '</h1>';
    foreach ($lines as $ln) $block .= '<p class="ln ln-' . $ln['style'] . '">' . $e($ln['text']) . '</p>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($headline !== '' ? $hlines[0] : 'Post') . '</title><style>' . $css . '</style></head><body>'
         . $ghostHtml . '<div class="photo">' . $photoHtml . '</div>' . $pill . $logoHtml
         . '<div class="block">' . $block . '</div>'
         . '</body></html>';
}
