<?php
/**
 * portal.php — shared code for the agent portal (portal/*.php).
 * Plan: docs/AGENT_PORTAL_PLAN.md. Built 2026-10-01.
 *
 * THE RULE: a portal page never takes an agent or account id from the request.
 * portal_context() works out whose portal this is from the signed-in user
 * (users.intake_id, read from the database on every request) and every query on
 * every portal page is scoped by the ids it returns. When a page acts on one
 * item it was handed an id for (a QR code, say), it checks that item belongs to
 * the context's ids before touching it.
 *
 * Admins may preview any account with ?preview=<intake id>; the page then
 * carries a banner, and portal_url() keeps the parameter on every link and
 * form. A non-admin's ?preview= is ignored.
 *
 * Every refusal is a 403 page that says which gate stopped the person, never a
 * redirect (see require_role() in auth.php for why a guard must not redirect).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

/** Where agents' questions and requests go (Nikki, 2026-10-01: the team inbox, not a person). */
if (!defined('PORTAL_MARKETING_EMAIL')) define('PORTAL_MARKETING_EMAIL', 'marketing@monthaus.com');

/**
 * Email the marketing inbox from the portal: SendGrid's API from a monthaus.com
 * address, with Reply-To set to the agent so marketing can simply reply.
 * Deliberately NOT hs_send_email(): that one only mails
 * HOT_SHEET_ALLOWED_RECIPIENTS until Hot Sheets go live, and would silently
 * block marketing@. Returns ['sent'|'failed', detail].
 */
function portal_send_mail(string $subject, string $html, string $reply_email, string $reply_name): array {
    if (!defined('SENDGRID_API_KEY') || SENDGRID_API_KEY === '') return ['failed', 'SENDGRID_API_KEY not set'];
    $text = trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>#i', '#</(tr|div|p)>#i'], ["\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $payload = [
        'personalizations' => [['to' => [['email' => PORTAL_MARKETING_EMAIL]]]],
        'from'     => ['email' => 'portal@monthaus.com', 'name' => 'Mont Haus Agent Portal'],
        'subject'  => $subject,
        'content'  => [['type' => 'text/plain', 'value' => $text], ['type' => 'text/html', 'value' => $html]],
    ];
    if (filter_var($reply_email, FILTER_VALIDATE_EMAIL)) $payload['reply_to'] = ['email' => $reply_email, 'name' => $reply_name];
    $ch = curl_init(getenv('MH_SENDGRID_URL') ?: 'https://api.sendgrid.com/v3/mail/send');   // env: test harness only
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SENDGRID_API_KEY, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($code >= 200 && $code < 300) return ['sent', "HTTP {$code}"];
    error_log("portal_send_mail: HTTP {$code} {$err} " . substr($body, 0, 300));
    return ['failed', "HTTP {$code}" . ($err ? " {$err}" : '')];
}

/**
 * Change alert (Nikki, 2026-10-12): anything an agent changes on their portal
 * (phone, bio, social handles, Hot Sheet subscription) is emailed to
 * marketing@ with who, what, before, after and when, and left as a note on
 * their agent page (marketing_notes), so the admin side sees it too.
 * $changes: [[field label, before, after], ...]; nothing is sent for [].
 * Returns 'sent' | 'failed' | 'none'.
 */
