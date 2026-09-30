<?php
/**
 * cron/import_site_agents.php — ONE-TIME import of site.monthaus.com's agent
 * admin data into the roster (marketing_intakes + agent_mls_ids).
 *
 * Input is the JSON written by site.monthaus.com/export_agents_for_marketing.php.
 *
 *   php cron/import_site_agents.php /tmp/site_agents.json --dry-run
 *   php cron/import_site_agents.php /tmp/site_agents.json
 *
 * Rules (Nikki, 2026-09-21: marketing wins):
 *   · match each site agent to ONE roster row: board identity, then email, then
 *     name. An ambiguous match is reported and skipped, never guessed.
 *   · ALWAYS taken from the site (it is the only place these exist today):
 *     slug, website status + approved date, sort order, FUB flag, office /
 *     service area, board identities.
 *   · FILLED ONLY WHERE MARKETING IS BLANK: name, title, bio, email, phone,
 *     socials, website, mls_* mirror.
 *   · a site agent with no match becomes a new row, status 'roster'.
 *   · identities arrive with last_seen_at NULL: they were seen by Spark or
 *     Constellation, not Anyprop, so the Anyprop sync may not deactivate
 *     anyone over them until Anyprop has returned them once.
 *   · headshots are not imported here; the photo pipeline brings its own.
 *
 * Safe to re-run: every write is a fill-blank, a set-to-same, or INSERT IGNORE.
 * Run it BEFORE the website starts reading the feed, or every agent the site
 * has approved would arrive there as 'pending'.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/agent_roster.php';

$file = $argv[1] ?? '';
$dry  = in_array('--dry-run', $argv, true);
if ($file === '' || !is_file($file)) { fwrite(STDERR, "usage: php cron/import_site_agents.php <export.json> [--dry-run]\n"); exit(1); }
$data = json_decode(file_get_contents($file), true);
if (!is_array($data['agents'] ?? null)) { fwrite(STDERR, "{$file} is not an export from export_agents_for_marketing.php\n"); exit(1); }

$chk = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'web_status'");
if (!$chk || !$chk->fetch_row()) { fwrite(STDERR, "sql/agent_roster_v1.sql has not been run. Nothing done.\n"); exit(1); }

echo 'Importing ' . count($data['agents']) . " site agent(s)" . ($dry ? ' — DRY RUN, nothing will be written' : '') . "\n\n";

function one(mysqli $conn, string $sql, string $types, array $vals): array {
    $s = $conn->prepare($sql);
    if (!$s) throw new RuntimeException("prepare failed: {$conn->error}");
    if ($types !== '') $s->bind_param($types, ...$vals);
    $s->execute();
    $r = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $r;
}
/** Prefer the single live row when an archived duplicate shares the email or name. */
function narrow(array $hit): array {
    if (count($hit) < 2) return $hit;
    $live = array_values(array_filter($hit, fn($r) => (int)$r['is_active'] === 1 && $r['status'] !== 'archived'));
    return count($live) >= 1 ? $live : $hit;
}
/** Blank includes markup with no text in it: a cleared bio editor stores <p><br></p>. */
function blank($v): bool { return $v === null || trim(strip_tags((string)$v)) === ''; }

$stats = ['matched' => 0, 'created' => 0, 'ambiguous' => 0, 'slug_conflict' => 0, 'identities' => 0];

