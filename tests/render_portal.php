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
foreach (['portal/index.php', 'portal/qr.php', 'portal/spend.php', 'portal/receipt.php', 'portal/advertising.php', 'portal/orders.php',
          'portal/asset.php', 'inc/portal.php', 'inc/qr.php', 'inc/schema.php', 'inc/config.php', 'inc/financials.php', 'inc/creatives.php'] as $f) {
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
define('SENDGRID_API_KEY', 'test-key');
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
        // Creatives (sql/creatives_v1.sql): the portal asks for them by the campaign ids its
        // scoped list gave it; the ids asked for are logged so the test can check the list.
        if (preg_match('/FROM marketing_campaign_assets WHERE campaign_id IN \(([0-9, ]+)\)/', $sql, $m)) {
            $in = array_map('intval', explode(',', $m[1]));
            $this->write('assets_in', $in);
            return self::s(array_values(array_filter($D['assets'], fn($a) => in_array((int)$a['campaign_id'], $in, true))));
        }
        if (preg_match('/FROM marketing_campaign_assets a\s+JOIN marketing_campaigns c ON c\.id = a\.campaign_id\s+WHERE a\.id = \? AND c\.intake_id = \?/', $sql)) {
            $camps = array_column($D['campaigns'], null, 'id');
            return self::s(array_values(array_filter($D['assets'], fn($a) => (int)$a['id'] === (int)$p[0]
                && (int)($camps[$a['campaign_id']]['intake_id'] ?? 0) === (int)$p[1])));
        }
        if (preg_match('/FROM marketing_collateral_orders WHERE id = \? AND intake_id = \?/', $sql)) {
            return self::s(array_values(array_filter($D['orders'], fn($o) => (int)$o['id'] === (int)$p[0] && (int)$o['intake_id'] === (int)$p[1])));
        }
        if (preg_match('/FROM marketing_collateral_orders\s+WHERE intake_id = (\d+)/', $sql, $m)) {
            return self::s(array_values(array_filter($D['orders'], fn($o) => (int)$o['intake_id'] === (int)$m[1])));
        }
        if (preg_match('/FROM marketing_campaign_months m/', $sql)) {
            if (!preg_match('/c\.intake_id = (\d+)/', $sql)) throw new RuntimeException("UNSCOPED campaign months:\n{$sql}");
            return [];
        }
        if (preg_match('/FROM marketing_campaigns\s+WHERE intake_id = (\d+)/', $sql, $m)) {
            return self::s(array_values(array_filter($D['campaigns'], fn($c) => (int)$c['intake_id'] === (int)$m[1])));
        }
        if (preg_match('/INSERT INTO marketing_requests/', $sql)) { $this->insert_id = 501; $this->write('request', $p); return []; }
        if (preg_match('/UPDATE marketing_requests SET email_status/', $sql)) { $this->write('request_email', $p); return []; }
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
            'sierrah' => ['id' => 105, 'email' => 'sierrah.smith@monthaus.com', 'role' => 'agent'],
        ],
        'db' => [
            'migrated' => true,
            'users' => [
                ['id' => 101, 'first_name' => 'Jackson', 'last_name' => 'Horn', 'email' => 'jackson.horn@monthaus.com', 'role' => 'agent', 'is_active' => 1, 'intake_id' => 6],
                ['id' => 102, 'first_name' => 'Jonathan', 'last_name' => 'Boxer', 'email' => 'jonathan.boxer@monthaus.com', 'role' => 'agent', 'is_active' => 1, 'intake_id' => 8],
                ['id' => 103, 'first_name' => 'New', 'last_name' => 'Agent', 'email' => 'new.agent@monthaus.com', 'role' => 'agent', 'is_active' => 1, 'intake_id' => null],
                ['id' => 105, 'first_name' => 'Sierrah', 'last_name' => 'Smith', 'email' => 'sierrah.smith@monthaus.com', 'role' => 'agent', 'is_active' => 1, 'intake_id' => 40],
                ['id' => 1,   'first_name' => 'Nikki', 'last_name' => 'Boxer', 'email' => 'nikki.boxer@monthaus.com', 'role' => 'super_admin', 'is_active' => 1, 'intake_id' => null],
            ],
            'intakes' => [
                ['id' => 6,  'agent_name' => 'Jackson Horn', 'slug' => 'jackson-horn', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'agent'],
                ['id' => 7,  'agent_name' => 'Kimberlee Coates', 'slug' => 'kim-coates', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'agent'],
                ['id' => 8,  'agent_name' => 'Weber Boxer Group', 'slug' => '', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'team'],
                ['id' => 40, 'agent_name' => 'Sierrah Smith', 'slug' => 'sierrah-smith', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'agent'],
                ['id' => 30, 'agent_name' => 'Jonathan Boxer', 'slug' => 'jonathan-boxer', 'is_active' => 1, 'status' => 'active', 'headshot_url' => '', 'headshot_face_url' => '', 'entity_type' => 'agent'],
            ],
            'teams' => ['8' => [21, 23, 30]],
            'orders' => [
                ['id' => 11, 'intake_id' => 6, 'type' => 'business_cards', 'label' => 'JH cards', 'vendor' => 'Oakley', 'cost' => 250, 'ordered_at' => '2026-09-02', 'created_at' => '2026-09-02 10:00:00', 'billed_with_order_id' => null, 'receipt_file' => 'JHRECEIPT.pdf', 'receipt_orig_name' => 'jh-cards.pdf', 'paid_by' => 'broker', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
                 'status' => 'shipped', 'qty' => '500', 'tracking_number' => '1Z999JH', 'tracking_url' => 'https://www.ups.com/track?t=1Z999JH', 'delivered_at' => null, 'file_url' => 'https://www.dropbox.com/jh-cards-final',
                 'proof_file' => 'JHPROOF.png', 'proof_orig_name' => 'jh-cards-proof.png', 'proof_thumb' => 'JHPROOF_thumb.jpg', 'proof_uploaded_at' => '2026-09-01 10:00:00'],
                ['id' => 12, 'intake_id' => 6, 'type' => 'other', 'label' => 'JH flyers', 'vendor' => 'Local', 'cost' => 99.5, 'ordered_at' => '2026-09-10', 'created_at' => '2026-09-10 10:00:00', 'billed_with_order_id' => null, 'receipt_file' => null, 'receipt_orig_name' => null, 'paid_by' => '', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
                 'status' => 'delivered', 'qty' => null, 'tracking_number' => null, 'tracking_url' => null, 'delivered_at' => '2026-09-20', 'file_url' => null,
                 'proof_file' => null, 'proof_orig_name' => null, 'proof_thumb' => null, 'proof_uploaded_at' => null],
                ['id' => 13, 'intake_id' => 7, 'type' => 'postcards', 'label' => 'KC postcards', 'vendor' => 'Oakley', 'cost' => 7777, 'ordered_at' => '2026-09-05', 'created_at' => '2026-09-05 10:00:00', 'billed_with_order_id' => null, 'receipt_file' => 'KCRECEIPT.pdf', 'receipt_orig_name' => 'kc.pdf', 'paid_by' => 'broker', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
                 'status' => 'in_production', 'qty' => '250', 'tracking_number' => 'KCTRACK', 'tracking_url' => null, 'delivered_at' => null, 'file_url' => null,
                 'proof_file' => 'KCPROOF.png', 'proof_orig_name' => 'kc-secret-proof.png', 'proof_thumb' => 'KCPROOF_thumb.jpg', 'proof_uploaded_at' => '2026-09-01 10:00:00'],
                ['id' => 14, 'intake_id' => 8, 'type' => 'yard_signs', 'label' => 'WB signs', 'vendor' => 'Oakley', 'cost' => 4321, 'ordered_at' => '2026-08-20', 'created_at' => '2026-08-20 10:00:00', 'billed_with_order_id' => null, 'receipt_file' => null, 'receipt_orig_name' => null, 'paid_by' => 'split', 'paid_broker_amount' => 3000, 'paid_mh_amount' => 1321,
                 'status' => 'ordered', 'qty' => '6', 'tracking_number' => null, 'tracking_url' => null, 'delivered_at' => null, 'file_url' => null,
                 'proof_file' => null, 'proof_orig_name' => null, 'proof_thumb' => null, 'proof_uploaded_at' => null],
            ],
            'campaigns' => [
                ['id' => 21, 'intake_id' => 6, 'platform' => 'aspen_times', 'name' => 'JH Ad', 'budget' => 1234, 'billing_mode' => 'one_time', 'unit_rate' => null, 'unit_weekday' => null, 'unit_label' => null, 'start_date' => '2026-03-05', 'end_date' => null, 'created_at' => '2026-03-01 10:00:00', 'paid_by' => 'mont_haus', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
                 'status' => 'active', 'medium' => 'print', 'ad_size' => 'Half page', 'split_group' => null, 'notes' => 'JH SECRET NOTE', 'sent' => 1],
                ['id' => 22, 'intake_id' => 7, 'platform' => 'vail_daily', 'name' => 'KC Ad', 'budget' => 8888, 'billing_mode' => 'one_time', 'unit_rate' => null, 'unit_weekday' => null, 'unit_label' => null, 'start_date' => '2026-03-05', 'end_date' => null, 'created_at' => '2026-03-01 10:00:00', 'paid_by' => 'broker', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
                 'status' => 'active', 'medium' => 'digital', 'ad_size' => null, 'split_group' => null, 'notes' => 'KC SECRET NOTE', 'sent' => 1],
                ['id' => 23, 'intake_id' => 8, 'platform' => 'vail_daily', 'name' => 'WB Ad', 'budget' => null, 'billing_mode' => 'one_time', 'unit_rate' => null, 'unit_weekday' => null, 'unit_label' => null, 'start_date' => null, 'end_date' => null, 'created_at' => '2026-09-01 10:00:00', 'paid_by' => null, 'paid_broker_amount' => null, 'paid_mh_amount' => null,
                 'status' => 'planned', 'medium' => 'digital', 'ad_size' => null, 'split_group' => null, 'notes' => '', 'sent' => 0],
            ],
            // Creatives on the placements (sql/creatives_v1.sql): an uploaded image with a
            // thumbnail, a PDF without one, and Kim's, which must never show for anyone else.
            'assets' => [
                ['id' => 31, 'campaign_id' => 21, 'label' => 'Half page', 'file_url' => 'https://www.dropbox.com/jh-ad-original', 'target_url' => null, 'file_type' => 'file', 'uploaded_at' => '2026-03-01 10:00:00',
                 'image_file' => 'JHAD.jpg', 'image_orig_name' => 'jh-ad.jpg', 'image_thumb' => 'JHAD_thumb.jpg', 'image_uploaded_at' => '2026-03-01 10:00:00'],
                ['id' => 33, 'campaign_id' => 21, 'label' => '', 'file_url' => '', 'target_url' => null, 'file_type' => 'file', 'uploaded_at' => '2026-03-02 10:00:00',
                 'image_file' => 'JHAD2.pdf', 'image_orig_name' => 'jh-ad-2.pdf', 'image_thumb' => null, 'image_uploaded_at' => '2026-03-02 10:00:00'],
                ['id' => 32, 'campaign_id' => 22, 'label' => 'KC Secret Creative', 'file_url' => 'https://www.dropbox.com/kc-ad-original', 'target_url' => null, 'file_type' => 'file', 'uploaded_at' => '2026-03-01 10:00:00',
                 'image_file' => 'KCAD.jpg', 'image_orig_name' => 'kc-ad.jpg', 'image_thumb' => 'KCAD_thumb.jpg', 'image_uploaded_at' => '2026-03-01 10:00:00'],
            ],
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
// A stand-in for SendGrid: records each payload, answers as the scenario says.
file_put_contents($SB . '/fake_sendgrid.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/mail.log', file_get_contents('php://input') . "\n", FILE_APPEND);
$scen = json_decode(file_get_contents(__DIR__ . '/scenario.json'), true);
http_response_code(!empty($scen['mail_fails']) ? 500 : 202);
PHP);
$log  = $SB . '/server.log';
file_put_contents($SB . '/scenario.json', json_encode(scenario()));
$pid = (int)trim(shell_exec(sprintf('PHP_CLI_SERVER_WORKERS=4 MH_SENDGRID_URL=%s php -n -d extension=curl -d extension=mbstring -d session.save_path=%s -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    escapeshellarg("http://127.0.0.1:{$PORT}/fake_sendgrid.php"), escapeshellarg($SB), $PORT, escapeshellarg($SB), escapeshellarg($log))));
register_shutdown_function(function () use ($pid, $SB) { if ($pid > 0) @exec("kill {$pid} 2>/dev/null"); @exec('rm -rf ' . escapeshellarg($SB)); });
for ($i = 0, $up = false; $i < 60 && !$up; $i++) { $fp = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($fp) { fclose($fp); $up = true; } else usleep(120000); }
if (!$up) { fwrite(STDERR, "FATAL: server did not start\n" . @file_get_contents($log)); exit(1); }

/** Request as $who; returns [status, location, body]. */
function req(string $who, string $path, ?array $post = null, array $over = []): array {
    global $PORT, $SB;
    file_put_contents($SB . '/scenario.json', json_encode(scenario(['as' => $who] + $over)));
    @unlink($SB . '/writes.log');
    @unlink($SB . '/mail.log');
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
function mails(): array {
    global $SB;
    return is_file($SB . '/mail.log') ? array_map(fn($l) => json_decode($l, true), file($SB . '/mail.log', FILE_IGNORE_NEW_LINES)) : [];
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

echo "\nSPEND\n";
[$c, , $b] = req('jackson', '/portal/spend.php');
ok('Jackson: spend renders', $c === 200 && no_fatal($b), $b);
ok('his figures: $250 he paid, $1,234 Mont Haus paid', has($b, '$250') && has($b, '$1,234'), $b);
ok('his undecided $99.50 shows as Being finalised', has($b, 'Being finalised') && has($b, '$99.50'), $b);
ok('NOTHING of Kim\'s or Weber Boxer\'s spend', !has($b, '7,777') && !has($b, '8,888') && !has($b, '4,321') && !has($b, '3,000'));
ok('no billing words anywhere', !preg_match('/invoic|billed|overdue|balance|unpaid/i', strip_tags($b)), $b);
[$c, , $b] = req('jackson', '/portal/spend.php?m=2026-09');
ok('a month opens with its lines in plain words', has($b, 'Business Cards: JH cards') && has($b, 'You paid') && has($b, 'JH flyers'), $b);
ok('…with a receipt link for his order only', has($b, 'receipt.php?order=11') && !has($b, 'order=13'), $b);
ok('…and no billing words', !preg_match('/invoic|billed|overdue|balance|unpaid/i', strip_tags($b)));
[$c, , $b] = req('jackson', '/portal/spend.php?m=2026-03');
ok('advertising lines name the outlet', has($b, 'Aspen Times: JH Ad') && has($b, 'Mont Haus paid'), $b);
[$c, , $b] = req('jackson', '/portal/spend.php?m=../../x');
ok('a nonsense month falls back to the overview', $c === 200 && has($b, 'By month'), "{$c}");
[, , $b] = req('jon', '/portal/spend.php');
ok('Weber Boxer sees the team\'s split: $3,000 and $1,321', has($b, '$3,000') && has($b, '$1,321') && !has($b, '$250') && !has($b, '7,777'), $b);
[, , $b] = req('jackson', '/portal/');
ok('home shows this year\'s spend in the new words', has($b, 'This year you&#039;ve spent $250 and Mont Haus $1,234, for a total of $1,484 on marketing and advertising.') || has($b, "This year you've spent \$250 and Mont Haus \$1,234, for a total of \$1,484 on marketing and advertising."), $b);
ok('…linked as See details', has($b, 'See details ›'));
ok('with codes, the QR card says Review + Edit', has($b, 'Review + Edit ›'));
ok('the footer sends questions to marketing@, with no phone and no personal email', has($b, 'marketing@monthaus.com') && !has($b, '948.4300') && !has($b, 'nikki.boxer@'));

echo "\nQR REQUESTS\n";
[, , $b] = req('sierrah', '/portal/');
ok('no codes: the card says Request and opens the form', has($b, 'Request ›') && has($b, 'qr.php?request=1'), $b);
[$c, , $b] = req('sierrah', '/portal/qr.php?request=1');
ok('the request form renders', $c === 200 && no_fatal($b) && has($b, 'QR Code Request') && has($b, 'Request a dynamic QR code for a marketing project.')
   && has($b, 'You can come back here to change the destination at any time'), $b);
[$c, $loc] = req('sierrah', '/portal/qr.php', ['csrf_token' => 'tok', 'action' => 'request', 'title' => 'Yard sign', 'details' => '', 'destination' => '']);
ok('no destination: refused, nothing saved or sent', writes() === [] && mails() === [] && has($loc, 'request=1'), json_encode(writes()) . $loc);
[$c, $loc] = req('sierrah', '/portal/qr.php', ['csrf_token' => 'tok', 'action' => 'request', 'title' => '45 Elm yard sign',
    'details' => 'Front lawn', 'destination' => 'https://site.monthaus.com/broker.php?s=sierrah-smith', 'intake_id' => 6]);
$w = writes(); $m = mails();
ok('a request is saved for HER account, whatever the form says', ($w[0][0] ?? '') === 'request' && (int)($w[0][1][0] ?? 0) === 40 && (int)($w[0][1][1] ?? 0) === 105, json_encode($w));
ok('…with the exact URL she pasted', ($w[0][1][5] ?? '') === 'https://site.monthaus.com/broker.php?s=sierrah-smith', json_encode($w));
ok('…emailed to marketing@monthaus.com', ($m[0]['personalizations'][0]['to'][0]['email'] ?? '') === 'marketing@monthaus.com', json_encode($m));
ok('…with Reply-To the agent and the project in the subject', ($m[0]['reply_to']['email'] ?? '') === 'sierrah.smith@monthaus.com' && has($m[0]['subject'] ?? '', '45 Elm yard sign'), json_encode($m));
ok('…the email outcome is recorded', ($w[1][0] ?? '') === 'request_email' && ($w[1][1][0] ?? '') === 'sent', json_encode($w));
ok('…and she sees the confirmation', $c === 302 && has($loc, 'sent=1'), "{$c} {$loc}");
[, , $b] = req('sierrah', '/portal/qr.php?sent=1');
ok('confirmation: your request has been sent', has($b, 'Your request has been sent!'), $b);
foreach (['My profile page' => 'not a web address', 'https://zillow.com/x' => 'another site', 'http://evil.example.com' => 'off monthaus.com'] as $bad => $why) {
    [$c, $loc] = req('sierrah', '/portal/qr.php', ['csrf_token' => 'tok', 'action' => 'request', 'title' => 'X', 'details' => '', 'destination' => $bad]);
    ok("destination refused: {$why}", writes() === [] && mails() === [] && has($loc, 'request=1'), json_encode(writes()) . $loc);
}
req('sierrah', '/portal/qr.php', ['csrf_token' => 'tok', 'action' => 'request', 'title' => 'X', 'details' => '', 'destination' => 'monthaus.com/listing.php?k=9']);
ok('a pasted address without https:// gets it', (writes()[0][1][5] ?? '') === 'https://monthaus.com/listing.php?k=9', json_encode(writes()));
[$c, , $b] = req('sierrah', '/portal/qr.php?request=1');
ok('the form asks for the exact URL from the browser', has($b, 'Destination URL') && has($b, 'type="url"') && has($b, 'Copy the exact web address from your browser'), $b);
[$c, $loc] = req('sierrah', '/portal/qr.php', ['csrf_token' => 'tok', 'action' => 'request', 'title' => 'X', 'details' => '', 'destination' => 'https://monthaus.com/y'], ['mail_fails' => true]);
ok('email fails: saved, recorded as failed, and she is told to email marketing@', (writes()[1][1][0] ?? '') === 'failed' && has($loc, 'request=1'), json_encode(writes()) . " {$loc}");
[, , $b] = req('sierrah', '/portal/qr.php?request=1');
[$c, $loc] = req('sierrah', '/portal/qr.php', ['csrf_token' => 'nope', 'action' => 'request', 'title' => 'X', 'details' => '', 'destination' => 'https://monthaus.com/y']);
ok('a bad form token sends nothing', writes() === [] && mails() === [], json_encode(writes()));
[, , $b] = req('jackson', '/portal/qr.php');
ok('with codes: a small Request one link under the list', has($b, 'Need another QR code?') && has($b, 'request=1'), $b);

echo "\nRECEIPTS\n";
$rdir = dirname($SB) . '/receipts/';
@mkdir($rdir, 0777, true);
file_put_contents($rdir . 'JHRECEIPT.pdf', 'JACKSON-RECEIPT-BYTES');
file_put_contents($rdir . 'KCRECEIPT.pdf', 'KIM-RECEIPT-BYTES');
[$c, , $b] = req('jackson', '/portal/receipt.php?order=11');
ok('Jackson opens his own receipt', $c === 200 && $b === 'JACKSON-RECEIPT-BYTES', "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/receipt.php?order=13');
ok('Kim\'s receipt is not found for Jackson', $c === 404 && !has($b, 'KIM-RECEIPT'), "{$c} {$b}");
[$c, , $b] = req('jon', '/portal/receipt.php?order=11');
ok('…nor for Weber Boxer', $c === 404 && !has($b, 'JACKSON'), "{$c} {$b}");
@unlink($rdir . 'JHRECEIPT.pdf'); @unlink($rdir . 'KCRECEIPT.pdf');

echo "\nADVERTISING\n";
[$c, , $b] = req('jackson', '/portal/advertising.php');
ok('Jackson: advertising renders', $c === 200 && no_fatal($b), $b);
ok('his placement, in plain words: outlet, print, size, running now', has($b, 'Aspen Times: JH Ad') && has($b, 'Print, Half page') && has($b, 'Running now') && has($b, 'From 5 March 2026'), $b);
ok('the card pictures his creative by its thumbnail', has($b, 'asset.php?asset=31&amp;thumb=1'), $b);
ok('NOTHING of Kim\'s or Weber Boxer\'s advertising', !has($b, 'KC Ad') && !has($b, 'asset=32') && !has($b, 'KC Secret') && !has($b, 'WB Ad'), $b);
ok('the creatives were asked for by HIS campaign ids only', (writes()[0][0] ?? '') === 'assets_in' && (writes()[0][1] ?? []) === [21], json_encode(writes()));
ok('no budget, notes or billing words', !has($b, 'SECRET NOTE') && !has($b, '1,234.00') && !preg_match('/invoic|billed|overdue|balance|unpaid|budget/i', strip_tags($b)), $b);
[$c, , $b] = req('jackson', '/portal/advertising.php?id=21');
ok('his placement opens with every creative', $c === 200 && no_fatal($b) && has($b, 'Half page') && has($b, 'asset.php?asset=31&amp;thumb=1') && has($b, 'asset.php?asset=31&amp;dl=1'), $b);
ok('…the PDF creative gets a PDF tile and Open PDF, not a broken picture', has($b, '>PDF<') && has($b, 'Open PDF') && !has($b, 'asset=33&amp;thumb=1'), $b);
ok('…the Dropbox original is a secondary link', has($b, 'https://www.dropbox.com/jh-ad-original') && has($b, 'Open original'), $b);
ok('…what it cost, in the spend words', has($b, 'Mont Haus paid') && has($b, '$1,234') && !has($b, 'SECRET NOTE'), $b);
[$c, , $b] = req('jackson', '/portal/advertising.php?id=22');
ok('Kim\'s placement id is not found for Jackson, with none of her data', $c === 404 && has($b, 'could not be found') && !has($b, 'KC Ad') && !has($b, 'asset=32') && !has($b, 'SECRET'), "{$c} {$b}");
[$c, , $b] = req('jon', '/portal/advertising.php');
ok('Weber Boxer sees the team\'s placement and nothing of Jackson\'s', has($b, 'Vail Daily: WB Ad') && has($b, 'Dates to be confirmed') && !has($b, 'JH Ad') && !has($b, 'asset=31'), $b);
[, , $b] = req('nikki', '/portal/advertising.php?preview=6');
ok('an admin previews Jackson\'s advertising, and links keep the preview', has($b, 'Preview:') && has($b, 'JH Ad') && has($b, 'advertising.php?preview=6&amp;id=21'), $b);

echo "\nPRINT ORDERS\n";
[$c, , $b] = req('jackson', '/portal/orders.php');
ok('Jackson: orders render', $c === 200 && no_fatal($b), $b);
ok('his orders in plain words: what, how many, where it is', has($b, 'Business Cards: JH cards') && has($b, 'On its way') && has($b, 'Quantity 500') && has($b, 'JH flyers') && has($b, 'Delivered 20 September 2026'), $b);
ok('the card pictures the proof by its thumbnail', has($b, 'asset.php?order=11&amp;thumb=1'), $b);
ok('NOTHING of Kim\'s or Weber Boxer\'s orders', !has($b, 'KC postcards') && !has($b, 'order=13') && !has($b, 'WB signs') && !has($b, 'Oakley'), $b);
ok('no vendor, order number or billing words', !preg_match('/invoic|billed|overdue|balance|unpaid|vendor/i', strip_tags($b)), $b);
[$c, , $b] = req('jackson', '/portal/orders.php?id=11');
ok('his order opens with the proof, tracking and cost', $c === 200 && no_fatal($b) && has($b, 'asset.php?order=11&amp;thumb=1') && has($b, 'Track the shipment') && has($b, 'https://www.ups.com/track?t=1Z999JH') && has($b, 'You paid') && has($b, '$250'), $b);
ok('…and the original files as a secondary link', has($b, 'https://www.dropbox.com/jh-cards-final') && has($b, 'Open original files'), $b);
[$c, , $b] = req('jackson', '/portal/orders.php?id=12');
ok('an order with no proof says so rather than showing a broken picture', $c === 200 && has($b, 'not on the portal yet') && !has($b, 'order=12&amp;thumb=1'), $b);
[$c, , $b] = req('jackson', '/portal/orders.php?id=13');
ok('Kim\'s order id is not found for Jackson, with none of her data', $c === 404 && has($b, 'could not be found') && !has($b, 'KC postcards') && !has($b, 'KCTRACK'), "{$c} {$b}");
[, , $b] = req('jon', '/portal/orders.php');
ok('Weber Boxer sees the team\'s order only', has($b, 'Yard Signs: WB signs') && has($b, 'Ordered') && !has($b, 'JH cards') && !has($b, 'order=11'), $b);

echo "\nCREATIVE FILES\n";
$cdir = dirname($SB) . '/creatives/';
@mkdir($cdir, 0777, true);
foreach (['JHAD.jpg' => 'JACKSON-AD-BYTES', 'JHAD_thumb.jpg' => 'JACKSON-AD-THUMB', 'JHAD2.pdf' => 'JACKSON-PDF-BYTES', 'KCAD.jpg' => 'KIM-AD-BYTES',
          'KCAD_thumb.jpg' => 'KIM-AD-THUMB', 'JHPROOF.png' => 'JACKSON-PROOF-BYTES', 'JHPROOF_thumb.jpg' => 'JACKSON-PROOF-THUMB', 'KCPROOF.png' => 'KIM-PROOF-BYTES'] as $f => $bytes) {
    file_put_contents($cdir . $f, $bytes);
}
[$c, , $b] = req('jackson', '/portal/asset.php?asset=31');
ok('Jackson opens his own creative', $c === 200 && $b === 'JACKSON-AD-BYTES', "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/asset.php?asset=31&thumb=1');
ok('…and its thumbnail', $c === 200 && $b === 'JACKSON-AD-THUMB', "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/asset.php?asset=33&thumb=1');
ok('a creative with no thumbnail: 404 for the thumbnail, the file still opens', $c === 404 && req('jackson', '/portal/asset.php?asset=33')[2] === 'JACKSON-PDF-BYTES', "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/asset.php?asset=32');
ok('Kim\'s creative is not found for Jackson', $c === 404 && !has($b, 'KIM'), "{$c} {$b}");
[$c, , $b] = req('jon', '/portal/asset.php?asset=31');
ok('…nor Jackson\'s for Weber Boxer', $c === 404 && !has($b, 'JACKSON'), "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/asset.php?order=11');
ok('Jackson opens his own proof', $c === 200 && $b === 'JACKSON-PROOF-BYTES', "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/asset.php?order=11&thumb=1');
ok('…and its thumbnail', $c === 200 && $b === 'JACKSON-PROOF-THUMB', "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/asset.php?order=13');
ok('Kim\'s proof is not found for Jackson', $c === 404 && !has($b, 'KIM'), "{$c} {$b}");
[$c, , $b] = req('jackson', '/portal/asset.php?order=12');
ok('an order without a proof: 404', $c === 404, "{$c}");
[$c, , $b] = req('jackson', '/portal/asset.php');
ok('no id at all: 404, nothing served', $c === 404 && !has($b, 'BYTES'), "{$c} {$b}");
foreach (array_keys(['JHAD.jpg' => 1, 'JHAD_thumb.jpg' => 1, 'JHAD2.pdf' => 1, 'KCAD.jpg' => 1, 'KCAD_thumb.jpg' => 1, 'JHPROOF.png' => 1, 'JHPROOF_thumb.jpg' => 1, 'KCPROOF.png' => 1]) as $f) @unlink($cdir . $f);

echo "\nHOME CARDS\n";
[, , $b] = req('jackson', '/portal/');
ok('home counts his advertising and orders in plain words', has($b, 'You have 1 ad running now') && has($b, 'You have 1 order on the way, and 1 delivered'), $b);
[, , $b] = req('jon', '/portal/');
ok('team home: a placement on record, an order on the way', has($b, 'You have 1 ad placement on record') && has($b, 'You have 1 order on the way.'), $b);
[, , $b] = req('sierrah', '/portal/');
ok('nothing yet: the cards say so, no dead ends', has($b, 'No ad placements yet.') && has($b, 'No print orders yet.'), $b);

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
              'refused-nolink' => ['nolink', '/portal/'], 'spend-jackson' => ['jackson', '/portal/spend.php'],
              'spend-month-jackson' => ['jackson', '/portal/spend.php?m=2026-09'], 'spend-wb' => ['jon', '/portal/spend.php'],
              'home-sierrah' => ['sierrah', '/portal/'], 'qr-request' => ['sierrah', '/portal/qr.php?request=1'],
              'qr-sent' => ['sierrah', '/portal/qr.php?sent=1'],
              'ads-jackson' => ['jackson', '/portal/advertising.php'], 'ad-jackson' => ['jackson', '/portal/advertising.php?id=21'],
              'orders-jackson' => ['jackson', '/portal/orders.php'], 'order-jackson' => ['jackson', '/portal/orders.php?id=11'],
              'ads-wb' => ['jon', '/portal/advertising.php'], 'orders-wb' => ['jon', '/portal/orders.php']] as $name => [$who, $path]) {
        file_put_contents("{$snap}/{$name}.html", req($who, $path)[2]);
    }
    echo "  (snapshots saved to {$snap})\n";
}

$log_txt = (string)@file_get_contents($log);
ok('no PHP warnings or errors in the server log', !preg_match('/(Warning|Fatal|Deprecated|Notice):/', $log_txt), $log_txt);

echo "\n" . ($fail ? "FAIL — {$fail} failed, {$pass} passed" : "PASS — {$pass} assertions") . "\n";
exit($fail ? 1 : 0);
