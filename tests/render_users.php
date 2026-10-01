<?php
/**
 * render_users.php — exercise users.php against a stub database, both as a
 * rendered page and as a live POST endpoint.
 *
 *   php tests/render_users.php
 *
 * There is no arithmetic on this page, so unlike the billing tests there is
 * nothing to prove in isolation. What there IS is a set of guards that only
 * matter on the day somebody trips them — the last super admin, your own role,
 * a role the enum cannot store — and every one of them fails silently or
 * catastrophically if it stops working. So this harness does not stop at the
 * markup: it starts a real PHP server over the sandbox and POSTs, so the
 * redirect each guard produces is the thing being asserted.
 *
 * ── THE STUB IS STRICT ───────────────────────────────────────────────────────
 *
 * Same rules as render_order_split.php, for the same reason: a stub more
 * forgiving than the real thing turns a crash into a pass.
 *
 *   · mysqli::close() sets a flag every query()/prepare() checks and throws
 *     after it, exactly as real mysqli does. users.php closes the connection
 *     before it renders, so anything added below that line fails here.
 *   · an unfixtured SQL statement THROWS rather than returning no rows.
 *   · bind_param() throws when the type string and the argument count disagree.
 *   · every column comes back as a STRING, the way a driver hands them over.
 *
 * It runs under `php -n` so ext/mysqli is absent and the stub class is the only
 * mysqli that exists.
 */

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;

