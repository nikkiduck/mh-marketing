<?php
/**
 * cron/import_profiles.php — bulk headshots, bios and contact details.
 *
 * Drop the files in an import folder, run this once, and every agent it can
 * match is filled in. Re-runnable: by default it only fills what is empty, so
 * a second run changes nothing unless you pass --overwrite.
 *
 *   /var/www/marketing.monthaus.com/import/
 *       headshots/      Jonathan Boxer.jpg, scott-weber.png, sara.perkowski@monthaus.com.jpg
 *       bios/           Jonathan Boxer.txt, scott-weber.html
 *       profiles.csv    a spreadsheet saved as CSV (see below)
 *
 * A file is matched to an agent by its name, whatever spelling: their email,
 * their website key (slug), their name on the roster, or their MLS name.
 * Punctuation, case, spaces, dots, underscores and dashes are all ignored, so
 * "Jonathan Boxer.jpg", "jonathan_boxer.JPG" and "JonathanBoxer.jpeg" all land
 * on the same person. Anything that matches nobody, or two people, is listed
 * and skipped: nothing is guessed.
 *
 * profiles.csv: first row is the headings. One column must identify the agent
 * (heading "email", "slug", "name" or "agent"). The rest are optional, any
 * order, and a blank cell is left alone:
 *     name, email, phone, title, bio, instagram, facebook, linkedin, tiktok,
 *     website, service area, office, board, mls id
 * With --create, a row whose name matches nobody is added to the roster as an
 * Active agent with the marketing checklist, instead of being skipped: that is
 * how someone the MLS feed has not reached yet (a Denver agent, say) gets in.
 * Give them a "board" and "mls id" (for example REColorado and 55061958) and
 * the identity is recorded too, so listings credit them and the MLS sync
 * recognises them later instead of creating a second row.
 * "email" fills their Mont Haus email; the MLS email and phone stay separate,
 * as the MLS supplies them.
 *
 * Headshots become two files in the portal's own agent-photos folder, so the
 * website can use them: a full one (long side 1400px) and a square one for
 * cards and lists. The square crop is taken from the top middle, which suits
 * an ordinary headshot; re-crop any that look off on the agent page.
 *
 *   php /var/www/marketing.monthaus.com/cron/import_profiles.php --dry-run
 *   php /var/www/marketing.monthaus.com/cron/import_profiles.php
 *   php /var/www/marketing.monthaus.com/cron/import_profiles.php --overwrite
 *   php /var/www/marketing.monthaus.com/cron/import_profiles.php --create
 *   php /var/www/marketing.monthaus.com/cron/import_profiles.php --from-mls
 *   php /var/www/marketing.monthaus.com/cron/import_profiles.php --dir=/home/admin/agent-files
 *
 * --from-mls copies the MLS email and phone into the contact fields for anyone
 * whose are blank, which is usually all the contact detail needed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/agent_roster.php';
require_once __DIR__ . '/../inc/agent_lifecycle.php';   // mk_onboard() for --create

$dry = in_array('--dry-run', $argv, true);
$over = in_array('--overwrite', $argv, true);
$from_mls = in_array('--from-mls', $argv, true);
$create = in_array('--create', $argv, true);
$dir = __DIR__ . '/../import';
foreach ($argv as $a) if (preg_match('/^--dir=(.+)$/', $a, $m)) $dir = rtrim($m[1], '/');
$photo_dir = __DIR__ . '/../agent-photos';
$photo_url = rtrim(SITE_URL, '/') . '/agent-photos';

echo "Profile import from {$dir}" . ($dry ? ' (DRY RUN: nothing will be written)' : '') . ($over ? ' (overwriting what is already there)' : '') . "\n\n";

// ── Who we can match ────────────────────────────────────────────────────────
$key = fn(string $s) => strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
// Every column: $stage() compares against what is already there, so a second
// run has to see the fields the spreadsheet can fill (socials, website, ...).
$people = $conn->query("SELECT * FROM marketing_intakes
                         WHERE is_active = 1 AND status <> 'archived'" . mk_agents_only_sql($conn))->fetch_all(MYSQLI_ASSOC);
$by_key = [];
foreach ($people as $p) {
    foreach ([$p['mh_email'], $p['mls_email'], $p['slug'], $p['agent_name'], $p['mls_full_name']] as $v) {
        $k = $key((string)$v);
        if ($k === '') continue;
        $by_key[$k][$p['id']] = $p;
        // Also the local part of an address, so "jonathan.boxer.jpg" works.
        if (str_contains((string)$v, '@')) {
            $lk = $key(strstr((string)$v, '@', true));
            if ($lk !== '') $by_key[$lk][$p['id']] = $p;
        }
    }
}
/** One agent, or null with the reason printed. */
$match = function (string $label, string $raw) use (&$by_key, $key): ?array {
    $hits = $by_key[$key($raw)] ?? [];
    if (count($hits) === 1) return reset($hits);
    echo '   ? ' . $label . ': ' . (count($hits) > 1
        ? 'matches ' . count($hits) . ' agents (#' . implode(', #', array_keys($hits)) . '), skipped'
        : 'no agent with that name or email, skipped') . "\n";
    return null;
};

