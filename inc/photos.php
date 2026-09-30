<?php
/**
 * inc/photos.php — agent headshots, stored by this portal.
 *
 * The website only publishes photos we host (api/roster.php: a Dropbox share
 * link is a page, not an image), so an upload here becomes two JPEGs in
 * agent-photos/ and the feed hands the site their URLs.
 *
 *   <slug>.jpg          profile photo, long side 1400px
 *   <slug>-square.jpg   600x600, cropped from the top middle, for cards and lists
 *
 * Same conversion as cron/import_profiles.php, so a bulk import and a
 * one-off upload produce the same files.
 */

require_once __DIR__ . '/config.php';

function mk_photo_dir(): string  { return __DIR__ . '/../agent-photos'; }

/**
 * Saving a photo fails when the folder belongs to whoever ran the bulk import
 * over SSH rather than to the web server. Say so, with the fix (2026-09-25).
 */
function mk_photo_perm_hint(): string {
    // Two users write here: the web server (uploads and crops) and whoever runs
    // the bulk import over SSH. Shared group plus setgid keeps both working.
    return 'The photo could not be written. Run: sudo chown -R www-data:www-data '
         . '/var/www/marketing.monthaus.com/agent-photos && sudo chmod -R 2775 '
         . '/var/www/marketing.monthaus.com/agent-photos';
}

/** Leave new files group-writable, so the other user can replace them later. */
function mk_photo_written(string $file): void { @chmod($file, 0664); }
function mk_photo_base(): string { return rtrim(SITE_URL, '/') . '/agent-photos'; }

/**
 * Store one uploaded file. Returns null when nothing was uploaded,
 * ['error' => '...'] when it could not be used, otherwise
 * ['url' => '...', 'square_url' => '...'] (square_url only for a profile photo).
 * $name is the file name without extension; $face crops it square.
 */
