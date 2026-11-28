<?php
/**
 * templates.php — the admin Templates page until 2026-11-28, when Nikki had
 * it combined with Social Media ("It's weird that they are two sections").
 * Its Instagram tools are on social_media.php now and its print tools on
 * digital_print.php (Digital + Print). This file only sends an old link or
 * bookmark on to Social Media, keeping the chosen agent.
 *
 * The agents' own Templates page is portal/templates.php and is untouched.
 */
require_once __DIR__ . '/inc/social.php';   // auth, config and the database, as the page had
require_login();
$agent = (int)($_GET['agent'] ?? 0);
header('Location: /social_media.php' . ($agent > 0 ? '?agent=' . $agent : ''), true, 302);
exit;
