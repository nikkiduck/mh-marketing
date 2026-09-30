<?php
/**
 * inc/agent_lifecycle.php — Onboard and Offboard, each one step.
 *
 * Before these existed, bringing an agent on or off meant separate actions in
 * separate places (intake, checklist, website approval, Hot Sheet list, FUB
 * rotation), and one missed step is how a departed person stays on a mailing
 * list. Each function does every step, skips any whose tables do not exist yet
 * (migrations not run), and returns a plain-English list of what it did, which
 * the page shows back. Used by index.php and agent.php.
 *
 * Neither deletes anything. Offboarding keeps billing history, notes and tasks,
 * exactly as archiving always has (see CLAUDE.md: deactivate, do not delete).
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/_onboarding.php';

// mk_table_exists() and mk_column_exists() come from inc/schema.php.

/** The address an agent's Hot Sheet goes to: MH email, else the MLS email. */
function mk_agent_email(array $a): string {
    return strtolower(trim((string)(($a['mh_email'] ?? '') ?: ($a['mls_email'] ?? ''))));
}

/**
 * Onboard: sets an agent up. Since 2026-09-22 (Active = with Mont Haus in the
 * MLS) the MLS sync calls this itself for every new agent, and the roster calls
 * it to Restore an archived one; "onboarding" on the roster now means only the
 * marketing checklist.
 *   · status → active, intake date today (when coming from 'roster')
 *   · onboarding checklist seeded (skipped when it already exists)
 *   · website status left 'pending' so the profile waits for approval
 *   · no Hot Sheets subscription: that is the checklist item "Hot Sheets:
 *     Subscribe" (mk_hs_subscribe), ticked after the marketing meeting
 */
function mk_onboard(mysqli $conn, int $id): array {
    $done = [];
    $a = $conn->query("SELECT * FROM marketing_intakes WHERE id = " . (int)$id)->fetch_assoc();
    if (!$a) return ['Agent not found.'];

    if (in_array($a['status'], ['roster', 'pending', 'archived'], true) || !(int)$a['is_active']) {
        $from_roster = $a['status'] === 'roster';
        $conn->query("UPDATE marketing_intakes SET status = 'active', is_active = 1, archived_at = NULL"
                   . ($from_roster ? ", intake_date = CURDATE()" : '')
                   . (mk_column_exists($conn, 'marketing_intakes', 'departure_detected_at') ? ', departure_detected_at = NULL' : '')
                   . " WHERE id = " . (int)$id);
        $done[] = $from_roster ? 'Created the intake from the MLS record.' : 'Marked active.';
    }
    $before = (int)$conn->query("SELECT COUNT(*) FROM marketing_tasks WHERE intake_id = " . (int)$id . " AND category = 'onboarding'")->fetch_row()[0];
    mkt_seed_onboarding_tasks($conn, $id);
    if ($before === 0) $done[] = 'Added the onboarding checklist.';

    // Website key (slug) now, so the profile is ready to approve without
    // waiting for the nightly sync. Teams never get one.
    if (mk_column_exists($conn, 'marketing_intakes', 'slug') && trim((string)($a['slug'] ?? '')) === ''
        && ($a['entity_type'] ?? 'agent') !== 'team') {
        require_once __DIR__ . '/agent_roster.php';
        $nm = trim((string)($a['agent_name'] ?: ($a['mls_full_name'] ?? '')));
        if ($nm !== '') {
            $slug = mk_make_slug($conn, $nm, (int)$id);
            $s = $conn->prepare("UPDATE marketing_intakes SET slug = ? WHERE id = ?");
            $s->bind_param('si', $slug, $id); $s->execute(); $s->close();
        }
    }
    if (mk_column_exists($conn, 'marketing_intakes', 'web_status') && $a['web_status'] === 'pending') {
        $done[] = 'Website profile is waiting for approval (Profile tab).';
    }

    // No Hot Sheet subscription here (Nikki, 2026-09-22): it is the checklist
    // item "Hot Sheets: Subscribe", ticked after the marketing meeting.
    return $done ?: ['Already onboarded; nothing to change.'];
}

/** Is this agent getting the Hot Sheets (by agent, or by their address)? */
function mk_hs_subscribed(mysqli $conn, int $id): bool {
    if (!mk_table_exists($conn, 'hs_subscribers')) return false;
    $a = $conn->query("SELECT mh_email, mls_email FROM marketing_intakes WHERE id = " . (int)$id)->fetch_assoc();
    $email = $a ? mk_agent_email($a) : '';
    $s = $conn->prepare("SELECT 1 FROM hs_subscribers WHERE is_active = 1 AND unsubscribed_at IS NULL AND (intake_id = ? OR (? <> '' AND email = ?)) LIMIT 1");
    $s->bind_param('iss', $id, $email, $email); $s->execute();
    $on = (bool)$s->get_result()->fetch_row(); $s->close();
    return $on;
}

/**
 * Subscribe an agent to the Hot Sheets (weekly). Called when the checklist
 * item "Hot Sheets: Subscribe" is ticked. An address that unsubscribed itself
 * is never re-subscribed. Returns one line saying what happened.
 */
