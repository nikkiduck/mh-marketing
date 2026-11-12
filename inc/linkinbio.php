<?php
/**
 * inc/linkinbio.php — the Link in Bio store (Nikki, 2026-11-12: "a linkinbio
 * page for our Instagram account ... the post covers appear in a grid and I
 * specify where they link to"). The page itself is the website's,
 * monthaus.com/linkinbio; this is the list behind it: the covers in order,
 * newest first, each with the address it opens.
 *
 * One JSON file, CREATIVES_DIR/social/linkinbio.json, and one JPEG per cover
 * beside it in CREATIVES_DIR/social/linkinbio/<id>.jpg (BIO_COVER_W wide, the
 * post's own shape). No table: one super admin, a few dozen covers.
 *
 *   { "items": [ { "id": "16 hex", "url": "https://…", "title": "words for
 *       the picture", "source": "plan" | "manual", "week": "2026-W46",
 *       "slot": 0, "added_at": "…", "v": 1731450000 }, … ] }
 *
 * The order of the list is the order on the page. `v` changes when the cover
 * picture is remade, so the website fetches it again. A cover made from a
 * Weekly Plans post (`source` plan) remembers its week and slot, which is how
 * the week page knows the post is already in the bio; it is a COPY: resetting
 * or changing the post later leaves the cover as it was until Remake cover.
 *
 * To the website: api/roster.php sends bio_feed() as the feed's `linkinbio`
 * key (present = the site replaces its list; the first BIO_MAX only), each
 * cover's picture served by api/linkinbio_cover.php (public: the pictures are
 * on Instagram already). social_linkinbio.php asks the site to sync after
 * every change (mk_site_sync()), so a cover is live in about a second.
 *
 * Needs only inc/config.php (CREATIVES_DIR, SITE_URL), so the feed can load
 * it without the plan tools. The admin page is social_linkinbio.php.
 */

const BIO_MAX      = 30;    // covers on the website's page; older ones stay in the list here, greyed
const BIO_COVER_W  = 600;   // px: three across on a phone at 3x is 375, on a desktop column about 430
const BIO_URL_MAX  = 500;
const BIO_TITLE_MAX = 120;

function bio_dir(): string {
    $dir = rtrim(CREATIVES_DIR, '/') . '/social/linkinbio';
    if (!is_dir($dir)) { @mkdir($dir, 02775, true); if (function_exists('social_open_dirs')) social_open_dirs($dir); else @chmod($dir, 02775); }
    return $dir;
}
function bio_path(): string { return dirname(bio_dir()) . '/linkinbio.json'; }
function bio_id_ok(string $id): bool { return (bool)preg_match('/^[a-f0-9]{16}$/', $id); }
function bio_cover_file(string $id): string { return bio_id_ok($id) ? bio_dir() . '/' . $id . '.jpg' : ''; }

/** The list, in page order. Items without a cover file are left out. */
function bio_items(): array {
    $j = json_decode((string)@file_get_contents(bio_path()), true);
    $out = [];
    foreach ((array)($j['items'] ?? []) as $it) {
        if (!is_array($it) || !bio_id_ok((string)($it['id'] ?? '')) || !is_file(bio_cover_file((string)$it['id']))) continue;
        $out[] = $it;
    }
    return $out;
}

