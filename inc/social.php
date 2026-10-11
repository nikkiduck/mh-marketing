<?php
/**
 * inc/social.php — Social posts on the agent portal (Nikki, 2026-10-11).
 *
 * An agent picks a template (Featured Listing, Open House), one of THEIR
 * listings, one of its photos, checks the pre-filled words, and downloads an
 * Instagram-ready PNG (1080 x 1440). The agent posts it themselves; nothing
 * here talks to Instagram.
 *
 *   portal/social.php          the page (steps + live preview)
 *   portal/social_render.php   POST → the post as HTML, for the preview frame
 *   portal/social_export.php   POST → the same HTML screenshotted by headless
 *                              Chromium (installed 2026-10-11), as a PNG download
 *   portal/social_photos.php   GET  → the listing's photos (from the site)
 *   inc/social_render.php      the templates themselves (standalone, no DB)
 *
 * Who may use it: SOCIAL_BETA_EMAILS (inc/config.php). While the list is set,
 * anyone else gets a plain 404, nothing in the nav, as if the page did not
 * exist (Nikki, 2026-10-11: hidden while it is being built). Empty = open to
 * every portal login.
 *
 * Whose listings: hs_listing_state rows (the site's Mont Haus feed, synced
 * hourly) whose list or co-list agent id is one of the account's board
 * identities in agent_mls_ids (team aliases such as WebBoxGrp included) or
 * whose agent name is the account's, live statuses only, cross-board copies
 * collapsed. Photos: the site's api/listings.php?scope=photos, through its
 * img.php proxy; the list is kept in the session so a posted photo URL is
 * checked against it (never rendered from a free URL).
 *
 * The render is ONE path: social_render() in inc/social_render.php makes the
 * preview and the export from the same normalised post. Chromium runs as
 * www-data with a throwaway profile under the system temp dir, one render at
 * a time (flock: the box has under 1 GB), 60 s cap. A copy of every export is
 * kept in CREATIVES_DIR/social/<intake id>/ for a later "your recent posts".
 */
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/social_render.php';

if (!defined('SOCIAL_CHROMIUM')) define('SOCIAL_CHROMIUM', '/usr/bin/chromium');

/** True when this sign-in may see Social posts (SOCIAL_BETA_EMAILS, or everyone when it is empty). */
function social_allowed(): bool {
    if (!defined('SOCIAL_BETA_EMAILS') || trim((string)SOCIAL_BETA_EMAILS) === '') return true;
    $me = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
    $list = array_filter(array_map('trim', explode(',', strtolower((string)SOCIAL_BETA_EMAILS))));
    return $me !== '' && in_array($me, $list, true);
}

/** A plain 404 for everyone not on the list: the page does not exist for them. Call after portal_context(). */
function social_gate(): void {
    if (social_allowed()) return;
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>404 Not Found</title>\n</head><body>\n<h1>Not Found</h1>\n<p>The requested URL was not found on this server.</p>\n</body></html>\n";
    exit;
}

