<?php
/**
 * marketing/index.php
 * Agent Roster — sourced from office_roster (master),
 * joined to marketing_intakes for task counts and status.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/_onboarding.php';
require_login();
require_role('admin');

// ── Flash messages ─────────────────────────────────────────────────────────────
$success = isset($_GET['saved'])    ? 'Agent saved successfully.'  : '';
$success = isset($_GET['archived']) ? 'Agent archived.'            : $success;
$success = isset($_GET['restored']) ? 'Agent restored to active.'  : $success;

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $pid    = (int)($_POST['id'] ?? 0);

    // Create a blank intake and redirect to agent.php with the contact card open
    if ($action === 'new_agent') {
        $uid   = (int)($_SESSION['user_id'] ?? 0);
        $today = date('Y-m-d');
        $stmt  = $conn->prepare(
            "INSERT INTO marketing_intakes (agent_name, intake_date, status, is_active, created_by)
             VALUES ('', ?, 'active', 1, ?)"
        );
        $stmt->bind_param('si', $today, $uid);
        $stmt->execute();
        $new_id = (int)$conn->insert_id;
        $stmt->close();
        mkt_seed_onboarding_tasks($conn, $new_id);
        header("Location: agent.php?id={$new_id}&tab=overview&open=contact"); exit;
    }

    if ($pid && $action === 'archive') {
        $stmt = $conn->prepare(
            "UPDATE marketing_intakes SET status='archived', is_active=0, archived_at=NOW() WHERE id=?"
        );
        $stmt->bind_param('i', $pid); $stmt->execute(); $stmt->close();
        header('Location: index.php?archived=1'); exit;
    }
    if ($pid && $action === 'restore') {
        $stmt = $conn->prepare(
            "UPDATE marketing_intakes SET status='active', is_active=1, archived_at=NULL WHERE id=?"
        );
        $stmt->bind_param('i', $pid); $stmt->execute(); $stmt->close();
        header('Location: index.php?restored=1'); exit;
    }
}

// ── Load full roster from office_roster + marketing_intakes ───────────────────
// office_roster is master; marketing_intakes joined for status, tasks, headshot.
// UNION ALL also picks up intakes not yet linked to a roster entry.
$agents = [];
$res = $conn->query("
    SELECT
        r.id              AS roster_id,
        r.name            AS agent_name,
        r.title           AS agent_title,
        r.markets,
        r.agent_key,
        r.vail_agent_key,
        r.mls_id_aspen,
        r.mls_id_vail,
        r.active          AS roster_active,
        mi.id             AS intake_id,
        mi.agent_title    AS intake_title,
        mi.status         AS intake_status,
        mi.headshot_url,
        mi.start_date,
        mi.intake_date,
        mi.updated_at     AS updated_at,
        COUNT(mt.id)      AS open_tasks
    FROM office_roster r
    LEFT JOIN marketing_intakes mi
           ON mi.roster_id = r.id AND mi.is_active = 1
    LEFT JOIN marketing_tasks mt
           ON mt.intake_id = mi.id AND mt.status = 'open'
    GROUP BY r.id

    UNION ALL

    SELECT
        NULL              AS roster_id,
        mi.agent_name     AS agent_name,
        mi.agent_title    AS agent_title,
        NULL              AS markets,
        mi.mls_id_aspen   AS agent_key,
        mi.mls_id_vail    AS vail_agent_key,
        mi.mls_id_aspen,
        mi.mls_id_vail,
        1                 AS roster_active,
        mi.id             AS intake_id,
        mi.agent_title    AS intake_title,
        mi.status         AS intake_status,
        mi.headshot_url,
        mi.start_date,
        mi.intake_date,
        mi.updated_at     AS updated_at,
        COUNT(mt.id)      AS open_tasks
    FROM marketing_intakes mi
    LEFT JOIN marketing_tasks mt
           ON mt.intake_id = mi.id AND mt.status = 'open'
    WHERE mi.is_active = 1 AND mi.roster_id IS NULL
    GROUP BY mi.id

    -- Most recently touched first, then alphabetical. The explicit
    -- `updated_at IS NULL` term keeps roster agents with no marketing intake
    -- at the bottom rather than relying on how NULLs sort under DESC.
    ORDER BY updated_at IS NULL, updated_at DESC, agent_name ASC
");
if ($res) $agents = $res->fetch_all(MYSQLI_ASSOC);
$conn->close();

// ── Count by display-status ───────────────────────────────────────────────────
$counts = ['active' => 0, 'pending' => 0, 'archived' => 0, 'no_intake' => 0];
foreach ($agents as $a) {
    if (!$a['intake_id']) {
        $counts['no_intake']++;
    } else {
        $s = $a['intake_status'] ?: 'active';
        if (isset($counts[$s])) $counts[$s]++;
    }
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $i = strtoupper($parts[0][0] ?? '');
    if (count($parts) > 1) $i .= strtoupper($parts[count($parts)-1][0] ?? '');
    return substr($i, 0, 2);
}

// Build MLS market badges: [['label'=>'Aspen','has_key'=>true], ...]
function mls_badges(array $a): array {
    // Determine markets from the markets field, or infer from keys
    $markets_str = $a['markets'] ?? '';
    $markets = $markets_str
        ? array_map('trim', explode(',', $markets_str))
        : [];

    // Always include a market if there's a key for it, even if not in markets field
    $key_map = [
        'Aspen' => !empty($a['agent_key']),
        'Vail'  => !empty($a['vail_agent_key']),
    ];

    $badges = [];
    // Show all markets from the field
    foreach ($markets as $m) {
        if (!$m) continue;
        $badges[$m] = $key_map[$m] ?? false;
    }
    // Also show any markets inferred from keys not already listed
    foreach ($key_map as $m => $has) {
        if ($has && !isset($badges[$m])) $badges[$m] = true;
    }

    return array_map(fn($label, $has) => ['label' => $label, 'has_key' => $has],
                     array_keys($badges), array_values($badges));
}

$status_label = ['active' => 'Active', 'pending' => 'Pending', 'archived' => 'Archived'];
$status_color = ['active' => '#10b981', 'pending' => '#f97316', 'archived' => '#9ca3af'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Agent Roster — Mont Haus Marketing</title>
  <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root { --bs-primary: #0184BB; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
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

    .wrap { max-width:1200px; margin:32px auto; padding:0 24px 80px; }

    /* Renamed from .page-header — the theme defines that class as a fixed bar
       with min-height:55px and padding:13px 0, which this row was inheriting. */
    .mk-page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
    .mk-page-header h1 { font-size:20px; font-weight:700; margin:0; }

    .alert-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7;
                     border-radius:4px; padding:10px 14px; margin-bottom:16px; font-size:14px; }

    .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 18px;
           font-size:13px; font-weight:600; border:none; border-radius:3px;
           cursor:pointer; text-decoration:none; text-transform:uppercase; letter-spacing:.4px; }
    .btn-primary { background:#f97316; color:#fff; }
    .btn-primary:hover { background:#ea6c0a; }
    .btn-sm  { padding:4px 10px; font-size:11px; }
    .btn-outline { background:transparent; border:1px solid #d1d5db; color:#374151; }
    .btn-outline:hover { background:#f3f4f6; }
    .btn-ghost { background:transparent; border:none; color:#9ca3af; padding:3px 5px;
                 cursor:pointer; font-size:13px; line-height:1; border-radius:3px; }
    .btn-ghost:hover { color:#374151; background:#f3f4f6; }

    .toolbar { display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
    .search-wrap { position:relative; flex:1; min-width:200px; max-width:320px; }
    .search-wrap .ti-search {
      position:absolute; left:10px; top:50%; transform:translateY(-50%);
      color:#9ca3af; font-size:15px; pointer-events:none;
    }
    #agentSearch {
      width:100%; padding:7px 10px 7px 32px; font-size:13px;
      border:1px solid #d1d5db; border-radius:4px; outline:none;
      font-family:inherit; color:#111;
    }
    #agentSearch:focus { border-color:#75BDB6; box-shadow:0 0 0 2px rgba(117,189,182,.2); }

    .sort-label { font-size:12px; color:#6b7280; font-weight:600; text-transform:uppercase;
                  letter-spacing:.4px; white-space:nowrap; }
    .sort-btns { display:flex; gap:4px; }
    .sort-btn {
      padding:5px 11px; font-size:12px; font-weight:600; border:1px solid #d1d5db;
      border-radius:3px; background:#fff; color:#374151; cursor:pointer;
      display:inline-flex; align-items:center; gap:4px; white-space:nowrap;
      transition:background .15s, border-color .15s;
    }
    .sort-btn:hover { background:#f3f4f6; }
    .sort-btn.active-sort { background:#111; color:#fff; border-color:#111; }
    .sort-btn .ti { font-size:11px; }

    .filter-bar {
      display:flex; gap:6px; margin-bottom:20px;
      border-bottom:2px solid #e5e7eb; padding-bottom:0;
    }
    .filter-tab {
      padding:7px 14px; font-size:13px; font-weight:600; cursor:pointer;
      border:none; background:none; color:#6b7280; border-bottom:2px solid transparent;
      margin-bottom:-2px; white-space:nowrap; transition:color .15s, border-color .15s;
    }
    .filter-tab:hover { color:#111; }
    .filter-tab.active-tab { color:#111; border-bottom-color:#111; }
    .filter-tab .count {
      display:inline-block; background:#f3f4f6; color:#6b7280;
      border-radius:10px; padding:1px 7px; font-size:11px; margin-left:4px; font-weight:700;
    }
    .filter-tab.active-tab .count { background:#111; color:#fff; }

    /* ── Agent grid ── */
    .agent-grid {
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap:12px;
    }
    @media (max-width:1100px) { .agent-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width:760px)  { .agent-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width:480px)  { .agent-grid { grid-template-columns: 1fr; } }

    /* ── Agent card ── */
    .agent-card {
      background:#fff; border-radius:6px; padding:14px 14px 12px;
      box-shadow:0 1px 3px rgba(0,0,0,.08);
      display:flex; flex-direction:column; gap:8px;
      transition:box-shadow .2s;
      position:relative; min-width:0; overflow:hidden;
    }
    .agent-card:hover { box-shadow:0 3px 10px rgba(0,0,0,.12); }
    .agent-card.no-intake { opacity:.75; border:1px dashed #d1d5db; box-shadow:none; }
    .agent-card.no-intake:hover { opacity:1; box-shadow:0 2px 8px rgba(0,0,0,.08); }

    .card-top { display:flex; align-items:center; gap:10px; min-width:0; }
    .agent-avatar {
      width:40px; height:40px; border-radius:50%; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      font-size:14px; font-weight:700; color:#fff; overflow:hidden;
    }
    .agent-avatar img { width:100%; height:100%; object-fit:cover; }
    .agent-info { flex:1; min-width:0; }
    .agent-name {
      font-size:13px; font-weight:700; color:#111; text-decoration:none;
      display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      line-height:1.3;
    }
    a.agent-name:hover { color:#f97316; }
    .agent-title {
      font-size:11px; color:#9ca3af; margin-top:1px; line-height:1.3;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }

    /* ── MLS key row ── */
    .mls-row {
      display:flex; gap:6px; flex-wrap:wrap; min-height:16px;
    }
    .mls-tag {
      display:inline-flex; align-items:center; gap:4px;
      font-size:10px; font-weight:600; border-radius:3px;
      padding:2px 6px; white-space:nowrap;
    }
    .mls-tag.mls-ok      { background:#f0fdf4; color:#15803d; }
    .mls-tag.mls-missing { background:#fef2f2; color:#b91c1c; }

    .card-bottom { display:flex; align-items:center; justify-content:space-between; gap:6px; }
    .status-chip {
      display:inline-block; font-size:9px; font-weight:700;
      text-transform:uppercase; letter-spacing:.5px;
      padding:2px 7px; border-radius:10px; color:#fff; flex-shrink:0;
    }
    .status-chip.no-intake-chip {
      background:#f3f4f6; color:#9ca3af;
    }
    .tasks-badge {
      display:inline-flex; align-items:center; gap:4px;
      font-size:11px; font-weight:700; padding:3px 9px; border-radius:10px;
    }
    .tasks-badge.has-tasks { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
    .tasks-badge.no-tasks  { background:#f0fdf4; color:#86efac; border:1px solid #bbf7d0; font-weight:600; }

    .card-actions-row {
      display:flex; gap:5px; align-items:center;
      border-top:1px solid #f3f4f6; padding-top:10px;
    }
    .card-actions-row .spacer { flex:1; }

    .empty-state { text-align:center; padding:60px 0; color:#9ca3af; font-size:14px; }
    .empty-state .ti { font-size:36px; display:block; margin-bottom:10px; color:#d1d5db; }
    #noResults { display:none; }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <?php if ($success): ?>
      <div class="alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="mk-page-header">
      <h1>Agent Roster</h1>
      <div style="display:flex;gap:8px;">
        <a class="btn btn-outline btn-sm" href="roster.php" style="text-transform:none;font-size:12px;">
          <i class="ti ti-settings"></i> Manage Roster
        </a>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="_action" value="new_agent">
          <button type="submit" class="btn btn-primary"><i class="ti ti-plus"></i> New Agent</button>
        </form>
      </div>
    </div>

    <!-- Search + Sort toolbar -->
    <div class="toolbar">
      <div class="search-wrap">
        <i class="ti ti-search"></i>
        <input type="text" id="agentSearch" placeholder="Search agents…" autocomplete="off">
      </div>
      <span class="sort-label">Sort:</span>
      <div class="sort-btns">
        <button class="sort-btn active-sort" data-sort="updated" data-dir="desc">
          Recently Updated <i class="ti ti-chevron-down"></i>
        </button>
        <button class="sort-btn" data-sort="first" data-dir="asc">
          First Name <i class="ti ti-chevron-up"></i>
        </button>
        <button class="sort-btn" data-sort="last" data-dir="asc">
          Last Name <i class="ti ti-chevron-up"></i>
        </button>
        <button class="sort-btn" data-sort="start" data-dir="asc">
          Start Date <i class="ti ti-chevron-up"></i>
        </button>
      </div>
    </div>

    <!-- Status filter tabs -->
    <div class="filter-bar">
      <button class="filter-tab active-tab" data-filter="active">
        Active <span class="count"><?= $counts['active'] ?></span>
      </button>
      <button class="filter-tab" data-filter="pending">
        Pending <span class="count"><?= $counts['pending'] ?></span>
      </button>
      <button class="filter-tab" data-filter="archived">
        Archived <span class="count"><?= $counts['archived'] ?></span>
      </button>
      <button class="filter-tab" data-filter="no_intake">
        No Intake <span class="count"><?= $counts['no_intake'] ?></span>
      </button>
      <button class="filter-tab" data-filter="all">
        All <span class="count"><?= array_sum($counts) ?></span>
      </button>
    </div>

    <!-- Grid -->
    <div class="agent-grid" id="agentGrid">
      <?php foreach ($agents as $a):
        $has_intake  = !empty($a['intake_id']);
        $status      = $has_intake ? ($a['intake_status'] ?: 'active') : 'no_intake';
        $chip_color  = $status_color[$status] ?? '#9ca3af';
        $chip_text   = $status_label[$status] ?? 'No Intake';
        $open_tasks  = (int)$a['open_tasks'];
        $title       = $a['intake_title'] ?: $a['agent_title'] ?: '';
        $avatar_bg   = match($status) {
            'pending'   => '#f97316',
            'archived'  => '#9ca3af',
            'no_intake' => '#d1d5db',
            default     => '#1a1a1a',
        };
        $name_parts  = preg_split('/\s+/', trim($a['agent_name']));
        $first_name  = $name_parts[0] ?? '';
        $last_name   = count($name_parts) > 1 ? $name_parts[count($name_parts)-1] : $first_name;
        $start_iso   = $a['start_date'] ?: $a['intake_date'] ?: '9999-12-31';
        // Sorted as a string, so an ISO timestamp compares correctly. Agents
        // with no marketing intake get an empty value and fall to the bottom
        // under a descending sort — matching the SQL ordering.
        $updated_iso = $a['updated_at'] ?: '';

        $mls_badges = mls_badges($a);
      ?>
      <div class="agent-card <?= $has_intake ? '' : 'no-intake' ?>"
           data-status="<?= htmlspecialchars($status) ?>"
           data-name="<?= htmlspecialchars(strtolower($a['agent_name'])) ?>"
           data-first="<?= htmlspecialchars(strtolower($first_name)) ?>"
           data-last="<?= htmlspecialchars(strtolower($last_name)) ?>"
           data-start="<?= htmlspecialchars($start_iso) ?>"
           data-updated="<?= htmlspecialchars($updated_iso) ?>">

        <div class="card-top">
          <div class="agent-avatar" style="background:<?= $avatar_bg ?>;">
            <?php if (!empty($a['headshot_url'])): ?>
              <img src="<?= htmlspecialchars($a['headshot_url']) ?>" alt="">
            <?php else: ?>
              <?= htmlspecialchars(initials($a['agent_name'])) ?>
            <?php endif; ?>
          </div>
          <div class="agent-info">
            <?php if ($has_intake): ?>
              <a class="agent-name" href="agent.php?id=<?= $a['intake_id'] ?>">
                <?= htmlspecialchars($a['agent_name']) ?>
              </a>
            <?php else: ?>
              <span class="agent-name"><?= htmlspecialchars($a['agent_name']) ?></span>
            <?php endif; ?>
            <?php if ($title): ?>
              <div class="agent-title"><?= htmlspecialchars($title) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- MLS market badges -->
        <div class="mls-row">
          <?php if ($mls_badges): ?>
            <?php foreach ($mls_badges as $b): ?>
              <span class="mls-tag <?= $b['has_key'] ? 'mls-ok' : 'mls-missing' ?>">
                <?php if ($b['has_key']): ?>
                  <svg width="10" height="10" viewBox="0 0 10 10" fill="none" style="flex-shrink:0;">
                    <circle cx="5" cy="5" r="5" fill="#10b981"/>
                    <path d="M2.5 5l1.8 1.8 3.2-3.2" stroke="#fff" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                <?php else: ?>
                  <svg width="10" height="10" viewBox="0 0 10 10" fill="none" style="flex-shrink:0;">
                    <circle cx="5" cy="5" r="5" fill="#ef4444"/>
                    <path d="M3 3l4 4M7 3l-4 4" stroke="#fff" stroke-width="1.3" stroke-linecap="round"/>
                  </svg>
                <?php endif; ?>
                <?= htmlspecialchars($b['label']) ?>
              </span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="mls-tag mls-missing">
              <svg width="10" height="10" viewBox="0 0 10 10" fill="none" style="flex-shrink:0;">
                <circle cx="5" cy="5" r="5" fill="#ef4444"/>
                <path d="M3 3l4 4M7 3l-4 4" stroke="#fff" stroke-width="1.3" stroke-linecap="round"/>
              </svg>
              No MLS
            </span>
          <?php endif; ?>
        </div>

        <div class="card-bottom">
          <?php if ($has_intake): ?>
            <span class="status-chip" style="background:<?= $chip_color ?>;"><?= $chip_text ?></span>
            <?php if ($open_tasks > 0): ?>
              <span class="tasks-badge has-tasks">
                <i class="ti ti-list-check" style="font-size:10px;"></i> <?= $open_tasks ?>
              </span>
            <?php else: ?>
              <span class="tasks-badge no-tasks">
                <i class="ti ti-check" style="font-size:10px;"></i> Done
              </span>
            <?php endif; ?>
          <?php else: ?>
            <span class="status-chip no-intake-chip">No Intake</span>
          <?php endif; ?>
        </div>

        <div class="card-actions-row">
          <?php if ($has_intake): ?>
            <a class="btn btn-primary btn-sm" href="agent.php?id=<?= $a['intake_id'] ?>">
              <i class="ti ti-eye"></i> Profile
            </a>
            <a class="btn btn-outline btn-sm" href="intake.php?id=<?= $a['intake_id'] ?>">
              <i class="ti ti-pencil"></i>
            </a>
            <span class="spacer"></span>
            <?php if ($status !== 'archived'): ?>
              <form method="POST" style="margin:0;" onsubmit="return confirm('Archive <?= htmlspecialchars(addslashes($a['agent_name'])) ?>?')">
                <input type="hidden" name="_action" value="archive">
                <input type="hidden" name="id" value="<?= $a['intake_id'] ?>">
                <button type="submit" class="btn-ghost" title="Archive"><i class="ti ti-archive"></i></button>
              </form>
            <?php else: ?>
              <form method="POST" style="margin:0;">
                <input type="hidden" name="_action" value="restore">
                <input type="hidden" name="id" value="<?= $a['intake_id'] ?>">
                <button type="submit" class="btn-ghost" title="Restore" style="color:#10b981;"><i class="ti ti-refresh"></i></button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <form method="POST" action="roster.php" style="margin:0;">
              <input type="hidden" name="action" value="create_intake">
              <input type="hidden" name="roster_id" value="<?= $a['roster_id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm">
                <i class="ti ti-plus"></i> Create Intake
              </button>
            </form>
            <span class="spacer"></span>
            <a class="btn-ghost" href="roster.php#card-<?= $a['roster_id'] ?>" title="Edit roster record">
              <i class="ti ti-pencil"></i>
            </a>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>

    <div id="noResults" class="empty-state">
      <i class="ti ti-search"></i>
      No agents match your search.
    </div>

  </div>
</div>

<script>
(function () {
  const grid       = document.getElementById('agentGrid');
  const noResults  = document.getElementById('noResults');
  const searchEl   = document.getElementById('agentSearch');
  const filterTabs = document.querySelectorAll('.filter-tab');
  const sortBtns   = document.querySelectorAll('.sort-btn');

  let currentFilter = 'active';
  // Matches the SQL ordering: most recently edited first. The client re-sorts
  // on load, so leaving this as 'first' silently overrode the query's ORDER BY.
  let currentSort   = 'updated';
  let currentDir    = 'desc';
  let currentSearch = '';

  function cards() { return Array.from(grid.querySelectorAll('.agent-card')); }

  function applyAll() {
    const all = cards();
    let visible = 0;
    all.forEach(c => {
      const statusMatch = (currentFilter === 'all' || c.dataset.status === currentFilter);
      const searchMatch = !currentSearch || c.dataset.name.includes(currentSearch);
      const show = statusMatch && searchMatch;
      c.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (noResults) noResults.style.display = (visible === 0) ? '' : 'none';

    const visible_cards = all.filter(c => c.style.display !== 'none');
    visible_cards.sort((a, b) => {
      let va = a.dataset[currentSort] || '';
      let vb = b.dataset[currentSort] || '';
      const cmp = va.localeCompare(vb);
      return currentDir === 'asc' ? cmp : -cmp;
    });
    visible_cards.forEach(c => grid.appendChild(c));
  }

  filterTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      currentFilter = tab.dataset.filter;
      filterTabs.forEach(t => t.classList.toggle('active-tab', t === tab));
      applyAll();
    });
  });

  sortBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const newSort = btn.dataset.sort;
      if (currentSort === newSort) {
        currentDir = currentDir === 'asc' ? 'desc' : 'asc';
      } else {
        currentSort = newSort;
        // Each button declares its own sensible default direction — dates read
        // newest-first, names read A-Z.
        currentDir  = btn.dataset.dir || 'asc';
      }
      sortBtns.forEach(b => {
        const isActive = b.dataset.sort === currentSort;
        b.classList.toggle('active-sort', isActive);
        const icon = b.querySelector('.ti');
        if (icon) {
          icon.className = 'ti ' + (isActive
            ? (currentDir === 'asc' ? 'ti-chevron-up' : 'ti-chevron-down')
            : 'ti-chevron-up');
        }
      });
      applyAll();
    });
  });

  if (searchEl) {
    searchEl.addEventListener('input', () => {
      currentSearch = searchEl.value.toLowerCase().trim();
      applyAll();
    });
  }

  applyAll();
})();
</script>

</body>
</html>
