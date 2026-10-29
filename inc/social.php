<?php
/**
 * inc/social.php — Social posts on the agent portal (Nikki, 2026-10-11;
 * reworked 2026-10-12 to her notes).
 *
 * An agent picks a template (Featured Listing, Open House) and a look (dark,
 * light), one of THEIR listings from a dropdown, a photo (the listing's, or
 * one they upload), frames it (drag + zoom), checks the words (a headline and
 * up to four sub lines, each in a style), and downloads an Instagram-ready
 * PNG (1080 x 1440). The agent posts it themselves; nothing here talks to
 * Instagram.
 *
 *   portal/social.php          the page (steps + live preview)
 *   portal/social_render.php   POST → the post as HTML, for the preview frame
 *   portal/social_export.php   POST → the same HTML screenshotted by headless
 *                              Chromium (installed 2026-10-11), as a PNG download
 *   portal/social_photos.php   GET  → the listing's photos (from the site) + uploads
 *   portal/social_upload.php   POST → store the agent's own photo
 *   portal/social_asset.php    GET  → serve one of the agent's uploads
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
 * whose agent name is the account's, live statuses plus anything closed in
 * the last SOCIAL_CLOSED_DAYS (Nikki, 2026-10-24: a "Just Sold" post the day
 * of the closing, as the Hot Sheet keeps a sale in view), cross-board copies
 * collapsed. Photos: the site's api/listings.php?scope=photos, through its
 * img.php proxy; the list is kept in the session so a posted photo URL is
 * checked against it (never rendered from a free URL). Uploads live in
 * CREATIVES_DIR/social/<intake id>/uploads/ as <id>.jpg (longest side 2000)
 * + <id>-thumb.jpg (400), EXIF rotation applied; a posted "upload:<id>" is
 * honoured only when that file exists under THIS account's folder.
 *
 * The render is ONE path: social_render() in inc/social_render.php makes the
 * preview and the export from the same normalised post. Chromium runs as
 * www-data with a throwaway profile under the system temp dir, one render at
 * a time (flock: the box has under 1 GB), 60 s cap; an uploaded photo is read
 * from disk for the export (file://) because Chromium has no portal session.
 * A copy of every export is kept in CREATIVES_DIR/social/<intake id>/.
 */
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/photos.php';          // mk_photo_scale(), mk_photo_srgb()
require_once __DIR__ . '/social_render.php';

if (!defined('SOCIAL_CHROMIUM')) define('SOCIAL_CHROMIUM', '/usr/bin/chromium');
const SOCIAL_UPLOAD_MAX = 20 * 1024 * 1024;    // bytes; the server allows 25M

/**
 * The context the social endpoints act in (2026-10-28). Normally portal_context():
 * the signed-in agent's account, or the account an admin previews. With
 * ?preview=admin, an elevated admin acts as THEMSELVES, with no agent account:
 * the admin side's General Social (template_general.php) needs no agent (Nikki),
 * and the renderer, the stock photos, the uploads (keyed by the person anyway)
 * and the export need nothing from an account. The synthetic account has id 0;
 * nothing listing-side is reachable from it (social_listings() returns nothing
 * for an empty id list), and the export copy goes to social/admin-<user id>/.
 */
function social_context(mysqli $conn): array {
    if (($_GET['preview'] ?? '') !== 'admin') return portal_context($conn);
    require_login();
    if (!is_elevated_admin()) portal_stop('No access', 'This page is for the marketing team.');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st = $conn->prepare("SELECT id, first_name, last_name, email, role, is_active, intake_id FROM users WHERE id = ? LIMIT 1");
    $st->bind_param('i', $uid);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user || !(int)$user['is_active']) portal_stop('No access', 'This account is not active.');
    $name = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) ?: (string)$user['email'];
    $acct = ['id' => 0, 'agent_name' => $name, 'slug' => '', 'is_active' => 1, 'status' => 'approved', 'headshot_url' => null,
             'headshot_face_url' => null, 'entity_type' => 'admin'];
    return ['user' => $user, 'acct' => $acct, 'ids' => [], 'preview' => true, 'teams' => [], 'scopes' => [], 'markets' => []];
}