/** The account's name, title, phone and square headshot for the post's agent row. */
function social_agent(mysqli $conn, array $ctx): array {
    $id   = (int)$ctx['acct']['id'];
    $cols = 'agent_name, headshot_face_url, headshot_url'
          . (mk_column_exists($conn, 'marketing_intakes', 'agent_title') ? ', agent_title' : ", '' AS agent_title")
          . (mk_column_exists($conn, 'marketing_intakes', 'cell_phone')  ? ', cell_phone'  : ", '' AS cell_phone");
    $st = $conn->prepare("SELECT {$cols} FROM marketing_intakes WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $a = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    $title = trim((string)strtok((string)($a['agent_title'] ?? ''), "\r\n"));   // "CEO\nEmploying Broker": first line
    return [
        'name'     => trim((string)($a['agent_name'] ?? '')),
        'title'    => $title,
        'phone'    => social_phone_text((string)($a['cell_phone'] ?? '')),
        'headshot' => trim((string)($a['headshot_face_url'] ?? '')) ?: trim((string)($a['headshot_url'] ?? '')),
    ];
}

/** The public site's resized copy of a board photo (img.php: 400 / 800 / 1600). */
function social_img(string $raw, int $w): string {
    $base = defined('PUBLIC_SITE_URL') ? rtrim(PUBLIC_SITE_URL, '/') : 'https://site.monthaus.com';
    return $base . '/img.php?u=' . rawurlencode($raw) . '&w=' . $w;
}

/**
 * The account's live listings, keyed "market|listing_key", with the words the
 * form pre-fills (price_text, specs_text, subline) and a thumbnail.
 */
function social_listings(mysqli $conn, array $ctx): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!mk_table_exists($conn, 'hs_listing_state')) return $cache;

    $ids = implode(',', array_map('intval', $ctx['ids']));   // from portal_context, never the request
    $conds = []; $types = ''; $vals = [];
    $r = $conn->query("SELECT DISTINCT market, mls_agent_id FROM agent_mls_ids WHERE intake_id IN ({$ids}) AND mls_agent_id <> ''");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $i) {
        $conds[] = '(market = ? AND (list_agent_mls_id = ? OR colist_agent_mls_id = ?))';
        $types  .= 'sss';
        array_push($vals, $i['market'], $i['mls_agent_id'], $i['mls_agent_id']);
    }
    foreach ($ctx['scopes'] as $s) {
        $n = strtolower(trim((string)$s['name']));
        if ($n === '') continue;
        $conds[] = '(LOWER(list_agent_name) = ? OR LOWER(colist_agent_name) = ?)';
        $types  .= 'ss';
        array_push($vals, $n, $n);
    }
    if (!$conds) return $cache;

    $st = $conn->prepare("SELECT market, listing_key, mls_id, status, is_rental, property_type, address, city,
                                 list_price, beds, baths, sqft, primary_photo
                            FROM hs_listing_state
                           WHERE in_feed = 1 AND status IN ('Active','Active Under Contract','Pending','Coming Soon')
                             AND (" . implode(' OR ', $conds) . ")
                           ORDER BY (market = 'recolorado'), list_price DESC, address");
    $st->bind_param($types, ...$vals);
    $st->execute();
    $seen = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
        // One property on two boards is one choice here (the local board's copy sorts first).
        $k = strtolower(preg_replace('/\s+/', ' ', trim((string)$l['address']))) . '|' . strtolower(trim((string)$l['city'])) . '|' . (int)$l['is_rental'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $id = $l['market'] . '|' . $l['listing_key'];
        $cache[$id] = $l + [
            'id'         => $id,
            'price_text' => social_price_text($l['list_price']),
            'specs_text' => social_specs_text($l['beds'], $l['baths'], $l['sqft']),
            'subline'    => trim((string)$l['city']) !== '' ? trim((string)$l['city']) . ', Colorado' : '',
            'thumb'      => (string)$l['primary_photo'] !== '' ? social_img((string)$l['primary_photo'], 400) : '',
        ];
    }
    $st->close();
    return $cache;
}

/** One of the account's listings by "market|listing_key", or null. */
function social_listing(mysqli $conn, array $ctx, string $id): ?array {
    return social_listings($conn, $ctx)[$id] ?? null;
}

/**
 * The listing's photos from the site (raw board URLs, primary first), kept in
 * the session (last 12 listings) so a posted choice can be checked.
 */
function social_photos(string $market, string $key): array {
    $sk = $market . '|' . $key;
    if (!defined('LISTINGS_FEED_URL') || !defined('LISTINGS_FEED_TOKEN') || (string)LISTINGS_FEED_TOKEN === '') return [];
    $ch = curl_init(LISTINGS_FEED_URL . '?scope=photos&market=' . rawurlencode($market) . '&key=' . rawurlencode($key));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . LISTINGS_FEED_TOKEN, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = ($code === 200 && $body) ? json_decode($body, true) : null;
    $urls = [];
    foreach ((array)($j['photos'] ?? []) as $p) {
        $u = (string)($p['url'] ?? '');
        if ($u !== '' && !in_array($u, $urls, true)) $urls[] = $u;
    }
    if ($urls) {
        $all = (array)($_SESSION['social_photos'] ?? []);
        unset($all[$sk]);
        $all[$sk] = $urls;
        $_SESSION['social_photos'] = array_slice($all, -12, null, true);
    }
    return $urls;
}

/**
 * The posted form as a normalised post for social_render(), or a message
 * (string) when there is nothing to render yet. Everything is checked here:
 * the template name, the listing (one of the account's), the photo (one the
 * site listed for that listing, else its primary), lengths, dates.
 */
