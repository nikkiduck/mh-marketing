<?php
/**
 * cron/merge_agents.php — fold a duplicate agent row into the real one.
 *
 * A person can end up on the roster twice when a row is created for them
 * without noticing the one that exists (e.g. "Add" on a Spark MLS roster line
 * for someone whose row was not linked to it yet). This moves what the
 * duplicate holds onto the row you keep, then archives the duplicate. Nothing
 * is deleted.
 *
 *   php /var/www/marketing.monthaus.com/cron/merge_agents.php --find
 *   php /var/www/marketing.monthaus.com/cron/merge_agents.php --keep=31 --drop=35 --dry-run
 *   php /var/www/marketing.monthaus.com/cron/merge_agents.php --keep=31 --drop=35
 *
 * Moved to --keep: the Spark MLS roster link (roster_id), MLS identities, the
 * Hot Sheet subscription, team memberships, and any profile field --keep has
 * blank. The duplicate's own checklist stays with it (--keep has its own).
 * If the duplicate has campaigns, orders, notes or billing, it stops and lists
 * them: those are real work, so check before merging (--force to merge anyway;
 * they stay on the archived duplicate).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/agent_roster.php';

$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(keep|drop)=#?(\d+)$/', $a, $m)) $o[$m[1]] = (int)$m[2];
    if (in_array($a, ['--dry-run', '--find', '--force'], true)) $o[substr($a, 2)] = true;
}
$dry = !empty($o['dry-run']);
$not_team = mk_agents_only_sql($conn, 'a');

if (!empty($o['find']) || empty($o['keep']) || empty($o['drop'])) {
    // Active rows sharing an email or a name.
    $r = $conn->query("SELECT a.id, a.agent_name, a.mh_email, a.roster_id, a.slug, a.web_status, a.created_at,
                              (SELECT COUNT(*) FROM agent_mls_ids i WHERE i.intake_id = a.id) AS ids
                         FROM marketing_intakes a
                        WHERE a.is_active = 1 AND a.status <> 'archived'{$not_team}
                          AND EXISTS (SELECT 1 FROM marketing_intakes b
                                       WHERE b.id <> a.id AND b.is_active = 1 AND b.status <> 'archived'"
                        . mk_agents_only_sql($conn, 'b') . "
                                         AND ((a.mh_email <> '' AND LOWER(b.mh_email) = LOWER(a.mh_email))
                                              OR LOWER(TRIM(b.agent_name)) = LOWER(TRIM(a.agent_name))))
                        ORDER BY a.agent_name, a.id");
    $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    echo $rows ? "Possible duplicates (id · name · email · website · MLS ids · Spark roster link · created):\n" : "No duplicates found.\n";
    foreach ($rows as $x) {
        printf("  #%-4d %-24s %-32s %-9s %d ids  %s  %s\n", $x['id'], $x['agent_name'], $x['mh_email'],
               $x['slug'] ? ($x['web_status'] ?: '') : 'no site', $x['ids'], $x['roster_id'] ? "roster {$x['roster_id']}" : 'no roster link', $x['created_at']);
    }
    if ($rows) echo "\nKeep the row with the website and MLS IDs: --keep=ID --drop=ID [--dry-run]\n";
    exit(0);
}

$keep_id = $o['keep']; $drop_id = $o['drop'];
$get = fn(int $id) => $conn->query("SELECT * FROM marketing_intakes WHERE id = {$id}")->fetch_assoc();
$keep = $get($keep_id); $drop = $get($drop_id);
if (!$keep || !$drop || $keep_id === $drop_id) { fwrite(STDERR, "Need two different existing rows (--keep, --drop).\n"); exit(1); }
if (($keep['entity_type'] ?? 'agent') === 'team' || ($drop['entity_type'] ?? 'agent') === 'team') { fwrite(STDERR, "One of them is a team; use split_team.php for teams.\n"); exit(1); }

echo "Keep #{$keep_id} {$keep['agent_name']}\nDrop #{$drop_id} {$drop['agent_name']} (will be archived)" . ($dry ? "\nDRY RUN: nothing will be written" : '') . "\n\n";

// Real work on the duplicate?
$work = [];
foreach (['marketing_campaigns' => 'campaigns', 'marketing_collateral_orders' => 'orders', 'marketing_notes' => 'notes',
          'marketing_billing_months' => 'billing months'] as $t => $label) {
    $chk = $conn->query("SHOW TABLES LIKE '{$t}'");
    if (!$chk || !$chk->fetch_row()) continue;
    $n = (int)$conn->query("SELECT COUNT(*) FROM {$t} WHERE intake_id = {$drop_id}")->fetch_row()[0];
    if ($n) $work[] = "{$n} {$label}";
}
$n = (int)$conn->query("SELECT COUNT(*) FROM marketing_tasks WHERE intake_id = {$drop_id} AND category <> 'onboarding'")->fetch_row()[0];
if ($n) $work[] = "{$n} non-checklist tasks";
if ($work && empty($o['force'])) {
    fwrite(STDERR, "#{$drop_id} has " . implode(', ', $work) . ". Check them before merging; add --force to merge anyway (they stay on the archived row).\n");
    exit(1);
}

$blank = fn($v) => $v === null || trim(strip_tags((string)$v)) === '';
$conn->begin_transaction();
try {
    // Spark roster link.
    if (!$keep['roster_id'] && $drop['roster_id']) {
        echo "  link  Spark roster {$drop['roster_id']} → #{$keep_id}\n";
        if (!$dry) {
            $conn->query("UPDATE marketing_intakes SET roster_id = NULL WHERE id = {$drop_id}");
            $conn->query("UPDATE marketing_intakes SET roster_id = " . (int)$drop['roster_id'] . " WHERE id = {$keep_id}");
        }
    }
    // Profile fields --keep has blank.
    $sets = []; $vals = []; $types = '';
    foreach (['mh_email', 'alt_email', 'cell_phone', 'agent_title', 'bio_text', 'headshot_url', 'headshot_face_url',
              'social_instagram', 'social_facebook', 'social_linkedin', 'social_tiktok', 'website_url',
              'mls_full_name', 'mls_email', 'mls_phone', 'office', 'start_date'] as $c) {
        if (!array_key_exists($c, $keep) || $blank($drop[$c] ?? null) || !$blank($keep[$c])) continue;
        echo "  copy  {$c}: " . substr(strip_tags((string)$drop[$c]), 0, 60) . "\n";
        $sets[] = "`{$c}` = ?"; $vals[] = (string)$drop[$c]; $types .= 's';
    }
    if (!$dry && $sets) {
        $vals[] = $keep_id; $types .= 'i';
        $s = $conn->prepare("UPDATE marketing_intakes SET " . implode(', ', $sets) . " WHERE id = ?");
        $s->bind_param($types, ...$vals); $s->execute(); $s->close();
    }
    // MLS identities.
    foreach ($conn->query("SELECT * FROM agent_mls_ids WHERE intake_id = {$drop_id}")->fetch_all(MYSQLI_ASSOC) as $i) {
        echo "  ident {$i['market']}/{$i['mls_agent_id']} → #{$keep_id}\n";
        if ($dry) continue;
        $s = $conn->prepare("INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id, agent_key, member_status, is_alias, last_seen_at) VALUES (?,?,?,?,?,?,?)");
        $s->bind_param('issssis', $keep_id, $i['market'], $i['mls_agent_id'], $i['agent_key'], $i['member_status'], $i['is_alias'], $i['last_seen_at']);
        $s->execute(); $s->close();
        $conn->query("DELETE FROM agent_mls_ids WHERE id = " . (int)$i['id']);
    }
    // Hot Sheet subscription (only when --keep has none).
    if (($t = $conn->query("SHOW TABLES LIKE 'hs_subscribers'")) && $t->fetch_row()) {
        $has_keep = (int)$conn->query("SELECT COUNT(*) FROM hs_subscribers WHERE intake_id = {$keep_id}")->fetch_row()[0];
        $has_drop = (int)$conn->query("SELECT COUNT(*) FROM hs_subscribers WHERE intake_id = {$drop_id}")->fetch_row()[0];
        if ($has_drop && !$has_keep) { echo "  Hot Sheet subscription → #{$keep_id}\n"; if (!$dry) $conn->query("UPDATE hs_subscribers SET intake_id = {$keep_id} WHERE intake_id = {$drop_id}"); }
    }
    // Team memberships.
    if (($t = $conn->query("SHOW TABLES LIKE 'team_members'")) && $t->fetch_row()) {
        foreach ($conn->query("SELECT team_id, sort_order FROM team_members WHERE member_id = {$drop_id}")->fetch_all(MYSQLI_ASSOC) as $tm) {
            echo "  team  #{$tm['team_id']} membership → #{$keep_id}\n";
            if (!$dry) {
                $conn->query("INSERT IGNORE INTO team_members (team_id, member_id, sort_order) VALUES (" . (int)$tm['team_id'] . ", {$keep_id}, " . (int)$tm['sort_order'] . ")");
                $conn->query("DELETE FROM team_members WHERE team_id = " . (int)$tm['team_id'] . " AND member_id = {$drop_id}");
            }
        }
    }
    // Archive the duplicate.
    echo "  drop  #{$drop_id}: archived, off the website, not in FUB\n";
    if (!$dry) {
        $conn->query("UPDATE marketing_intakes SET status = 'archived', is_active = 0, archived_at = NOW(), slug = NULL,
                             web_status = 'inactive', in_fub = 0, departure_detected_at = NULL WHERE id = {$drop_id}");
    }
    $dry ? $conn->rollback() : $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, '✗ ' . $e->getMessage() . " (rolled back, nothing written)\n");
    exit(1);
}
echo "\n" . ($dry ? 'DRY RUN done.' : 'Done.') . "\n";
