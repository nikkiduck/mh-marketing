<?php
/**
 * order_split.php — one vendor invoice, several agents.
 *
 * A vendor bills the discount, the rush fee, the shipping and the sales tax
 * ONCE, at the bottom of the invoice, however many agents' signs are on it.
 * Entering only each agent's line-item total therefore under-states every one
 * of them, and the order never reconciles to the card statement. On Oakley
 * IND-650292 that gap is $1,278.64 on $2,368.40 of merchandise — every $1.00 of
 * sticker price actually cost $1.54.
 *
 * This page enters the invoice once, tags each line to an agent, and writes the
 * LANDED cost into each agent's collateral order.
 *
 * ── WHY THIS NEEDS NO CHANGES ANYWHERE ELSE ─────────────────────────────────
 *
 * marketing_collateral_orders.cost is already the one number the rest of the app
 * reads: mh_agent_financials() derives the Financials tab, billing.php and the
 * roster balance badge from it and nothing else. Writing the landed cost into
 * that field means every downstream surface is correct with no edit to any of
 * them. That is the whole reason this feature is a new page plus one migration
 * rather than a rewrite of the money.
 *
 * ── THE ARITHMETIC IS NOT HERE ──────────────────────────────────────────────
 *
 * mh_invoice_allocate() lives in inc/invoice_split.php, which queries nothing
 * and echoes nothing, so tests/test_invoice_split.php tests the real function
 * rather than a copy. Read the header of that file for why each charge is
 * split pro-rata, and for the proof that tax needs no rate.
 *
 *     php tests/test_invoice_split.php     # 70 assertions
 *
 * The JavaScript at the bottom of this page is a SECOND implementation of that
 * arithmetic and is deliberately labelled a preview. It exists so the figures
 * move as you type, which a server round-trip per keystroke cannot do. It never
 * decides anything: Save recomputes in PHP and the page then redisplays the
 * server's numbers. If the two ever disagree, the PHP is right by definition —
 * and the tolerance check in the JS says so on screen rather than leaving you
 * to notice.
 *
 * ── WHAT IT WRITES, AND WHAT IT LEAVES ALONE ────────────────────────────────
 *
 * On an order it owns, this page writes: type, label, qty, cost, merch_amount,
 * vendor, vendor_url, order_number, ordered_at, paid_by, status,
 * tracking_number, tracking_url, vendor_invoice_id.
 *
 * FULFILMENT IS INVOICE-LEVEL. status, tracking_number and tracking_url are
 * entered ONCE, on the invoice, and stored on marketing_vendor_invoices. One
 * invoice is one shipment: the vendor makes it together and sends it in one
 * box, so a status per agent line would be the same value typed several times
 * and free to disagree with itself.
 *
 * Those three are then copied onto EVERY collateral order this page writes, so
 * the Collateral tab and the agent's own view still show tracking per order.
 * The consequence, and it is deliberate: editing one order's tracking on the
 * Collateral tab and then re-saving the invoice here overwrites it with the
 * invoice's value. If a shipment ever genuinely splits into two boxes, that is
 * the assumption to revisit.
 *
 * It does NOT write delivered_at, file_url or notes on an existing order —
 * those are edited on the Collateral tab and are not on this form, so writing
 * them would blank them. notes is written on INSERT only, where there is
 * nothing to lose.
 *
 * ── billed_with_order_id DOES NOT COMBINE WITH THIS ─────────────────────────
 *
 * That column de-duplicates a combined purchase inside ONE agent's record: the
 * order holding the cost reports it, the linked ones contribute $0. This page
 * does the opposite — every row carries its own true share. An order with both
 * set would be counted as zero by mh_agent_financials() while still holding an
 * allocated cost, so the money would silently vanish from Financials. Every row
 * this page writes therefore has billed_with_order_id cleared, and an order
 * that already has one set is refused rather than adopted.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/financials.php';      // mk_coll_type_label()
require_once __DIR__ . '/inc/schema.php';          // mk_table_exists()
require_once __DIR__ . '/inc/invoice_split.php';   // mh_invoice_allocate()
require_login();
require_role('admin');

$me = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;

function os_money(float $n): string { return '$' . number_format($n, 2); }
function os_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** Money from a form field. Accepts "1,284.00", "$1,284", "" — all as floats. */
function os_num($v): float {
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    return (float)preg_replace('/[^0-9.\-]/', '', $v);
}

$COLL_TYPES = ['business_cards','yard_signs','oh_signs','postcards','brochures','other'];

// ── Schema guard ──────────────────────────────────────────────────────────────
//
// Deploying this page before its migration fails SILENTLY without this: the
// SELECT against a missing table returns false, the invoice list renders empty,
// and nothing anywhere says why. Same class of problem as the initials column —
// see inc/schema.php. The guard is scaffolding for the deploy window and can go
// once vendor_invoices_v1.sql has been run.
// Checked per migration, not as one boolean, so a database that is half-migrated
// names the file it still needs. The first version of this guard tested only v1's
// objects; v2's columns were then missing at INSERT time and the page reported
// "Unknown column 'status' in 'field list'" — a raw driver error where a plain
// sentence belonged. Every column this page WRITES has to be represented here.
$schema_v1 = mk_table_exists($conn, 'marketing_vendor_invoices')
          && mk_column_exists($conn, 'marketing_collateral_orders', 'vendor_invoice_id')
          && mk_column_exists($conn, 'marketing_collateral_orders', 'merch_amount');
$schema_v2 = $schema_v1
          && mk_column_exists($conn, 'marketing_vendor_invoices', 'status')
          && mk_column_exists($conn, 'marketing_vendor_invoices', 'tracking_number')
          && mk_column_exists($conn, 'marketing_vendor_invoices', 'tracking_url');

$schema_ready   = $schema_v1 && $schema_v2;
$schema_missing = !$schema_v1 ? 'sql/vendor_invoices_v1.sql'
                             : 'sql/vendor_invoices_v2_fulfilment.sql';

// ── CSRF ──────────────────────────────────────────────────────────────────────
//
// Reusing the token change_password.php already establishes, rather than
// inventing a second scheme. This page writes money onto several agents at
// once, which is worth the four lines even though the rest of the app relies on
// SameSite=Lax alone.
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

$invoice_id = (int)($_GET['id'] ?? 0);
$error      = '';
$saved      = !empty($_GET['saved']);

/**
 * Store an uploaded invoice receipt. Same rules as mk_store_receipt() in
 * agent.php: PDF/JPG/PNG only, 10 MB cap, outside the document root.
 */
