<?php
/**
 * tests/export_ads_idml.php — the Full Bleed digital ads as InDesign IDML,
 * one document per size, for Nikki to tweak locally (2026-11-18: "can you
 * export the files/sizes you just created in this new Full Bleed design as
 * an InDesign idml file so I can tweak locally?").
 *
 *   php tests/export_ads_idml.php <outdir> <links-uri>
 *
 * <links-uri> is where the Links folder will sit on HER Mac, as InDesign
 * writes it: file:/Users/.../Full%20Bleed%20InDesign/Links (the links are
 * absolute, as in her own exports; InDesign also looks in a Links folder
 * beside the document when a path has moved). Run on the server: it needs
 * Chromium (to measure) and Imagick (image sizes). It writes
 * <outdir>/MH_FullBleed_<size>.idml for the seven sizes and <outdir>/Links/
 * (the listing photos and the headshots; the logo is her own vector file,
 * MH-stack-logo_white_1.ai, copied into Links by hand).
 *
 * Each document is that size's slides as pages: 1 headline, 2 price, 3 one
 * broker, 4 two brokers, the same sample ad as tests/render_ads_sample.php
 * (970 Powder Lane). Everything is a native, editable object:
 *   - the photo in a full-page frame (fill proportionally, centred);
 *   - the scrim as a full-page rectangle in the swatch MH Scrim Ink with a
 *     Gradient Feather whose stops are the web version's, and a second one
 *     behind the logo;
 *   - the words as live text in named paragraph styles (Ad Headline, Ad
 *     Details, Ad Price, Ad Label, Ad Name, Ad Phone, Ad Site), one frame
 *     per block; the slate rule as a 1 px rectangle;
 *   - the logo as her placed .ai; headshots in their own frames.
 *
 * HOW: no layout is redone by hand. Each slide's HTML (ads_render_frame(),
 * the same document the GIF is made from) is opened in headless Chromium
 * with a script that reads every element's box, its font, size, line height,
 * tracking and color and the BASELINE of its first line; --dump-dom hands
 * that back. A text frame's first baseline is fixed at the measured offset
 * and each paragraph's leading is the measured distance from the baseline
 * before it, so the type sits where the browser put it, to the pixel.
 *
 * The package is tests/idml_skeleton/ (the Resources, master and XML files of
 * an IDML exported by her InDesign, "display_template", the one the Market
 * Snapshot IDML was built on) plus the spreads and stories written here.
 * IDML is a ZIP whose first entry is `mimetype`, stored uncompressed.
 *
 * Coordinates: a one-page spread's origin is the middle of its left edge, so
 * a point at page (x, y) is spread (x, y - H/2). 1 px = 1 pt.
 */
require __DIR__ . '/../inc/ads_export.php';   // ads_render.php, ads_styles.php, SOCIAL_CHROMIUM

$out = rtrim($argv[1] ?? '', '/');
$linksUri = rtrim($argv[2] ?? '', '/');
if ($out === '' || $linksUri === '') { fwrite(STDERR, "usage: php tests/export_ads_idml.php <outdir> <links-uri>\n"); exit(1); }
@mkdir("$out/Links", 0775, true);
$skel = __DIR__ . '/idml_skeleton';
$x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
$n = fn(float $v) => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';