function portal_change_alert(mysqli $conn, array $ctx, string $what, array $changes): string {
    if (!$changes) return 'none';
    $u    = $ctx['user'];
    $who  = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']) ?: (string)$u['email'];
    $acct = (string)$ctx['acct']['agent_name'];
    $when = (new DateTime('now', new DateTimeZone('America/Denver')))->format('D, M j, Y g:i A T');
    $cut  = fn($v) => mb_strlen((string)$v) > 600 ? mb_substr((string)$v, 0, 600) . '…' : (string)$v;
    $e    = fn($v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), false);
    $lines = []; $rows = '';
    foreach ($changes as [$label, $before, $after]) {
        $b = trim(html_entity_decode(strip_tags((string)$before), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $a = trim(html_entity_decode(strip_tags((string)$after),  ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $lines[] = "- {$label}: " . ($b === '' ? '(blank)' : '"' . $cut($b) . '"') . ' → ' . ($a === '' ? '(blank)' : '"' . $cut($a) . '"');
        $rows .= '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;vertical-align:top;white-space:nowrap;">' . $e($label) . '</td>'
               . '<td style="padding:6px 12px 6px 0;vertical-align:top;color:#6b7280;">' . ($b === '' ? '—' : $e($cut($b))) . '</td>'
               . '<td style="padding:6px 0;vertical-align:top;">' . ($a === '' ? '—' : $e($cut($a))) . '</td></tr>';
    }
    // The note on their agent page.
    if (mk_table_exists($conn, 'marketing_notes')) {
        $content = "Portal: {$who} changed their {$what} ({$when})\n" . implode("\n", $lines);
        $iid = (int)$ctx['acct']['id']; $uid = (int)$u['id'];
        $st = $conn->prepare("INSERT INTO marketing_notes (intake_id, content, created_by) VALUES (?, ?, ?)");
        if ($st) { $st->bind_param('isi', $iid, $content, $uid); $st->execute(); $st->close(); }
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;">'
          . '<p><strong>' . $e($who) . '</strong> (' . $e($acct) . ') changed their <strong>' . $e($what) . '</strong> on the agent portal, ' . $e($when) . '.</p>'
          . '<table style="border-collapse:collapse;font-size:14px;"><tr><th style="text-align:left;padding:0 12px 6px 0;color:#6b7280;font-weight:normal;">What</th>'
          . '<th style="text-align:left;padding:0 12px 6px 0;color:#6b7280;font-weight:normal;">Before</th><th style="text-align:left;padding:0 0 6px;color:#6b7280;font-weight:normal;">After</th></tr>'
          . $rows . '</table>'
          . '<p style="margin-top:18px;"><a href="' . htmlspecialchars(rtrim(SITE_URL, '/') . '/agent.php?id=' . (int)$ctx['acct']['id']) . '" '
          . 'style="background:#0d0c0a;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px;display:inline-block;">Open their agent page</a></p>'
          . '<p style="color:#6b7280;font-size:12px;">The website picks profile changes up within the hour. Reply to this email to reach ' . $e($who) . ' directly.</p></div>';
    [$status] = portal_send_mail("Profile change: {$acct} ({$what})", $html, (string)$u['email'], $who);
    return $status;
}

/**
 * The account's live listings (and its teams'), from the Hot Sheet feed copy
 * (hs_listing_state): matched by MLS identity, else by name, like the
 * Instagram tool's social_listings(); one property on two boards is one row.
 */
function portal_listings(mysqli $conn, array $ctx): array {
    if (!mk_table_exists($conn, 'hs_listing_state')) return [];
    $ids = implode(',', array_map('intval', $ctx['ids']));   // from portal_context, never the request
    $conds = []; $types = ''; $vals = [];
    $r = $conn->query("SELECT DISTINCT market, mls_agent_id FROM agent_mls_ids WHERE intake_id IN ({$ids}) AND mls_agent_id <> ''");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $i) {
        $conds[] = '(market = ? AND (list_agent_mls_id = ? OR colist_agent_mls_id = ?))';
        $types .= 'sss'; array_push($vals, $i['market'], $i['mls_agent_id'], $i['mls_agent_id']);
    }
    foreach ($ctx['scopes'] as $sc) {
        $n = strtolower(trim((string)$sc['name']));
        if ($n === '') continue;
        $conds[] = '(LOWER(list_agent_name) = ? OR LOWER(colist_agent_name) = ?)';
        $types .= 'ss'; array_push($vals, $n, $n);
    }
    if (!$conds) return [];
    $st = $conn->prepare("SELECT market, listing_key, mls_id, status, is_rental, property_type, address, city, list_price, beds, baths, sqft, primary_photo, url
                            FROM hs_listing_state
                           WHERE in_feed = 1 AND status IN ('Active','Active Under Contract','Pending','Coming Soon')
                             AND (" . implode(' OR ', $conds) . ")
                           ORDER BY (market = 'recolorado'), is_rental, list_price DESC, address");
    $st->bind_param($types, ...$vals); $st->execute();
    $out = []; $seen = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
        $k = strtolower(preg_replace('/\s+/', ' ', trim((string)$l['address']))) . '|' . strtolower(trim((string)$l['city'])) . '|' . (int)$l['is_rental'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true; $out[] = $l;
    }
    $st->close();
    return $out;
}

/**
 * The account's pocket (off-MLS) listings (sql/pocket_listings_v1.sql,
 * 2026-10-14): entered by admins on the agent page, shown after the MLS
 * listings on My Profile, never on the website. Available and Under Contract
 * only; sold and withdrawn stay on the admin page. Scoped by the context's
 * ids (the person and their teams), never the request.
 */
function portal_pocket_listings(mysqli $conn, array $ctx): array {
    if (!mk_table_exists($conn, 'pocket_listings')) return [];
    $ids = implode(',', array_map('intval', $ctx['ids']));
    $r = $conn->query("SELECT * FROM pocket_listings WHERE intake_id IN ({$ids}) AND status IN ('available', 'under_contract')
                        ORDER BY FIELD(status, 'available', 'under_contract'), is_rental, list_price DESC, id DESC");
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

/** A pocket listing's state for the card: [label, css class]. */
function portal_pocket_state(array $p): array {
    return (string)($p['status'] ?? '') === 'under_contract' ? ['Under Contract', 'pending'] : ['Available', 'active'];
}

/** A plain, branded 403 that names the reason. */
function portal_stop(string $title, string $why): never {
    http_response_code(403);
    $email = htmlspecialchars((string)($_SESSION['user_email'] ?? ''), ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title>'
       . '<style>body{font-family:Jost,system-ui,-apple-system,sans-serif;max-width:34rem;'
       . 'margin:16vh auto;padding:0 1.5rem;color:#1c1a17;line-height:1.6;background:#faf8f4}'
       . 'h1{font-family:"Cormorant Garamond",Georgia,serif;font-weight:500;font-size:2rem;margin:0 0 .6rem}'
       . 'p{color:#4a4539}code{background:#efe9dd;padding:.1rem .35rem;border-radius:3px;font-size:.9em}'
       . 'a{color:#8a6d36}</style></head><body>'
       . '<h1>' . htmlspecialchars($title) . '</h1>'
       . '<p>' . $why . '</p>'
       . ($email !== '' ? '<p style="font-size:.9em;color:#8a8370">Signed in as <code>' . $email . '</code>. '
       . '<a href="/logout.php">Sign out</a></p>' : '')
       . '</body></html>';
    exit;
}

/**
 * Whose portal this is. Returns:
 *   user     the signed-in users row
 *   acct     the marketing_intakes row being shown (an agent, or a team)
 *   ids      account id plus, for a team, its members' ids: what QR codes are
 *            scoped by (a code belongs to a sign, not to billing)
 *   preview  true when an admin is looking at someone else's portal
 */
function portal_context(mysqli $conn): array {
    require_login();   // includes the ACCESS_ALLOWLIST gate

    if (!mk_column_exists($conn, 'users', 'intake_id')) {
        portal_stop('Not ready yet', 'The agent portal is not switched on yet '
            . '(<code>sql/portal_identity_v1.sql</code> has not been run).');
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st = $conn->prepare("SELECT id, first_name, last_name, email, role, is_active, intake_id FROM users WHERE id = ? LIMIT 1");
    $st->bind_param('i', $uid);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user || !(int)$user['is_active']) {
        portal_stop('No access', 'This account is not active. Please email ' . PORTAL_MARKETING_EMAIL . '.');
    }

    $preview = false;
    $iid = (int)($user['intake_id'] ?? 0);
    if (is_elevated_admin() && isset($_GET['preview'])) {
        $iid = (int)$_GET['preview'];
        $preview = true;
    }
    if ($iid <= 0) {
        if (is_elevated_admin()) {
            portal_stop('Choose an agent to preview', 'Admins see the portal through an agent\'s eyes. '
                . 'Open it from an agent with <code>/portal/?preview=&lt;id&gt;</code>, '
                . 'or link your own login to an account on the <a href="/users.php">Users</a> page.');
        }
        portal_stop('Almost there', 'Your sign-in works, but it is not connected to your marketing '
            . 'account yet. The marketing team will set that up; there is nothing you need to do.');
    }

    $cols = 'id, agent_name, slug, is_active, status, headshot_url, headshot_face_url'
          . (mk_column_exists($conn, 'marketing_intakes', 'entity_type') ? ', entity_type' : '');
    $st = $conn->prepare("SELECT {$cols} FROM marketing_intakes WHERE id = ? LIMIT 1");
    $st->bind_param('i', $iid);
    $st->execute();
    $acct = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$acct || !(int)$acct['is_active'] || ($acct['status'] ?? '') === 'archived') {
        portal_stop('No access', 'This marketing account is no longer active. Please email ' . PORTAL_MARKETING_EMAIL . '.');
    }
    if (($acct['entity_type'] ?? 'agent') === 'staff') {
        portal_stop('No access', 'The agent portal is for agents.');
    }
    // A login is linked to a PERSON (Nikki, 2026-10-08): team marketing is shown
    // on each member's own portal, so a login pointed at a team row is a setup
    // mistake, not a way in. An admin may still preview a team row (the roster's
    // eye icon), which shows the team's own section alone.
    if (($acct['entity_type'] ?? 'agent') === 'team' && !$preview) {
        portal_stop('Almost there', 'Your sign-in is linked to the team account <strong>' . ph($acct['agent_name'])
            . '</strong> rather than to you. The marketing team will point it at your own account; the team\'s '
            . 'marketing then appears on your portal as its own section. Nothing you need to do.');
    }

    // The teams this person belongs to: each becomes a section on their portal
    // (advertising, print orders, spend, QR codes), seen by every member alike.
    $teams = [];
    if (($acct['entity_type'] ?? 'agent') !== 'team' && mk_table_exists($conn, 'team_members')) {
        $st = $conn->prepare("SELECT t.id, t.agent_name, t.initials FROM team_members tm JOIN marketing_intakes t ON t.id = tm.team_id
                               WHERE tm.member_id = ? AND t.is_active = 1 AND t.status <> 'archived' ORDER BY t.agent_name, t.id");
        $mid = (int)$acct['id'];
        $st->bind_param('i', $mid);
        $st->execute();
        // 'short': the team's initials from its roster row ("WB"), else its name; headings read "WB Marketing Spend" (Nikki, 2026-10-14).
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) $teams[] = ['id' => (int)$t['id'], 'name' => (string)$t['agent_name'], 'short' => trim((string)($t['initials'] ?? '')) ?: (string)$t['agent_name']];
        $st->close();
    }

    // ids: whose QR codes this portal lists. A person: their own and their teams'.
    // A team (preview only): the team's and its members'.
    $ids = [(int)$acct['id']];
    foreach ($teams as $t) $ids[] = $t['id'];
    if (($acct['entity_type'] ?? 'agent') === 'team' && mk_table_exists($conn, 'team_members')) {
        $st = $conn->prepare("SELECT member_id FROM team_members WHERE team_id = ?");
        $tid = (int)$acct['id'];
        $st->bind_param('i', $tid);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $m) $ids[] = (int)$m['member_id'];
        $st->close();
    }

    // scopes: the accounts whose advertising, orders, spend and files this
    // portal shows, the person first, then each team. Every page scopes its
    // queries to these ids and nothing else.
    $scopes = [['id' => (int)$acct['id'], 'name' => (string)$acct['agent_name'], 'short' => '', 'team' => false]];
    foreach ($teams as $t) $scopes[] = ['id' => $t['id'], 'name' => $t['name'], 'short' => $t['short'], 'team' => true];

    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return ['user' => $user, 'acct' => $acct, 'ids' => array_values(array_unique($ids)), 'preview' => $preview,
            'teams' => $teams, 'scopes' => $scopes];
}

/** The account ids whose items this portal may show (the person and their teams), as a SQL IN list. Ints from the context only. */
function portal_scope_in(array $ctx): string {
    return implode(',', array_map(fn($s) => (int)$s['id'], $ctx['scopes']));
}

/**
 * The scope a page is asked for: ?team=<id> when it is one of the person's
 * teams, else the person's own account. Never an id the context did not list.
 */
function portal_scope(array $ctx, int $team_id): array {
    foreach ($ctx['scopes'] as $s) if ($s['team'] && $s['id'] === $team_id) return $s;
    return $ctx['scopes'][0];
}

/** "Weber Boxer Group" for a team scope, '' for the person's own. */
function portal_scope_name(array $scope): string {
    return $scope['team'] ? (string)$scope['name'] : '';
}

/** Who the broker-side figure belongs to, in the spend words: "You" or "The team". */
function portal_scope_you(array $scope): string {
    return $scope['team'] ? 'The team' : 'You';
}

/** Link inside the portal, keeping an admin's ?preview= on it. */
function portal_url(array $ctx, string $path, array $q = []): string {
    if ($ctx['preview']) $q = ['preview' => (int)$ctx['acct']['id']] + $q;
    return $path . ($q ? '?' . http_build_query($q) : '');
}

function portal_csrf_ok(): bool {
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''));
}

function ph($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** $1,234, or $1,234.50 when the cents matter. */
function portal_money(float $v): string {
    $v = round($v, 2);
    return '$' . number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2);
}

/** Outlet names, as agent.php's $platform_labels shows them. Keep in step. */
function portal_platform_label(string $p): string {
    return [
        'vail_daily' => 'Vail Daily', 'aspen_daily' => 'Aspen Daily News',
        'aspen_times' => 'Aspen Times', 'vail_bc_mag' => 'Vail BC Mag', 'social_meta' => 'Meta (Facebook/Instagram)',
        'social_instagram' => 'Instagram', 'google' => 'Google', 'other' => 'Advertising', 'membership' => 'Membership',
    ][$p] ?? ucwords(str_replace('_', ' ', $p));
}

/** A placement row that is a membership (campaign_membership_v1.sql, 2026-10-14), not an ad. */
function portal_is_membership(array $c): bool {
    return (string)($c['medium'] ?? '') === 'membership';
}

/** "March 5, 2026" (US order since 2026-10-09, Nikki), or '' for no date. */
function portal_date(?string $d): string {
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00') return '';
    $t = strtotime($d);
    return $t ? date('F j, Y', $t) : '';
}

/**
 * When a placement ran, in plain words (US order, Nikki 2026-10-09):
 * "August 13-31, 2026", "August 13 - September 2, 2026", "December 20, 2026 -
 * January 5, 2027", "From March 5, 2026", or "Dates to be confirmed".
 */
function portal_run_words(?string $start, ?string $end): string {
    $s = ($start ?? '') !== '' && $start !== '0000-00-00' ? strtotime($start) : false;
    $e = ($end ?? '') !== '' && $end !== '0000-00-00' ? strtotime($end) : false;
    if (!$s && !$e) return 'Dates to be confirmed';
    if ($s && !$e)  return 'From ' . date('F j, Y', $s);
    if (!$s && $e)  return 'Until ' . date('F j, Y', $e);
    if (date('Y-m', $s) === date('Y-m', $e)) return date('j', $s) === date('j', $e) ? date('F j, Y', $s) : date('F j', $s) . '-' . date('j, Y', $e);
    if (date('Y', $s) === date('Y', $e)) return date('F j', $s) . ' - ' . date('F j, Y', $e);
    return date('F j, Y', $s) . ' - ' . date('F j, Y', $e);
}

/**
 * What an item cost, as one figure for the header line (Nikki, 2026-10-09:
 * "$400 spent"): everything on the spend for it, whoever pays. "Amount being
 * finalised" while the figure is not set; '' when there is nothing yet.
 */
function portal_spend_total_words(array $sp): string {
    if ($sp['billed_with'] !== '') return 'Included in ' . $sp['billed_with'];
    $total = (float)$sp['broker'] + (float)$sp['mh'] + (float)$sp['unassigned'];
    if ($total > 0) return portal_money($total) . ' spent';
    if ($sp['pending']) return 'Amount being finalised';
    if ($sp['lines'] > 0) return 'No charge';
    return '';
}

/** A placement's state in the agent's words: Running now / Starts 5 March 2026 / Planned / Ended. */
function portal_campaign_state(array $c): string {
    $today  = date('Y-m-d');
    $status = (string)($c['status'] ?? 'planned');
    $start  = (string)($c['start_date'] ?? ''); $end = (string)($c['end_date'] ?? '');
    if ($status === 'ended' || ($end !== '' && $end !== '0000-00-00' && $end < $today)) return 'Ended';
    if ($start !== '' && $start !== '0000-00-00' && $start > $today) return 'Starts ' . portal_date($start);
    if ($status === 'active' || ($start !== '' && $start !== '0000-00-00')) return 'Running now';
    return 'Planned';
}

/**
 * A membership's state in the agent's words (2026-10-14): Active member /
 * Renews soon (60 days out) / Renewal due (the date has passed and nobody has
 * marked it ended) / Starts March 5, 2026 / Ended / Being set up.
 */
function portal_membership_state(array $c): string {
    $today  = date('Y-m-d');
    $status = (string)($c['status'] ?? 'planned');
    $start  = (string)($c['start_date'] ?? ''); $end = (string)($c['end_date'] ?? '');
    $has_s  = $start !== '' && $start !== '0000-00-00';
    $has_e  = $end !== '' && $end !== '0000-00-00';
    if ($status === 'ended') return 'Ended';
    if ($has_e && $end < $today) return 'Renewal due';
    if ($has_s && $start > $today) return 'Starts ' . portal_date($start);
    if ($has_e && (strtotime($end) - strtotime($today)) <= 60 * 86400) return 'Renews soon';
    if ($status === 'active' || $has_s) return 'Active member';
    return 'Being set up';
}

/** "Member since March 5, 2026 · Renews March 5, 2027", either half alone, or "Dates to be confirmed". */
function portal_membership_term(array $c): string {
    $s = portal_date($c['start_date'] ?? null); $e = portal_date($c['end_date'] ?? null);
    $out = [];
    if ($s !== '') $out[] = 'Member since ' . $s;
    if ($e !== '') $out[] = 'Renews ' . $e;
    return $out ? implode(' · ', $out) : 'Dates to be confirmed';
}

/** An order's status in the agent's words (agent.php's statuses, reworded). */
function portal_order_state(array $o): string {
    return [
        'pending'       => 'Being prepared',
        'ordered'       => 'Ordered',
        'in_production' => 'Being printed',
        'shipped'       => 'On its way',
        'delivered'     => 'Delivered',
    ][(string)($o['status'] ?? 'pending')] ?? ucwords(str_replace('_', ' ', (string)$o['status']));
}

/** Collateral type in plain words (mk_coll_type_label from inc/financials.php, when loaded). */
function portal_order_kind(string $type): string {
    if (function_exists('mk_coll_type_label')) return mk_coll_type_label($type);
    return ['business_cards' => 'Business Cards', 'yard_signs' => 'Yard Signs', 'oh_signs' => 'Open House Signs',
            'postcards' => 'Postcards', 'brochures' => 'Brochures', 'other' => 'Other'][$type] ?? ucwords(str_replace('_', ' ', $type));
}

/**
 * What one placement or order cost, from the same mh_agent_financials() pass
 * the Spend page shows: every line whose ref_id is this item, summed.
 * Returns broker / mh / unassigned, 'lines' (how many months carried it),
 * 'pending' (a line still awaiting its figure) and 'billed_with' (a print
 * order included in another order's cost). Spend only, never billing state.
 */
function portal_item_spend(array $fin, string $kind, int $ref_id): array {
    $r = ['broker' => 0.0, 'mh' => 0.0, 'unassigned' => 0.0, 'lines' => 0, 'pending' => false, 'billed_with' => ''];
    foreach ($fin['months'] as $m) {
        foreach ($m['items'] as $it) {
            if (($it['kind'] ?? '') !== $kind || (int)($it['ref_id'] ?? 0) !== $ref_id) continue;
            $r['lines']++;
            foreach (['broker', 'mh', 'unassigned'] as $f) $r[$f] += (float)($it[$f] ?? 0);
            if ($kind === 'Advertising' && empty($it['set']) && (float)($it['total'] ?? 0) == 0.0) $r['pending'] = true;
            if (!empty($it['billed_with'])) $r['billed_with'] = (string)$it['billed_with'];
        }
    }
    return $r;
}

/** The three spend figures for one item, as a short line; nothing when there is no spend yet. */
function portal_item_spend_html(array $sp, string $you = 'You'): string {
    if ($sp['billed_with'] !== '') return '<span class="muted">Included in ' . ph($sp['billed_with']) . '</span>';
    $out = [];
    if ($sp['broker'] > 0)     $out[] = ph($you) . ' paid <b>' . ph(portal_money($sp['broker'])) . '</b>';
    if ($sp['mh'] > 0)         $out[] = 'Mont Haus paid <b>' . ph(portal_money($sp['mh'])) . '</b>';
    if ($sp['unassigned'] > 0) $out[] = '<span class="tbd">Being finalised <b>' . ph(portal_money($sp['unassigned'])) . '</b></span>';
    if (!$out && $sp['pending']) $out[] = '<span class="tbd">Amount being finalised</span>';
    if (!$out && $sp['lines'] > 0) $out[] = '<span class="muted">No charge</span>';
    return implode('<span class="pt-sep"></span>', $out);
}

/**
 * The picture on a card or page: the thumbnail made at upload, a PDF tile
 * when the file is a PDF (or too big for a thumbnail), or a plain tile with
 * a word on it when nothing has been uploaded. $src is the portal/asset.php
 * query for this item (['asset' => id] or ['order' => id]).
 */
function portal_picture(array $ctx, array $src, ?string $file, ?string $thumb, string $word, bool $large = false): string {
    $cls = 'pt-pic' . ($large ? ' large' : '');
    if (($thumb ?? '') !== '') {
        $url = portal_url($ctx, '/portal/asset.php', $src + ['thumb' => 1]);
        return '<span class="' . $cls . '"><img src="' . ph($url) . '" alt="" loading="lazy"></span>';
    }
    if (($file ?? '') !== '') {
        $pdf = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf';
        return '<span class="' . $cls . ' tile"><span>' . ($pdf ? 'PDF' : 'Image') . '</span></span>';
    }
    // Nothing uploaded: no placeholder picture at all (Nikki, 2026-10-09); the
    // caller writes portal_no_creative_html() in words instead.
    return '';
}

/**
 * The line shown wherever a creative or proof has not been uploaded yet
 * (Nikki, 2026-10-09). $link = false inside a card that is itself a link
 * (the list cards): a link inside a link is invalid HTML and the browser
 * closes the card at the mail link, spilling the rest of it out (seen
 * 2026-10-10). The address is still shown, just not clickable there.
 */
function portal_no_creative_html(bool $link = true): string {
    $addr = $link ? '<a href="mailto:' . PORTAL_MARKETING_EMAIL . '">' . PORTAL_MARKETING_EMAIL . '</a>' : '<b>' . PORTAL_MARKETING_EMAIL . '</b>';
    return '<span class="pt-nopic">Contact ' . $addr . ' to see the creative.</span>';
}

/** First name for the greeting; a team shows its own name. */
function portal_greeting_name(array $ctx): string {
    if (($ctx['acct']['entity_type'] ?? 'agent') === 'team') return (string)$ctx['acct']['agent_name'];
    $fn = trim((string)($ctx['user']['first_name'] ?? ''));
    if ($ctx['preview'] || $fn === '') $fn = strtok(trim((string)$ctx['acct']['agent_name']), ' ') ?: '';
    return $fn;
}

/**
 * Page top: dark header with the logo, the nav, and the preview banner.
 * $active is the nav key of the current page.
 */
/**
 * The portal's menu (Nikki, 2026-10-12): main tabs with sub-menus. A group
 * has no page of its own; its first item stands in for it. Active keys are
 * the leaf keys; 'hotsheets' counts as My Profile (the subscription lives
 * there). Shared by the header and the sub-tab row.
 */
function portal_nav(): array {
    return [
        'home'      => ['Home', '/portal/'],
        'profile'   => ['My Profile', '/portal/profile.php'],
        'marketing' => ['My Marketing', null, [
            'spend'  => ['Spend', '/portal/spend.php'],
            'ads'    => ['Advertising', '/portal/advertising.php'],
            'orders' => ['Collateral', '/portal/orders.php'],
            'memberships' => ['Memberships', '/portal/memberships.php'],   // chambers, associations, clubs Mont Haus joined for them (2026-10-14)
        ]],
        'tools'     => ['My Tools', null, [
            'elevation' => ['Elevation Planner', '/portal/elevation.php'],
            'market'    => ['Market Report', '/portal/market.php'],   // JB's monthly report of the markets Mont Haus serves (2026-10-18)
            'social'    => ['Templates', '/portal/templates.php'],   // "Templates" (Nikki, 2026-10-13): a landing with the Instagram Post and Listing Flyer tools, which switch between each other
            'qr'        => ['QR Codes', '/portal/qr.php'],
            'brand'     => ['Brand Tools', '/portal/brand.php'],
        ]],
    ];
}

/** The group key a leaf belongs to ('' for a top-level page). */
function portal_nav_group(string $active): string {
    if ($active === 'hotsheets') return 'profile';
    foreach (portal_nav() as $k => $item) {
        if ($k === $active) return $k;
        foreach ($item[2] ?? [] as $lk => $_) if ($lk === $active) return $k;
    }
    return '';
}

/**
 * Page top: dark header with the logo, the menu, and the preview banner.
 * $active is the nav key of the current page (a leaf key).
 */
function portal_header(array $ctx, string $title, string $active, array $jump = []): void {
    $nav   = portal_nav();
    $group = portal_nav_group($active);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ph($title) ?> · Mont Haus</title>
<link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/portal.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/portal.css') ?>">
</head>
<body>
<?php if ($ctx['preview']): ?>
  <div class="pt-preview">Preview: you are seeing <strong><?= ph($ctx['acct']['agent_name']) ?></strong>'s portal as they see it.
    <a href="/agent.php?id=<?= (int)$ctx['acct']['id'] ?>">Back to their page</a></div>
<?php elseif (is_elevated_admin()): // an admin on their OWN portal (Nikki, 2026-10-08: admins have both) ?>
  <div class="pt-preview">This is your own agent portal. <a href="/">Back to the admin pages</a></div>
<?php endif; ?>
<header class="pt-top">
  <div class="pt-top-in">
    <a class="pt-logo" href="<?= ph(portal_url($ctx, '/portal/')) ?>"><img src="/assets/images/logo-white.svg" alt="Mont Haus"></a>
    <div class="pt-top-right">
      <a class="pt-signout" href="/logout.php">Sign out</a>
      <button class="pt-menu-btn" type="button" aria-expanded="false" aria-controls="ptNav" onclick="document.body.classList.toggle('pt-nav-open');this.setAttribute('aria-expanded',document.body.classList.contains('pt-nav-open'))">
        <span></span><span></span><span></span><b>Menu</b>
      </button>
    </div>
  </div>
  <nav class="pt-nav" id="ptNav" aria-label="Portal">
    <?php foreach ($nav as $k => $item): [$label, $path] = $item; $subs = $item[2] ?? []; ?>
      <?php if (!$subs): ?>
        <a href="<?= ph(portal_url($ctx, $path)) ?>" class="pt-nav-a <?= $k === $group ? 'on' : '' ?>"><?= ph($label) ?></a>
      <?php else: ?>
        <div class="pt-group <?= $k === $group ? 'on' : '' ?>">
          <button type="button" class="pt-nav-a pt-group-btn" aria-expanded="false"
                  onclick="var g=this.parentNode;g.classList.toggle('open');this.setAttribute('aria-expanded',g.classList.contains('open'))"><?= ph($label) ?> <i class="pt-caret"></i></button>
          <div class="pt-sub">
            <?php foreach ($subs as $lk => [$l2, $p2]): ?>
              <a href="<?= ph(portal_url($ctx, $p2)) ?>" class="<?= $lk === $active ? 'on' : '' ?>"><?= ph($l2) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
</header>
<?php if ($group !== '' && !empty($nav[$group][2])): // the group's pages, as a row of sub-tabs under the header ?>
<div class="pt-subnav-wrap"><nav class="pt-subnav" aria-label="<?= ph($nav[$group][0]) ?>">
  <?php foreach ($nav[$group][2] as $lk => [$l2, $p2]): ?>
    <a href="<?= ph(portal_url($ctx, $p2)) ?>" class="<?= $lk === $active ? 'on' : '' ?>"><?= ph($l2) ?></a>
  <?php endforeach; ?>
</nav></div>
<?php endif; ?>
<?php if ($jump): // a long page's own sections, as anchors in a row (My Profile; the Brand pages under their group's tabs); sticks while scrolling ?>
<div class="pt-subnav-wrap sticky jump"><nav class="pt-subnav" aria-label="On this page">
  <?php foreach ($jump as $id => $label): ?><a href="#<?= ph($id) ?>"><?= ph($label) ?></a><?php endforeach; ?>
</nav></div>
<?php endif; ?>
<script>
// Desktop: a tap outside closes an open dropdown; Esc too.
document.addEventListener('click', function (e) {
  document.querySelectorAll('.pt-group.open').forEach(function (g) { if (!g.contains(e.target)) { g.classList.remove('open'); g.querySelector('.pt-group-btn').setAttribute('aria-expanded', 'false'); } });
});
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { document.body.classList.remove('pt-nav-open'); document.querySelectorAll('.pt-group.open').forEach(function (g) { g.classList.remove('open'); }); } });
</script>
<main class="pt-main">
    <?php
}

/**
 * Page bottom: the Contact marketing form on every page (Nikki, 2026-10-13;
 * it was a section of My Profile). Posts to portal/contact.php, which sends
 * the person back to the page they wrote from. Reads the page's $ctx; an
 * admin preview shows the form as the agent sees it, with its fields
 * disabled (Nikki, 2026-10-13: hidden, it looked as if it had not rendered).
 */
function portal_footer(): void {
    global $ctx;
    $flash = $_SESSION['pt_contact_flash'] ?? null; unset($_SESSION['pt_contact_flash']);
    $back  = (string)($_SERVER['REQUEST_URI'] ?? '/portal/');
    if (!str_starts_with($back, '/portal/')) $back = '/portal/';
    $preview = !empty($ctx['preview']);
    ?>
</main>
<footer class="pt-foot" id="contact-marketing">
  <div class="pt-foot-in">
    <h2 class="pt-h2">Contact marketing</h2>
    <p class="pt-foot-sub">A question, a change we should make, something you need. Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a> or write here.</p>
    <?php if ($flash): ?><div class="pt-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= ph($flash[1]) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['csrf_token'])): $dis = $preview ? ' disabled' : ''; ?>
    <form method="post" class="pt-form pt-foot-form" action="/portal/contact.php">
      <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="back" value="<?= ph($back) ?>">
      <label>Subject <input type="text" name="subject" maxlength="200" required placeholder="e.g. New headshot"<?= $dis ?>></label>
      <label>Message <textarea name="message" rows="4" maxlength="4000" required<?= $dis ?>></textarea></label>
      <div class="pt-row"><button type="submit" class="pt-btn main"<?= $dis ?>>Send to marketing</button><?php if ($preview): ?><span class="pt-help" style="align-self:center">Preview: the form is turned off.</span><?php endif; ?></div>
    </form>
    <?php endif; ?>
  </div>
</footer>
</body>
</html>
    <?php
}

/** A picture tapped in a gallery opens large (the Brand pages, 2026-10-18); Esc or a tap outside closes it. Call before portal_footer(). */
function portal_lightbox(): void {
    ?>
<div class="pt-lb" id="ptLb" role="dialog" aria-modal="true" hidden><button type="button" class="pt-lb-x" aria-label="Close">&times;</button><img src="" alt=""></div>
<script>
(function () {
  var lb = document.getElementById('ptLb'), im = lb.querySelector('img');
  function close() { lb.hidden = true; im.src = ''; }
  document.querySelectorAll('img[data-lb]').forEach(function (i) { i.addEventListener('click', function () { im.src = i.src; im.alt = i.alt; lb.hidden = false; }); });
  lb.addEventListener('click', function (e) { if (e.target === lb || e.target.classList.contains('pt-lb-x')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
    <?php
}

/** "970.555.0100" (Nikki, 2026-10-13: periods), or the text as given when it is not a 10-digit number. */
function portal_phone(?string $raw): string {
    $raw = trim((string)$raw);
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) === 10 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6) : $raw;
}
