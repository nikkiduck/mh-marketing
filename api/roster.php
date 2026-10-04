<?php
/**
 * api/roster.php — the agent feed monthaus.com reads.
 *
 * Read-only JSON of every agent with a slug, inactive ones included (the site
 * needs them to take people down). Shape: docs/HANDOFF-public-site-agent-integration.md.
 *
 * NO sign-in and NO allowlist — this page deliberately does not include
 * inc/auth.php. Access is a shared secret instead:
 *
 *   Authorization: Bearer <ROSTER_FEED_TOKEN>        (constant in inc/db.php)
 *
 * Header only, never ?token= — query strings are written to the Apache access
 * log. An undefined or empty token refuses everything (fail closed).
 *
 *   curl -s -H "Authorization: Bearer $TOKEN" https://marketing.monthaus.com/api/roster.php | head
 */

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/agent_roster.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function feed_fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// db.php both defines ROSTER_FEED_TOKEN and opens the connection, so the
// connection exists before the check. Nothing is queried until it passes.
$bearer = '';
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($hdr === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $hdr = $v;
}
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $hdr, $m)) $bearer = $m[1];

require_once __DIR__ . '/../inc/db.php';

if (!defined('ROSTER_FEED_TOKEN') || strlen((string)ROSTER_FEED_TOKEN) < 24) {
    feed_fail(503, 'feed not configured');
}
if ($bearer === '' || !hash_equals((string)ROSTER_FEED_TOKEN, $bearer)) {
    feed_fail(401, 'unauthorized');
}

$chk = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'web_status'");
if (!$chk || !$chk->fetch_row()) feed_fail(503, 'roster migration not applied');