/** The URL of a social endpoint for this context: portal_url(), or ?preview=admin for the admin's own context. */
function social_url(array $ctx, string $path, array $q = []): string {
    if ((int)$ctx['acct']['id'] === 0) return $path . '?' . http_build_query(['preview' => 'admin'] + $q);
    return portal_url($ctx, $path, $q);
}

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

/** The public site's resized copy of a board photo (img.php: 400 / 800 / 1600). */
function social_img(string $raw, int $w): string {
    $base = defined('PUBLIC_SITE_URL') ? rtrim(PUBLIC_SITE_URL, '/') : 'https://site.monthaus.com';
    return $base . '/img.php?u=' . rawurlencode($raw) . '&w=' . $w;
}

/**
 * How long a closed listing stays in the Templates dropdowns after the sync
 * saw it close (Nikki, 2026-10-24: Allison's Brett Trl closing was gone the
 * day of, so the Just Sold post could not be made). A week, as she asked; the
 * Hot Sheet's own sold window is HS_SOLD_WINDOW_DAYS.
 */
const SOCIAL_CLOSED_DAYS = 7;

/** True for a status that means the listing closed (sold, or leased for a rental). Same words as hs_is_closed(). */
function social_is_closed(string $s): bool { return in_array(strtolower(trim($s)), ['closed', 'sold', 'leased'], true); }

/**
 * The SQL that says which hs_listing_state rows the Templates tools offer:
 * in the feed and live (Active, Active Under Contract, Pending, Coming
 * Soon), or closed within SOCIAL_CLOSED_DAYS: a `closed` row in
 * hs_listing_changes since then (the sync writes one when a status turns
 * closed), or a close_date since then for a listing first seen closed. One
 * definition, used by social_listings() and the admin picker's counts
 * (social_agent_choices()). $alias is the hs_listing_state alias with its
 * dot ('st.'), and the table MUST carry one: hs_listing_changes has market
 * and listing_key too, so an unqualified name inside the EXISTS would
 * resolve to the change row and match every listing. The date is generated
 * here, never from a request, so it is a literal.
 */
function social_live_sql(string $alias): string {
    $since_dt = gmdate('Y-m-d H:i:s', time() - SOCIAL_CLOSED_DAYS * 86400);
    $since_d  = gmdate('Y-m-d', time() - SOCIAL_CLOSED_DAYS * 86400);
    return "{$alias}in_feed = 1 AND ({$alias}status IN ('Active','Active Under Contract','Pending','Coming Soon')
              OR (LOWER({$alias}status) IN ('closed','sold','leased')
                  AND ({$alias}close_date >= '{$since_d}'
                       OR EXISTS (SELECT 1 FROM hs_listing_changes hc
                                   WHERE hc.market = {$alias}market AND hc.listing_key = {$alias}listing_key
                                     AND hc.change_type = 'closed' AND hc.detected_at >= '{$since_dt}'))))";
}

/**
 * The account's live listings, keyed "market|listing_key", with the words the
 * form pre-fills (price_text, specs_text, town) and a thumbnail. A listing
 * closed in the last SOCIAL_CLOSED_DAYS is in the list too, `closed` => true,
 * after the live ones (social_live_sql()).
 */
