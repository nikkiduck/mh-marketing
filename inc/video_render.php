<?php
/**
 * inc/video_render.php — the Video tool's drawing (Nikki, 2026-11-26: "is it
 * possible to include video ... so we can build video stories and reels?";
 * admin only for now). Standalone (no portal.php, no database): it needs only
 * inc/social_render.php, whose fonts and palette it shares, so
 * tests/render_video_sample.php can make a real video on the server.
 *
 * A video is 1080 x 1920 at 30 frames a second, built from SEGMENTS that
 * cross-fade into each other: listing photos that move slowly (a zoom, or a
 * pan across a wide photo), or one uploaded video clip, then an optional
 * closing card. Over them sit LAYERS, each a transparent picture that fades
 * in and out: the logo, and the words.
 *
 * What is drawn where:
 *   - The layers and the closing card are HTML (video_layer_html(), one CSS,
 *     video_layer_css()). The export screenshots each one in Chromium on a
 *     transparent ground (video_layer_document()); the preview puts the same
 *     markup in one page (video_preview_html()). One markup, never two.
 *   - The MOTION exists twice and is labelled so: ffmpeg makes it in the
 *     export (inc/video_export.php) and CSS animation plays it in the
 *     preview. Both read video_timeline() and video_motion(); a change to
 *     the timing or the movement is made there and nowhere else.
 *
 * The normalised video (video_from_request() in inc/video.php):
 *   kind photos|clip, photos [[url, file]], clip [file, start, length, w, h,
 *   audio], hold (seconds a photo is on screen), cover_hold (the first
 *   photo's own seconds), end_hold (the closing card's), motion mix|zoom|pan,
 *   captions [photo's place => its own text] (video_captions()), logos
 *   [photo's place => bool] (video_logo_runs()), zooms
 *   [photo's place => 1..2] (video_photo_zoom()),
 *   label, headline (Enter = a new line), lines [3], words_show open|all,
 *   the look (video_look(): logo_kind full|mark|none, mark, badge,
 *   badge_color; video_logo_opts(): logo_scale, logo_dx, logo_dy,
 *   logo_shadow), end (bool), end_address, end_site, end_extra, agent and
 *   agent2 [name, phone, headshot_url, headshot_file, logo (the picture is a
 *   team's logo), dark (show it in white)] | null (any part may be blank:
 *   the card's contact is typed on the form), name (the file name, no extension).
 */
require_once __DIR__ . '/social_render.php';

const VIDEO_W = 1080;
const VIDEO_H = 1920;
const VIDEO_FPS = 30;
const VIDEO_XF = 18;            // a cross-fade, in frames (0.6 s)
const VIDEO_END_SECONDS = 4.0;  // the closing card, after its fade in, to begin with (video_end_seconds())
const VIDEO_MAX_PHOTOS = 10;
const VIDEO_WIDE = 1.3;         // a photo this many times wider than the frame's shape is "wide": it pans in Mix
const VIDEO_ZOOM = 0.10;        // a zoom runs between 1 and 1 + this
const VIDEO_PAN_SPEED = 0.06;   // a pan drifts this many frame widths a second (65 px): about the pace of the zoom, whatever the photo's shape
const VIDEO_PAN_MAX = 0.7;      // and never crosses more than this share of the photo's spare width
const VIDEO_FADE = 0.5;         // a layer's fade, in seconds
const VIDEO_END_GHOST = 0.2;    // how much of the closing card's background photo shows through (it is drawn in gray)
// The safe zones (Nikki, 2026-11-26, from Instagram's 2026 guide): a reel keeps 220 px clear at the top and 450 at the
// bottom; 250 at the top covers a story as well. Nothing that must be read sits outside them.
const VIDEO_SAFE_TOP = 250;
const VIDEO_SAFE_BOTTOM = 450;

/** The movements offered. */
function video_motions(): array { return ['mix' => 'Mix', 'zoom' => 'Zoom', 'pan' => 'Pan']; }

/** The seconds a photo can stay on screen. */
function video_holds(): array { return ['2.5' => '2.5 seconds', '3' => '3 seconds', '4' => '4 seconds', '5' => '5 seconds']; }

/**
 * The seconds the cover (the first photo) and the closing card can stay: times of their own (Nikki, 2026-11-26: the cover
 * "may need a second or two longer to read", "and the closing slide as well"), longer than a photo's to begin with.
 */
function video_card_holds(): array { $o = []; foreach ([2, 3, 4, 5, 6, 7, 8] as $s) $o[(string)$s] = $s . ' seconds'; return $o; }
const VIDEO_COVER_SECONDS = 5.0;
/** The cover's seconds: its own time, or a photo's when the video carries none (an older caller). */
function video_cover_seconds(array $v): float { return (float)($v['cover_hold'] ?? ($v['hold'] ?? 3)); }
/** The closing card's seconds after its fade in. */
function video_end_seconds(array $v): float { return (float)($v['end_hold'] ?? VIDEO_END_SECONDS); }

/** How long the words stay: over the first two photos, or the whole video. */
function video_words_shows(): array { return ['open' => 'Opening only', 'all' => 'Whole video']; }

