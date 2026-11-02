<?php
/**
 * inc/plan_render.php — the split layout the Social Media Plan templates add
 * (2026-11-02): the photo on top, the words on the ground colour below it,
 * and, for a Location Feature's "team in place", a row of the brokers' faces
 * above the headline. The broker feature uses it for a headshot, which a
 * full-photo fade would crop badly; everything full-photo or centred is still
 * drawn by social_render() (inc/social_render.php), whose palette
 * (social_palette()) and fonts (social_font_css()) this shares, so a change
 * to the look lands in both.
 *
 * Standalone on purpose (no portal.php, no database); it needs only
 * inc/social_render.php. tests/render_plan_sample.php renders sample data.
 *
 * The normalised post (plan_post_from_request() in inc/plan.php):
 *   format post|square|story, theme dark|light, mark triangle|mmark|none,
 *   logo top|none (the stack or the M mark: logo_kind full|mark, upper right
 *   over the photo), badge left|right, label, photo_url / photo_file, zoom,
 *   focus_x, focus_y, headline (Enter = a new line), lines (text + style, as
 *   the Instagram posts), team ([name, first, url, file]).
 *
 * Sizes: the photo takes 900 of a post's 1440, 640 of a square's 1080 and
 * 1230 of a story's 1920; the block has the rest, less the story's 170 px
 * safe area at the bottom. The block's budget on a post is 420 px: a team row
 * takes about 200, the rule 25, a one-line headline about 75, so with a team
 * two lines fit and without one four do; plan_render_split() trims the lines
 * to what fits rather than letting them run off the frame.
 */
require_once __DIR__ . '/social_render.php';

/** The photo's height in the split layout, per format. */
function plan_split_photo_height(string $format): int {
    return match ($format) { 'story' => 1230, 'square' => 640, default => 900 };
}

