<?php
/**
 * inc/qr.php — dynamic QR codes (qr.monthaus.com).
 *
 * Shared by qr.php (the public redirect) and qr_codes.php (the admin page), so
 * the two can never disagree about what a valid code or destination is.
 *
 * The one rule behind all of it: a QR code is printed, and a printed square
 * cannot be changed. So the code (the path after qr.monthaus.com/) is fixed at
 * creation, and everything that can change lives behind it in qr_codes.
 *
 * Needs inc/config.php loaded first (PUBLIC_SITE_URL, and the QR_* constants
 * if they have been set there; the defaults below apply otherwise).
 */

// Where the printed codes point. Changing this after codes have been printed
// breaks every one of them, so it is a constant with a warning, not a setting.
if (!defined('QR_BASE_URL'))      define('QR_BASE_URL', 'https://qr.monthaus.com');

// Where an unknown, paused or broken code lands. Never an error page: someone
// standing at a sign in the snow should always end up somewhere useful.
if (!defined('QR_FALLBACK_URL'))  define('QR_FALLBACK_URL', 'https://monthaus.com');

// Destinations must be on this domain or one of its subdomains. Keeps
// qr.monthaus.com from becoming an open redirect to anywhere on the internet.
if (!defined('QR_ALLOWED_DOMAIN')) define('QR_ALLOWED_DOMAIN', 'monthaus.com');

// Tag the visit so the website's analytics can tell QR traffic apart:
// ?utm_source=qr&utm_medium=print&utm_campaign=<code>. Skipped when the
// destination already carries its own utm_source.
if (!defined('QR_ADD_UTM'))       define('QR_ADD_UTM', true);

/** Paths that are not codes, and may never become one. */
const QR_RESERVED = ['admin', 'api', 'www', 'qr', 'robots-txt', 'favicon', 'index', 'login', 'test'];

/** Tidy what someone typed into the shape of a code. Validate afterwards. */
function qr_normalize_code(string $raw): string {
    $c = strtolower(trim($raw));
    $c = preg_replace('~^https?://[^/]+/~', '', $c);   // pasted the whole URL
    $c = preg_replace('~[\s_]+~', '-', $c);
    $c = preg_replace('~[^a-z0-9-]~', '', $c);
    $c = preg_replace('~-{2,}~', '-', $c);
    return trim($c, '-');
}

/** '' when fine, otherwise the sentence to show. */
function qr_code_error(string $code): string {
    if ($code === '')                          return 'The code is empty.';
    if (strlen($code) < 2)                     return 'Codes need at least two characters.';
    if (strlen($code) > 40)                    return 'Keep codes to 40 characters or fewer. Shorter scans more reliably.';
    if (!preg_match('~^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$~', $code))
                                               return 'Use lowercase letters, numbers and hyphens only.';
    if (in_array($code, QR_RESERVED, true))    return '"' . $code . '" is reserved. Pick another.';
    return '';
}

/** '' when the destination is acceptable, otherwise the sentence to show. */
function qr_dest_error(string $url): string {
    $url = trim($url);
    if ($url === '')                           return 'Enter the page the code should open.';
    if (strlen($url) > 1000)                   return 'That address is too long.';
    $p = parse_url($url);
    if (!$p || empty($p['host']))              return 'That is not a full web address. Start it with https://';
    if (strtolower($p['scheme'] ?? '') !== 'https')
                                               return 'The address must start with https://';
    $host   = strtolower($p['host']);
    $domain = strtolower(QR_ALLOWED_DOMAIN);
    if ($host !== $domain && !str_ends_with($host, '.' . $domain))
                                               return 'Codes can only point at ' . $domain . ' (or one of its subdomains).';
    if ($host === strtolower((string) parse_url(QR_BASE_URL, PHP_URL_HOST)))
                                               return 'A code cannot point back at ' . $host . '.';
    if (!empty($p['user']) || !empty($p['pass']))
                                               return 'That address has a username in it.';
    return '';
}

/** The printed address for a code. */
function qr_public_url(string $code): string {
    return rtrim(QR_BASE_URL, '/') . '/' . $code;
}

/** A broker's page on the public website, the same link agent.php uses. */
function qr_profile_url(string $slug): string {
    $base = defined('PUBLIC_SITE_URL') ? PUBLIC_SITE_URL : QR_FALLBACK_URL;
    // /{slug} since 2026-10-13 (the site 301s the old broker.php?s= form, so codes already printed still work)
    return rtrim($base, '/') . '/' . rawurlencode($slug);
}