function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$what}\n"; }
    else { $fail++; echo "  FAIL {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}
function has(string $hay, string $needle): bool { return strpos($hay, $needle) !== false; }

// ── Sandbox ──────────────────────────────────────────────────────────────────
$SB = sys_get_temp_dir() . '/us_render_' . bin2hex(random_bytes(4));
mkdir($SB . '/inc', 0777, true);

foreach (['inc/schema.php', 'inc/_nav.php', 'users.php'] as $f) {
    $src = $ROOT . '/' . $f;
    if (!is_file($src)) { fwrite(STDERR, "FATAL: missing {$src}\n"); exit(1); }
    copy($src, $SB . '/' . $f);
}

/* Scenario knobs are read from a file the harness rewrites between runs, so the
   sandbox itself is built once. */
$SCEN = $SB . '/scenario.json';

file_put_contents($SB . '/inc/auth.php', <<<'PHP'
<?php
$scen = json_decode(file_get_contents(__DIR__ . '/../scenario.json'), true);
if ($scen['allowlist'] !== null) { define('ACCESS_ALLOWLIST', $scen['allowlist']); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function require_login(): void {}
function require_role(string $r): void {}
function is_super_admin(): bool { return true; }
function current_user(): array {
    $s = json_decode(file_get_contents(__DIR__ . '/../scenario.json'), true);
    return ['id' => $s['me_id'], 'first_name' => 'Nikki', 'last_name' => 'Boxer',
            'email' => 'nikki.boxer@monthaus.com', 'role' => 'super_admin'];
}
PHP);

file_put_contents($SB . '/inc/db.php', <<<'PHP'
<?php
class mh_result {
    private array $rows; private int $i = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc() { return $this->rows[$this->i++] ?? null; }
    public function fetch_row()   { $r = $this->rows[$this->i++] ?? null; return $r ? array_values($r) : null; }
}
class mh_stmt {
    private array $rows; private $owner;
    public function __construct(array $rows, $owner) { $this->rows = $rows; $this->owner = $owner; }
    public function bind_param(string $types, &...$vars): bool {
        if (strlen($types) !== count($vars)) {
            throw new RuntimeException("bind_param: {$types} is " . strlen($types)
                . " types for " . count($vars) . " arguments");
        }
        return true;
    }
    public function execute(): bool {
        if ($this->owner->closed) throw new Error('mysqli object is already closed');
        return true;
    }
    public function get_result() { return new mh_result($this->rows); }
    public function close(): bool { return true; }
}
class mysqli {
    public bool $closed = false;
    public int $insert_id = 99;
    public static array $fixtures = [];
    private function guard(): void {
        if ($this->closed) throw new Error('mysqli object is already closed');
    }
    private function match(string $sql): array {
        foreach (self::$fixtures as $needle => $rows) {
            if (stripos($sql, $needle) !== false) return $rows;
        }
        throw new RuntimeException("stub mysqli: no fixture matches SQL:\n" . trim($sql));
    }
    public function query(string $sql) { $this->guard(); return new mh_result($this->match($sql)); }
    public function prepare(string $sql) { $this->guard(); return new mh_stmt($this->match($sql), $this); }
    public function close(): bool { $this->closed = true; return true; }
}

/* Every value a STRING, as a real driver returns them. */
function mh_str(array $rows): array {
    return array_map(fn($r) => array_map(fn($v) => $v === null ? null : (string)$v, $r), $rows);
}

$scen = json_decode(file_get_contents(__DIR__ . '/../scenario.json'), true);

$USERS = mh_str($scen['users']);

mysqli::$fixtures = [
    // mk_role_enum_has_super_admin()
    'SELECT COLUMN_TYPE' => $scen['role_enum_has_sa']
        ? [['COLUMN_TYPE' => "enum('agent','admin','super_admin')"]]
        : [['COLUMN_TYPE' => "enum('agent','admin')"]],
    // mk_column_exists()
    'SELECT 1 FROM information_schema.COLUMNS' => $scen['has_entra'] ? [[1]] : [],
    // mk_other_active_super_admins()
    'SELECT COUNT(*)' => [['n' => (string)$scen['other_active_sa']]],
    // mk_email_taken()
    'SELECT id FROM users WHERE LOWER(email)' => $scen['email_taken'] ? [['id' => '7']] : [],
    // save_user / set_active read the current row
    'SELECT id, role, is_active, email FROM users' => $scen['target_row'] ? [$scen['target_row']] : [],
    'SELECT id, first_name, last_name, role FROM users' => $scen['target_row'] ? [$scen['target_row']] : [],
    // portal accounts for the picker (users.php, agent portal phase 1)
    'FROM marketing_intakes' => mh_str([
        ['id'=>8, 'agent_name'=>'Weber Boxer Group', 'entity_type'=>'team'],
        ['id'=>7, 'agent_name'=>'Kimberlee Coates', 'entity_type'=>'agent'],
    ]),
    // writes
    'INSERT INTO users' => [],
    'UPDATE users'      => [],
    // the page's own list
    'FROM users' => $USERS,
];
$conn = new mysqli();
PHP);

/* ── Scenario ──────────────────────────────────────────────────────────────── */
function scenario(array $over = []): array {
    return array_merge([
        'me_id'            => 1,
        'allowlist'        => 'nikki.boxer@monthaus.com,jm.drai@monthaus.com',
        'role_enum_has_sa' => true,
        'has_entra'        => true,
        'other_active_sa'  => 1,
        'email_taken'      => false,
        'target_row'       => null,
        'users'            => [
            ['id'=>1,'first_name'=>'Nikki','last_name'=>'Boxer','email'=>'nikki.boxer@monthaus.com',
             'role'=>'super_admin','is_active'=>1,'last_login'=>'2026-08-30 09:12:00',
             'created_at'=>'2026-01-04 10:00:00','has_password'=>1,
             'entra_object_id'=>'oid-aaa','sso_linked_at'=>'2026-08-22 11:00:00'],
            ['id'=>2,'first_name'=>'Jonathan','last_name'=>'Boxer','email'=>'jonathan.boxer@monthaus.com',
             'role'=>'admin','is_active'=>1,'last_login'=>'2026-08-28 14:00:00',
             'created_at'=>'2026-01-04 10:00:00','has_password'=>0,
             'entra_object_id'=>'oid-bbb','sso_linked_at'=>'2026-08-23 09:00:00'],
            ['id'=>3,'first_name'=>'Kim','last_name'=>'Coates','email'=>'kim.coates@monthaus.com',
             'role'=>'agent','is_active'=>1,'last_login'=>null,
             'created_at'=>'2026-08-30 10:00:00','has_password'=>0,
             'entra_object_id'=>null,'sso_linked_at'=>null],
            ['id'=>4,'first_name'=>'Bonnie','last_name'=>'Scott','email'=>'bonnie.scott@monthaus.com',
             'role'=>'admin','is_active'=>0,'last_login'=>'2026-06-02 08:00:00',
             'created_at'=>'2026-01-04 10:00:00','has_password'=>1,
             'entra_object_id'=>null,'sso_linked_at'=>null],
        ],
    ], $over);
}

// ── The server ───────────────────────────────────────────────────────────────
$PORT = 8731 + (getmypid() % 200);
$log  = $SB . '/server.log';
$cmd  = sprintf('php -n -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
                $PORT, escapeshellarg($SB), escapeshellarg($log));
$pid = (int) trim(shell_exec($cmd));
register_shutdown_function(function () use ($pid) { if ($pid > 0) @exec("kill {$pid} 2>/dev/null"); });

/* Wait for it, with the scenario already in place — the server refuses to
   answer before the file exists. */
file_put_contents($SCEN, json_encode(scenario()));
$up = false;
for ($i = 0; $i < 60; $i++) {
    $fp = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2);
    if ($fp) { fclose($fp); $up = true; break; }
    usleep(120000);
}
if (!$up) { fwrite(STDERR, "FATAL: server did not start\n" . @file_get_contents($log)); exit(1); }

function req(int $port, string $path, ?array $post = null, string $jar = ''): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_PROXY          => '',
    ]);
    if ($jar !== '') { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw  = curl_exec($ch);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $head = substr($raw, 0, $hlen);
    $body = substr($raw, $hlen);
    $loc  = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $head, $m)) $loc = trim($m[1]);
    return ['code' => $code, 'head' => $head, 'body' => $body, 'loc' => $loc];
}

