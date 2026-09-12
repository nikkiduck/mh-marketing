<?php
/**
 * render_order_split.php — render order_split.php against a stub database and
 * read the numbers back out of the HTML.
 *
 *   php tests/render_order_split.php
 *
 * test_invoice_split.php proves the arithmetic. This proves the PAGE — that the
 * figures reach the markup, that the reconciliation chips light up the right
 * colour, and that the page degrades visibly rather than silently when its
 * migration has not been run. Reasoning about what a template will emit is not
 * the same as seeing what it emitted; the 169px gap and the mysqli-after-close
 * fatal were both found this way and neither was visible by reading.
 *
 * ── THE STUB IS STRICT ON PURPOSE ────────────────────────────────────────────
 *
 * A stub more forgiving than the real thing converts a crash into a pass, which
 * is worse than having no stub. This one:
 *
 *   · sets a flag in mysqli::close(), and every query()/prepare() after that
 *     throws exactly as real mysqli does
 *   · THROWS on a SQL statement it does not recognise, rather than returning an
 *     empty result — so a query added to the page without being added here
 *     fails the harness instead of silently rendering as "no data"
 *   · returns every column as a STRING, the way a real driver hands them back,
 *     so a float comparison that only works on native floats fails here
 *
 * It builds a sandbox in the system temp directory: the real order_split.php,
 * inc/invoice_split.php, inc/financials.php, inc/schema.php and inc/_nav.php,
 * plus stub inc/auth.php and inc/db.php. Nothing in the repo is written to.
 */

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;