function social_listings(mysqli $conn, array $ctx): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!mk_table_exists($conn, 'hs_listing_state')) return $cache;

    if (!$ctx['ids']) return [];                               // the admin's own context (social_context) has no account
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
                                 list_price, close_price, close_date, beds, baths, sqft, primary_photo, url
                            FROM hs_listing_state st
                           WHERE " . social_live_sql('st.') . "
                             AND (" . implode(' OR ', $conds) . ")
                           ORDER BY (LOWER(status) IN ('closed','sold','leased')), (market = 'recolorado'), list_price DESC, address");
    $st->bind_param($types, ...$vals);
    $st->execute();
    $seen = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
        // One property on two boards is one choice here (the local board's copy sorts first).
        $k = strtolower(preg_replace('/\s+/', ' ', trim((string)$l['address']))) . '|' . strtolower(trim((string)$l['city'])) . '|' . (int)$l['is_rental'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $id = $l['market'] . '|' . $l['listing_key'];
        $closed = social_is_closed((string)$l['status']);
        $cache[$id] = $l + [
            'id'         => $id,
            'closed'     => $closed,
            'headline'   => social_short_address((string)$l['address']),   // "610 S West End St #K103"
            // Rentals: no price by default (Nikki, 2026-10-12); the agent can type one into Text 2.
            // A closed listing: none either. The list price is no longer the figure, and the
            // sold price is the agent's to publish or not; the dropdown says "Sold".
            'price_text' => ((int)$l['is_rental'] || $closed) ? '' : social_price_text($l['list_price']),
            'specs_text' => social_specs_text($l['beds'], $l['baths'], $l['sqft']),
            'town'       => trim((string)$l['city']) !== '' ? trim((string)$l['city']) . ', Colorado' : '',
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
    // The public remarks ride along (2026-10-13), for the flyer's headline suggestion.
    $rem = (array)($_SESSION['social_remarks'] ?? []);
    unset($rem[$sk]);
    $rem[$sk] = mb_substr(trim((string)($j['remarks'] ?? '')), 0, 600);
    $_SESSION['social_remarks'] = array_slice($rem, -12, null, true);
    if ($urls) {
        $all = (array)($_SESSION['social_photos'] ?? []);
        unset($all[$sk]);
        $all[$sk] = $urls;
        $_SESSION['social_photos'] = array_slice($all, -12, null, true);
    }
    return $urls;
}

/**
 * A headline suggestion from the opening of the public remarks (Nikki,
 * 2026-10-13: "maybe pull a phrase from the opening of the listing
 * description"): the first sentence, minus a "Welcome to" sort of start,
 * cut at a word boundary to about 44 characters, no trailing punctuation.
 * '' when the remarks give nothing usable; the agent edits it anyway.
 */
function social_headline_from_remarks(string $remarks): string {
    $t = trim(preg_replace('/\s+/', ' ', $remarks));
    if ($t === '') return '';
    // The first sentence, minus the usual lead-ins ("Welcome to", "Presenting", "Enjoy", "This").
    $t = preg_split('/(?<=[.!?])\s+|\s+[-–—]+\s+|\s*\|\s*/u', $t)[0] ?? '';
    $t = preg_replace('/^(welcome (home )?to|introducing|presenting|discover|experience|enjoy|step into|come (home )?to|this)\s+/i', '', $t);
    $t = trim($t, " .,;:!?-");
    // Too long for a headline: cut at the last clause break before 44 characters
    // ("Perched on Red Mountain | with a top-of-the-world…"), else at a word.
    $max = 44;
    if (mb_strlen($t) > $max) {
        $head = mb_substr($t, 0, $max);
        $best = -1;
        foreach ([', ', ' with ', ' from ', ' at ', ' featuring ', ' features ', ' offering ', ' offers ', ' that ', ' where ', ' and ', ' boasting ', ' is ', ' in ', ' on '] as $m) {
            $pos = mb_strrpos($head, $m);
            if ($pos !== false && $pos > 16 && $pos > $best) $best = $pos;
        }
        if ($best < 0) { $sp = mb_strrpos($head, ' '); $best = ($sp !== false && $sp > 16) ? $sp : $max; }
        $t = mb_substr($t, 0, $best);
    }
    // No dangling small word at the end ("…with a", "…of the").
    $t = preg_replace('/(\s+(a|an|the|with|and|of|in|on|at|for|to|this|that|its|is|from))+$/i', '', trim($t, " .,;:!?-"));
    $t = trim($t, " .,;:!?-");
    if (mb_strlen($t) < 8) return '';
    return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
}

// ── The agent's own photos ────────────────────────────────────────────────────

/**
 * Where the signed-in PERSON's uploads live (outside the web root): keyed by
 * users.id, not the portal account, since 2026-10-18. Keyed by account, an
 * admin previewing an agent uploaded INTO that agent's folder (Nikki's test
 * photos showed on Jonathan's portal as "Yours"). The uploads under
 * social/<intake>/uploads from before were moved to social/users/<user>/.
 */
function social_upload_dir(array $ctx): string {
    return rtrim(CREATIVES_DIR, '/') . '/social/users/' . (int)$ctx['user']['id'] . '/uploads';
}

/** The file for an upload id (hex), full size or the thumbnail; null when it is not this person's. */
function social_upload_file(array $ctx, string $id, bool $thumb = false): ?string {
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    $f = social_upload_dir($ctx) . '/' . $id . ($thumb ? '-thumb' : '') . '.jpg';
    // realpath: CREATIVES_DIR is written with a "..", and the export hands this path to Chromium as a file:// URL.
    return is_file($f) ? (realpath($f) ?: $f) : null;
}

/** The portal URL that serves an upload (400 = thumbnail, else full size). Carries ?preview= like every portal link. */
function social_upload_url(array $ctx, string $id, int $w): string {
    return social_url($ctx, '/portal/social_asset.php', ['id' => $id, 'w' => $w]);   // social_url: the admin's own context too
}

/**
 * mkdir's mode is cut by the umask (02775 becomes 02755), which left the
 * folders a CLI test run created unwritable to Apache (2026-10-12: "could not
 * be saved on the server"). chmod is not cut by the umask: set the mode
 * outright on the new folder and its parents under CREATIVES_DIR/social.
 */
function social_open_dirs(string $dir): void {
    $root = rtrim(CREATIVES_DIR, '/') . '/social';
    for ($d = $dir; str_starts_with($d, $root); $d = dirname($d)) {
        @chmod($d, 02775);
        if ($d === $root) break;
    }
}

/** Remove one of this person's uploads (the file and its thumbnail). False when it is not theirs. */
function social_delete_upload(array $ctx, string $id): bool {
    $f = social_upload_file($ctx, $id);
    if (!$f) return false;
    $t = social_upload_file($ctx, $id, true);
    $ok = @unlink($f);
    if ($t) @unlink($t);
    return $ok;
}

/** This person's upload ids, newest first (at most 60). */
function social_uploads(array $ctx): array {
    $dir = social_upload_dir($ctx);
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.jpg') ?: [];
    $files = array_filter($files, fn($f) => !str_ends_with($f, '-thumb.jpg'));
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return array_slice(array_map(fn($f) => basename($f, '.jpg'), $files), 0, 60);
}

/**
 * Store an agent's own photo from $_FILES[$field]: JPG, PNG or WEBP up to
 * SOCIAL_UPLOAD_MAX, EXIF rotation applied, saved as JPEG at 2000 px on the
 * long side plus a 400 px thumbnail. ['id' => hex] or ['error' => words].
 */
function social_store_upload(array $ctx, string $field): array {
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        return ['error' => [
            UPLOAD_ERR_NO_FILE   => 'No photo arrived. Please try again.',
            UPLOAD_ERR_INI_SIZE  => 'That photo is larger than the server allows (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE => 'That photo is too large.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
        ][$err] ?? "Upload failed (code {$err})."];
    }
    $tmp = $_FILES[$field]['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) return ['error' => 'The upload did not arrive. Please try again.'];
    if (($_FILES[$field]['size'] ?? 0) > SOCIAL_UPLOAD_MAX) return ['error' => 'That photo is over 20 MB. Please use a smaller one.'];
    $mime = (string)@mime_content_type($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['error' => 'Please use a JPG, PNG or WEBP photo' . (in_array($mime, ['image/heic', 'image/heif'], true) ? ' (this one is HEIC; your phone can export it as JPG)' : '') . '.'];
    }
    $srgb = function_exists('mk_photo_srgb') ? mk_photo_srgb($tmp) : ['file' => $tmp];
    $img  = @imagecreatefromstring((string)file_get_contents($srgb['file']));
    if ($srgb['file'] !== $tmp) @unlink($srgb['file']);
    if (!$img) return ['error' => 'That file could not be read as a photo. Please use a JPG, PNG or WEBP.'];
    if (function_exists('exif_read_data') && $mime === 'image/jpeg') {
        $e = @exif_read_data($tmp);
        $o = (int)($e['Orientation'] ?? 0);
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($img, IMG_FLIP_HORIZONTAL);
        $deg = [3 => 180, 4 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$o] ?? 0;
        if ($deg) { $r = imagerotate($img, $deg, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }
    $dir = social_upload_dir($ctx);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 02775, true)) { imagedestroy($img); return ['error' => 'The photo folder could not be created on the server. Please email ' . PORTAL_MARKETING_EMAIL . '.']; }
        social_open_dirs($dir);
    }
    if (!is_writable($dir)) { imagedestroy($img); return ['error' => 'The photo folder on the server is not writable. Please email ' . PORTAL_MARKETING_EMAIL . '.']; }
    $id = bin2hex(random_bytes(8));
    $ok = @imagejpeg(mk_photo_scale($img, 2000), "{$dir}/{$id}.jpg", 88);
    $ok = $ok && @imagejpeg(mk_photo_scale($img, 400), "{$dir}/{$id}-thumb.jpg", 82);
    imagedestroy($img);
    if (!$ok) { @unlink("{$dir}/{$id}.jpg"); @unlink("{$dir}/{$id}-thumb.jpg"); return ['error' => 'The photo could not be saved on the server. Please email ' . PORTAL_MARKETING_EMAIL . '.']; }
    @chmod("{$dir}/{$id}.jpg", 0664); @chmod("{$dir}/{$id}-thumb.jpg", 0664);
    return ['id' => $id];
}