/**
 * The look of the cover. Everything is CENTERED (Nikki, 2026-11-26: "remove
 * lower left/upper right alignment options for text and logo. Center only",
 * to work inside the reels' safe zones): the logo top center, the text in
 * the middle of what is left under it. What is chosen: the logo's kind (or
 * none), its size, nudge and shadow as the picture templates set them
 * (social_logo_options(); the size starts at 130 % as theirs does), the
 * watermark, and the badge's place and color. The lists the picture
 * templates share are their own (social_logo_kinds(), social_marks(),
 * social_badge_colors()).
 */
function video_logo_kinds(): array { return social_logo_kinds() + ['none' => 'None']; }
function video_badge_places(): array { return ['text' => 'With the text', 'left' => 'Upper left', 'right' => 'Upper right']; }

/** The look a video was asked for, every value one of its list. */
function video_look(array $v): array {
    $pick = fn(string $k, array $list, string $d) => isset($list[(string)($v[$k] ?? '')]) ? (string)$v[$k] : $d;
    $kind = $pick('logo_kind', video_logo_kinds(), 'full');
    return ['layout' => 'center', 'logo' => ($kind === 'none' || ($v['logo'] ?? '') === 'none') ? 'none' : 'center',
            'logo_kind' => $kind === 'mark' ? 'mark' : 'full', 'mark' => $pick('mark', social_marks(), 'none'),
            'badge' => $pick('badge', video_badge_places(), 'text'), 'badge_color' => $pick('badge_color', social_badge_colors(), 'slate')];
}

/** The logo's size (a share of its own, .5 to 2), nudge (px) and shadow (0 to 100), as social_logo_options() reads them. */
function video_logo_opts(array $v): array {
    return social_logo_options(['logo_scale' => $v['logo_scale'] ?? 1.3, 'logo_dx' => $v['logo_dx'] ?? 0, 'logo_dy' => $v['logo_dy'] ?? 0, 'logo_shadow' => $v['logo_shadow'] ?? 0]);
}

/** The logo's height in px, 0 for none. */
function video_logo_height(array $v): int {
    $k = video_look($v);
    return $k['logo'] === 'none' ? 0 : (int)round(($k['logo_kind'] === 'mark' ? 118 : 150) * video_logo_opts($v)['scale']);
}

/** Where the middle of the text sits, px from the top: halfway between the logo's foot (the top safe zone with no logo) and the bottom safe zone. */
function video_text_mid(array $v): int {
    $h = video_logo_height($v);
    $from = VIDEO_SAFE_TOP + ($h > 0 ? $h + max(0, video_logo_opts($v)['dy']) : 0);
    return (int)round(($from + VIDEO_H - VIDEO_SAFE_BOTTOM) / 2);
}
/**
 * One photo's movement: ['type' => 'zoom', 'from' => 1.0, 'to' => 1.1] or
 * ['type' => 'pan', 'dir' => 1] (1 drifts the view to the right, -1 to the
 * left; a pan is centered on the photo and its distance is
 * video_pan_travel(), a pace, not a share of the photo: crossing most of a
 * wide photo in three seconds read as rushed, Nikki 2026-11-26).
 * $i is the photo's place, so the direction alternates;
 * $wide says the photo is wider than the frame by VIDEO_WIDE. Mix takes
 * turns: zoom, pan, zoom, pan, the cover a zoom (nearly every listing photo
 * is wide for a 9:16 frame, so "pan the wide ones" was a pan on every photo:
 * Nikki, 2026-11-26). A photo with nothing to pan across always zooms.
 */
function video_motion(string $mode, int $i, bool $wide): array {
    $pan = $wide && ($mode === 'pan' || ($mode === 'mix' && $i % 2 === 1));
    $turn = $mode === 'mix' ? intdiv($i, 2) : $i;   // in Mix each kind comes every other photo, so its direction turns every other time
    if ($pan) return ['type' => 'pan', 'dir' => $turn % 2 === 0 ? 1 : -1];
    return $turn % 2 === 0 ? ['type' => 'zoom', 'from' => 1.0, 'to' => 1.0 + VIDEO_ZOOM] : ['type' => 'zoom', 'from' => 1.0 + VIDEO_ZOOM, 'to' => 1.0];
}

/**
 * Where a photo sits in the frame: [x, y], each 0 to 1, 0.5 0.5 = centered
 * (Nikki, 2026-11-27: "drag and move each photo so we can control the starting
 * position of the zoom or pan"). It is the share of the photo's spare width
 * and height left out on the left and at the top, at every step: the cut for
 * the photo's own zoom, the fit to the frame, the point a zoom moves about and
 * the middle of a pan (kept far enough from the photo's edge for the pan to
 * run its distance). The preview (vz() in video_preview_html()) and the
 * export (video_photo_filter()) work it out the same way.
 */
function video_photo_focus(array $v, int $i): array {
    $f = $v['focus'][$i] ?? null;
    $c = fn($n) => max(0.0, min(1.0, round((float)$n, 4)));
    return is_array($f) && count($f) >= 2 ? [$c(array_values($f)[0]), $c(array_values($f)[1])] : [0.5, 0.5];
}

/** A photo's own zoom (1 to 2, 1 = the whole height), set on the form to crop a board's watermark out of the frame. */
function video_photo_zoom(array $v, int $i): float {
    return max(1.0, min(2.0, round((float)($v['zooms'][$i] ?? 1), 2)));
}