/** The same match, without printing: the CSV reports once per row. */
$quiet_match = function (string $raw) use (&$by_key, $key): ?array {
    $hits = $by_key[$key($raw)] ?? [];
    return count($hits) === 1 ? reset($hits) : null;
};

$updates = [];   // id => [column => value]
$stage = function (int $id, string $col, $val, array $p) use (&$updates, $over): bool {
    $cur = trim(strip_tags((string)($updates[$id][$col] ?? $p[$col] ?? '')));
    if ($cur !== '' && !$over) return false;
    $updates[$id][$col] = $val;
    return true;
};

// ── 1. Headshots ────────────────────────────────────────────────────────────
$shots = glob($dir . '/headshots/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [];
$other = array_diff(glob($dir . '/headshots/*') ?: [], $shots);
echo '1. Headshots: ' . count($shots) . " image(s)\n";
foreach ($other as $o) if (!is_dir($o)) echo '   ? ' . basename($o) . ": not a JPG, PNG or WEBP, skipped\n";

/** Load, straighten and scale; returns a GD image or null. */
function ip_load(string $file): ?GdImage {
    $info = @getimagesize($file);
    $img = match ($info[2] ?? 0) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
        IMAGETYPE_PNG  => @imagecreatefrompng($file),
        IMAGETYPE_WEBP => @imagecreatefromwebp($file),
        default        => null,
    };
    if (!$img) return null;
    // Phone photos carry their rotation in EXIF rather than in the pixels.
    if (($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $e = @exif_read_data($file);
        $deg = [3 => 180, 6 => -90, 8 => 90][$e['Orientation'] ?? 0] ?? 0;
        if ($deg) $img = imagerotate($img, $deg, 0);
    }
    return $img;
}
function ip_scale(GdImage $src, int $max): GdImage {
    $w = imagesx($src); $h = imagesy($src);
    $s = min(1, $max / max($w, $h));
    if ($s >= 1) return $src;
    $out = imagecreatetruecolor((int)round($w * $s), (int)round($h * $s));
    imagecopyresampled($out, $src, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    return $out;
}
/** Square crop from the top middle: where a head is in a headshot. */
function ip_square(GdImage $src, int $size): GdImage {
    $w = imagesx($src); $h = imagesy($src);
    $side = min($w, $h);
    $x = (int)round(($w - $side) / 2);
    $y = (int)round(min(max(0, ($h - $side) * 0.15), $h - $side));
    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $src, 0, 0, $x, $y, $size, $size, $side, $side);
    return $out;
}

foreach ($shots as $file) {
    $base = pathinfo($file, PATHINFO_FILENAME);
    $p = $match(basename($file), $base);
    if (!$p) continue;
    $id = (int)$p['id'];
    $hosted = fn($u) => $u && (str_starts_with((string)$u, 'photo.php') || str_starts_with((string)$u, rtrim(SITE_URL, '/') . '/'));
    if ($hosted($p['headshot_url']) && $hosted($p['headshot_face_url']) && !$over) {
        echo "   = {$p['agent_name']}: already has a headshot here, left alone (--overwrite to replace)\n";
        continue;
    }
    $name = ($p['slug'] ?: ('agent-' . $id));
    echo "   + {$p['agent_name']} ← " . basename($file) . " → {$name}.jpg, {$name}-square.jpg\n";
    if (!$dry) {
        if (!is_dir($photo_dir) && !@mkdir($photo_dir, 0775, true)) { echo "   ✗ cannot create {$photo_dir}\n"; break; }
        $img = ip_load($file);
        if (!$img) { echo "   ✗ " . basename($file) . ": could not be read as an image\n"; continue; }
        imagejpeg(ip_scale($img, 1400), "{$photo_dir}/{$name}.jpg", 88);
        imagejpeg(ip_square($img, 600), "{$photo_dir}/{$name}-square.jpg", 88);
        // Group-writable, so the web server can replace them from the crop tool.
        @chmod("{$photo_dir}/{$name}.jpg", 0664);
        @chmod("{$photo_dir}/{$name}-square.jpg", 0664);
    }
    $v = time();
    $updates[$id]['headshot_url']      = "{$photo_url}/{$name}.jpg?v={$v}";
    $updates[$id]['headshot_face_url'] = "{$photo_url}/{$name}-square.jpg?v={$v}";
}

// ── 2. Bios ─────────────────────────────────────────────────────────────────
$bios = glob($dir . '/bios/*.{txt,html,htm,md,TXT,HTML,HTM,MD}', GLOB_BRACE) ?: [];
echo "\n2. Bios: " . count($bios) . " file(s)\n";
foreach ($bios as $file) {
    $p = $match(basename($file), pathinfo($file, PATHINFO_FILENAME));
    if (!$p) continue;
    $raw = (string)file_get_contents($file);
    if (!preg_match('/<\w+[^>]*>/', $raw)) {
        // Plain text: blank lines separate paragraphs.
        $raw = implode('', array_map(fn($x) => '<p>' . htmlspecialchars(trim($x), ENT_QUOTES) . '</p>',
                                     array_filter(preg_split('/\R\s*\R/', $raw), fn($x) => trim($x) !== '')));
    }
    $clean = mk_clean_bio($raw);
    if (trim(strip_tags($clean)) === '') { echo "   ? " . basename($file) . ": empty, skipped\n"; continue; }
    echo ($stage((int)$p['id'], 'bio_text', $clean, $p)
        ? "   + {$p['agent_name']} ← " . basename($file) . ' (' . str_word_count(strip_tags($clean)) . " words)\n"
        : "   = {$p['agent_name']}: already has a bio, left alone (--overwrite to replace)\n");
}

// ── 3. profiles.csv ─────────────────────────────────────────────────────────
$csv = null;
foreach ([$dir . '/profiles.csv', $dir . '/contacts.csv', $dir . '/agents.csv'] as $c) if (is_file($c)) { $csv = $c; break; }
echo "\n3. Spreadsheet: " . ($csv ? basename($csv) : 'none found (profiles.csv)') . "\n";
if ($csv && ($fh = fopen($csv, 'r'))) {
    // Headings are read loosely: case, spaces and punctuation are ignored, and
    // the usual spellings all map to the same field.
    $cols = [];
    foreach ([
        'mh_email'         => ['email', 'emailaddress', 'mhemail', 'monthausemail', 'workemail', 'agentemail', 'officeemail'],
        'alt_email'        => ['altemail', 'alternateemail', 'personalemail', 'secondemail'],
        'cell_phone'       => ['phone', 'phonenumber', 'cell', 'cellphone', 'cellnumber', 'mobile', 'mobilephone', 'mobilenumber', 'directphone'],
        'agent_title'      => ['title', 'jobtitle', 'role', 'position'],
        'bio_text'         => ['bio', 'biography', 'about', 'profile'],
        'social_instagram' => ['instagram', 'ig', 'instagramurl', 'instagramhandle'],
        'social_facebook'  => ['facebook', 'fb', 'facebookurl'],
        'social_linkedin'  => ['linkedin', 'linkedinurl'],
        'social_tiktok'    => ['tiktok', 'tiktokurl'],
        'website_url'      => ['website', 'websiteurl', 'personalwebsite', 'url'],
        'service_area'     => ['servicearea', 'serviceareas', 'areasserved', 'markets served'],
        'office'           => ['office', 'officelocation'],
    ] as $col => $names) foreach ($names as $nm) $cols[preg_replace('/[^a-z0-9]/', '', $nm)] = $col;
    $boards = ['aspen' => 'aspen', 'agsmls' => 'aspen', 'vail' => 'vail', 'cren' => 'cren', 'recolorado' => 'recolorado',
               'denver' => 'recolorado', 'remetrodenver' => 'recolorado', 'elevate' => 'elevate', 'altitude' => 'altitude'];
    $head = fgetcsv($fh) ?: [];
    $head = array_map(fn($h) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$h)), $head);
    $find = function (array $names) use ($head) {
        foreach ($names as $n) { $i = array_search($n, $head, true); if ($i !== false) return $i; }
        return false;
    };
    $name_col  = $find(['name', 'agentname', 'agent', 'fullname', 'agentfullname', 'displayname', 'brokername', 'broker']);
    $first_col = $find(['first', 'firstname', 'givenname']);
    $last_col  = $find(['last', 'lastname', 'surname', 'familyname']);
    $board_col = $find(['board', 'market', 'mls', 'mlsboard', 'mlsname']);
    $mlsid_col = $find(['mlsid', 'mlsagentid', 'agentmlsid', 'mlsnumber', 'agentid', 'memberid', 'membermlsid']);
    // Several identifying columns can be present; a row is matched on whichever
    // of them it actually fills in (a name row with no email still matches).
    $id_cols = [];
    foreach ($head as $i => $h) if (($cols[$h] ?? '') === 'mh_email') { $id_cols[] = $i; break; }
    $i = array_search('slug', $head, true); if ($i !== false) $id_cols[] = $i;
    foreach ([$name_col, $first_col !== false && $last_col !== false ? 'names' : false] as $c) if ($c !== false) $id_cols[] = $c;
    $id_col = $id_cols[0] ?? null;
    if ($id_col === null) {
        echo "   ? no column that identifies the agent (email, slug or name), skipped\n";
    } else {
        echo '   headings: matching on ' . implode(' / ', array_map(fn($c) => $c === 'names' ? 'first + last' : $head[$c], $id_cols))
           . ($create ? ', adding anyone not on the roster' : ', --create would add anyone not on the roster')
           . ($board_col !== false && $mlsid_col !== false ? ', MLS ID from "' . $head[$board_col] . '" + "' . $head[$mlsid_col] . '"' : '')
           . "\n";
        $n = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $n++;
            if (!array_filter(array_map('trim', $row))) continue;
            $row_name = $name_col !== false ? trim((string)($row[$name_col] ?? '')) : '';
            if ($row_name === '' && $first_col !== false && $last_col !== false) {
                $row_name = trim(trim((string)($row[$first_col] ?? '')) . ' ' . trim((string)($row[$last_col] ?? '')));
            }
            $who = ''; $p = null;
            foreach ($id_cols as $i) {
                $v = $i === 'names' ? $row_name : trim((string)($row[$i] ?? ''));
                if ($v === '') continue;
                $who = $who === '' ? $v : $who;
                $p = $quiet_match($v);
                if ($p) break;
            }
            if (!$p && $create) {
                $new_name = $row_name;
                if ($new_name === '') {
                    echo "   ? row {$n} ({$who}): --create needs a name (a \"name\" column, or \"first\" and \"last\"), skipped\n"; continue;
                }
                $board = $board_col !== false ? ($boards[strtolower(preg_replace('/[^a-z0-9]/i', '', (string)($row[$board_col] ?? '')))] ?? null) : null;
                $mls_id = $mlsid_col !== false ? trim((string)($row[$mlsid_col] ?? '')) : '';
                if ($dry) {
                    // A dry run writes nothing, so remember the ones it would
                    // add: a second row for the same person is not a second add.
                    static $would = [];
                    if (!isset($would[$key($new_name)])) {
                        $would[$key($new_name)] = true;
                        echo "   * {$new_name}: not on the roster, would be added as Active"
                           . ($board && $mls_id !== '' ? " with {$board} MLS ID {$mls_id}" : '') . "\n";
                    }
                    continue;
                }
                echo "   * {$new_name}: not on the roster, adding as Active"
                   . ($board && $mls_id !== '' ? " with {$board} MLS ID {$mls_id}" : '') . "\n";
                $st = $conn->prepare("INSERT INTO marketing_intakes (agent_name, intake_date, status, is_active) VALUES (?, CURDATE(), 'active', 1)");
                $st->bind_param('s', $new_name); $st->execute();
                $new_id = (int)$conn->insert_id; $st->close();
                if ($board && $mls_id !== '' && mk_table_exists($conn, 'agent_mls_ids')) {
                    // last_seen_at stays NULL: an identity Anyprop has never
                    // returned is never used to mark anyone Inactive.
                    $st = $conn->prepare("INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id, member_status, is_alias)
                                          VALUES (?, ?, ?, 'Active', 0)");
                    $st->bind_param('iss', $new_id, $board, $mls_id); $st->execute(); $st->close();
                }
                foreach (mk_onboard($conn, $new_id) as $line) echo "      {$line}\n";
                $p = $conn->query("SELECT * FROM marketing_intakes WHERE id = {$new_id}")->fetch_assoc();
                $people[] = $p;
                foreach ([$p['mh_email'], $p['slug'], $p['agent_name']] as $v) { $k = $key((string)$v); if ($k !== '') $by_key[$k][$p['id']] = $p; }
            }
            if (!$p) { echo "   ? row {$n} (" . ($who ?: 'no name or email') . '): ' . ($who === '' ? 'nothing to match on' : ($create ? 'no agent with that name or email' : 'no agent with that name or email (--create adds them to the roster)')) . ", skipped\n"; continue; }
            $set = [];
            foreach ($head as $i => $h) {
                if (!isset($cols[$h])) continue;   // the email column fills their email too
                $v = trim((string)($row[$i] ?? ''));
                if ($v === '') continue;
                $col = $cols[$h];
                if ($col === 'bio_text') $v = mk_clean_bio(preg_match('/<\w+[^>]*>/', $v) ? $v : '<p>' . htmlspecialchars($v, ENT_QUOTES) . '</p>');
                if ($stage((int)$p['id'], $col, $v, $p)) $set[] = $h;
            }
            echo $set ? "   + {$p['agent_name']}: " . implode(', ', $set) . "\n"
                      : "   = {$p['agent_name']}: nothing new (--overwrite to replace what is there)\n";
        }
    }
    fclose($fh);
}

