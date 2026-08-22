<?php
/**
 * db.sample.php
 *
 * Copy to db.php on the server and fill in the values. db.php is gitignored
 * and denied by .htaccess — it never belongs in the repo.
 *
 *   cp deploy/db.sample.php inc/db.php
 *   chmod 640 inc/db.php && sudo chown admin:www-data inc/db.php
 *
 * This site has its own database — a Lightsail managed MySQL 8.4 instance,
 * not the hub's. See README.md and DEPLOY_LIGHTSAIL.md.
 */

// ── Spark MLS API ────────────────────────────────────────────────────────────
// Used by agent.php, mls_key_debug.php and the two sync crons.
define('SPARK_ACCESS_TOKEN',      'PASTE_ASPEN_TOKEN');   // Aspen/Glenwood MLS
define('VAIL_SPARK_ACCESS_TOKEN', 'PASTE_VAIL_TOKEN');    // Vail MLS
define('VAIL_OFFICE_MLS_ID',      'oaltixrealty');        // Mont Haus office, Vail board
define('ASPEN_OFFICE_ID',         'PASTE_ASPEN_OFFICE_ID');

// ── Other services ───────────────────────────────────────────────────────────
define('SENDGRID_API_KEY', 'PASTE_SENDGRID_KEY');   // mailer.php — password resets
define('LOFTY_API_KEY',    'PASTE_LOFTY_KEY');

// ── Database ─────────────────────────────────────────────────────────────────
// The endpoint from the Lightsail console, Connect tab. Something like
// ls-xxxxxxxx.xxxxxxxx.us-west-2.rds.amazonaws.com
define('DB_HOST', 'PASTE_MANAGED_DB_ENDPOINT');
define('DB_PORT', 3306);
define('DB_USER', 'mhadmin');                 // see the note on a limited user below
define('DB_PASS', 'PASTE_DB_PASSWORD');
define('DB_NAME', 'dbmarketing_monthaus');    // Lightsail prefixes the name with "db"

/**
 * TLS.
 *
 * The database is no longer on this machine — every query crosses the network,
 * even if that network is AWS's private one. Without TLS the password and all
 * row data travel in cleartext.
 *
 * Note this is a *confidentiality* requirement, not a connectivity one: PHP 8's
 * mysqlnd handles MySQL 8.4's caching_sha2_password over an unencrypted socket
 * quite happily, so an un-encrypted connection would appear to work fine. That
 * is exactly why it is worth being deliberate here.
 *
 * The CA bundle is AWS's, downloaded once on the server:
 *
 *   sudo mkdir -p /etc/ssl/aws
 *   sudo curl -o /etc/ssl/aws/rds-global-bundle.pem \
 *        https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem
 *   sudo chmod 644 /etc/ssl/aws/rds-global-bundle.pem
 */
define('DB_SSL_CA', '/etc/ssl/aws/rds-global-bundle.pem');

$conn = mysqli_init();
if (!$conn) {
    error_log('mysqli_init() failed');
    die('A database error occurred. Please try again later.');
}

$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
$conn->ssl_set(null, null, DB_SSL_CA, null, null);

if (!@$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, null, MYSQLI_CLIENT_SSL)) {
    // Deliberately vague to the browser; the detail goes to the error log.
    error_log('Database connection failed: ' . mysqli_connect_error());
    die('A database error occurred. Please try again later.');
}

$conn->set_charset('utf8mb4');

/**
 * Drop ONLY_FULL_GROUP_BY for this connection.
 *
 * This is not optional and it is not cosmetic. The roster queries in
 * index.php and roster.php select non-aggregated columns alongside a
 * GROUP BY. SiteGround's MySQL had ONLY_FULL_GROUP_BY switched off, so they
 * ran fine there for months; stock MySQL 8 enables it by default and both
 * pages die with a 500 on the very first request. Verified by importing the
 * schema into a clean MySQL 8 and loading each page.
 *
 * Setting it per-connection rather than on the server means the app carries
 * its own requirement — it survives a MySQL upgrade, a restored snapshot, or
 * a move to a different database, none of which preserve a parameter-group
 * change.
 *
 * Everything else in the default mode is kept, strict mode included.
 *
 * The real fix is to rewrite those two queries with ANY_VALUE() or a proper
 * GROUP BY, at which point this line can go. Until then, it stays.
 */
$conn->query(
    "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
  . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
);

// Cron scripts and page requests should agree on what "now" means.
// The instance clock is UTC; keep the session there too.
$conn->query("SET time_zone = '+00:00'");


/* ─────────────────────────────────────────────────────────────────────────────
 * Use a limited user here, not the Lightsail master account.
 *
 * The master user can DROP and ALTER. This file is read by every web request,
 * so it should hold credentials that cannot destroy anything. Connect once as
 * the master user and create the app's own:
 *
 *   CREATE USER 'mh_app'@'%' IDENTIFIED BY '<a long random password>';
 *   GRANT SELECT, INSERT, UPDATE, DELETE ON dbmarketing_monthaus.* TO 'mh_app'@'%';
 *   FLUSH PRIVILEGES;
 *
 * Then set DB_USER to 'mh_app' above, and keep the master credentials for
 * migrations you run by hand.
 *
 * '@%' rather than '@localhost' because the app connects over the network now.
 * The database is not publicly reachable — confirm public mode is OFF on the
 * Lightsail Networking tab — so the host wildcard is bounded by the VPC.
 * ───────────────────────────────────────────────────────────────────────────── */