/** How far a pan drifts over $frames frames, in the frame's own pixels (the export and the preview both cap it at VIDEO_PAN_MAX of the photo's spare width). */
function video_pan_travel(int $frames): float {
    return round(VIDEO_PAN_SPEED * VIDEO_W * $frames / VIDEO_FPS, 2);
}

/** True when a picture of $w x $h is "wide" for the frame. */
function video_is_wide(int $w, int $h): bool {
    return $h > 0 && ($w / $h) >= VIDEO_WIDE * VIDEO_W / VIDEO_H;
}

/**
 * The timeline, in frames: ['segs' => [[type photo|clip|end, i, start,
 * frames]], 'total' => frames, 'layers' => [key => [in, out|null]] in
 * seconds, 'end_start' => frame | null]. Every segment but the first begins
 * VIDEO_XF frames before the one ahead of it ends: that overlap is the
 * cross-fade. The layers (shade, mark, logo, words, cap{n}) fade out as the
 * closing card fades in; the cover text and the watermark leave before the
 * second photo arrives unless the text is kept for the whole video.
 */
function video_timeline(array $v): array {
    $segs = []; $at = 0;
    $push = function (string $type, int $i, int $frames) use (&$segs, &$at) {
        $start = $segs ? $at - VIDEO_XF : 0;
        $segs[] = ['type' => $type, 'i' => $i, 'start' => $start, 'frames' => $frames];
        $at = $start + $frames;
    };
    if (($v['kind'] ?? 'photos') === 'clip') {
        $push('clip', 0, max(VIDEO_XF * 2 + 1, (int)round((float)$v['clip']['length'] * VIDEO_FPS)));
    } else {
        $hold = (int)round((float)($v['hold'] ?? 3) * VIDEO_FPS);
        $cover = (int)round(video_cover_seconds($v) * VIDEO_FPS);
        foreach (array_keys($v['photos']) as $i) $push('photo', $i, ($i === 0 ? $cover : $hold) + VIDEO_XF);
    }
    $content = count($segs);
    $endStart = null;
    if (!empty($v['end'])) { $push('end', 0, (int)round(video_end_seconds($v) * VIDEO_FPS) + VIDEO_XF); $endStart = $segs[count($segs) - 1]['start']; }
    $total = $at;
    $out = $endStart !== null ? $endStart / VIDEO_FPS : null;          // the layers leave as the closing card arrives
    // The cover text (Nikki, 2026-11-26: the first photo is the cover): on the
    // first photo and gone before the second, unless it is kept for the whole
    // video ('words_show' all). A clip: its first six seconds, or half of a
    // short one.
    $wordsOut = $out; $coverOut = $out;
    if (($v['kind'] ?? 'photos') === 'photos') {
        if ($content >= 2) $coverOut = $segs[1]['start'] / VIDEO_FPS - VIDEO_FADE;
    } else {
        $w = min(6.0, $segs[0]['frames'] / VIDEO_FPS / 2);
        if ($out === null || $w < $out) $coverOut = $w;
    }
    if (($v['words_show'] ?? 'open') === 'open') $wordsOut = $coverOut;
    // The watermark belongs to the cover alone, whatever the text does.
    $layers = ['mark' => [0.3, $coverOut], 'words' => [0.6, $wordsOut]];
    // The logo: on the photos ticked for it (Nikki, 2026-11-26: a "Logo?" tick on each photo), one layer for each run of
    // them, on with the run's first photo and off before the photo after its last. A clip carries it throughout.
    foreach (video_logo_runs($v) as [$a, $b]) {
        $sa = $segs[$a]; $sb = $segs[$b];
        $layers['logo' . $a] = [$a === 0 ? 0.3 : ($sa['start'] + VIDEO_XF / 2) / VIDEO_FPS,
                               $b + 1 < $content ? ($sb['start'] + $sb['frames'] - VIDEO_XF) / VIDEO_FPS - 0.3 : $out];
    }
    // A photo's own text ("custom text on every slide. This will make for
    // proper reels"): from the second photo on, on with its photo and off
    // before the next one arrives.
    $shadeOut = $wordsOut; $any = video_has_words($v);
    foreach (video_captions($v) as $i => $text) {
        $s = $segs[$i];
        $next = ($i + 1 < $content || $endStart !== null) ? ($s['start'] + $s['frames'] - VIDEO_XF) / VIDEO_FPS - 0.3 : null;
        $layers['cap' . $i] = [($s['start'] + VIDEO_XF / 2) / VIDEO_FPS, $next];
        $shadeOut = $next; $any = true;
    }
    // The shade under the text is ONE layer from the first text to the last: fading it out and in again with every photo
    // flickered, and showed worst on a watermark.
    if ($any) $layers['shade'] = [video_has_words($v) ? 0.6 : (array_values(array_filter($layers, fn($k) => str_starts_with($k, 'cap'), ARRAY_FILTER_USE_KEY))[0][0] ?? 0.6), $shadeOut];
    return ['segs' => $segs, 'total' => $total, 'end_start' => $endStart, 'layers' => $layers];
}
/**
 * The runs of photos that show the logo: [[first, last]], places in play
 * order. 'logos' is the form's tick for each photo; a video with no such
 * list (a clip, the test harness) shows the logo throughout. None when the
 * logo is switched off.
 */