/** The complete HTML document for one split-layout picture. $export = true reads the photo and the faces from disk and embeds the fonts. */
function plan_render_split(array $p, ?string $base = null, bool $export = false): string {
    $base   = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W      = SOCIAL_W;
    $format = isset(social_formats()[(string)($p['format'] ?? '')]) ? (string)$p['format'] : 'post';
    $theme  = ($p['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $H      = social_format_height($format);
    [$top, $bot] = social_format_insets($format);
    $photoH = plan_split_photo_height($format);
    $blockH = $H - $photoH;
    $c      = social_palette($theme, $base);
    $fontCss = social_font_css($base, $export);
    $square = $format === 'square';
    $k  = $square ? 0.86 : 1.0;             // the square runs a size smaller throughout
    $sz = fn(int $px) => round($px * $k);

    $logo     = ($p['logo'] ?? 'top') === 'none' ? 'none' : 'top';
    $logoKind = ($p['logo_kind'] ?? 'full') === 'mark' ? 'mark' : 'full';
    $badge    = ($p['badge'] ?? 'left') === 'right' ? 'right' : 'left';
    $label    = trim((string)($p['label'] ?? ''));
    $mark     = isset(social_marks()[(string)($p['mark'] ?? '')]) ? (string)$p['mark'] : 'triangle';

    // The photo frame, as social_render(): zoom x the frame, cover, focus, the box pulled by (zoom - 1) x focus.
    $zoom = max(1.0, min(3.0, (float)($p['zoom'] ?? 1)));
    $fx   = max(0, min(100, (int)($p['focus_x'] ?? 50)));
    $fy   = max(0, min(100, (int)($p['focus_y'] ?? 50)));
    $tx   = -round(($zoom - 1) * $fx / 100 * $W, 1);
    $ty   = -round(($zoom - 1) * $fy / 100 * $photoH, 1);
    $zpct = round($zoom * 100, 2);
    $photo = ($export && !empty($p['photo_file'])) ? 'file://' . $p['photo_file'] : (string)($p['photo_url'] ?? '');

    // The team row: at most six faces, each a circle with the first name under it.
    $team = array_slice((array)($p['team'] ?? []), 0, 6);
    $face = $square ? 104 : 140;
    $hasTeam = count($team) > 0;

    // The words that fit: the headline, then as many lines as the block has room for (blank ones dropped first).
    $styles = social_line_styles();
    $lines = [];
    foreach ((array)($p['lines'] ?? []) as $ln) {
        $t = trim((string)($ln['text'] ?? ''));
        if ($t === '') continue;
        $lines[] = ['text' => $t, 'style' => isset($styles[$ln['style'] ?? '']) ? $ln['style'] : 'plain'];
    }
    $max = $hasTeam ? 2 : ($square ? 3 : 4);
    $lines = array_slice($lines, 0, $max);

    $headline = trim(str_replace("\r", '', (string)($p['headline'] ?? '')));
    $hlines = array_values(array_filter(array_map('trim', preg_split('/\n/', $headline)), fn($x) => $x !== ''));
    $longest = array_reduce($hlines, fn($m, $x) => mb_strlen($x) > mb_strlen($m) ? $x : $m, '');
    $hsize = match ($format) {
        'story'  => social_fit_size($longest, 72, [18 => 64, 24 => 54, 32 => 46, 42 => 40]),
        'square' => social_fit_size($longest, 54, [18 => 48, 24 => 42, 32 => 36, 42 => 32]),
        default  => social_fit_size($longest, 66, [18 => 58, 24 => 50, 32 => 44, 42 => 38]),
    };
    if (count($hlines) > 2) $hsize = (int)round($hsize * 0.86);   // three lines of headline: a step smaller

    $logoH = $logoKind === 'mark' ? 118 : 152;
    $pillTop = 64 + $top;
    $pillCss = $badge === 'right'
        ? ".pill{left:auto;right:64px" . ($logo === 'top' ? ";top:" . ($pillTop + $logoH + 28) . "px" : '') . "}"
        : '';

    $css = <<<CSS
{$fontCss}
html,body{margin:0;padding:0}
body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:{$c['bg']};color:{$c['text']};
     font-family:'Jost','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased}
.photo{position:absolute;left:0;top:0;width:{$W}px;height:{$photoH}px;overflow:hidden;background:#2D333D}
.photo img.pic{display:block;width:{$zpct}%;height:{$zpct}%;object-fit:cover;object-position:{$fx}% {$fy}%;transform:translate({$tx}px,{$ty}px)}
.photo .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:{$c['bg']}}
.photo .none img{width:260px;opacity:.16}
.photo::before{content:'';position:absolute;left:0;right:0;top:0;height:{$e(320 + $top)}px;z-index:1;background:linear-gradient(to bottom,{$c['topfade']})}
.photo.plain::before{display:none}
.pill{position:absolute;left:64px;top:{$pillTop}px;z-index:3;padding:21px 32px 19px;background:{$c['pill']};color:{$c['pilltext']};
     font-size:27px;font-weight:600;letter-spacing:.26em;text-transform:uppercase;line-height:1;white-space:nowrap;box-shadow:0 4px 18px rgba(0,0,0,.22)}
{$pillCss}
.logo{position:absolute;top:{$pillTop}px;right:64px;height:{$logoH}px;z-index:3}
.ghost{position:absolute;right:-220px;bottom:0;width:970px;z-index:1;opacity:{$c['ghost']}}
.ghost.m{right:0;width:745px}
.block{position:absolute;left:0;top:{$photoH}px;width:{$W}px;height:{$blockH}px;box-sizing:border-box;padding:{$sz(56)}px 64px {$e(64 + $bot)}px;z-index:2;
       display:flex;flex-direction:column;justify-content:flex-start;overflow:hidden}
.team{display:flex;gap:{$sz(16)}px;margin:0 0 {$sz(26)}px;align-items:flex-start}
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
    $plain = ($label === '' && $logo !== 'top') ? ' plain' : '';   // nothing up top: no top fade either
    $pill  = $label !== '' ? '<div class="pill">' . $e($label) . '</div>' : '';
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
    $block .= '<div class="rule"></div>';
    if ($hlines) $block .= '<h1 class="addr">' . str_replace(' #', '&nbsp;#', implode('<br>', array_map($e, $hlines))) . '</h1>';
    foreach ($lines as $ln) $block .= '<p class="ln ln-' . $ln['style'] . '">' . $e($ln['text']) . '</p>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
         . '<title>' . $e($headline !== '' ? $hlines[0] : 'Post') . '</title><style>' . $css . '</style></head><body>'
         . '<div class="photo' . $plain . '">' . $photoHtml . '</div>' . $pill . $logoHtml . $ghostHtml
         . '<div class="block">' . $block . '</div>'
         . '</body></html>';
}
