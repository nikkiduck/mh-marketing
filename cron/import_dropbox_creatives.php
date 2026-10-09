<?php
/**
 * cron/import_dropbox_creatives.php — one-off (2026-10-09, Nikki's "option 2"):
 * fetch the Dropbox-linked ad creatives and print proofs and store them on the
 * portal exactly as a manual upload would (mk_store_creative_from_path: same
 * checks, names and thumbnail), so every agent's cards have pictures before
 * the first sign-in. Not a cron job; it lives here with the other CLI scripts.
 *
 *   php cron/import_dropbox_creatives.php --dry-run          what would be fetched, and why some cannot be
 *   php cron/import_dropbox_creatives.php --asset=5          one advertising creative (marketing_campaign_assets.id)
 *   php cron/import_dropbox_creatives.php --order=2          one print order's proof (marketing_collateral_orders.id)
 *   php cron/import_dropbox_creatives.php --all              every creative and proof still without a file
 *   php cron/import_dropbox_creatives.php --rethumb          make the thumbnail for every stored file that has none (PDFs, after the gs fallback)
 *
 * Rules: a row that already has a file is never touched; the Dropbox link is
 * kept (the portal still offers "Open original"). Only single-file share
 * links (dropbox.com/scl/fi/... or /s/...) can be fetched: a folder link
 * (/scl/fo/...) is reported and skipped. A link that is not a public share
 * comes back as a Dropbox web page, which is reported as such. Over the
 * upload limit (MK_CREATIVE_MAX_MB), or not a JPG/PNG/GIF/PDF, is reported and skipped (the same limits as the
 * upload form). Nothing is deleted. Exit code 1 when any row was skipped.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/schema.php';
require_once __DIR__ . '/../inc/creatives.php';

$opt = getopt('', ['dry-run', 'all', 'asset:', 'order:', 'rethumb']);
$dry = isset($opt['dry-run']);
if (!$dry && !isset($opt['all']) && !isset($opt['asset']) && !isset($opt['order']) && !isset($opt['rethumb'])) {
    fwrite(STDERR, "Say what to import: --dry-run, --all, --asset=ID, --order=ID, or --rethumb\n");
    exit(2);
}
if (!mk_column_exists($conn, 'marketing_campaign_assets', 'image_file') || !mk_column_exists($conn, 'marketing_collateral_orders', 'proof_file')) {
    fwrite(STDERR, "sql/creatives_v1.sql has not run here.\n");
    exit(2);
}

// ── --rethumb: stored files with no thumbnail get one now ───────────────────
if (isset($opt['rethumb'])) {
    $made = $failed = 0;
    foreach ([['marketing_campaign_assets', 'image_file', 'image_thumb'], ['marketing_collateral_orders', 'proof_file', 'proof_thumb']] as [$table, $fcol, $tcol]) {
        $r = $conn->query("SELECT id, {$fcol} AS f FROM {$table} WHERE {$fcol} IS NOT NULL AND {$fcol} <> '' AND ({$tcol} IS NULL OR {$tcol} = '') ORDER BY id");
        foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) {
            $stored = basename((string)$row['f']);
            $path   = CREATIVES_DIR . $stored;
            $tag    = sprintf('%-5s %-3d %s', $table === 'marketing_campaign_assets' ? 'asset' : 'order', (int)$row['id'], $stored);
            if (!is_file($path)) { echo "  ⚠   {$tag}: file missing on disk\n"; $failed++; continue; }
            $base = preg_replace('/\.[a-z0-9]+$/i', '', $stored);
            $thumb = strtolower(pathinfo($stored, PATHINFO_EXTENSION)) === 'pdf'
                ? mk_creative_pdf_thumb($path, CREATIVES_DIR . $base . '_thumb.jpg')
                : mk_creative_image_thumb($path, CREATIVES_DIR . $base . '_thumb.jpg');
            if ($thumb === null) { echo "  ⚠   {$tag}: no thumbnail could be made (see the error log)\n"; $failed++; continue; }
            @chgrp(CREATIVES_DIR . $thumb, filegroup(CREATIVES_DIR));
            $st = $conn->prepare("UPDATE {$table} SET {$tcol} = ? WHERE id = ? AND ({$tcol} IS NULL OR {$tcol} = '')");
            $st->bind_param('si', $thumb, $row['id']); $st->execute(); $st->close();
            echo "  ✓   {$tag}: thumbnail made\n"; $made++;
        }
    }
    echo "\n{$made} thumbnails made, {$failed} not.\n";
    exit($failed > 0 ? 1 : 0);
}

/** A Dropbox share link as a direct download, or '' when it cannot be one (a folder, another host). */
function dbx_direct(string $url): string {
    $u = trim($url);
    if (!preg_match('~^https://(www\.)?dropbox\.com/(scl/fi/|s/)~i', $u)) return '';
    $u = preg_replace('/([?&])dl=0(&|$)/', '$1dl=1$2', $u, 1, $n);
    if ($n === 0) $u .= (strpos($u, '?') === false ? '?' : '&') . 'dl=1';
    return $u;
}
/** Why a link cannot be fetched, in words, or '' when it can. */
function dbx_why_not(string $url): string {
    $u = trim($url);
    if ($u === '') return 'no link';
    if (preg_match('~^https://(www\.)?dropbox\.com/scl/fo/~i', $u)) return 'a Dropbox FOLDER link: open it and upload the file by hand';
    if (!preg_match('~^https://(www\.)?dropbox\.com/~i', $u)) return 'not a Dropbox link (' . parse_url($u, PHP_URL_HOST) . ')';
    if (dbx_direct($u) === '') return 'a Dropbox link of an unknown shape';
    return '';
}
/** The file name Dropbox gives, from the link's path (/scl/fi/<id>/<name>?...). */
function dbx_name(string $url): string {
    $path = (string)parse_url($url, PHP_URL_PATH);
    return rawurldecode(basename($path));
}
/**
 * Download to a temp file: [path, orig name, bytes] or ['error' => ...].
 * Stops reading past the size limit rather than pulling a 300 MB print file.
 */