// ── The stock mountain photos (2026-10-15) ────────────────────────────────────

/**
 * The Mont Haus stock library for the Other template: assets/stock/<name>.jpg
 * (2048 px) + thumbs/<name>.jpg (400 px), captions in assets/stock/stock.json
 * (name => "Aspen from the air, fall"), built from Nikki's "Mont Haus/Mtn
 * Photos" folder (the Adobe Stock ID is the number at the end of a name).
 * The images are on the server only (git-ignored); the manifest is tracked.
 * [name => caption], only names whose file exists.
 */
function social_stock(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $dir = __DIR__ . '/../assets/stock';
    $j = is_file("$dir/stock.json") ? json_decode((string)file_get_contents("$dir/stock.json"), true) : null;
    foreach ((array)$j as $name => $caption) {
        if (preg_match('/^[a-z0-9-]+$/', (string)$name) && is_file("$dir/$name.jpg")) $cache[$name] = (string)$caption;
    }
    return $cache;
}

/** The library grouped: [region => [name => caption]], regions in social_stock_region_order(), photos in manifest order inside each. */
function social_stock_grouped(): array {
    $groups = array_fill_keys(social_stock_region_order(), []);
    foreach (social_stock() as $name => $caption) $groups[social_stock_region($name)][$name] = $caption;
    return array_filter($groups);
}