foreach ($data['agents'] as $a) {
    $name  = trim((string)($a['display_name'] ?? '')) ?: trim((string)($a['mls_full_name'] ?? ''));
    $slug  = trim((string)($a['slug'] ?? ''));
    $label = "{$name} [{$slug}]";

    // ── Match ────────────────────────────────────────────────────────────────
    $iid = 0; $how = '';
    foreach ($a['identities'] ?? [] as $i) {
        if ((int)$i['is_alias']) continue;
        $hit = one($conn, "SELECT DISTINCT intake_id AS id FROM agent_mls_ids WHERE market = ? AND mls_agent_id = ? AND is_alias = 0",
                   'ss', [$i['market'], $i['mls_agent_id']]);
        if (count($hit) === 1) { $iid = (int)$hit[0]['id']; $how = "identity {$i['market']}/{$i['mls_agent_id']}"; break; }
    }
    if (!$iid) {
        foreach (array_unique(array_filter([strtolower(trim((string)($a['email'] ?? ''))), strtolower(trim((string)($a['mls_email'] ?? '')))])) as $em) {
            $hit = narrow(one($conn, "SELECT id, is_active, status FROM marketing_intakes WHERE (LOWER(mh_email) = ? OR LOWER(alt_email) = ? OR LOWER(mls_email) = ?)" . mk_team_sql($conn),
                       'sss', [$em, $em, $em]));
            if (count($hit) === 1) { $iid = (int)$hit[0]['id']; $how = "email {$em}"; break; }
            if (count($hit) > 1) { $iid = -1; $how = "email {$em} → #" . implode(', #', array_column($hit, 'id')); break; }
        }
    }
    if (!$iid) {
        foreach (array_unique(array_filter([strtolower($name), strtolower(trim((string)($a['mls_full_name'] ?? '')))])) as $nm) {
            $hit = narrow(one($conn, "SELECT id, is_active, status FROM marketing_intakes WHERE (LOWER(TRIM(agent_name)) = ? OR LOWER(TRIM(mls_full_name)) = ?)" . mk_team_sql($conn),
                       'ss', [$nm, $nm]));
            if (count($hit) === 1) { $iid = (int)$hit[0]['id']; $how = "name"; break; }
            if (count($hit) > 1) { $iid = -1; $how = "name → #" . implode(', #', array_column($hit, 'id')); break; }
        }
    }
    if ($iid === -1) { $stats['ambiguous']++; echo "? {$label}: ambiguous ({$how}) — skipped, link by hand\n"; continue; }

    // ── Slug: the site's is a public URL, so it wins unless another row has it ─
    if ($slug !== '') {
        $holder = one($conn, "SELECT id FROM marketing_intakes WHERE slug = ? AND id <> ?", 'si', [$slug, $iid]);
        if ($holder) {
            $stats['slug_conflict']++;
            echo "! {$label}: slug already held by #{$holder[0]['id']} — ";
            $slug = '';   // keep whatever the row has; the sync assigns one if it has none
            echo "slug not taken\n";
        }
    }

    $office       = $a['offices'][0] ?? 'other';
    $service_area = trim((string)($a['service_area'] ?? ''));
    $web_status   = in_array($a['status'] ?? '', ['pending', 'approved', 'inactive'], true) ? $a['status'] : 'pending';
    $approved_at  = $a['approved_at'] ?: null;
    $sort         = (int)($a['sort_order'] ?? 0);
    $fub          = (int)!empty($a['in_fub']);

    $fill = [   // roster column => site value, written only where the roster is blank
        'agent_name'       => $name,
        'agent_title'      => trim((string)($a['title'] ?? '')),
        'bio_text'         => mk_clean_bio($a['bio'] ?? ''),
        'mh_email'         => strtolower(trim((string)($a['email'] ?? ''))),
        'cell_phone'       => trim((string)($a['phone'] ?? '')),
        'social_instagram' => trim((string)($a['instagram'] ?? '')),
        'social_facebook'  => trim((string)($a['facebook'] ?? '')),
        'social_linkedin'  => trim((string)($a['linkedin'] ?? '')),
        'social_tiktok'    => trim((string)($a['tiktok'] ?? '')),
        'website_url'      => trim((string)($a['website'] ?? '')),
        'mls_full_name'    => trim((string)($a['mls_full_name'] ?? '')),
        'mls_email'        => strtolower(trim((string)($a['mls_email'] ?? ''))),
        'mls_phone'        => trim((string)($a['mls_phone'] ?? '')),
    ];

    if ($iid) {
        $stats['matched']++;
        $cur = one($conn, "SELECT * FROM marketing_intakes WHERE id = ?", 'i', [$iid])[0];
        $sets = ['web_status = ?', 'web_approved_at = ?', 'sort_order = ?', 'in_fub = ?', 'office = ?', 'service_area = ?'];
        $vals = [$web_status, $approved_at, $sort, $fub, $office, $service_area ?: $cur['service_area']];
        $types = 'ssiiss';
        if ($slug !== '') { $sets[] = 'slug = ?'; $vals[] = $slug; $types .= 's'; }
        $filled = [];
        foreach ($fill as $col => $v) {
            if ($v !== '' && blank($cur[$col] ?? null)) { $sets[] = "`{$col}` = ?"; $vals[] = $v; $types .= 's'; $filled[] = $col; }
        }
        echo "= {$label} → #{$iid} {$cur['agent_name']} (by {$how}); web {$web_status}, office {$office}"
           . ($filled ? '; filled: ' . implode(', ', $filled) : '; nothing to fill') . "\n";
        if (!$dry) {
            $vals[] = $iid; $types .= 'i';
            $s = $conn->prepare("UPDATE marketing_intakes SET " . implode(', ', $sets) . " WHERE id = ?");
            $s->bind_param($types, ...$vals); $s->execute(); $s->close();
        }
    } else {
        $stats['created']++;
        echo "+ {$label}: no roster row — creating (status roster, web {$web_status})\n";
        // Link the Spark-era office_roster row nobody has claimed, so index.php
        // draws one card for this person rather than two.
        $roster_id = null;
        foreach (array_filter([strtolower(trim((string)($a['mls_email'] ?? ''))), strtolower(trim((string)($a['email'] ?? '')))]) as $em) {
            $r = one($conn, "SELECT r.id FROM office_roster r LEFT JOIN marketing_intakes mi ON mi.roster_id = r.id
                              WHERE LOWER(r.email) = ? AND mi.id IS NULL LIMIT 1", 's', [$em]);
            if ($r) { $roster_id = (int)$r[0]['id']; break; }
        }
        if (!$roster_id) {
            $r = one($conn, "SELECT r.id FROM office_roster r LEFT JOIN marketing_intakes mi ON mi.roster_id = r.id
                              WHERE LOWER(TRIM(r.name)) IN (?, ?) AND mi.id IS NULL LIMIT 1",
                     'ss', [strtolower($name), strtolower(trim((string)($a['mls_full_name'] ?? '')))]);
            if ($r) $roster_id = (int)$r[0]['id'];
        }
        if ($roster_id) echo "    linked to office_roster #{$roster_id}\n";
        if (!$dry) {
            $cols = ['intake_date', 'status', 'is_active', 'web_status', 'web_approved_at', 'sort_order', 'in_fub', 'office', 'service_area', 'slug'];
            $cols[] = 'roster_id';
            $vals = [date('Y-m-d'), 'roster', 1, $web_status, $approved_at, $sort, $fub, $office, $service_area ?: null, $slug ?: null, $roster_id];
            $types = 'ssissiisssi';
            foreach ($fill as $col => $v) if ($v !== '') { $cols[] = $col; $vals[] = $v; $types .= 's'; }
            $s = $conn->prepare("INSERT INTO marketing_intakes (`" . implode('`, `', $cols) . "`) VALUES ("
                              . implode(',', array_fill(0, count($cols), '?')) . ")");
            $s->bind_param($types, ...$vals); $s->execute(); $iid = (int)$conn->insert_id; $s->close();
        }
    }

    // ── Identities ───────────────────────────────────────────────────────────
    foreach ($a['identities'] ?? [] as $i) {
        $stats['identities']++;
        echo "    {$i['market']}/{$i['mls_agent_id']}" . ((int)$i['is_alias'] ? ' (team alias)' : '') . "\n";
        if ($dry || !$iid) continue;
        $s = $conn->prepare("INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id, agent_key, member_status, is_alias)
                             VALUES (?, ?, ?, ?, ?, ?)");
        $mk = (string)$i['market']; $mid = (string)$i['mls_agent_id']; $key = (string)($i['agent_key'] ?? '');
        $ms = (string)($i['member_status'] ?: 'Active'); $al = (int)$i['is_alias'];
        $s->bind_param('issssi', $iid, $mk, $mid, $key, $ms, $al); $s->execute(); $s->close();
    }
}

$slugs = mk_assign_missing_slugs($conn, $dry);
echo "\n" . ($dry ? 'DRY RUN: ' : '') . "{$stats['matched']} matched, {$stats['created']} created, "
   . "{$stats['ambiguous']} ambiguous (skipped), {$stats['slug_conflict']} slug conflicts, "
   . "{$stats['identities']} identities, " . count($slugs) . " other rows given slugs.\n";
$conn->close();