function mk_hs_subscribe(mysqli $conn, int $id): string {
    if (!mk_table_exists($conn, 'hs_subscribers')) return 'Hot Sheets are not set up yet (sql/hot_sheets_v2.sql).';
    $a = $conn->query("SELECT * FROM marketing_intakes WHERE id = " . (int)$id)->fetch_assoc();
    $email = $a ? mk_agent_email($a) : '';
    if ($email === '') return 'No email on file, so they could not be subscribed to the Hot Sheets.';
    $s = $conn->prepare("SELECT id, is_active, unsubscribed_at FROM hs_subscribers WHERE email = ? OR intake_id = ? LIMIT 1");
    $s->bind_param('si', $email, $id); $s->execute();
    $sub = $s->get_result()->fetch_assoc(); $s->close();
    if (!$sub) {
        $tok = bin2hex(random_bytes(16));
        $s = $conn->prepare("INSERT INTO hs_subscribers (email, intake_id, frequency, unsubscribe_token) VALUES (?, ?, 'weekly', ?)");
        $s->bind_param('sis', $email, $id, $tok); $s->execute(); $s->close();
        return "Subscribed {$email} to the Hot Sheets (weekly).";
    }
    if ($sub['unsubscribed_at']) return "{$email} unsubscribed from the Hot Sheets themselves, so they were not re-subscribed.";
    $conn->query("UPDATE hs_subscribers SET is_active = 1, intake_id = " . (int)$id . " WHERE id = " . (int)$sub['id']);
    return (int)$sub['is_active'] ? "{$email} already gets the Hot Sheets." : "Re-activated the Hot Sheet subscription for {$email}.";
}

/** The checklist item was un-ticked: stop the emails (not an "unsubscribe"). */
function mk_hs_pause(mysqli $conn, int $id): void {
    if (!mk_table_exists($conn, 'hs_subscribers')) return;
    $conn->query("UPDATE hs_subscribers SET is_active = 0 WHERE intake_id = " . (int)$id);
}

/**
 * One click: the rest of the marketing checklist is done (for agents who were
 * set up before the checklist was kept up to date). "Hot Sheets: Subscribe"
 * is only ticked when they already get the Hot Sheets: ticking it means
 * subscribing, and that waits for the marketing meeting.
 */
function mk_complete_checklist(mysqli $conn, int $id): array {
    $open = $conn->query("SELECT id, title FROM marketing_tasks WHERE intake_id = " . (int)$id
                       . " AND category = 'onboarding' AND status = 'open'")->fetch_all(MYSQLI_ASSOC);
    $n = 0; $held = false;
    foreach ($open as $t) {
        if ($t['title'] === MK_HS_TASK && !mk_hs_subscribed($conn, $id)) { $held = true; continue; }
        $conn->query("UPDATE marketing_tasks SET status = 'done', completed_at = NOW() WHERE id = " . (int)$t['id']);
        $n++;
    }
    $out = [$n ? "Marked {$n} checklist item" . ($n === 1 ? '' : 's') . ' done.' : 'Nothing else was open.'];
    if ($held) $out[] = '"Hot Sheets: Subscribe" is still open: tick it on their checklist once you have told them about the Hot Sheets.';
    return $out;
}

/**
 * Offboard: everything that should stop, stops; the record and its history stay.
 *   · status → archived (off the active roster)
 *   · website status → inactive (the site takes the profile down)
 *   · removed from FUB lead rotation
 *   · Hot Sheet subscription deactivated (not marked "unsubscribed": that
 *     is the person's own choice, recorded only by unsubscribe.php)
 */
function mk_offboard(mysqli $conn, int $id): array {
    $done = [];
    $a = $conn->query("SELECT * FROM marketing_intakes WHERE id = " . (int)$id)->fetch_assoc();
    if (!$a) return ['Agent not found.'];

    $conn->query("UPDATE marketing_intakes SET status = 'archived', is_active = 0, archived_at = NOW() WHERE id = " . (int)$id);
    $done[] = 'Archived (billing, tasks and notes are kept).';

    if (mk_column_exists($conn, 'marketing_intakes', 'web_status')) {
        if ($a['web_status'] !== 'inactive') {
            $conn->query("UPDATE marketing_intakes SET web_status = 'inactive' WHERE id = " . (int)$id);
            $done[] = 'Taken off the website (it updates on the site\'s next sync).';
        }
        if ((int)$a['in_fub']) {
            $conn->query("UPDATE marketing_intakes SET in_fub = 0 WHERE id = " . (int)$id);
            $done[] = 'Removed from FUB lead rotation.';
        }
    }
    if (mk_table_exists($conn, 'hs_subscribers')) {
        $email = mk_agent_email($a);
        $s = $conn->prepare("UPDATE hs_subscribers SET is_active = 0 WHERE (intake_id = ? OR (? <> '' AND email = ?)) AND is_active = 1");
        $s->bind_param('iss', $id, $email, $email); $s->execute();
        if ($s->affected_rows) $done[] = 'Stopped their Hot Sheet emails.';
        $s->close();
    }
    return $done;
}

/**
 * What Offboard will do, for the confirm dialog, so nobody has to guess.
 */
function mk_offboard_preview(array $a, bool $subscribed, bool $has_web): array {
    $p = ['Archive the agent (billing, tasks and notes are kept)'];
    if ($has_web && ($a['web_status'] ?? '') !== 'inactive') $p[] = 'Take their profile off the website';
    if (!empty($a['in_fub'])) $p[] = 'Remove them from FUB lead rotation';
    if ($subscribed) $p[] = 'Stop their Hot Sheet emails';
    return $p;
}
