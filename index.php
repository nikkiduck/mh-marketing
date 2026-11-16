<?php
/**
 * index.php — Admin Home: the landing page of the admin side (Nikki,
 * 2026-11-14: "Agent Roster page should be just for agent management").
 *
 * One card per area, in the order she gave: Agent Roster, Social Media, MH
 * Send, Market Reports, Hot Sheets, Templates, QR Codes, Agent Billing, Brand
 * Guidelines, Portal Users, What's New, Anyprop Feed. These used to be the row of buttons in the
 * roster's header; the roster is agents.php now and keeps only the agent
 * management ones. A card a role cannot open is not drawn (Social Media, MH
 * Send, Portal Users and What's New are super admin only, as their pages are).
 *
 * This file was the roster until 2026-11-14. Its forms posted here, so a POST
 * from a page somebody still had open is passed on to agents.php as it is
 * (307 keeps the method and the fields). Sign-in lands here (/index.php is
 * the default return address in login.php, oauth_start.php and inc/sso.php).
 *
 * To add a card: one row in $cards. Nothing else lists them.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/schema.php';             // mk_table_exists()
require_once __DIR__ . '/inc/admin_tool_shell.php';
require_login();
// Agents land in their portal instead of this admin page's 403 (2026-10-01).
// Not a guard loop: /portal/ never sends anyone back here.
if (($_SESSION['user_role'] ?? '') === 'agent') { header('Location: /portal/'); exit; }
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Location: /agents.php', true, 307); exit; }

// Pipeline deals waiting for review: the count the roster's Hot Sheets button carried.
$pipeline_pending = 0;
if (mk_table_exists($conn, 'hs_pipeline_transactions')) {
    $r = $conn->query("SELECT COUNT(*) FROM hs_pipeline_transactions WHERE review_status = 'pending'");
    if ($r) $pipeline_pending = (int)$r->fetch_row()[0];
}

$super = is_super_admin();
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// title, href, icon, what it is, opens a new tab, shown
$cards = [
    ['Agent Roster',     'agents.php',                 'users',           'Every agent: profiles, onboarding, website approval, leadership and staff.', false, true],
    ['Social Media',     'social_media.php',           'brand-instagram', 'The Social Media Plan, the weekly plans and the tools that make each piece.', false, $super],
    ['MH Send',          'https://send.monthaus.com/', 'send',            'B2Bs, Client Emails and mailing lists. Same Microsoft sign-in.', true, $super],
    ['Market Reports',   'market_report.php',          'chart-bar',       'The monthly market report and the snapshot cards.', false, true],
    ['Hot Sheets',       'hot_sheet_preview.php',      'mail',            'This week\'s Hot Sheets by area, the subscribers and the Pipeline deals to review.', false, true],
    ['Templates',        'templates.php',              'layout-grid',     'The agents\' template tools for any agent: posts, stories, snapshots and flyers.', false, true],
    ['QR Codes',         'qr_codes.php',               'qrcode',          'Make a code, change where it points and see its scans.', false, true],
    ['Agent Billing',    'billing.php',                'receipt-2',       'What each agent owes, month by month, and the invoices to split.', false, true],
    ['Brand Guidelines', '/brand-guidelines/',         'palette',         'The public brand guidelines page.', true, true],
    ['Portal Users',     'users.php',                  'key',             'Who may sign in, their role and which marketing account they see.', false, $super],
    ['What\'s New',      'whats_new.php',              'bell',            'The notices agents see under their bell, on the portal and in MH Send.', false, $super],
    ['Anyprop Feed',     'anyprop_debug.php',          'database',        'The raw Anyprop feed: look up a member or an office, or list a board or a company.', false, true],   // every admin (Nikki, 2026-11-16: "just in case they find it useful"); named as the page is
];

admin_tool_header([
    'title'  => 'Admin Home',
    'active' => 'home',
    'css'    => '
    .ah-cards { display:grid; grid-template-columns:1fr; gap:16px; }
    @media (min-width:600px) { .ah-cards { grid-template-columns:1fr 1fr; } }
    @media (min-width:960px) { .ah-cards { grid-template-columns:1fr 1fr 1fr; } }
    .ah-card { display:flex; gap:14px; align-items:flex-start; background:#fff; border:1px solid #dfe3e8; border-radius:14px; padding:20px;
               color:inherit; text-decoration:none; transition:border-color .15s, box-shadow .15s; }
    .ah-card:hover { border-color:#4a5566; box-shadow:0 4px 18px rgba(0,0,0,.06); text-decoration:none; color:inherit; }
    .ah-ico { flex:none; width:42px; height:42px; border-radius:10px; background:#eceff3; color:#4a5569; display:flex; align-items:center; justify-content:center; font-size:22px; }
    .ah-card h3 { font-family:\'Cormorant Garamond\', Georgia, serif; font-weight:500; font-size:24px; line-height:1.15; margin:0 0 6px; color:#1f242c; }
    .ah-card h3 .ti { font-size:14px; color:#6b7a8f; vertical-align:middle; margin-left:4px; }
    .ah-card p { font-size:14px; color:#6b7a8f; margin:0; line-height:1.5; }
    .ah-badge { display:inline-block; margin-top:10px; background:#fff4e5; border:1px solid #f5d9a8; color:#8a5300; border-radius:20px; padding:2px 10px; font-size:12px; font-weight:600; }
    ',
]);
?>
    <div class="ah-cards">
      <?php foreach ($cards as [$title, $href, $icon, $sub, $newtab, $show]): if (!$show) continue; ?>
      <a class="ah-card" href="<?= $e($href) ?>"<?= $newtab ? ' target="_blank" rel="noopener"' : '' ?>>
        <span class="ah-ico"><i class="ti ti-<?= $e($icon) ?>" aria-hidden="true"></i></span>
        <div>
          <h3><?= $e($title) ?><?php if ($newtab): ?><i class="ti ti-external-link" title="Opens in a new tab" aria-label="Opens in a new tab"></i><?php endif; ?></h3>
          <p><?= $e($sub) ?></p>
          <?php if ($href === 'hot_sheet_preview.php' && $pipeline_pending): ?>
          <span class="ah-badge"><?= $pipeline_pending ?> Pipeline deal<?= $pipeline_pending === 1 ? '' : 's' ?> to review</span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
<?php admin_tool_footer();
