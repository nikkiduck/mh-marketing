<?php
/**
 * index.php — the agent roster: the landing page and the working list.
 *
 * Sourced from office_roster (the Spark-era roster, until the Anyprop sync
 * replaces it) UNION ALL marketing_intakes, as before. What this page adds
 * (Nikki, 2026-09-21):
 *   · a table as the default view, cards as an option (remembered per browser);
 *   · "Needs attention": counts that filter the list to what needs doing
 *     (agents to onboard, website approvals, incomplete website profiles,
 *     balances due) plus the Pipeline review queue;
 *   · a completeness meter per agent (headshot, face crop, bio, phone, MLS IDs,
 *     website approved);
 *   · Onboard and Offboard as single actions (inc/agent_lifecycle.php).
 *
 * Rules carried over from the previous version, still load-bearing:
 *   · the card name is COALESCE(mi.agent_name, r.name): the marketing record
 *     wins, because office_roster.name is overwritten by the nightly sync;
 *   · BOTH ARMS OF THE UNION SELECT THE SAME COLUMNS IN THE SAME ORDER;
 *   · $conn->close() comes early; everything that queries sits above it;
 *   · the balance marker uses mh_agent_financials(), like billing.php;
 *   · initials() is identical to agent.php's.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/_onboarding.php';
require_once __DIR__ . '/inc/schema.php';
require_once __DIR__ . '/inc/financials.php';
require_once __DIR__ . '/inc/agent_lifecycle.php';
require_once __DIR__ . '/inc/agent_roster.php';   // mk_agents_only_sql()
require_once __DIR__ . '/inc/site_sync.php';      // "Sync to Website"
require_login();
// Agents land in their portal instead of this admin page's 403 (2026-10-01).
// Not a guard loop: /portal/ never sends anyone back here.
if (($_SESSION['user_role'] ?? '') === 'agent') { header('Location: /portal/'); exit; }
require_role('admin');

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $pid    = (int)($_POST['id'] ?? 0);
    $flash  = [];

    // Blank intake, straight to the agent page with the contact card open.
    if ($action === 'new_agent') {
        $uid   = (int)($_SESSION['user_id'] ?? 0);
        $today = date('Y-m-d');
        $stmt  = $conn->prepare("INSERT INTO marketing_intakes (agent_name, intake_date, status, is_active, created_by)
                                 VALUES ('', ?, 'active', 1, ?)");
        $stmt->bind_param('si', $today, $uid);
        $stmt->execute();
        $new_id = (int)$conn->insert_id;
        $stmt->close();
        mkt_seed_onboarding_tasks($conn, $new_id);
        header("Location: agent.php?id={$new_id}&tab=overview&open=contact"); exit;
    }

    // Onboard. An office_roster row with no intake yet gets one first (the
    // same insert roster.php's create_intake does), then the common steps.
    if ($action === 'onboard' || $action === 'promote' || $action === 'restore') {
        $roster_id = (int)($_POST['roster_id'] ?? 0);
        if (!$pid && $roster_id) {
            $chk = $conn->query("SELECT id FROM marketing_intakes WHERE roster_id = {$roster_id} LIMIT 1")->fetch_assoc();
            // Already here but not linked to this Spark roster line (how Jonathan
            // got a second row, 2026-09-22): link it instead of adding a duplicate.
            if (!$chk && ($ro = $conn->query("SELECT name, email FROM office_roster WHERE id = {$roster_id}")->fetch_assoc())) {
                $em = strtolower(trim((string)$ro['email'])); $nm = strtolower(trim((string)$ro['name']));
                $s = $conn->prepare("SELECT id FROM marketing_intakes
                                      WHERE is_active = 1 AND status <> 'archived' AND roster_id IS NULL" . mk_agents_only_sql($conn) . "
                                        AND ((? <> '' AND (LOWER(mh_email) = ? OR LOWER(mls_email) = ? OR LOWER(alt_email) = ?))
                                             OR LOWER(TRIM(agent_name)) = ?)");
                $s->bind_param('sssss', $em, $em, $em, $em, $nm); $s->execute();
                $hits = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
                if (count($hits) === 1) {
                    $chk = $hits[0];
                    $conn->query("UPDATE marketing_intakes SET roster_id = {$roster_id} WHERE id = " . (int)$chk['id']);
                }
            }
            if ($chk) {
                $pid = (int)$chk['id'];
            } elseif ($ro = $conn->query("SELECT name, email, phone, title FROM office_roster WHERE id = {$roster_id}")->fetch_assoc()) {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                $s = $conn->prepare("INSERT INTO marketing_intakes (agent_name, agent_title, mh_email, cell_phone, roster_id,
                                                                    intake_date, created_by, status, is_active)
                                     VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'active', 1)");
                $s->bind_param('ssssii', $ro['name'], $ro['title'], $ro['email'], $ro['phone'], $roster_id, $uid);
                $s->execute(); $pid = (int)$conn->insert_id; $s->close();
            }
        }
        if ($pid) {
            $flash = mk_onboard($conn, $pid);
            $name = (string)($conn->query("SELECT agent_name FROM marketing_intakes WHERE id = {$pid}")->fetch_row()[0] ?? '');
            $_SESSION['mk_flash'] = ['title' => ($action === 'restore' ? 'Restored ' : 'Added ') . $name,
                                     'lines' => $flash, 'link' => "agent.php?id={$pid}&tab=tasks"];
            header('Location: index.php'); exit;
        }
    }

    // Inactive, but still with Mont Haus (the MLS was wrong, or a transfer is
    // in progress): back to Active. The sync will not flag them again for the
    // same missing identity.
    // One click: the rest of the marketing checklist is done.
    if ($pid && $action === 'complete_checklist') {
        $name = (string)($conn->query("SELECT agent_name FROM marketing_intakes WHERE id = {$pid}")->fetch_row()[0] ?? '');
        $_SESSION['mk_flash'] = ['title' => 'Checklist: ' . $name, 'lines' => mk_complete_checklist($conn, $pid),
                                 'link' => "agent.php?id={$pid}&tab=tasks"];
        header('Location: index.php'); exit;
    }

    // The status badge: Inactive by hand (in the MLS under MH, not an active
    // broker), or back to Active. Nothing else changes.
    if ($pid && ($action === 'set_inactive' || $action === 'set_active')) {
        if ($action === 'set_inactive') {
            $conn->query("UPDATE marketing_intakes SET status = 'inactive' WHERE id = {$pid} AND status <> 'archived'");
        } else {
            $conn->query("UPDATE marketing_intakes SET status = 'active'"
                       . (mk_column_exists($conn, 'marketing_intakes', 'departure_detected_at') ? ', departure_detected_at = NULL' : '')
                       . " WHERE id = {$pid} AND status <> 'archived'");
        }
        $name = (string)($conn->query("SELECT agent_name FROM marketing_intakes WHERE id = {$pid}")->fetch_row()[0] ?? '');
        $_SESSION['mk_flash'] = $action === 'set_inactive'
            ? ['title' => $name . ' is Inactive', 'lines' => ['Their website profile, Hot Sheets and FUB were not changed. Use Offboard to turn those off.']]
            : ['title' => $name . ' is Active again', 'lines' => ['Nothing else was changed.']];
        header('Location: index.php'); exit;
    }

    if ($pid && $action === 'keep') {
        $conn->query("UPDATE marketing_intakes SET departure_detected_at = NULL WHERE id = {$pid}");
        $name = (string)($conn->query("SELECT agent_name FROM marketing_intakes WHERE id = {$pid}")->fetch_row()[0] ?? '');
        $_SESSION['mk_flash'] = ['title' => $name . ' is Active again', 'lines' => ['Marked as still with Mont Haus. Nothing else was changed.']];
        header('Location: index.php'); exit;
    }

    // Push the roster to the public website now, instead of waiting for its
    // nightly pull. The work happens over there; this shows what it reported.
    if ($action === 'sync_site') {
        $r = mk_site_sync(!empty($_POST['dry']));
        $_SESSION['mk_flash'] = [
            'title' => $r['ok'] ? (empty($_POST['dry']) ? 'Website synced' : 'Website sync, dry run') : 'Website sync did not run',
            'lines' => $r['lines'],
            'ok'    => $r['ok'],
            'log'   => $r['log'],
        ];
        header('Location: index.php'); exit;
    }

    if ($pid && ($action === 'offboard' || $action === 'archive')) {
        $name = (string)($conn->query("SELECT agent_name FROM marketing_intakes WHERE id = {$pid}")->fetch_row()[0] ?? '');
        $_SESSION['mk_flash'] = ['title' => 'Offboarded ' . $name, 'lines' => mk_offboard($conn, $pid)];
        header('Location: index.php'); exit;
    }
    header('Location: index.php'); exit;
}

$flash = $_SESSION['mk_flash'] ?? null;
unset($_SESSION['mk_flash']);

// ── Load the roster ──────────────────────────────────────────────────────────
$has_initials = mk_column_exists($conn, 'marketing_intakes', 'initials');
$has_web      = mk_column_exists($conn, 'marketing_intakes', 'web_status');
$col_initials = $has_initials ? 'mi.initials' : 'NULL';
// Columns from agent_roster_v1.sql. When it has not run, both arms select the
// same NULL placeholders, so the column lists still line up.
$col_web = $has_web
    ? "mi.web_status, mi.headshot_face_url, mi.in_fub, mi.office, mi.mls_email, mi.mls_phone, mi.departure_detected_at"
    : "NULL AS web_status, NULL AS headshot_face_url, 0 AS in_fub, NULL AS office, NULL AS mls_email, NULL AS mls_phone, NULL AS departure_detected_at";
$has_bio = "(mi.bio_text IS NOT NULL AND TRIM(mi.bio_text) NOT IN ('', '<p><br></p>', '<p></p>'))";
// agent_roster_v2_teams.sql: a team (Weber Boxer Group) is a marketing entity.
$has_team = mk_column_exists($conn, 'marketing_intakes', 'entity_type');
$col_web .= $has_team ? ", mi.entity_type" : ", 'agent' AS entity_type";
// Staff (leadership_v1.sql) are not agents and are not on this roster: they are
// managed on leadership.php. Teams stay (they are marketed and billed).
$no_staff = $has_team ? " AND mi.entity_type <> 'staff'" : '';

$agents = [];
$res = $conn->query("
    SELECT
        r.id              AS roster_id,
        COALESCE(mi.agent_name, r.name) AS agent_name,
        {$col_initials}   AS initials,
        r.title           AS agent_title,
        r.markets,
        r.agent_key,
        r.vail_agent_key,
        r.mls_id_aspen,
        r.mls_id_vail,
        r.active          AS roster_active,
        mi.id             AS intake_id,
        mi.agent_title    AS intake_title,
        mi.status         AS intake_status,
        mi.headshot_url,
        mi.start_date,
        mi.intake_date,
        mi.updated_at     AS updated_at,
        COUNT(mt.id)      AS open_tasks,
        mi.mh_email,
        COALESCE(NULLIF(mi.cell_phone,''), NULLIF(r.phone,'')) AS phone,
        {$has_bio}        AS has_bio,
        {$col_web}
    FROM office_roster r
    LEFT JOIN marketing_intakes mi
           ON mi.roster_id = r.id AND mi.is_active = 1{$no_staff}
    LEFT JOIN marketing_tasks mt
           ON mt.intake_id = mi.id AND mt.status = 'open'
    -- A Spark roster line nobody is linked to, for someone who already has a
    -- row here (same email), is not shown a second time.
    WHERE mi.id IS NOT NULL OR r.email IS NULL OR r.email = ''
       OR NOT EXISTS (SELECT 1 FROM marketing_intakes x
                       WHERE x.is_active = 1 AND x.roster_id IS NULL
                         AND (LOWER(x.mh_email) = LOWER(r.email) OR LOWER(x.mls_email) = LOWER(r.email)))
    GROUP BY r.id

    UNION ALL

    SELECT
        NULL              AS roster_id,
        mi.agent_name     AS agent_name,
        {$col_initials}   AS initials,
        mi.agent_title    AS agent_title,
        NULL              AS markets,
        mi.mls_id_aspen   AS agent_key,
        mi.mls_id_vail    AS vail_agent_key,
        mi.mls_id_aspen,
        mi.mls_id_vail,
        1                 AS roster_active,
        mi.id             AS intake_id,
        mi.agent_title    AS intake_title,
        mi.status         AS intake_status,
        mi.headshot_url,
        mi.start_date,
        mi.intake_date,
        mi.updated_at     AS updated_at,
        COUNT(mt.id)      AS open_tasks,
        mi.mh_email,
        NULLIF(mi.cell_phone,'') AS phone,
        {$has_bio}        AS has_bio,
        {$col_web}
    FROM marketing_intakes mi
    LEFT JOIN marketing_tasks mt
           ON mt.intake_id = mi.id AND mt.status = 'open'
    WHERE mi.is_active = 1 AND mi.roster_id IS NULL{$no_staff}
    GROUP BY mi.id

    ORDER BY agent_name ASC
");
if ($res) $agents = $res->fetch_all(MYSQLI_ASSOC);

// Archived intakes are not in the UNION (both arms want is_active = 1), so the
// Archived filter gets its own small query.
$archived = [];
$r = $conn->query("SELECT mi.id AS intake_id, mi.roster_id, mi.agent_name, {$col_initials} AS initials, mi.agent_title,
                          mi.agent_title AS intake_title, 'archived' AS intake_status, mi.headshot_url, mi.start_date,
                          mi.intake_date, mi.updated_at, 0 AS open_tasks, mi.mh_email, mi.cell_phone AS phone,
                          {$has_bio} AS has_bio, {$col_web}, NULL AS markets, NULL AS agent_key, NULL AS vail_agent_key
                     FROM marketing_intakes mi WHERE mi.is_active = 0{$no_staff} ORDER BY mi.archived_at DESC");
if ($r) $archived = $r->fetch_all(MYSQLI_ASSOC);

// Board identities (agent_roster_v1.sql): how a CREN-only agent gets a badge.
$id_markets = [];
if (mk_table_exists($conn, 'agent_mls_ids')) {
    $r = $conn->query("SELECT DISTINCT intake_id, market FROM agent_mls_ids WHERE member_status = 'Active' AND is_alias = 0");
    if ($r) while ($row = $r->fetch_assoc()) $id_markets[(int)$row['intake_id']][$row['market']] = true;
}

// Hot Sheet subscriptions, by agent and by address.
$hs_by_id = []; $hs_by_email = [];
if (mk_table_exists($conn, 'hs_subscribers')) {
    $r = $conn->query("SELECT intake_id, email FROM hs_subscribers WHERE is_active = 1 AND unsubscribed_at IS NULL");
    if ($r) while ($row = $r->fetch_assoc()) {
        if ($row['intake_id']) $hs_by_id[(int)$row['intake_id']] = true;
        $hs_by_email[strtolower($row['email'])] = true;
    }
}

// Teams and their members, both directions.
$team_members = []; $member_teams = [];
if ($has_team && mk_table_exists($conn, 'team_members')) {
    $r = $conn->query("SELECT tm.team_id, tm.member_id, t.agent_name AS team_name, " . ($has_initials ? 't.initials' : 'NULL') . " AS team_initials, m.agent_name AS member_name
                         FROM team_members tm
                         JOIN marketing_intakes t ON t.id = tm.team_id
                         JOIN marketing_intakes m ON m.id = tm.member_id
                        ORDER BY tm.team_id, tm.sort_order");
    if ($r) while ($row = $r->fetch_assoc()) {
        $team_members[(int)$row['team_id']][] = $row['member_name'];
        $member_teams[(int)$row['member_id']][] = ['id' => (int)$row['team_id'], 'name' => $row['team_name'], 'initials' => $row['team_initials']];
    }
}

// The marketing checklist (onboarding tasks): open / total per agent. Since
// 2026-09-22 this is all "Onboarding" means; it does not decide Active.
$checklist = [];
$r = $conn->query("SELECT intake_id, SUM(status = 'open') AS open_n, COUNT(*) AS total_n
                     FROM marketing_tasks WHERE category = 'onboarding' AND parent_id IS NULL GROUP BY intake_id");
if ($r) while ($row = $r->fetch_assoc()) $checklist[(int)$row['intake_id']] = [(int)$row['open_n'], (int)$row['total_n']];

// Pipeline deals waiting for review.
$pipeline_pending = 0;
if (mk_table_exists($conn, 'hs_pipeline_transactions')) {
    $pipeline_pending = (int)$conn->query("SELECT COUNT(*) FROM hs_pipeline_transactions WHERE review_status = 'pending'")->fetch_row()[0];
}

// Balances, BEFORE the close (see CLAUDE.md: the harness once missed a query
// written below it). Same definition as billing.php: every unpaid month's
// broker total, from mh_agent_financials().
$paid_months = [];
$r = $conn->query("SELECT intake_id, ym FROM marketing_billing_months WHERE paid_at IS NOT NULL");
if ($r) while ($row = $r->fetch_assoc()) $paid_months[(int)$row['intake_id']][$row['ym']] = true;
$balances = [];
foreach ($agents as $a) {
    $iid = (int)($a['intake_id'] ?? 0);
    if (!$iid || isset($balances[$iid]) || ($a['intake_status'] ?? '') === 'roster') continue;
    $fin = mh_agent_financials($conn, $iid);
    $out = 0.0;
    foreach ($fin['months'] as $ym => $m) if (!isset($paid_months[$iid][$ym])) $out += (float)$m['broker'];
    $balances[$iid] = $out;
}

$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

/** Identical to agent.php's: the roster and the profile show the same avatar. */
function initials(string $name, ?string $override = null): string {
    $o = strtoupper(trim((string)$override));
    if ($o !== '') return substr($o, 0, 3);
    $parts = preg_split('/\s+/', trim($name));
    $i = strtoupper($parts[0][0] ?? '');
    if (count($parts) > 1) $i .= strtoupper($parts[count($parts)-1][0] ?? '');
    return substr($i, 0, 2);
}

