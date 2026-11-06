<?php
/**
 * inc/accounting_mail.php: "Send to Accounting" on a collateral order
 * (agent.php, Collateral tab; Nikki, 2026-11-06).
 *
 * One click emails ACCOUNTING_EMAIL (inc/config.php) the order's details,
 * who pays, the orders that share its receipt, its notes, the receipt file
 * itself as an attachment, and a link back to the agent's Collateral tab.
 * The email goes out from the signed-in admin (inc/mailer.php), so a reply
 * reaches whoever pressed the button. On success agent.php stamps
 * accounting_sent_at / accounting_sent_by (sql/collateral_accounting_v1.sql).
 *
 * Shared receipts: a "Billed with" order on the same agent, or a vendor
 * invoice split over several agents (order_split.php), carries one invoice
 * for several orders. Each order is sent on its own, because accounting
 * books per agent, and the email lists the other orders on the same receipt
 * with the combined figure so the invoice is not read as new money each time.
 *
 * Look: the B2B tool's Aspen header band (send.monthaus.com, same server),
 * then one 600 px white card in the portal's slate palette. Plain tables and
 * inline styles, like the Hot Sheet (inc/hs_template.php).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/schema.php';

/** The header image the email opens with: the Aspen band in the slate scheme (Nikki, 2026-11-06;
 *  the B2B tool's header-aspen.jpg on send.monthaus.com is still the older sepia tone). */
const MK_ACCT_HEADER_IMAGE = SITE_URL . '/assets/images/email/header-aspen-slate.jpg';

function mk_acct_e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mk_acct_money($v): string { return '$' . number_format((float)$v, 2); }
function mk_acct_date(?string $d): string { return ($d && $d !== '0000-00-00') ? date('m/d/Y', strtotime($d)) : ''; }
/** A shared-receipt row: its own cost, or "included" when the figure sits on the order it is billed with. */
function mk_acct_cost_or_included(array $o): string { return ($o['cost'] !== null && $o['cost'] !== '') ? mk_acct_money($o['cost']) : 'included'; }

/** "yard_signs" to "Yard Signs"; the card's own label when it has one. */
function mk_acct_order_title(array $o): string {
    $type = ucwords(str_replace('_', ' ', (string)($o['type'] ?? 'order')));
    $type = str_replace('Oh Signs', 'Open House Signs', $type);
    $label = trim((string)($o['label'] ?? ''));
    return $label !== '' ? $label . ' (' . $type . ')' : $type;
}

function mk_acct_status_label(?string $s): string {
    return ucwords(str_replace('_', ' ', (string)($s ?: 'pending')));
}

/** "Broker pays", "Mont Haus pays", "Split" or "" when nobody has been named. */
function mk_acct_who_pays(array $o): string {
    return ['broker' => 'Broker pays', 'mont_haus' => 'Mont Haus pays', 'split' => 'Split'][(string)($o['paid_by'] ?? '')] ?? '';
}

/**
 * The orders that share this order's receipt.
 *   billed:  "Billed with" group on the same agent (the order it points at, the
 *            orders pointing at it, and those pointing at the same parent).
 *   invoice: every other order on the same vendor invoice (order_split.php),
 *            any agent, with the invoice row itself in `invoice_row`.
 * Each row carries `agent_name`.
 */
function mk_acct_siblings(mysqli $conn, array $o): array {
    $out = ['billed' => [], 'invoice' => [], 'invoice_row' => null];
    $oid  = (int)$o['id'];
    $root = (int)($o['billed_with_order_id'] ?? 0) ?: $oid;
    $r = $conn->query(
        "SELECT o.*, i.agent_name FROM marketing_collateral_orders o
           JOIN marketing_intakes i ON i.id = o.intake_id
          WHERE o.id <> {$oid} AND (o.id = {$root} OR o.billed_with_order_id = {$root} OR o.billed_with_order_id = {$oid})
          ORDER BY o.id"
    );
    if ($r) $out['billed'] = $r->fetch_all(MYSQLI_ASSOC);

    if (!empty($o['vendor_invoice_id']) && mk_column_exists($conn, 'marketing_collateral_orders', 'vendor_invoice_id')) {
        $vid = (int)$o['vendor_invoice_id'];
        $r = $conn->query(
            "SELECT o.*, i.agent_name FROM marketing_collateral_orders o
               JOIN marketing_intakes i ON i.id = o.intake_id
              WHERE o.vendor_invoice_id = {$vid} AND o.id <> {$oid}
              ORDER BY i.agent_name, o.id"
        );
        if ($r) $out['invoice'] = $r->fetch_all(MYSQLI_ASSOC);
        try {
            $ir = $conn->query("SELECT * FROM marketing_vendor_invoices WHERE id = {$vid} LIMIT 1");
            if ($ir) $out['invoice_row'] = $ir->fetch_assoc() ?: null;
        } catch (mysqli_sql_exception $e) { /* table not there: no invoice detail */ }
    }
    return $out;
}