function dbx_fetch(string $direct): array {
    $tmp = tempnam(sys_get_temp_dir(), 'dbx_');
    $fh  = fopen($tmp, 'wb');
    $name = '';
    $ch = curl_init($direct);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 120, CURLOPT_FILE => $fh,
        CURLOPT_USERAGENT => 'MontHausMarketing/1.0 (creative import)',
        CURLOPT_MAXFILESIZE => MK_CREATIVE_MAX_BYTES + 1024,   // Dropbox sends Content-Length, so this stops an oversized file before the first byte
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$name) {
            if (preg_match('/^content-disposition:.*filename\*?=(?:UTF-8\'\')?"?([^";\r\n]+)/i', $line, $m)) $name = rawurldecode(trim($m[1]));
            return strlen($line);
        },
    ]);
    $ok   = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    $bytes = (int)@filesize($tmp);
    if (!$ok && stripos($err, 'exceed') !== false)  { @unlink($tmp); return ['error' => 'over ' . MK_CREATIVE_MAX_MB . ' MB, the upload limit']; }
    if (!$ok)                                         { @unlink($tmp); return ['error' => 'download failed: ' . $err]; }
    if ($code !== 200)                                { @unlink($tmp); return ['error' => "Dropbox answered HTTP {$code}" . ($code === 404 ? ' (link removed or not shared)' : '')]; }
    if ($bytes > MK_CREATIVE_MAX_BYTES)               { @unlink($tmp); return ['error' => 'over ' . MK_CREATIVE_MAX_MB . ' MB, the upload limit']; }
    $mime = (string)mime_content_type($tmp);
    if ($mime === 'text/html')                        { @unlink($tmp); return ['error' => 'Dropbox returned a web page, not the file: the link is not a public share']; }
    return [$tmp, $name, $bytes, $mime];
}