/** A stock photo's file on disk, or null. */
function social_stock_file(string $name): ?string {
    if (!isset(social_stock()[$name])) return null;
    return realpath(__DIR__ . '/../assets/stock/' . $name . '.jpg') ?: null;
}

/** A stock photo's URL (400 = the thumbnail, else the 2048 copy). */
function social_stock_url(string $name, int $w): string {
    return rtrim(SITE_URL, '/') . '/assets/stock/' . ($w <= 400 ? 'thumbs/' : '') . $name . '.jpg';
}

// ── The post ─────────────────────────────────────────────────────────────────

/**
 * The posted form as a normalised post for social_render(), or a message
 * (string) when there is nothing to render yet. Everything is checked here:
 * the template, the look, the listing (one of the account's), the photo (one
 * the site listed for that listing, or one of this account's uploads, else
 * the listing's primary), the label (a known one, none, or a short custom
 * one), the lines and their styles, lengths, dates, the frame.
 */
function social_post_from_request(mysqli $conn, array $ctx, array $in): array|string {
    $offered = social_templates_offered();
    $type  = isset($offered[(string)($in['type'] ?? '')]) ? (string)$in['type'] : array_key_first($offered);
    $theme = isset(social_themes()[(string)($in['theme'] ?? '')]) ? (string)$in['theme'] : 'dark';
    $format = social_template_format($type);   // the story templates are 1080 x 1920
    $mark  = isset(social_marks()[(string)($in['mark'] ?? '')]) ? (string)$in['mark'] : 'triangle';   // the story's watermark

    $kind = social_template_kind($type);
    $layout = $kind === 'other' && ($in['layout'] ?? '') === 'center' ? 'center' : 'corner';   // centred words: General Social only (2026-10-28)
    $logo = (string)($in['logo'] ?? ($in['story_logo'] ?? ''));                                 // the logo's place; story_logo was its old name
    if (!isset(social_logo_places()[$logo])) $logo = $format === 'story' ? 'bottom' : 'top';
    if ($logo === 'center' && $layout !== 'center') $logo = 'top';
    $l = $kind === 'other' ? null : social_listing($conn, $ctx, (string)($in['listing'] ?? ''));   // Other: no listing, the agent's own photo and words
    if ($kind !== 'other' && !$l) return 'Choose one of your listings to see your post.';

    // The photo: an upload of this account's, or a board photo the site listed for this listing.
    $photo = (string)($in['photo'] ?? '');
    $photo_url = ''; $photo_file = null;
    if (str_starts_with($photo, 'upload:') && ($f = social_upload_file($ctx, substr($photo, 7)))) {
        $photo_url  = social_upload_url($ctx, substr($photo, 7), 1600);
        $photo_file = $f;
    } elseif ($l) {
        $allowed = (array)($_SESSION['social_photos'][$l['id']] ?? []);
        if ((string)$l['primary_photo'] !== '') $allowed[] = (string)$l['primary_photo'];
        if (!in_array($photo, $allowed, true)) $photo = (string)$l['primary_photo'];
        $photo_url = $photo !== '' ? social_img($photo, 1600) : '';
    } elseif (str_starts_with($photo, 'stock:') && ($f = social_stock_file(substr($photo, 6)))) {
        // Other: one of the stock mountain photos (2026-10-15).
        $photo_url  = social_stock_url(substr($photo, 6), 2048);
        $photo_file = $f;
    } else {
        // Other: the Mont Haus mountains placeholder (assets/images/social-placeholder.jpg) until a photo is picked.
        $photo = 'placeholder';
        $photo_file = realpath(__DIR__ . '/../assets/images/social-placeholder.jpg') ?: null;
        $photo_url  = rtrim(SITE_URL, '/') . '/assets/images/social-placeholder.jpg';
    }

    // The label on the photo: a ready-made one, none, or the agent's own words.
    $pick = (string)($in['label'] ?? '');
    if ($pick === '__custom')                       $label = mb_substr(trim((string)($in['label_custom'] ?? '')), 0, 28);
    elseif ($pick === '__none')                     $label = '';
    elseif (in_array($pick, social_labels(), true)) $label = $pick;
    else                                            $label = $kind === 'openhouse' ? 'Open House' : ($kind === 'other' ? '' : 'Featured Listing');

    // The template's line SLOTS (two on Full Photo, four on the split family), each
    // with a style; a blank slot is dropped by the renderer. Only the slots the
    // template shows are read: the form still posts the hidden ones with their
    // pre-filled words, and reading them promoted "5 Bed · 8 Bath" into view the
    // moment the price was cleared (Nikki, 2026-10-12).
    $styles = social_line_styles(); $defaults = social_line_defaults();
    $texts = (array)($in['line_text'] ?? ($kind === 'other' ? ['Mont Haus International Realty'] : [])); $kinds = (array)($in['line_style'] ?? []);
    $lines = [];
    for ($i = 0; $i < social_line_count($type); $i++) {
        $s = (string)($kinds[$i] ?? $defaults[$i]);
        $lines[] = ['text' => mb_substr(trim((string)($texts[$i] ?? '')), 0, 90), 'style' => isset($styles[$s]) ? $s : $defaults[$i]];
    }

    $p = [
        'type'       => $type,
        'theme'      => $theme,
        'mark'       => $mark,
        'logo'       => $logo,
        'logo_kind'  => isset(social_logo_kinds()[(string)($in['logo_kind'] ?? '')]) ? (string)$in['logo_kind'] : 'full',   // the stack or the M mark
        'layout'     => $layout,
        'format'     => $format,
        'listing'    => $l,
        'label'      => $label,
        'photo'      => $photo,
        'photo_url'  => $photo_url,
        'photo_file' => $photo_file,
        'zoom'       => max(1.0, min(3.0, round((float)($in['zoom'] ?? 1), 2))),
        'focus_x'    => max(0, min(100, (int)($in['focus_x'] ?? 50))),
        'focus_y'    => max(0, min(100, (int)($in['focus_y'] ?? 50))),
        // Centred, the headline keeps its line breaks (a textarea on General Social) and may run to 120 characters.
        'headline'   => mb_substr(trim(str_replace("\r", '', (string)($in['headline'] ?? ($l['headline'] ?? ($kind === 'other' ? 'Alpine Roots. Global Vision.' : ''))))), 0, $layout === 'center' ? 120 : 80),
        'lines'      => $lines,
        'date_text'  => '',
        'time_text'  => '',
        'when_text'  => '',
    ];
    if ($kind === 'openhouse') {
        // The date and time line is the agent's own words when they typed any ("Today 11am-2pm",
        // Nikki 2026-10-15), else built from the date and time pickers.
        $p['date_text'] = social_date_text((string)($in['oh_date'] ?? ''));
        $p['time_text'] = social_time_range_text((string)($in['oh_start'] ?? ''), (string)($in['oh_end'] ?? ''));
        $p['when_text'] = mb_substr(trim(str_replace("\r", '', (string)($in['oh_when'] ?? ''))), 0, 90);   // a textarea: Enter = a line break on the post
        if ($p['when_text'] === '' && $p['date_text'] === '') return 'Type or pick the open house date and time to see your post.';
    }
    return $p;
}

