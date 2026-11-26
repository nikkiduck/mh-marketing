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
 *   audio], hold (seconds a photo is on screen), motion mix|zoom|pan,
 *   label, headline (Enter = a new line), lines [3], words_show open|all,
 *   end (bool), end_address, agent [name, phone, headshot_url,
 *   headshot_file] | null, name (the file name, no extension).
 */
require_once __DIR__ . '/social_render.php';

const VIDEO_W = 1080;
const VIDEO_H = 1920;
const VIDEO_FPS = 30;
const VIDEO_XF = 18;            // a cross-fade, in frames (0.6 s)
const VIDEO_END_SECONDS = 3.0;  // the closing card, after its fade in
const VIDEO_MAX_PHOTOS = 10;
const VIDEO_WIDE = 1.3;         // a photo this many times wider than the frame's shape is "wide": it pans in Mix
const VIDEO_ZOOM = 0.10;        // a zoom runs between 1 and 1 + this
const VIDEO_PAN_SPEED = 0.06;   // a pan drifts this many frame widths a second (65 px): about the pace of the zoom, whatever the photo's shape
const VIDEO_PAN_MAX = 0.7;      // and never crosses more than this share of the photo's spare width
const VIDEO_FADE = 0.5;         // a layer's fade, in seconds
const VIDEO_SAFE_TOP = 200;     // clear of the story and reel controls
const VIDEO_SAFE_BOTTOM = 330;  // clear of a reel's caption and buttons

/** The movements offered. */
function video_motions(): array { return ['mix' => 'Mix', 'zoom' => 'Zoom', 'pan' => 'Pan']; }

/** The seconds a photo can stay on screen. */
function video_holds(): array { return ['2.5' => '2.5 seconds', '3' => '3 seconds', '4' => '4 seconds', '5' => '5 seconds']; }

/** How long the words stay: over the first two photos, or the whole video. */
function video_words_shows(): array { return ['open' => 'Opening only', 'all' => 'Whole video']; }

/**
 * One photo's movement: ['type' => 'zoom', 'from' => 1.0, 'to' => 1.1] or
 * ['type' => 'pan', 'dir' => 1] (1 drifts the view to the right, -1 to the
 * left; a pan is centered on the photo and its distance is
 * video_pan_travel(), a pace, not a share of the photo: crossing most of a
 * wide photo in three seconds read as rushed, Nikki 2026-11-26).
 * $i is the photo's place, so the direction alternates;
 * $wide says the photo is wider than the frame by VIDEO_WIDE. Mix pans a
 * wide photo and zooms the rest; Pan zooms a photo with nothing to pan across.
 */
function video_motion(string $mode, int $i, bool $wide): array {
    $pan = $mode === 'pan' ? $wide : ($mode === 'mix' && $wide);
    if ($pan) return ['type' => 'pan', 'dir' => $i % 2 === 0 ? 1 : -1];
    return $i % 2 === 0 ? ['type' => 'zoom', 'from' => 1.0, 'to' => 1.0 + VIDEO_ZOOM] : ['type' => 'zoom', 'from' => 1.0 + VIDEO_ZOOM, 'to' => 1.0];
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
 * cross-fade. The logo and the words fade out as the closing card fades in;
 * with Opening only, the words leave before the third photo arrives.
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
        foreach (array_keys($v['photos']) as $i) $push('photo', $i, $hold + VIDEO_XF);
    }
    $content = count($segs);
    $endStart = null;
    if (!empty($v['end'])) { $push('end', 0, (int)round(VIDEO_END_SECONDS * VIDEO_FPS) + VIDEO_XF); $endStart = $segs[count($segs) - 1]['start']; }
    $total = $at;
    $out = $endStart !== null ? $endStart / VIDEO_FPS : null;          // the layers leave as the closing card arrives
    $wordsOut = $out;
    if (($v['words_show'] ?? 'open') === 'open' && ($v['kind'] ?? 'photos') === 'photos' && $content >= 3) {
        $wordsOut = $segs[2]['start'] / VIDEO_FPS - VIDEO_FADE;        // gone before the third photo fades in
    } elseif (($v['words_show'] ?? 'open') === 'open' && ($v['kind'] ?? 'photos') === 'clip') {
        $w = min(6.0, $segs[0]['frames'] / VIDEO_FPS / 2);             // a clip: the first six seconds, or half of a short one
        if ($out === null || $w < $out) $wordsOut = $w;
    }
    return ['segs' => $segs, 'total' => $total, 'end_start' => $endStart,
            'layers' => ['logo' => [0.3, $out], 'words' => [0.6, $wordsOut]]];
}