function render(int $port, string $SCEN, array $over = [], string $qs = ''): string {
    file_put_contents($SCEN, json_encode(scenario($over)));
    $r = req($port, '/users.php' . $qs);
    return $r['body'];
}

echo "\nrender_users.php\n";

// ── 1. It renders at all, and the list reaches the markup ────────────────────
echo "\nTHE PAGE\n";
$html = render($PORT, $SCEN);

ok('renders without a fatal', has($html, '</html>') && !has($html, 'Fatal error'),
   substr(strip_tags($html), 0, 300));
ok('no mysqli-after-close', !has($html, 'already closed'));
ok('every account is listed', has($html, '4 accounts'));
foreach (['Nikki Boxer', 'Jonathan Boxer', 'Kim Coates', 'Bonnie Scott'] as $n) {
    ok("lists {$n}", has($html, $n));
}
ok('roles are chipped, not printed raw',
   has($html, 'chip role-super_admin') && has($html, 'chip role-admin') && has($html, 'chip role-agent'));
ok('an inactive account is marked inactive', has($html, '>Inactive<'));
ok('an unlinked account says so', has($html, '>Not linked<'));
ok('a linked account says Microsoft', has($html, '>Microsoft<'));

// ── 2. Your own row ──────────────────────────────────────────────────────────
echo "\nYOUR OWN ROW\n";
ok('your row is marked "you"', has($html, 'chip you'));
ok('your role select is disabled', (bool) preg_match('/<select id="r1"[^>]*disabled/', $html));
ok('…and posts its current value anyway',
   (bool) preg_match('/<input type="hidden" name="role" value="super_admin">/', $html));
ok('your Deactivate button is disabled',
   (bool) preg_match('/disabled title="You cannot deactivate your own account"/', $html));
ok('somebody else\'s role select is not disabled',
   (bool) preg_match('/<select id="r2" name="role" >/', $html));

// ── 3. The allowlist column ──────────────────────────────────────────────────
echo "\nTHE ALLOWLIST — a second gate, and it is not role\n";
ok('the limited-rollout note appears', has($html, 'limited rollout'));
ok('an allowed address reads Allowed', has($html, '>Allowed<'));
ok('an address not on the list is flagged', has($html, 'No access yet'));
ok('the Access column exists', has($html, '<th class="c-access">'));

$html_open = render($PORT, $SCEN, ['allowlist' => '']);
ok('an empty allowlist drops the note', !has($html_open, 'limited rollout'));
ok('…and the whole column with it',
   !has($html_open, '<th class="c-access">') && !has($html_open, 'No access yet'));
ok('…and the edit row\'s colspan follows the column count',
   has($html, 'colspan="5"') && has($html_open, 'colspan="4"'));

