<?php
/**
 * marketing/_onboarding.php
 * Seeds the standard onboarding task tree for a new agent.
 * Include in intake.php and agent.php.
 */

/**
 * Insert the default onboarding tasks + sub-tasks.
 * Safe to call multiple times — skips if tasks already exist.
 */
/** The checklist item whose tick subscribes the agent to the Hot Sheets. */
if (!defined('MK_HS_TASK')) define('MK_HS_TASK', 'Hot Sheets: Subscribe');

function mkt_seed_onboarding_tasks(mysqli $conn, int $intake_id): void {
    $r = $conn->query("SELECT COUNT(*) AS c FROM marketing_tasks WHERE intake_id={$intake_id} AND category='onboarding'");
    if ($r && (int)$r->fetch_assoc()['c'] > 0) return;

    // Helper: insert one task, return its new id
    $ins = function(string $title, ?int $parent_id, int $sort, string $priority = 'normal') use ($conn, $intake_id): int {
        $t   = $conn->real_escape_string($title);
        $p   = $conn->real_escape_string($priority);
        $pid = $parent_id === null ? 'NULL' : (int)$parent_id;
        $conn->query(
            "INSERT INTO marketing_tasks (intake_id, parent_id, title, category, priority, status, sort_order)
             VALUES ({$intake_id}, {$pid}, '{$t}', 'onboarding', '{$p}', 'open', {$sort})"
        );
        return (int)$conn->insert_id;
    };

    // ── Top-level checklist items ──────────────────────────────────────────────
    // Using sort_order in multiples of 10 so future items can be inserted between.
    $t1 = $ins('Email Setup',                null, 10);
    $t2 = $ins('Email Signature',            null, 20);
    $t3 = $ins('QR Code',                    null, 30);
    $t4 = $ins('Marketing Overview Meeting', null, 40);  // date picker on parent
    // Ticking this subscribes them (agent.php → mk_hs_subscribe). Nikki,
    // 2026-09-22: no automatic subscription; she explains the Hot Sheets in
    // the marketing meeting first, so the first one is not a surprise.
    $t45 = $ins(MK_HS_TASK,                  null, 45);
    $t5 = $ins('Bio Received',               null, 50);
    $t6 = $ins('Headshot Received',          null, 60);
    $t7 = $ins('Added to MH Website',        null, 70);
    $t8 = $ins('Instagram Announcement',     null, 80);  // date picker on parent
    $t9 = $ins('B2B Announcement',           null, 90);  // date picker on parent

    // No sub-tasks. The checklist is a flat list of 10 items.
    // File links (email signature, QR code SVG, headshots) live on the Assets tab.
}