// ── The rows to look at ─────────────────────────────────────────────────────
$rows = [];
$w_asset = isset($opt['asset']) ? ' AND a.id = ' . (int)$opt['asset'] : '';
$w_order = isset($opt['order']) ? ' AND o.id = ' . (int)$opt['order'] : '';
if (!isset($opt['order'])) {
    $r = $conn->query("SELECT a.id, a.label, a.file_url, a.image_file, c.id AS campaign_id, c.name AS campaign, i.agent_name
                         FROM marketing_campaign_assets a JOIN marketing_campaigns c ON c.id = a.campaign_id
                         JOIN marketing_intakes i ON i.id = c.intake_id
                        WHERE a.file_url IS NOT NULL AND a.file_url <> ''{$w_asset} ORDER BY a.id");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $a) $rows[] = ['kind' => 'asset', 'id' => (int)$a['id'], 'who' => $a['agent_name'],
        'what' => trim((string)$a['label']) . ' on ' . trim((string)$a['campaign']), 'url' => (string)$a['file_url'], 'has' => trim((string)$a['image_file']) !== ''];
}
if (!isset($opt['asset'])) {
    $r = $conn->query("SELECT o.id, o.type, o.label, o.file_url, o.proof_file, i.agent_name
                         FROM marketing_collateral_orders o JOIN marketing_intakes i ON i.id = o.intake_id
                        WHERE o.file_url IS NOT NULL AND o.file_url <> ''{$w_order} ORDER BY o.id");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $o) $rows[] = ['kind' => 'order', 'id' => (int)$o['id'], 'who' => $o['agent_name'],
        'what' => trim((string)$o['type']) . ' ' . trim((string)$o['label']), 'url' => (string)$o['file_url'], 'has' => trim((string)$o['proof_file']) !== ''];
}
if (!$rows) { echo "Nothing matches.\n"; exit(0); }

$done = $skipped = 0;
foreach ($rows as $row) {
    $tag = sprintf('%-5s %-3d %s: %s', $row['kind'], $row['id'], $row['who'], $row['what']);
    if ($row['has']) { echo "  -   {$tag}: already has a file, left alone\n"; continue; }
    $why = dbx_why_not($row['url']);
    if ($why !== '') { echo "  ⚠   {$tag}: {$why}\n"; $skipped++; continue; }
    $direct = dbx_direct($row['url']);
    if ($dry) { echo "  →   {$tag}: would fetch " . dbx_name($row['url']) . "\n"; continue; }

    $got = dbx_fetch($direct);
    if (isset($got['error'])) { echo "  ⚠   {$tag}: {$got['error']}\n"; $skipped++; continue; }
    [$tmp, $name, $bytes, $mime] = $got;
    if ($name === '') $name = dbx_name($row['url']);
    $stored = mk_store_creative_from_path($tmp, $name, false);
    @unlink($tmp);
    if (isset($stored['error'])) { echo "  ⚠   {$tag}: {$stored['error']} ({$mime})\n"; $skipped++; continue; }

    // Write only if the row is STILL empty (someone may have uploaded by hand meanwhile).
    if ($row['kind'] === 'asset') {
        $st = $conn->prepare("UPDATE marketing_campaign_assets SET image_file = ?, image_orig_name = ?, image_thumb = ?, image_uploaded_at = NOW()
                               WHERE id = ? AND (image_file IS NULL OR image_file = '')");
    } else {
        $st = $conn->prepare("UPDATE marketing_collateral_orders SET proof_file = ?, proof_orig_name = ?, proof_thumb = ?, proof_uploaded_at = NOW()
                               WHERE id = ? AND (proof_file IS NULL OR proof_file = '')");
    }
    $st->bind_param('sssi', $stored['file'], $stored['orig'], $stored['thumb'], $row['id']);
    $st->execute();
    $changed = $st->affected_rows; $st->close();
    if ($changed < 1) {
        mk_delete_creative_files($stored['file'], $stored['thumb']);
        echo "  -   {$tag}: a file was uploaded by hand meanwhile, left alone\n";
        continue;
    }
    $done++;
    printf("  ✓   %s: %s (%s, %s), thumbnail %s\n", $tag, $stored['orig'], $mime, $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB',
           $stored['thumb'] ? 'made' : 'NOT made (a tile will show)');
}
echo "\n" . ($dry ? 'Dry run. ' : '') . "{$done} stored, {$skipped} skipped.\n";
exit($skipped > 0 ? 1 : 0);
