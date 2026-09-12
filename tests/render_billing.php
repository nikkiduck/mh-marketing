<?php
/**
 * tests/render_billing.php — render billing.php against fixture data.
 *
 * Run:  php tests/render_billing.php
 *
 * WHY THIS EXISTS. The three tabs are three readings of the same money, and the
 * ways they can disagree are all silent:
 *
 *   · Tab 1 hides settled months older than last month. Build the all-time
 *     totals from that filtered set and "covered by Mont Haus to date" shrinks
 *     every time a month is marked paid. Nothing on screen would say so.
 *   · Tab 1 lists active agents only. Carry that into the lifetime totals and
 *     every offboarded agent's spend silently disappears from the company's
 *     own figures.
 *   · A line with Who Pays unset belongs to neither party. Fold it into either
 *     column and one of them is overstated by exactly that amount.
 *
 * None of those produce an error, a blank page, or a wrong-looking layout. They
 * produce a page that looks right and adds up wrong, which is the only kind of
 * bug this page can have that matters.
 *
 * The real inc/financials.php is loaded — the point is to exercise
 * mh_agent_financials(), not to reimplement it. Only mysqli and the auth
 * helpers are stubbed.
 */

$ROOT = dirname(__DIR__);
$SB   = sys_get_temp_dir() . '/bl_' . bin2hex(random_bytes(4));
mkdir($SB . '/inc', 0777, true);

copy($ROOT . '/billing.php',        $SB . '/billing.php');
copy($ROOT . '/inc/financials.php', $SB . '/inc/financials.php');

