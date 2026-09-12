<?php
/**
 * inc/schema.php — "does this column exist yet?"
 *
 * Deploying a page before its migration is an easy mistake and the symptoms are
 * silent, which is the worst combination:
 *
 *   · agent.php builds one UPDATE from every posted field and skips the write
 *     entirely when prepare() fails, then redirects saying "saved". One unknown
 *     column makes the whole form quietly stop working.
 *   · index.php selects the column in its roster query. A failed query leaves
 *     $agents empty, so the page renders fine with no agents on it.
 *
 * Neither produces an error anyone would see. Guarding costs one
 * information_schema lookup, cached per request, and only where a new column is
 * actually involved.
 *
 * Once a migration has been run everywhere, the guard around it can go — it is
 * scaffolding for the deploy window, not a permanent abstraction.
 */

function mk_column_exists(mysqli $conn, string $table, string $col): bool {
    static $cache = [];
    $key = $table . '.' . $col;
    if (isset($cache[$key])) return $cache[$key];

    $ok = false;
    $s  = $conn->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
          LIMIT 1"
    );
    if ($s) {
        $s->bind_param('ss', $table, $col);
        $s->execute();
        $ok = (bool) $s->get_result()->fetch_row();
        $s->close();
    }
    return $cache[$key] = $ok;
}

/**
 * Same idea one level up: "has this table been created yet?"
 *
 * order_split.php is useless without marketing_vendor_invoices, and the failure
 * without this guard is the silent kind again — SELECT against a missing table
 * fails, the page renders with an empty invoice list, and nothing anywhere says
 * why. With it, the page can show the one sentence that actually helps: which
 * migration has not been run.
 *
 * Same per-request cache as mk_column_exists(), for the same reason.
 */
function mk_table_exists(mysqli $conn, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $ok = false;
    $s  = $conn->prepare(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          LIMIT 1"
    );
    if ($s) {
        $s->bind_param('s', $table);
        $s->execute();
        $ok = (bool) $s->get_result()->fetch_row();
        $s->close();
    }
    return $cache[$table] = $ok;
}