function video_logo_runs(array $v): array {
    if (video_look($v)['logo'] === 'none') return [];
    $n = ($v['kind'] ?? 'photos') === 'photos' ? count((array)($v['photos'] ?? [])) : 1;
    if ($n < 1) return [];
    if (($v['kind'] ?? 'photos') !== 'photos' || !isset($v['logos'])) return [[0, $n - 1]];
    $runs = []; $from = null;
    for ($i = 0; $i <= $n; $i++) {
        $on = $i < $n && !empty($v['logos'][$i]);
        if ($on && $from === null) $from = $i;
        if (!$on && $from !== null) { $runs[] = [$from, $i - 1]; $from = null; }
    }
    return $runs;
}

/** The photos that carry their own text: [photo's place => text], in play order, never the first (the cover). Photos only; a clip has none. */
function video_captions(array $v): array {
    if (($v['kind'] ?? 'photos') !== 'photos' || ($v['words_show'] ?? 'open') === 'all') return [];
    $out = [];
    foreach (array_keys((array)($v['photos'] ?? [])) as $i) {
        $t = trim((string)($v['captions'][$i] ?? ''));
        if ($i > 0 && $t !== '') $out[$i] = $t;   // the first photo is the cover: its text is the cover's
    }
    return $out;
}

/** True when the video has any words to show. */
function video_has_words(array $v): bool {
    return trim((string)($v['label'] ?? '')) !== '' || trim((string)($v['headline'] ?? '')) !== '' || array_filter(array_map('trim', (array)($v['lines'] ?? [])));
}

