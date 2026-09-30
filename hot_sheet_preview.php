<?php
/**
 * hot_sheet_preview.php — the Hot Sheet email exactly as it would go out today.
 *
 *   /hot_sheet_preview.php               Listings
 *   /hot_sheet_preview.php?type=rentals  Rentals
 *
 * Admin only. Renders the real email HTML from the same build_hs_data() the
 * send cron uses, so what you see is what would be sent. Sends nothing.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_login();
require_role('admin');

require_once __DIR__ . '/inc/hs_data.php';
require_once __DIR__ . '/inc/hs_template.php';

$type = ($_GET['type'] ?? '') === 'rentals' ? 'rentals' : 'listings';

$chk = $conn->query("SHOW TABLES LIKE 'hs_manual_listings'");
if (!$chk || !$chk->fetch_row()) {
    http_response_code(503);
    exit('Hot Sheets is not set up yet: run sql/hot_sheets_v1.sql and sql/hot_sheets_v2.sql.');
}

$data = build_hs_data($conn);
$conn->close();

// The unsubscribe link points nowhere real in a preview.
echo render_hot_sheet_email($data, $type, SITE_URL . '/unsubscribe.php?t=preview');