// ── The sample ad: 970 Powder Lane, as on the boards ──────────────────────────
$base = 'https://marketing.monthaus.com';
$links = [];   // name => [file on this server, px w, px h, points w, points h, ppi]
$fetch = function (string $name, string $src) use (&$links, $out): ?string {
    $dest = "$out/Links/$name";
    if (!is_file($dest)) { $b = @file_get_contents($src); if ($b === false || $b === '') { fwrite(STDERR, "cannot read $src\n"); return null; } file_put_contents($dest, $b); }
    $im = new Imagick($dest); $w = $im->getImageWidth(); $h = $im->getImageHeight();
    $res = $im->getImageResolution(); $units = $im->getImageUnits(); $im->clear();
    $ppi = (float)($res['x'] ?? 0); if ($units === Imagick::RESOLUTION_PIXELSPERCENTIMETER) $ppi *= 2.54;
    if ($ppi < 1) $ppi = 72.0;   // no density in the file: InDesign reads it as 72
    $links[$name] = [realpath($dest), $w, $h, $w * 72 / $ppi, $h * 72 / $ppi, $ppi];
    return realpath($dest);
};
$photoIds = ['c7740ee55c47f9a59989998541ef55da', 'cd57ef7309c5211ef2f5a497b64b5acd', 'ca47d1eefe0f5073e66a86b447a6adf7'];
$photoNames = ['970-Powder-Ln_01-exterior.jpg', '970-Powder-Ln_02-living.jpg', '970-Powder-Ln_03-stair.jpg'];
$photoFiles = [];
foreach ($photoIds as $i => $id) {   // w=2048 is the board's own file, byte for byte
    $photoFiles[$i] = $fetch($photoNames[$i], 'https://monthaus.com/img.php?u=' . rawurlencode("https://photos.anpcdn.com/base/57be43_$id.jpg") . '&w=2048');
    if (!$photoFiles[$i]) exit(1);
}
$shots = __DIR__ . '/../agent-photos';
$a1f = $fetch('Jonathan-Boxer.jpg', "$shots/jonathan-boxer-30-square.jpg"); $a2f = $fetch('Scott-Weber.jpg', "$shots/scott-weber-21-square.jpg");
if (!$a1f || !$a2f) exit(1);
$listing = ['address' => '970 Powder Lane', 'city' => 'Aspen', 'beds' => 9, 'baths' => 12, 'sqft' => 9768, 'list_price' => 58000000];
$agent  = ['name' => 'Jonathan Boxer', 'phone' => '9709484802', 'headshot_url' => '', 'headshot_file' => $a1f];
$agent2 = ['name' => 'Scott Weber', 'phone' => '9709482766', 'headshot_url' => '', 'headshot_file' => $a2f];
$frame = fn(string $kind, int $p, array $more = []) => ['kind' => $kind, 'photo_url' => '', 'photo_file' => $photoFiles[$p], 'zoom' => 1, 'fx' => 50, 'fy' => 50] + $more;
$ad = ['style' => 'bleed', 'share' => ADS_SHARE_DEFAULT, 'texture' => '', 'hold' => 4, 'listing' => $listing, 'agents' => [$agent],
       'frames' => [$frame('headline', 0, ['headline' => "A Rare Ski-In Sanctuary\non Aspen Mountain"]),
                    $frame('price', 1, ['headline' => "Nine Bedrooms,\nSteps from the Slopes", 'price' => 'Offered at $58,000,000']),
                    $frame('agent', 2)]];
$pages = [['Headline', $ad, 0], ['Price', $ad, 1], ['Presented by', $ad, 2], ['Presented by, two brokers', ['agents' => [$agent, $agent2]] + $ad, 2]];
$linkOfFile = []; foreach ($links as $name => $l) $linkOfFile[$l[0]] = $name;