$html_none = render($PORT, $SCEN, ['allowlist' => null]);
ok('an undefined ACCESS_ALLOWLIST behaves like an empty one',
   !has($html_none, '<th class="c-access">'));

// ── 4. The two warnings ──────────────────────────────────────────────────────
echo "\nWARNINGS\n";
ok('break-glass is quiet while a super admin has a password',
   !has($html, 'break-glass password'));

$sc = scenario();
$sc['users'][0]['has_password'] = 0;              // the only active super_admin
$html_bg = render($PORT, $SCEN, ['users' => $sc['users']]);
ok('break-glass warns when no active super admin has a password',
   has($html_bg, 'No super admin has a break-glass password'));

ok('no migration banner when the enum is current',
   !has($html, 'cannot store the super admin role'));
$html_old = render($PORT, $SCEN, ['role_enum_has_sa' => false]);
ok('an enum without super_admin is called out',
   has($html_old, 'cannot store the super admin role'));
ok('…and the Add form no longer offers it',
   (bool) preg_match('/<select id="nr"(.*?)<\/select>/s', $html_old, $addsel)
   && !has($addsel[1], 'super_admin'));
ok('…nor does an ordinary user\'s role select',
   (bool) preg_match('/<select id="r3"(.*?)<\/select>/s', $html_old, $r3sel)
   && !has($r3sel[1], 'super_admin'));
ok('…while an existing super_admin still shows their role',
   has($html_old, '<option value="super_admin" selected>super_admin</option>'));

// ── 5. Deploying ahead of sso_schema.sql ─────────────────────────────────────
echo "\nTHE entra_object_id GUARD\n";
$html_noentra = render($PORT, $SCEN, ['has_entra' => false]);
ok('the page still lists everyone without the SSO columns',
   has($html_noentra, '4 accounts') && has($html_noentra, 'Kim Coates'),
   'an unguarded query would render an empty list with no error');
ok('…and reports nobody as linked', !has($html_noentra, '>Microsoft<'));

// ── 6. The table sizes with min-width, never width ───────────────────────────
echo "\nTHE TABLE\n";
preg_match('/<thead>(.*?)<\/thead>/s', $html, $m);
ok('no bare width= on any <th>', !preg_match('/<th[^>]*\swidth=/i', $m[1] ?? ''));
$src = file_get_contents(dirname(__DIR__) . '/users.php');
preg_match_all('/th\.c-[a-z]+\s*\{\s*min-width:(\d+)px/', $src, $fm);
$floors = array_sum(array_map('intval', $fm[1]));
preg_match('/table\.us-list\s*\{[^}]*min-width:(\d+)px/', $src, $tm);
$tablefloor = (int)($tm[1] ?? 0);
ok('every column has a min-width floor', count($fm[1]) >= 4, count($fm[1]) . ' found');
ok('the table floor covers the sum of them',
   $tablefloor >= $floors, "table {$tablefloor}px vs columns {$floors}px");

// ── 7. THE GUARDS ────────────────────────────────────────────────────────────
//
// The whole reason this harness runs a server. Each of these is a real POST and
// the assertion is on the redirect the page actually produced.
echo "\nTHE GUARDS\n";

$JAR = $SB . '/cookies.txt';
@unlink($JAR);
file_put_contents($SCEN, json_encode(scenario()));
$seed = req($PORT, '/users.php', null, $JAR);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $seed['body'], $cm);
$TOKEN = $cm[1] ?? '';
ok('the form carries a CSRF token', $TOKEN !== '');

function post(int $port, string $SCEN, array $fields, array $over, string $jar): string {
    file_put_contents($SCEN, json_encode(scenario($over)));
    $r = req($port, '/users.php', $fields, $jar);
    return urldecode($r['loc']);
}

// CSRF
$loc = post($PORT, $SCEN, ['_action'=>'add_user','csrf_token'=>'wrong','first_name'=>'A',
    'last_name'=>'B','email'=>'a@b.com','role'=>'agent'], [], $JAR);
ok('a bad CSRF token changes nothing', has($loc, 'k=err') && has($loc, 'had expired'), $loc);