function os_store_receipt(string $field = 'receipt'): ?array {
    if (empty($_FILES[$field]['tmp_name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return null;                                   // nothing uploaded — not an error
    }
    $tmp  = $_FILES[$field]['tmp_name'];
    $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : '';
    $ext  = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if ($ext === null)                              return ['error' => 'Receipt must be a PDF, JPG or PNG.'];
    if ($_FILES[$field]['size'] > 10 * 1024 * 1024) return ['error' => 'Receipt exceeds the 10 MB limit.'];
    if (!is_dir(RECEIPTS_DIR) && !mkdir(RECEIPTS_DIR, 0750, true)) {
        return ['error' => 'Could not create the receipts folder.'];
    }
    $stored = 'invoice_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($tmp, RECEIPTS_DIR . $stored)) return ['error' => 'Failed to save the receipt.'];
    $orig = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($_FILES[$field]['name'] ?? $stored));
    return ['file' => $stored, 'orig' => $orig];
}

/**
 * Give one collateral order its OWN physical copy of the invoice receipt.
 *
 * Not a shared filename on purpose. agent.php's delete_collateral_receipt
 * physically unlinks the file, so three orders pointing at one file would mean
 * clearing the receipt on one agent silently breaks the link on the other two.
 * A few duplicated PDFs is a much better trade than that.
 */
function os_copy_receipt_for_order(string $stored): ?string {
    $src = RECEIPTS_DIR . basename($stored);
    if (!is_file($src)) return null;
    $ext  = pathinfo($src, PATHINFO_EXTENSION) ?: 'pdf';
    $copy = 'receipt_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    return @copy($src, RECEIPTS_DIR . $copy) ? $copy : null;
}

/** Remove a stored receipt file. Silent if already gone. */
function os_unlink_receipt(?string $stored): void {
    $stored = trim((string)$stored);
    if ($stored === '') return;
    $p = RECEIPTS_DIR . basename($stored);
    if (is_file($p)) @unlink($p);
}

// ── Blank shapes, so the render path never has to test for existence ─────────
$inv = [
    'id' => 0, 'vendor' => '', 'order_number' => '', 'vendor_url' => '',
    'ordered_at' => date('Y-m-d'),
    'merch_subtotal' => '', 'discount' => '', 'rush' => '', 'shipping' => '',
    'tax' => '', 'other' => '', 'grand_total' => '',
    'house_merch' => '', 'house_label' => '',
    'receipt_file' => '', 'receipt_orig_name' => '', 'notes' => '',
    // Fulfilment, once for the whole invoice. 'ordered' rather than 'pending':
    // an invoice being typed here is one that has been placed, and defaulting
    // to pending would mark every split order as not yet ordered.
    'status' => 'ordered', 'tracking_number' => '', 'tracking_url' => '',
];
$rows = [];

/**
 * Production statuses, in the order an order actually moves through them.
 * Same set as marketing_collateral_orders.status on the Collateral tab — the
 * two surfaces edit one column and must offer one vocabulary.
 */
const OS_STATUSES = ['pending', 'ordered', 'in_production', 'shipped', 'delivered'];

function os_status_label(string $s): string {
    return ucwords(str_replace('_', ' ', $s));
}

/** One blank allocation row. Fulfilment is invoice-level, not per line. */
function os_blank_row(): array {
    return ['order_id' => 0, 'intake_id' => 0, 'type' => 'oh_signs',
            'label' => '', 'qty' => '', 'merch' => '', 'paid_by' => ''];
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST
// ═══════════════════════════════════════════════════════════════════════════════
if ($schema_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($CSRF, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        exit('That form has expired. Go back, reload the page and try again.');
    }
    $action     = $_POST['_action'] ?? '';
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);

    // ── Delete an invoice ────────────────────────────────────────────────────
    //
    // The collateral orders are NOT deleted with it — they are real orders that
    // were really placed, and an agent's history should not disappear because
    // the invoice record was tidied up. They are unlinked instead, keeping the
    // cost they were allocated. Deleting the orders too would silently rewrite
    // several agents' balances.
    if ($action === 'delete_invoice' && $invoice_id > 0) {
        $s = $conn->prepare("UPDATE marketing_collateral_orders SET vendor_invoice_id = NULL WHERE vendor_invoice_id = ?");
        if ($s) { $s->bind_param('i', $invoice_id); $s->execute(); $s->close(); }
        $g = $conn->prepare("SELECT receipt_file FROM marketing_vendor_invoices WHERE id = ? LIMIT 1");
        if ($g) { $g->bind_param('i', $invoice_id); $g->execute();
                  $old = $g->get_result()->fetch_assoc(); $g->close();
                  os_unlink_receipt($old['receipt_file'] ?? null); }
        $d = $conn->prepare("DELETE FROM marketing_vendor_invoices WHERE id = ?");
        if ($d) { $d->bind_param('i', $invoice_id); $d->execute(); $d->close(); }
        header('Location: order_split.php?deleted=1'); exit;
    }

    if ($action === 'save_invoice') {
        // ── Gather ───────────────────────────────────────────────────────────
        foreach (['vendor','order_number','vendor_url','ordered_at','house_label','notes',
                  'tracking_number','tracking_url'] as $f) {
            $inv[$f] = trim((string)($_POST[$f] ?? ''));
        }
        $inv['status'] = in_array($_POST['status'] ?? '', OS_STATUSES, true)
                       ? (string)$_POST['status'] : 'ordered';
        foreach (['merch_subtotal','discount','rush','shipping','tax','other','grand_total','house_merch'] as $f) {
            $inv[$f] = os_num($_POST[$f] ?? '');
        }
        $inv['id'] = $invoice_id;

        $post_rows = (array)($_POST['row'] ?? []);
        foreach ($post_rows as $pr) {
            if (!empty($pr['delete'])) continue;
            $intake = (int)($pr['intake_id'] ?? 0);
            $merch  = os_num($pr['merch'] ?? '');
            // A row with neither an agent nor an amount is an untouched spare.
            if ($intake === 0 && abs($merch) < 0.005) continue;
            $rows[] = [
                'order_id'  => (int)($pr['order_id'] ?? 0),
                'intake_id' => $intake,
                'type'      => in_array($pr['type'] ?? '', $COLL_TYPES, true) ? $pr['type'] : 'other',
                'label'     => trim((string)($pr['label'] ?? '')),
                'qty'       => trim((string)($pr['qty'] ?? '')),
                'merch'     => $merch,
                'paid_by'   => in_array($pr['paid_by'] ?? '', ['broker','mont_haus'], true) ? $pr['paid_by'] : '',
            ];
        }

        // ── Validate ─────────────────────────────────────────────────────────
        //
        // Every one of these blocks the save. A figure that does not reconcile
        // must be REPORTED, not absorbed — the whole point of the page is that
        // the numbers it writes tie to a card statement.
        $line_merch = 0.0;
        foreach ($rows as $r) $line_merch += $r['merch'];
        $pool = $line_merch + (float)$inv['house_merch'];

        if (!$rows) {
            $error = 'Add at least one line and tag it to an agent.';
        } elseif (array_filter($rows, fn($r) => $r['intake_id'] === 0)) {
            $error = 'Every line needs an agent. Merchandise that belongs to nobody goes in the House line instead.';
        } elseif (abs($pool - (float)$inv['merch_subtotal']) >= 0.005) {
            $error = sprintf(
                'The lines come to %s but the merchandise subtotal says %s — a %s difference. '
              . 'Fix one or the other before saving.',
                os_money($pool), os_money((float)$inv['merch_subtotal']),
                os_money(abs($pool - (float)$inv['merch_subtotal']))
            );
        }

        if ($error === '') {
            $alloc_rows = [];
            foreach ($rows as $i => $r) $alloc_rows[] = ['key' => (string)$i, 'merch' => $r['merch']];
            if ((float)$inv['house_merch'] > 0) {
                $alloc_rows[] = ['key' => 'house', 'merch' => (float)$inv['house_merch']];
            }
            $charges = [
                'discount' => (float)$inv['discount'], 'rush'  => (float)$inv['rush'],
                'shipping' => (float)$inv['shipping'], 'tax'   => (float)$inv['tax'],
                'other'    => (float)$inv['other'],
                'grand_total' => (float)$inv['grand_total'],
            ];
            $alloc = mh_invoice_allocate($alloc_rows, $charges);

            if (!$alloc['reconciles']) {
                $error = sprintf(
                    'Subtotal + rush − discount + tax + shipping comes to %s, but the grand total says %s. '
                  . 'Those have to agree before anything is written to an agent.',
                    os_money($alloc['expected_total']), os_money($alloc['grand_total'])
                );
            }
        }

        // ── Write ────────────────────────────────────────────────────────────
        if ($error === '') {
            $rec = os_store_receipt();
            if ($rec && isset($rec['error'])) {
                $error = $rec['error'];
            } else {
                // A transaction because this writes several agents' costs. A
                // partial write here is the one outcome worse than a refusal:
                // two agents updated, one not, and nothing on screen saying so.
                // Empty tracking is NULL rather than '' — that is what "nothing
                // entered" means, and what the Collateral tab already stores.
                $trkn_inv = $inv['tracking_number'] !== '' ? $inv['tracking_number'] : null;
                $trku_inv = $inv['tracking_url']    !== '' ? $inv['tracking_url']    : null;

                $conn->begin_transaction();
                try {
                    // ── the invoice row ──
                    if ($invoice_id > 0) {
                        $sql = "UPDATE marketing_vendor_invoices SET
                                  vendor=?, order_number=?, vendor_url=?, ordered_at=?,
                                  merch_subtotal=?, discount=?, rush=?, shipping=?, tax=?, `other`=?,
                                  grand_total=?, house_merch=?, house_label=?, notes=?,
                                  status=?, tracking_number=?, tracking_url=?
                                WHERE id=?";
                        $s = $conn->prepare($sql);
                        if (!$s) throw new RuntimeException('Could not prepare the invoice update.');
                        $od = $inv['ordered_at'] !== '' ? $inv['ordered_at'] : null;
                        // 18 placeholders, 18 types:
                        //   ssss     vendor, order_number, vendor_url, ordered_at
                        //   dddddddd the eight money columns
                        //   ss       house_label, notes
                        //   sss      status, tracking_number, tracking_url
                        //   i        id
                        $s->bind_param('ssssddddddddsssssi',
                            $inv['vendor'], $inv['order_number'], $inv['vendor_url'], $od,
                            $inv['merch_subtotal'], $inv['discount'], $inv['rush'], $inv['shipping'],
                            $inv['tax'], $inv['other'], $inv['grand_total'], $inv['house_merch'],
                            $inv['house_label'], $inv['notes'],
                            $inv['status'], $trkn_inv, $trku_inv, $invoice_id);
                        $s->execute(); $s->close();
                    } else {
                        $sql = "INSERT INTO marketing_vendor_invoices
                                  (vendor, order_number, vendor_url, ordered_at,
                                   merch_subtotal, discount, rush, shipping, tax, `other`,
                                   grand_total, house_merch, house_label, notes,
                                   status, tracking_number, tracking_url, created_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                        $s = $conn->prepare($sql);
                        if (!$s) throw new RuntimeException('Could not prepare the invoice insert.');
                        $od = $inv['ordered_at'] !== '' ? $inv['ordered_at'] : null;
                        // 18 placeholders, 18 types — same shape as the update
                        // above but ending in created_by rather than id.
                        $s->bind_param('ssssddddddddsssssi',
                            $inv['vendor'], $inv['order_number'], $inv['vendor_url'], $od,
                            $inv['merch_subtotal'], $inv['discount'], $inv['rush'], $inv['shipping'],
                            $inv['tax'], $inv['other'], $inv['grand_total'], $inv['house_merch'],
                            $inv['house_label'], $inv['notes'],
                            $inv['status'], $trkn_inv, $trku_inv, $me);
                        $s->execute();
                        $invoice_id = (int)$conn->insert_id;
                        $s->close();
                    }
                    if ($invoice_id <= 0) throw new RuntimeException('The invoice did not save.');

                    // ── the receipt ──
                    //
                    // Only touched when a new file was uploaded. Re-saving to
                    // fix a figure must not wipe the receipt already attached.
                    if ($rec) {
                        $g = $conn->prepare("SELECT receipt_file FROM marketing_vendor_invoices WHERE id=? LIMIT 1");
                        $g->bind_param('i', $invoice_id); $g->execute();
                        $prev = $g->get_result()->fetch_assoc(); $g->close();
                        if (!empty($prev['receipt_file']) && $prev['receipt_file'] !== $rec['file']) {
                            os_unlink_receipt($prev['receipt_file']);
                        }
                        $u = $conn->prepare("UPDATE marketing_vendor_invoices
                                                SET receipt_file=?, receipt_orig_name=?, receipt_uploaded_at=NOW()
                                              WHERE id=?");
                        $u->bind_param('ssi', $rec['file'], $rec['orig'], $invoice_id);
                        $u->execute(); $u->close();
                        $inv['receipt_file']      = $rec['file'];
                        $inv['receipt_orig_name'] = $rec['orig'];
                    }

                    // ── the agent orders ──
                    $keep = [];
                    foreach ($rows as $i => $r) {
                        $a      = $alloc['rows'][(string)$i];
                        $landed = $a['landed'];
                        $oid    = $r['order_id'];
                        $paid   = $r['paid_by'] !== '' ? $r['paid_by'] : null;
                        $od     = $inv['ordered_at'] !== '' ? $inv['ordered_at'] : null;
                        $lab    = $r['label'] !== '' ? $r['label'] : null;
                        $qty    = $r['qty']   !== '' ? $r['qty']   : null;

                        // Only adopt an order that is already ours. An id posted
                        // for someone else's order, or for one using
                        // billed_with_order_id, is treated as new rather than
                        // quietly overwritten.
                        $adoptable = false;
                        if ($oid > 0) {
                            $c = $conn->prepare("SELECT intake_id, vendor_invoice_id, billed_with_order_id
                                                   FROM marketing_collateral_orders WHERE id=? LIMIT 1");
                            $c->bind_param('i', $oid); $c->execute();
                            $ex = $c->get_result()->fetch_assoc(); $c->close();
                            $adoptable = $ex
                                && (int)$ex['intake_id'] === $r['intake_id']
                                && (int)($ex['vendor_invoice_id'] ?? 0) === $invoice_id
                                && empty($ex['billed_with_order_id']);
                        }

                        if ($adoptable) {
                            // status and tracking_* ARE written, because the form
                            // loaded this order's current values into the row —
                            // see the reopen query below. delivered_at, file_url
                            // and notes are still absent: they are not on this
                            // form, so writing them would blank them.
                            $u = $conn->prepare(
                                "UPDATE marketing_collateral_orders SET
                                    type=?, label=?, qty=?, cost=?, merch_amount=?,
                                    vendor=?, vendor_url=?, order_number=?, ordered_at=?,
                                    paid_by=?, status=?, tracking_number=?, tracking_url=?,
                                    billed_with_order_id=NULL
                                 WHERE id=? AND intake_id=?");
                            if (!$u) throw new RuntimeException('Could not prepare the order update.');
                            // 15 placeholders, 15 types, counted twice:
                            //   sss  type, label, qty
                            //   dd   cost, merch_amount
                            //   ssss vendor, vendor_url, order_number, ordered_at
                            //   s    paid_by          ← an enum, NOT an integer
                            //   sss  status, tracking_number, tracking_url
                            //   ii   id, intake_id
                            // Miscounted type strings have bitten this project
                            // more than once, and binding paid_by as 'i' would
                            // silently store 0 for 'broker'.
                            $u->bind_param('sssddssssssssii',
                                $r['type'], $lab, $qty, $landed, $r['merch'],
                                $inv['vendor'], $inv['vendor_url'], $inv['order_number'], $od,
                                $paid, $inv['status'], $trkn_inv, $trku_inv,
                                $oid, $r['intake_id']);
                            if (!$u->execute()) throw new RuntimeException('An order failed to update.');
                            $u->close();
                        } else {
                            $note = mh_invoice_row_note((string)$inv['vendor'],
                                                        (string)$inv['order_number'], $r['merch']);
                            $ins = $conn->prepare(
                                "INSERT INTO marketing_collateral_orders
                                   (intake_id, type, label, qty, cost, merch_amount,
                                    vendor, vendor_url, order_number, ordered_at,
                                    paid_by, status, tracking_number, tracking_url,
                                    notes, vendor_invoice_id)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                            if (!$ins) throw new RuntimeException('Could not prepare the order insert.');
                            // 16 placeholders, 16 types:
                            //   i    intake_id
                            //   sss  type, label, qty
                            //   dd   cost, merch_amount
                            //   ssss vendor, vendor_url, order_number, ordered_at
                            //   s    paid_by
                            //   sss  status, tracking_number, tracking_url
                            //   s    notes
                            //   i    vendor_invoice_id
                            $ins->bind_param('isssddsssssssssi',
                                $r['intake_id'], $r['type'], $lab, $qty, $landed, $r['merch'],
                                $inv['vendor'], $inv['vendor_url'], $inv['order_number'], $od,
                                $paid, $inv['status'], $trkn_inv, $trku_inv,
                                $note, $invoice_id);
                            if (!$ins->execute()) throw new RuntimeException('An order failed to save.');
                            $oid = (int)$conn->insert_id;
                            $ins->close();
                            if ($oid <= 0) throw new RuntimeException('An order saved without an id.');
                        }

                        // Each order gets its OWN copy of the receipt — see
                        // os_copy_receipt_for_order() for why not a shared one.
                        if ($rec && $oid > 0) {
                            $g = $conn->prepare("SELECT receipt_file FROM marketing_collateral_orders WHERE id=? LIMIT 1");
                            $g->bind_param('i', $oid); $g->execute();
                            $prev = $g->get_result()->fetch_assoc(); $g->close();
                            os_unlink_receipt($prev['receipt_file'] ?? null);
                            $copy = os_copy_receipt_for_order($rec['file']);
                            if ($copy !== null) {
                                $u = $conn->prepare("UPDATE marketing_collateral_orders
                                                        SET receipt_file=?, receipt_orig_name=?, receipt_uploaded_at=NOW()
                                                      WHERE id=?");
                                $u->bind_param('ssi', $copy, $rec['orig'], $oid);
                                $u->execute(); $u->close();
                            }
                        }
                        $keep[] = $oid;
                    }

                    // Rows removed from the form. Unlinked and zeroed rather
                    // than deleted — the order was really placed, and deleting
                    // it would take its history with it. A $0 order shows on the
                    // Collateral tab where it can be dealt with deliberately.
                    $ph = $keep ? implode(',', array_fill(0, count($keep), '?')) : '';
                    $sql = "UPDATE marketing_collateral_orders
                               SET vendor_invoice_id = NULL, cost = 0.00
                             WHERE vendor_invoice_id = ?"
                         . ($ph ? " AND id NOT IN ({$ph})" : '');
                    $d = $conn->prepare($sql);
                    if ($d) {
                        $d->bind_param(str_repeat('i', 1 + count($keep)), $invoice_id, ...$keep);
                        $d->execute(); $d->close();
                    }

                    $conn->commit();
                    header("Location: order_split.php?id={$invoice_id}&saved=1"); exit;

                } catch (Throwable $e) {
                    $conn->rollback();
                    // The receipt was written to disk before the transaction, so
                    // it has to be cleaned up by hand on a rollback.
                    if ($rec) os_unlink_receipt($rec['file']);
                    $error = 'Nothing was saved — ' . $e->getMessage()
                           . ' No agent was changed.';
                }
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOAD
// ═══════════════════════════════════════════════════════════════════════════════
$agents        = [];
$invoice_list  = [];
$orphan_note   = '';

if ($schema_ready) {
    // Staff (leadership_v1.sql) are never billed, so never offered here.
    $no_staff = (($c = $conn->query("SHOW COLUMNS FROM marketing_intakes LIKE 'entity_type'")) && $c->fetch_row())
              ? "WHERE entity_type <> 'staff'" : '';
    $r = $conn->query("SELECT id, agent_name, is_active FROM marketing_intakes {$no_staff} ORDER BY agent_name ASC");
    if ($r) while ($a = $r->fetch_assoc()) $agents[(int)$a['id']] = $a;

    $r = $conn->query("
        SELECT v.id, v.vendor, v.order_number, v.ordered_at, v.grand_total, v.merch_subtotal,
               COUNT(o.id) AS n_orders
          FROM marketing_vendor_invoices v
          LEFT JOIN marketing_collateral_orders o ON o.vendor_invoice_id = v.id
         GROUP BY v.id
         ORDER BY v.ordered_at DESC, v.id DESC
         LIMIT 40");
    if ($r) while ($v = $r->fetch_assoc()) $invoice_list[] = $v;

    // Load an existing invoice, unless we are re-rendering a rejected POST — in
    // that case $inv/$rows already hold what was typed and reloading would throw
    // the correction away.
    if ($invoice_id > 0 && $error === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $s = $conn->prepare("SELECT * FROM marketing_vendor_invoices WHERE id=? LIMIT 1");
        if ($s) {
            $s->bind_param('i', $invoice_id); $s->execute();
            $got = $s->get_result()->fetch_assoc(); $s->close();
            if ($got) {
                $inv = array_merge($inv, $got);
                // Fulfilment is not read back per order — it belongs to the
                // invoice row, which was loaded above, and is copied onto every
                // order on save.
                $q = $conn->prepare(
                    "SELECT id, intake_id, type, label, qty, cost, merch_amount, paid_by
                       FROM marketing_collateral_orders
                      WHERE vendor_invoice_id = ?
                      ORDER BY id ASC");
                $q->bind_param('i', $invoice_id); $q->execute();
                $res = $q->get_result();
                while ($o = $res->fetch_assoc()) {
                    $rows[] = [
                        'order_id'  => (int)$o['id'],
                        'intake_id' => (int)$o['intake_id'],
                        'type'      => (string)$o['type'],
                        'label'     => (string)($o['label'] ?? ''),
                        'qty'       => (string)($o['qty'] ?? ''),
                        'merch'     => (float)($o['merch_amount'] ?? 0),
                        'paid_by'   => (string)($o['paid_by'] ?? ''),
                    ];
                }
                $q->close();

                // An order deleted or re-costed on the Collateral tab after the
                // split leaves the invoice unable to reconcile. Say so here
                // rather than letting the totals look wrong for no visible
                // reason — this is the one thing about the page that can drift.
                $line = 0.0;
                foreach ($rows as $rr) $line += $rr['merch'];
                $pool = $line + (float)$inv['house_merch'];
                if (abs($pool - (float)$inv['merch_subtotal']) >= 0.005) {
                    $orphan_note = sprintf(
                        'The lines below come to %s but this invoice was saved with a %s subtotal. '
                      . 'An order was probably edited or deleted on a Collateral tab since the split. '
                      . 'Correct the lines and save again.',
                        os_money($pool), os_money((float)$inv['merch_subtotal'])
                    );
                }
            } else {
                $invoice_id = 0;
            }
        }
    }
}

// One spare row to type into, always — plus enough to fill out a new invoice.
// Written as two statements rather than one clever condition: the obvious
// version, `count($rows) < max(3, count($rows) + 1)`, never terminates.
$rows[] = os_blank_row();
while (count($rows) < 4) $rows[] = os_blank_row();

// Server-computed allocation for display. This — not the JavaScript — is what
// was written to the agents.
$alloc_rows = [];
foreach ($rows as $i => $r) {
    if ($r['intake_id'] === 0 && abs((float)$r['merch']) < 0.005) continue;
    $alloc_rows[] = ['key' => (string)$i, 'merch' => (float)$r['merch']];
}
if ((float)$inv['house_merch'] > 0) $alloc_rows[] = ['key' => 'house', 'merch' => (float)$inv['house_merch']];
$view = mh_invoice_allocate($alloc_rows, [
    'discount' => (float)$inv['discount'], 'rush' => (float)$inv['rush'],
    'shipping' => (float)$inv['shipping'], 'tax'  => (float)$inv['tax'],
    'other'    => (float)$inv['other'],    'grand_total' => (float)$inv['grand_total'],
]);

$nav_active = 'split';
$page_title = $invoice_id > 0 ? 'Split an Invoice — ' . ($inv['vendor'] ?: 'Invoice') : 'Split an Invoice';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= os_e($page_title) ?> — Mont Haus Marketing</title>
  <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root { --bs-primary: #0184BB; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    /* Overriding `top` on .pc-container leaves a phantom black gap — see CLAUDE.md */
    .pc-container { margin-left:0; top:0; margin-top:70px; min-height:calc(100vh - 70px); }
    .mh-header-inner {
      width:100%; padding:0 15px;
      display:flex; align-items:center; justify-content:space-between; height:70px;
    }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link {
      display:inline-flex; align-items:center; gap:5px;
      padding:5px 12px; font-size:.8rem; font-weight:500;
      color:#75BDB6; text-decoration:none; border-radius:6px;
      white-space:nowrap; transition:color .2s, background .2s;
    }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }

    body { font-family:'Public Sans',sans-serif; background:#f8f9fa; }
    .wrap { max-width:1180px; margin:0 auto; padding:24px 18px 60px; }

    .os-head h1 { font-size:22px; font-weight:700; color:#1f2937; margin:0; }
    .os-head p  { margin:4px 0 0; font-size:13px; color:#6b7280; max-width:70ch; }
    .os-head { display:flex; align-items:flex-end; justify-content:space-between;
               gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .os-head-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

    .os-card { background:#fff; border:1px solid #e9ecef; border-radius:10px;
               margin-bottom:14px; overflow:hidden; }
    .os-card > h2 { font-size:12px; font-weight:700; text-transform:uppercase;
                    letter-spacing:.5px; color:#6b7280; margin:0;
                    padding:12px 16px; border-bottom:1px solid #f3f4f6; }
    .os-card-body { padding:14px 16px; }

    .os-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
    .os-field { display:flex; flex-direction:column; gap:4px; }
    .os-field > span { font-size:11px; font-weight:700; text-transform:uppercase;
                       letter-spacing:.4px; color:#6b7280; }
    .os-field input, .os-field select, .os-field textarea {
      width:100%; padding:7px 9px; font-size:13px; font-family:inherit;
      border:1px solid #dee2e6; border-radius:6px; background:#fff; color:#1f2937;
    }
    .os-field input:focus, .os-field select:focus { outline:2px solid #0184BB33; border-color:#0184BB; }
    .os-field small { font-size:11px; color:#9ca3af; font-weight:400; }

    /* The line table. Scrolls inside itself rather than pushing the page wide. */
    .os-table-scroll { overflow-x:auto; }
    /* 1140px is the sum of the nine column floors in the thead plus their
       8px-a-side padding. It has to be at least that, or the table compresses
       to this figure and steals the difference from whichever column has the
       narrowest content. Recompute it when a column is added or resized —
       a stale 940px here is what crushed Qty to about 20px. */
    table.os-lines { width:100%; border-collapse:collapse; min-width:1140px; }
    .os-lines th { font-size:10px; font-weight:700; text-transform:uppercase;
                   letter-spacing:.4px; color:#9ca3af; text-align:left;
                   padding:8px 8px; border-bottom:1px solid #f3f4f6; white-space:nowrap; }
    .os-lines td { padding:6px 8px; border-bottom:1px solid #f8f9fa; vertical-align:middle; }
    .os-lines tr:last-child td { border-bottom:none; }
    .os-lines input, .os-lines select {
      width:100%; padding:6px 8px; font-size:13px; font-family:inherit;
      border:1px solid #dee2e6; border-radius:6px; background:#fff;
    }
    .os-lines .num  { text-align:right; }
    .os-lines .calc { font-size:13px; font-weight:600; color:#1f2937; text-align:right;
                      white-space:nowrap; }
    .os-lines .share { font-size:12px; color:#6b7280; text-align:right; white-space:nowrap; }
    .os-lines tr.house-row { background:#f8f9fa; }
    .os-lines tr.house-row td { color:#6b7280; }
    .os-rm { background:none; border:none; color:#c0c4cc; cursor:pointer; font-size:16px;
             line-height:1; padding:4px; }
    .os-rm:hover { color:#b91c1c; }

    /* Reconciliation strip. Green when it ties, red when it does not — this is
       the one thing on the page that must be readable at a glance, because it
       is what stands between a typo and three wrong agent balances. */
    .os-check { display:flex; gap:10px; flex-wrap:wrap; margin:14px 0 4px; }
    .os-chip { flex:1; min-width:190px; border-radius:8px; padding:10px 13px;
               border:1px solid #e9ecef; background:#fff; }
    .os-chip .k { font-size:10px; font-weight:700; text-transform:uppercase;
                  letter-spacing:.5px; color:#9ca3af; }
    .os-chip .v { font-size:17px; font-weight:700; margin-top:3px; color:#1f2937; }
    .os-chip .n { font-size:11px; color:#9ca3af; margin-top:2px; }
    .os-chip.ok  { background:#f0fdf4; border-color:#bbf7d0; }
    .os-chip.ok  .v { color:#166534; }
    .os-chip.bad { background:#fef2f2; border-color:#fecaca; }
    .os-chip.bad .v { color:#991b1b; }

    .os-alert { border-radius:8px; padding:11px 14px; font-size:13px; margin-bottom:14px;
                border:1px solid; }
    .os-alert.err  { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .os-alert.warn { background:#fff7ed; border-color:#fed7aa; color:#9a3412; }
    .os-alert.good { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }

    .os-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap;
                  margin-top:16px; }
    .os-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 16px;
              font-size:13px; font-weight:600; border-radius:7px; border:1px solid transparent;
              cursor:pointer; text-decoration:none; font-family:inherit; }
    .os-btn.primary { background:#0184BB; color:#fff; }
    .os-btn.primary:hover { background:#016d9c; }
    .os-btn.ghost   { background:#fff; color:#374151; border-color:#dee2e6; }
    .os-btn.ghost:hover { background:#f8f9fa; }
    .os-btn.danger  { background:#fff; color:#b91c1c; border-color:#fecaca; }
    .os-btn.danger:hover { background:#fef2f2; }

    .os-list { display:flex; flex-direction:column; }
    .os-list a { display:flex; align-items:center; gap:12px; padding:10px 16px;
                 border-bottom:1px solid #f8f9fa; text-decoration:none; color:#374151;
                 font-size:13px; flex-wrap:wrap; }
    .os-list a:last-child { border-bottom:none; }
    .os-list a:hover { background:#f8f9fa; }
    .os-list .vn { font-weight:700; color:#01679A; }
    .os-list .sp { flex:1; }
    .os-list .tot { font-weight:700; }
    .os-empty { padding:32px 16px; text-align:center; color:#9ca3af; font-size:13px; }

    .os-note { font-size:12px; color:#9ca3af; margin-top:14px; max-width:80ch; }
    .os-note code { background:#f3f4f6; padding:1px 5px; border-radius:4px; font-size:11px; }

    /* ── Mobile ──────────────────────────────────────────────────────────────
       Additive only, inside a max-width query, and placed at the very END of
       this block so it beats the rules above it — a mobile block written next
       to the rules it overrides loses on source order, which is exactly how
       agent.php's tab block failed once already. See CLAUDE.md. */
    @media (max-width: 820px) {
      .wrap { padding:16px 12px 48px; }
      .os-head { align-items:flex-start; }
      .os-chip { min-width:calc(50% - 5px); }
      .os-actions .os-btn { flex:1; justify-content:center; }
    }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

<?php if (!$schema_ready): ?>
    <?php // Deploying this page before its migration would otherwise fail
          // silently — an empty list and no explanation. See inc/schema.php. ?>
    <div class="os-head"><div><h1>Split an Invoice</h1></div></div>
    <div class="os-alert err">
      <strong>This page needs a migration that has not been run yet.</strong><br>
      Run <code><?= os_e($schema_missing) ?></code> in TablePlus, then reload. Until then
      nothing on this page will work, and no other page is affected.
      <?php if ($schema_v1): ?>
        <br><small style="opacity:.85;">The invoice table is there; only the fulfilment
        columns are missing. Re-running <code>v1</code> will not add them — it is a
        <code>CREATE TABLE IF NOT EXISTS</code> and skips entirely once the table
        exists.</small>
      <?php endif; ?>
    </div>
<?php else: ?>

    <div class="os-head">
      <div>
        <h1>Split an Invoice</h1>
        <p>One vendor invoice, several agents. Enter the totals once and each agent's
           collateral order gets its true landed cost — merchandise plus its share of
           the discount, rush, shipping and tax.</p>
      </div>
      <div class="os-head-actions">
        <?php if ($invoice_id > 0): ?>
          <a class="os-btn ghost" href="order_split.php"><i class="ti ti-plus"></i> New invoice</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($saved): ?>
      <div class="os-alert good">
        <i class="ti ti-check"></i> Saved. Each agent's collateral order below now carries its landed cost,
        and their Financials, Billing and roster balance all reflect it.
      </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
      <div class="os-alert good">
        <i class="ti ti-check"></i> Invoice deleted. The collateral orders it created were kept and unlinked —
        they still carry the cost they were allocated.
      </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="os-alert err"><i class="ti ti-alert-triangle"></i> <?= os_e($error) ?></div>
    <?php endif; ?>
    <?php if ($orphan_note !== ''): ?>
      <div class="os-alert warn"><i class="ti ti-alert-triangle"></i> <?= os_e($orphan_note) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="osForm">
      <input type="hidden" name="csrf_token" value="<?= os_e($CSRF) ?>">
      <input type="hidden" name="_action" value="save_invoice">
      <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">

      <!-- ── The invoice ─────────────────────────────────────────────────── -->
      <div class="os-card">
        <h2>1 · The invoice, exactly as it prints</h2>
        <div class="os-card-body">
          <div class="os-grid" style="margin-bottom:12px;">
            <label class="os-field"><span>Vendor</span>
              <input type="text" name="vendor" value="<?= os_e($inv['vendor']) ?>" placeholder="Oakley Signs &amp; Graphics"></label>
            <label class="os-field"><span>Order #</span>
              <input type="text" name="order_number" value="<?= os_e($inv['order_number']) ?>" placeholder="IND-650292"></label>
            <label class="os-field"><span>Order date</span>
              <input type="date" name="ordered_at" value="<?= os_e($inv['ordered_at']) ?>"></label>
            <label class="os-field"><span>Vendor / order URL</span>
              <input type="url" name="vendor_url" value="<?= os_e($inv['vendor_url']) ?>" placeholder="https://…"></label>
          </div>

          <div class="os-grid">
            <label class="os-field"><span>Merchandise subtotal</span>
              <input type="text" inputmode="decimal" name="merch_subtotal" data-money
                     value="<?= $inv['merch_subtotal'] !== '' ? os_e(number_format((float)$inv['merch_subtotal'], 2, '.', '')) : '' ?>">
              <small>Before any discount</small></label>
            <label class="os-field"><span>Rush / expedite</span>
              <input type="text" inputmode="decimal" name="rush" data-money
                     value="<?= $inv['rush'] !== '' ? os_e(number_format((float)$inv['rush'], 2, '.', '')) : '' ?>"></label>
            <label class="os-field"><span>Promo discount</span>
              <input type="text" inputmode="decimal" name="discount" data-money
                     value="<?= $inv['discount'] !== '' ? os_e(number_format((float)$inv['discount'], 2, '.', '')) : '' ?>">
              <small>Enter positive — it is subtracted</small></label>
            <label class="os-field"><span>Sales tax</span>
              <input type="text" inputmode="decimal" name="tax" data-money
                     value="<?= $inv['tax'] !== '' ? os_e(number_format((float)$inv['tax'], 2, '.', '')) : '' ?>"></label>
            <label class="os-field"><span>Shipping</span>
              <input type="text" inputmode="decimal" name="shipping" data-money
                     value="<?= $inv['shipping'] !== '' ? os_e(number_format((float)$inv['shipping'], 2, '.', '')) : '' ?>"></label>
            <label class="os-field"><span>Other</span>
              <input type="text" inputmode="decimal" name="other" data-money
                     value="<?= $inv['other'] !== '' ? os_e(number_format((float)$inv['other'], 2, '.', '')) : '' ?>"></label>
            <label class="os-field"><span>Grand total charged</span>
              <input type="text" inputmode="decimal" name="grand_total" data-money
                     value="<?= $inv['grand_total'] !== '' ? os_e(number_format((float)$inv['grand_total'], 2, '.', '')) : '' ?>">
              <small>What hit the card</small></label>
          </div>

          <?php /* Fulfilment, once for the whole invoice. One invoice is one
                   shipment — a status and tracking number per agent line would
                   be the same value typed several times and free to disagree
                   with itself. These are copied onto every collateral order the
                   split writes, so the Collateral tab still shows tracking per
                   order; it is entered once and fans out. */ ?>
          <div class="os-grid" style="margin-top:12px;">
            <label class="os-field"><span>Status</span>
              <select name="status">
                <?php foreach (OS_STATUSES as $st): ?>
                  <option value="<?= os_e($st) ?>" <?= $inv['status'] === $st ? 'selected' : '' ?>>
                    <?= os_e(os_status_label($st)) ?></option>
                <?php endforeach; ?>
              </select>
              <small>Applied to every order on this invoice</small></label>
            <label class="os-field"><span>Tracking #</span>
              <input type="text" name="tracking_number" value="<?= os_e($inv['tracking_number']) ?>"
                     placeholder="Optional">
              <small>Optional</small></label>
            <label class="os-field" style="grid-column:span 2;"><span>Tracking URL</span>
              <input type="url" name="tracking_url" value="<?= os_e($inv['tracking_url']) ?>"
                     placeholder="https://…"></label>
          </div>

          <div class="os-grid" style="margin-top:12px;">
            <label class="os-field" style="grid-column:span 2;"><span>Receipt</span>
              <input type="file" name="receipt" accept="application/pdf,image/jpeg,image/png">
              <small>
                PDF, JPG or PNG, max 10 MB. Every agent's order gets its own copy.
                <?php if (!empty($inv['receipt_orig_name']) && $invoice_id > 0): ?>
                  Currently
                  <a href="receipt.php?invoice_id=<?= (int)$invoice_id ?>" target="_blank" rel="noopener noreferrer">
                    <?= os_e($inv['receipt_orig_name']) ?></a> — uploading replaces it.
                <?php endif; ?>
              </small></label>
            <label class="os-field" style="grid-column:span 2;"><span>Notes</span>
              <input type="text" name="notes" value="<?= os_e($inv['notes']) ?>"
                     placeholder="Anything worth remembering about this order"></label>
          </div>
        </div>
      </div>

      <!-- ── The lines ───────────────────────────────────────────────────── -->
      <div class="os-card">
        <h2>2 · Who gets what</h2>
        <div class="os-table-scroll">
          <table class="os-lines" id="osLines">
            <thead>
              <tr>
                <?php /* Every sizing hint here is min-width, never width.
                         `width` is a suggestion the table overrules to make
                         columns fit, and it takes the space from whichever
                         column holds the narrowest content — which is Qty. A
                         `width:70px` Qty column was squeezed to about 20px.
                         min-width is a floor, so the table overflows into
                         .os-table-scroll instead of crushing a cell. If you add
                         a column, raise table.os-lines { min-width } to match
                         the new sum.

                         Status and tracking are NOT columns here. One invoice
                         is one shipment, so they live once in the header card
                         above and are copied onto every order this page
                         writes. */ ?>
                <th style="min-width:164px;">Agent</th>
                <th style="min-width:140px;">Type</th>
                <th style="min-width:140px;">Label</th>
                <th style="min-width:86px;">Qty</th>
                <th style="min-width:112px;" class="num">Merchandise</th>
                <th style="min-width:130px;">Who pays</th>
                <th style="min-width:70px;" class="num">Share</th>
                <th style="min-width:120px;" class="num">Landed cost</th>
                <th style="min-width:34px;"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                    $a = $view['rows'][(string)$i] ?? null; ?>
              <tr data-row>
                <td>
                  <input type="hidden" name="row[<?= $i ?>][order_id]" value="<?= (int)$r['order_id'] ?>">
                  <select name="row[<?= $i ?>][intake_id]">
                    <option value="0">— pick an agent —</option>
                    <?php foreach ($agents as $aid => $ag): ?>
                      <option value="<?= (int)$aid ?>" <?= (int)$r['intake_id'] === (int)$aid ? 'selected' : '' ?>>
                        <?= os_e($ag['agent_name']) ?><?= empty($ag['is_active']) ? ' (archived)' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <select name="row[<?= $i ?>][type]">
                    <?php foreach ($COLL_TYPES as $t): ?>
                      <option value="<?= os_e($t) ?>" <?= $r['type'] === $t ? 'selected' : '' ?>>
                        <?= os_e(mk_coll_type_label($t)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input type="text" name="row[<?= $i ?>][label]" value="<?= os_e($r['label']) ?>" placeholder="e.g. Open House set"></td>
                <td><input type="text" name="row[<?= $i ?>][qty]" value="<?= os_e($r['qty']) ?>" placeholder="5"></td>
                <td><input type="text" inputmode="decimal" class="num" data-merch
                           name="row[<?= $i ?>][merch]"
                           value="<?= abs((float)$r['merch']) > 0.0001 ? os_e(number_format((float)$r['merch'], 2, '.', '')) : '' ?>"
                           placeholder="0.00"></td>
                <td>
                  <select name="row[<?= $i ?>][paid_by]">
                    <option value=""          <?= $r['paid_by'] === ''          ? 'selected' : '' ?>>— not decided —</option>
                    <option value="broker"    <?= $r['paid_by'] === 'broker'    ? 'selected' : '' ?>>Broker</option>
                    <option value="mont_haus" <?= $r['paid_by'] === 'mont_haus' ? 'selected' : '' ?>>Mont Haus</option>
                  </select>
                </td>
                <td class="share" data-share><?= $a ? number_format($a['share'] * 100, 2) . '%' : '—' ?></td>
                <td class="calc"  data-landed><?= $a ? os_money($a['landed']) : '—' ?></td>
                <td style="text-align:center;">
                  <button type="button" class="os-rm" data-rm title="Remove this line">&times;</button>
                </td>
              </tr>
            <?php endforeach; ?>

              <!-- The house line. Merchandise that belongs to no agent — generic
                   office inventory like unbranded Open House signs.

                   It is in the allocation pool but is never written to anybody.
                   Leaving it OUT of the pool would make the agents absorb the
                   freight and tax on signs that were never theirs: on
                   IND-650292 that would have charged two agents 74% more than
                   they owe. Only the merchandise figure is stored, so the
                   invoice can be reopened and still reconcile. -->
              <tr class="house-row">
                <td colspan="2"><strong style="font-size:13px;">Mont Haus — house</strong>
                  <div style="font-size:11px;">Generic stock, not billed to anyone</div></td>
                <td colspan="2"><input type="text" name="house_label" value="<?= os_e($inv['house_label']) ?>"
                                       placeholder="e.g. 20 unbranded Open House signs"></td>
                <td><input type="text" inputmode="decimal" class="num" data-merch data-house
                           name="house_merch"
                           value="<?= (float)$inv['house_merch'] > 0.0001 ? os_e(number_format((float)$inv['house_merch'], 2, '.', '')) : '' ?>"
                           placeholder="0.00"></td>
                <td style="font-size:11px;">never billed — no order is created</td>
                <td class="share" data-share="house"><?= isset($view['rows']['house']) ? number_format($view['rows']['house']['share'] * 100, 2) . '%' : '—' ?></td>
                <td class="calc"  data-landed="house"><?= isset($view['rows']['house']) ? os_money($view['rows']['house']['landed']) : '—' ?></td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="os-card-body" style="border-top:1px solid #f3f4f6;">
          <button type="button" class="os-btn ghost" id="osAdd"><i class="ti ti-plus"></i> Add a line</button>
        </div>
      </div>

      <!-- ── Reconciliation ──────────────────────────────────────────────── -->
      <div class="os-check">
        <div class="os-chip" id="chipLines">
          <div class="k">Lines + house</div>
          <div class="v" data-v>—</div>
          <div class="n">must equal the merchandise subtotal</div>
        </div>
        <div class="os-chip" id="chipRecon">
          <div class="k">Subtotal + rush − disc + tax + ship</div>
          <div class="v" data-v>—</div>
          <div class="n">must equal the grand total charged</div>
        </div>
        <div class="os-chip" id="chipFactor">
          <div class="k">Landed cost factor</div>
          <div class="v" data-v>—</div>
          <div class="n">what $1.00 of sticker price really costs</div>
        </div>
      </div>

      <div class="os-actions">
        <button type="submit" class="os-btn primary"><i class="ti ti-device-floppy"></i>
          <?= $invoice_id > 0 ? 'Save and re-split' : 'Save and write to the agents' ?></button>
        <?php if ($invoice_id > 0): ?>
          <a class="os-btn ghost" href="order_split.php"><i class="ti ti-x"></i> Cancel</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($invoice_id > 0): ?>
      <?php // Its own form. A nested <form> is invalid HTML, the browser drops
            // the inner one and the button would post nothing — the same trap
            // the month grid documents in CLAUDE.md. ?>
      <form method="post" style="margin-top:10px;"
            onsubmit="return confirm('Delete this invoice record? The collateral orders it created are KEPT and keep their costs — only the invoice and the links go.');">
        <input type="hidden" name="csrf_token" value="<?= os_e($CSRF) ?>">
        <input type="hidden" name="_action" value="delete_invoice">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
        <button type="submit" class="os-btn danger"><i class="ti ti-trash"></i> Delete this invoice record</button>
      </form>
    <?php endif; ?>

    <!-- ── Recent invoices ───────────────────────────────────────────────── -->
    <div class="os-card" style="margin-top:22px;">
      <h2>Recent split invoices</h2>
      <?php if (!$invoice_list): ?>
        <div class="os-empty">Nothing split yet. Fill the form above and save.</div>
      <?php else: ?>
        <div class="os-list">
          <?php foreach ($invoice_list as $v): ?>
            <a href="order_split.php?id=<?= (int)$v['id'] ?>">
              <span class="vn"><?= os_e($v['vendor'] ?: 'Invoice') ?></span>
              <span><?= os_e($v['order_number'] ?: '') ?></span>
              <span style="color:#9ca3af;"><?= os_e($v['ordered_at'] ? date('j M Y', strtotime($v['ordered_at'])) : '') ?></span>
              <span class="sp"></span>
              <span style="color:#6b7280;"><?= (int)$v['n_orders'] ?> order<?= (int)$v['n_orders'] === 1 ? '' : 's' ?></span>
              <span class="tot"><?= os_money((float)$v['grand_total']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <p class="os-note">
      The figures above are a live preview. What gets saved is recomputed on the server by
      <code>mh_invoice_allocate()</code> in <code>inc/invoice_split.php</code>, and the page
      redisplays the server's numbers after a save. Read that file's header for why each charge
      is split pro-rata and why tax needs no rate; <code>php tests/test_invoice_split.php</code>
      checks the arithmetic.
    </p>

<?php endif; ?>

  </div>
</div>

<script>
/* ─────────────────────────────────────────────────────────────────────────────
   LIVE PREVIEW ONLY.

   This is a transliteration of mh_invoice_allocate() in inc/invoice_split.php,
   kept here so the figures move as you type — which a server round-trip per
   keystroke cannot do. It decides NOTHING. Save recomputes everything in PHP,
   and the page then redisplays the server's numbers.

   Two implementations of money is exactly what inc/financials.php exists to
   avoid, so this one is deliberately a straight line-for-line copy: same
   rounding, same order of operations, same residual rule. If you change the PHP,
   change this in the same commit, and run:

       php tests/test_invoice_split.php

   The PHP is the one that is tested, and the one that is right.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
  var form = document.getElementById('osForm');
  if (!form) return;

  var money = function (n) {
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  var num = function (el) {
    if (!el) return 0;
    var v = String(el.value || '').replace(/[^0-9.\-]/g, '');
    var f = parseFloat(v);
    return isNaN(f) ? 0 : f;
  };
  /* PHP's round() is half-away-from-zero. JS Math.round is half-up, which
     differs on negatives — and the discount line is always negative. */
  var r2 = function (n) {
    var s = n < 0 ? -1 : 1;
    return s * Math.round(Math.abs(n) * 100 + 1e-9) / 100;
  };
  var f = function (name) { return form.querySelector('[name="' + name + '"]'); };

  /* Show every money box to two decimals once it loses focus, so a typed 128
     reads as 128.00 and a typed 25.6 as 25.60. PHP already renders stored
     figures this way; this is only about what a freshly typed one looks like.

     On focusout, never on input — rewriting the value under a cursor sends the
     caret to the end on every keystroke, which makes the field unusable.

     toFixed, not toLocaleString: the value is posted back and parsed by
     os_num(), so it has to stay a plain decimal. A thousands separator would
     look right and submit wrong.

     Two things are deliberately left alone. A blank field stays blank — the
     placeholder reads 0.00 already, and writing a real 0.00 into every box a
     user tabbed past makes an untouched invoice look filled in. And a value
     that does not parse at all is left exactly as typed, so a fat-fingered
     entry stays visible instead of silently becoming 0.00. */
  function normaliseMoney(el) {
    var raw = String(el.value || '').trim();
    if (raw === '') return;
    var n = parseFloat(raw.replace(/[^0-9.\-]/g, ''));
    if (isNaN(n)) return;
    el.value = n.toFixed(2);
  }

  /* focusout, not blur — blur does not bubble, so a delegated listener would
     never fire. Delegation is what makes this reach rows added after load. */
  form.addEventListener('focusout', function (e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('[data-money], [data-merch]')) return;
    normaliseMoney(el);
    recalc();
  });

  function recalc() {
    var discount = num(f('discount')), rush = num(f('rush')),
        shipping = num(f('shipping')), tax = num(f('tax')),
        other    = num(f('other')),    grand = num(f('grand_total')),
        subtotal = num(f('merch_subtotal')), house = num(f('house_merch'));

    var trs = Array.prototype.slice.call(form.querySelectorAll('tr[data-row]'));
    var parts = [];
    trs.forEach(function (tr) {
      parts.push({ el: tr, merch: num(tr.querySelector('[data-merch]')) });
    });
    if (house > 0) parts.push({ el: null, merch: house });

    var merchTotal = parts.reduce(function (a, p) { return a + p.merch; }, 0);

    var houseShare = form.querySelector('[data-share="house"]');
    var houseLanded = form.querySelector('[data-landed="house"]');

    if (Math.abs(merchTotal) < 0.005) {
      trs.forEach(function (tr) {
        tr.querySelector('[data-share]').textContent  = '—';
        tr.querySelector('[data-landed]').textContent = '—';
      });
      if (houseShare)  houseShare.textContent  = '—';
      if (houseLanded) houseLanded.textContent = '—';
    } else {
      var sum = 0, maxMerch = null, maxIdx = -1;
      var out = parts.map(function (p, i) {
        var share = p.merch / merchTotal;
        var alloc = r2(p.merch + (-r2(discount * share)) + r2(rush * share)
                       + r2(shipping * share) + r2(tax * share) + r2(other * share));
        sum += alloc;
        if (maxMerch === null || p.merch > maxMerch) { maxMerch = p.merch; maxIdx = i; }
        return { share: share, landed: alloc };
      });
      var residual = r2(grand - sum);
      if (Math.abs(residual) >= 0.005 && maxIdx >= 0) {
        out[maxIdx].landed = r2(out[maxIdx].landed + residual);
      }
      parts.forEach(function (p, i) {
        var pct = (out[i].share * 100).toFixed(2) + '%';
        if (p.el) {
          p.el.querySelector('[data-share]').textContent  = pct;
          p.el.querySelector('[data-landed]').textContent = money(out[i].landed);
        } else {
          if (houseShare)  houseShare.textContent  = pct;
          if (houseLanded) houseLanded.textContent = money(out[i].landed);
        }
      });
      if (house <= 0) {
        if (houseShare)  houseShare.textContent  = '—';
        if (houseLanded) houseLanded.textContent = '—';
      }
    }

    /* The two checks that decide whether Save is allowed to do anything. Both
       are plain sums — the part of this page hardest to get wrong and most
       expensive to get wrong. */
    var linesOk = Math.abs(merchTotal - subtotal) < 0.005 && subtotal !== 0;
    setChip('chipLines', money(merchTotal), linesOk,
            linesOk ? 'matches the merchandise subtotal'
                    : 'subtotal says ' + money(subtotal));

    var expected = r2(subtotal - discount + rush + shipping + tax + other);
    var reconOk  = Math.abs(expected - grand) < 0.005 && grand !== 0;
    setChip('chipRecon', money(expected), reconOk,
            reconOk ? 'matches the grand total charged'
                    : 'grand total says ' + money(grand));

    var factor = merchTotal > 0.005 ? grand / merchTotal : 0;
    setChip('chipFactor', factor ? factor.toFixed(4) + '×' : '—', null,
            factor ? 'every $1.00 of sticker price costs ' + money(factor) : '');
  }

  function setChip(id, value, ok, note) {
    var c = document.getElementById(id);
    if (!c) return;
    c.querySelector('[data-v]').textContent = value;
    c.querySelector('.n').textContent = note || '';
    c.classList.remove('ok', 'bad');
    if (ok === true)  c.classList.add('ok');
    if (ok === false) c.classList.add('bad');
  }

  form.addEventListener('input', recalc);
  form.addEventListener('change', recalc);

  /* Reset one field of a cloned or emptied row. Mirrors os_blank_row() in PHP —
     change one, change the other. Fulfilment fields are not in here because
     they are invoice-level and live outside the table. */
  function osClearField(el) {
    if (el.tagName === 'SELECT') el.selectedIndex = 0;
    else if (el.type === 'hidden') el.value = '0';
    else el.value = '';
  }

  /* Add a line. The new row is CLONED from an existing one rather than built
     from a template string, so the agent list and the type list can never drift
     out of step with the ones rendered by PHP above. */
  var addBtn = document.getElementById('osAdd');
  if (addBtn) addBtn.addEventListener('click', function () {
    var rowsEls = form.querySelectorAll('tr[data-row]');
    var last = rowsEls[rowsEls.length - 1];
    if (!last) return;
    var clone = last.cloneNode(true);
    var idx = rowsEls.length;
    clone.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace(/row\[\d+\]/, 'row[' + idx + ']');
      osClearField(el);
    });
    clone.querySelector('[data-share]').textContent  = '—';
    clone.querySelector('[data-landed]').textContent = '—';
    last.parentNode.insertBefore(clone, last.nextSibling);
    recalc();
  });

  /* Remove a line. Clearing rather than deleting the last remaining row, so the
     table can never end up with nothing to clone from. */
  form.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-rm]');
    if (!btn) return;
    var tr = btn.closest('tr[data-row]');
    var all = form.querySelectorAll('tr[data-row]');
    if (all.length > 1) {
      tr.parentNode.removeChild(tr);
    } else {
      tr.querySelectorAll('[name]').forEach(osClearField);
    }
    recalc();
  });

  recalc();
})();
</script>
</body>
</html>