function social_post_from_request(mysqli $conn, array $ctx, array $in): array|string {
    $types = social_templates();
    $type  = (string)($in['type'] ?? 'featured');
    if (!isset($types[$type])) $type = 'featured';

    $l = social_listing($conn, $ctx, (string)($in['listing'] ?? ''));
    if (!$l) return 'Choose one of your listings to see your post.';

    $photo   = (string)($in['photo'] ?? '');
    $allowed = (array)($_SESSION['social_photos'][$l['id']] ?? []);
    if ((string)$l['primary_photo'] !== '') $allowed[] = (string)$l['primary_photo'];
    if (!in_array($photo, $allowed, true)) $photo = (string)$l['primary_photo'];

    $txt = fn(string $k, int $max, string $default = '') => mb_substr(trim((string)($in[$k] ?? $default)), 0, $max);
    $agent = social_agent($conn, $ctx);
    $label = (string)($in['label'] ?? '');
    if (!in_array($label, social_labels(), true)) $label = social_labels()[0];

    $p = [
        'type'          => $type,
        'listing'       => $l,
        'label'         => $label,
        'photo'         => $photo,
        'photo_url'     => $photo !== '' ? social_img($photo, 1600) : '',
        'focus_x'       => max(0, min(100, (int)($in['focus_x'] ?? 50))),
        'focus_y'       => max(0, min(100, (int)($in['focus_y'] ?? 50))),
        'headline'      => $txt('headline', 80, (string)$l['address']),
        'subline'       => $txt('subline', 80, (string)$l['subline']),
        'price'         => $txt('price', 40, (string)$l['price_text']),
        'specs'         => $txt('specs', 80, (string)$l['specs_text']),
        'note'          => $txt('note', 90),
        'date_text'     => '',
        'time_text'     => '',
        'agent'         => [
            'name'     => $txt('agent_name', 60, $agent['name']),
            'title'    => $txt('agent_title', 60, $agent['title']),
            'phone'    => $txt('agent_phone', 30, $agent['phone']),
            'headshot' => $agent['headshot'],
        ],
        'show_headshot' => !isset($in['has_headshot_box']) || !empty($in['show_headshot']),
    ];
    if ($type === 'openhouse') {
        $p['date_text'] = social_date_text((string)($in['oh_date'] ?? ''));
        $p['time_text'] = social_time_range_text((string)($in['oh_start'] ?? ''), (string)($in['oh_end'] ?? ''));
        if ($p['date_text'] === '') return 'Pick the open house date to see your post.';
    }
    return $p;
}

/** "633-w-main-street-open-house-20261018-1432.png" */
function social_filename(array $p): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$p['headline']), '-')) ?: 'post';
    $kind = $p['type'] === 'openhouse' ? 'open-house' : strtolower(str_replace(' ', '-', (string)$p['label']));
    return mb_substr($slug, 0, 60) . '-' . $kind . '-' . date('Ymd-Hi') . '.png';
}

/**
 * The post as PNG bytes: the HTML written to a private temp file and
 * screenshotted by headless Chromium at exactly SOCIAL_W x SOCIAL_H. Null when
 * Chromium failed (logged to the PHP error log with its last lines).
 */
function social_export_png(string $html): ?string {
    $dir = rtrim(sys_get_temp_dir(), '/') . '/mh-social';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { error_log('social_export: cannot create ' . $dir); return null; }
    $id  = bin2hex(random_bytes(8));
    $in  = "{$dir}/{$id}.html";
    $out = "{$dir}/{$id}.png";
    if (file_put_contents($in, $html) === false) { error_log('social_export: cannot write ' . $in); return null; }

    $lock = fopen("{$dir}/.lock", 'c');
    if ($lock) flock($lock, LOCK_EX);        // one Chromium at a time: the box has under 1 GB
    set_time_limit(120);
    $cmd = 'HOME=' . escapeshellarg($dir) . ' /usr/bin/timeout 60 ' . escapeshellarg(SOCIAL_CHROMIUM)
         . ' --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --no-first-run'
         . ' --disable-extensions --mute-audio --force-device-scale-factor=1'
         . ' --window-size=' . SOCIAL_W . ',' . SOCIAL_H . ' --virtual-time-budget=10000'
         . ' --user-data-dir=' . escapeshellarg("{$dir}/profile")
         . ' --screenshot=' . escapeshellarg($out) . ' ' . escapeshellarg('file://' . $in) . ' 2>&1';
    exec($cmd, $lines, $rc);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }

    $png = is_file($out) ? file_get_contents($out) : false;
    @unlink($in);
    @unlink($out);
    if ($png === false || $png === '') {
        error_log('social_export: chromium rc=' . $rc . ' ' . implode(' | ', array_slice($lines, -3)));
        return null;
    }
    return $png;
}

/** Keep a copy of an export under CREATIVES_DIR/social/<intake id>/ (best effort). */
function social_keep_copy(array $ctx, string $name, string $png): void {
    if (!defined('CREATIVES_DIR')) return;
    $dir = rtrim(CREATIVES_DIR, '/') . '/social/' . (int)$ctx['acct']['id'];
    if (!is_dir($dir) && !@mkdir($dir, 02775, true)) return;
    @file_put_contents($dir . '/' . $name, $png);
    @chmod($dir . '/' . $name, 0664);
}
