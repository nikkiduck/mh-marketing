<?php
/**
 * cron/activate_roster.php — everyone with Mont Haus in the MLS is Active.
 *
 * Nikki, 2026-09-22: Active means "with Mont Haus in the MLS", not "onboarded
 * by marketing". The Anyprop sync now adds new agents as Active itself. This
 * script brings everything else into line:
 *
 *   1. rows still at status 'roster' (found by the MLS sync or the site import)
 *      or 'pending' become 'active', with the marketing checklist (mk_onboard).
 *      No Hot Sheet subscription: that is the checklist item "Hot Sheets:
 *      Subscribe", ticked after the marketing meeting.
 *   2. Spark-era office_roster people with no row here yet: linked to an
 *      existing row when exactly one matches by email or name, otherwise given
 *      a new Active row the same way. Team display names ("A | B C") are
 *      skipped. Anyone who was offboarded (an archived row) is left alone.
 *
 * Re-runnable. Run it once now (dry run first). Until Anyprop covers every
 * board (Vail is Spark-only today), also run it nightly after sync_roster.php
 * so a new Spark-only agent does not wait on the roster as "Add":
 *   45 3 * * * /usr/bin/php /var/www/marketing.monthaus.com/cron/activate_roster.php >> /var/log/mh-marketing/activate_roster.log 2>&1
 * New rows are emailed to ROSTER_ALERT_EMAILS, except with --no-email (use it
 * on the first run, when everyone is "new").
 *
 *   php /var/www/marketing.monthaus.com/cron/activate_roster.php --dry-run
 *   php /var/www/marketing.monthaus.com/cron/activate_roster.php --no-email
 *   php /var/www/marketing.monthaus.com/cron/activate_roster.php --skip=160,170   (office_roster ids to leave out)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/agent_roster.php';
require_once __DIR__ . '/../inc/agent_lifecycle.php';
require_once __DIR__ . '/../inc/roster_alerts.php';

$dry = in_array('--dry-run', $argv, true);
$no_email = in_array('--no-email', $argv, true);
$skip = [];
foreach ($argv as $a) if (preg_match('/^--skip=([\d,#\s]+)$/', $a, $m)) $skip = array_map('intval', preg_split('/[,\s#]+/', $m[1], -1, PREG_SPLIT_NO_EMPTY));

echo "Activate roster" . ($dry ? ' (DRY RUN: nothing will be written)' : '') . "\n\n";
$new_ids = [];
$not_team = mk_agents_only_sql($conn);

// 1. 'roster' / 'pending' rows → Active.
$rows = $conn->query("SELECT id, agent_name, status FROM marketing_intakes
                       WHERE is_active = 1 AND status IN ('roster', 'pending'){$not_team} ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);
echo "1. Waiting to be onboarded → Active: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "   #{$r['id']} {$r['agent_name']} ({$r['status']})\n";
    if ($dry) continue;
    foreach (mk_onboard($conn, (int)$r['id']) as $line) echo "      {$line}\n";
}

// 2. office_roster people with no row.
$has_orphans = ($t = $conn->query("SHOW TABLES LIKE 'office_roster'")) && $t->fetch_row();
$people = $has_orphans ? $conn->query("SELECT r.id, r.name, r.email, r.phone, r.title FROM office_roster r
                                         WHERE r.active = 1
                                           AND NOT EXISTS (SELECT 1 FROM marketing_intakes mi WHERE mi.roster_id = r.id)
                                         ORDER BY r.name")->fetch_all(MYSQLI_ASSOC) : [];
echo "\n2. In the Spark MLS roster with no row here: " . count($people) . "\n";
$q_match = $conn->prepare("SELECT id, agent_name, roster_id, status, is_active FROM marketing_intakes
                            WHERE ((? <> '' AND (LOWER(mh_email) = ? OR LOWER(mls_email) = ? OR LOWER(alt_email) = ?))
                                   OR LOWER(TRIM(agent_name)) = ? OR LOWER(TRIM(mls_full_name)) = ?){$not_team}");
foreach ($people as $p) {
    $rid = (int)$p['id'];
    $label = "   office_roster {$rid} {$p['name']} <{$p['email']}>";
    if (in_array($rid, $skip, true)) { echo "{$label}: skipped (--skip)\n"; continue; }
    if (str_contains((string)$p['name'], '|')) { echo "{$label}: a team display name, skipped\n"; continue; }
    $em = strtolower(trim((string)$p['email'])); $nm = strtolower(trim((string)$p['name']));
    $q_match->bind_param('ssssss', $em, $em, $em, $em, $nm, $nm); $q_match->execute();
    $hit = $q_match->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($hit) > 1) {
        $live = array_values(array_filter($hit, fn($h) => (int)$h['is_active'] === 1 && $h['status'] !== 'archived'));
        if (count($live) === 1) $hit = $live;
    }
    if (count($hit) > 1) { echo "{$label}: matches rows #" . implode(', #', array_column($hit, 'id')) . ", left for you to sort out\n"; continue; }
    if (count($hit) === 1) {
        $h = $hit[0];
        if ($h['status'] === 'archived' || !(int)$h['is_active']) { echo "{$label}: matches #{$h['id']} {$h['agent_name']}, who was offboarded; left alone\n"; continue; }
        echo "{$label}: already here as #{$h['id']} {$h['agent_name']}" . ($h['roster_id'] ? '' : ', linked') . "\n";
        if (!$dry && !$h['roster_id']) $conn->query("UPDATE marketing_intakes SET roster_id = {$rid} WHERE id = " . (int)$h['id']);
        continue;
    }
    echo "{$label}: new Active row\n";
    if ($dry) continue;
    $s = $conn->prepare("INSERT INTO marketing_intakes (agent_name, agent_title, mh_email, cell_phone, roster_id, intake_date, status, is_active)
                         VALUES (?, ?, ?, ?, ?, CURDATE(), 'active', 1)");
    $s->bind_param('ssssi', $p['name'], $p['title'], $p['email'], $p['phone'], $rid); $s->execute();
    $new = (int)$conn->insert_id; $s->close();
    $new_ids[] = $new;
    echo "      #{$new}\n";
    foreach (mk_onboard($conn, $new) as $line) echo "      {$line}\n";
}

echo "\n";
if ($new_ids && !$no_email) foreach (mk_roster_alert($conn, ['new' => $new_ids], $dry) as $line) echo "{$line}\n";
echo ($dry ? 'DRY RUN done.' : 'Done.') . " Website profiles were not touched: each still waits for your approval.\n";
$conn->close();