// ── 4. Contact details from the MLS ─────────────────────────────────────────
if ($from_mls) {
    echo "\n4. Contact details from the MLS record\n";
    foreach ($people as $p) {
        $set = [];
        if (trim((string)$p['mls_email']) !== '' && $stage((int)$p['id'], 'mh_email', trim((string)$p['mls_email']), $p)) $set[] = 'email';
        if (trim((string)$p['mls_phone']) !== '' && $stage((int)$p['id'], 'cell_phone', trim((string)$p['mls_phone']), $p)) $set[] = 'phone';
        if ($set) echo "   + {$p['agent_name']}: " . implode(', ', $set) . "\n";
    }
}

// ── Save ────────────────────────────────────────────────────────────────────
echo "\n";
if (!$updates) { echo ($dry ? 'DRY RUN done. ' : '') . "Nothing to change.\n"; $conn->close(); exit(0); }
$n = 0;
foreach ($updates as $id => $set) {
    $n++;
    if ($dry) continue;
    $sql = 'UPDATE marketing_intakes SET ' . implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($set))) . ' WHERE id = ?';
    $s = $conn->prepare($sql);
    $vals = array_values($set); $vals[] = $id;
    $s->bind_param(str_repeat('s', count($set)) . 'i', ...$vals);
    $s->execute(); $s->close();
}
echo ($dry ? "DRY RUN done: {$n} agent(s) would be updated.\n" : "Done: {$n} agent(s) updated.\n");
echo "Website profiles still need your approval, and the site picks the photos up on its next agent sync.\n";
$conn->close();
