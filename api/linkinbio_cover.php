<?php
/**
 * api/linkinbio_cover.php — one Link in Bio cover picture (inc/linkinbio.php,
 * 2026-11-12): ?id=<16 hex>. The website downloads it from here when the
 * roster feed's `linkinbio` list names it; the admin page shows it too.
 *
 * NO sign-in, on purpose, like api/roster.php: the website fetches it with no
 * session, and a cover is a picture already posted on Instagram. Only files
 * the store made (CREATIVES_DIR/social/linkinbio/<id>.jpg) can be reached:
 * the id is 16 hex characters and nothing else.
 */
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/linkinbio.php';

$f = bio_cover_file((string)($_GET['id'] ?? ''));
if ($f === '' || !is_file($f)) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($f));
header('Cache-Control: public, max-age=31536000, immutable');   // the address carries ?v=, which moves on when the picture is remade
header('X-Robots-Tag: noindex');
readfile($f);
