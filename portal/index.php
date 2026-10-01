<?php
/**
 * portal/index.php — the agent portal's home. docs/AGENT_PORTAL_PLAN.md.
 *
 * Phase 1 (2026-10-01): the greeting and a card per section that exists. Cards
 * for Spend, Campaigns and Orders arrive with their pages; nothing is shown
 * as "coming soon", so an agent never meets a dead end.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
$ids = $ctx['ids'];
$in  = implode(',', array_map('intval', $ids));   // ints from portal_context, never from the request

$qr_n = 0;
if (mk_table_exists($conn, 'qr_codes')) {
    $qr_n = (int)$conn->query("SELECT COUNT(*) FROM qr_codes WHERE intake_id IN ({$in})")->fetch_row()[0];
}

$photo = trim((string)($ctx['acct']['headshot_face_url'] ?? '')) ?: trim((string)($ctx['acct']['headshot_url'] ?? ''));
$is_team = ($ctx['acct']['entity_type'] ?? 'agent') === 'team';

portal_header($ctx, 'Home', 'home');
?>
  <div class="pt-hello">
    <?php if ($photo !== ''): ?><img class="pt-avatar" src="<?= ph($photo) ?>" alt=""><?php endif; ?>
    <div>
      <h1 class="pt-h1">Hello, <?= ph(portal_greeting_name($ctx)) ?></h1>
      <p class="pt-lead" style="margin:0"><?= $is_team ? 'Your team\'s marketing at Mont Haus.' : 'Your marketing at Mont Haus.' ?></p>
    </div>
  </div>

  <div class="pt-cards">
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/qr.php')) ?>">
      <h2 class="pt-card-h">QR codes</h2>
      <p class="pt-card-sub"><?= $qr_n === 0 ? 'You do not have any QR codes yet.'
          : ($qr_n === 1 ? 'You have 1 QR code. See where it goes, or change it.'
                         : "You have {$qr_n} QR codes. See where each one goes, or change it.") ?></p>
      <span class="pt-card-go">Open →</span>
    </a>
  </div>
<?php portal_footer();
