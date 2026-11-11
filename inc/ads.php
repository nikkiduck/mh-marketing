<?php
/**
 * inc/ads.php — Digital Ads (PROTOTYPE, Nikki, 2026-11-09), super admin
 * only, on the Social Media Plan's foundation (inc/plan.php: the gate, the
 * listings in the feed, the approved brokers, the photo tiles and the photo
 * check) with its own renderer (inc/ads_render.php) and its own exports:
 *
 *   template_ad.php    the page: the slides previewed at the top, the controls
 *                      under them (inc/ads_body.php)
 *   ad_render.php      POST → one slide as HTML, for a preview iframe
 *   ad_export.php      POST → the animated GIF (under ADS_GIF_MAX, colours
 *                      dropped until it fits), or a ZIP of the slides as 2x
 *                      JPGs for Photoshop (Nikki: "so if I choose I can port
 *                      into Photoshop for gif creation"); ?probe=1 → JSON with
 *                      the GIF's byte count and colours, for the size readout
 *   plan_photos.php    reused as is for the listing's photos (?kind=property)
 *
 * The GIF: Chromium screenshots each slide (ads_export_png(), the Instagram
 * export's command with the ad's window size and a device scale factor), then
 * Imagick quantises each frame, sets the hold, loops for ever and trims the
 * frames to what changed (optimizeImageLayers). A newspaper's cap is 1 MB,
 * so the export tries 256 colours, then fewer, and reports what it landed on.
 */
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/ads_render.php';
require_once __DIR__ . '/ads_export.php';   // Chromium + Imagick: the GIF and the slides (standalone, so the harness can make a real GIF)

const ADS_MAX_FRAMES = 3;

/** Every approved broker's phone as the ads print it ("970.274.1497"): the profile's cell, else the MLS phone. Cached per request. */
function ads_agent_phones(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $cols = ['id'];
    foreach (['cell_phone', 'mls_phone'] as $c) if (mk_column_exists($conn, 'marketing_intakes', $c)) $cols[] = $c;
    if (count($cols) === 1) return $cache;
    $r = $conn->query("SELECT " . implode(', ', $cols) . " FROM marketing_intakes WHERE is_active = 1");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $row) {
        $p = trim((string)($row['cell_phone'] ?? '')) ?: trim((string)($row['mls_phone'] ?? ''));
        if ($p !== '') $cache[(int)$row['id']] = ads_phone_text($p);
    }
    return $cache;
}

/** The roster broker a listing's agent words name (the first side), by name, or 0. */
function ads_agent_for_listing(array $l, array $agents): int {
    $words = strtolower((string)($l['agent'] ?? ''));
    if ($words === '') return 0;
    foreach ($agents as $a) if (str_contains($words, strtolower($a['name']))) return (int)$a['id'];
    return 0;
}

/** A short file-name slug from the listing's address: "1100-N-Frontage-Rd-W-1513". */
function ads_slug(string $s): string {
    $s = social_short_address($s);
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'ad';
}

/**
 * The form → the normalised ad, or a sentence saying what is missing. Every
 * posted value is checked: the listing is one in the feed, the broker one on
 * the approved roster, each slide's photo one the site listed for that
 * listing (plan_photo()), the texture one of ads_textures().
 */
