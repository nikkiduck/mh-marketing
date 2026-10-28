<?php
/**
 * portal/contact.php — the footer's Contact marketing form (every portal page,
 * since 2026-10-13; it was a section of My Profile). POST only. Saved in
 * marketing_requests (kind `contact`) first, then emailed to marketing@ with
 * Reply-To the agent (portal_send_mail), then back to the page it was sent
 * from with a flash. The same shape as the QR code request in qr.php.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
$back = (string)($_POST['back'] ?? '/portal/');
if (!str_starts_with($back, '/portal/') || str_contains($back, "\n")) $back = '/portal/';
$done = fn() => header('Location: ' . $back . '#contact-marketing');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /portal/'); exit; }

$subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
$message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 4000);
if (!portal_csrf_ok()) {
    $_SESSION['pt_contact_flash'] = ['err', 'That page had been open too long. Please try again.'];
} elseif ($ctx['preview']) {
    $_SESSION['pt_contact_flash'] = ['err', 'This is a preview: nothing was sent.'];
} elseif ($subject === '' || $message === '') {
    $_SESSION['pt_contact_flash'] = ['err', 'Please give your message a subject and some words.'];
} else {
    $u = $ctx['user']; $who = trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'];
    $acct = (int)$ctx['acct']['id'];
    $rid = 0;
    if (mk_table_exists($conn, 'marketing_requests')) {
        $kind = 'contact'; $uidv = (int)$u['id']; $dest = '';
        $st = $conn->prepare("INSERT INTO marketing_requests (intake_id, user_id, kind, title, details, destination) VALUES (?, ?, ?, ?, ?, ?)");
        $st->bind_param('iissss', $acct, $uidv, $kind, $subject, $message, $dest);
        $st->execute(); $rid = (int)$conn->insert_id; $st->close();
    }
    $e = fn($v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), false);
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;">'
          . '<p><strong>' . $e($who) . '</strong> (' . $e($ctx['acct']['agent_name']) . ') wrote from their agent portal'
          . ($back !== '/portal/' ? ' (' . $e($back) . ')' : '') . ':</p>'
          . '<p style="white-space:pre-wrap;border-left:3px solid #4a5566;padding-left:12px;">' . $e($message) . '</p>'
          . '<p style="color:#6b7280;font-size:12px;">Reply to this email to answer ' . $e($who) . ' directly.' . ($rid ? " Request #{$rid}." : '') . '</p></div>';
    [$st_mail, $detail] = portal_send_mail('Portal message: ' . $subject . ' (' . $ctx['acct']['agent_name'] . ')', $html, (string)$u['email'], $who);
    if ($rid) {
        $st = $conn->prepare("UPDATE marketing_requests SET email_status = ?, email_error = ? WHERE id = ?");
        $err = $st_mail === 'sent' ? null : mb_substr($detail, 0, 500);
        $st->bind_param('ssi', $st_mail, $err, $rid); $st->execute(); $st->close();
    }
    $_SESSION['pt_contact_flash'] = $st_mail === 'sent' ? ['ok', 'Sent. Marketing will reply to ' . $u['email'] . '.']
                                                       : ['err', 'Sorry, your message could not be sent just now. Please email ' . PORTAL_MARKETING_EMAIL . ' directly.'];
}
$done(); exit;
