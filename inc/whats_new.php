<?php
/**
 * inc/whats_new.php — What's New: the notices Nikki posts for agents (2026-11-14).
 *
 * Nikki: "B2B has a What's New notification system. I'd like to create something similar for the Client Emails and
 * for the Marketing Portal as a whole ... move the B2B notification system from a JSON that I edit and upload to an
 * area in my Admin portal ... pulls from the B2B and Client Email notifications so I don't have to type them twice."
 *
 * ONE list, kept here, typed once. Every notice has an AREA, and the area decides who sees it and where:
 *
 *   portal  Marketing Portal   every agent, in the agent portal
 *   b2b     B2Bs               Aspen and Vail agents: on MH Send's B2B pages, and in their portal
 *   emails  Client Emails      agents who have Client Emails: on Client Emails in MH Send, and in their portal
 *
 * The portal shows its own notices plus the B2B and Client Emails ones the agent may use (wn_portal_areas()).
 * MH Send reads the list through api/whats_new.php (its inc/whats_new.php caches it for a few minutes).
 *
 * Store: one JSON file, CREATIVES_DIR/whats_new/whats_new.json (the Link in Bio pattern: no table, changed under a
 * lock). { next_id, items: [ { id, area, icon, title, message, active, created (UTC) } ] }. The admin page is
 * whats_new.php (super admin only).
 *
 * IDS only go up and are never reused: a person's "seen" marker is the highest id they have seen
 * (users.last_seen_announcement_id here; MH Send keeps its own two markers). They continue from MH Send's old
 * feature_announcements table (ids 1 and 14074, carried over as the seed), so the markers people already had on
 * the B2B pages still mean what they meant.
 */

const WN_AREAS = [
    'portal' => ['Marketing Portal', 'Every agent, in the agent portal.'],
    'b2b'    => ['B2Bs', 'Aspen and Vail agents: on the B2B pages of MH Send, and in their portal.'],
    'emails' => ['Client Emails', 'Agents who have Client Emails: on Client Emails in MH Send, and in their portal.'],
];
const WN_TITLE_MAX   = 80;
const WN_MESSAGE_MAX = 600;
const WN_ICON_DEFAULT = '✨';
const WN_FIRST_ID = 14075;          // the first id made here: MH Send's old table ended at 14074
// Notices that existed before the portal had its bell never show as NEW there (they are in the bell's list).
const WN_PORTAL_BASELINE = 14074;
const WN_PANEL_MAX = 12;            // notices listed under the bell

function wn_dir(): string {
    $dir = rtrim(CREATIVES_DIR, '/') . '/whats_new';
    if (!is_dir($dir)) { @mkdir($dir, 02775, true); @chmod($dir, 02775); }
    return $dir;
}
function wn_path(): string { return wn_dir() . '/whats_new.json'; }

/** What the list holds before the first change: the two notices MH Send's B2B pages had, with their ids and dates. */
function wn_seed(): array {
    return ['next_id' => WN_FIRST_ID, 'items' => [
        ['id' => 1, 'area' => 'b2b', 'icon' => '🔀', 'title' => 'Drag to reorder brokers', 'active' => true, 'created' => '2026-06-21 18:57:09',
         'message' => 'You can now drag brokers into any order on the Listing Broker card: whoever you drag to the top sends the email (as long as their address is @monthaus.com). Look for the grip handle on the left of each broker row.'],
        ['id' => 14074, 'area' => 'b2b', 'icon' => '🏘️', 'title' => 'Open House Tours', 'active' => true, 'created' => '2026-09-10 16:46:19',
         'message' => 'You can now announce an Open House Tour featuring multiple properties, including those from other brokerages. Choose the Multiple Open House template and enter the MLS numbers of the properties on your tour.'],
    ]];
}

/** One stored row made safe to use: null when it is not a notice. */
function wn_row(mixed $it): ?array {
    if (!is_array($it)) return null;
    $id = (int)($it['id'] ?? 0); $area = (string)($it['area'] ?? '');
    $title = trim((string)($it['title'] ?? '')); $message = trim((string)($it['message'] ?? ''));
    if ($id <= 0 || !isset(WN_AREAS[$area]) || $title === '' || $message === '') return null;
    return ['id' => $id, 'area' => $area, 'icon' => (string)($it['icon'] ?? '') ?: WN_ICON_DEFAULT, 'title' => $title, 'message' => $message,
            'active' => !empty($it['active']), 'created' => (string)($it['created'] ?? '')];
}