/** True when the video has any words to show. */
function video_has_words(array $v): bool {
    return trim((string)($v['label'] ?? '')) !== '' || trim((string)($v['headline'] ?? '')) !== '' || array_filter(array_map('trim', (array)($v['lines'] ?? [])));
}

/** The layers this video uses, in stacking order. */
function video_layers(array $v): array {
    return array_merge(['logo'], video_has_words($v) ? ['words'] : []);
}

/** The CSS of every layer and the closing card, scoped by the wrapper's class (.L-logo, .L-words, .L-end). */
function video_layer_css(): string {
    $c = social_palette('dark');
    $top = VIDEO_SAFE_TOP; $bot = VIDEO_SAFE_BOTTOM;
    return ".L{position:absolute;inset:0;overflow:hidden;font-family:'Jost',sans-serif;color:#fff}"
         // The logo: upper right on a soft fade, as the posts carry it.
         . ".L-logo .fade{position:absolute;left:0;right:0;top:0;height:620px;background:linear-gradient(180deg, rgba(20,24,30,.62) 0%, rgba(20,24,30,.28) 55%, rgba(20,24,30,0) 100%)}"
         . ".L-logo img{position:absolute;right:72px;top:{$top}px;height:150px}"
         // The words: lower left on the posts' fade; the badge, a short rule, the headline, three lines.
         . ".L-words .fade{position:absolute;left:0;right:0;bottom:0;height:1240px;background:linear-gradient(180deg, rgba(20,24,30,0) 0%, rgba(20,24,30,.55) 45%, rgba(20,24,30,.9) 100%)}"
         . ".L-words .in{position:absolute;left:72px;right:72px;bottom:{$bot}px}"
         . ".L-words .pill{display:inline-block;background:{$c['pill']};color:{$c['pilltext']};font-weight:500;font-size:26px;line-height:1;letter-spacing:.22em;text-transform:uppercase;padding:15px 24px 14px 28px;margin:0 0 36px}"
         . ".L-words .rule{width:92px;height:2px;background:{$c['accent']};margin:0 0 30px}"
         . ".L-words h1{font-family:'Cormorant Garamond',serif;font-weight:500;line-height:1.03;margin:0 0 30px;font-variant-numeric:lining-nums;text-shadow:0 2px 18px rgba(0,0,0,.4)}"
         . ".L-words p{margin:0}"
         . ".L-words .l1{font-weight:500;font-size:30px;line-height:1.3;letter-spacing:.2em;text-transform:uppercase;color:{$c['accent']}}"
         . ".L-words .l2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:62px;line-height:1.15;font-variant-numeric:lining-nums;margin-top:14px}"
         . ".L-words .l3{font-size:27px;line-height:1.3;letter-spacing:.17em;text-transform:uppercase;color:{$c['muted']};margin-top:16px}"
         // The closing card: the logo, the address, who presents it, the site.
         . ".L-end{background:{$c['bg']};display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:" . ($top + 40) . "px 90px " . ($bot + 20) . "px;box-sizing:border-box}"
         . ".L-end .logo{height:330px}"
         . ".L-end .rule{width:92px;height:2px;background:{$c['accent']};margin:70px 0 56px}"
         . ".L-end .addr{font-weight:500;font-size:32px;line-height:1.45;letter-spacing:.2em;text-transform:uppercase;color:{$c['accent']}}"
         . ".L-end .by{margin-top:78px;display:flex;flex-direction:column;align-items:center}"
         . ".L-end .by img{width:230px;height:230px;object-fit:cover;display:block;margin-bottom:34px}"   // square, never a circle
         . ".L-end .by small{font-size:23px;letter-spacing:.24em;text-transform:uppercase;color:{$c['muted']}}"
         . ".L-end .by b{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:68px;line-height:1.15;margin-top:10px}"
         . ".L-end .by span{font-size:34px;letter-spacing:.08em;margin-top:8px;font-variant-numeric:lining-nums}"
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
    if ($key === 'logo') {
        return '<div class="L L-logo"><div class="fade"></div><img src="' . $e($logo) . '" alt="Mont Haus International Realty"></div>';
    }
    if ($key === 'words') {
        $h = '';
        $label = trim((string)($v['label'] ?? ''));
        $headline = trim(str_replace("\r", '', (string)($v['headline'] ?? '')));
        if ($label !== '') $h .= '<div><span class="pill">' . $e($label) . '</span></div>';
        $h .= '<div class="rule"></div>';
        if ($headline !== '') $h .= '<h1 style="font-size:' . video_headline_size($headline) . 'px">' . nl2br($e($headline), false) . '</h1>';
        foreach (array_values((array)($v['lines'] ?? [])) as $i => $line) {
            if ($i < 3 && trim((string)$line) !== '') $h .= '<p class="l' . ($i + 1) . '">' . $e(trim((string)$line)) . '</p>';
        }
        return '<div class="L L-words"><div class="fade"></div><div class="in">' . $h . '</div></div>';
    }
    // The closing card.
    $h = '<img class="logo" src="' . $e($logo) . '" alt="Mont Haus International Realty">';
    $addr = trim((string)($v['end_address'] ?? ''));
    if ($addr !== '') $h .= '<div class="rule"></div><div class="addr">' . nl2br($e($addr), false) . '</div>';
    $a = $v['agent'] ?? null;
    if ($a) {
        $shot = ($export && !empty($a['headshot_file'])) ? 'file://' . $a['headshot_file'] : (string)($a['headshot_url'] ?? '');
        $h .= '<div class="by">' . ($shot !== '' ? '<img src="' . $e($shot) . '" alt="">' : '') . '<small>Presented by</small><b>' . $e($a['name']) . '</b>'
            . (trim((string)($a['phone'] ?? '')) !== '' ? '<span>' . $e($a['phone']) . '</span>' : '') . '</div>';
    }
    $h .= '<div class="site">monthaus.com</div>';
    return '<div class="L L-end">' . $h . '</div>';
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
 * as video_is_wide()).
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
                    ? ['object-position:calc(50% - ' . $m['dir'] . '*var(--p,5%)) 50%', 'object-position:calc(50% + ' . $m['dir'] . '*var(--p,5%)) 50%']
                    : ['transform:scale(' . $m['from'] . ')', 'transform:scale(' . $m['to'] . ')'];
                $css .= "@keyframes {$k}{0%{{$from}}" . $pct($a) . "{{$from}}" . $pct($b) . "{{$to}}100%{{$to}}}";
                $css .= ".s{$n}." . ($wide ? 'w' : 'n') . ">img{animation:{$k} {$secs}s linear infinite}";
            }
            $url = (string)($v['photos'][$s['i']][0] ?? '');
            $html .= '<div class="seg s' . $n . '"><img src="' . $e($url) . '" alt="" onload="var r=this.naturalWidth/this.naturalHeight,s=r*' . $H . '-' . $W . ',c=Math.min(r,2)*' . $H . '-' . $W . ';this.parentNode.classList.add(r>=' . round(VIDEO_WIDE * VIDEO_W / VIDEO_H, 4) . '?\'w\':\'n\');if(s>0)this.style.setProperty(\'--p\',(50*Math.min(' . video_pan_travel((int)$s['frames']) . ',' . VIDEO_PAN_MAX . '*c)/s)+\'%\')"></div>';
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
         . video_layer_css() . $css . '</style></head><body>' . $html . $js . '</body></html>';
}
