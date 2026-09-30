<?php
/**
 * unsubscribe.php — Hot Sheet unsubscribe. PUBLIC: no sign-in, no allowlist.
 *
 *   GET  ?t=TOKEN   shows a confirm button. It does NOT unsubscribe: link
 *                   scanners in corporate mail open every URL in an email,
 *                   and a GET that acted would unsubscribe people at random.
 *   POST ?t=TOKEN   unsubscribes. This is also the RFC 8058 one-click path:
 *                   mail clients POST "List-Unsubscribe=One-Click" here, as
 *                   announced by the List-Unsubscribe-Post header.
 *
 * The token (hs_subscribers.unsubscribe_token, 32 hex) is the only key. The
 * page never says which address a token belongs to.
 */
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$valid = (bool)preg_match('/^[a-f0-9]{32}$/', $token);
$state = 'invalid';

if ($valid) {
    $s = $conn->prepare("SELECT id, unsubscribed_at FROM hs_subscribers WHERE unsubscribe_token = ?");
    $s->bind_param('s', $token);
    $s->execute();
    $sub = $s->get_result()->fetch_assoc();
    $s->close();

    if ($sub) {
        $state = $sub['unsubscribed_at'] ? 'done' : 'confirm';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$sub['unsubscribed_at']) {
            $u = $conn->prepare("UPDATE hs_subscribers SET is_active = 0, unsubscribed_at = NOW() WHERE id = ?");
            $id = (int)$sub['id'];
            $u->bind_param('i', $id);
            $u->execute();
            $u->close();
            $state = 'done';
        }
    }
}
$conn->close();

// A one-click POST from a mail client wants a 2xx and nothing else.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    http_response_code($state === 'done' ? 200 : 404);
    exit;
}
if ($state === 'invalid') http_response_code(404);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mont Haus Hot Sheet</title>
<style>
  body { margin:0; background:#f4f4f4; font-family:Arial, Helvetica, sans-serif; color:#1a1a1a; }
  .box { max-width:440px; margin:12vh auto 0; background:#fff; padding:36px 32px; text-align:center; }
  h1 { font-size:20px; margin:0 0 12px; }
  p { font-size:14px; line-height:1.6; color:#4b5563; margin:0 0 22px; }
  button { background:#1a1a1a; color:#fff; border:0; padding:12px 22px; font-size:12px; font-weight:bold;
           letter-spacing:.5px; text-transform:uppercase; cursor:pointer; }
</style>
</head>
<body>
<div class="box">
<?php if ($state === 'confirm'): ?>
  <h1>Unsubscribe from the Hot Sheet?</h1>
  <p>You will stop receiving the Mont Haus Hot Sheet emails.</p>
  <form method="post" action="unsubscribe.php">
    <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
    <button type="submit">Unsubscribe</button>
  </form>
<?php elseif ($state === 'done'): ?>
  <h1>You're unsubscribed</h1>
  <p>You won't receive the Mont Haus Hot Sheet any more.</p>
<?php else: ?>
  <h1>Link not recognised</h1>
  <p>This unsubscribe link is not valid. If you keep receiving emails you don't want, reply to one and we'll remove you.</p>
<?php endif; ?>
</div>
</body>
</html>