/** The layers this video uses, in stacking order. */
function video_layers(array $v): array {
    $k = video_look($v);
    $caps = video_captions($v);
    $words = video_has_words($v);
    return array_merge(($words || $caps) ? ['shade'] : [], $k['mark'] !== 'none' ? ['mark'] : [], array_map(fn($r) => 'logo' . $r[0], video_logo_runs($v)),
                       $words ? ['words'] : [], array_map(fn($i) => 'cap' . $i, array_keys($caps)));
}
/** The CSS of every layer and the closing card, scoped by the wrapper's class (.L-logo, .L-words, .L-end). */
function video_layer_css(): string {
    $c = social_palette('dark');
    $top = VIDEO_SAFE_TOP; $bot = VIDEO_SAFE_BOTTOM;
    return ".L{position:absolute;inset:0;overflow:hidden;font-family:'Jost',sans-serif;color:#fff}"
         // The logo: upper right on a soft fade, as the posts carry it.
         . ".L-logo .fade{position:absolute;left:0;right:0;top:0;height:620px;background:linear-gradient(180deg, rgba(20,24,30,.62) 0%, rgba(20,24,30,.28) 55%, rgba(20,24,30,0) 100%)}"
         . ".L-logo .logo{position:absolute;left:50%;top:{$top}px;height:150px;transform:translateX(-50%)}"   // its height, nudge and shadow are set inline
         // The watermark, lower right, as a story carries it: the triangle cut off by the edge, or the M mark flush to the corner.
         . ".L-mark .ghost{position:absolute;right:-220px;bottom:0;width:970px;opacity:{$c['ghost']}}.L-mark .ghost.m{right:0;width:745px}"
         // The shade under the text: the posts' fade from the bottom, or a veil over the whole frame when the text is centered.
         . ".L-shade .fade{position:absolute;left:0;right:0;bottom:0;height:1240px;background:linear-gradient(180deg, rgba(20,24,30,0) 0%, rgba(20,24,30,.55) 45%, rgba(20,24,30,.9) 100%)}"
         // Centered text: a soft band across the middle (its place set inline), so the top and bottom of the photo stay bright.
         . ".L-shade.center .fade{bottom:auto;height:1300px;background:linear-gradient(180deg, rgba(20,24,30,0) 0%, rgba(20,24,30,.5) 28%, rgba(20,24,30,.5) 72%, rgba(20,24,30,0) 100%)}"
         // The words: lower left on the posts' fade; the badge, a short rule, the headline, three lines.
         . ".L-words .in{position:absolute;left:72px;right:72px;bottom:{$bot}px}"
         . ".L-words .pill{display:inline-block;background:{$c['pill']};color:{$c['pilltext']};font-weight:500;font-size:26px;line-height:1;letter-spacing:.22em;text-transform:uppercase;padding:15px 24px 14px 28px;margin:0 0 36px}"
         . ".L-words .rule{width:92px;height:2px;background:{$c['accent']};margin:0 0 30px}"
         . ".L-words h1{font-family:'Cormorant Garamond',serif;font-weight:500;line-height:1.03;margin:0 0 30px;font-variant-numeric:lining-nums;text-shadow:0 2px 18px rgba(0,0,0,.4)}"
         . ".L-words p{margin:0}"
         . ".L-words .l1{font-weight:500;font-size:30px;line-height:1.3;letter-spacing:.2em;text-transform:uppercase;color:{$c['accent']}}"
         . ".L-words .l2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:62px;line-height:1.15;font-variant-numeric:lining-nums;margin-top:14px}"
         . ".L-words .l3{font-size:27px;line-height:1.3;letter-spacing:.17em;text-transform:uppercase;color:{$c['muted']};margin-top:16px}"
         // Centered: the text in the middle of the frame on a veil instead of the bottom fade.
         . ".L-words.center .in{bottom:auto;top:50%;transform:translateY(-50%);text-align:center}.L-words.center .pill{margin-left:auto;margin-right:auto}.L-words.center .rule{margin-left:auto;margin-right:auto}"
         // A photo's own text: where the opening text sits, on a shorter fade, in the headline's face.
         . ".L-cap .in{position:absolute;left:72px;right:72px;bottom:{$bot}px}"
         . ".L-cap .rule{width:92px;height:2px;background:{$c['accent']};margin:0 0 30px}"
         . ".L-cap p{font-family:'Cormorant Garamond',serif;font-weight:500;line-height:1.1;margin:0;font-variant-numeric:lining-nums;text-shadow:0 2px 18px rgba(0,0,0,.45);text-wrap:balance}"
         . ".L-cap.center .in{bottom:auto;top:50%;transform:translateY(-50%);text-align:center}.L-cap.center .rule{margin-left:auto;margin-right:auto}"
         // The badge in an upper corner; under the logo when that is in the same corner (.low, the top set inline).
         . ".L-words .pill.up{position:absolute;top:{$top}px;margin:0}.L-words .pill.up.left{left:72px}.L-words .pill.up.right{right:72px}"
         // The closing card: the logo, the address, who presents it, the site.
         . ".L-end{background:{$c['bg']};display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:" . ($top + 20) . "px 90px " . ($bot + 80) . "px;box-sizing:border-box}"
         . ".L-end{isolation:isolate}.L-end img.bg{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:-1;opacity:" . VIDEO_END_GHOST . ";filter:grayscale(1)}"
         . ".L-end .logo{height:280px}"
         . ".L-end .rule{width:92px;height:2px;background:{$c['accent']};margin:54px 0 44px}"
         . ".L-end .addr{font-weight:500;font-size:32px;line-height:1.45;letter-spacing:.2em;text-transform:uppercase;color:{$c['accent']}}"
         . ".L-end .by{margin-top:58px;display:flex;flex-direction:column;align-items:center}"
         . ".L-end .by img.shot{width:230px;height:230px;object-fit:cover;display:block;margin-bottom:34px}"   // square, never a circle
         // A team's logo where a headshot goes. A dark logo (said at upload) is turned to white: greyed, inverted, and screened onto the card so a white ground drops out.
         . ".L-end .by img.tlogo{display:block;max-width:600px;max-height:210px;width:auto;height:auto;margin-bottom:34px}.L-end .by img.tlogo.inv{filter:grayscale(1) invert(1) contrast(1.15);mix-blend-mode:screen}"
         // Two presenters: side by side, each with its own picture, name and phone, under one "Presented by".
         . ".L-end .by.two{margin-top:52px}.L-end .by.two .pair{display:flex;justify-content:center;align-items:flex-start;gap:44px;margin-top:30px}"
         . ".L-end .by.two .who{width:430px;display:flex;flex-direction:column;align-items:center}"
         . ".L-end .by.two img.shot{width:200px;height:200px;margin-bottom:26px}.L-end .by.two img.tlogo{max-width:400px;max-height:200px;margin-bottom:26px}"
         . ".L-end .by.two b{font-size:50px;margin-top:0}.L-end .by.two .who span{font-size:29px}"
         . ".L-end .by .more{font-size:30px;letter-spacing:.04em;margin-top:26px}"
         . ".L-end .by small{font-size:23px;letter-spacing:.24em;text-transform:uppercase;color:{$c['muted']}}"
         . ".L-end .by b{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:68px;line-height:1.15;margin-top:10px}"
         . ".L-end .by span{font-size:34px;letter-spacing:.08em;margin-top:8px;font-variant-numeric:lining-nums}"
         . ".L-end .by span+span{font-size:30px;letter-spacing:.04em}"
         . ".L-end .site{position:absolute;left:0;right:0;bottom:{$bot}px;font-size:27px;letter-spacing:.22em;text-transform:uppercase;color:{$c['muted']}}";
}

/** The headline's size for its longest line. */
function video_headline_size(string $headline): int {
    $long = 0;
    foreach (explode("\n", $headline) as $row) $long = max($long, mb_strlen(trim($row)));
    return $long <= 14 ? 112 : ($long <= 19 ? 96 : ($long <= 25 ? 82 : 70));
}

/**
 * One layer's markup: 'logo', 'words' or 'end' (the closing card). $export =
 * true reads the logo and the headshot from disk, as Chromium has no session.
 */