/** "633-w-main-street-open-house-20261018-1432.png" */
function social_filename(array $p): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$p['headline']), '-')) ?: 'post';
    $kind = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)($p['label'] ?: $p['type'])), '-')) ?: 'post';
    $fmt = (string)($p['format'] ?? 'post');
    return mb_substr($slug, 0, 60) . '-' . $kind . ($fmt !== 'post' ? '-' . $fmt : '') . '-' . date('Ymd-Hi') . '.png';
}

/**
 * The post as PNG bytes: the HTML written to a private temp file and
 * screenshotted by headless Chromium at exactly SOCIAL_W x SOCIAL_H. Null when
 * Chromium failed (logged to the PHP error log with its last lines).
 */
function social_export_png(string $html, int $height = SOCIAL_H): ?string {
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
         . ' --window-size=' . SOCIAL_W . ',' . $height . ' --virtual-time-budget=10000'
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
    $dir = rtrim(CREATIVES_DIR, '/') . '/social/' . ((int)$ctx['acct']['id'] > 0 ? (int)$ctx['acct']['id'] : 'admin-' . (int)$ctx['user']['id']);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 02775, true)) return;
        social_open_dirs($dir);
    }
    @file_put_contents($dir . '/' . $name, $png);
    @chmod($dir . '/' . $name, 0664);
}