/** Boards this agent is on: [slug => label] (labels from the registry, inc/boards.php). Identities first, then Spark-era keys. */
function mk_boards(array $a, array $id_markets): array {
    $b = [];
    foreach (array_keys($id_markets[(int)($a['intake_id'] ?? 0)] ?? []) as $m) $b[$m] = mk_board_label($m);
    if (!empty($a['agent_key']) || !empty($a['mls_id_aspen'])) $b['aspen'] = mk_board_label('aspen');
    if (!empty($a['vail_agent_key']) || !empty($a['mls_id_vail'])) $b['vail'] = mk_board_label('vail');
    foreach (array_filter(array_map('trim', explode(',', (string)($a['markets'] ?? '')))) as $m) {
        $b[strtolower($m)] = mk_board_label(strtolower($m));
    }
    return $b;
}

/**
 * Completeness: [done, total, missing labels]. A photo only counts when this
 * portal hosts it (photo.php / our own URL): a Dropbox link is not an image the
 * website can use.
 */
function mk_completeness(array $a, array $boards, bool $has_web): array {
    $hosted = fn($u) => $u && (str_starts_with($u, 'photo.php') || str_starts_with($u, rtrim(SITE_URL, '/') . '/'));
    $checks = [
        'Headshot'     => $hosted((string)$a['headshot_url']) || (!$has_web && !empty($a['headshot_url'])),
        'Bio'          => (bool)$a['has_bio'],
        'Phone'        => trim((string)($a['phone'] ?: ($a['mls_phone'] ?? ''))) !== '',
        'MLS IDs'      => (bool)$boards,
    ];
    if ($has_web) {
        $checks['Face crop'] = $hosted((string)$a['headshot_face_url']);
        $checks['On website'] = ($a['web_status'] ?? '') === 'approved';
    }
    $missing = array_keys(array_filter($checks, fn($v) => !$v));
    return [count($checks) - count($missing), count($checks), $missing];
}