function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$what}\n"; }
    else { $fail++; echo "  FAIL {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}
function has(string $hay, string $needle): bool { return strpos($hay, $needle) !== false; }
function count_of(string $hay, string $needle): int { return substr_count($hay, $needle); }

// ── Build the sandbox ────────────────────────────────────────────────────────
$SB = sys_get_temp_dir() . '/os_render_' . bin2hex(random_bytes(4));
mkdir($SB . '/inc', 0777, true);

foreach (['inc/invoice_split.php', 'inc/financials.php', 'inc/schema.php', 'inc/_nav.php', 'order_split.php'] as $f) {
    $src = $ROOT . '/' . $f;
    if (!is_file($src)) { fwrite(STDERR, "FATAL: missing {$src}\n"); exit(1); }
    copy($src, $SB . '/' . $f);
}

// ── Stub inc/auth.php ────────────────────────────────────────────────────────
file_put_contents($SB . '/inc/auth.php', <<<'PHP'
<?php
define('RECEIPTS_DIR', sys_get_temp_dir() . '/os_receipts_test/');
function require_login(): void {}
function require_role(string $r): void {}
function current_user(): array {
    return ['id' => 1, 'first_name' => 'Nikki', 'last_name' => 'Boxer',
            'email' => 'nikki.boxer@monthaus.com', 'role' => 'super_admin'];
}
PHP);

// ── Stub inc/db.php — a strict mysqli ────────────────────────────────────────
file_put_contents($SB . '/inc/db.php', <<<'PHP'
<?php
/* Rows the harness serves. HARNESS_SCHEMA_READY flips the migration guard. */
class mh_result {
    private array $rows; private int $i = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc() { return $this->rows[$this->i++] ?? null; }
    public function fetch_row()   { $r = $this->rows[$this->i++] ?? null; return $r ? array_values($r) : null; }
}
class mh_stmt {
    private array $rows; private $owner;
    public bool $closed = false;
    public function __construct(array $rows, $owner) { $this->rows = $rows; $this->owner = $owner; }
    public function bind_param(string $types, &...$vars): bool {
        /* Real mysqli fatals when the type string and the argument count differ.
           The whole point of this project's bind_param rule is that miscounts
           are silent, so the stub must NOT be lenient here. */
        if (strlen($types) !== count($vars)) {
            throw new RuntimeException("bind_param: {$types} is " . strlen($types)
                . " types for " . count($vars) . " arguments");
        }
        /* Fixtures that vary by BOUND VALUE rather than by SQL text.
           mk_column_exists() binds the table and column as parameters, so every
           call to it has identical SQL — matching on the query alone cannot tell
           "does marketing_collateral_orders have merch_amount" from "does
           marketing_vendor_invoices have status". A half-migrated database is
           exactly that distinction, so the stub has to resolve after binding.
           A closure would be the obvious shape but the fixture array is
           var_export()ed into the driver, and closures do not survive that. */
        if (array_key_exists('__by_params', $this->rows)) {
            $key = implode('.', array_map('strval', $vars));
            $this->rows = $this->rows['__by_params'][$key] ?? [];
        }
        return true;
    }
    public function execute(): bool {
        if ($this->owner->closed) throw new Error('mysqli object is already closed');
        return true;
    }
    public function get_result() { return new mh_result($this->rows); }
    /* Note this close() is mh_stmt's, NOT mysqli's — putting the after-close
       flag on the wrong class is a mistake this project has already made once. */
    public function close(): bool { $this->closed = true; return true; }
}
class mysqli {
    public bool $closed = false;
    public int $insert_id = 0;
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
    public function begin_transaction(): bool { $this->guard(); return true; }
    public function commit(): bool { $this->guard(); return true; }
    public function rollback(): bool { $this->guard(); return true; }
}
$conn = new mysqli();
PHP);

/**
 * Render the page in a subprocess with the given fixtures and $_GET.
 * A subprocess because the page emits headers and a full document, and because
 * a fatal must be observable rather than taking the harness down with it.
 */
function render(string $SB, array $fixtures, array $get, bool $schema_ready): array {
    $driver = $SB . '/driver.php';
    file_put_contents($driver, '<?php
$_GET = ' . var_export($get, true) . ';
$_POST = [];
$_SERVER["REQUEST_METHOD"] = "GET";
require __DIR__ . "/inc/db.php";
mysqli::$fixtures = ' . var_export($fixtures, true) . ';
require __DIR__ . "/order_split.php";
');
    // -n loads no php.ini, so ext/mysqli is absent and the stub `class mysqli`
    // above is the only one defined. With the real extension loaded this dies
    // with "Cannot redeclare class mysqli" before rendering a byte.
    $out = [];
    $code = 0;
    exec('php -n -d error_reporting=E_ALL -d display_errors=1 '
         . escapeshellarg($driver) . ' 2>&1', $out, $code);
    return ['html' => implode("\n", $out), 'code' => $code];
}

// Fixture rows. Every value a string, as a real driver returns them.
$FOUND     = [['1' => '1']];          // information_schema hit
$NOT_FOUND = [];                      // information_schema miss

$AGENTS = [
    ['id' => '7',  'agent_name' => 'Allison D',  'is_active' => '1'],
    ['id' => '12', 'agent_name' => 'JM Drai',    'is_active' => '1'],
    ['id' => '19', 'agent_name' => 'Casey Lund', 'is_active' => '0'],
];
$INVOICE = [[
    'id' => '3', 'vendor' => 'Oakley Signs & Graphics', 'order_number' => 'IND-650292',
    'vendor_url' => '', 'ordered_at' => '2026-05-01',
    'merch_subtotal' => '2368.40', 'discount' => '220.60', 'rush' => '644.34',
    'shipping' => '573.27', 'tax' => '281.63', 'other' => '0.00',
    'grand_total' => '3647.04', 'house_merch' => '1748.40',
    'house_label' => '20 unbranded Open House signs',
    'receipt_file' => '', 'receipt_orig_name' => '', 'notes' => '',
    'status' => 'shipped', 'tracking_number' => '1Z999AA10123456784',
    'tracking_url' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
]];
// No status or tracking here: fulfilment is invoice-level. These columns are
// exactly what the reopen query selects, so a column added to the page without
// being added here surfaces as a missing-key warning rather than passing.
$ORDERS = [
    ['id' => '101', 'intake_id' => '7',  'type' => 'oh_signs', 'label' => 'Open House set',
     'qty' => '5', 'cost' => '477.37', 'merch_amount' => '310.00', 'paid_by' => 'broker'],
    ['id' => '102', 'intake_id' => '12', 'type' => 'oh_signs', 'label' => 'Open House set',
     'qty' => '5', 'cost' => '477.37', 'merch_amount' => '310.00', 'paid_by' => 'broker'],
];
$LIST = [[
    'id' => '3', 'vendor' => 'Oakley Signs & Graphics', 'order_number' => 'IND-650292',
    'ordered_at' => '2026-05-01', 'grand_total' => '3647.04',
    'merch_subtotal' => '2368.40', 'n_orders' => '2',
]];

$READY = [
    'information_schema.TABLES'          => $FOUND,
    'information_schema.COLUMNS'         => $FOUND,
    'FROM marketing_intakes'             => $AGENTS,
    'FROM marketing_vendor_invoices v'   => $LIST,
    'FROM marketing_vendor_invoices WHERE' => $INVOICE,
    'WHERE vendor_invoice_id'            => $ORDERS,
];

// ═══════════════════════════════════════════════════════════════════════════
echo "\nRENDERING AN EXISTING INVOICE — Oakley IND-650292\n";
// ═══════════════════════════════════════════════════════════════════════════
$r = render($SB, $READY, ['id' => '3'], true);
$h = $r['html'];

ok('page renders without a fatal', $r['code'] === 0 && !has($h, 'Fatal error'),
   substr(strip_tags($h), 0, 400));
ok('no PHP warnings or notices',
   !has($h, 'Warning:') && !has($h, 'Notice:') && !has($h, 'Deprecated:'),
   substr($h, max(0, strpos($h, 'Warning:') ?: 0), 300));
ok('the migration notice is NOT shown', !has($h, 'vendor_invoices_v1.sql'));

ok('invoice header loaded',   has($h, 'IND-650292'));
ok('vendor loaded',           has($h, 'Oakley Signs'));
ok('subtotal in the form',    has($h, 'value="2368.40"'));
ok('grand total in the form', has($h, 'value="3647.04"'));
ok('house merchandise in the form', has($h, 'value="1748.40"'));

// The figures that matter. These are the numbers written to the agents.
ok('both agents show $477.37',  count_of($h, '$477.37') === 2,
   'found ' . count_of($h, '$477.37'));
ok('the house shows $2,692.30', has($h, '$2,692.30'));
ok('shares render',             has($h, '13.09%') && has($h, '73.82%'));

// Every agent must appear in every picker, archived ones flagged rather than
// hidden — a historical invoice may well be for somebody since archived.
ok('agent picker lists all agents', has($h, 'Allison D') && has($h, 'JM Drai'));
ok('archived agents are marked, not hidden', has($h, 'Casey Lund (archived)'));

// Selected agents must come back selected, or reopening an invoice silently
// unassigns every line and the next save writes the costs to nobody.
ok('agent selections survive a reload',
   preg_match('/value="7"\s+selected/', $h) && preg_match('/value="12"\s+selected/', $h));
ok('paid_by selections survive a reload', has($h, 'value="broker"    selected'));

// A spare row, always.
ok('a blank spare row is offered', count_of($h, 'data-row') > count($ORDERS));

// ── Fulfilment is invoice-level, entered once ───────────────────────────────
//
// One invoice is one shipment. A status and tracking number per agent line
// would be the same value typed several times, free to disagree with itself —
// and the two extra columns crushed Qty to about 20px. These assert the fields
// exist ONCE, on the invoice, and nowhere in the lines table.
$has_opt = function (string $label) use ($h): bool {
    // Whitespace-tolerant: the label sits on the line after the opening tag.
    // Matching exact indentation would fail on a reformat that changed nothing.
    return (bool)preg_match('/>\s*' . preg_quote($label, '/') . '\s*<\/option>/', $h);
};
ok('every production status is offered',
   $has_opt('Pending') && $has_opt('Ordered') && $has_opt('In Production')
   && $has_opt('Shipped') && $has_opt('Delivered'));
ok('exactly one status select, on the invoice',
   count_of($h, 'name="status"') === 1 && !has($h, '[status]'));
ok('exactly one tracking number field',
   count_of($h, 'name="tracking_number"') === 1 && !has($h, '[tracking_number]'));
ok('exactly one tracking URL field',
   count_of($h, 'name="tracking_url"') === 1 && !has($h, '[tracking_url]'));
ok('the stored status comes back selected',
   (bool)preg_match('/value="shipped"\s+selected/', $h));
ok('the stored tracking number is reloaded', has($h, '1Z999AA10123456784'));
ok('the stored tracking URL is reloaded',    has($h, 'ups.com/track'));

// ── The lines table has nine columns and every one has a floor ──────────────
//
// A `width` is a suggestion the table overrules to make columns fit, and it
// takes the space from whichever column holds the narrowest content — Qty,
// which is how it ended up about 20px wide. min-width is a floor. The table
// min-width must be at least the sum of the floors plus 8px-a-side padding, or
// the whole table compresses and the floors get overruled together.
preg_match('/<table class="os-lines".*?<\/thead>/s', $h, $thead);
$head_html = $thead[0] ?? '';
ok('every column has a min-width floor, none a bare width',
   !preg_match('/<th style="width:/', $head_html),
   'a bare width= survives in the header row');
preg_match_all('/min-width:(\d+)px/', $head_html, $mw);
$floors = array_map('intval', $mw[1] ?? []);
ok('the lines table has nine columns', count($floors) === 9, 'found ' . count($floors));
preg_match('/table\.os-lines\s*\{[^}]*min-width:(\d+)px/', $h, $tw);
$table_min = (int)($tw[1] ?? 0);
ok('table min-width covers the sum of the floors',
   $table_min >= array_sum($floors) + 16 * count($floors),
   "table {$table_min}px vs floors " . array_sum($floors) . ' + padding ' . (16 * count($floors)));

// Money boxes normalise to two decimals on focusout. The harness cannot run the
// handler, but it can prove it is wired to the event that bubbles: `blur` does
// not, so a delegated listener on blur would silently never fire and every
// freshly typed figure would keep looking like "25.6".
ok('money fields normalise on focusout, not blur',
   has($h, "addEventListener('focusout'") && has($h, 'normaliseMoney'));

// ── Invoice field order ─────────────────────────────────────────────────────
// Subtotal, Rush, Discount, Tax, Shipping — the order the invoice itself reads.
$order = [];
foreach (['merch_subtotal','rush','discount','tax','shipping'] as $f) {
    $order[$f] = strpos($h, 'name="' . $f . '"');
}
ok('money fields are in invoice order',
   $order['merch_subtotal'] < $order['rush'] && $order['rush'] < $order['discount']
   && $order['discount'] < $order['tax'] && $order['tax'] < $order['shipping'],
   json_encode($order));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nTHE MIGRATION GUARD\n";
// ═══════════════════════════════════════════════════════════════════════════
//
// Publishing the page before running vendor_invoices_v1.sql must say so. The
// failure without the guard is the silent kind: an empty list, no error.
$NOT_READY = $READY;
$NOT_READY['information_schema.TABLES'] = $NOT_FOUND;
$r2 = render($SB, $NOT_READY, [], false);
$h2 = $r2['html'];

ok('no fatal without the migration', $r2['code'] === 0 && !has($h2, 'Fatal error'),
   substr(strip_tags($h2), 0, 400));
ok('it names the migration to run', has($h2, 'vendor_invoices_v1.sql'));
ok('and does NOT render the form',  !has($h2, 'name="merch_subtotal"'));

// A HALF-migrated database: v1 ran, v2 did not.
//
// This is the state that actually shipped, and the guard did not catch it — it
// tested only v1's objects, so the page rendered its form, accepted an invoice,
// and failed at INSERT with "Unknown column 'status' in 'field list'". A raw
// driver error where a plain sentence belonged.
//
// It is also the state re-running v1 CANNOT fix: v1 is a
// CREATE TABLE IF NOT EXISTS and skips entirely once the table exists. The
// notice has to name v2 specifically, or the obvious next move is the one that
// silently does nothing.
$HALF = $READY;
$HALF['information_schema.COLUMNS'] = ['__by_params' => [
    'marketing_collateral_orders.vendor_invoice_id' => $FOUND,
    'marketing_collateral_orders.merch_amount'      => $FOUND,
    'marketing_vendor_invoices.status'              => $NOT_FOUND,
    'marketing_vendor_invoices.tracking_number'     => $NOT_FOUND,
    'marketing_vendor_invoices.tracking_url'        => $NOT_FOUND,
]];
$r2b = render($SB, $HALF, [], false);
$h2b = $r2b['html'];

ok('half-migrated does not fatal', $r2b['code'] === 0 && !has($h2b, 'Fatal error'),
   substr(strip_tags($h2b), 0, 400));
ok('half-migrated names v2, not v1',
   has($h2b, 'vendor_invoices_v2_fulfilment.sql')
   && !has($h2b, '<code>sql/vendor_invoices_v1.sql</code>'));
ok('half-migrated warns that re-running v1 will not help',
   has($h2b, 'CREATE TABLE IF NOT EXISTS'));
ok('half-migrated refuses to render the form', !has($h2b, 'name="merch_subtotal"'));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nA BLANK NEW INVOICE\n";
// ═══════════════════════════════════════════════════════════════════════════
$r3 = render($SB, $READY, [], true);
$h3 = $r3['html'];

ok('blank page renders', $r3['code'] === 0 && !has($h3, 'Fatal error'),
   substr(strip_tags($h3), 0, 400));
ok('no division by zero on an empty form',
   !has($h3, 'Division by zero') && !has($h3, 'INF') && !has($h3, 'NAN'));
ok('four rows to type into', count_of($h3, 'data-row') >= 4);
ok('the house line is present', has($h3, 'name="house_merch"'));
ok('landed cells start empty', count_of($h3, '>—<') >= 4);
ok('the recent-invoice list renders', has($h3, 'Recent split invoices'));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nTHE STUB ITSELF\n";
// ═══════════════════════════════════════════════════════════════════════════
//
// A harness that cannot fail proves nothing. Confirm the strictness is real:
// an unmatched query must throw rather than render as no data.
$r4 = render($SB, ['information_schema.TABLES' => $FOUND], [], true);
ok('an unfixtured query throws rather than rendering empty',
   has($r4['html'], 'no fixture matches SQL') || has($r4['html'], 'Uncaught'));

// And that the page really is reading the fixtures, not hardcoding.
$MOVED = $READY;
$MOVED['FROM marketing_vendor_invoices WHERE'] = [array_merge($INVOICE[0], [
    'merch_subtotal' => '1000.00', 'discount' => '0.00', 'rush' => '0.00',
    'shipping' => '100.00', 'tax' => '0.00', 'grand_total' => '1100.00',
    'house_merch' => '380.00',
])];
$MOVED['WHERE vendor_invoice_id'] = [
    ['id' => '101', 'intake_id' => '7', 'type' => 'yard_signs', 'label' => '',
     'qty' => '1', 'cost' => '0', 'merch_amount' => '620.00', 'paid_by' => ''],
];
$r5 = render($SB, $MOVED, ['id' => '3'], true);
// 620 of 1000 merchandise, $100 shipping on top → 620 + 62 = 682.00
ok('different fixtures give different figures', has($r5['html'], '$682.00'),
   'expected $682.00 in the rendered allocation');
// 380 house + 620 agent = 1000 = subtotal, so no drift warning.
ok('a reconciling invoice shows no drift warning',
   !has($r5['html'], 'probably edited or deleted'));

// A row edited away on the Collateral tab must be REPORTED, not rendered as if
// the invoice were fine — this is the one thing about the page that can drift.
$DRIFT = $READY;
$DRIFT['WHERE vendor_invoice_id'] = [$ORDERS[0]];   // one of two orders gone
$r6 = render($SB, $DRIFT, ['id' => '3'], true);
ok('a deleted order is reported as drift',
   has($r6['html'], 'probably edited or deleted'));

// ── Clean up ────────────────────────────────────────────────────────────────
foreach (glob($SB . '/inc/*') as $f) @unlink($f);
foreach (glob($SB . '/*') as $f) { if (is_file($f)) @unlink($f); }
@rmdir($SB . '/inc'); @rmdir($SB);

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
