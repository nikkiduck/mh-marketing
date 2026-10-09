<?php
/**
 * inc/creatives.php — storing, copying and removing creative files: the ad
 * image on an advertising creative (marketing_campaign_assets.image_*) and
 * the proof on a collateral order (marketing_collateral_orders.proof_*).
 * sql/creatives_v1.sql; docs/AGENT_PORTAL_PLAN.md section 5.
 *
 * Same shape as inc/receipts.php, with one addition: a small JPEG thumbnail
 * is made ONCE here, at upload, and stored beside the original. The portal's
 * cards show the thumbnail; nothing ever resizes an original on page view
 * (the box has 1 GB of RAM and a phone card does not need a 20 MB print file).
 *
 * Files live in CREATIVES_DIR, outside the web root, and are only ever served
 * through creative.php (admin) and portal/asset.php (the owning agent), by id.
 */

/** What an upload may be, by sniffed MIME type, and the extension it is stored under. */
const MK_CREATIVE_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'application/pdf' => 'pdf',
];
const MK_CREATIVE_MAX_BYTES = 8 * 1024 * 1024;   // 8 MB: plenty for a web-size ad or proof, kind to the box
const MK_CREATIVE_THUMB_PX  = 720;               // longest side of the thumbnail; fills a phone card at 2x

/**
 * Save an uploaded creative and return ['file', 'orig', 'thumb'] (thumb may
 * be null), ['error' => ...] when the upload is unacceptable, or null when
 * no file was submitted (not an error: every form that takes one is optional).
 */
function mk_store_creative(string $field): ?array {
    if (empty($_FILES[$field]['tmp_name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) {
        // A file that was chosen but refused by PHP (over upload_max_filesize,
        // say) arrives with an error code and no tmp_name: say so rather than
        // saving the rest of the form as if nothing had been chosen.
        $code = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) return ['error' => 'That file is too large (8 MB at most).'];
        if ($code !== UPLOAD_ERR_NO_FILE) return ['error' => 'The file did not upload. Please try again.'];
        return null;
    }
    return mk_store_creative_from_path($_FILES[$field]['tmp_name'], (string)($_FILES[$field]['name'] ?? ''), true);
}

/**
 * Store a creative that is already on disk: the upload above, or a file a
 * script fetched (cron/import_dropbox_creatives.php, 2026-10-09). Same
 * checks, same names, same thumbnail; $uploaded says the path is a PHP
 * upload (moved with move_uploaded_file) rather than an ordinary file
 * (renamed). Returns ['file', 'orig', 'thumb'] or ['error' => ...].
 */
function mk_store_creative_from_path(string $path, string $orig_name, bool $uploaded = false): array {
    $mime = function_exists('mime_content_type') ? (string)mime_content_type($path) : '';
    if (!isset(MK_CREATIVE_TYPES[$mime]))              return ['error' => 'The file must be a JPG, PNG, GIF or PDF.'];
    if ((int)@filesize($path) > MK_CREATIVE_MAX_BYTES) return ['error' => 'That file is too large (8 MB at most).'];

    $dir = CREATIVES_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 02775, true)) return ['error' => 'Could not create the creatives folder on the server.'];

    $ext    = MK_CREATIVE_TYPES[$mime];
    $base   = 'creative_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
    $stored = "{$base}.{$ext}";
    $ok = $uploaded ? move_uploaded_file($path, $dir . $stored) : @rename($path, $dir . $stored);
    if (!$ok) return ['error' => 'Failed to save the file.'];
    @chmod($dir . $stored, 0664);   // group-writable like every other file here (CLAUDE.md > Deploying files)

    $thumb = $mime === 'application/pdf' ? mk_creative_pdf_thumb($dir . $stored, $dir . $base . '_thumb.jpg')
                                         : mk_creative_image_thumb($dir . $stored, $dir . $base . '_thumb.jpg');

    $orig = preg_replace('/[^A-Za-z0-9._ -]/', '_', $orig_name !== '' ? $orig_name : $stored);
    return ['file' => $stored, 'orig' => $orig, 'thumb' => $thumb];
}

/**
 * A JPEG thumbnail of an image, MK_CREATIVE_THUMB_PX on its longest side.
 * Returns the stored thumbnail name, or null when one cannot be made (no GD,
 * an image too big to decode within memory_limit, a broken file): the card
 * then shows a generic tile and the original is still there to open.
 */
function mk_creative_image_thumb(string $src, string $dst): ?string {
    if (!function_exists('imagecreatefromstring')) return null;
    $info = @getimagesize($src);
    if (!$info || $info[0] < 1 || $info[1] < 1) return null;
    [$w, $h] = $info;
    // Decoding needs about 5 bytes per pixel (RGBA plus GD's own copy).
    // Leave the rest of the request its memory, and skip rather than crash.
    $limit = mk_creative_ini_bytes((string)ini_get('memory_limit'));
    if ($limit > 0 && $w * $h * 5 > $limit - memory_get_usage() - 16 * 1024 * 1024) return null;

    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) return null;
    $scale = min(1, MK_CREATIVE_THUMB_PX / max($w, $h));
    $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($tw, $th);
    // Transparent PNG and GIF creatives sit on white, as they would on paper.
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($im);
    $ok = imagejpeg($out, $dst, 84);
    imagedestroy($out);
    if (!$ok) return null;
    @chmod($dst, 0664);
    return basename($dst);
}

