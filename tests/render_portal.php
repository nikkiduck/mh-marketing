<?php
/**
 * render_portal.php — the agent portal's security model as assertions.
 *
 *   php tests/render_portal.php
 *
 * docs/AGENT_PORTAL_PLAN.md section 8. Renders the real portal pages under a
 * PHP server, signed in as different people, against a stub database, and
 * asserts that nobody ever sees or changes another account's data: Jackson sees
 * only his codes, Weber Boxer Group sees the team's and its members', a code id
 * from someone else is refused on GET and on POST, an agent's ?preview= is
 * ignored, and every refusal names its reason.
 *
 * The stub is STRICT, like the other render harnesses: an unexpected SQL
 * statement throws, bind_param() checks its count, values come back as strings.
 * It evaluates the scoping clauses the pages write (`intake_id IN (...)`,
 * `q.id = N`, bound ids) against fixture rows, so a page that forgot its scope
 * would show another account's rows and fail here.
 *
 * Needs a PHP whose `php -n` has no mysqli built in (the servers' Debian PHP;
 * Homebrew's PHP compiles mysqli in, so run it on a server: copy the repo to
 * /tmp and run it there).
 */

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$what}\n"; }
    else { $fail++; echo "  FAIL {$what}" . ($detail !== '' ? " — " . substr($detail, 0, 300) : '') . "\n"; }
}
function has(string $hay, string $needle): bool { return strpos($hay, $needle) !== false; }

// The pages run under `php -n`; that PHP must not have its own mysqli class.
if (stripos((string)shell_exec('php -n -m 2>/dev/null'), 'mysqli') !== false) {
    fwrite(STDERR, "`php -n` here has mysqli built in, which the stub cannot replace: run this on a server (see header).\n");
    exit(1);
}

// ── Sandbox ──────────────────────────────────────────────────────────────────
$SB = sys_get_temp_dir() . '/pt_render_' . bin2hex(random_bytes(4));
mkdir($SB . '/inc', 0777, true);
mkdir($SB . '/portal', 0777, true);
foreach (['portal/index.php', 'portal/qr.php', 'inc/portal.php', 'inc/qr.php', 'inc/schema.php', 'inc/config.php'] as $f) {
    if (!is_file("{$ROOT}/{$f}")) { fwrite(STDERR, "FATAL: missing {$f}\n"); exit(1); }
    copy("{$ROOT}/{$f}", "{$SB}/{$f}");
}