/** The whole store: ['next_id' => int, 'items' => rows]. The seed until the file exists. */
function wn_load(): array {
    $raw = @file_get_contents(wn_path());
    $j = ($raw === false || trim($raw) === '') ? wn_seed() : json_decode($raw, true);
    if (!is_array($j)) $j = ['items' => []];
    $items = array_values(array_filter(array_map('wn_row', (array)($j['items'] ?? []))));
    $top = $items ? max(array_column($items, 'id')) : 0;
    return ['next_id' => max((int)($j['next_id'] ?? 0), $top + 1, WN_FIRST_ID), 'items' => $items];
}

/**
 * Notices, newest first. $areas null = every area; $active_only false = hidden ones too (the admin page).
 */
function wn_items(?array $areas = null, bool $active_only = true): array {
    $out = [];
    foreach (wn_load()['items'] as $it) {
        if ($active_only && !$it['active']) continue;
        if ($areas !== null && !in_array($it['area'], $areas, true)) continue;
        $out[] = $it;
    }
    usort($out, fn($a, $b) => $b['id'] <=> $a['id']);
    return $out;
}

/** Change the store under a lock: $fn takes the store (next_id, items) by reference. False when it could not be written. */
function wn_update(callable $fn): bool {
    $fh = @fopen(wn_path(), 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $raw = (string)stream_get_contents($fh);
    $j = trim($raw) === '' ? wn_seed() : json_decode($raw, true);
    if (!is_array($j)) { flock($fh, LOCK_UN); fclose($fh); return false; }   // a file that cannot be read is never written over
    $items = array_values(array_filter(array_map('wn_row', (array)($j['items'] ?? []))));
    $top = $items ? max(array_column($items, 'id')) : 0;
    $store = ['next_id' => max((int)($j['next_id'] ?? 0), $top + 1, WN_FIRST_ID), 'items' => $items];
    $fn($store);
    ftruncate($fh, 0); rewind($fh);
    $ok = fwrite($fh, json_encode(['next_id' => (int)$store['next_id'], 'items' => array_values($store['items'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    @chmod(wn_path(), 0664);
    return $ok;
}

/** Plain text on one line, cut to $max characters. */
function wn_clean_line(string $s, int $max): string {
    $s = trim((string)preg_replace('/\s+/u', ' ', strip_tags($s)));
    return mb_substr($s, 0, $max);
}
/** Plain text that may have line breaks (at most one blank line in a row), cut to $max characters. */
function wn_clean_text(string $s, int $max): string {
    $s = str_replace(["\r\n", "\r"], "\n", strip_tags($s));
    $s = (string)preg_replace('/[^\S\n]+/u', ' ', $s);
    $s = (string)preg_replace('/ ?\n ?/', "\n", $s);
    $s = trim((string)preg_replace('/\n{3,}/', "\n\n", $s));
    return mb_substr($s, 0, $max);
}
/** The picture beside a notice: one emoji (a few characters at most), or the default. */
function wn_clean_icon(string $s): string {
    $s = mb_substr(trim(strip_tags($s)), 0, 4);
    return ($s === '' || preg_match('/[\p{L}\p{N}<>&"\']/u', $s)) ? WN_ICON_DEFAULT : $s;   // letters and digits are not an icon
}

/**
 * The fields of a posted notice, cleaned: ['area', 'icon', 'title', 'message'], or words saying what is missing.
 */
function wn_fields(array $in): array|string {
    $area = (string)($in['area'] ?? '');
    if (!isset(WN_AREAS[$area])) return 'Choose where the notification shows.';
    $title = wn_clean_line((string)($in['title'] ?? ''), WN_TITLE_MAX);
    $message = wn_clean_text((string)($in['message'] ?? ''), WN_MESSAGE_MAX);
    if ($title === '') return 'Give the notification a title.';
    if ($message === '') return 'Write the notification\'s message.';
    return ['area' => $area, 'icon' => wn_clean_icon((string)($in['icon'] ?? '')), 'title' => $title, 'message' => $message];
}

/** Post a new notice. Returns its id, or 0. */
function wn_add(array $f): int {
    $id = 0;
    $ok = wn_update(function (array &$s) use ($f, &$id) {
        $id = (int)$s['next_id'];
        $s['next_id'] = $id + 1;
        $s['items'][] = ['id' => $id, 'area' => $f['area'], 'icon' => $f['icon'], 'title' => $f['title'], 'message' => $f['message'],
                         'active' => true, 'created' => gmdate('Y-m-d H:i:s')];
    });
    return $ok ? $id : 0;
}

/** Change a notice's words, icon or area. It keeps its id and date, so nobody is told about it again. */
function wn_set(int $id, array $f): bool {
    $hit = false;
    $ok = wn_update(function (array &$s) use ($id, $f, &$hit) {
        foreach ($s['items'] as &$it) if ($it['id'] === $id) { $it = ['area' => $f['area'], 'icon' => $f['icon'], 'title' => $f['title'], 'message' => $f['message']] + $it; $hit = true; }
    });
    return $ok && $hit;
}

/** Show or hide a notice. A hidden one is kept here and shown to nobody. */
function wn_set_active(int $id, bool $on): bool {
    $hit = false;
    $ok = wn_update(function (array &$s) use ($id, $on, &$hit) {
        foreach ($s['items'] as &$it) if ($it['id'] === $id) { $it['active'] = $on; $hit = true; }
    });
    return $ok && $hit;
}

/** Delete a notice for good. Its id is never used again. */
function wn_remove(int $id): bool {
    $hit = false;
    $ok = wn_update(function (array &$s) use ($id, &$hit) {
        $n = count($s['items']);
        $s['items'] = array_values(array_filter($s['items'], fn($it) => $it['id'] !== $id));
        $hit = count($s['items']) < $n;
    });
    return $ok && $hit;
}

/** "Nov 14, 2026" in Mountain time from the stored UTC stamp. '' when there is none. */
function wn_date(string $utc): string {
    if ($utc === '') return '';
    try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Denver'))->format('M j, Y'); }
    catch (Throwable $e) { return ''; }
}

/** What MH Send reads (api/whats_new.php): the B2B and Client Emails notices that are showing. */
function wn_feed(): array {
    $out = [];
    foreach (wn_items(['b2b', 'emails']) as $it) {
        $out[] = ['id' => $it['id'], 'area' => $it['area'], 'icon' => $it['icon'], 'title' => $it['title'], 'message' => $it['message'], 'created_at' => $it['created']];
    }
    return $out;
}

// ── The agent portal ─────────────────────────────────────────────────────────

/**
 * The areas whose notices this portal shows: its own always; B2Bs and Client Emails when the portal's menu gives
 * the agent that part of MH Send (portal_send_access(): the same test as the MH Send menu, so a notice never points
 * at something the agent cannot open).
 */
function wn_portal_areas(array $ctx): array {
    $a = function_exists('portal_send_access') ? portal_send_access($ctx) : ['b2b' => false, 'emails' => false];
    $areas = ['portal'];
    if ($a['b2b']) $areas[] = 'b2b';
    if ($a['emails']) $areas[] = 'emails';
    return $areas;
}

/**
 * What the portal's header draws: ['items' => the bell's list, 'new' => the newest notice this person has not seen
 * or null, 'latest' => the newest id listed, 'can_mark' => whether seeing it is recorded].
 *
 * Seen = users.last_seen_announcement_id (the column has been on the table since the hub; nothing used it here).
 * In an admin's preview of someone's portal the marker read is that agent's (their users row by intake_id) and
 * nothing is recorded: looking at a portal must not mark the agent's notices as seen.
 */
function wn_portal_state(mysqli $conn, array $ctx): array {
    $items = array_slice(wn_items(wn_portal_areas($ctx)), 0, WN_PANEL_MAX);
    $mark = WN_PORTAL_BASELINE;
    try {
        if (!empty($ctx['preview'])) {
            $st = $conn->prepare("SELECT MIN(last_seen_announcement_id) FROM users WHERE intake_id = ? AND is_active = 1");
            $acct = (int)($ctx['acct']['id'] ?? 0); $st->bind_param('i', $acct);
        } else {
            $st = $conn->prepare("SELECT last_seen_announcement_id FROM users WHERE id = ? LIMIT 1");
            $uid = (int)($ctx['user']['id'] ?? 0); $st->bind_param('i', $uid);
        }
        $st->execute(); $row = $st->get_result()->fetch_row(); $st->close();
        $mark = max($mark, (int)($row[0] ?? 0));
    } catch (Throwable $e) { /* the page still draws; nothing shows as new */ $mark = PHP_INT_MAX; }
    $new = null;
    foreach ($items as $it) if ($it['id'] > $mark) { $new = $it; break; }   // newest first
    return ['items' => $items, 'new' => $new, 'latest' => $items ? $items[0]['id'] : 0, 'can_mark' => empty($ctx['preview'])];
}

/** Record that a person has seen everything up to $id. Only ever moves the marker forward. */
function wn_portal_mark(mysqli $conn, int $user_id, int $id): bool {
    if ($user_id <= 0 || $id <= 0) return false;
    $top = wn_load()['next_id'] - 1;
    $id = min($id, $top);   // never past the newest notice there is
    $st = $conn->prepare("UPDATE users SET last_seen_announcement_id = ? WHERE id = ? AND last_seen_announcement_id < ? LIMIT 1");
    $st->bind_param('iii', $id, $user_id, $id);
    $ok = $st->execute(); $st->close();
    return $ok;
}