function video_layer_html(array $v, string $key, ?string $base = null, bool $export = false): string {
    $base = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $disk = realpath(__DIR__ . '/../assets/images/MH_vert-stack_white.svg');
    $logo = ($export && $disk) ? 'file://' . $disk : $base . '/assets/images/MH_vert-stack_white.svg';
    $k = video_look($v);
    $img = function (string $file) use ($export, $base): string {
        $d = realpath(__DIR__ . '/../assets/images/' . $file);
        return ($export && $d) ? 'file://' . $d : $base . '/assets/images/' . $file;
    };
    $mid = video_text_mid($v);
    if ($key === 'shade') return '<div class="L L-shade center"><div class="fade" style="top:' . ($mid - 650) . 'px"></div></div>';
    if ($key === 'mark') return '<div class="L L-mark"><img class="ghost' . ($k['mark'] === 'mmark' ? ' m' : '') . '" src="' . $e($img($k['mark'] === 'mmark' ? 'M-mark_white.svg' : 'triangle_mark-white.svg')) . '" alt=""></div>';
    if (str_starts_with($key, 'logo')) {
        $h = '';
        if ($k['logo'] !== 'none') {
            $o = video_logo_opts($v);
            $style = 'height:' . video_logo_height($v) . 'px;transform:translate(calc(-50% + ' . $o['dx'] . 'px),' . $o['dy'] . 'px);'
                   . ($o['logo_shadow'] > 0 ? 'filter:drop-shadow(' . social_shadow_css($o['logo_shadow']) . ');' : '');
            $h .= '<div class="fade"></div><img class="logo" style="' . $style . '" src="' . $e($k['logo_kind'] === 'mark' ? $img('M-mark_white.svg') : $logo) . '" alt="Mont Haus International Realty">';
        }
        return '<div class="L L-logo">' . $h . '</div>';
    }
    if (str_starts_with($key, 'cap')) {
        $text = video_captions($v)[(int)substr($key, 3)] ?? '';
        $long = 0;
        foreach (explode("\n", $text) as $row) $long = max($long, mb_strlen(trim($row)));
        $size = mb_strlen($text) <= 28 ? 96 : (mb_strlen($text) <= 60 ? 82 : (mb_strlen($text) <= 100 ? 70 : 60));
        if ($long > 0 && $long <= 18 && $size < 96 && substr_count($text, "\n") > 0) $size = min(96, $size + 12);   // short rows the writer broke by hand can run larger
        return '<div class="L L-cap center"><div class="in" style="top:' . $mid . 'px"><div class="rule"></div>'
             . '<p style="font-size:' . $size . 'px">' . nl2br($e($text), false) . '</p></div></div>';
    }
    if ($key === 'words') {
        $h = '';
        $label = trim((string)($v['label'] ?? ''));
        $headline = trim(str_replace("\r", '', (string)($v['headline'] ?? '')));
        [, $pbg, $ptx] = social_badge_colors()[$k['badge_color']];
        $pstyle = $k['badge_color'] === 'slate' ? '' : "background:{$pbg};color:{$ptx};";
        $up = '';
        if ($label !== '' && $k['badge'] !== 'text') {
            // In the logo's corner the badge goes under it.
            $under = '';   // the logo is top center: a corner badge never meets it
            $up = '<span class="pill up ' . $k['badge'] . '" style="' . $pstyle . $under . '">' . $e($label) . '</span>';
        } elseif ($label !== '') {
            $h .= '<div><span class="pill"' . ($pstyle !== '' ? ' style="' . $pstyle . '"' : '') . '>' . $e($label) . '</span></div>';
        }
        $h .= '<div class="rule"></div>';
        if ($headline !== '') $h .= '<h1 style="font-size:' . video_headline_size($headline) . 'px">' . nl2br($e($headline), false) . '</h1>';
        foreach (array_values((array)($v['lines'] ?? [])) as $i => $line) {
            if ($i < 3 && trim((string)$line) !== '') $h .= '<p class="l' . ($i + 1) . '">' . $e(trim((string)$line)) . '</p>';
        }
        return '<div class="L L-words center">' . $up . '<div class="in" style="top:' . $mid . 'px">' . $h . '</div></div>';
    }
    // The closing card.
    $h = '<img class="logo" src="' . $e($logo) . '" alt="Mont Haus International Realty">';
    $addr = trim((string)($v['end_address'] ?? ''));
    if ($addr !== '') $h .= '<div class="rule"></div><div class="addr">' . nl2br($e($addr), false) . '</div>';
    // Who presents it: one (picture, "Presented by", name, phone) or two side by side under one "Presented by".
    $pic = function (array $a) use ($export, $e): string {
        $src = ($export && !empty($a['headshot_file'])) ? 'file://' . $a['headshot_file'] : (string)($a['headshot_url'] ?? '');
        if ($src === '') return '';
        return !empty($a['logo']) ? '<img class="tlogo' . (!empty($a['dark']) ? ' inv' : '') . '" src="' . $e($src) . '" alt="">' : '<img class="shot" src="' . $e($src) . '" alt="">';
    };
    $phone = fn(array $a) => trim((string)($a['phone'] ?? '')) !== '' ? '<span>' . $e(trim((string)$a['phone'])) . '</span>' : '';
    $a = $v['agent'] ?? null; $b = $v['agent2'] ?? null;
    $extra = trim((string)($v['end_extra'] ?? ($a['extra'] ?? '')));
    $more = $extra !== '' ? '<span class="more">' . $e($extra) . '</span>' : '';
    if ($a && $b) {
        $col = fn(array $x) => '<div class="who">' . $pic($x) . (trim((string)($x['name'] ?? '')) !== '' ? '<b>' . $e(trim((string)$x['name'])) . '</b>' : '') . $phone($x) . '</div>';
        $h .= '<div class="by two"><small>Presented by</small><div class="pair">' . $col($a) . $col($b) . '</div>' . $more . '</div>';
    } elseif ($a) {
        $name = trim((string)($a['name'] ?? ''));
        $h .= '<div class="by">' . $pic($a) . ($name !== '' ? '<small>Presented by</small><b>' . $e($name) . '</b>' : '') . $phone($a) . $more . '</div>';
    } elseif ($more !== '') {
        $h .= '<div class="by">' . $more . '</div>';
    }
    // The site line is the caller's ('end_site'); a video made before it was a field says monthaus.com.
    $site = array_key_exists('end_site', $v) ? trim((string)$v['end_site']) : 'monthaus.com';
    if ($site !== '') $h .= '<div class="site">' . $e($site) . '</div>';
    // A ghosted photo behind it all: one of the video's photos, where it sits in its own slide (video_photo_focus()). The
    // export reads the copy video_build() fetched ('end_bg_file').
    $bg = '';
    $at = $v['end_bg'] ?? null;
    if ($at !== null && isset($v['photos'][(int)$at])) {
        $src = $export ? (!empty($v['end_bg_file']) ? 'file://' . $v['end_bg_file'] : '') : (string)$v['photos'][(int)$at][0];
        [$fx, $fy] = video_photo_focus($v, (int)$at);
        if ($src !== '') $bg = '<img class="bg" src="' . $e($src) . '" alt="" style="object-position:' . ($fx * 100) . '% ' . ($fy * 100) . '%">';
    }
    return '<div class="L L-end">' . $bg . $h . '</div>';
}