file_put_contents($SB . '/inc/auth.php', <<<'PHP'
<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$scen = json_decode(file_get_contents(__DIR__ . '/../scenario.json'), true);
$who  = $scen['people'][$scen['as']];
$_SESSION['user_id']    = $who['id'];
$_SESSION['user_email'] = $who['email'];
$_SESSION['user_role']  = $who['role'];
$_SESSION['csrf_token'] = 'tok';
function require_login(): void {}
function require_role(string $r): void {}
function is_elevated_admin(): bool { return in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'], true); }
function is_super_admin(): bool { return ($_SESSION['user_role'] ?? '') === 'super_admin'; }
PHP);

file_put_contents($SB . '/inc/db.php', <<<'PHP'
<?php
const MYSQLI_ASSOC = 1; const MYSQLI_NUM = 2;
class mh_result {
    private int $i = 0;
    public function __construct(private array $rows) {}
    public function fetch_assoc() { return $this->rows[$this->i++] ?? null; }
    public function fetch_row()   { $r = $this->rows[$this->i++] ?? null; return $r === null ? null : array_values($r); }
    public function fetch_all(int $mode = MYSQLI_NUM): array {
        return $mode === MYSQLI_ASSOC ? $this->rows : array_map('array_values', $this->rows);
    }
}
class mh_stmt {
    private array $vars = [];
    public function __construct(private string $sql, private mysqli $db) {}
    public function bind_param(string $types, &...$vars): bool {
        if (strlen($types) !== count($vars)) throw new RuntimeException("bind_param: {$types} for " . count($vars) . " args");
        $this->vars = $vars; return true;
    }
    public function execute(): bool { $this->res = $this->db->run($this->sql, $this->vars); return true; }
    public $res = [];
    public function get_result() { return new mh_result($this->res); }
    public function close(): bool { return true; }
}
class mysqli {
    public int $insert_id = 0;
    public string $error = '';
    private array $D;
    public function __construct() { $this->D = json_decode(file_get_contents(__DIR__ . '/../scenario.json'), true)['db']; }
    private static function s(array $rows): array {
        return array_map(fn($r) => array_map(fn($v) => $v === null ? null : (string)$v, $r), $rows);
    }
    private static function in_list(string $sql): ?array {
        return preg_match('/intake_id IN \(([0-9, ]+)\)/', $sql, $m) ? array_map('intval', explode(',', $m[1])) : null;
    }
    private function write(string $what, array $vals): void {
        file_put_contents(__DIR__ . '/../writes.log', json_encode([$what, $vals]) . "\n", FILE_APPEND);
    }
    public function run(string $sql, array $p): array {
        $D = $this->D;
        if (stripos($sql, 'information_schema') !== false) {
            if (in_array('intake_id', $p, true) && in_array('users', $p, true)) return $D['migrated'] ? [[1]] : [];
            return [[1]];
        }
        if (preg_match('/FROM users WHERE id = \?/', $sql)) {
            return self::s(array_values(array_filter($D['users'], fn($u) => (int)$u['id'] === (int)$p[0])));
        }
        if (preg_match('/FROM marketing_intakes WHERE id = \?/', $sql)) {
            return self::s(array_values(array_filter($D['intakes'], fn($a) => (int)$a['id'] === (int)$p[0])));
        }
        if (preg_match('/FROM team_members WHERE team_id = \?/', $sql)) {
            return self::s(array_map(fn($m) => ['member_id' => $m], $D['teams'][(string)$p[0]] ?? []));
        }
        if (preg_match('/SELECT COUNT\(\*\) FROM qr_codes WHERE intake_id IN/', $sql)) {
            $in = self::in_list($sql);
            return [[ (string)count(array_filter($D['qr'], fn($q) => in_array((int)$q['intake_id'], $in, true))) ]];
        }
        if (preg_match('/FROM qr_codes q LEFT JOIN marketing_intakes mi/', $sql)) {
            $in = self::in_list($sql);
            if ($in === null) throw new RuntimeException("UNSCOPED qr_codes query:\n{$sql}");
            $one = preg_match('/q\.id = (\d+)/', $sql, $m) ? (int)$m[1] : null;
            $by  = array_column($D['intakes'], null, 'id');
            $rows = [];
            foreach ($D['qr'] as $q) {
                if (!in_array((int)$q['intake_id'], $in, true)) continue;
                if ($one !== null && (int)$q['id'] !== $one) continue;
                $a = $by[$q['intake_id']] ?? [];
                $rows[] = $q + ['agent_name' => $a['agent_name'] ?? null, 'slug' => $a['slug'] ?? null, 'entity_type' => $a['entity_type'] ?? 'agent'];
            }
            return self::s($rows);
        }
        if (preg_match('/^\s*UPDATE qr_codes SET dest_type/', $sql)) { $this->write('update_qr', $p); return []; }
        if (preg_match('/INSERT INTO qr_code_changes/', $sql))       { $this->write('log_change', $p); return []; }
        throw new RuntimeException("stub mysqli: no fixture for SQL:\n" . trim($sql));
    }
    public function query(string $sql) { return new mh_result($this->run($sql, [])); }
    public function prepare(string $sql) { return new mh_stmt($sql, $this); }
    public function close(): bool { return true; }
}
$conn = new mysqli();
PHP);

// ── Scenario ─────────────────────────────────────────────────────────────────
function scenario(array $over = []): array {
    return array_replace_recursive([
        'as' => 'jackson',
        'people' => [
            'jackson' => ['id' => 101, 'email' => 'jackson.horn@monthaus.com', 'role' => 'agent'],
            'jon'     => ['id' => 102, 'email' => 'jonathan.boxer@monthaus.com', 'role' => 'agent'],
            'nolink'  => ['id' => 103, 'email' => 'new.agent@monthaus.com', 'role' => 'agent'],
            'nikki'   => ['id' => 1,   'email' => 'nikki.boxer@monthaus.com', 'role' => 'super_admin'],
        ],
        'db' => [
            'migrated' => true,
            'users' => [
                ['id' => 101, 'first_name' => 'Jackson', 'last_name' => 'Horn', 'email' => 'jackson.horn@monthaus.com', 'role' => 'agent', 'is_active' => 1, 'intake_id' => 6],
                ['id' => 102, 'first_name' => 'Jonathan', 'last_name' => 'Boxer', 'email' => 'jonathan.boxer@monthaus.com', 'role' => 'agent', 'is_active' => 1, 'intake_id' => 8],
                ['id' => 103, 'first_name' => 'New', 'last_name' => 'Agent', 'email' => 'new.agent@monthaus.com', 'role' => 'agent', 'is_active' => 1, 'intake_id' => null],
                ['id' => 1,   'first_name' => 'Nikki', 'last_name' => 'Boxer', 'email' => 'nikki.boxer@monthaus.com', 'role' => 'super_admin', 'is_active' => 1, 'intake_id' => null],
            ],
            'intakes' => [
                ['id' => 6,  'agent_name' => 'Jackson Horn', 'slug' => 'jackson-horn', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'agent'],
                ['id' => 7,  'agent_name' => 'Kimberlee Coates', 'slug' => 'kim-coates', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'agent'],
                ['id' => 8,  'agent_name' => 'Weber Boxer Group', 'slug' => '', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'team'],
                ['id' => 30, 'agent_name' => 'Jonathan Boxer', 'slug' => 'jonathan-boxer', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'agent'],
            ],
            'teams' => ['8' => [21, 23, 30]],
            'qr' => [
                ['id' => 1, 'code' => 'jhorn-yard', 'intake_id' => 6,  'label' => 'JH Yard Sign',     'dest_type' => 'profile', 'dest_url' => null, 'is_active' => 1, 'scan_count' => 4],
                ['id' => 5, 'code' => 'jhorn-oh',   'intake_id' => 6,  'label' => 'JH Open House',    'dest_type' => 'url', 'dest_url' => 'https://monthaus.com/oh', 'is_active' => 0, 'scan_count' => 0],
                ['id' => 2, 'code' => 'wb-office',  'intake_id' => 8,  'label' => 'WB Office Window', 'dest_type' => 'url', 'dest_url' => 'https://monthaus.com/weber-boxer', 'is_active' => 1, 'scan_count' => 9],
                ['id' => 3, 'code' => 'jboxer-yard','intake_id' => 30, 'label' => 'JB Yard Sign',     'dest_type' => 'profile', 'dest_url' => null, 'is_active' => 1, 'scan_count' => 1],
                ['id' => 4, 'code' => 'kcoates-oh', 'intake_id' => 7,  'label' => 'KC Secret Sign',   'dest_type' => 'url', 'dest_url' => 'https://monthaus.com/kc', 'is_active' => 1, 'scan_count' => 2],
            ],
        ],
    ], $over);
}

// ── Server ───────────────────────────────────────────────────────────────────
$PORT = 8931 + (getmypid() % 200);
$log  = $SB . '/server.log';
file_put_contents($SB . '/scenario.json', json_encode(scenario()));
$pid = (int)trim(shell_exec(sprintf('php -n -d session.save_path=%s -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    escapeshellarg($SB), $PORT, escapeshellarg($SB), escapeshellarg($log))));
register_shutdown_function(function () use ($pid, $SB) { if ($pid > 0) @exec("kill {$pid} 2>/dev/null"); @exec('rm -rf ' . escapeshellarg($SB)); });
for ($i = 0, $up = false; $i < 60 && !$up; $i++) { $fp = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($fp) { fclose($fp); $up = true; } else usleep(120000); }
if (!$up) { fwrite(STDERR, "FATAL: server did not start\n" . @file_get_contents($log)); exit(1); }

/** Request as $who; returns [status, location, body]. */
function req(string $who, string $path, ?array $post = null, array $over = []): array {
    global $PORT, $SB;
    file_put_contents($SB . '/scenario.json', json_encode(scenario(['as' => $who] + $over)));
    @unlink($SB . '/writes.log');
    usleep(30000);   // let the file settle before the server reads it
    $ch = curl_init("http://127.0.0.1:{$PORT}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $hdr = substr($raw, 0, $hlen); $body = substr($raw, $hlen);
    $loc = preg_match('/^Location:\s*(.+)$/mi', $hdr, $m) ? trim($m[1]) : '';
    return [$code, $loc, $body];
}
function writes(): array {
    global $SB;
    return is_file($SB . '/writes.log') ? array_map(fn($l) => json_decode($l, true), file($SB . '/writes.log', FILE_IGNORE_NEW_LINES)) : [];
}
function no_fatal(string $body): bool { return !has($body, 'Fatal error') && !has($body, 'Uncaught') && !has($body, 'Warning:'); }

echo "render_portal.php\n\nWHO SEES WHAT\n";
[$c, , $b] = req('jackson', '/portal/qr.php');
ok('Jackson: page renders', $c === 200 && no_fatal($b), $b);
ok('Jackson sees his code', has($b, 'jhorn-yard') && has($b, 'JH Yard Sign'), $b);
ok('Jackson sees NOTHING of Kim\'s', !has($b, 'kcoates') && !has($b, 'KC Secret'));
ok('Jackson sees NOTHING of Weber Boxer\'s', !has($b, 'wb-office') && !has($b, 'jboxer-yard') && !has($b, 'Weber'));
ok('his paused code says paused and has no change button', has($b, 'JH Open House') && has($b, 'Paused')
   && substr_count($b, 'Change where it goes') === 1);
ok('plain words for a profile destination', has($b, 'Your profile page on monthaus.com'));

[$c, , $b] = req('jackson', '/portal/');
ok('Jackson home renders and counts only his codes', $c === 200 && no_fatal($b) && has($b, 'You have 2 QR codes'), $b);

[$c, , $b] = req('jon', '/portal/qr.php');
ok('Weber Boxer: team code and member code shown', has($b, 'wb-office') && has($b, 'jboxer-yard'), $b);
ok('Weber Boxer: member code names its owner', has($b, 'Jonathan Boxer'));
ok('Weber Boxer sees NOTHING of Jackson\'s or Kim\'s', !has($b, 'jhorn') && !has($b, 'kcoates'));
[, , $b] = req('jon', '/portal/');
ok('team home greets the team', has($b, 'Hello, Weber Boxer Group') && has($b, 'You have 2 QR codes'), $b);

echo "\nSOMEONE ELSE'S CODE\n";
[$c, , $b] = req('jackson', '/portal/qr.php?edit=4');
ok('?edit= with Kim\'s code id shows no form and none of her data', !has($b, 'Where should') && !has($b, 'KC Secret'), $b);
[$c, $loc] = req('jackson', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 4, 'dest_type' => 'url', 'dest_url' => 'https://monthaus.com/hijack']);
ok('POST for Kim\'s code writes nothing', writes() === [], json_encode(writes()));
ok('…and redirects back without an edit form', $c === 302 && !has($loc, 'edit='), "{$c} {$loc}");
[$c, $loc] = req('jon', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 1, 'dest_type' => 'profile']);
ok('Weber Boxer cannot change Jackson\'s code', writes() === [], json_encode(writes()));

echo "\nCHANGING A DESTINATION\n";
[$c, $loc] = req('jackson', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 1, 'dest_type' => 'url', 'dest_url' => 'https://monthaus.com/listing.php?k=1']);
$w = writes();
ok('a monthaus.com page is saved', ($w[0][0] ?? '') === 'update_qr' && ($w[0][1] ?? []) === ['url', 'https://monthaus.com/listing.php?k=1', 1], json_encode($w));
ok('…and logged with his user id', ($w[1][0] ?? '') === 'log_change' && (int)($w[1][1][4] ?? 0) === 101, json_encode($w));
ok('…then back to the list', $c === 302 && $loc === '/portal/qr.php', "{$c} {$loc}");
req('jackson', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 1, 'dest_type' => 'url', 'dest_url' => 'monthaus.com/no-scheme']);
ok('an address without https:// gets it added', (writes()[0][1][1] ?? '') === 'https://monthaus.com/no-scheme', json_encode(writes()));
[$c, $loc] = req('jackson', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 1, 'dest_type' => 'url', 'dest_url' => 'https://evil.example.com/']);
ok('a page off monthaus.com is refused', writes() === [] && has($loc, 'edit=1'), json_encode(writes()) . " {$loc}");
req('jackson', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 5, 'dest_type' => 'url', 'dest_url' => 'https://monthaus.com/x']);
ok('a paused code cannot be changed', writes() === [], json_encode(writes()));
req('jon', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 2, 'dest_type' => 'profile']);
ok('a team code cannot point at a profile it does not have', writes() === [], json_encode(writes()));
req('jon', '/portal/qr.php', ['csrf_token' => 'tok', 'id' => 3, 'dest_type' => 'url', 'dest_url' => 'https://monthaus.com/jb']);
ok('a team member\'s code can be changed from the team account', (writes()[0][1][2] ?? 0) === 3, json_encode(writes()));
req('jackson', '/portal/qr.php', ['csrf_token' => 'nope', 'id' => 1, 'dest_type' => 'profile']);
ok('a bad form token writes nothing', writes() === [], json_encode(writes()));
[, , $b] = req('jackson', '/portal/qr.php?edit=1');
ok('the edit page offers My profile page and a monthaus.com page', has($b, 'My profile page') && has($b, 'A page on monthaus.com'), $b);
[, , $b] = req('jon', '/portal/qr.php?edit=2');
ok('a team code\'s edit page offers no profile choice', !has($b, 'profile page</b>') && has($b, 'A page on monthaus.com'), $b);

