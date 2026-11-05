<?php
/**
 * One-off (2026-11-04): give every agent photo in agent-photos/ its unique
 * identifier. Files become {slug}-{id}.jpg and {slug}-{id}-square.jpg (the
 * names agent.php has written since the same day) and marketing_intakes is
 * pointed at them; the ?v= is kept, so the website's hourly sync sees the
 * same version and nothing is re-downloaded for nothing.
 *
 *   php cron/photos_add_ids.php            dry run: prints the plan, writes nothing
 *   php cron/photos_add_ids.php --apply    renames the files and updates the rows
 *
 * Why: Sierrah Smith's photos sat at agent.jpg / agent-square.jpg from a
 * blank-name save, and a later agent with the same fallback key had his
 * written over hers on the website. Cutouts (<slug>-cutout.png, Social
 * Media Plan) are left alone: they are keyed by slug and nothing else.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/photos.php';

$apply = in_array('--apply', $argv, true);
$dir   = mk_photo_dir();
$base  = mk_photo_base();

$res = $conn->query("SELECT id, slug, headshot_url, headshot_face_url FROM marketing_intakes
                      WHERE COALESCE(headshot_url, '') <> '' OR COALESCE(headshot_face_url, '') <> ''
                      ORDER BY id");
if (!$res) { fwrite(STDERR, "query failed: {$conn->error}\n"); exit(1); }

$planned = 0; $done = 0;
foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
    $id   = (int)$r['id'];
    $name = (trim((string)$r['slug']) ?: 'agent') . '-' . $id;
    foreach (['headshot_url' => '', 'headshot_face_url' => '-square'] as $col => $suffix) {
        $url = trim((string)$r[$col]);
        if ($url === '' || !str_starts_with($url, $base . '/')) continue;   // not hosted here
        $file  = basename((string)parse_url($url, PHP_URL_PATH));
        $want  = "{$name}{$suffix}.jpg";
        if ($file === $want) continue;
        $planned++;
        $from = "{$dir}/{$file}"; $to = "{$dir}/{$want}";
        $query   = (string)parse_url($url, PHP_URL_QUERY);
        $new_url = "{$base}/{$want}" . ($query !== '' ? "?{$query}" : '');
        if (!is_file($from)) { echo "  ✗ #{$id} {$col}: {$file} is not on disk, left alone\n"; continue; }
        if (is_file($to))    { echo "  ✗ #{$id} {$col}: {$want} already exists, left alone\n"; continue; }
        echo "  #{$id} {$col}: {$file} → {$want}\n";
        if (!$apply) continue;
        if (!rename($from, $to)) { echo "    ✗ rename failed (permissions?)\n"; continue; }
        mk_photo_written($to);
        $st = $conn->prepare("UPDATE marketing_intakes SET `{$col}` = ? WHERE id = ?");
        $st->bind_param('si', $new_url, $id); $st->execute(); $st->close();
        $done++;
    }
}
echo $apply ? "✓ {$done} of {$planned} renamed and re-pointed.\n"
            : "DRY RUN: {$planned} file(s) to rename. Run with --apply to do it.\n";
