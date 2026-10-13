<?php
/**
 * inc/portal_hotsheets.php — the agent's own Hot Sheet subscription, shown on
 * My Profile (Nikki, 2026-10-12; it was its own page, portal/hotsheets.php,
 * which now only takes the save). The subscription is the signed-in PERSON's,
 * found by their sign-in address (users.email), never by an id from the
 * request: Jonathan, Scott and Sara share a team but each has their own.
 * An admin previewing sees the account's own subscription.
 */
require_once __DIR__ . '/boards.php';
require_once __DIR__ . '/agent_lifecycle.php';

function portal_hs_ready(mysqli $conn): bool {
    return mk_table_exists($conn, 'hs_subscribers') && mk_column_exists($conn, 'hs_subscribers', 'areas');
}

/** The subscription and everything the form needs. */
function portal_hs_load(mysqli $conn, array $ctx): array {
    $email = strtolower(trim((string)$ctx['user']['email']));
    if ($ctx['preview']) {
        $sub = mk_hs_subscription($conn, (int)$ctx['acct']['id']);
        $email = $sub['email'] ?? mk_agent_email($conn->query("SELECT mh_email, mls_email FROM marketing_intakes WHERE id = " . (int)$ctx['acct']['id'])->fetch_assoc() ?: []);
    } else {
        $st = $conn->prepare("SELECT * FROM hs_subscribers WHERE email = ? LIMIT 1");
        $st->bind_param('s', $email); $st->execute();
        $sub = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
    // The roster row this address belongs to (for the checklist), else the account when it is a person.
    $st = $conn->prepare("SELECT id FROM marketing_intakes WHERE is_active = 1 AND (LOWER(mh_email) = ? OR LOWER(mls_email) = ?) ORDER BY (LOWER(mh_email) = ?) DESC, id LIMIT 1");
    $st->bind_param('sss', $email, $email, $email); $st->execute();
    $intake_id = (int)($st->get_result()->fetch_assoc()['id'] ?? 0);
    $st->close();
    if ($intake_id === 0 && ($ctx['acct']['entity_type'] ?? 'agent') !== 'team') $intake_id = (int)$ctx['acct']['id'];

    $freq = $sub ? (string)$sub['frequency'] : 'twice_weekly';
    if ($freq === 'weekly') $freq = 'twice_weekly';
    return [
        'sub'       => $sub,
        'email'     => $email,
        'intake_id' => $intake_id,
        'areas'     => mk_area_names(),
        'on'        => $sub && (int)$sub['is_active'] && !$sub['unsubscribed_at'],
        'ticked'    => $sub ? mk_areas_decode($sub['areas']) : [],
        'freq'      => $freq,
    ];
}

/** One line on what they get: "the Roaring Fork Valley Hot Sheet twice a week", or why nothing. */
function portal_hs_summary(array $hs): string {
    if ($hs['on'] && $hs['ticked']) {
        return 'You get the ' . implode(', ', array_map('mk_area_name', $hs['ticked'])) . ' Hot Sheet' . (count($hs['ticked']) === 1 ? '' : 's')
             . ' ' . ($hs['freq'] === 'daily' ? 'every day' : 'twice a week') . '.';
    }
    if ($hs['on']) return 'You are subscribed but have not chosen any areas yet, so nothing is being sent.';
    return 'Emailed Mont Haus listings, pocket listings, buyer reps and rentals, for the areas you choose.';
}

/** The status line and the form (areas, how often, save; pause when on). Posts to /portal/hotsheets.php. */
function portal_hs_form(array $ctx, array $hs, ?array $flash): void {
    $sub = $hs['sub']; $on = $hs['on']; $ticked = $hs['ticked']; $freq = $hs['freq']; $email = $hs['email'];
    $action = portal_url($ctx, '/portal/hotsheets.php');
    ?>
  <?php if ($flash): ?>
    <div class="pt-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= ph($flash[1]) ?></div>
  <?php endif; ?>
  <p style="margin:0 0 18px;">
    <?php if ($on && $ticked): ?>
      <span class="pt-status">On</span>
      <span class="pt-help" style="margin-left:8px;">Going to <?= ph($email) ?>.</span>
    <?php elseif ($on): ?>
      <span class="pt-status off">No areas yet</span>
      <span class="pt-help" style="margin-left:8px;">Tick at least one area below.</span>
    <?php elseif ($sub && $sub['unsubscribed_at']): ?>
      <span class="pt-status off">Unsubscribed</span>
      <span class="pt-help" style="margin-left:8px;">You unsubscribed from an email. Save below to start again.</span>
    <?php elseif ($sub): ?>
      <span class="pt-status off">Paused</span>
      <span class="pt-help" style="margin-left:8px;">Save below to start them again.</span>
    <?php else: ?>
      <span class="pt-status off">Not subscribed</span>
      <span class="pt-help" style="margin-left:8px;">Choose your areas and save to start.</span>
    <?php endif; ?>
  </p>

  <form method="post" class="pt-form" action="<?= ph($action) ?>">
    <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="save">
    <h3 class="pt-h3">Which areas</h3>
    <div class="pt-checks">
      <?php foreach ($hs['areas'] as $k => $name): ?>
        <label class="pt-check">
          <input type="checkbox" name="areas[]" value="<?= ph($k) ?>" <?= in_array($k, $ticked, true) ? 'checked' : '' ?>>
          <span><?= ph($name) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="pt-note">An area's email only goes out on days it has something to show. If an area has no Mont Haus
      listings or rentals right now, nothing is sent.</div>
    <h3 class="pt-h3">How often</h3>
    <label class="pt-choice">
      <input type="radio" name="frequency" value="twice_weekly" <?= $freq !== 'daily' ? 'checked' : '' ?>>
      <b>Twice a week</b>
      <small>Mondays and Thursdays, in the morning.</small>
    </label>
    <label class="pt-choice">
      <input type="radio" name="frequency" value="daily" <?= $freq === 'daily' ? 'checked' : '' ?>>
      <b>Every day</b>
      <small>Each morning, with everything that changed since Monday.</small>
    </label>
    <div class="pt-row">
      <button type="submit" class="pt-btn main"><?= $on ? 'Save Hot Sheets' : 'Save and start' ?></button>
    </div>
  </form>
  <?php if ($on): ?>
  <form method="post" action="<?= ph($action) ?>" style="margin-top:20px;"
        onsubmit="return confirm('Pause your Hot Sheets? You can start them again here any time.');">
    <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="pause">
    <p class="pt-help">Need a break? <button type="submit" class="pt-btn quiet" style="padding:8px 14px;font-size:15px;">Pause my Hot Sheets</button></p>
  </form>
  <?php endif; ?>
    <?php
}