echo "\nPREVIEW AND REFUSALS\n";
[, , $b] = req('jackson', '/portal/qr.php?preview=8');
ok('an agent\'s ?preview= is ignored', has($b, 'jhorn-yard') && !has($b, 'wb-office') && !has($b, 'Preview:'), $b);
[, , $b] = req('nikki', '/portal/qr.php?preview=6');
ok('an admin previews Jackson with a banner', has($b, 'Preview:') && has($b, 'jhorn-yard') && !has($b, 'kcoates'), $b);
ok('…and links keep the preview', has($b, 'preview=6'));
[$c, , $b] = req('nikki', '/portal/');
ok('an admin without a preview gets a 403 that explains', $c === 403 && has($b, 'preview'), "{$c}");
[$c, , $b] = req('nolink', '/portal/');
ok('an unlinked agent gets a friendly 403', $c === 403 && has($b, 'not connected to your marketing'), "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/', null, ['db' => ['migrated' => false]]);
ok('before the migration: a 403 naming it', $c === 403 && has($b, 'portal_identity_v1.sql'), "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/', null, ['db' => ['intakes' => [0 => ['is_active' => 0]]]]);
ok('an archived account is refused', $c === 403 && has($b, 'no longer active'), "{$c}");

// Optional: save rendered pages for a visual check
// (PORTAL_SNAPSHOTS=/some/dir php tests/render_portal.php).
if ($snap = getenv('PORTAL_SNAPSHOTS')) {
    @mkdir($snap, 0777, true);
    foreach (['home-jackson' => ['jackson', '/portal/'], 'qr-jackson' => ['jackson', '/portal/qr.php'],
              'qr-edit-jackson' => ['jackson', '/portal/qr.php?edit=1'], 'home-wb' => ['jon', '/portal/'],
              'qr-wb' => ['jon', '/portal/qr.php'], 'qr-preview' => ['nikki', '/portal/qr.php?preview=6'],
              'refused-nolink' => ['nolink', '/portal/']] as $name => [$who, $path]) {
        file_put_contents("{$snap}/{$name}.html", req($who, $path)[2]);
    }
    echo "  (snapshots saved to {$snap})\n";
}

$log_txt = (string)@file_get_contents($log);
ok('no PHP warnings or errors in the server log', !preg_match('/(Warning|Fatal|Deprecated|Notice):/', $log_txt), $log_txt);

echo "\n" . ($fail ? "FAIL — {$fail} failed, {$pass} passed" : "PASS — {$pass} assertions") . "\n";
exit($fail ? 1 : 0);