function mk_store_photo(string $field, string $name, bool $face): ?array {
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if ($err !== UPLOAD_ERR_OK) {
        return ['error' => [
            UPLOAD_ERR_INI_SIZE  => 'That image is larger than the server allows (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE => 'That image is larger than the form allows.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
        ][$err] ?? "Upload failed (code {$err})."];
    }
    $tmp = $_FILES[$field]['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) return ['error' => 'The upload did not arrive. Please try again.'];
    if (!function_exists('imagecreatefromstring')) return ['error' => 'Image support (PHP GD) is not installed on the server.'];
    if (($_FILES[$field]['size'] ?? 0) > 12 * 1024 * 1024) return ['error' => 'That image is over 12 MB. Please use a smaller one.'];

    $srgb = mk_photo_srgb($tmp);
    $img  = @imagecreatefromstring((string)file_get_contents($srgb['file']));
    if ($srgb['file'] !== $tmp) @unlink($srgb['file']);
    if (!$img) return ['error' => 'That file could not be read as an image. Use JPG, PNG or WEBP.'];
    // Phone photos carry their rotation in EXIF rather than in the pixels.
    if (function_exists('exif_read_data')) {
        $e = @exif_read_data($tmp);
        $deg = [3 => 180, 6 => -90, 8 => 90][$e['Orientation'] ?? 0] ?? 0;
        if ($deg) { $r = imagerotate($img, $deg, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }
    $dir = mk_photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['error' => 'The agent-photos folder could not be created on the server.'];
    if (!is_writable($dir)) return ['error' => mk_photo_perm_hint()];

    $v = time();
    $out = ['note' => $srgb['note']];
    if ($face) {
        if (!@imagejpeg(mk_photo_square($img, 600), "{$dir}/{$name}.jpg", 88)) { imagedestroy($img); return ['error' => mk_photo_perm_hint()]; }
        mk_photo_written("{$dir}/{$name}.jpg");
        $out['url'] = mk_photo_base() . "/{$name}.jpg?v={$v}";
    } else {
        if (!@imagejpeg(mk_photo_scale($img, 1400), "{$dir}/{$name}.jpg", 88)) { imagedestroy($img); return ['error' => mk_photo_perm_hint()]; }
        mk_photo_written("{$dir}/{$name}.jpg");
        $out['url'] = mk_photo_base() . "/{$name}.jpg?v={$v}";
        $sq = preg_replace('/(-square)?$/', '-square', $name, 1);
        if (@imagejpeg(mk_photo_square($img, 600), "{$dir}/{$sq}.jpg", 88)) {
            mk_photo_written("{$dir}/{$sq}.jpg");
            $out['square_url'] = mk_photo_base() . "/{$sq}.jpg?v={$v}";
        }
    }
    imagedestroy($img);
    return $out;
}

function mk_photo_scale(GdImage $src, int $max): GdImage {
    $w = imagesx($src); $h = imagesy($src);
    $s = min(1, $max / max($w, $h));
    if ($s >= 1) return $src;
    $out = imagecreatetruecolor((int)round($w * $s), (int)round($h * $s));
    imagecopyresampled($out, $src, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    return $out;
}

/** Square crop from the top middle: where a head is in a headshot. */
function mk_photo_square(GdImage $src, int $size): GdImage {
    $w = imagesx($src); $h = imagesy($src);
    $side = min($w, $h);
    $x = (int)round(($w - $side) / 2);
    $y = (int)round(min(max(0, ($h - $side) * 0.15), $h - $side));
    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $src, 0, 0, $x, $y, $size, $size, $side, $side);
    return $out;
}

/**
 * Store a square crop the browser made (the crop tool sends a data URL).
 * Returns the new URL, or null when there is nothing usable.
 */
function mk_store_photo_data(string $data, string $name): ?array {
    if (!str_starts_with($data, 'data:image/')) return null;
    $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
    if ($bin === false) return ['error' => 'The crop could not be read. Please try again.'];
    $img = @imagecreatefromstring($bin);
    if (!$img) return ['error' => 'The crop could not be read as an image.'];
    $dir = mk_photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['error' => 'The agent-photos folder could not be created on the server.'];
    $out = imagescale($img, 600, 600, IMG_BICUBIC) ?: $img;
    $ok = @imagejpeg($out, "{$dir}/{$name}.jpg", 88);
    imagedestroy($img);
    if (!$ok) return ['error' => mk_photo_perm_hint()];
    mk_photo_written("{$dir}/{$name}.jpg");
    return ['url' => mk_photo_base() . "/{$name}.jpg?v=" . time()];
}

/**
 * Crop a piece out of an image already on disk (or a just-uploaded file), the
 * way the crop tool framed it, and save it at $out_w x $out_h. The browser
 * sends the box in the picture's own pixels, so nothing depends on it being
 * able to export a canvas: a photo from another site taints the canvas and the
 * export throws, which is how a crop could look right on screen and save
 * nothing (2026-09-23). Square for the face, 4:5 for the profile photo, which
 * is the shape of the website's broker cards (2026-09-27).
 */
function mk_crop_photo(string $src_file, array $box, string $name, int $out_w = 600, int $out_h = 600): array {
    if (!is_file($src_file)) return ['error' => 'The photo to crop from is not stored here. Upload it as the profile photo first.'];
    $srgb = mk_photo_srgb($src_file);
    $img  = @imagecreatefromstring((string)file_get_contents($srgb['file']));
    if ($srgb['file'] !== $src_file) @unlink($srgb['file']);
    if (!$img) return ['error' => 'That photo could not be read as an image.'];
    $w = imagesx($img); $h = imagesy($img);
    $bw = (int)round($box['w'] ?? 0); $bh = (int)round($box['h'] ?? 0);
    if ($bw < 8 || $bh < 8) { imagedestroy($img); return ['error' => 'That crop is too small.']; }
    // The tool allows a box that hangs off the edge; keep it inside the picture
    // without changing its shape.
    $scale = min(1, $w / $bw, $h / $bh);
    $bw = max(8, (int)round($bw * $scale)); $bh = max(8, (int)round($bh * $scale));
    $x = max(0, min((int)round($box['x'] ?? 0), $w - $bw));
    $y = max(0, min((int)round($box['y'] ?? 0), $h - $bh));
    $cut = @imagecrop($img, ['x' => $x, 'y' => $y, 'width' => $bw, 'height' => $bh]);
    if (!$cut) { imagedestroy($img); return ['error' => 'That crop could not be made.']; }
    $out = imagescale($cut, $out_w, $out_h, IMG_BICUBIC) ?: $cut;
    $dir = mk_photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['error' => 'The agent-photos folder could not be created on the server.'];
    if (!is_writable($dir)) return ['error' => mk_photo_perm_hint()];
    $ok = @imagejpeg($out, "{$dir}/{$name}.jpg", 88);
    imagedestroy($img);
    if (!$ok) return ['error' => mk_photo_perm_hint()];
    mk_photo_written("{$dir}/{$name}.jpg");
    return ['url' => mk_photo_base() . "/{$name}.jpg?v=" . time(), 'note' => $srgb['note']];
}

/** The local file behind one of our own photo URLs, or '' when it is elsewhere. */
function mk_photo_file(?string $url): string {
    $u = trim((string)$url);
    if ($u === '' || !str_starts_with($u, mk_photo_base() . '/')) return '';
    $base = basename(parse_url($u, PHP_URL_PATH) ?: '');
    return $base === '' ? '' : mk_photo_dir() . '/' . $base;
}

/** "8M", "25M", "1G" as bytes: PHP's own limits, for messages and checks. */
function mk_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
}

/**
 * Colour profiles. A photo exported as Adobe RGB or Display P3 carries a
 * profile that says "these numbers are wider than sRGB". GD cannot read
 * profiles: it copies the raw numbers into a plain file, browsers read them as
 * sRGB, and skin goes red (Nikki, 2026-09-28). So: convert when the server can,
 * and say so plainly when it cannot.
 */
function mk_srgb_profile_path(): string { return __DIR__ . '/../assets/icc/sRGB.icc'; }

/** The name of the file's colour profile when it is NOT sRGB, else null. */
function mk_photo_profile_name(string $file): ?string {
    $head = (string)@file_get_contents($file, false, null, 0, 262144);
    if ($head === '' || !str_contains($head, 'ICC_PROFILE')) {
        // No profile. EXIF can still say the numbers are not sRGB.
        if (function_exists('exif_read_data')) {
            $e = @exif_read_data($file);
            if (isset($e['ColorSpace']) && (int)$e['ColorSpace'] === 65535) return 'an uncalibrated profile';
        }
        return null;
    }
    foreach (['Adobe RGB' => 'Adobe RGB', 'Display P3' => 'Display P3', 'DisplayP3' => 'Display P3',
              'ProPhoto' => 'ProPhoto RGB', 'Apple Wide' => 'Apple Wide Color'] as $needle => $name) {
        if (stripos($head, $needle) !== false) return $name;
    }
    if (stripos($head, 'sRGB') !== false) return null;
    return 'a colour profile that is not sRGB';
}

/**
 * Hand back a file whose numbers really are sRGB. Returns
 * ['file' => path to use, 'note' => what to tell her, or null].
 * The converted file is a temporary one; the caller reads it and forgets it.
 */
function mk_photo_srgb(string $file): array {
    $name = mk_photo_profile_name($file);
    if ($name === null) return ['file' => $file, 'note' => null];

    if (class_exists('Imagick') && is_file(mk_srgb_profile_path())) {
        try {
            $im = new Imagick($file);
            $im->profileImage('icc', (string)file_get_contents(mk_srgb_profile_path()));
            $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(95);
            $tmp = tempnam(sys_get_temp_dir(), 'mhsrgb');
            $im->writeImage($tmp);
            $im->clear();
            return ['file' => $tmp, 'note' => null];
        } catch (Throwable $e) { /* fall through to the note */ }
    }
    $lead = in_array($name, ['a colour profile that is not sRGB', 'an uncalibrated profile'], true)
          ? 'This photo carries ' . $name
          : 'This photo is tagged ' . $name . ' rather than sRGB';
    return ['file' => $file, 'note' => $lead . ', so its colours can look oversaturated on the web (skin tends to go '
          . 'red). Re-export it as sRGB, or ask for php8.4-imagick to be installed on the server and we will convert '
          . 'it here from now on.'];
}