/** Change the list under a lock: $fn takes the items by reference. */
function bio_update(callable $fn): bool {
    bio_dir();
    $fh = @fopen(bio_path(), 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $j = json_decode((string)stream_get_contents($fh), true);
    $items = array_values(array_filter((array)($j['items'] ?? []), 'is_array'));
    $fn($items);
    ftruncate($fh, 0); rewind($fh);
    $ok = fwrite($fh, json_encode(['items' => array_values($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    @chmod(bio_path(), 0664);
    return $ok;
}

/** An address a cover may open: https, a real host, no spaces. '' when it is not one. */
function bio_clean_url(string $url): string {
    $u = trim($url);
    if ($u !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $u)) $u = 'https://' . $u;   // "monthaus.com/search" as typed
    if (strlen($u) > BIO_URL_MAX || !preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}(?:[/?#]\S*)?$~i', $u)) return '';
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : '';
}

function bio_clean_title(string $t): string {
    return mb_substr(trim(preg_replace('/\s+/', ' ', $t)), 0, BIO_TITLE_MAX);
}

/** Picture bytes (PNG, JPEG, WEBP) to the cover's JPEG, BIO_COVER_W wide. $exif_from: the uploaded file, for a phone photo's rotation. */
function bio_write_cover(string $id, string $bytes, string $exif_from = ''): bool {
    $file = bio_cover_file($id);
    $img = $file !== '' ? @imagecreatefromstring($bytes) : false;
    if (!$img) return false;
    if ($exif_from !== '' && function_exists('exif_read_data')) {
        $o = (int)((@exif_read_data($exif_from) ?: [])['Orientation'] ?? 0);
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($img, IMG_FLIP_HORIZONTAL);
        $deg = [3 => 180, 4 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$o] ?? 0;
        if ($deg) { $r = imagerotate($img, $deg, 0); if ($r) $img = $r; }
    }
    $w = imagesx($img); $h = imagesy($img);
    if ($w > BIO_COVER_W) {
        $out = imagecreatetruecolor(BIO_COVER_W, (int)round($h * BIO_COVER_W / $w));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        $img = $out;
    } elseif (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    imageinterlace($img, true);
    $ok = @imagejpeg($img, $file, 86);
    if ($ok) @chmod($file, 0664);
    return $ok;
}

/** Add a cover at the top. $it: url, title, source, and week + slot for a plan post. The new id, or '' when it failed. */
function bio_add(array $it, string $bytes, string $exif_from = ''): string {
    $id = bin2hex(random_bytes(8));
    if (!bio_write_cover($id, $bytes, $exif_from)) return '';
    $row = ['id' => $id, 'url' => (string)$it['url'], 'title' => (string)($it['title'] ?? ''), 'source' => ($it['source'] ?? '') === 'plan' ? 'plan' : 'manual',
            'added_at' => date('c'), 'v' => time()];
    if ($row['source'] === 'plan') { $row['week'] = (string)$it['week']; $row['slot'] = (int)$it['slot']; }
    $ok = bio_update(function (array &$items) use ($row) { array_unshift($items, $row); });
    if (!$ok) { @unlink(bio_cover_file($id)); return ''; }
    return $id;
}

function bio_remove(string $id): bool {
    if (!bio_id_ok($id)) return false;
    $ok = bio_update(function (array &$items) use ($id) { $items = array_filter($items, fn($x) => ($x['id'] ?? '') !== $id); });
    if ($ok) @unlink(bio_cover_file($id));
    return $ok;
}

/** Move a cover one place: $dir -1 = earlier on the page, 1 = later; 'top' = first. */
function bio_move(string $id, int|string $dir): bool {
    return bio_update(function (array &$items) use ($id, $dir) {
        $k = array_search($id, array_column($items, 'id'), true);
        if ($k === false) return;
        $to = $dir === 'top' ? 0 : $k + (int)$dir;
        if ($to < 0 || $to >= count($items) || $to === $k) return;
        $row = array_splice($items, $k, 1);
        array_splice($items, $to, 0, $row);
    });
}

/** Change where a cover goes and its words. */
function bio_set(string $id, string $url, string $title): bool {
    return bio_update(function (array &$items) use ($id, $url, $title) {
        foreach ($items as &$x) if (($x['id'] ?? '') === $id) { $x['url'] = $url; $x['title'] = $title; }
    });
}

/** A new picture for an existing cover (Remake cover): the file replaced, `v` moved on. */
function bio_recover(string $id, string $bytes): bool {
    if (!bio_write_cover($id, $bytes)) return false;
    return bio_update(function (array &$items) use ($id) { foreach ($items as &$x) if (($x['id'] ?? '') === $id) $x['v'] = time(); });
}

/** The cover made from a Weekly Plans post, or null: how the week page knows the post is in the bio. */
function bio_find_plan(string $week, int $slot, ?array $items = null): ?array {
    foreach ($items ?? bio_items() as $x) if (($x['source'] ?? '') === 'plan' && ($x['week'] ?? '') === $week && (int)($x['slot'] ?? -1) === $slot) return $x;
    return null;
}

/** The cover's public picture address (api/linkinbio_cover.php). */
function bio_cover_url(array $it): string {
    return rtrim(SITE_URL, '/') . '/api/linkinbio_cover.php?id=' . $it['id'] . '&v=' . (int)($it['v'] ?? 0);
}

/** The roster feed's `linkinbio` list: the first BIO_MAX covers, in order. */
function bio_feed(): array {
    $out = [];
    foreach (array_slice(bio_items(), 0, BIO_MAX) as $it) {
        $url = bio_clean_url((string)($it['url'] ?? ''));
        if ($url === '') continue;
        $out[] = ['id' => $it['id'], 'url' => $url, 'title' => bio_clean_title((string)($it['title'] ?? '')), 'image' => bio_cover_url($it)];
    }
    return $out;
}

/**
 * Where a Weekly Plans piece would send someone, when the piece itself says:
 * a Property piece to its listing's page on the website (the feed's own
 * `url`), a Broker Feature to the agent's page, a Location Feature about an
 * office to /offices. '' for the rest: Nikki pastes the address. Used for a
 * cover's first destination and for a Story's sticker link. Needs the plan
 * tools (inc/plan.php), so only the admin pages call it.
 */
function bio_plan_dest(mysqli $conn, string $kind, array $form): string {
    $site = rtrim(PUBLIC_SITE_URL, '/');
    if ($kind === 'property') {
        $lid = (string)($form['listing'] ?? '');
        $l = plan_listing($conn, $lid);
        if (!$l && substr_count($lid, '|') === 1) {
            // plan_listings() holds the live and just-closed only; a post saved weeks ago may be for a listing that has since
            // left it (221 Wrights Road, Removed, 2026-11-12). Its page on the website is still there: read the address direct.
            [$mk, $lk] = explode('|', $lid);
            $q = $conn->prepare("SELECT url, mls_id FROM hs_listing_state WHERE market = ? AND listing_key = ? LIMIT 1");
            if ($q) { $q->bind_param('ss', $mk, $lk); $q->execute(); $l = $q->get_result()->fetch_assoc() ?: null; $q->close(); }
        }
        if (!$l) return '';
        $u = bio_clean_url((string)($l['url'] ?? ''));
        return $u !== '' ? $u : (trim((string)$l['mls_id']) !== '' ? $site . '/listing/mls/' . rawurlencode(trim((string)$l['mls_id'])) : '');
    }
    if ($kind === 'broker') {
        $a = plan_agents($conn)[(int)($form['agent'] ?? 0)] ?? null;
        return $a && preg_match('/^[a-z0-9-]+$/', (string)$a['slug']) ? $site . '/' . $a['slug'] : '';
    }
    if ($kind === 'location') {
        $loc = plan_location((string)($form['loc'] ?? ''));
        return $loc && $loc['kind'] === 'office' ? $site . '/offices' : '';
    }
    return '';
}