// ── Stubs ────────────────────────────────────────────────────────────────────
file_put_contents($SB . '/inc/auth.php', '<?php
function require_login() {}
function require_role($r) {}
function current_user() { return ["id" => 1, "email" => "test@monthaus.com"]; }
');

file_put_contents($SB . '/inc/_nav.php', '<?php /* nav omitted */ ?>');

file_put_contents($SB . '/inc/db.php', '<?php
define("MYSQLI_ASSOC", 1);
class mh_result {
    private array $rows; private int $i = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc() { return $this->rows[$this->i++] ?? null; }
    public function fetch_row()   { $r = $this->rows[$this->i++] ?? null; return $r ? array_values($r) : null; }
    public function fetch_all($m = 1) { return $this->rows; }
}
class mh_stmt {
    private array $rows; public bool $closed = false;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function bind_param(string $types, &...$v): bool {
        /* Real mysqli fatals on a count mismatch. The stub must not be lenient —
           a silent miscount is the exact failure this project guards against. */
        if (strlen($types) !== count($v)) {
            throw new RuntimeException("bind_param: {$types} is " . strlen($types)
                . " types for " . count($v) . " arguments");
        }
        return true;
    }
    public function execute(): bool { return true; }
    public function get_result() { return new mh_result($this->rows); }
    public function close(): bool { $this->closed = true; return true; }
}
class mysqli {
    public static array $fixtures = [];
    public int $insert_id = 0;
    private function match(string $sql): array {
        foreach (self::$fixtures as $needle => $rows) {
            if (stripos($sql, $needle) !== false) return $rows;
        }
        /* Throw rather than return [] — an unfixtured query that quietly
           returned nothing would render a clean page full of zeroes and pass. */
        throw new RuntimeException("stub mysqli: no fixture matches SQL:\n" . trim($sql));
    }
    public function query(string $sql) { return new mh_result($this->match($sql)); }
    public function prepare(string $sql) { return new mh_stmt($this->match($sql)); }
    public function close(): bool { return true; }
}
$conn = new mysqli();
');

// ── Fixtures ─────────────────────────────────────────────────────────────────
//
// Two active agents and one former one, chosen so every rule this page has can
// be told apart from every other:
//
//   Jackson Horn (active)   advertising, broker pays in full
//   Minette Stapleton (act) collateral, one split line and one undecided line
//   Bonnie Scott (FORMER)   collateral, Mont Haus paid in full
//
// Bonnie is the load-bearing one. Her spend must be absent from tab 1 and
// present in tabs 2 and 3, and no other fixture can prove that.

$INTAKES = [
    ['id' => '7',  'agent_name' => 'Jackson Horn',      'roster_id' => null, 'is_active' => '1'],
    ['id' => '12', 'agent_name' => 'Minette Stapleton', 'roster_id' => null, 'is_active' => '1'],
    ['id' => '30', 'agent_name' => 'Bonnie Scott',      'roster_id' => null, 'is_active' => '0'],
];

$ym   = date('Y-m');
$YM   = $ym . '-10';                       // a date inside the current month

$COLLATERAL = [
    // Minette: $698.47 split — $500 broker, $198.47 Mont Haus
    '12' => [[
        'id' => '501', 'type' => 'yard_signs', 'label' => 'Yard Signs',
        'vendor' => 'Oakley', 'cost' => '698.47', 'ordered_at' => $YM,
        'created_at' => $YM, 'billed_with_order_id' => null,
        'receipt_file' => null, 'receipt_orig_name' => null,
        'paid_by' => 'split', 'paid_broker_amount' => '500.00',
        'paid_mh_amount' => '198.47',
    ], [
        // Who Pays never set — belongs to neither column
        'id' => '502', 'type' => 'oh_signs', 'label' => 'Open House Signs',
        'vendor' => 'Oakley', 'cost' => '250.00', 'ordered_at' => $YM,
        'created_at' => $YM, 'billed_with_order_id' => null,
        'receipt_file' => null, 'receipt_orig_name' => null,
        'paid_by' => null, 'paid_broker_amount' => null, 'paid_mh_amount' => null,
    ]],
    // Bonnie, former: Mont Haus covered all of it
    '30' => [[
        'id' => '601', 'type' => 'business_cards', 'label' => 'Farewell cards',
        'vendor' => 'Moo', 'cost' => '412.00', 'ordered_at' => $YM,
        'created_at' => $YM, 'billed_with_order_id' => null,
        'receipt_file' => null, 'receipt_orig_name' => null,
        'paid_by' => 'mont_haus', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
    ]],
    '7' => [],
];

$CAMPAIGNS = [
    // Jackson: $1,500 one-time, broker pays
    '7' => [[
        'id' => '900', 'platform' => 'Aspen Daily News', 'name' => 'Sticky anchor ads',
        'budget' => '1500.00', 'billing_mode' => 'one_time', 'unit_rate' => null,
        'unit_weekday' => null, 'unit_label' => null,
        'start_date' => $YM, 'end_date' => $YM, 'created_at' => $YM,
        'paid_by' => 'broker', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
    ]],
    '12' => [], '30' => [],
];

/**
 * Render billing.php with one agent's rows swapped in per mh_agent_financials()
 * call. The stub matches on SQL text, but financials.php asks the same question
 * for each agent with the id interpolated — so the fixture key carries the id
 * and each agent's data is selected by it.
 */
function render(string $SB, array $get, array $intakes, array $coll,
                array $camps, array $billing_months): array {
    $fx = ['FROM marketing_billing_months' => $billing_months,
           'FROM marketing_intakes'        => $intakes];
    foreach ($intakes as $ag) {
        $id = $ag['id'];
        $fx["FROM marketing_collateral_orders\n         WHERE intake_id = {$id}"] = $coll[$id] ?? [];
        $fx["WHERE c.intake_id = {$id}"] = [];
        $fx["FROM marketing_campaigns\n         WHERE intake_id = {$id}"] = $camps[$id] ?? [];
    }
    $driver = $SB . '/driver.php';
    file_put_contents($driver, '<?php
$_GET = ' . var_export($get, true) . ';
$_POST = [];
$_SERVER["REQUEST_METHOD"] = "GET";
require __DIR__ . "/inc/db.php";
mysqli::$fixtures = ' . var_export($fx, true) . ';
require __DIR__ . "/billing.php";
');
    // -n loads no php.ini, so ext/mysqli is absent and the stub class above is
    // the only mysqli defined. With the real extension loaded this dies with
    // "Cannot redeclare class mysqli" before rendering a byte.
    $out = []; $code = 0;
    exec('php -n -d error_reporting=E_ALL -d display_errors=1 '
         . escapeshellarg($driver) . ' 2>&1', $out, $code);
    return ['html' => implode("\n", $out), 'code' => $code];
}

// ── Assertions ───────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$what}\n"; }
    else       { $fail++; echo "  FAIL {$what}" . ($detail ? "\n       {$detail}" : '') . "\n"; }
}
function has(string $h, string $n): bool { return strpos($h, $n) !== false; }
/** Text inside one panel only — the whole point is that tabs differ. */
function panel(string $h, string $id): string {
    $i = strpos($h, 'id="panel-' . $id . '"');
    if ($i === false) return '';
    $j = strpos($h, '<!-- /panel-' . $id . ' -->', $i);
    return $j === false ? substr($h, $i) : substr($h, $i, $j - $i);
}

$r = render($SB, [], $INTAKES, $COLLATERAL, $CAMPAIGNS, []);
$h = $r['html'];

echo "\nTHE PAGE RENDERS\n";
ok('no fatal', $r['code'] === 0 && !has($h, 'Fatal error') && !has($h, 'Warning:'),
   substr(strip_tags($h), 0, 500));
ok('all three tabs are present',
   has($h, 'data-tab="due"') && has($h, 'data-tab="monthly"') && has($h, 'data-tab="agents"'));
ok('Due from agent opens by default', has($h, 'bl-panel active" id="panel-due"'));
// Every tab carries its own subtitle. A single page-level caption described the
// collections view only — on the report tabs it claimed settled months drop off
// and that the figures were "what each agent owes", when neither holds there.
ok('every tab has its own subtitle',
   substr_count($h, 'class="bl-sub') === 3);
ok('the subtitle showing matches the tab',
   (bool)preg_match('/bl-sub active" data-sub="due"/', $h)
   && !preg_match('/bl-sub active" data-sub="(monthly|agents)"/', $h));
ok('switching tabs moves the subtitle too', has($h, "querySelectorAll('.bl-sub')"));

echo "\nTAB 1 — DUE FROM AGENT\n";
$p1 = panel($h, 'due');
ok('active agents appear',   has($p1, 'Jackson Horn') && has($p1, 'Minette Stapleton'));
ok('a FORMER agent does not', !has($p1, 'Bonnie Scott'),
   'tab 1 is a to-do list — nobody chases an invoice to someone who left');
ok('the amount is a breakdown trigger', has($p1, 'bl-amt-btn') && has($p1, 'data-bd-ym='));
ok('the empty "This cycle" chip is gone', !has($p1, 'This cycle'));
ok("Mont Haus's share shows on the row", has($p1, 'MH $198.47'));
ok('an undecided line is flagged',        has($p1, 'Undecided $250.00'));

echo "\nTAB 2 — MONTHLY BREAKDOWN\n";
$p2 = panel($h, 'monthly');
ok('the former agent IS counted here', has($p2, 'Bonnie Scott'),
   'a monthly total that omits departed agents understates real spend');
ok('she is marked former',             has($p2, 'Former'));
ok('all three columns are headed',
   has($p2, 'Due from agent') && has($p2, 'Covered by Mont Haus') && has($p2, 'Undecided'));
// Broker  = 1500.00 + 500.00            = 2000.00
// MH      =  198.47 + 412.00            =  610.47
// Undecid =  250.00                     =  250.00
// Total   = 2000.00 + 610.47 + 250.00   = 2860.47
ok('month total: due from agents',   has($p2, '$2,000.00'));
ok('month total: covered by MH',     has($p2, '$610.47'));
ok('month total: undecided',         has($p2, '$250.00'));
ok('the three columns sum to the month total', has($p2, '$2,860.47'),
   'broker + mh + unassigned must reconcile, or a line is being counted twice or not at all');

echo "\nTAB 3 — AGENT TOTALS\n";
$p3 = panel($h, 'agents');
ok('every agent is listed, former included',
   has($p3, 'Jackson Horn') && has($p3, 'Minette Stapleton') && has($p3, 'Bonnie Scott'));
ok('all-time Mont Haus total is shown', has($p3, '$610.47'));
ok('Mont Haus spend is NOT given a paid state', !has($p3, 'Mont Haus paid'),
   "MH's share is absorbed, not invoiced — it is spend, not a receivable");

echo "\nSETTLED MONTHS DO NOT SHRINK THE TOTALS\n";
// The trap this page is most likely to fall into: tab 1 drops settled months
// older than last month, so building the all-time figures from that filtered
// set would make Mont Haus's total fall every time a month is marked paid.
// Mark EVERY month paid and confirm tabs 2 and 3 are unmoved.
$PAID = [];
foreach ($INTAKES as $ag) {
    $PAID[] = ['intake_id' => $ag['id'], 'ym' => $ym, 'billed_at' => '2026-08-01',
               'billed_amount' => '0.00', 'paid_at' => '2026-08-02',
               'paid_by_user' => '1', 'billed_by' => '1'];
}
$r2 = render($SB, [], $INTAKES, $COLLATERAL, $CAMPAIGNS, $PAID);
$h2 = $r2['html'];
ok('still renders once everything is settled', $r2['code'] === 0 && !has($h2, 'Fatal error'),
   substr(strip_tags($h2), 0, 400));
ok('monthly MH total is unchanged by payment', has(panel($h2, 'monthly'), '$610.47'));
ok('all-time MH total is unchanged by payment', has(panel($h2, 'agents'), '$610.47'));
ok('paid money moves into the Paid column',     has(panel($h2, 'agents'), '$2,000.00'));

echo "\nTHE TAB IN THE URL\n";
$r3 = render($SB, ['tab' => 'agents'], $INTAKES, $COLLATERAL, $CAMPAIGNS, []);
ok('?tab=agents opens that tab', has($r3['html'], 'bl-panel active" id="panel-agents"'));
$r4 = render($SB, ['tab' => 'nonsense'], $INTAKES, $COLLATERAL, $CAMPAIGNS, []);
ok('an unknown tab falls back to the first', has($r4['html'], 'bl-panel active" id="panel-due"'));

echo "\nTHE MODAL DATA\n";
ok('breakdown JSON is embedded', has($h, 'id="blBreakdown"'));
preg_match('/id="blBreakdown"[^>]*>(.*?)<\/script>/s', $h, $m);
$bd = json_decode($m[1] ?? '{}', true);
ok('it is valid JSON', is_array($bd), substr($m[1] ?? '', 0, 200));
ok('Minette has both her lines',
   isset($bd['12'][$ym]) && count($bd['12'][$ym]) === 2,
   json_encode($bd['12'][$ym] ?? null));
ok('each line carries its own three-way split',
   isset($bd['12'][$ym][0]['broker'], $bd['12'][$ym][0]['mh'], $bd['12'][$ym][0]['un']));
ok('the former agent is NOT in the modal data', !isset($bd['30']),
   'the modal only opens from tab 1, so shipping her lines would be dead weight');

echo "\n{$pass} passed, {$fail} failed\n";
exec('rm -rf ' . escapeshellarg($SB));
exit($fail === 0 ? 0 : 1);