// ── Shape every row once; both views and the counts read from this ──────────
// An office_roster person whose intake was archived shows under Archived only,
// not also as "To onboard" (the UNION's first arm joins active intakes only).
$archived_roster = array_flip(array_filter(array_map(fn($a) => (int)$a['roster_id'], $archived)));

$rows = [];
foreach (array_merge($agents, $archived) as $a) {
    if (empty($a['intake_id']) && isset($archived_roster[(int)$a['roster_id']])) continue;
    $iid       = (int)($a['intake_id'] ?? 0);
    // Active = with Mont Haus in the MLS (Nikki, 2026-09-22). Inactive = the
    // MLS stopped listing them under MH; nothing else changes until she acts.
    // Old 'roster' / 'pending' rows are simply Active (cron/activate_roster.php
    // converts them). $is_roster: a Spark-era office_roster person with no
    // intake yet, shown Active with an Add button until the nightly
    // activate_roster.php run gives them one.
    $is_roster = !$iid;
    // Inactive two ways: the MLS stopped listing them (departure_detected_at,
    // red dot + email) or Nikki marked them (status 'inactive': in the MLS
    // under MH but not an active broker, e.g. the TC). The sync never writes
    // status, so her choice sticks.
    $mls_gone  = !empty($a['departure_detected_at']);
    $manual_in = ($a['intake_status'] ?? '') === 'inactive';
    $status    = ($a['intake_status'] ?? '') === 'archived' ? 'archived'
               : ($mls_gone || $manual_in ? 'inactive' : 'active');
    [$ck_open, $ck_total] = $checklist[$iid] ?? [0, 0];
    $boards    = mk_boards($a, $id_markets);
    [$done, $total, $missing] = mk_completeness($a, $boards, $has_web);
    $email     = strtolower(trim((string)($a['mh_email'] ?: ($a['mls_email'] ?? ''))));
    $hs        = ($iid && isset($hs_by_id[$iid])) || ($email !== '' && isset($hs_by_email[$email]));
    $bal       = $balances[$iid] ?? 0.0;
    $is_team   = ($a['entity_type'] ?? 'agent') === 'team';
    $web       = $has_web && $iid && !$is_roster && !$is_team ? (string)$a['web_status'] : '';
    $attn = [];
    if ($status === 'inactive' && $mls_gone && !$manual_in)                $attn[] = 'inactive';
    if ($status === 'active' && $web === 'pending')                        $attn[] = 'web_pending';
    if ($web === 'approved' && (in_array('Headshot', $missing, true) || in_array('Bio', $missing, true))) $attn[] = 'web_incomplete';
    if ($is_team) { $done = $total = 0; $missing = []; }   // a team has no website profile to complete
    if ($bal > 0)                                                          $attn[] = 'balance';
    $name  = trim((string)$a['agent_name']) ?: '(no name yet)';
    $parts = preg_split('/\s+/', $name);
    $rows[] = $a + [
        '_iid' => $iid, '_status' => $status, '_manual_inactive' => $manual_in, '_mls_gone' => $mls_gone, '_team' => $is_team, '_roster_only' => $is_roster,
        '_ck_open' => $ck_open, '_ck_total' => $ck_total, '_onb' => !$is_team && $status === 'active' && $ck_open > 0,
        '_members' => $team_members[$iid] ?? [], '_teams' => $member_teams[$iid] ?? [], '_boards' => $boards, '_done' => $done, '_total' => $total,
        '_missing' => $missing, '_hs' => $hs, '_bal' => $bal, '_web' => $web, '_attn' => $attn, '_name' => $name,
        '_first' => strtolower($parts[0] ?? ''), '_last' => $is_team ? strtolower($name) : strtolower($parts[count($parts) - 1] ?? ''),   // a team files under its first word
        '_offboard' => $iid && $status !== 'archived'
            ? mk_offboard_preview($a, $hs, $has_web) : [],
    ];
}