/**
 * Where a code sends people right now, or null when it cannot say (paused, a
 * profile code whose broker has no web address, a stored URL that no longer
 * passes the rules). The caller falls back; this never invents a target.
 *
 * $row needs dest_type, dest_url, is_active and `slug` (the broker's
 * marketing_intakes.slug, joined in).
 */
function qr_resolve(array $row): ?string {
    if (!(int) ($row['is_active'] ?? 0)) return null;
    if (($row['dest_type'] ?? '') === 'profile') {
        $slug = trim((string) ($row['slug'] ?? ''));
        return $slug === '' ? null : qr_profile_url($slug);
    }
    $url = trim((string) ($row['dest_url'] ?? ''));
    return qr_dest_error($url) === '' ? $url : null;
}

/** Add the utm tags, keeping any query string and #fragment intact. */
function qr_with_utm(string $url, string $code): string {
    if (!QR_ADD_UTM || stripos($url, 'utm_source=') !== false) return $url;
    $frag = '';
    if (($h = strpos($url, '#')) !== false) { $frag = substr($url, $h); $url = substr($url, 0, $h); }
    $tags = http_build_query(['utm_source' => 'qr', 'utm_medium' => 'print', 'utm_campaign' => $code]);
    return $url . (str_contains($url, '?') ? '&' : '?') . $tags . $frag;
}

/** Record a change in qr_code_changes. Failures are logged, never fatal. */
function qr_log_change(mysqli $conn, int $qr_id, string $what, ?string $old, ?string $new, ?int $user_id): void {
    $s = $conn->prepare("INSERT INTO qr_code_changes (qr_id, what, old_value, new_value, changed_by) VALUES (?, ?, ?, ?, ?)");
    if (!$s) { error_log('qr_log_change: ' . $conn->error); return; }
    $s->bind_param('isssi', $qr_id, $what, $old, $new, $user_id);
    $s->execute();
    $s->close();
}

/** Today's date in Mountain time, the day a scan is counted against. */
function qr_today(): string {
    return (new DateTime('now', new DateTimeZone('America/Denver')))->format('Y-m-d');
}

// ── Shares: one code on more than one agent's portal (2026-10-26) ───────────
// sql/qr_code_shares_v1.sql. A share puts a code on another agent's portal
// without moving it: qr_codes.intake_id stays the owner. The portal lists and
// edits a code when the account owns it OR a share names the account (or one
// of its teams), so two people change the same row and there is never a
// second destination. Set on the admin page only; the portal cannot share.

/** True once sql/qr_code_shares_v1.sql has run. Needs inc/schema.php. */
function qr_shares_ready(mysqli $conn): bool {
    static $ready = null;
    if ($ready === null) $ready = function_exists('mk_table_exists') && mk_table_exists($conn, 'qr_code_shares');
    return $ready;
}

/**
 * The WHERE clause that picks the codes a set of account ids may see and
 * change: owned by one of them, or shared with one of them. $in is a SQL IN
 * list of ints the caller built from its own context, never from the request.
 * $q is the qr_codes alias in the caller's query.
 */
function qr_scope_sql(mysqli $conn, string $in, string $q = 'q'): string {
    $own = "{$q}.intake_id IN ({$in})";
    if (!qr_shares_ready($conn)) return $own;
    return "({$own} OR {$q}.id IN (SELECT qr_id FROM qr_code_shares WHERE intake_id IN ({$in})))";
}

/**
 * Who each code is shared with: [qr_id => [['id' => intake id, 'name' => agent name], ...]],
 * for the codes in $qr_ids (ints). Empty before the migration.
 */
function qr_shares_for(mysqli $conn, array $qr_ids): array {
    $out = [];
    $qr_ids = array_values(array_filter(array_map('intval', $qr_ids)));
    if (!$qr_ids || !qr_shares_ready($conn)) return $out;
    $list = implode(',', $qr_ids);
    $r = $conn->query("SELECT s.qr_id, s.intake_id, mi.agent_name
                         FROM qr_code_shares s
                    LEFT JOIN marketing_intakes mi ON mi.id = s.intake_id
                        WHERE s.qr_id IN ({$list})
                     ORDER BY mi.agent_name, s.intake_id");
    if ($r) foreach ($r as $row) {
        $out[(int) $row['qr_id']][] = ['id' => (int) $row['intake_id'], 'name' => (string) ($row['agent_name'] ?? ('Broker #' . $row['intake_id']))];
    }
    return $out;
}
