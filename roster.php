<?php
/**
 * marketing/roster.php — Agent Roster Management
 * Single source of truth: office_roster is master.
 * Shows all agents, their MLS keys, and linked marketing intake status.
 */
require_once __DIR__ . '/inc/auth.php';
require_login();
// Admins only (2026-10-01). The role check below reads the database and refuses
// 'agent', but a BLANK role slipped past it; require_role() refuses that too.
require_role('admin');
require_once __DIR__ . '/inc/db.php';

// ── Access control ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (($me['role'] ?? '') === 'agent') { http_response_code(403); exit('Forbidden'); }

// ── POST handlers ────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($action === 'update_roster') {
    $id = (int)($_POST['id'] ?? 0);
    $allowed = ['name','email','phone','office','markets','title',
                'mls_id_aspen','mls_id_vail','agent_key','vail_agent_key','active'];
    $sets = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $_POST)) {
            $val = trim($_POST[$f]);
            if ($f === 'active') $val = $val ? 1 : 0;
            $sets[] = "`{$f}` = '" . $conn->real_escape_string($val) . "'";
        }
    }
    if ($id && $sets) {
        $conn->query("UPDATE office_roster SET " . implode(', ', $sets) . " WHERE id={$id}");
    }
    header('Location: roster.php?saved=' . $id);
    exit;
}

if ($action === 'create_intake') {
    $roster_id = (int)($_POST['roster_id'] ?? 0);
    if ($roster_id) {
        // Check not already linked
        $chk = $conn->query("SELECT id, status FROM marketing_intakes WHERE roster_id={$roster_id} LIMIT 1");
        $linked = $chk->fetch_assoc();
        // Linked to a row the Anyprop sync created (status 'roster'): that row
        // becomes the intake, keeping its MLS identities and website fields.
        if ($linked && $linked['status'] === 'roster') {
            $lid = (int)$linked['id'];
            $conn->query("UPDATE marketing_intakes SET status='active', intake_date=CURDATE() WHERE id={$lid}");
            require_once __DIR__ . '/inc/_onboarding.php';
            mkt_seed_onboarding_tasks($conn, $lid);
            header("Location: agent.php?id={$lid}&tab=tasks&seeded=1");
            exit;
        }
        if (!$linked) {
            $r = $conn->query("SELECT name, email, phone, title FROM office_roster WHERE id={$roster_id} LIMIT 1");
            $ag = $r->fetch_assoc();
            $name  = $conn->real_escape_string($ag['name']);
            $email = $conn->real_escape_string($ag['email']);
            $phone = $conn->real_escape_string($ag['phone']);
            $title = $conn->real_escape_string($ag['title'] ?? '');
            $uid   = (int)$_SESSION['user_id'];
            $conn->query(
                "INSERT INTO marketing_intakes
                 (agent_name, agent_title, mh_email, cell_phone, roster_id, intake_date, created_by, status, is_active)
                 VALUES ('{$name}', '{$title}', '{$email}', '{$phone}', {$roster_id}, CURDATE(), {$uid}, 'active', 1)"
            );
            $new_id = (int)$conn->insert_id;
            // Seed onboarding tasks
            require_once __DIR__ . '/inc/_onboarding.php';
            mkt_seed_onboarding_tasks($conn, $new_id);
            header("Location: agent.php?id={$new_id}&tab=tasks&seeded=1");
            exit;
        }
    }
    header('Location: roster.php');
    exit;
}

if ($action === 'link_intake') {
    $roster_id = (int)($_POST['roster_id'] ?? 0);
    $intake_id = (int)($_POST['intake_id'] ?? 0);
    if ($roster_id && $intake_id) {
        $conn->query("UPDATE marketing_intakes SET roster_id={$roster_id} WHERE id={$intake_id}");
    }
    header('Location: roster.php');
    exit;
}