/** "Under Contract" for the RESO value; the rest as sent. */
function social_status_words(string $s): string {
    if (social_is_closed($s)) return strtolower(trim($s)) === 'leased' ? 'Leased' : 'Sold';
    return $s === 'Active Under Contract' ? 'Under Contract' : $s;
}

/**
 * The agents an admin may build templates for (inc/admin_tool_shell.php's
 * picker, 2026-10-21): every active agent account, with how many live
 * listings (recent closings included, social_live_sql()) the feed holds
 * under their board ids (list or co-list side; the
 * team-name matches social_listings() also makes are not counted here, so a
 * team member may see more in the tool than the number promises).
 * [id => ['id', 'agent_name', 'n']], by name.
 */
function social_agent_choices(mysqli $conn): array {
    $out = [];
    $hasState = mk_table_exists($conn, 'hs_listing_state');
    $join = $hasState
        ? "LEFT JOIN agent_mls_ids a ON a.intake_id = i.id AND a.mls_agent_id <> ''
           LEFT JOIN hs_listing_state l ON l.market = a.market AND " . social_live_sql('l.') . "
                AND (l.list_agent_mls_id = a.mls_agent_id OR l.colist_agent_mls_id = a.mls_agent_id)"
        : '';
    $count = $hasState ? 'COUNT(DISTINCT l.market, l.listing_key)' : '0';
    $type  = mk_column_exists($conn, 'marketing_intakes', 'entity_type') ? "AND COALESCE(i.entity_type, 'agent') = 'agent'" : '';
    $r = $conn->query("SELECT i.id, i.agent_name, {$count} AS n
                         FROM marketing_intakes i {$join}
                        WHERE i.is_active = 1 AND COALESCE(i.status, '') <> 'archived' {$type}
                        GROUP BY i.id, i.agent_name
                        ORDER BY i.agent_name");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $row) $out[(int)$row['id']] = ['id' => (int)$row['id'], 'agent_name' => (string)$row['agent_name'], 'n' => (int)$row['n']];
    return $out;
}