function ads_from_request(mysqli $conn, array $ctx, array $in): array|string {
    $size = isset(ads_sizes()[(string)($in['size'] ?? '')]) ? (string)$in['size'] : '970x250';
    $listing = plan_listing($conn, (string)($in['listing'] ?? ''));
    if (!$listing) return 'Choose a listing to see the ad.';
    $roster = plan_agents($conn);
    $agent  = $roster[(int)($in['agent'] ?? 0)] ?? null;
    $agent2 = $roster[(int)($in['agent2'] ?? 0)] ?? null;   // a second broker on the Presented by slide (Nikki, 2026-11-09)
    if ($agent2 && $agent && $agent2['id'] === $agent['id']) $agent2 = null;
    if (!$agent && $agent2) { $agent = $agent2; $agent2 = null; }
    $share = isset(ads_shares()[(string)($in['share'] ?? '')]) ? (string)$in['share'] : ADS_SHARE_DEFAULT;

    $texture = (string)($in['texture'] ?? ADS_TEXTURE_DEFAULT);
    if (!isset(ads_textures()[$texture])) $texture = ADS_TEXTURE_DEFAULT;
    $hold = max(2.0, min(8.0, round((float)($in['hold'] ?? 4), 1)));
    $prefix = preg_replace('/[^A-Za-z0-9]+/', '', (string)($in['prefix'] ?? 'MH'));
    $prefix = $prefix !== '' ? mb_substr($prefix, 0, 12) : 'MH';

    $h1 = mb_substr(trim(str_replace("\r", '', (string)($in['headline'] ?? ''))), 0, 90);
    $h2 = mb_substr(trim(str_replace("\r", '', (string)($in['headline2'] ?? ''))), 0, 90);
    $priceIn = mb_substr(trim((string)($in['price'] ?? '')), 0, 40);
    $price = $priceIn !== '' ? $priceIn : (string)($listing['price_text'] ?? '');
    if ($price !== '' && $priceIn === '' && !empty($listing['closed']) && $listing['close_price'] !== null) $price = social_price_text($listing['close_price']);

    // The slides: the headline slide always; the price slide and the Presented by slide when ticked (the latter needs a broker).
    $kinds = ['headline'];
    if (!empty($in['frame_price'])) $kinds[] = 'price';
    if (!empty($in['frame_agent']) && $agent) $kinds[] = 'agent';

    // Each slide's photo and framing are posted BY KIND (photo[headline], zoom[price], …), so a slide keeps its photo
    // when another slide is switched off.
    // The sizes with a photo either side of the panel (970 x 90, the Impact) take a second one per slide: photo2[kind] …
    $photos = (array)($in['photo'] ?? []); $zooms = (array)($in['zoom'] ?? []); $fxs = (array)($in['fx'] ?? []); $fys = (array)($in['fy'] ?? []);
    $photos2 = (array)($in['photo2'] ?? []); $zooms2 = (array)($in['zoom2'] ?? []); $fxs2 = (array)($in['fx2'] ?? []); $fys2 = (array)($in['fy2'] ?? []);
    $frames = [];
    foreach ($kinds as $kind) {
        [$url, $file] = plan_photo($ctx, (string)($photos[$kind] ?? ''), $listing);
        [$url2, $file2] = (string)($photos2[$kind] ?? '') !== '' ? plan_photo($ctx, (string)$photos2[$kind], $listing) : ['', null];   // blank = the first photo again
        $frames[] = [
            'kind' => $kind, 'photo_url' => $url, 'photo_file' => $file,
            'zoom' => max(1.0, min(3.0, (float)($zooms[$kind] ?? 1))), 'fx' => max(0, min(100, (int)($fxs[$kind] ?? 50))), 'fy' => max(0, min(100, (int)($fys[$kind] ?? 50))),
            'photo2_url' => $url2, 'photo2_file' => $file2,
            'zoom2' => max(1.0, min(3.0, (float)($zooms2[$kind] ?? 1))), 'fx2' => max(0, min(100, (int)($fxs2[$kind] ?? 50))), 'fy2' => max(0, min(100, (int)($fys2[$kind] ?? 50))),
            'headline' => $kind === 'price' ? $h2 : ($kind === 'headline' ? $h1 : ''),
            'price' => $kind === 'price' ? $price : '',
        ];
    }

    $ags = [];
    foreach ([$agent, $agent2] as $x) {
        if (!$x) continue;
        $u = $x['face_url'] !== '' ? $x['face_url'] : $x['headshot_url'];
        $f = $u !== '' ? mk_photo_file($u) : '';
        $ags[] = ['id' => $x['id'], 'name' => $x['name'], 'phone' => ads_agent_phones($conn)[$x['id']] ?? '',
                  'headshot_url' => $u, 'headshot_file' => ($f !== '' && is_file($f)) ? (realpath($f) ?: $f) : null];
    }
    $preview = max(0, min(count($frames) - 1, (int)($in['frame'] ?? 0)));
    return [
        'size' => $size, 'share' => $share, 'texture' => $texture, 'hold' => $hold, 'prefix' => $prefix,
        'listing' => $listing, 'agents' => $ags, 'frames' => $frames, 'preview' => $preview,
        'name_base' => $prefix . '_' . ads_slug((string)$listing['address']),
        'name' => $prefix . '_' . ads_slug((string)$listing['address']) . '_' . $size,
    ];
}

