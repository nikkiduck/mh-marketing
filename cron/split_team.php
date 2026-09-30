<?php
/**
 * cron/split_team.php — turn a roster row that mixes a team and a person into
 * a team (marketing entity) plus the person's own agent row.
 *
 * Built for Weber Boxer Group, whose row carries Jonathan Boxer's email, so the
 * MLS sync and the website import attached HIS identities, slug and website
 * profile to the TEAM. Needs agent_roster_v2_teams.sql.
 *
 *   php /var/www/marketing.monthaus.com/cron/split_team.php --list
 *   php /var/www/marketing.monthaus.com/cron/split_team.php --team=8 --person=new --members=new,21,23 --credit=new,21 --initials=WB --dry-run
 *   php /var/www/marketing.monthaus.com/cron/split_team.php --team=8 --person=new --members=new,21,23 --credit=new,21 --initials=WB
 *
 * --person  the individual whose details ended up on the team row (Jonathan).
 *           --person=new creates their row (named from the team row's MLS
 *           name, or --person-name="Jonathan Boxer"), since the MLS sync
 *           matched them to the team and they never got one. In --members and
 *           --credit, "new" stands for that same new row.
 * --members everyone on the team, --person included.
 * --credit  who gets the team's MLS ID (so team listings credit them by name);
 *           defaults to every member. WB: Jonathan and Scott only. Sara is on
 *           the team as an assistant; her rentals are under her own MLS ID.
 * --name    the team's name on the roster; defaults to the part of the current
 *           name before "|" ("Weber Boxer Group").
 * --initials the team's avatar/tag initials ("WB"); by default the initials of
 *           the name without a trailing "Group" or "Team".
 *
 * What moves from the team to --person (the team never needs these):
 *   website slug, website status / approval date / sort order, FUB flag,
 *   office, MLS mirror (mls_*), personal (non-alias) MLS identities, and the
 *   Hot Sheet subscription.
 * What is COPIED to --person only where theirs is blank (the team keeps it):
 *   email, phone, title, bio, headshots, socials, website URL.
 * What stays with the team, untouched: campaigns, collateral, tasks, notes,
 *   billing. The team row becomes entity_type 'team', off the website.
 * Alias identities (the team's MLS ID) are given to the --credit people, then
 * removed from the team row, so team listings credit them by name.
 *
 * One transaction: all of it or none of it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/db.php';

$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(team|person|members|name|initials|credit|person-name)=(.+)$/', $a, $m)) {
        // "#8" (as --list prints it) and "8" both work.
        $o[$m[1]] = in_array($m[1], ['team', 'person', 'members', 'credit'], true) ? str_ireplace(['#', 'NEW'], ['', 'new'], $m[2]) : $m[2];
    }
    if ($a === '--dry-run') $o['dry'] = true;
    if ($a === '--list')    $o['list'] = true;
}
$dry = !empty($o['dry']);

$chk = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'entity_type'");
if (!$chk || !$chk->fetch_row()) { fwrite(STDERR, "Run sql/agent_roster_v2_teams.sql first. Nothing done.\n"); exit(1); }

if (!empty($o['list']) || empty($o['team']) || empty($o['person'])) {
    echo "Rows that look like a team or a team member (id · status · name · email):\n";
    $r = $conn->query("SELECT id, status, entity_type, agent_name, mh_email, slug FROM marketing_intakes
                        WHERE agent_name LIKE '%group%' OR agent_name LIKE '%team%' OR agent_name LIKE '%|%'
                           OR agent_name LIKE '%boxer%' OR agent_name LIKE '%weber%' OR agent_name LIKE '%perkowski%'
                        ORDER BY agent_name");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $x) {
        printf("  #%-4d %-9s %-6s %-50s %s%s\n", $x['id'], $x['status'], $x['entity_type'], $x['agent_name'], $x['mh_email'],
               $x['slug'] ? "  [slug {$x['slug']}]" : '');
    }
    echo "\nThen: --team=ID --person=ID --members=ID,ID,... [--dry-run]\n";
    echo "A person with no row of their own (their details are on the team row): --person=new,\n";
    echo "and write \"new\" for them in --members and --credit too.\n";
    exit(0);
}

$team_id   = (int)$o['team'];
$new_person = strtolower(trim((string)$o['person'])) === 'new';
$person_id = $new_person ? -1 : (int)$o['person'];   // -1 = the row created below
$ids_of = fn(string $v) => array_values(array_unique(array_filter(array_map(
    fn($x) => strtolower(trim($x)) === 'new' ? -1 : (int)$x, explode(',', $v)))));
$members   = $ids_of((string)($o['members'] ?? $person_id));
if (!in_array($person_id, $members, true)) $members[] = $person_id;
$credit = isset($o['credit'])
    ? $ids_of((string)$o['credit'])
    : $members;
foreach ($credit as $c) if (!in_array($c, $members, true)) { fwrite(STDERR, "--credit #{$c} is not in --members.\n"); exit(1); }

$get = fn(int $id) => $id > 0 ? $conn->query("SELECT * FROM marketing_intakes WHERE id = {$id}")->fetch_assoc() : null;
$team = $get($team_id);
$hint = "Use the numbers from --list (not office roster or MLS numbers), with no spaces around the commas.\n";
if (!$team_id || !$person_id) { fwrite(STDERR, "--team and --person need numbers (or --person=new), e.g. --team=8 --person=new.\n" . $hint); exit(1); }
if (!$team) { fwrite(STDERR, "No roster row #{$team_id} (--team).\n" . $hint); exit(1); }
if ($new_person) {
    $new_name = trim((string)($o['person-name'] ?? '')) ?: trim((string)($team['mls_full_name'] ?? ''));
    if ($new_name === '') { fwrite(STDERR, "The team row has no MLS name to use. Add --person-name=\"Jonathan Boxer\".\n"); exit(1); }
    $person = ['agent_name' => $new_name . ' (new)'];
} else {
    $person = $get($person_id);
    if (!$person) { fwrite(STDERR, "No roster row #{$person_id} (--person).\n" . $hint); exit(1); }
    if ($team_id === $person_id) { fwrite(STDERR, "--team and --person are the same row (#{$team_id}). Use --person=new to create the person's own row.\n"); exit(1); }
}
foreach ($members as $m) if ($m !== -1 && !$get($m)) { fwrite(STDERR, "No roster row #{$m} (--members).\n" . $hint); exit(1); }
if (in_array($team_id, $members, true)) { fwrite(STDERR, "The team (#{$team_id}) cannot be one of its own --members.\n"); exit(1); }

$label = fn(array $ids) => '#' . implode(', #', array_map(fn($i) => $i === -1 ? 'new' : $i, $ids));
echo "Team   #{$team_id} {$team['agent_name']}\nPerson " . ($new_person ? 'NEW' : "#{$person_id}") . " {$person['agent_name']}\nMembers: " . $label($members)
   . "\nTeam MLS ID credits: " . $label($credit)
   . ($dry ? "\nDRY RUN: nothing will be written\n" : "\n") . "\n";

$blank = fn($v) => $v === null || trim(strip_tags((string)$v)) === '';
$conn->begin_transaction();
try {
    if ($new_person) {
        // Their own row: active, today, no slug yet (the slug moves over from the team below).
        // Linked to their Spark MLS roster line when one is free, so the roster
        // does not show them twice.
        $rid = null;
        if (($t = $conn->query("SHOW TABLES LIKE 'office_roster'")) && $t->fetch_row()) {
            $em = strtolower(trim((string)($team['mls_email'] ?: $team['mh_email']))); $nm = strtolower($new_name);
            $s = $conn->prepare("SELECT r.id FROM office_roster r WHERE ((? <> '' AND LOWER(r.email) = ?) OR LOWER(TRIM(r.name)) = ?)
                                   AND r.name NOT LIKE '%|%' AND NOT EXISTS (SELECT 1 FROM marketing_intakes mi WHERE mi.roster_id = r.id)
                                 ORDER BY (LOWER(r.email) = ?) DESC LIMIT 1");
            $s->bind_param('ssss', $em, $em, $nm, $em); $s->execute();
            $row = $s->get_result()->fetch_row(); $s->close();
            $rid = $row ? (int)$row[0] : null;
        }
        $s = $conn->prepare("INSERT INTO marketing_intakes (agent_name, intake_date, status, is_active, roster_id) VALUES (?, CURDATE(), 'active', 1, ?)");
        $s->bind_param('si', $new_name, $rid); $s->execute(); $person_id = (int)$conn->insert_id; $s->close();
        $person = $get($person_id);
        $members = array_map(fn($m) => $m === -1 ? $person_id : $m, $members);
        $credit  = array_map(fn($m) => $m === -1 ? $person_id : $m, $credit);
        echo "  new   #{$person_id} {$new_name}\n";
    }
    $sets = []; $vals = []; $types = '';
    // Moved: website + MLS fields belong to the person.
    foreach (['slug', 'web_status', 'web_approved_at', 'sort_order', 'in_fub', 'office', 'service_area',
              'mls_full_name', 'mls_email', 'mls_phone', 'mls_synced_at'] as $c) {
        if ($blank($team[$c] ?? null) || (in_array($c, ['sort_order', 'in_fub'], true) && !(int)$team[$c])) continue;
        if ($c === 'web_status' && $team[$c] === 'pending' && $person[$c] !== 'pending') continue;
        echo "  move  {$c}: " . substr((string)$team[$c], 0, 60) . "\n";
        $sets[] = "`{$c}` = ?"; $vals[] = (string)$team[$c]; $types .= 's';
    }
    // Copied where the person has nothing.
    foreach (['mh_email', 'alt_email', 'cell_phone', 'agent_title', 'bio_text', 'headshot_url', 'headshot_face_url',
              'social_instagram', 'social_facebook', 'social_linkedin', 'social_tiktok', 'website_url'] as $c) {
        if ($blank($team[$c] ?? null) || !$blank($person[$c] ?? null)) continue;
        echo "  copy  {$c}: " . substr(strip_tags((string)$team[$c]), 0, 60) . "\n";
        $sets[] = "`{$c}` = ?"; $vals[] = (string)$team[$c]; $types .= 's';
    }
    if (!$dry && $sets) {
        // The slug is unique: free it on the team row first.
        $conn->query("UPDATE marketing_intakes SET slug = NULL WHERE id = {$team_id}");
        $vals[] = $person_id; $types .= 'i';
        $s = $conn->prepare("UPDATE marketing_intakes SET " . implode(', ', $sets) . " WHERE id = ?");
        $s->bind_param($types, ...$vals); $s->execute(); $s->close();
    }

    // Identities.
    $ids = $conn->query("SELECT * FROM agent_mls_ids WHERE intake_id = {$team_id}")->fetch_all(MYSQLI_ASSOC);
    foreach ($ids as $i) {
        $to = (int)$i['is_alias'] ? $credit : [$person_id];
        echo "  " . ((int)$i['is_alias'] ? 'alias' : 'ident') . " {$i['market']}/{$i['mls_agent_id']} → #" . implode(', #', $to) . "\n";
        if ($dry) continue;
        $ins = $conn->prepare("INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id, agent_key, member_status, is_alias, last_seen_at)
                               VALUES (?,?,?,?,?,?,?)");
        foreach ($to as $m) {
            $ins->bind_param('issssis', $m, $i['market'], $i['mls_agent_id'], $i['agent_key'], $i['member_status'], $i['is_alias'], $i['last_seen_at']);
            $ins->execute();
        }
        $ins->close();
        $conn->query("DELETE FROM agent_mls_ids WHERE id = " . (int)$i['id']);
    }

    // Hot Sheet subscription follows the person.
    $has_hs = ($t = $conn->query("SHOW TABLES LIKE 'hs_subscribers'")) && $t->fetch_row();
    if ($has_hs) {
        $n = (int)$conn->query("SELECT COUNT(*) FROM hs_subscribers WHERE intake_id = {$team_id}")->fetch_row()[0];
        if ($n) { echo "  Hot Sheet subscription → #{$person_id}\n"; if (!$dry) $conn->query("UPDATE hs_subscribers SET intake_id = {$person_id} WHERE intake_id = {$team_id}"); }
    }

    // The team row itself. "Weber Boxer Group | Jonathan Boxer Scott Weber"
    // (the MLS display name) becomes "Weber Boxer Group"; --name overrides.
    $team_name = trim((string)($o['name'] ?? explode('|', (string)$team['agent_name'])[0]));
    if ($team_name === '') $team_name = (string)$team['agent_name'];
    $ini = strtoupper(trim((string)($o['initials'] ?? '')));
    if ($ini === '') {
        $words = preg_split('/\s+/', preg_replace('/\s+(group|team)$/i', '', $team_name));
        $ini = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 3))));
    }
    $ini = mb_substr($ini, 0, 4);
    $has_ini = ($t = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'initials'")) && $t->fetch_row();
    echo "  team  #{$team_id}: named \"{$team_name}\"" . ($has_ini ? " ({$ini})" : '') . ", entity_type team, off the website, not in FUB, no MLS mirror\n";
    if (!$dry) {
        $s = $conn->prepare("UPDATE marketing_intakes SET agent_name = ?, entity_type = 'team', slug = NULL, web_status = 'inactive', in_fub = 0,
                             mls_full_name = NULL, mls_email = NULL, mls_phone = NULL, mls_synced_at = NULL
                       WHERE id = ?");
        $s->bind_param('si', $team_name, $team_id); $s->execute(); $s->close();
        if ($has_ini) {
            $s = $conn->prepare("UPDATE marketing_intakes SET initials = ? WHERE id = ?");
            $s->bind_param('si', $ini, $team_id); $s->execute(); $s->close();
        }
        $tm = $conn->prepare("INSERT IGNORE INTO team_members (team_id, member_id, sort_order) VALUES (?,?,?)");
        foreach ($members as $k => $m) { $tm->bind_param('iii', $team_id, $m, $k); $tm->execute(); }
        $tm->close();
    }
    echo "  members: #" . implode(', #', $members) . "\n";

    $dry ? $conn->rollback() : $conn->commit();
    if (!$dry && $new_person) {
        require_once __DIR__ . '/../inc/agent_lifecycle.php';
        foreach (mk_onboard($conn, $person_id) as $line) echo "  onboard: {$line}\n";
    }
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, '✗ ' . $e->getMessage() . " — rolled back, nothing written.\n");
    exit(1);
}
echo "\n" . ($dry ? "DRY RUN done." : "Done.") . " Campaigns, collateral, tasks, notes and billing stay on the team.\n";