// ── Measuring a slide in Chromium ─────────────────────────────────────────────
const IDML_MEASURE_JS = <<<'JS'
<script>
(async function () {
  try { await document.fonts.ready; } catch (e) {}
  await Promise.all(Array.from(document.images).map(i => i.complete ? 0 : new Promise(r => { i.onload = i.onerror = r; })));
  const box = el => { const r = el.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; };
  const base = el => {   // the baseline of the element's first line: a zero-size inline-block sits on it
    const m = document.createElement('span'); m.style.cssText = 'display:inline-block;width:0;height:0;vertical-align:baseline';
    el.insertBefore(m, el.firstChild); const y = m.getBoundingClientRect().bottom; m.remove(); return y;
  };
  const type = el => { const s = getComputedStyle(el); return { font: s.fontFamily.split(',')[0].replace(/["']/g, '').trim(), size: parseFloat(s.fontSize), weight: parseInt(s.fontWeight, 10) || 400,
                         lh: parseFloat(s.lineHeight) || parseFloat(s.fontSize) * 1.2, ls: parseFloat(s.letterSpacing) || 0, color: s.color, upper: s.textTransform === 'uppercase' }; };
  const para = el => {
    const runs = [];   // a paragraph is its own text plus, on the smallest size, an inline phone span
    el.childNodes.forEach(c => {
      if (c.nodeType === 3) { const t = c.nodeValue.replace(/\s+/g, ' '); if (t) runs.push(Object.assign({ text: t }, type(el))); }   // spaces collapsed, as the browser draws them ("9 BEDS  |  12 BATHS" has two in the source)
      else if (c.tagName === 'BR') runs.push(Object.assign({ text: ' ' }, type(el)));
      else if (c.tagName === 'SPAN') runs.push(Object.assign({ text: c.textContent, gap: parseFloat(getComputedStyle(c).marginLeft) || 0 }, type(c)));
    });
    return Object.assign({ cls: el.className, baseline: base(el), lines: el.querySelectorAll('br').length + 1, runs: runs }, box(el), type(el));
  };
  const out = { frames: [], heads: [], rule: null, logo: null };
  document.querySelectorAll('.w, .ag > div:not(.rows):not(.row), .ag > .lbl, .row > div').forEach(el => {
    const ps = el.matches('p') ? [el] : Array.from(el.querySelectorAll('h1, p'));
    if (ps.length) out.frames.push(Object.assign({ paras: ps.map(para) }, box(el)));
  });
  document.querySelectorAll('.hd').forEach(el => out.heads.push(Object.assign({ src: el.getAttribute('src') }, box(el))));
  const r = document.querySelector('.rule'); if (r) out.rule = Object.assign({ color: getComputedStyle(r).backgroundColor }, box(r));
  const l = document.querySelector('.logo'); if (l) out.logo = box(l);
  document.body.setAttribute('data-geo', JSON.stringify(out));
})();
</script>
JS;

/** One slide's measurements, or null. */
function idml_measure(string $html, int $w, int $h): ?array {
    $dir = rtrim(sys_get_temp_dir(), '/') . '/mh-social';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $in = $dir . '/' . bin2hex(random_bytes(8)) . '.html';
    file_put_contents($in, str_replace('</body>', IDML_MEASURE_JS . '</body>', $html));
    $lock = fopen("$dir/.lock", 'c'); if ($lock) flock($lock, LOCK_EX);
    $cmd = 'HOME=' . escapeshellarg($dir) . ' /usr/bin/timeout 60 ' . escapeshellarg(SOCIAL_CHROMIUM)
         . ' --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --no-first-run --disable-extensions --mute-audio'
         . ' --force-device-scale-factor=1 --allow-file-access-from-files --window-size=' . max($w, 600) . ',' . max($h, 400) . ' --virtual-time-budget=10000'
         . ' --user-data-dir=' . escapeshellarg("$dir/profile") . ' --dump-dom ' . escapeshellarg('file://' . $in) . ' 2>/dev/null';
    $dom = (string)shell_exec($cmd);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    @unlink($in);
    if (!preg_match('/data-geo="([^"]*)"/', $dom, $m)) return null;
    $j = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    return is_array($j) ? $j : null;
}

// ── The pieces of a spread ───────────────────────────────────────────────────
$uid = 0; $id = function (string $p) use (&$uid) { return $p . (++$uid); };
/** A rectangle's path, page coordinates in, spread coordinates out. */
$path = function (float $l, float $t, float $w, float $h, int $H) use ($n): string {
    $y0 = $t - $H / 2; $y1 = $y0 + $h; $x1 = $l + $w; $pt = fn($px, $py) => '<PathPointType Anchor="' . $n($px) . ' ' . $n($py) . '" LeftDirection="' . $n($px) . ' ' . $n($py) . '" RightDirection="' . $n($px) . ' ' . $n($py) . '" />';
    return '<Properties><PathGeometry><GeometryPathType PathOpen="false"><PathPointArray>' . $pt($l, $y0) . $pt($l, $y1) . $pt($x1, $y1) . $pt($x1, $y0) . '</PathPointArray></GeometryPathType></PathGeometry></Properties>';
};
$item = fn(string $tag, string $self, string $name, string $ctype, string $fill, string $extra = '') =>
    '<' . $tag . ' Self="' . $self . '" ContentType="' . $ctype . '" StoryTitle="$ID/" OverriddenPageItemProps="" Visible="true" Name="' . $name . '" ItemLayer="uce" Locked="false" LocalDisplaySetting="Default"'
    . ' AppliedObjectStyle="ObjectStyle/$ID/[None]" ItemTransform="1 0 0 1 0 0" StrokeWeight="0" StrokeColor="Swatch/None" FillColor="' . $fill . '"' . $extra . '>';
$link = fn(string $self, string $name, string $format) => '<Link Self="' . $self . '" AssetURL="$ID/" AssetID="$ID/" LinkResourceURI="' . $x($linksUri . '/' . rawurlencode($name)) . '" LinkResourceFormat="$ID/' . $format . '"'
    . ' StoredState="Normal" LinkClassID="35906" LinkClientID="257" LinkResourceModified="false" LinkObjectModified="false" ShowInUI="true" CanEmbed="true" CanUnembed="true" CanPackage="true"'
    . ' ImportPolicy="NoAutoImport" ExportPolicy="NoAutoExport" LinkImportStamp="file 0 0" LinkImportModificationTime="2026-11-18T00:00:00" LinkImportTime="2026-11-18T00:00:00" LinkResourceSize="0~0" />';
/** A photo in a frame, filling it proportionally, focused at (fx, fy) per cent as object-position does. */
$image = function (string $name, float $l, float $t, float $w, float $h, int $H, string $label, float $fx = 50, float $fy = 50) use (&$links, $id, $path, $item, $link, $n): string {
    [, $pw, $ph, $ptw, $pth, $ppi] = $links[$name];
    $s = max($w / $ptw, $h / $pth);
    $tx = $l + ($w - $ptw * $s) * $fx / 100; $ty = ($t - $H / 2) + ($h - $pth * $s) * $fy / 100;
    $eff = (int)round($ppi / $s);
    return $item('Rectangle', $id('rc'), $label, 'GraphicType', 'Swatch/None') . $path($l, $t, $w, $h, $H)
         . '<Image Self="' . $id('im') . '" ItemTransform="' . $n($s) . ' 0 0 ' . $n($s) . ' ' . $n($tx) . ' ' . $n($ty) . '" Space="$ID/#Links_RGB" ActualPpi="' . (int)round($ppi) . ' ' . (int)round($ppi) . '" EffectivePpi="' . $eff . ' ' . $eff . '"'
         . ' ImageTypeName="$ID/JPEG" AppliedObjectStyle="ObjectStyle/$ID/[None]" LocalDisplaySetting="Default" Visible="true" Name="$ID/"><Properties><Profile type="string">$ID/Embedded</Profile>'
         . '<GraphicBounds Left="0" Top="0" Right="' . $n($ptw) . '" Bottom="' . $n($pth) . '" /></Properties>' . $link($id('ln'), $name, 'JPEG') . '</Image></Rectangle>';
};
/** A full-page rectangle in the scrim's ink with a Gradient Feather: stops [opacity 0..1, % along], start point and angle (0 = to the right, 90 = up, 180 = to the left). */
$feather = function (string $label, int $W, int $H, array $stops, float $sx, float $sy, float $angle, float $length) use ($id, $path, $item, $n): string {
    $self = $id('sc'); $o = '';
    foreach ($stops as $i => [$op, $loc]) $o .= '<OpacityGradientStop Self="' . $self . 'gs' . $i . '" Opacity="' . $n($op * 100) . '" Location="' . $n($loc) . '"' . ($i ? ' Midpoint="50"' : '') . ' />';
    return $item('Rectangle', $self, $label, 'Unassigned', 'Color/MH Scrim Ink') . $path(0, 0, $W, $H, $H)
         . '<TransparencySetting><GradientFeatherSetting Applied="true"' . (abs($angle) > .01 ? ' Angle="' . $n($angle) . '"' : '') . ' Length="' . $n($length) . '" GradientStart="' . $n($sx) . ' ' . $n($sy - $H / 2) . '">' . $o
         . '</GradientFeatherSetting></TransparencySetting></Rectangle>';
};
$swatch = fn(string $css) => match (true) {   // the web version's colors → the document's swatches
    (bool)preg_match('/^rgb\(255, ?255, ?255\)/', $css) => 'Color/MH White',
    (bool)preg_match('/^rgb\(214, ?222, ?232\)/', $css) => 'Color/MH Slate Light',
    (bool)preg_match('/^rgb\(174, ?187, ?204\)/', $css) => 'Color/MH Slate',
    default => 'Color/MH White',
};
$styleName = fn(string $cls) => match (true) { $cls === 'h' => 'Ad Headline', $cls === 'c' => 'Ad Details', $cls === 'p' => 'Ad Price', $cls === 'lbl' => 'Ad Label',
                                              $cls === 'nm' => 'Ad Name', $cls === 'tl' => 'Ad Phone', $cls === 'st' => 'Ad Site', default => 'Ad Details' };
$weightName = fn(int $w) => match (true) { $w >= 700 => 'Bold', $w >= 600 => 'SemiBold', $w >= 500 => 'Medium', $w >= 400 => 'Regular', default => 'Light' };

// ── One document per size ────────────────────────────────────────────────────
$only = $argv[3] ?? '';
foreach (ads_sizes() as $size => $S) {
    if ($only !== '' && $only !== $size) continue;
    $W = $S['w']; $H = $S['h']; $B = ads_bleed_sizes()[$size];
    $spreads = []; $stories = []; $styles = [];   // $styles: name => the attributes of its first use, which define it
    foreach ($pages as $pi => [$pageName, $pad, $slide]) {
        $a = ['size' => $size] + $pad;
        $g = idml_measure(ads_render_frame($a, $slide, $base, true), $W, $H);
        if (!$g) { fwrite(STDERR, "$size page " . ($pi + 1) . ": could not measure\n"); exit(1); }
        $f = $a['frames'][$slide]; $items = '';

        // 1. The photo, the whole page.
        $items .= $image($linkOfFile[$f['photo_file']], 0, 0, $W, $H, $H, 'Photo', (float)$f['fx'], (float)$f['fy']);

        // 2. The scrims: the one behind the words (to the right, or up on the rectangle), then the logo's corner.
        $stops = array_map(fn($s) => [$s[0], $s[1]], $B['scrim']);
        if (end($stops)[1] < 100) $stops[] = [end($stops)[0], 100];
        $items .= $B['mode'] === 'bottom' ? $feather('Scrim', $W, $H, $stops, $W / 2, $H, 90, $H) : $feather('Scrim', $W, $H, $stops, 0, $H, 0, $W);
        if ($B['lscrim']) {
            [$dir, $op, $reach] = $B['lscrim']; $hyp = sqrt($W * $W + $H * $H);
            // CSS "to top left" runs perpendicular to the other diagonal, over a line 2WH / hyp long, from the bottom right corner.
            [$sx, $sy, $ang, $len] = match ($dir) {
                'to left'        => [$W, $H / 2, 180.0, $W * $reach / 100],
                'to top left'    => [$W, $H, rad2deg(atan2($W, -$H)), 2 * $W * $H / $hyp * $reach / 100],
                'to bottom left' => [$W, 0, rad2deg(atan2(-$W, -$H)), 2 * $W * $H / $hyp * $reach / 100],
            };
            $items .= $feather('Scrim behind logo', $W, $H, [[$op, 0], [0, 100]], $sx, $sy, $ang, $len);
        }

        // 3. Headshots.
        $agentFiles = array_map(fn($ag) => $ag['headshot_file'], $a['agents']);
        foreach ($g['heads'] as $hi => $hd) {
            $file = $agentFiles[$hi] ?? $agentFiles[0];
            $items .= $image($linkOfFile[$file], $hd['x'], $hd['y'], $hd['w'], $hd['h'], $H, 'Headshot ' . $a['agents'][$hi]['name'], 50, 18);
        }

        // 4. The rule under the headline.
        if ($g['rule']) $items .= $item('Rectangle', $id('rl'), 'Rule', 'Unassigned', 'Color/MH Slate') . $path($g['rule']['x'], $g['rule']['y'], $g['rule']['w'], max(1, $g['rule']['h']), $H) . '</Rectangle>';

        // 5. The words: one frame per block, the first baseline fixed where the browser put it, each paragraph's
        //    leading the distance from the baseline before it.
        foreach ($g['frames'] as $fr) {
            $story = $id('st'); $ps = ''; $prev = null;
            foreach ($fr['paras'] as $k => $p) {
                $lead = $prev === null ? $p['lh'] : $p['baseline'] - $prev;
                $prev = $p['baseline'] + ($p['lines'] - 1) * $p['lh'];
                $sname = $styleName($p['cls']);
                $attrs = function (array $r, float $leading) use ($weightName, $swatch, $n): array {
                    return ['FontStyle' => $weightName($r['weight']), 'PointSize' => $n($r['size']), 'Tracking' => (string)(int)round($r['ls'] / $r['size'] * 1000), 'FillColor' => $swatch($r['color']),
                            'Capitalization' => $r['upper'] ? 'AllCaps' : 'Normal', 'OTFFigureStyle' => str_contains($r['font'], 'Cormorant') ? 'ProportionalLining' : 'Default',
                            'AppliedFont' => $r['font'], 'Leading' => $n($leading)];
                };
                $pa = $attrs($p, $lead);
                $styles[$sname] ??= $pa;
                $runs = '';
                foreach ($p['runs'] as $ri => $r) {
                    $ra = $attrs($r, $lead); $over = ''; $props = '';
                    foreach ($ra as $key => $v) {   // only what differs from the paragraph style is written on the run
                        if ($v === $styles[$sname][$key]) continue;
                        if ($key === 'AppliedFont') $props .= '<AppliedFont type="string">' . $x($v) . '</AppliedFont>';
                        elseif ($key === 'Leading') $props .= '<Leading type="unit">' . $v . '</Leading>';
                        else $over .= ' ' . $key . '="' . $x($v) . '"';
                    }
                    $text = !empty($r['gap']) ? '  ' . $r['text'] : $r['text'];   // the inline phone's gap, as two spaces
                    $runs .= '<CharacterStyleRange AppliedCharacterStyle="CharacterStyle/$ID/[No character style]"' . $over . '>' . ($props !== '' ? '<Properties>' . $props . '</Properties>' : '')
                           . '<Content>' . $x($text) . '</Content>' . ($k < count($fr['paras']) - 1 && $ri === count($p['runs']) - 1 ? '<Br />' : '') . '</CharacterStyleRange>';
                }
                $ps .= '<ParagraphStyleRange AppliedParagraphStyle="ParagraphStyle/' . $x($sname) . '">' . $runs . '</ParagraphStyleRange>';
            }
            $stories[$story] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<idPkg:Story xmlns:idPkg="http://ns.adobe.com/AdobeInDesign/idml/1.0/packaging" DOMVersion="21.4"><Story Self="' . $story . '" UserText="true" IsEndnoteStory="false"'
                . ' AppliedTOCStyle="n" TrackChanges="false" StoryTitle="$ID/" AppliedNamedGrid="n"><StoryPreference OpticalMarginAlignment="false" OpticalMarginSize="12" FrameType="TextFrameType" StoryOrientation="Horizontal"'
                . ' StoryDirection="LeftToRightDirection" /><InCopyExportOption IncludeGraphicProxies="true" IncludeAllResources="false" />' . $ps . '</Story></idPkg:Story>';
            // The frame: as wide as its block, with room to the right for a longer name (never under the logo), and a little below.
            $first = $fr['paras'][0];
            $isWords = count($fr['paras']) && in_array($first['cls'], ['h'], true);
            $right = $W - 8;
            if ($g['logo'] && $fr['y'] < $g['logo']['y'] + $g['logo']['h'] && $fr['y'] + $fr['h'] > $g['logo']['y'] && $g['logo']['x'] > $fr['x']) $right = $g['logo']['x'] - 8;
            $fw = $isWords ? $fr['w'] : max($fr['w'], min($right - $fr['x'], $fr['w'] * 1.5 + 20));
            $items .= '<TextFrame Self="' . $id('tf') . '" ParentStory="' . $story . '" PreviousTextFrame="n" NextTextFrame="n" ContentType="TextType" OverriddenPageItemProps="" Visible="true" Name="' . ($isWords ? 'Words' : 'Presented by') . '"'
                    . ' ItemLayer="uce" Locked="false" LocalDisplaySetting="Default" AppliedObjectStyle="ObjectStyle/$ID/[Normal Text Frame]" ItemTransform="1 0 0 1 0 0" StrokeWeight="0" StrokeColor="Swatch/None" FillColor="Swatch/None">'
                    . $path($fr['x'], $fr['y'], $fw, $fr['h'] + max(4, $first['size'] * .3), $H)
                    . '<TextFramePreference TextColumnCount="1" VerticalJustification="TopAlign" AutoSizingType="Off" FirstBaselineOffset="FixedHeight" MinimumFirstBaselineOffset="' . $n($first['baseline'] - $fr['y']) . '">'
                    . '<Properties><InsetSpacing type="list"><ListItem type="unit">0</ListItem><ListItem type="unit">0</ListItem><ListItem type="unit">0</ListItem><ListItem type="unit">0</ListItem></InsetSpacing></Properties></TextFramePreference></TextFrame>';
        }

        // 6. The logo: her vector file, MH-stack-logo_white_1.ai (artboard 248.871 x 183.175 pt), as tall as the web logo and flush with its right edge.
        if ($g['logo']) {
            $lw = 248.871; $lh = 183.175; $s = $g['logo']['h'] / $lh; $l = $g['logo']['x'] + $g['logo']['w'] - $lw * $s; $t = $g['logo']['y'];
            $items .= $item('Rectangle', $id('lg'), 'Logo', 'GraphicType', 'Swatch/None') . $path($l, $t, $lw * $s, $lh * $s, $H)
                    . '<PDF Self="' . $id('pd') . '" ItemTransform="' . $n($s) . ' 0 0 ' . $n($s) . ' ' . $n($l) . ' ' . $n($t - $H / 2) . '" ImageTypeName="$ID/Adobe Portable Document Format (PDF)" AppliedObjectStyle="ObjectStyle/$ID/[None]"'
                    . ' LocalDisplaySetting="Default" Visible="true" Name="$ID/"><Properties><GraphicBounds Left="0" Top="0" Right="' . $lw . '" Bottom="' . $lh . '" /></Properties>'
                    . '<PDFAttribute PageNumber="1" PDFCrop="CropContentVisibleLayers" TransparentBackground="true" />' . $link($id('ln'), 'MH-stack-logo_white_1.ai', 'Adobe Portable Document Format (PDF)') . '</PDF></Rectangle>';
        }

        $sp = $pi === 0 ? 'ud1' : $id('sp'); $pg = $pi === 0 ? 'ud6' : $id('pg');   // the skeleton's section starts at page ud6
        $spreads[$sp] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<idPkg:Spread xmlns:idPkg="http://ns.adobe.com/AdobeInDesign/idml/1.0/packaging" DOMVersion="21.4"><Spread Self="' . $sp . '" PageTransitionType="None"'
            . ' PageTransitionDirection="NotApplicable" PageTransitionDuration="Medium" ShowMasterItems="true" PageCount="1" BindingLocation="0" SpreadHidden="false" AllowPageShuffle="true" ItemTransform="1 0 0 1 0 0" FlattenerOverride="Default">'
            . '<Page Self="' . $pg . '" TabOrder="" AppliedMaster="n" OverrideList="" MasterPageTransform="1 0 0 1 0 0" Name="' . ($pi + 1) . '" AppliedTrapPreset="TrapPreset/$ID/kDefaultTrapStyleName" GeometricBounds="0 0 ' . $H . ' ' . $W . '"'
            . ' ItemTransform="1 0 0 1 0 ' . $n(-$H / 2) . '" AppliedAlternateLayout="ud7" LayoutRule="UseMaster" SnapshotBlendingMode="IgnoreLayoutSnapshots" OptionalPage="false" GridStartingPoint="TopOutside" UseMasterGrid="true">'
            . '<Properties><PageColor type="enumeration">UseMasterColor</PageColor></Properties><MarginPreference ColumnCount="1" ColumnGutter="12" Top="0" Bottom="0" Left="0" Right="0" ColumnDirection="Horizontal" ColumnsPositions="0 ' . $W . '" /></Page>'
            . $items . '</Spread></idPkg:Spread>';
    }

    // ── The package: the skeleton's files, adapted, plus the spreads and stories ──
    $name = "MH_FullBleed_$size";
    $get = fn(string $f) => (string)file_get_contents("$skel/$f");
    $files = ['META-INF/container.xml' => $get('META-INF/container.xml'), 'XML/Tags.xml' => $get('XML/Tags.xml'), 'XML/BackingStory.xml' => $get('XML/BackingStory.xml')];
    // The parent (master) pages: the skeleton's are Letter with 36 pt margins. At the ad's size with none, so a page she adds is
    // the right size (and a 50 px page is not shorter than its parent's margins).
    $ms = $get('MasterSpreads/MasterSpread_ud8.xml');
    $ms = str_replace('GeometricBounds="0 0 792 612"', 'GeometricBounds="0 0 ' . $H . ' ' . $W . '"', $ms);
    $ms = str_replace(['ItemTransform="1 0 0 1 -612 -396"', 'ItemTransform="1 0 0 1 0 -396"'], ['ItemTransform="1 0 0 1 -' . $W . ' ' . $n(-$H / 2) . '"', 'ItemTransform="1 0 0 1 0 ' . $n(-$H / 2) . '"'], $ms);
    $ms = str_replace('Top="36" Bottom="36" Left="36" Right="36" ColumnDirection="Horizontal" ColumnsPositions="0 540"', 'Top="0" Bottom="0" Left="0" Right="0" ColumnDirection="Horizontal" ColumnsPositions="0 ' . $W . '"', $ms);
    if (str_contains($ms, '792') || str_contains($ms, 'Top="36"')) { fwrite(STDERR, "skeleton master spread is not the one this script knows\n"); exit(1); }
    $files['MasterSpreads/MasterSpread_ud8.xml'] = $ms;
    // Preferences: the page size, and RGB blending so the feathered scrim looks as it does on the web. PagesPerDocument stays 1:
    // the spreads bring their own pages, and 4 here made InDesign add three blank ones in front (2026-11-18).
    $pref = $get('Resources/Preferences.xml');
    $pref = preg_replace(['/PageHeight="[^"]*"/', '/PageWidth="[^"]*"/', '/PagesPerDocument="[^"]*"/', '/BlendingSpace="CMYK"/'], ['PageHeight="' . $H . '"', 'PageWidth="' . $W . '"', 'PagesPerDocument="1"', 'BlendingSpace="RGB"'], $pref, 1);
    // The document's default margins are 36 pt each way: more than a 50 px page is tall, and InDesign then reported the 320 x 50
    // document as 15552 pt high. No margins on any size.
    $pref = preg_replace('/(<MarginPreference [^>]*?)Top="[^"]*" Bottom="[^"]*" Left="[^"]*" Right="[^"]*"/', '$1Top="0" Bottom="0" Left="0" Right="0"', $pref, 1);
    $files['Resources/Preferences.xml'] = $pref;
    // Graphic: three swatches after MH White, cloned from it.
    $gr = $get('Resources/Graphic.xml');
    if (!preg_match('/<Color Self="Color\/MH White"[^>]*\/>/', $gr, $m)) { fwrite(STDERR, "skeleton has no MH White swatch\n"); exit(1); }
    $more = '';
    foreach (['MH Scrim Ink' => '22 26 33', 'MH Slate Light' => '214 222 232', 'MH Slate' => '174 187 204'] as $cn => $cv) $more .= "\n\t" . str_replace(['MH White', '255 255 255'], [$cn, $cv], $m[0]);
    $files['Resources/Graphic.xml'] = str_replace($m[0], $m[0] . $more, $gr);
    // Fonts: Cormorant Garamond SemiBold beside Medium (the small sizes use it).
    $fo = $get('Resources/Fonts.xml');
    if (preg_match('/<Font Self="ff41FontCormorantGaramond-Medium"[^>]*\/>/', $fo, $m)) $fo = str_replace($m[0], $m[0] . "\n\t\t" . str_replace('Medium', 'SemiBold', $m[0]), $fo);
    $files['Resources/Fonts.xml'] = $fo;
    // Styles: the seven paragraph styles, each defined by its first use in this document.
    $ps = '';
    foreach ($styles as $sn => $at) {
        $ps .= "\n\t\t" . '<ParagraphStyle Self="ParagraphStyle/' . $x($sn) . '" Name="' . $x($sn) . '" FillColor="' . $at['FillColor'] . '" FontStyle="' . $at['FontStyle'] . '" PointSize="' . $at['PointSize'] . '" Tracking="' . $at['Tracking'] . '"'
             . ' Capitalization="' . $at['Capitalization'] . '" OTFFigureStyle="' . $at['OTFFigureStyle'] . '" Justification="LeftAlign" Hyphenation="false"><Properties><BasedOn type="string">$ID/[No paragraph style]</BasedOn>'
             . '<AppliedFont type="string">' . $x($at['AppliedFont']) . '</AppliedFont><Leading type="unit">' . $at['Leading'] . '</Leading></Properties></ParagraphStyle>';
    }
    $st = $get('Resources/Styles.xml');
    if (!str_contains($st, '</RootParagraphStyleGroup>')) { fwrite(STDERR, "skeleton Styles.xml has no paragraph style group\n"); exit(1); }
    $files['Resources/Styles.xml'] = preg_replace('/\s*<\/RootParagraphStyleGroup>/', $ps . "\n\t" . '</RootParagraphStyleGroup>', $st, 1);
    // designmap: the stories and spreads listed, the section as long as the document.
    $dm = $get('designmap.xml');
    $dm = preg_replace('/StoryList="[^"]*"/', 'StoryList="' . implode(' ', array_keys($stories)) . '"', $dm, 1);
    $dm = str_replace('Name="display_template.indd"', 'Name="' . $name . '.indd"', $dm);
    $dm = preg_replace('/(<Section Self="ud7" )Length="1"/', '$1Length="' . count($pages) . '"', $dm, 1);
    $dm = preg_replace('/AlternateLayoutLength="1"/', 'AlternateLayoutLength="' . count($pages) . '"', $dm, 1);
    $dm = preg_replace('/\s*<idPkg:Story src="Stories\/[^"]*" \/>/', '', $dm);
    $sl = ''; foreach (array_keys($spreads) as $sp) $sl .= "\n\t" . '<idPkg:Spread src="Spreads/Spread_' . $sp . '.xml" />';
    $dm = preg_replace('/\s*<idPkg:Spread src="Spreads\/Spread_ud1\.xml" \/>/', $sl, $dm, 1);
    $tl = ''; foreach (array_keys($stories) as $sk) $tl .= "\n\t" . '<idPkg:Story src="Stories/Story_' . $sk . '.xml" />';
    $dm = preg_replace('/(<idPkg:BackingStory src="XML\/BackingStory\.xml" \/>)/', '$1' . $tl, $dm, 1);
    $files['designmap.xml'] = $dm;
    foreach ($spreads as $sp => $xml) $files["Spreads/Spread_$sp.xml"] = $xml;
    foreach ($stories as $sk => $xml) $files["Stories/Story_$sk.xml"] = $xml;

    // Every part must be well-formed XML before it is zipped.
    foreach ($files as $fn => $xml) { $d = new DOMDocument(); if (!@$d->loadXML($xml)) { fwrite(STDERR, "$size: $fn is not well-formed\n"); exit(1); } }
    $zipPath = "$out/$name.idml"; @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "cannot write $zipPath\n"); exit(1); }
    $zip->addFromString('mimetype', 'application/vnd.adobe.indesign-idml-package'); $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);   // first, and stored
    foreach ($files as $fn => $xml) $zip->addFromString($fn, $xml);
    $zip->close();
    printf("%s.idml  %d pages, %d stories, styles: %s\n", $name, count($spreads), count($stories), implode(', ', array_keys($styles)));
}
echo "Links: " . implode(', ', array_keys($links)) . " (+ MH-stack-logo_white_1.ai, copied by hand)\n";