/**
 * Subject, HTML and plain text for one order.
 * $agent is the marketing_intakes row, $o the order, $sib from mk_acct_siblings().
 */
function mk_acct_order_email(array $agent, array $o, array $sib, string $sender_name): array {
    $ink = '#1f242c'; $dim = '#5c6574'; $slate = '#4a5566'; $line = '#dfe3e8'; $page = '#f6f7f9';
    $font = 'font-family:Arial, Helvetica, sans-serif;';

    $agent_name = trim((string)($agent['agent_name'] ?? '')) ?: 'Agent';
    $title      = mk_acct_order_title($o);
    $who        = mk_acct_who_pays($o);
    $cost       = $o['cost'] !== null && $o['cost'] !== '' ? mk_acct_money($o['cost']) : '';
    $link       = SITE_URL . '/billing.php?tab=agents';   // Billing, Agents tab (Nikki, 2026-11-06)
    $has_rcpt   = !empty($o['receipt_file']) && is_file(RECEIPTS_DIR . basename((string)$o['receipt_file']));
    $rcpt_name  = (string)($o['receipt_orig_name'] ?: $o['receipt_file'] ?: '');

    $subject = 'Marketing Order Receipt: ' . $agent_name;   // Nikki's wording, 2026-11-06

    // Detail rows: label, value; blanks are left out.
    $vendor = trim((string)($o['vendor'] ?? ''));
    $rows = [
        ['Agent',        $agent_name],
        ['Order',        $title],
        ['Vendor',       $vendor],
        ['Order number', (string)($o['order_number'] ?? '')],
        ['Quantity',     (string)($o['qty'] ?? '')],
        ['Cost',         $cost],
        ['Status',       mk_acct_status_label($o['status'] ?? '')],
        ['Ordered',      mk_acct_date($o['ordered_at'] ?? null)],
        ['Delivered',    mk_acct_date($o['delivered_at'] ?? null)],
        ['Tracking',     (string)($o['tracking_number'] ?? '')],
    ];
    $rows = array_values(array_filter($rows, fn($r) => trim($r[1]) !== ''));

    // Who pays.
    $pay_lines = [];
    if ($who !== '') {
        $pay_lines[] = $who;
        if (($o['paid_by'] ?? '') === 'split') {
            $pay_lines[] = 'Broker ' . mk_acct_money($o['paid_broker_amount'] ?? 0) . ' / Mont Haus ' . mk_acct_money($o['paid_mh_amount'] ?? 0);
        }
    }

    // Shared receipt: the other orders on it and the combined figure.
    $shared = [];   // [heading, rows[[who, what, cost]], total line]
    if ($sib['billed']) {
        $srows = [[$agent_name, $title, mk_acct_cost_or_included($o)]];
        $tot = (float)($o['cost'] ?? 0);
        foreach ($sib['billed'] as $s) { $srows[] = [$s['agent_name'], mk_acct_order_title($s), mk_acct_cost_or_included($s)]; $tot += (float)($s['cost'] ?? 0); }
        $shared[] = ['This order is billed with ' . count($sib['billed']) . ' other order' . (count($sib['billed']) === 1 ? '' : 's') . ' on one receipt', $srows, 'Receipt total ' . mk_acct_money($tot)];
    }
    if ($sib['invoice']) {
        $srows = [[$agent_name, $title, mk_acct_cost_or_included($o)]];
        $tot = (float)($o['cost'] ?? 0);
        foreach ($sib['invoice'] as $s) { $srows[] = [$s['agent_name'], mk_acct_order_title($s), mk_acct_cost_or_included($s)]; $tot += (float)($s['cost'] ?? 0); }
        $inv = $sib['invoice_row'];
        $head = 'This order is one agent\'s share of a vendor invoice split over ' . (count($sib['invoice']) + 1) . ' orders';
        if ($inv && !empty($inv['order_number'])) $head .= ' (' . ($inv['vendor'] ? $inv['vendor'] . ' ' : '') . '#' . $inv['order_number'] . ')';
        $totline = 'Agents\' shares ' . mk_acct_money($tot);
        if ($inv) {
            if ((float)($inv['house_merch'] ?? 0) > 0) $totline .= ', office inventory ' . mk_acct_money($inv['house_merch']) . ($inv['house_label'] ? ' (' . $inv['house_label'] . ')' : '');
            $totline .= ', invoice total ' . mk_acct_money($inv['grand_total'] ?? 0);
        }
        $shared[] = [$head, $srows, $totline];
    }

    $notes = trim((string)($o['notes'] ?? ''));
    // Mountain time: this server runs PHP and MySQL in UTC.
    $sent_line = 'Sent by ' . $sender_name . ' from the Mont Haus marketing portal, ' . (new DateTime('now', new DateTimeZone('America/Denver')))->format('F j, Y \a\t g:i A');

    // ---- HTML ------------------------------------------------------------
    $e = 'mk_acct_e';
    $h  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    $h .= '<meta name="format-detection" content="telephone=no, date=no, address=no, email=no"><title>' . $e($subject) . '</title>';
    $h .= '<style>body{margin:0;padding:0;} table,td{mso-table-lspace:0;mso-table-rspace:0;} img{border:0;display:block;} @media screen and (max-width:480px){.wrap{width:100% !important;} .px{padding-left:20px !important;padding-right:20px !important;}}</style></head>';
    $h .= '<body style="margin:0;padding:0;background:' . $page . ';' . $font . '">';
    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $page . ';"><tr><td align="center" style="padding:24px 12px;">';
    $h .= '<table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid ' . $line . ';">';
    // Header band
    $h .= '<tr><td style="padding:0;line-height:0;font-size:0;"><img src="' . $e(MK_ACCT_HEADER_IMAGE) . '" width="600" alt="Mont Haus International Realty" style="width:100%;max-width:600px;height:auto;"></td></tr>';
    // Title
    $h .= '<tr><td class="px" style="padding:28px 40px 8px;' . $font . '">';
    $h .= '<div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:' . $slate . ';font-weight:bold;">Collateral order</div>';
    $h .= '<div style="font-size:24px;line-height:1.25;color:' . $ink . ';font-weight:bold;margin-top:6px;">' . $e($agent_name) . '</div>';
    $h .= '<div style="font-size:16px;line-height:1.4;color:' . $dim . ';margin-top:2px;">' . $e($title) . ($cost !== '' ? ' &middot; ' . $e($cost) : '') . '</div>';
    $h .= '</td></tr>';
    // Detail rows
    $h .= '<tr><td class="px" style="padding:16px 40px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid ' . $line . ';">';
    foreach ($rows as [$k, $v]) {
        $h .= '<tr><td style="padding:9px 0;border-bottom:1px solid ' . $line . ';' . $font . 'font-size:12px;color:' . $dim . ';width:130px;vertical-align:top;text-transform:uppercase;letter-spacing:.5px;">' . $e($k) . '</td>';
        $h .= '<td style="padding:9px 0;border-bottom:1px solid ' . $line . ';' . $font . 'font-size:14px;color:' . $ink . ';vertical-align:top;">' . $e($v) . '</td></tr>';
    }
    $h .= '</table></td></tr>';
    // Who pays
    $block = function (string $heading, string $inner) use ($font, $ink, $slate, $page, $line): string {
        return '<tr><td class="px" style="padding:20px 40px 0;"><div style="background:' . $page . ';border:1px solid ' . $line . ';padding:14px 16px;">'
             . '<div style="' . $font . 'font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:' . $slate . ';font-weight:bold;margin-bottom:6px;">' . $heading . '</div>'
             . '<div style="' . $font . 'font-size:14px;line-height:1.5;color:' . $ink . ';">' . $inner . '</div></div></td></tr>';
    };
    if ($pay_lines) $h .= $block('Who pays', $e(implode('. ', $pay_lines)) . '.');
    foreach ($shared as [$head, $srows, $totline]) {
        $inner = '<div style="margin-bottom:8px;">' . $e($head) . '.</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
        foreach ($srows as [$sw, $swhat, $sc]) {
            $inner .= '<tr><td style="' . $font . 'font-size:13px;color:' . $ink . ';padding:3px 0;">' . $e($sw) . ' <span style="color:' . $dim . ';">' . $e($swhat) . '</span></td>'
                    . '<td align="right" style="' . $font . 'font-size:13px;color:' . $ink . ';padding:3px 0;white-space:nowrap;">' . $e($sc) . '</td></tr>';
        }
        $inner .= '</table><div style="margin-top:8px;padding-top:8px;border-top:1px solid ' . $line . ';font-size:13px;font-weight:bold;">' . $e($totline) . '</div>';
        $h .= $block('Shared receipt', $inner);
    }
    if ($notes !== '') $h .= $block('Notes', nl2br($e($notes)));
    // Receipt line
    $rcpt_html = $has_rcpt
        ? '<span style="color:' . $ink . ';">Receipt attached:</span> ' . $e($rcpt_name)
        : '<span style="color:#9a3412;font-weight:bold;">No receipt was attached to this order.</span>';
    $h .= '<tr><td class="px" style="padding:20px 40px 0;' . $font . 'font-size:14px;color:' . $dim . ';">' . $rcpt_html . '</td></tr>';
    // Button
    $h .= '<tr><td class="px" align="left" style="padding:24px 40px 32px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="background:' . $slate . ';">';
    $h .= '<a href="' . $e($link) . '" style="display:inline-block;padding:13px 24px;' . $font . 'font-size:13px;font-weight:bold;letter-spacing:.5px;color:#ffffff;text-decoration:none;">Open in Billing</a>';
    $h .= '</td></tr></table><div style="' . $font . 'font-size:12px;color:' . $dim . ';margin-top:10px;">Opens Billing on the marketing portal (admin sign-in).</div></td></tr>';
    // Footer
    $h .= '<tr><td class="px" style="padding:16px 40px 20px;border-top:1px solid ' . $line . ';' . $font . 'font-size:11px;line-height:1.5;color:' . $dim . ';">' . $e($sent_line) . '<br>Mont Haus International Realty</td></tr>';
    $h .= '</table></td></tr></table></body></html>';

    // ---- Plain text ------------------------------------------------------
    $t = "COLLATERAL ORDER\n" . $agent_name . "\n" . $title . ($cost !== '' ? ' - ' . $cost : '') . "\n\n";
    foreach ($rows as [$k, $v]) $t .= str_pad($k . ':', 14) . $v . "\n";
    if ($pay_lines) $t .= "\nWho pays: " . implode('. ', $pay_lines) . ".\n";
    foreach ($shared as [$head, $srows, $totline]) {
        $t .= "\n" . $head . ".\n";
        foreach ($srows as [$sw, $swhat, $sc]) $t .= '  ' . $sw . ', ' . $swhat . ': ' . $sc . "\n";
        $t .= '  ' . $totline . "\n";
    }
    if ($notes !== '') $t .= "\nNotes:\n" . $notes . "\n";
    $t .= "\n" . ($has_rcpt ? 'Receipt attached: ' . $rcpt_name : 'No receipt was attached to this order.') . "\n";
    $t .= "\nOpen in Billing: " . $link . "\n\n" . $sent_line . "\nMont Haus International Realty\n";

    return ['subject' => $subject, 'html' => $h, 'text' => $t, 'has_receipt' => $has_rcpt];
}