// ── Load roster ──────────────────────────────────────────────
$agents = [];
$res = $conn->query("
    SELECT r.*,
           mi.id         AS intake_id,
           mi.status     AS intake_status,
           mi.is_active  AS intake_active,
           mb.id         AS broker_id,
           mb.is_active  AS broker_active
    FROM office_roster r
    LEFT JOIN marketing_intakes mi ON mi.roster_id = r.id AND mi.is_active = 1
    LEFT JOIN mh_brokers mb        ON mb.roster_id = r.id
    GROUP BY r.id
    ORDER BY r.name ASC
");
while ($row = $res->fetch_assoc()) $agents[] = $row;

// Unlinked marketing intakes (no roster_id yet)
$unlinked_intakes = [];
$ui = $conn->query("SELECT id, agent_name, mh_email FROM marketing_intakes WHERE (roster_id IS NULL OR roster_id = 0) AND is_active = 1 ORDER BY agent_name");
while ($row = $ui->fetch_assoc()) $unlinked_intakes[] = $row;

$saved_id = (int)($_GET['saved'] ?? 0);

$conn->close();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agent Roster — Mont Haus Marketing</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta17/dist/css/tabler.min.css">
<style>
.roster-card { border-radius: 8px; border: 1px solid #e6ebf1; background: #fff; margin-bottom: 12px; overflow: hidden; }
.roster-card.inactive { opacity: 0.55; }
.roster-header { display: flex; align-items: center; gap: 12px; padding: 14px 16px; cursor: pointer;
                 background: #f8f9fa; border-bottom: 1px solid #e6ebf1; }
.roster-header:hover { background: #f0f3f7; }
.agent-avatar { width: 38px; height: 38px; border-radius: 50%; background: #1a5276;
                color: #fff; display: flex; align-items: center; justify-content: center;
                font-weight: 700; font-size: 14px; flex-shrink: 0; }
.agent-name { font-weight: 600; font-size: 15px; color: #111827; }
.agent-meta { font-size: 12px; color: #374151; }
.badge-market { display:inline-block; font-size:11px; padding:2px 7px; border-radius:10px;
                background:#d4e6f1; color:#1a5276; margin-right:3px; }
.badge-market.vail { background:#d5f5e3; color:#1d8348; }
.roster-body { display: none; padding: 16px; }
.roster-body.open { display: block; }
.mls-key { font-family: monospace; font-size: 12px; background: #f1f3f4; padding: 3px 7px;
           border-radius: 4px; color: #111827; word-break: break-all; }
.status-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:12px; font-weight:600; }
.status-has { background:#d5f5e3; color:#1d8348; }
.status-none { background:#fde8e8; color:#c0392b; }
.status-inactive { background:#fef9e7; color:#9a7d0a; }
.inline-form input[type=text], .inline-form select { font-size:13px; color: #111827; }
.section-label { font-size:11px; font-weight:700; text-transform:uppercase; color:#4b5563; letter-spacing:.5px; margin-bottom:4px; }
.chevron { transition: transform .2s; margin-left: auto; color: #6b7280; }
.chevron.open { transform: rotate(180deg); }
.unlinked-banner { background: #fff3cd; border: 1px solid #ffc107; border-radius:6px; padding: 12px 16px; margin-bottom: 16px; }
/* Darken all form labels and linked record text */
.form-label { color: #111827 !important; font-weight: 500; }
.form-check-label { color: #111827 !important; }
a { color: #1a5276; }
</style>
</head>
<body class="antialiased">
<div class="wrapper">
  <div class="page">

    <?php /* ── Nav ── */ ?>
    <div class="navbar navbar-expand-md navbar-light d-print-none" style="border-bottom:1px solid #e6ebf1; background:#fff;">
      <div class="container-xl">
        <a class="navbar-brand" href="index.php">
          <span style="font-weight:700; color:#1a5276;">Mont Haus</span>
          <span style="color:#6c757d; font-size:14px;"> / Marketing</span>
        </a>
        <div class="navbar-nav ms-auto">
          <a class="nav-link" href="index.php">← All Agents</a>
        </div>
      </div>
    </div>

    <div class="page-wrapper">
      <div class="container-xl py-4">

        <div class="d-flex align-items-center mb-4 gap-3">
          <div>
            <h1 class="page-title mb-0">Agent Roster</h1>
            <div class="text-muted" style="font-size:13px;">
              <?= count($agents) ?> agents in office_roster &nbsp;·&nbsp;
              <strong><?= count(array_filter($agents, fn($a) => $a['active'])) ?></strong> active
            </div>
          </div>
        </div>

        <?php if ($saved_id): ?>
        <div class="alert alert-success alert-dismissible" role="alert">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24"
               stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/>
            <path d="M5 12l5 5l10 -10"/></svg>
          Roster record saved.
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($unlinked_intakes): ?>
        <div class="unlinked-banner mb-3">
          <strong>⚠️ <?= count($unlinked_intakes) ?> marketing intake<?= count($unlinked_intakes) > 1 ? 's' : '' ?> not linked to roster:</strong>
          <?php foreach ($unlinked_intakes as $ui): ?>
            <div style="margin-top:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
              <span><?= htmlspecialchars($ui['agent_name']) ?></span>
              <form method="POST" style="display:flex; gap:6px; align-items:center;">
                <input type="hidden" name="action" value="link_intake">
                <input type="hidden" name="intake_id" value="<?= $ui['id'] ?>">
                <select name="roster_id" class="form-select form-select-sm" style="width:220px;" required>
                  <option value="">— link to roster agent —</option>
                  <?php foreach ($agents as $ag): ?>
                    <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-warning">Link</button>
                <a href="agent.php?id=<?= $ui['id'] ?>" class="btn btn-sm btn-ghost-secondary">View</a>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php /* ── Legend ── */ ?>
        <div class="d-flex gap-3 mb-3 flex-wrap" style="font-size:12px;">
          <span><span class="status-badge status-has">✓ Has Intake</span> — marketing intake linked</span>
          <span><span class="status-badge status-none">✗ No Intake</span> — not yet in marketing system</span>
          <span><span class="status-badge status-inactive">~ Inactive</span> — has intake but inactive</span>
        </div>

        <?php foreach ($agents as $ag):
            $initials = implode('', array_map(fn($p) => strtoupper($p[0] ?? ''), array_slice(explode(' ', $ag['name']), 0, 2)));
            $has_intake  = !empty($ag['intake_id']);
            $is_inactive = !$ag['active'];
            $markets     = array_filter(array_map('trim', explode(',', $ag['markets'] ?? '')));
        ?>
        <div class="roster-card <?= $is_inactive ? 'inactive' : '' ?>" id="card-<?= $ag['id'] ?>">

          <?php /* ── Card header ── */ ?>
          <div class="roster-header" onclick="toggleCard(<?= $ag['id'] ?>)">
            <div class="agent-avatar"><?= htmlspecialchars($initials) ?></div>
            <div>
              <div class="agent-name"><?= htmlspecialchars($ag['name']) ?></div>
              <div class="agent-meta">
                <?= htmlspecialchars($ag['email'] ?: '—') ?>
                <?php if ($ag['title']): ?>
                  &nbsp;·&nbsp; <?= htmlspecialchars($ag['title']) ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="ms-3 d-flex gap-1 flex-wrap">
              <?php foreach ($markets as $m): ?>
                <span class="badge-market <?= stripos($m, 'vail') !== false ? 'vail' : '' ?>"><?= htmlspecialchars($m) ?></span>
              <?php endforeach; ?>
            </div>

            <div class="ms-auto d-flex align-items-center gap-2">
              <?php if ($has_intake): ?>
                <?php if ($ag['intake_active']): ?>
                  <span class="status-badge status-has">✓ Has Intake</span>
                <?php else: ?>
                  <span class="status-badge status-inactive">~ Inactive</span>
                <?php endif; ?>
                <a href="agent.php?id=<?= $ag['intake_id'] ?>" class="btn btn-sm btn-ghost-primary"
                   onclick="event.stopPropagation()">View →</a>
              <?php else: ?>
                <span class="status-badge status-none">✗ No Intake</span>
                <form method="POST" onclick="event.stopPropagation()">
                  <input type="hidden" name="action" value="create_intake">
                  <input type="hidden" name="roster_id" value="<?= $ag['id'] ?>">
                  <button class="btn btn-sm btn-primary">+ Create Intake</button>
                </form>
              <?php endif; ?>
              <svg class="chevron" id="chev-<?= $ag['id'] ?>" xmlns="http://www.w3.org/2000/svg"
                   width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </div>
          </div>

          <?php /* ── Card body ── */ ?>
          <div class="roster-body" id="body-<?= $ag['id'] ?>">
            <form method="POST" class="inline-form">
              <input type="hidden" name="action" value="update_roster">
              <input type="hidden" name="id" value="<?= $ag['id'] ?>">

              <div class="row g-3">

                <?php /* Contact */ ?>
                <div class="col-12">
                  <div class="section-label">Contact</div>
                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label form-label-sm">Full Name</label>
                      <input type="text" name="name" class="form-control form-control-sm"
                             value="<?= htmlspecialchars($ag['name']) ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label form-label-sm">MH Email</label>
                      <input type="text" name="email" class="form-control form-control-sm"
                             value="<?= htmlspecialchars($ag['email']) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label form-label-sm">Phone</label>
                      <input type="text" name="phone" class="form-control form-control-sm"
                             value="<?= htmlspecialchars($ag['phone']) ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label form-label-sm">Title</label>
                      <input type="text" name="title" class="form-control form-control-sm"
                             value="<?= htmlspecialchars($ag['title'] ?? '') ?>">
                    </div>
                  </div>
                </div>

                <?php /* Markets + status */ ?>
                <div class="col-12">
                  <div class="row g-2">
                    <div class="col-md-3">
                      <label class="form-label form-label-sm">Markets</label>
                      <input type="text" name="markets" class="form-control form-control-sm"
                             placeholder="e.g. Aspen, Vail"
                             value="<?= htmlspecialchars($ag['markets'] ?? '') ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end pb-1">
                      <label class="form-check">
                        <input type="hidden" name="active" value="0">
                        <input class="form-check-input" type="checkbox" name="active" value="1"
                               <?= $ag['active'] ? 'checked' : '' ?>>
                        <span class="form-check-label">Active</span>
                      </label>
                    </div>
                  </div>
                </div>

                <?php /* MLS Keys */ ?>
                <div class="col-12">
                  <div class="section-label">MLS Keys</div>
                  <div class="row g-2">
                    <div class="col-md-6">
                      <label class="form-label form-label-sm">Aspen Spark Key (26-char)</label>
                      <input type="text" name="agent_key" class="form-control form-control-sm mls-key"
                             placeholder="26-char Spark key"
                             value="<?= htmlspecialchars($ag['agent_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label form-label-sm">Vail Spark Key (26-char)</label>
                      <input type="text" name="vail_agent_key" class="form-control form-control-sm mls-key"
                             placeholder="26-char Spark key"
                             value="<?= htmlspecialchars($ag['vail_agent_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label form-label-sm">Aspen MLS Short ID</label>
                      <input type="text" name="mls_id_aspen" class="form-control form-control-sm"
                             placeholder="e.g. 1513"
                             value="<?= htmlspecialchars($ag['mls_id_aspen'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label form-label-sm">Vail MLS Short ID</label>
                      <input type="text" name="mls_id_vail" class="form-control form-control-sm"
                             placeholder="e.g. 8842"
                             value="<?= htmlspecialchars($ag['mls_id_vail'] ?? '') ?>">
                    </div>
                  </div>
                </div>

                <?php /* Linked records info */ ?>
                <div class="col-12">
                  <div class="section-label">Linked Records</div>
                  <div style="font-size:12px; color:#374151; display:flex; gap:20px; flex-wrap:wrap;">
                    <span>marketing_intakes: <?= $has_intake
                        ? '<a href="agent.php?id=' . $ag['intake_id'] . '">intake #' . $ag['intake_id'] . '</a>'
                        : '<em>none</em>'; ?></span>
                    <span>mh_brokers: <?= $ag['broker_id']
                        ? 'id #' . $ag['broker_id']
                        : '<em class="text-warning">not linked</em>'; ?></span>
                  </div>
                </div>

                <div class="col-12">
                  <button type="submit" class="btn btn-sm btn-primary">Save</button>
                </div>

              </div>
            </form>
          </div>

        </div>
        <?php endforeach; ?>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta17/dist/js/tabler.min.js"></script>
<script>
function toggleCard(id) {
    const body = document.getElementById('body-' + id);
    const chev = document.getElementById('chev-' + id);
    const open = body.classList.toggle('open');
    chev.classList.toggle('open', open);
}
// Auto-open saved card
<?php if ($saved_id): ?>
toggleCard(<?= $saved_id ?>);
<?php endif; ?>
</script>
</body>
</html>
