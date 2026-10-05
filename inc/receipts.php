<?php
/**
 * inc/receipts.php — storing, copying and removing receipt files (vendor
 * invoices) for collateral orders and advertising placements.
 *
 * The two functions below lived inside agent.php's POST block until
 * 2026-10-05; they moved here unchanged so handlers anywhere in that block can
 * use them. order_split.php keeps its own os_* versions.
 */

/**
 * Save an uploaded receipt and return [stored_filename, original_name],
 * or null when no file was submitted.
 *
 * Files go OUTSIDE the document root, next to logs/, and are served by
 * receipt.php behind the admin session — they carry vendor and cost detail
 * and shouldn't be reachable by guessing a URL.
 */
function mk_store_receipt(string $field = 'receipt'): ?array {
    if (empty($_FILES[$field]['tmp_name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return null;   // nothing uploaded — not an error
    }
    $tmp  = $_FILES[$field]['tmp_name'];
    $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : '';
    $ext_map = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
    if (!isset($ext_map[$mime]))                    return ['error' => 'Receipt must be a PDF, JPG or PNG.'];
    if ($_FILES[$field]['size'] > 10 * 1024 * 1024) return ['error' => 'Receipt exceeds the 10 MB limit.'];

    $dir = RECEIPTS_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return ['error' => 'Could not create the receipts folder.'];

    $stored = 'receipt_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext_map[$mime];
    if (!move_uploaded_file($tmp, $dir . $stored))  return ['error' => 'Failed to save the receipt.'];

    $orig = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($_FILES[$field]['name'] ?? $stored));
    return ['file' => $stored, 'orig' => $orig];
}

/** Remove a stored receipt file from disk. Silent if already gone. */
function mk_delete_receipt_file(?string $stored): void {
    $stored = trim((string)$stored);
    if ($stored === '') return;
    $path = RECEIPTS_DIR . basename($stored);
    if (is_file($path)) @unlink($path);
}

/**
 * A second physical copy of a stored receipt, under a new name; returns the
 * new stored filename, or null when the source is missing or the copy fails.
 * Every record gets its OWN file (see CLAUDE.md > Receipts are copied per
 * order, not shared): removing the receipt on one agent unlinks the file, and
 * a shared file would break the link for everyone else.
 */
function mk_copy_receipt_file(?string $stored): ?string {
    $stored = trim((string)$stored);
    if ($stored === '') return null;
    $src = RECEIPTS_DIR . basename($stored);
    if (!is_file($src)) return null;
    $ext  = pathinfo($src, PATHINFO_EXTENSION) ?: 'pdf';
    $copy = 'receipt_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    return @copy($src, RECEIPTS_DIR . $copy) ? $copy : null;
}