// Add — the happy path
$loc = post($PORT, $SCEN, ['_action'=>'add_user','csrf_token'=>$TOKEN,'first_name'=>'Jean',
    'last_name'=>'Mitchell','email'=>'jean.mitchell@monthaus.com','role'=>'agent'], [], $JAR);
ok('a valid account is created', has($loc, 'k=ok') && has($loc, 'Jean Mitchell added'), $loc);
ok('…and the message says there is no password to send',
   has($loc, 'no password to send'), $loc);

// Add — validation
$loc = post($PORT, $SCEN, ['_action'=>'add_user','csrf_token'=>$TOKEN,'first_name'=>'Jean',
    'last_name'=>'Mitchell','email'=>'not-an-email','role'=>'agent'], [], $JAR);
ok('a malformed email is refused', has($loc, 'k=err') && has($loc, 'valid email'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'add_user','csrf_token'=>$TOKEN,'first_name'=>'',
    'last_name'=>'Mitchell','email'=>'jean@monthaus.com','role'=>'agent'], [], $JAR);
ok('a missing name is refused', has($loc, 'k=err') && has($loc, 'both required'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'add_user','csrf_token'=>$TOKEN,'first_name'=>'Jean',
    'last_name'=>'Mitchell','email'=>'jean@monthaus.com','role'=>'agent'], ['email_taken'=>true], $JAR);
ok('a duplicate email is refused', has($loc, 'k=err') && has($loc, 'already an account'), $loc);

// ── The role is never taken from the post ────────────────────────────────────
// An enum cannot store an unknown value: outside strict mode MySQL writes '',
// $hierarchy[''] misses, and that account is below every threshold — locked out
// of the whole app with nothing in any log. Whitelist or nothing.
$loc = post($PORT, $SCEN, ['_action'=>'add_user','csrf_token'=>$TOKEN,'first_name'=>'Jean',
    'last_name'=>'Mitchell','email'=>'jean@monthaus.com','role'=>'root'], [], $JAR);
ok('an invented role is refused, not written', has($loc, 'k=err') && has($loc, 'Unknown role'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'add_user','csrf_token'=>$TOKEN,'first_name'=>'Jean',
    'last_name'=>'Mitchell','email'=>'jean@monthaus.com','role'=>'super_admin'],
    ['role_enum_has_sa'=>false], $JAR);
ok('super_admin is refused while the enum cannot store it',
   has($loc, 'k=err') && has($loc, 'Unknown role'), $loc);

// ── You cannot change your own role ──────────────────────────────────────────
$loc = post($PORT, $SCEN, ['_action'=>'save_user','csrf_token'=>$TOKEN,'id'=>1,
    'first_name'=>'Nikki','last_name'=>'Boxer','email'=>'nikki.boxer@monthaus.com','role'=>'admin'],
    ['me_id'=>1,'target_row'=>['id'=>'1','role'=>'super_admin','is_active'=>'1','email'=>'nikki.boxer@monthaus.com']], $JAR);
ok('you cannot demote yourself', has($loc, 'k=err') && has($loc, 'your own role'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'save_user','csrf_token'=>$TOKEN,'id'=>1,
    'first_name'=>'Nikki','last_name'=>'Boxer-Duck','email'=>'nikki.boxer@monthaus.com','role'=>'super_admin'],
    ['me_id'=>1,'target_row'=>['id'=>'1','role'=>'super_admin','is_active'=>'1','email'=>'nikki.boxer@monthaus.com']], $JAR);
ok('…but you can rename yourself', has($loc, 'k=ok') && has($loc, 'Saved Nikki'), $loc);

// ── The last active super admin ──────────────────────────────────────────────
$loc = post($PORT, $SCEN, ['_action'=>'save_user','csrf_token'=>$TOKEN,'id'=>2,
    'first_name'=>'Jonathan','last_name'=>'Boxer','email'=>'jonathan.boxer@monthaus.com','role'=>'admin'],
    ['me_id'=>1,'other_active_sa'=>0,
     'target_row'=>['id'=>'2','role'=>'super_admin','is_active'=>'1','email'=>'jonathan.boxer@monthaus.com']], $JAR);