// ── Agents ───────────────────────────────────────────────────────────────────
$res = $conn->query("
    SELECT id, slug, web_status, is_active, agent_name, mls_full_name, agent_title, bio_text,
           mh_email, mls_email, cell_phone, mls_phone, sort_order, in_fub, office, service_area,
           social_instagram, social_facebook, social_linkedin, social_tiktok, website_url,
           headshot_url, headshot_face_url, updated_at
      FROM marketing_intakes
     WHERE slug IS NOT NULL AND slug <> ''" . mk_agents_only_sql($conn) . "   -- teams are marketing-only, never on the website
     ORDER BY sort_order, COALESCE(NULLIF(agent_name,''), mls_full_name)");
if (!$res) feed_fail(500, 'query failed');
$rows = $res->fetch_all(MYSQLI_ASSOC);

// ── Leadership (leadership_v1.sql) ───────────────────────────────────────────
// docs/HANDOFF-leadership-and-staff.md, Phase 3. Agents and staff marked for
// the Leadership page, in its order. Agents only while they are on the website;
// teams never. `agents` above is unchanged and contains no staff
// (mk_agents_only_sql). Before the migration there is no `leadership` key at
// all, which the site reads as "old feed, keep what you have".
$leaders = null;
$chk2 = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'leadership_show'");
if ($chk2 && $chk2->fetch_row()) {
    $lr = $conn->query("
        SELECT slug, entity_type, agent_name, mls_full_name, agent_title, bio_text, mh_email, mls_email, cell_phone, mls_phone,
               headshot_url, headshot_face_url, leadership_sort
          FROM marketing_intakes
         WHERE leadership_show = 1 AND is_active = 1 AND status <> 'archived'
           AND (entity_type = 'staff' OR (entity_type = 'agent' AND web_status = 'approved'))
           AND slug IS NOT NULL AND slug <> ''
         ORDER BY leadership_sort, COALESCE(NULLIF(agent_name,''), mls_full_name)");
    if (!$lr) feed_fail(500, 'leadership query failed');
    $leaders = $lr->fetch_all(MYSQLI_ASSOC);
}

$idents = [];
// 'Other brokerage' = the board still has the agent under their previous firm
// (cron/sync_anyprop_roster.php step 4b): kept off the website, or that firm's
// listings would be credited to a Mont Haus profile.
$r = $conn->query("SELECT intake_id, market, mls_agent_id, is_alias, member_status
                     FROM agent_mls_ids WHERE member_status <> 'Other brokerage'
                    ORDER BY market, is_alias, mls_agent_id");
if ($r) foreach ($r->fetch_all(MYSQLI_ASSOC) as $i) {
    $idents[(int)$i['intake_id']][] = [
        'market'        => $i['market'],
        'mls_agent_id'  => $i['mls_agent_id'],
        'is_alias'      => (bool)$i['is_alias'],
        'member_status' => $i['member_status'],
    ];
}
$conn->close();

/**
 * Social values are stored as handles by agent.php (it strips the site prefix).
 * The feed publishes full URLs, which is what the website's icons link to.
 */
function feed_social(?string $v, string $prefix): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('#^https?://#i', $v)) return $v;
    return $prefix . ltrim($v, '@/');
}

/** Only images this portal hosts are published. A Dropbox share link is a page, not an image. */
function feed_photo(?string $url): string {
    $u = trim((string)$url);
    if ($u === '') return '';
    if (str_starts_with($u, 'photo.php')) return rtrim(SITE_URL, '/') . '/' . $u;
    if (str_starts_with($u, rtrim(SITE_URL, '/') . '/')) return $u;
    return '';
}

$agents = [];
foreach ($rows as $a) {
    $status = (int)$a['is_active'] === 0 ? 'inactive' : $a['web_status'];   // archived in marketing = off the site
    $name   = trim((string)$a['agent_name']) ?: trim((string)$a['mls_full_name']);
    if ($name === '') continue;
    $agents[] = [
        'key'                => $a['slug'],
        'status'             => $status,
        'display_name'       => $name,
        'mls_full_name'      => (string)$a['mls_full_name'],
        'title'              => (string)$a['agent_title'],
        'bio'                => mk_clean_bio($a['bio_text']),
        'email'              => strtolower(trim((string)($a['mh_email'] ?: $a['mls_email']))),
        'phone'              => mk_e164($a['cell_phone'] ?: $a['mls_phone']),
        'sort_order'         => (int)$a['sort_order'],
        'in_fub'             => (bool)$a['in_fub'],
        'socials'            => [
            'instagram' => feed_social($a['social_instagram'], 'https://www.instagram.com/'),
            'facebook'  => feed_social($a['social_facebook'],  'https://www.facebook.com/'),
            'linkedin'  => feed_social($a['social_linkedin'],  'https://www.linkedin.com/in/'),
            'tiktok'    => feed_social($a['social_tiktok'],    'https://www.tiktok.com/@'),
            'website'   => feed_social($a['website_url'],      'https://'),
        ],
        'headshot_url'       => feed_photo($a['headshot_url']),
        'headshot_thumb_url' => feed_photo($a['headshot_face_url']),
        'offices'            => ($a['office'] && $a['office'] !== 'other') ? [$a['office']] : [],
        'service_area'       => (string)$a['service_area'],
        'identities'         => $idents[(int)$a['id']] ?? [],
        'updated_at'         => $a['updated_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($a['updated_at'] . ' UTC')) : null,
    ];
}

$out = ['generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'count' => count($agents), 'agents' => $agents];
if ($leaders !== null) {
    $out['leadership'] = [];
    foreach ($leaders as $l) {
        $name = trim((string)$l['agent_name']) ?: trim((string)$l['mls_full_name']);
        if ($name === '') continue;
        $is_staff = $l['entity_type'] === 'staff';
        $out['leadership'][] = [
            'key'                => $l['slug'],
            'type'               => $is_staff ? 'staff' : 'agent',
            'agent_key'          => $is_staff ? null : $l['slug'],   // links to the broker page; null for staff
            'name'               => $name,
            'title'              => (string)$l['agent_title'],
            'bio'                => mk_clean_bio($l['bio_text']),
            'email'              => strtolower(trim((string)($l['mh_email'] ?: $l['mls_email']))),
            'phone'              => mk_e164($l['cell_phone'] ?: $l['mls_phone']),
            'headshot_url'       => feed_photo($l['headshot_url']),
            'headshot_thumb_url' => feed_photo($l['headshot_face_url']),
            'sort'               => (int)$l['leadership_sort'],
        ];
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