// No Onboarding tab since 2026-10-02 (Nikki): the checklist lives on the
// Profile editor, and the Tasks column still shows open items.
$counts = ['active' => 0, 'inactive' => 0, 'archived' => 0];
$attn_counts = ['inactive' => 0, 'web_pending' => 0, 'web_incomplete' => 0, 'balance' => 0];
$offices = [];
foreach ($rows as $x) {
    if (isset($counts[$x['_status']])) $counts[$x['_status']]++;
    foreach ($x['_attn'] as $k) $attn_counts[$k]++;
}
ksort($offices);
$all_boards = [];
foreach ($rows as $x) $all_boards += $x['_boards'];
asort($all_boards);

$status_label = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];
$web_label    = ['approved' => 'On website', 'pending' => 'Awaiting approval', 'inactive' => 'Off website'];
$attn_label   = ['inactive' => 'no longer with Mont Haus in the MLS', 'web_pending' => 'awaiting website approval',
                 'web_incomplete' => 'on the website without a headshot or bio', 'balance' => 'with a balance due'];

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Agent Roster | Mont Haus Marketing</title>
  <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    [hidden] { display:none !important; }   /* .agent-grid sets display:grid, which beats the hidden attribute */
    *, *::before, *::after { box-sizing: border-box; }
    :root { --bs-primary: #0184BB; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .pc-container { margin-left:0; top:0; margin-top:70px; min-height:calc(100vh - 70px); }
    .mh-header-inner { width:100%; padding:0 15px; display:flex; align-items:center; justify-content:space-between; height:70px; }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:.8rem; font-weight:500;
                   color:#75BDB6; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }

    .wrap { max-width:1280px; margin:32px auto; padding:0 24px 80px; }
    .mk-page-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
    .mk-page-header h1 { font-size:20px; font-weight:700; margin:0; }
    .hdr-actions { display:flex; align-items:stretch; gap:8px; flex-wrap:wrap; }
    /* The header mixes links and form buttons. A <button> does not inherit the
       page's font the way an <a> does, and browsers give it their own metrics,
       so "Sync to Website" came out narrower and shorter than the links beside
       it (2026-09-28). These three lines make the two render identically. */
    .hdr-actions form { display:flex; margin:0; }
    button.btn, input.btn { font-family:inherit; }
    .hdr-actions .btn { line-height:1.45; box-sizing:border-box; margin:0; }

    .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; font-size:12px; font-weight:600; border:none;
           border-radius:3px; cursor:pointer; text-decoration:none; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
    .btn-primary { background:#f97316; color:#fff; } .btn-primary:hover { background:#ea6c0a; color:#fff; }
    .btn-outline { background:#fff; border:1px solid #d1d5db; color:#374151; text-transform:none; letter-spacing:0; }
    .btn-outline:hover { background:#f3f4f6; color:#111; }
    .btn-sm { padding:4px 10px; font-size:11px; }
    .btn-ghost { background:transparent; border:none; color:#9ca3af; padding:4px 6px; cursor:pointer; font-size:14px; line-height:1; border-radius:3px; }
    .btn-ghost:hover { color:#374151; background:#f3f4f6; }

    /* ── Flash from Onboard / Offboard: says exactly what was done ── */
    .flash { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:6px; padding:12px 16px; margin-bottom:16px; font-size:13px; }
    .flash strong { display:block; margin-bottom:4px; }
    .flash ul { margin:0; padding-left:18px; }

    /* ── Needs attention ── */
    .attn { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
    .attn-chip { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; background:#fff; border:1px solid #e5e7eb;
                 border-radius:6px; font-size:13px; color:#374151; cursor:pointer; text-decoration:none; }
    .attn-chip b { font-size:16px; color:#111; }
    .attn-chip:hover { border-color:#111; color:#111; }
    .attn-chip.on { background:#111; border-color:#111; color:#fff; } .attn-chip.on b { color:#fff; }
    .attn-chip.money b { color:#c2410c; } .attn-chip.on.money b { color:#fff; }
    .attn-ok { font-size:13px; color:#15803d; padding:8px 0; }

    /* ── Toolbar ── */
    .toolbar { display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap; }
    .search-wrap { position:relative; flex:1; min-width:200px; max-width:320px; }
    .search-wrap .ti-search { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:15px; pointer-events:none; }
    #agentSearch { width:100%; padding:7px 10px 7px 32px; font-size:13px; border:1px solid #d1d5db; border-radius:4px; outline:none; font-family:inherit; color:#111; }
    #agentSearch:focus { border-color:#75BDB6; box-shadow:0 0 0 2px rgba(117,189,182,.2); }
    .suggest { position:absolute; left:0; right:0; top:calc(100% + 4px); z-index:30; background:#fff; border:1px solid #d1d5db; border-radius:6px;
               box-shadow:0 8px 24px rgba(0,0,0,.12); padding:4px 0; max-height:320px; overflow-y:auto; }
    .suggest-item { display:flex; align-items:center; gap:8px; padding:7px 12px; font-size:13px; color:#111; text-decoration:none; cursor:pointer; }
    .suggest-item:hover, .suggest-item.on { background:#f0f9f8; }
    .suggest-item .s-name { font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .suggest-item .s-meta { margin-left:auto; font-size:11px; color:#6b7280; white-space:nowrap; text-transform:capitalize; }
    .suggest-item .s-meta.off { color:#b45309; }
    .toolbar select { padding:7px 10px; font-size:13px; border:1px solid #d1d5db; border-radius:4px; background:#fff; font-family:inherit; color:#111; }
    .view-toggle { margin-left:auto; display:flex; border:1px solid #d1d5db; border-radius:4px; overflow:hidden; }
    .view-toggle button { border:0; background:#fff; padding:6px 10px; font-size:15px; color:#6b7280; cursor:pointer; }
    .view-toggle button.on { background:#111; color:#fff; }

    .filter-bar { display:flex; gap:6px; margin-bottom:14px; border-bottom:2px solid #e5e7eb; }
    .filter-tab { padding:7px 14px; font-size:13px; font-weight:600; cursor:pointer; border:none; background:none; color:#6b7280;
                  border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; }
    .filter-tab:hover { color:#111; }
    .filter-tab.active-tab { color:#111; border-bottom-color:#111; }
    .filter-tab .count { display:inline-block; background:#f3f4f6; color:#6b7280; border-radius:10px; padding:1px 7px; font-size:11px; margin-left:4px; font-weight:700; }
    .filter-tab.active-tab .count { background:#111; color:#fff; }

    /* ── Table ── */
    .roster-table { width:100%; border-collapse:separate; border-spacing:0; background:#fff; border-radius:6px;
                    box-shadow:0 1px 3px rgba(0,0,0,.08); font-size:13px; }
    .roster-table th { text-align:left; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.4px;
                       padding:10px 12px; border-bottom:1px solid #e5e7eb; white-space:nowrap; user-select:none; }
    .roster-table th[data-sort] { cursor:pointer; } .roster-table th[data-sort]:hover { color:#111; }
    .roster-table th .ti { font-size:11px; }
    .roster-table td { padding:9px 12px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
    .roster-table tr:last-child td { border-bottom:0; }
    .roster-table tbody tr:hover td { background:#fafafa; }
    .roster-table tr.to-onboard td { color:#6b7280; }
    .who { display:flex; align-items:center; gap:10px; min-width:0; }
    .agent-avatar { width:34px; height:34px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center;
                    font-size:12px; font-weight:700; color:#fff; overflow:hidden; }
    .agent-avatar img { width:100%; height:100%; object-fit:cover; }
    .who-name { font-weight:700; color:#111; text-decoration:none; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:230px; }
    a.who-name:hover { color:#f97316; }
    .who-title { font-size:11px; color:#9ca3af; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:230px; }

    .boards { font-size:12px; color:#6b7280; white-space:nowrap; }
    .web-mark { font-size:15px; font-weight:700; } .web-mark.yes { color:#10b981; } .web-mark.no { color:#d1d5db; }
    .who-line { display:flex; align-items:center; gap:6px; min-width:0; }
    .tag-team { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; background:#111; color:#fff;
                border-radius:3px; padding:2px 6px; white-space:nowrap; cursor:default; }
    .tag-member { font-size:10px; font-weight:700; background:#f3f4f6; color:#374151; border-radius:3px; padding:2px 5px;
                  text-decoration:none; white-space:nowrap; }
    .tag-member:hover { background:#111; color:#fff; }
    .board { display:inline-block; font-size:10px; font-weight:600; border-radius:3px; padding:2px 6px; background:#f0fdf4; color:#15803d; margin:1px 2px 1px 0; white-space:nowrap; }
    .board.none { background:#fef2f2; color:#b91c1c; }
    .pill { display:inline-block; font-size:11px; font-weight:600; border-radius:10px; padding:2px 9px; white-space:nowrap; }
    .pill.approved { background:#ecfdf5; color:#047857; } .pill.pending { background:#fff7ed; color:#c2410c; }
    .pill.inactive { background:#f3f4f6; color:#6b7280; }
    .pill.s-active { background:#f0fdf4; color:#15803d; } .pill.s-pending { background:#fff7ed; color:#c2410c; }
    .pill.s-inactive { background:#fef2f2; color:#b91c1c; } .pill.s-archived { background:#f3f4f6; color:#6b7280; }
    .pill-form { display:inline; margin:0; }
    .pill-btn { border:0; cursor:pointer; font-family:inherit; line-height:inherit; }
    .pill-btn:hover { box-shadow:0 0 0 2px rgba(0,0,0,.08); }
    .ck { font-size:11px; font-weight:600; color:#c2410c; text-decoration:none; white-space:nowrap; }
    .red-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#dc2626; margin:0 2px 1px 3px; vertical-align:middle; }
    .attn-chip.alert { border-color:#fecaca; background:#fef2f2; color:#991b1b; } .attn-chip.alert b { color:#b91c1c; }
    .muted { color:#d1d5db; }

    .meter { display:flex; align-items:center; gap:7px; text-decoration:none; color:#6b7280; font-size:11px; }
    .meter-bar { width:56px; height:6px; background:#f3f4f6; border-radius:3px; overflow:hidden; }
    .meter-bar i { display:block; height:100%; background:#f59e0b; }
    .meter.full .meter-bar i { background:#10b981; }
    a.meter:hover { color:#111; }

    .tasks-badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px;
                   background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
    .balance-badge { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%;
                     font-size:12px; font-weight:800; line-height:1; background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; text-decoration:none; }
    .balance-badge:hover { background:#c2410c; color:#fff; border-color:#c2410c; }
    .marks { display:flex; gap:6px; font-size:15px; }
    .mark { color:#d1d5db; } .mark.on { color:#0184BB; }
    .row-actions { display:flex; gap:4px; justify-content:flex-end; align-items:center; }

    /* ── Cards (the optional view) ── */
    .agent-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; }
    @media (max-width:1100px) { .agent-grid { grid-template-columns:repeat(3, 1fr); } }
    @media (max-width:760px)  { .agent-grid { grid-template-columns:repeat(2, 1fr); } }
    @media (max-width:480px)  { .agent-grid { grid-template-columns:1fr; } }
    .agent-card { background:#fff; border-radius:6px; padding:14px; box-shadow:0 1px 3px rgba(0,0,0,.08); display:flex; flex-direction:column; gap:8px; min-width:0; }
    .agent-card.to-onboard { border:1px dashed #d1d5db; box-shadow:none; }
    .card-top { display:flex; align-items:center; gap:10px; min-width:0; }
    .card-foot { display:flex; align-items:center; gap:6px; border-top:1px solid #f3f4f6; padding-top:9px; margin-top:auto; }
    .card-foot .spacer { flex:1; }

    .empty-state { text-align:center; padding:60px 0; color:#9ca3af; font-size:14px; display:none; }

    /* ── Offboard confirm ── */
    dialog.confirm { border:0; border-radius:8px; padding:22px 24px; max-width:440px; width:calc(100% - 32px); box-shadow:0 20px 50px rgba(0,0,0,.25); }
    dialog.confirm::backdrop { background:rgba(17,17,17,.45); }
    dialog.confirm h3 { font-size:17px; margin:0 0 10px; }
    dialog.confirm ul { margin:0 0 18px; padding-left:18px; font-size:13px; line-height:1.7; color:#374151; }
    dialog.confirm .btns { display:flex; gap:8px; justify-content:flex-end; }
    .btn-danger { background:#b91c1c; color:#fff; } .btn-danger:hover { background:#991b1b; }

    /* ── Phone: the table becomes a stacked list, one agent per block ──
       Measured where it runs out: nine columns stop fitting at about 900px.
       Below that each row lays out as a card-like block; the header row goes. */
    @media (max-width: 900px) {
      .roster-table thead { display:none; }
      .roster-table, .roster-table tbody, .roster-table tr, .roster-table td { display:block; width:100%; }
      .roster-table tr { padding:10px 12px; border-bottom:1px solid #f3f4f6; display:grid;
                         grid-template-columns:1fr auto; gap:6px 10px; align-items:center; }
      .roster-table td { padding:0; border:0; }
      .roster-table td.c-who { grid-column:1 / -1; }
      .roster-table td.c-hide-sm { display:none; }
      .who-name, .who-title { max-width:none; }
    }
    @media (max-width: 620px) {
      .toolbar { flex-direction:column; align-items:stretch; }
      .search-wrap { max-width:none; }
      #agentSearch { font-size:16px; padding:9px 10px 9px 32px; }   /* 16px: iOS zooms below it */
      .view-toggle { margin-left:0; align-self:flex-start; }
      .filter-bar { overflow-x:auto; scrollbar-width:none; }
      .filter-bar::-webkit-scrollbar { display:none; }
      .filter-tab { flex:none; padding:8px 12px; }
    }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <?php if ($flash): ?>
      <div class="flash<?= array_key_exists('ok', $flash) && !$flash['ok'] ? ' bad' : '' ?>"><strong><?= e($flash['title']) ?></strong>
        <ul><?php foreach ($flash['lines'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
        <?php if (!empty($flash['link'])): ?><a href="<?= e($flash['link']) ?>" style="display:inline-block;margin-top:6px;font-weight:600;color:#065f46;">Open their checklist <i class="ti ti-arrow-right"></i></a><?php endif; ?>
        <?php if (!empty($flash['log'])): ?>
          <details class="flash-log"><summary>Full log</summary><pre><?= e(trim($flash['log'])) ?></pre></details>
        <?php endif; ?></div>
    <?php endif; ?>

    <div class="mk-page-header">
      <h1>Agent Roster</h1>
      <div class="hdr-actions">
        <a class="btn btn-outline btn-sm" href="hot_sheet_preview.php"><i class="ti ti-mail"></i> Hot Sheets</a>
        <a class="btn btn-outline btn-sm" href="subscribers.php"><i class="ti ti-users"></i> Subscribers</a>
        <a class="btn btn-outline btn-sm" href="qr_codes.php"><i class="ti ti-qrcode"></i> QR Codes</a>
        <a class="btn btn-outline btn-sm" href="website_order.php"><i class="ti ti-arrows-sort"></i> Website Order</a>
        <a class="btn btn-outline btn-sm" href="leadership.php"><i class="ti ti-crown"></i> Leadership &amp; Staff</a>
        <?php // Anyprop Feed / Spark Feed buttons removed 2026-10-06 (Nikki); the
              // probe pages anyprop_debug.php and spark_debug.php still answer by URL. ?>
        <?php if (defined('SITE_AGENT_SYNC_URL') && SITE_AGENT_SYNC_URL !== ''): ?>
        <!-- Pushes every profile to the public site now. Safe to press twice:
             the website only writes what actually differs. -->
        <form method="POST" style="margin:0;" id="syncSiteForm">
          <input type="hidden" name="_action" value="sync_site">
          <button type="submit" class="btn btn-outline btn-sm" id="syncSiteBtn"
                  title="Update the agents on <?= e(preg_replace('~^https?://~', '', rtrim(defined('PUBLIC_SITE_URL') ? PUBLIC_SITE_URL : 'the website', '/'))) ?> with what is here">
            <i class="ti ti-refresh"></i> Sync to Website</button>
        </form>
        <?php endif; ?>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="_action" value="new_agent">
          <button type="submit" class="btn btn-primary"><i class="ti ti-plus"></i> New Agent</button>
        </form>
      </div>
    </div>

    <!-- Needs attention: each chip filters the list; Pipeline links out -->
    <div class="attn" id="attn">
      <?php $any = false; foreach ($attn_counts as $k => $n): if (!$n) continue; $any = true; ?>
        <button type="button" class="attn-chip<?= $k === 'balance' ? ' money' : ($k === 'inactive' ? ' alert' : '') ?>" data-attn="<?= $k ?>">
          <b><?= $n ?></b> <?= e($attn_label[$k]) ?></button>
      <?php endforeach; ?>
      <?php if ($pipeline_pending): $any = true; ?>
        <a class="attn-chip" href="pipeline_review.php"><b><?= $pipeline_pending ?></b> Pipeline deal<?= $pipeline_pending === 1 ? '' : 's' ?> to review <i class="ti ti-arrow-right"></i></a>
      <?php endif; ?>
      <?php if (!$any): ?><div class="attn-ok"><i class="ti ti-circle-check"></i> Nothing needs attention.</div><?php endif; ?>
    </div>

    <div class="toolbar">
      <div class="search-wrap">
        <i class="ti ti-search"></i>
        <input type="text" id="agentSearch" placeholder="Search name or email" autocomplete="off"
               role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="agentSuggest">
        <!-- Typeahead: pick a name (or press Enter on the first match) to open
             the profile; typing alone still filters the list below. -->
        <div class="suggest" id="agentSuggest" role="listbox" hidden></div>
      </div>
      <select id="boardFilter" aria-label="Board">
        <option value="">All boards</option>
        <?php foreach ($all_boards as $slug => $label): ?><option value="<?= e($slug) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <div class="view-toggle" role="group" aria-label="View">
        <button type="button" data-view="table" title="Table"><i class="ti ti-list"></i></button>
        <button type="button" data-view="cards" title="Cards"><i class="ti ti-layout-grid"></i></button>
      </div>
    </div>

    <div class="filter-bar">
      <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $k => $lbl): ?>
        <button class="filter-tab<?= $k === 'active' ? ' active-tab' : '' ?>" data-filter="<?= $k ?>"><?= $lbl ?>
          <?php if ($k === 'inactive' && $attn_counts['inactive']): ?><span class="red-dot" title="No longer with Mont Haus in the MLS"></span><?php endif; ?>
          <span class="count"><?= $counts[$k] ?></span></button>
      <?php endforeach; ?>
      <button class="filter-tab" data-filter="all">All <span class="count"><?= count($rows) ?></span></button>
    </div>

    <?php
      /** Attributes every view shares; the script filters and sorts on these. */
      $row_attrs = function (array $x): string {
          return 'data-status="' . e($x['_status']) . '"'
               . ' data-search="' . e(strtolower($x['_name'] . ' ' . ($x['mh_email'] ?? '') . ' ' . ($x['mls_email'] ?? ''))) . '"'
               . ' data-first="' . e($x['_first']) . '" data-last="' . e($x['_last']) . '"'
               . ' data-updated="' . e($x['updated_at'] ?? '') . '" data-start="' . e(($x['start_date'] ?: $x['intake_date']) ?: '9999-12-31') . '"'
               . ' data-boards=" ' . e(implode(' ', array_keys($x['_boards']))) . ' "'
               . ' data-attn=" ' . e(implode(' ', $x['_attn'])) . ' "';
      };
      $avatar = function (array $x): string {
          $bg = $x['_roster_only'] ? '#d1d5db' : (['inactive' => '#9ca3af', 'archived' => '#9ca3af'][$x['_status']] ?? '#1a1a1a');
          $inner = !empty($x['headshot_face_url']) ? '<img src="' . e($x['headshot_face_url']) . '" alt="">'
                 : (!empty($x['headshot_url']) ? '<img src="' . e($x['headshot_url']) . '" alt="">'
                 : e(initials($x['_name'], $x['initials'] ?? null)));
          return '<div class="agent-avatar" style="background:' . $bg . ';">' . $inner . '</div>';
      };
      // No titles here (too long for a list; they are on the agent page).
      // A team says so and names its members on hover; a member links to
      // their team.
      $name_html = function (array $x): string {
          $n = $x['_iid']
             ? '<a class="who-name" href="agent.php?id=' . $x['_iid'] . '">' . e($x['_name']) . '</a>'
             : '<span class="who-name">' . e($x['_name']) . '</span>';
          $tags = '';
          if ($x['_team']) {
              $tags .= '<span class="tag-team" title="' . e($x['_members'] ? 'Members: ' . implode(', ', $x['_members']) : 'No members linked yet') . '">Team'
                     . ($x['_members'] ? ' · ' . count($x['_members']) : '') . '</span>';
          }
          foreach ($x['_teams'] as $t) {
              $tags .= '<a class="tag-member" href="agent.php?id=' . $t['id'] . '" title="Member of ' . e($t['name']) . '">' . e(initials($t['name'], $t['initials'] ?? null)) . '</a>';
          }
          return $tags ? '<div class="who-line">' . $n . $tags . '</div>' : $n;
      };
      $boards_html = function (array $x): string {
          if (!$x['_boards']) return '<span class="muted">·</span>';
          return '<span class="boards">' . e(implode(' · ', $x['_boards'])) . '</span>';
      };
      // Website: a tick or a cross; the words are in the tooltip.
      $web_html = function (array $x) use ($web_label): string {
          if ($x['_web'] === '') return '<span class="muted">·</span>';
          $ok = $x['_web'] === 'approved';
          return '<span class="web-mark ' . ($ok ? 'yes' : 'no') . '" title="' . e($web_label[$x['_web']] ?? $x['_web']) . '">'
               . ($ok ? '&#10003;' : '&#10007;') . '</span>';
      };
      // Status, plus the marketing checklist while it is open.
      // The Active / Inactive badge is a button: Active → Inactive (in the MLS
      // but not an active broker), Inactive → Active. Archived is not clickable
      // (Restore does that).
      $status_html = function (array $x) use ($status_label): string {
          $label = e($status_label[$x['_status']] ?? $x['_status']);
          if (!$x['_iid'] || $x['_status'] === 'archived') {
              $h = '<span class="pill s-' . e($x['_status']) . '">' . $label . '</span>';
          } else {
              if ($x['_status'] === 'active') {
                  $act = 'set_inactive'; $tip = 'Click to mark Inactive (not an active broker)';
                  $ask = 'Mark ' . $x['_name'] . ' Inactive? Their website profile, Hot Sheets and FUB stay as they are; Offboard turns those off.';
              } else {
                  $act = 'set_active';
                  $tip = $x['_mls_gone'] && !$x['_manual_inactive']
                       ? 'Not listed under Mont Haus in the MLS since ' . date('M j', strtotime((string)$x['departure_detected_at'])) . '. Click if they are still with Mont Haus.'
                       : 'Marked Inactive by hand. Click to make Active again.';
                  $ask = 'Mark ' . $x['_name'] . ' Active again?';
              }
              $h = '<form method="POST" class="pill-form" onsubmit="return confirm(' . e(json_encode($ask)) . ');">'
                 . '<input type="hidden" name="_action" value="' . $act . '"><input type="hidden" name="id" value="' . $x['_iid'] . '">'
                 . '<button type="submit" class="pill pill-btn s-' . e($x['_status']) . '" title="' . e($tip) . '">' . $label . '</button></form>';
          }
          // The checklist count, the Profile meter and the Subs marks left the
          // list on 2026-10-07 (Nikki): the agent page has them.
          return $h;
      };
      $actions_html = function (array $x): string {
          if ($x['_roster_only']) {
              return '<form method="POST" style="margin:0;"><input type="hidden" name="_action" value="onboard">'
                   . '<input type="hidden" name="roster_id" value="' . (int)($x['roster_id'] ?? 0) . '">'
                   . '<button type="submit" class="btn btn-outline btn-sm" title="In the MLS roster but not set up here yet"><i class="ti ti-user-plus"></i> Add</button></form>';
          }
          // Their portal, seen as they see it (admin preview; Nikki, 2026-10-07).
          $keep = $x['_status'] !== 'archived'
              ? '<a class="btn-ghost" href="/portal/?preview=' . $x['_iid'] . '" target="_blank" rel="noopener" title="View their agent portal"><i class="ti ti-eye"></i></a>'
              : '';
          if ($x['_onb']) {
              $keep .= '<form method="POST" style="margin:0;" onsubmit="return confirm(\'Mark the rest of ' . e(addslashes($x['_name'])) . '\\\'s marketing checklist done? (Hot Sheets: Subscribe stays open unless they already get them.)\');">'
                    . '<input type="hidden" name="_action" value="complete_checklist"><input type="hidden" name="id" value="' . $x['_iid'] . '">'
                    . '<button type="submit" class="btn-ghost" title="Mark checklist complete"><i class="ti ti-checks"></i></button></form>';
          }
          if ($x['_status'] === 'inactive') {
              $keep .= '<form method="POST" style="margin:0;"><input type="hidden" name="_action" value="keep">'
                    . '<input type="hidden" name="id" value="' . $x['_iid'] . '">'
                    . '<button type="submit" class="btn-ghost" title="Still with Mont Haus: back to Active"><i class="ti ti-user-check"></i></button></form>';
          }
          if ($x['_status'] === 'archived') {
              return '<form method="POST" style="margin:0;"><input type="hidden" name="_action" value="restore">'
                   . '<input type="hidden" name="id" value="' . $x['_iid'] . '">'
                   . '<button type="submit" class="btn-ghost" title="Restore (onboards again)"><i class="ti ti-refresh"></i></button></form>';
          }
          return $keep . '<button type="button" class="btn-ghost js-offboard" title="Offboard" data-id="' . $x['_iid'] . '"'
               . ' data-name="' . e($x['_name']) . '" data-steps="' . e(json_encode($x['_offboard'])) . '"><i class="ti ti-user-minus"></i></button>';
      };
    ?>

    <!-- Table view (default) -->
    <table class="roster-table" id="viewTable">
      <thead><tr>
        <th data-sort="first" title="Sort by first name">Agent <i class="ti ti-selector"></i></th>
        <th>Boards</th>
        <th style="text-align:center;" title="On the website">Web</th>
        <th>Status</th>
        <th>Tasks</th>
        <th title="Balance due">$</th>
        <th data-sort="updated">Updated <i class="ti ti-selector"></i></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr class="agent-row<?= $x['_roster_only'] ? ' to-onboard' : '' ?>" <?= $row_attrs($x) ?>>
          <td class="c-who"><div class="who"><?= $avatar($x) ?><div style="min-width:0;"><?= $name_html($x) ?></div></div></td>
          <td class="c-hide-sm"><?= $boards_html($x) ?></td>
          <td style="text-align:center;"><?= $web_html($x) ?></td>
          <td class="c-hide-sm"><?= $status_html($x) ?></td>
          <td class="c-hide-sm"><?= (int)$x['open_tasks'] > 0 ? '<a class="tasks-badge" href="agent.php?id=' . $x['_iid'] . '&tab=tasks" style="text-decoration:none;"><i class="ti ti-list-check"></i> ' . (int)$x['open_tasks'] . '</a>' : '<span class="muted">·</span>' ?></td>
          <td><?= $x['_bal'] > 0 ? '<a class="balance-badge" href="billing.php" title="Outstanding ' . e('$' . number_format($x['_bal'], 2)) . ': open Billing">$</a>' : '' ?></td>
          <td class="c-hide-sm" style="color:#9ca3af;font-size:12px;white-space:nowrap;"><?= $x['updated_at'] ? e(date('M j', strtotime($x['updated_at']))) : '' ?></td>
          <td><div class="row-actions"><?= $actions_html($x) ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Card view (optional) -->
    <div class="agent-grid" id="viewCards" hidden>
      <?php foreach ($rows as $x): ?>
        <div class="agent-card agent-row<?= $x['_roster_only'] ? ' to-onboard' : '' ?>" <?= $row_attrs($x) ?>>
          <div class="card-top"><?= $avatar($x) ?><div style="min-width:0;"><?= $name_html($x) ?></div></div>
          <div><?= $boards_html($x) ?></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <?= $x['_web'] ? $web_html($x) : '' ?>
            <?= $x['_status'] !== 'active' || $x['_onb'] ? $status_html($x) : '' ?>
          </div>
          <div class="card-foot">
            <?= (int)$x['open_tasks'] > 0 ? '<span class="tasks-badge"><i class="ti ti-list-check"></i> ' . (int)$x['open_tasks'] . '</span>' : '' ?>
            <?= $x['_bal'] > 0 ? '<a class="balance-badge" href="billing.php" title="Outstanding ' . e('$' . number_format($x['_bal'], 2)) . '">$</a>' : '' ?>
            <span class="spacer"></span>
            <?= $actions_html($x) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="empty-state" id="noResults"><i class="ti ti-search"></i> No agents match.</div>
  </div>
</div>

<dialog class="confirm" id="offboardDialog">
  <form method="POST">
    <input type="hidden" name="_action" value="offboard">
    <input type="hidden" name="id" id="offboardId">
    <h3>Offboard <span id="offboardName"></span>?</h3>
    <ul id="offboardSteps"></ul>
    <div class="btns">
      <button type="button" class="btn btn-outline" id="offboardCancel">Cancel</button>
      <button type="submit" class="btn btn-danger">Offboard</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var views = { table: document.getElementById('viewTable'), cards: document.getElementById('viewCards') };
  var state = { filter: 'active', attn: '', search: '', board: '', sort: 'first', dir: 'asc' };   // first name A-Z (Nikki, 2026-10-02; was last name)
  var noResults = document.getElementById('noResults');

  // View choice is a per-browser convenience only; storage can be missing or
  // throw (private windows), and the page works the same without it.
  var view = 'table';
  try { view = localStorage.getItem('mk_roster_view') === 'cards' ? 'cards' : 'table'; } catch (e) {}

  function rowsIn(el) { return Array.prototype.slice.call(el.querySelectorAll('.agent-row')); }

  function matches(r) {
    var d = r.dataset;
    if (state.attn) { if (d.attn.indexOf(' ' + state.attn + ' ') === -1) return false; }
    else if (state.filter !== 'all' && d.status !== state.filter) return false;
    if (state.search && d.search.indexOf(state.search) === -1) return false;
    if (state.board && d.boards.indexOf(' ' + state.board + ' ') === -1) return false;
    return true;
  }

  function apply() {
    var shown = 0;
    ['table', 'cards'].forEach(function (k) {
      var el = views[k], box = k === 'table' ? el.tBodies[0] : el;
      var rows = rowsIn(el);
      rows.forEach(function (r) { var ok = matches(r); r.style.display = ok ? '' : 'none'; if (ok && k === view) shown++; });
      rows.sort(function (a, b) {
        var va = a.dataset[state.sort] || '', vb = b.dataset[state.sort] || '';
        var tie = state.sort === 'first' ? 'last' : 'first';
        var c = va.localeCompare(vb) || (a.dataset[tie] || '').localeCompare(b.dataset[tie] || '');
        return state.dir === 'asc' ? c : -c;
      }).forEach(function (r) { box.appendChild(r); });
    });
    views.table.hidden = view !== 'table';
    views.cards.hidden = view !== 'cards';
    noResults.style.display = shown ? 'none' : 'block';
    document.querySelectorAll('.view-toggle button').forEach(function (b) { b.classList.toggle('on', b.dataset.view === view); });
    document.querySelectorAll('.attn-chip[data-attn]').forEach(function (c) { c.classList.toggle('on', c.dataset.attn === state.attn); });
    document.querySelectorAll('.filter-tab').forEach(function (t) { t.classList.toggle('active-tab', !state.attn && t.dataset.filter === state.filter); });
  }

  document.querySelectorAll('.filter-tab').forEach(function (t) {
    t.addEventListener('click', function () { state.filter = t.dataset.filter; state.attn = ''; apply(); });
  });
  document.querySelectorAll('.attn-chip[data-attn]').forEach(function (c) {
    c.addEventListener('click', function () { state.attn = state.attn === c.dataset.attn ? '' : c.dataset.attn; apply(); });
  });
  // Search box: filters the list as you type, and offers the matching agents as
  // a typeahead (table rows only: the cards repeat them). Down/Up move, Enter
  // opens the highlighted name (the first match when none is highlighted),
  // Escape closes. Any status is offered, so an archived agent is one search away.
  var search = document.getElementById('agentSearch'), suggest = document.getElementById('agentSuggest'), cursor = -1;
  function suggestItems() { return Array.prototype.slice.call(suggest.querySelectorAll('.suggest-item')); }
  function closeSuggest() { suggest.hidden = true; suggest.innerHTML = ''; cursor = -1; search.setAttribute('aria-expanded', 'false'); }
  function highlight(i) {
    var items = suggestItems();
    cursor = items.length ? Math.max(-1, Math.min(i, items.length - 1)) : -1;
    items.forEach(function (it, n) { it.classList.toggle('on', n === cursor); });
    if (cursor >= 0) items[cursor].scrollIntoView({ block: 'nearest' });
  }
  function renderSuggest() {
    var q = state.search;
    closeSuggest();
    if (!q) return;
    var hits = rowsIn(views.table).filter(function (r) { return r.dataset.search.indexOf(q) !== -1 && r.querySelector('a.who-name'); });
    hits.sort(function (a, b) {   // names that START with what was typed first, then first-name order
      var sa = a.dataset.search.indexOf(q) === 0 ? 0 : 1, sb = b.dataset.search.indexOf(q) === 0 ? 0 : 1;
      return (sa - sb) || a.dataset.first.localeCompare(b.dataset.first) || a.dataset.last.localeCompare(b.dataset.last);
    });
    if (!hits.length) return;
    hits.slice(0, 8).forEach(function (r) {
      var link = r.querySelector('a.who-name');
      var a = document.createElement('a');
      a.className = 'suggest-item'; a.href = link.href; a.setAttribute('role', 'option');
      var name = document.createElement('span'); name.className = 's-name'; name.textContent = link.textContent;
      var meta = document.createElement('span'); meta.className = 's-meta' + (r.dataset.status === 'active' ? '' : ' off');
      meta.textContent = r.dataset.status === 'active' ? '' : r.dataset.status;
      a.appendChild(name); a.appendChild(meta);
      a.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep focus so blur does not close it first
      suggest.appendChild(a);
    });
    suggest.hidden = false;
    search.setAttribute('aria-expanded', 'true');
  }
  search.addEventListener('input', function (e) { state.search = e.target.value.toLowerCase().trim(); apply(); renderSuggest(); });
  search.addEventListener('keydown', function (e) {
    if (suggest.hidden) { if (e.key === 'Escape') { search.value = ''; state.search = ''; apply(); } return; }
    if (e.key === 'ArrowDown')  { e.preventDefault(); highlight(cursor + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(cursor - 1); }
    else if (e.key === 'Escape') { closeSuggest(); }
    else if (e.key === 'Enter') {
      var items = suggestItems(), pick = items[cursor >= 0 ? cursor : 0];
      if (pick) { e.preventDefault(); window.location.href = pick.href; }
    }
  });
  search.addEventListener('focus', function () { renderSuggest(); });
  search.addEventListener('blur', function () { setTimeout(closeSuggest, 150); });
  document.addEventListener('click', function (e) { if (!e.target.closest('.search-wrap')) closeSuggest(); });
  document.getElementById('boardFilter').addEventListener('change', function (e) { state.board = e.target.value; apply(); });
  document.querySelectorAll('.view-toggle button').forEach(function (b) {
    b.addEventListener('click', function () {
      view = b.dataset.view;
      try { localStorage.setItem('mk_roster_view', view); } catch (e) {}
      apply();
    });
  });
  document.querySelectorAll('.roster-table th[data-sort]').forEach(function (th) {
    th.addEventListener('click', function () {
      var k = th.dataset.sort;
      if (state.sort === k) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
      else { state.sort = k; state.dir = k === 'updated' ? 'desc' : 'asc'; }
      apply();
    });
  });

  // Offboard: the dialog lists exactly what will happen to this agent.
  var dlg = document.getElementById('offboardDialog');
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.js-offboard');
    if (!b) return;
    document.getElementById('offboardId').value = b.dataset.id;
    document.getElementById('offboardName').textContent = b.dataset.name;
    var ul = document.getElementById('offboardSteps'); ul.innerHTML = '';
    JSON.parse(b.dataset.steps || '[]').forEach(function (s) { var li = document.createElement('li'); li.textContent = s; ul.appendChild(li); });
    dlg.showModal();
  });
  document.getElementById('offboardCancel').addEventListener('click', function () { dlg.close(); });

  // Sync to Website: the website does the work and can take a minute when there
  // are photos to fetch, so say so and do not let a second press queue another.
  var syncForm = document.getElementById('syncSiteForm');
  if (syncForm) {
    syncForm.addEventListener('submit', function () {
      var b = document.getElementById('syncSiteBtn');
      b.disabled = true;
      b.innerHTML = '<i class="ti ti-loader-2"></i> Syncing, this can take a minute';
    });
  }

  apply();
})();
</script>
</body>
</html>