/**
 * Build and send one order to ACCOUNTING_EMAIL. Returns [ok, detail]; detail
 * is the subject on success or the mailer's error on failure. Writes nothing:
 * the caller stamps the order.
 */
function mk_send_order_to_accounting(mysqli $conn, array $agent, array $o, string $sender_name, string $to = ''): array {
    $to = $to !== '' ? $to : (defined('ACCOUNTING_EMAIL') ? ACCOUNTING_EMAIL : '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'ACCOUNTING_EMAIL is not set in inc/config.php.'];

    $sib  = mk_acct_siblings($conn, $o);
    $mail = mk_acct_order_email($agent, $o, $sib, $sender_name);
    $att  = [];
    if ($mail['has_receipt']) {
        $path = RECEIPTS_DIR . basename((string)$o['receipt_file']);
        $name = (string)($o['receipt_orig_name'] ?: basename($path));
        if (pathinfo($name, PATHINFO_EXTENSION) === '') $name .= '.' . pathinfo($path, PATHINFO_EXTENSION);
        $att[] = ['path' => $path, 'name' => $name];
    }
    $err = '';
    $ok  = send_email($to, 'Mont Haus Accounting', $mail['subject'], $mail['html'], $mail['text'], [], $err, '', '', $att);
    return [$ok, $ok ? $mail['subject'] : ($err ?: 'The mail server did not accept the message.')];
}
