<?php
/**
 * plan_save.php — writes to the Weekly Plans store (inc/plan_store.php,
 * 2026-11-03) and returns to the week page. POST with the CSRF token,
 * plan_week (YYYY-Www), plan_slot (0 to 7) and plan_action:
 *   item    the builder's form (its Save to the week button): kept as the
 *           slot's piece, with the caption and a one-line summary
 *   done    tick or untick an Other slot: done=1|0 (the market slot's ticks
 *           are per slide, below)
 *   slides  the market slot's slide list (2026-11-09): slide_action=done|
 *           undone|remove with slide=<index>, or add with place (a
 *           market_card_defs() key) + ptype (SF|TC); the first change to a
 *           slot still on its suggestion keeps the suggestion as the list
 *   clear   drop the slot's piece (back to the suggestion)
 *   kind    give the slot another content kind for this week (the Content
 *           dropdown, 2026-11-09): slot_kind (a plan_slot_options() key),
 *           slot_words for Other; a piece saved under another kind is dropped
 *   status  the week: status=planned|open
 * Super admin only (plan_gate()).
 */
require_once __DIR__ . '/inc/plan.php';

$ctx = plan_gate($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and save again.');
}
$week = (string)($_POST['plan_week'] ?? '');
$slot = (int)($_POST['plan_slot'] ?? -1);
if (!plan_store_key_ok($week)) portal_stop('Which week?', 'The week was missing from the request. Go back to Weekly Plans and try again.');
$plan  = plan_week_load($week);
$slots = plan_week_slots($plan);   // the week's own content choices (2026-11-09)
$action = (string)($_POST['plan_action'] ?? 'item');
$back = 'social_plan.php?week=' . rawurlencode($week);

if ($action === 'status') {
    plan_week_set_status($week, (string)($_POST['status'] ?? 'open'));
    header('Location: ' . $back); exit;
}
if (!isset($slots[$slot])) portal_stop('Which piece?', 'That day and piece are not in the plan\'s week. Go back to Weekly Plans and try again.');
$def = $slots[$slot];

if ($action === 'kind') {
    if (!plan_slot_set_kind($week, $slot, (string)($_POST['slot_kind'] ?? ''), (string)($_POST['slot_words'] ?? ''))) {
        portal_stop('That content is not one of the plan\'s', 'Go back to Weekly Plans and choose again.');
    }
    header('Location: ' . $back); exit;
}
if ($action === 'clear') {
    plan_item_clear($week, $slot);
    header('Location: ' . $back . '&cleared=' . $slot); exit;
}
if ($action === 'slides') {
    if ($def['type'] !== 'market') portal_stop('Not a Market Stats day', 'Slides belong to a Market Stats day. Go back to Weekly Plans and try again.');
    $n = 0; foreach ($slots as $j => $s2) if ($j < $slot && $s2['type'] === 'market') $n++;   // the nth market slot of the week steps the suggested pair on
    $slides = plan_market_slides($plan['items'][$slot] ?? null, plan_week($week)['index'], $n);
    $what = (string)($_POST['slide_action'] ?? ''); $k = (int)($_POST['slide'] ?? -1);
    if ($what === 'add') $slides[] = ['place' => (string)($_POST['place'] ?? ''), 'ptype' => (string)($_POST['ptype'] ?? ''), 'done' => false];
    elseif (isset($slides[$k]) && ($what === 'done' || $what === 'undone')) $slides[$k]['done'] = $what === 'done';
    elseif (isset($slides[$k]) && $what === 'remove') array_splice($slides, $k, 1);
    else portal_stop('Which slide?', 'That slide is not on the day any more. Go back to Weekly Plans and try again.');
    plan_market_slides_save($week, $slot, $slides);
    header('Location: ' . $back); exit;
}
if ($action === 'done') {
    if (!empty($_POST['done'])) plan_item_save($week, $slot, ['kind' => $def['type'], 'done' => true, 'saved_at' => date('c')]);
    else plan_item_clear($week, $slot);
    header('Location: ' . $back); exit;
}

// A builder's piece: the form must render (the same check the download makes) before it is kept.
$kind = (string)($_POST['kind'] ?? '');
if ($kind !== $def['type']) portal_stop('Wrong tool for the day', 'This slot is a ' . ph($def['words']) . '; the form came from the ' . ph(plan_kinds()[$kind] ?? $kind) . ' tool.');
$form = plan_form_from_post($_POST);
$r = plan_post_from_request($conn, $ctx, $form);
if (is_string($r)) portal_stop('Nothing to save yet', ph($r));
$item = [
    'kind'     => $kind,
    'form'     => $form,
    'caption'  => mb_substr(trim(str_replace("\r", '', (string)($_POST['caption'] ?? ''))), 0, 2200),
    'summary'  => plan_item_summary($conn, $kind, $form),
    'frames'   => count($r['frames']),
    'saved_at' => date('c'),
];
if (!plan_item_save($week, $slot, $item)) {
    portal_stop('The week could not be saved', 'The plans folder on the server would not take the file. Email ' . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
}
header('Location: ' . $back . '&saved=' . $slot);
exit;