ok('the last active super admin cannot be demoted',
   has($loc, 'k=err') && has($loc, 'only active super admin'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'save_user','csrf_token'=>$TOKEN,'id'=>2,
    'first_name'=>'Jonathan','last_name'=>'Boxer','email'=>'jonathan.boxer@monthaus.com','role'=>'admin'],
    ['me_id'=>1,'other_active_sa'=>1,
     'target_row'=>['id'=>'2','role'=>'super_admin','is_active'=>'1','email'=>'jonathan.boxer@monthaus.com']], $JAR);
ok('…but one of two can be', has($loc, 'k=ok'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'set_active','csrf_token'=>$TOKEN,'id'=>2,'on'=>'0'],
    ['me_id'=>1,'other_active_sa'=>0,
     'target_row'=>['id'=>'2','first_name'=>'Jonathan','last_name'=>'Boxer','role'=>'super_admin','is_active'=>'1','email'=>'x@y.com']], $JAR);
ok('the last active super admin cannot be deactivated either',
   has($loc, 'k=err') && has($loc, 'only active super admin'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'set_active','csrf_token'=>$TOKEN,'id'=>1,'on'=>'0'],
    ['me_id'=>1,'target_row'=>['id'=>'1','first_name'=>'Nikki','last_name'=>'Boxer','role'=>'super_admin','is_active'=>'1','email'=>'x@y.com']], $JAR);
ok('you cannot deactivate yourself', has($loc, 'k=err') && has($loc, 'your own account'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'set_active','csrf_token'=>$TOKEN,'id'=>3,'on'=>'0'],
    ['me_id'=>1,'target_row'=>['id'=>'3','first_name'=>'Kim','last_name'=>'Coates','role'=>'agent','is_active'=>'1','email'=>'k@y.com']], $JAR);
ok('an ordinary account deactivates', has($loc, 'k=ok') && has($loc, 'deactivated'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'set_active','csrf_token'=>$TOKEN,'id'=>4,'on'=>'1'],
    ['me_id'=>1,'target_row'=>['id'=>'4','first_name'=>'Bonnie','last_name'=>'Scott','role'=>'admin','is_active'=>'0','email'=>'b@y.com']], $JAR);
ok('…and reactivates', has($loc, 'k=ok') && has($loc, 'reactivated'), $loc);

// ── Missing rows ─────────────────────────────────────────────────────────────
$loc = post($PORT, $SCEN, ['_action'=>'save_user','csrf_token'=>$TOKEN,'id'=>77,
    'first_name'=>'Ghost','last_name'=>'User','email'=>'g@y.com','role'=>'agent'],
    ['target_row'=>null], $JAR);
ok('editing a deleted account fails safely', has($loc, 'k=err') && has($loc, 'no longer exists'), $loc);

$loc = post($PORT, $SCEN, ['_action'=>'wat','csrf_token'=>$TOKEN], [], $JAR);
ok('an unknown action changes nothing', has($loc, 'k=err') && has($loc, 'Unknown action'), $loc);

// ── Post/redirect/get ────────────────────────────────────────────────────────
echo "\nPOST/REDIRECT/GET\n";
file_put_contents($SCEN, json_encode(scenario()));
$r = req($PORT, '/users.php', ['_action'=>'add_user','csrf_token'=>$TOKEN,'first_name'=>'A',
    'last_name'=>'B','email'=>'a.b@monthaus.com','role'=>'agent'], $JAR);
ok('every write ends in a redirect, so a refresh cannot repeat it',
   $r['code'] === 302 && $r['loc'] !== '', 'code ' . $r['code']);
ok('…carrying the new row so the page can scroll to it', has($r['loc'], 'u=99'), $r['loc']);

// ── Nothing runs after the connection is closed ──────────────────────────────
echo "\nCONNECTION LIFETIME\n";
ok('the page renders with the connection already closed',
   has($html, '</html>') && !has($html, 'already closed'),
   'the stub throws on any use after close(), as real mysqli does');

// ── Result ───────────────────────────────────────────────────────────────────
echo "\n";
if ($fail === 0) { echo "PASS — {$pass} assertions\n"; }
else { echo "FAIL — {$fail} failed, {$pass} passed\n"; }

@exec("kill {$pid} 2>/dev/null");
exec('rm -rf ' . escapeshellarg($SB));
exit($fail === 0 ? 0 : 1);