/**
 * First page of a PDF as a JPEG, when Imagick (with Ghostscript behind it) is
 * on the server. Otherwise null, and the card shows a PDF tile: the portal
 * looks best with images uploaded, and the admin form says so.
 */
function mk_creative_pdf_thumb(string $src, string $dst): ?string {
    if (!class_exists('Imagick')) return null;
    try {
        $im = new Imagick();
        $im->setResolution(72, 72);
        $im->readImage($src . '[0]');
        $im->setImageBackgroundColor('white');
        $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $im->thumbnailImage(MK_CREATIVE_THUMB_PX, MK_CREATIVE_THUMB_PX, true);
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(84);
        $ok = $im->writeImage($dst);
        $im->clear();
        if (!$ok) return null;
        @chmod($dst, 0664);
        return basename($dst);
    } catch (Throwable $e) {
        error_log('mk_creative_pdf_thumb: ' . $e->getMessage());
        return null;
    }
}

/** Remove a creative and its thumbnail from disk. Silent if already gone. */
function mk_delete_creative_files(?string $stored, ?string $thumb): void {
    foreach ([$stored, $thumb] as $f) {
        $f = trim((string)$f);
        if ($f === '') continue;
        $p = CREATIVES_DIR . basename($f);
        if (is_file($p)) @unlink($p);
    }
}

/**
 * A second physical copy of a creative and its thumbnail, under new names:
 * [file, thumb], either null when missing or when the copy fails. Every
 * record owns its files (CLAUDE.md > Receipts are copied per order, not
 * shared): removing the creative on one agent's copy of a shared placement
 * unlinks the file, and a shared file would break the picture for the others.
 */
function mk_copy_creative_files(?string $stored, ?string $thumb): array {
    $stored = trim((string)$stored);
    if ($stored === '' || !is_file(CREATIVES_DIR . basename($stored))) return [null, null];
    $ext  = pathinfo($stored, PATHINFO_EXTENSION) ?: 'jpg';
    $base = 'creative_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
    if (!@copy(CREATIVES_DIR . basename($stored), CREATIVES_DIR . "{$base}.{$ext}")) return [null, null];
    @chmod(CREATIVES_DIR . "{$base}.{$ext}", 0664);
    $new_thumb = null;
    $thumb = trim((string)$thumb);
    if ($thumb !== '' && is_file(CREATIVES_DIR . basename($thumb))
        && @copy(CREATIVES_DIR . basename($thumb), CREATIVES_DIR . "{$base}_thumb.jpg")) {
        $new_thumb = "{$base}_thumb.jpg";
        @chmod(CREATIVES_DIR . $new_thumb, 0664);
    }
    return ["{$base}.{$ext}", $new_thumb];
}

/**
 * Stream a stored creative (or its thumbnail) with the hardening receipt.php
 * uses: basename() on the stored name, a MIME allowlist, nosniff, no-store.
 * Both creative.php and portal/asset.php call this AFTER their own ownership
 * or role check; it never decides who may see a file, only how it is sent.
 */
function mk_send_creative(?string $stored, ?string $orig_name, bool $download = false): never {
    $stored = basename(trim((string)$stored));
    $path   = $stored !== '' ? CREATIVES_DIR . $stored : '';
    if ($path === '' || !is_file($path)) { http_response_code(404); exit('That file could not be found.'); }
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
    if (!isset(MK_CREATIVE_TYPES[$mime])) $mime = 'application/octet-stream';
    $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($orig_name ?: $stored));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-store');
    readfile($path);
    exit;
}

/** "128M" style ini values in bytes; 0 for unlimited or unknown. */
function mk_creative_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // fall through
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/**
 * After a campaign's creative rows were copied with INSERT ... SELECT (share,
 * duplicate), give every copied row its OWN files: the SELECT carried the
 * source's file names, so until this runs two rows point at one file.
 * Returns the files it made (for cleanup if the surrounding save fails). A
 * copy that cannot be made leaves the row with no image rather than a
 * borrowed one.
 */
function mk_own_asset_files(mysqli $conn, int $campaign_id): array {
    $made = [];
    $r = $conn->query("SELECT id, image_file, image_thumb FROM marketing_campaign_assets WHERE campaign_id = {$campaign_id} AND image_file IS NOT NULL");
    $u = $conn->prepare("UPDATE marketing_campaign_assets SET image_file = ?, image_thumb = ? WHERE id = ?");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $a) {
        [$f, $t] = mk_copy_creative_files($a['image_file'], $a['image_thumb']);
        foreach ([$f, $t] as $m) if ($m !== null) $made[] = $m;
        $aid = (int)$a['id'];
        $u->bind_param('ssi', $f, $t, $aid);
        $u->execute();
    }
    $u->close();
    return $made;
}