/** One layer as a complete document on a transparent ground, for the export's screenshot. */
function video_layer_document(array $v, string $key): string {
    $W = VIDEO_W; $H = VIDEO_H;
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $key . '</title><style>' . social_font_css('', true)
         . "html,body{margin:0;padding:0;background:transparent}body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden}" . video_layer_css()
         . '</style></head><body>' . video_layer_html($v, $key, null, true) . '</body></html>';
}

/**
 * The preview: the whole video as one page that plays and loops, the photos
 * moving and cross-fading by CSS animation on the export's timeline, the
 * layers the export's own markup. A clip plays as a <video> from $clip_url.
 * The movement of a photo depends on its shape, known only once it loads, so
 * each photo carries both animations and its onload picks one (the same test
 * as video_is_wide()). An animation picked that way starts counting when the
 * photo loads, late against the page's own clock, which held each photo
 * still for that long before it moved (Nikki, 2026-11-26): the onload sets
 * a negative animation-delay of the page's age to put it back in step.
 */
function video_preview_html(array $v, ?string $base = null, string $clip_url = ''): string {
    $base = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W = VIDEO_W; $H = VIDEO_H;
    $T = video_timeline($v);
    $total = $T['total']; $secs = round($total / VIDEO_FPS, 3);
    $pct = fn(float $frame) => rtrim(rtrim(number_format(max(0, min(100, $frame / $total * 100)), 3, '.', ''), '0'), '.') . '%';
    $css = ''; $html = '';
    foreach ($T['segs'] as $n => $s) {
        $a = $s['start']; $b = $s['start'] + $s['frames'];
        // Seen: nothing before its start, fully there after the cross-fade, gone the moment the next one has covered it.
        $css .= "@keyframes s{$n}{0%{opacity:0}" . ($a > 0 ? $pct($a) . "{opacity:0}" . $pct($a + VIDEO_XF) . "{opacity:1}" : "0.001%{opacity:1}")
              . $pct($b) . "{opacity:1}" . ($b < $total ? $pct($b + 0.05) . "{opacity:0}" : '') . "100%{opacity:" . ($b < $total ? 0 : 1) . "}}";
        $css .= ".s{$n}{animation:s{$n} {$secs}s linear infinite}";
        if ($s['type'] === 'photo') {
            foreach ([true, false] as $wide) {
                $m = video_motion((string)($v['motion'] ?? 'mix'), $s['i'], $wide);
                $k = ($wide ? 'w' : 'n') . $n;
                [$from, $to] = $m['type'] === 'pan'
                    ? ['transform:scale(var(--z,1));object-position:calc(var(--q,50%) - ' . $m['dir'] . '*var(--p,5%)) 50%', 'transform:scale(var(--z,1));object-position:calc(var(--q,50%) + ' . $m['dir'] . '*var(--p,5%)) 50%']
                    : ['transform:scale(calc(var(--z,1)*' . $m['from'] . '))', 'transform:scale(calc(var(--z,1)*' . $m['to'] . '))'];
                $css .= "@keyframes {$k}{0%{{$from}}" . $pct($a) . "{{$from}}" . $pct($b) . "{{$to}}100%{{$to}}}";
                // The photo's own position: a zoom moves about that point of the frame; a pan takes its middle (--q, from vz()) and its height from it.
                $css .= ".s{$n}." . ($wide ? 'w' : 'n') . ">img{animation:{$k} {$secs}s linear infinite;"
                      . ($m['type'] === 'pan' ? 'transform-origin:50% var(--fy,50%)' : 'transform-origin:var(--fx,50%) var(--fy,50%);object-position:var(--fx,50%) var(--fy,50%)') . '}';
            }
            $url = (string)($v['photos'][$s['i']][0] ?? '');
            $z = video_photo_zoom($v, (int)$s['i']);
            // data-i = the photo's place; data-s, data-a, data-b = when it is fully there, starts and ends, in ms (the form holds the preview on a photo while its zoom is dragged: vz() and vseek() below).
            $ms = fn(float $frame) => (int)round($frame / VIDEO_FPS * 1000);
            [$fx, $fy] = video_photo_focus($v, (int)$s['i']);
            $html .= '<div class="seg s' . $n . '" data-i="' . (int)$s['i'] . '" data-s="' . $ms($a > 0 ? $a + VIDEO_XF : 1) . '" data-a="' . $ms($a) . '" data-b="' . $ms($b) . '"><img src="' . $e($url) . '" alt="" style="--z:' . $z . '" data-t="' . video_pan_travel((int)$s['frames']) . '" data-fx="' . $fx . '" data-fy="' . $fy . '" onload="vz(this,' . $z . ',1)"></div>';
        } elseif ($s['type'] === 'clip') {
            $c = $v['clip'];
            $html .= '<div class="seg s' . $n . '"><video id="clip" src="' . $e($clip_url) . '#t=' . round((float)$c['start'], 2) . '" muted playsinline preload="auto" data-start="' . round((float)$c['start'], 2) . '"></video></div>';
        } else {
            $html .= '<div class="seg s' . $n . '">' . video_layer_html($v, 'end', $base, false) . '</div>';
        }
    }
    foreach (video_layers($v) as $key) {
        [$in, $out] = $T['layers'][$key];
        $f = VIDEO_FADE * VIDEO_FPS; $a = $in * VIDEO_FPS;
        $css .= "@keyframes l-{$key}{0%{opacity:0}" . $pct($a) . "{opacity:0}" . $pct($a + $f) . "{opacity:1}"
              . ($out !== null ? $pct($out * VIDEO_FPS) . "{opacity:1}" . $pct($out * VIDEO_FPS + $f) . "{opacity:0}100%{opacity:0}" : "100%{opacity:1}") . "}";
        $css .= ".l-{$key}{animation:l-{$key} {$secs}s linear infinite}";
        $html .= '<div class="lay l-' . $key . '">' . video_layer_html($v, $key, $base, false) . '</div>';
    }
    // A clip is kept in step with the page's own animation clock: back to its start each time the timeline loops.
    $js = ($v['kind'] ?? 'photos') === 'clip'
        ? '<script>(function(){var c=document.getElementById("clip");if(!c)return;var s=+c.dataset.start,T=' . ($secs * 1000) . ',t0=performance.now();'
          . 'function go(){try{c.currentTime=s;c.play()}catch(e){}}c.addEventListener("loadedmetadata",go);'
          . 'setInterval(function(){var n=performance.now();if(n-t0>=T){t0+=T*Math.floor((n-t0)/T);go()}},100)})();</script>'
        : '';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Video preview</title><style>' . social_font_css($base, false)
         . "html,body{margin:0;padding:0}body{position:relative;width:{$W}px;height:{$H}px;overflow:hidden;background:#14181e}"
         . ".seg,.lay{position:absolute;inset:0;overflow:hidden;opacity:0}.seg>img,.seg>video{display:block;width:100%;height:100%;object-fit:cover}"
         . video_layer_css() . $css . '</style>'
         // vz(): a photo's own zoom z and its position fx fy (0 to 1; left out = as they were), and from them how far it pans and about where
         // (on load, and again from the form while the zoom is dragged or the photo is moved). vspare(): the px of the photo out of the
         // frame across and down, what a drag has to move it by. vseek(): every animation put at t ms of the video, playing or held
         // there. vnow(): where the video is, in ms.
         . '<script>var W=' . $W . ',H=' . $H . ';'
         . 'function vz(m,z,first,fx,fy){if(fx==null)fx=+m.dataset.fx;else m.dataset.fx=fx;if(fy==null)fy=+m.dataset.fy;else m.dataset.fy=fy;m.dataset.z=z;'
         . 'var r=m.naturalWidth/m.naturalHeight,s=r*H-W,wc=Math.min(r*z,2)*H,c=wc-W;'
         . 'm.style.setProperty("--z",z);m.style.setProperty("--fx",(fx*100)+"%");m.style.setProperty("--fy",(fy*100)+"%");'
         . 'if(first){m.style.animationDelay=(-document.timeline.currentTime)+"ms";m.parentNode.classList.add(r>=' . round(VIDEO_WIDE * VIDEO_W / VIDEO_H, 4) . '?"w":"n")}'
         . 'if(s>0){var t=Math.min(+m.dataset.t,' . VIDEO_PAN_MAX . '*c),l=fx*(r*z*H-wc)+Math.max(t/2,Math.min(c-t/2,fx*c));'
         . 'm.style.setProperty("--p",(50*t/(z*s))+"%");m.style.setProperty("--q",(100*((l+W/2)/z-W/2)/s)+"%")}}'
         . 'function vspare(m){var r=m.naturalWidth/m.naturalHeight,z=+m.dataset.z||1;return[Math.max(W,r*H)*z-W,Math.max(H,W/r)*z-H]}'
         . 'function vseek(t,play){document.getAnimations().forEach(function(a){var e=a.effect.getComputedTiming(),D=+e.duration,d=+e.delay;if(!(D>0))return;a.currentTime=((t+d)%D+D)%D;play?a.play():a.pause()})}'
         . 'function vnow(){var a=document.getAnimations()[0];if(!a)return 0;var e=a.effect.getComputedTiming(),D=+e.duration;return D>0?(((+a.currentTime||0)-e.delay)%D+D)%D:0}</script>'
         . '</head><body>' . $html . $js . '</body></html>';
}
