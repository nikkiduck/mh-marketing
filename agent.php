<?php
/**
 * marketing/agent.php
 * Agent profile — Overview (interactive onboarding checklist) | Tasks | Collateral | Campaigns | Notes
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';           // SITE_URL, PUBLIC_SITE_URL
require_once __DIR__ . '/inc/_onboarding.php';
require_once __DIR__ . '/inc/schema.php';
require_once __DIR__ . '/inc/financials.php';
require_once __DIR__ . '/inc/agent_lifecycle.php';   // Hot Sheets checklist item
require_once __DIR__ . '/inc/agent_roster.php';     // mk_slug_base() for the web address
require_once __DIR__ . '/inc/photos.php';           // headshots, and PHP's upload limits
require_once __DIR__ . '/inc/qr.php';               // QR codes card on the Profile tab
require_login();
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
// ?slug=jonathan-boxer works too, so the website's admin can link straight here
// without knowing our row numbers (2026-09-23).
if (!$id && !empty($_GET['slug'])) {
    $st = $conn->prepare("SELECT id FROM marketing_intakes WHERE slug = ? LIMIT 1");
    $sl = trim((string)$_GET['slug']);
    $st->bind_param('s', $sl); $st->execute();
    $row = $st->get_result()->fetch_row(); $st->close();
    if ($row) $id = (int)$row[0];
}
if (!$id) { header('Location: index.php'); exit; }

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A photo bigger than PHP's post_max_size arrives as an empty request:
    // no $_POST, no $_FILES, no error, so the page just reloaded unchanged
    // (Nikki, 2026-09-28). Catch it and say what happened.
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $limit = ini_get('post_max_size');
        header("Location: agent.php?id={$id}&tab=profile&err="
             . urlencode("That file is larger than this server accepts in one go ({$limit}). "
                       . 'Use a smaller image, or ask for the limit to be raised.'));
        exit;
    }
    $action = $_POST['_action'] ?? '';
    $rtab   = $_POST['redirect_tab'] ?? 'tasks';

    // Bump the intake's updated_at for ANY change made on this page.
    // marketing_intakes.updated_at is ON UPDATE CURRENT_TIMESTAMP, but almost
    // everything here writes to a child table — marketing_tasks for checklist
    // ticks, marketing_collateral_orders for orders, marketing_campaigns for
    // advertising — so the parent row was never touched and the roster's
    // "Recently Updated" sort didn't move. Touching it here covers every action.
    if ($action !== '') {
        $conn->query("UPDATE marketing_intakes SET updated_at = NOW() WHERE id = {$id}");
    }

    /* --- Tasks --- */
    if ($action === 'toggle_task') {
        $tid    = (int)($_POST['task_id'] ?? 0);
        $status = trim($_POST['new_status'] ?? 'open');
        $done   = $status === 'done' ? 'NOW()' : 'NULL';
        $conn->query("UPDATE marketing_tasks SET status='{$conn->real_escape_string($status)}', completed_at={$done} WHERE id={$tid} AND intake_id={$id}");
        // "Hot Sheets: Subscribe": the tick IS the subscription (Nikki,
        // 2026-09-22). If it cannot subscribe them, the tick is undone and
        // the reason shown.
        $tt = $conn->query("SELECT title FROM marketing_tasks WHERE id={$tid} AND intake_id={$id}")->fetch_row();
        if ($tt && $tt[0] === MK_HS_TASK) {
            if ($status === 'done') {
                $msg = mk_hs_subscribe($conn, $id);
                if (!str_starts_with($msg, 'Subscribed') && !str_contains($msg, 'already gets') && !str_starts_with($msg, 'Re-activated')) {
                    $conn->query("UPDATE marketing_tasks SET status='open', completed_at=NULL WHERE id={$tid}");
                    header("Location: agent.php?id={$id}&tab={$rtab}&err=" . urlencode($msg)); exit;
                }
                header("Location: agent.php?id={$id}&tab={$rtab}&saved=1"); exit;
            }
            mk_hs_pause($conn, $id);
        }
        header("Location: agent.php?id={$id}&tab={$rtab}"); exit;
    }

    if ($action === 'update_task_file') {
        $tid  = (int)($_POST['task_id'] ?? 0);
        $furl = trim($_POST['file_url'] ?? '') ?: null;
        $s = $conn->prepare("UPDATE marketing_tasks SET file_url=? WHERE id=? AND intake_id=?");
        $s->bind_param('sii', $furl, $tid, $id); $s->execute(); $s->close();
        header("Location: agent.php?id={$id}&tab=profile"); exit;
    }

    if ($action === 'update_task_due') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $due = trim($_POST['due_date'] ?? '') ?: null;
        $s = $conn->prepare("UPDATE marketing_tasks SET due_date=? WHERE id=? AND intake_id=?");
        $s->bind_param('sii', $due, $tid, $id); $s->execute(); $s->close();
        header("Location: agent.php?id={$id}&tab=profile"); exit;
    }

    if ($action === 'add_task') {
        $title    = trim($_POST['title']    ?? '');
        $cat      = trim($_POST['category'] ?? 'other');
        $priority = trim($_POST['priority'] ?? 'normal');
        $due      = trim($_POST['due_date'] ?? '') ?: null;
        $notes    = trim($_POST['notes']    ?? '') ?: null;
        if ($title) {
            $s = $conn->prepare("INSERT INTO marketing_tasks (intake_id,title,category,priority,due_date,notes,status) VALUES (?,?,?,?,?,?,'open')");
            $s->bind_param('isssss', $id, $title, $cat, $priority, $due, $notes);
            $s->execute(); $s->close();
        }
        header("Location: agent.php?id={$id}&tab=tasks"); exit;
    }

    if ($action === 'delete_task') {
        $tid = (int)($_POST['task_id'] ?? 0);
        // Also delete sub-tasks
        $conn->query("DELETE FROM marketing_tasks WHERE (id={$tid} OR parent_id={$tid}) AND intake_id={$id}");
        header("Location: agent.php?id={$id}&tab={$rtab}"); exit;
    }

    /* --- Notes --- */
    if ($action === 'add_note') {
        $content = trim($_POST['content'] ?? '');
        $uid     = $_SESSION['user_id'] ?? null;
        if ($content) {
            $s = $conn->prepare("INSERT INTO marketing_notes (intake_id,content,created_by) VALUES (?,?,?)");
            $s->bind_param('isi', $id, $content, $uid);
            $s->execute(); $s->close();
        }
        header("Location: agent.php?id={$id}&tab=notes"); exit;
    }

    if ($action === 'delete_note') {
        $nid = (int)($_POST['note_id'] ?? 0);
        $s   = $conn->prepare("DELETE FROM marketing_notes WHERE id=? AND intake_id=?");
        $s->bind_param('ii', $nid, $id); $s->execute(); $s->close();
        header("Location: agent.php?id={$id}&tab=notes"); exit;
    }

    /* --- Campaigns / Advertising --- */
    if ($action === 'add_campaign') {
        $platform    = trim($_POST['platform']     ?? '');
        $name        = trim($_POST['name']         ?? '') ?: null;
        $budget      = trim($_POST['budget']       ?? '') ?: null;
        // one_time : whole amount in the start month
        // monthly  : budget is a PER-MONTH figure, charged in every month of the run
        // per_unit : unit_rate × matching days in each month
        $bill_mode   = in_array($_POST['billing_mode'] ?? '', ['monthly','monthly_flat','per_unit'], true)
                       ? $_POST['billing_mode'] : 'one_time';
        $unit_rate   = trim($_POST['unit_rate']  ?? '') ?: null;
        // '' means "no weekday — type the quantity each month", and must stay
        // NULL rather than becoming 0, which is a real weekday (Sunday).
        $unit_wd     = ($_POST['unit_weekday'] ?? '') === '' ? null : (int)$_POST['unit_weekday'];
        $unit_label  = trim($_POST['unit_label'] ?? '') ?: null;
        if ($bill_mode !== 'per_unit') { $unit_rate = null; $unit_wd = null; $unit_label = null; }
        $start       = trim($_POST['start_date']   ?? '') ?: null;
        $end         = trim($_POST['end_date']     ?? '') ?: null;
        $camp_notes  = trim($_POST['notes']        ?? '') ?: null;
        $target_url  = trim($_POST['target_url']   ?? '') ?: null;
        $utm_source  = trim($_POST['utm_source']   ?? '') ?: null;
        $utm_medium  = trim($_POST['utm_medium']   ?? '') ?: null;
        $utm_camp    = trim($_POST['utm_campaign'] ?? '') ?: null;
        $utm_content = trim($_POST['utm_content']  ?? '') ?: null;
        $sent        = isset($_POST['sent']) ? 1 : 0;
        // Who pays — same three fields as collateral orders
        $paid_by     = in_array($_POST['paid_by'] ?? '', ['broker','mont_haus','split'], true)
                       ? $_POST['paid_by'] : null;
        $paid_broker = trim($_POST['paid_broker_amount'] ?? '') ?: null;
        $paid_mh     = trim($_POST['paid_mh_amount']     ?? '') ?: null;
        if ($platform) {
            // Ad files are never stored on the campaign row — each creative goes into
            // marketing_campaign_assets so a placement can hold any number of ads,
            // each with its own file and its own target URL.
            $s = $conn->prepare("INSERT INTO marketing_campaigns (intake_id,platform,name,budget,billing_mode,unit_rate,unit_weekday,unit_label,start_date,end_date,notes,target_url,utm_source,utm_medium,utm_campaign,utm_content,paid_by,paid_broker_amount,paid_mh_amount,sent,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'planned')");
            // 20 placeholders. i + 18×s + i = 20 characters in the type string.
            // Count these every time — a miscounted type string has bitten
            // this project more than once. unit_weekday binds as 's' because
            // it is nullable; mysqli sends NULL correctly either way.
            $s->bind_param('issssssssssssssssssi', $id, $platform, $name, $budget, $bill_mode, $unit_rate, $unit_wd, $unit_label, $start, $end, $camp_notes, $target_url, $utm_source, $utm_medium, $utm_camp, $utm_content, $paid_by, $paid_broker, $paid_mh, $sent);
            $s->execute();
            $new_cid = (int)$conn->insert_id;
            $s->close();

            // Repeating ad rows posted as ads[0][label], ads[0][file_url], ads[0][target_url]
            $ads = $_POST['ads'] ?? [];
            if ($new_cid && is_array($ads)) {
                $a = $conn->prepare(
                    "INSERT INTO marketing_campaign_assets (campaign_id,label,file_url,target_url,file_type)
                     VALUES (?,?,?,?,'file')"
                );
                if ($a) {
                    foreach ($ads as $ad) {
                        if (!is_array($ad)) continue;
                        $furl = trim($ad['file_url'] ?? '');
                        $turl = trim($ad['target_url'] ?? '') ?: null;
                        $lbl  = trim($ad['label'] ?? '') ?: null;
                        // Skip blank rows — the form always renders at least one
                        if ($furl === '' && $turl === null) continue;
                        $a->bind_param('isss', $new_cid, $lbl, $furl, $turl);
                        $a->execute();
                    }
                    $a->close();
                }
            }
        }
        header("Location: agent.php?id={$id}&tab=advertising&saved=1"); exit;
    }

    if ($action === 'update_campaign') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        // Switching away from per_unit must clear the unit columns, or a
        // campaign flipped to monthly keeps a stale rate that reappears if it
        // is ever flipped back — a wrong figure with no visible cause.
        if (array_key_exists('billing_mode', $_POST) && $_POST['billing_mode'] !== 'per_unit') {
            $_POST['unit_rate'] = ''; $_POST['unit_weekday'] = ''; $_POST['unit_label'] = '';
        }
        $fields = ['platform','name','budget','billing_mode','unit_rate','unit_weekday','unit_label',
                   'start_date','end_date','notes','target_url',
                   'utm_source','utm_medium','utm_campaign','utm_content',
                   'paid_by','paid_broker_amount','paid_mh_amount'];
        $sets = []; $vals = []; $types = '';
        foreach ($fields as $f) {
            if (!array_key_exists($f, $_POST)) continue;
            $v      = trim($_POST[$f]);
            $sets[] = "`{$f}` = ?";
            $vals[] = $v === '' ? null : $v;
            $types .= 's';
        }
        if ($sets && $cid) {
            $vals[] = $cid; $vals[] = $id; $types .= 'ii';
            $s = $conn->prepare("UPDATE marketing_campaigns SET " . implode(', ', $sets) . " WHERE id=? AND intake_id=?");
            if ($s) { $s->bind_param($types, ...$vals); $s->execute(); $s->close(); }
        }
        header("Location: agent.php?id={$id}&tab=advertising&saved=1"); exit;
    }

    /* --- The month grid: every month of one placement, saved at once --- */
    //
    // Posted as months[YYYY-MM][amount] / [units] / [unit_rate] / [paid_by] /
    // [paid_broker_amount] / [paid_mh_amount] / [note].
    //
    // A month left entirely blank has its row DELETED rather than stored as a
    // set of NULLs. "No row" and "a row full of nothing" must not be two ways
    // of saying the same thing — the whole feature rests on a month either
    // having a figure or visibly not having one.
    if ($action === 'save_campaign_months') {
        $cid  = (int)($_POST['campaign_id'] ?? 0);
        $rows = $_POST['months'] ?? [];

        // The campaign must belong to this agent. Without this, a posted
        // campaign_id would let one agent's page write another's billing.
        $own = $conn->prepare("SELECT id FROM marketing_campaigns WHERE id=? AND intake_id=? LIMIT 1");
        $ok  = false;
        if ($own) { $own->bind_param('ii', $cid, $id); $own->execute(); $ok = (bool)$own->get_result()->fetch_assoc(); $own->close(); }

        if ($ok && is_array($rows)) {
            $ins = $conn->prepare(
                "INSERT INTO marketing_campaign_months
                    (campaign_id, ym, amount, units, unit_rate,
                     paid_by, paid_broker_amount, paid_mh_amount, note)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    amount=VALUES(amount), units=VALUES(units), unit_rate=VALUES(unit_rate),
                    paid_by=VALUES(paid_by),
                    paid_broker_amount=VALUES(paid_broker_amount),
                    paid_mh_amount=VALUES(paid_mh_amount), note=VALUES(note)"
            );
            $del = $conn->prepare("DELETE FROM marketing_campaign_months WHERE campaign_id=? AND ym=?");

            foreach ($rows as $ym => $f) {
                // YYYY-MM or the row is ignored. A malformed month would create
                // a row that resolves against nothing and is invisible in the
                // grid that wrote it.
                if (!is_array($f) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$ym)) continue;

                $v = function (string $k) use ($f) {
                    $x = trim((string)($f[$k] ?? ''));
                    return $x === '' ? null : $x;
                };
                $amount = $v('amount');
                $units  = $v('units');
                $urate  = $v('unit_rate');
                $note   = $v('note');
                $mpaid  = in_array($f['paid_by'] ?? '', ['broker','mont_haus','split'], true)
                          ? $f['paid_by'] : null;
                $mpb    = $mpaid === 'split' ? $v('paid_broker_amount') : null;
                $mpm    = $mpaid === 'split' ? $v('paid_mh_amount')     : null;

                if ($amount === null && $units === null && $urate === null
                    && $mpaid === null && $note === null) {
                    if ($del) { $ymv = (string)$ym; $del->bind_param('is', $cid, $ymv); $del->execute(); }
                    continue;
                }
                // 9 placeholders: i + 8×s = 9 characters in the type string.
                if ($ins) {
                    $ymv = (string)$ym;
                    $ins->bind_param('issssssss', $cid, $ymv, $amount, $units, $urate,
                                     $mpaid, $mpb, $mpm, $note);
                    $ins->execute();
                }
            }
            if ($ins) $ins->close();
            if ($del) $del->close();
        }
        header("Location: agent.php?id={$id}&tab=advertising&saved=1#camp-{$cid}"); exit;
    }

    /* --- Campaign ad files (multiple per campaign) --- */
    if ($action === 'add_campaign_asset') {
        $cid   = (int)($_POST['campaign_id'] ?? 0);
        $label = trim($_POST['label']    ?? '') ?: null;
        $furl  = trim($_POST['file_url'] ?? '');
        // Confirm the campaign belongs to this agent before attaching anything
        $own = $conn->prepare("SELECT id FROM marketing_campaigns WHERE id=? AND intake_id=? LIMIT 1");
        $ok  = false;
        if ($own) { $own->bind_param('ii', $cid, $id); $own->execute(); $ok = (bool)$own->get_result()->fetch_assoc(); $own->close(); }
        $turl = trim($_POST['target_url'] ?? '') ?: null;
        if ($ok && ($furl !== '' || $turl !== null)) {
            $s = $conn->prepare(
                "INSERT INTO marketing_campaign_assets (campaign_id,label,file_url,target_url,file_type)
                 VALUES (?,?,?,?,'file')"
            );
            if ($s) { $s->bind_param('isss', $cid, $label, $furl, $turl); $s->execute(); $s->close(); }
        }
        header("Location: agent.php?id={$id}&tab=advertising&saved=1"); exit;
    }

    if ($action === 'update_campaign_asset') {
        $aid   = (int)($_POST['asset_id'] ?? 0);
        $label = trim($_POST['label']      ?? '') ?: null;
        $furl  = trim($_POST['file_url']   ?? '');
        $turl  = trim($_POST['target_url'] ?? '') ?: null;
        $s = $conn->prepare(
            "UPDATE marketing_campaign_assets a
               JOIN marketing_campaigns c ON c.id = a.campaign_id
                SET a.label = ?, a.file_url = ?, a.target_url = ?
              WHERE a.id = ? AND c.intake_id = ?"
        );
        if ($s) { $s->bind_param('sssii', $label, $furl, $turl, $aid, $id); $s->execute(); $s->close(); }
        header("Location: agent.php?id={$id}&tab=advertising&saved=1"); exit;
    }

    if ($action === 'delete_campaign_asset') {
        $aid = (int)($_POST['asset_id'] ?? 0);
        $s = $conn->prepare(
            "DELETE a FROM marketing_campaign_assets a
               JOIN marketing_campaigns c ON c.id = a.campaign_id
              WHERE a.id = ? AND c.intake_id = ?"
        );
        if ($s) { $s->bind_param('ii', $aid, $id); $s->execute(); $s->close(); }
        header("Location: agent.php?id={$id}&tab=advertising"); exit;
    }

    // Clears the legacy single ad_file_url column on a campaign
    if ($action === 'clear_campaign_ad_file') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $s = $conn->prepare("UPDATE marketing_campaigns SET ad_file_url=NULL WHERE id=? AND intake_id=?");
        if ($s) { $s->bind_param('ii', $cid, $id); $s->execute(); $s->close(); }
        header("Location: agent.php?id={$id}&tab=advertising"); exit;
    }

    if ($action === 'update_campaign_status') {
        $cid    = (int)($_POST['campaign_id'] ?? 0);
        $status = $conn->real_escape_string($_POST['status'] ?? 'planned');
        $conn->query("UPDATE marketing_campaigns SET status='{$status}' WHERE id={$cid} AND intake_id={$id}");
        header("Location: agent.php?id={$id}&tab=advertising"); exit;
    }

    if ($action === 'toggle_campaign_sent') {
        $cid  = (int)($_POST['campaign_id'] ?? 0);
        $sent = (int)($_POST['sent'] ?? 0);
        $conn->query("UPDATE marketing_campaigns SET sent={$sent} WHERE id={$cid} AND intake_id={$id}");
        header("Location: agent.php?id={$id}&tab=advertising"); exit;
    }

    /* --- Duplicate a placement -------------------------------------------
     *
     * Re-running the same buy in a new month is the common case: same
     * publication, same creatives, same landing page — a different flight and
     * usually a different spend. So the copy carries everything that describes
     * the ad and drops everything that describes THIS run: the dates come over
     * blank, the status resets to planned, and Sent clears. A copy that
     * inherited August's dates and a "sent" flag would look like a real, live
     * placement the moment it appeared, and a forgotten one would quietly
     * bill twice.
     *
     * Month rows (marketing_campaign_months) are deliberately NOT copied —
     * they belong to the flight, and the new flight has no dates yet.
     */
    if ($action === 'duplicate_campaign') {
        $cid = (int)($_POST['campaign_id'] ?? 0);

        // The placement must belong to this agent, same as everywhere else a
        // campaign_id arrives from the browser.
        $src = null;
        $q = $conn->prepare("SELECT * FROM marketing_campaigns WHERE id=? AND intake_id=? LIMIT 1");
        if ($q) { $q->bind_param('ii', $cid, $id); $q->execute(); $src = $q->get_result()->fetch_assoc(); $q->close(); }

        $new_cid = 0;
        if ($src) {
            // " (Copy)" so the pair is tellable apart at a glance if the edit
            // form is abandoned. varchar(255), so trim before appending.
            $name = trim((string)($src['name'] ?? ''));
            if ($name !== '') {
                $suffix = ' (Copy)';
                if (mb_strlen($name . $suffix) > 255) {
                    $name = mb_substr($name, 0, 255 - mb_strlen($suffix));
                }
                $name .= $suffix;
            } else {
                $name = null;
            }

            $v = function (string $k) use ($src) {
                $x = $src[$k] ?? null;
                return ($x === null || $x === '') ? null : (string)$x;
            };

            $s = $conn->prepare(
                "INSERT INTO marketing_campaigns
                    (intake_id,platform,name,budget,billing_mode,unit_rate,unit_weekday,unit_label,
                     start_date,end_date,notes,target_url,utm_source,utm_medium,utm_campaign,utm_content,
                     paid_by,paid_broker_amount,paid_mh_amount,sent,status)
                 VALUES (?,?,?,?,?,?,?,?,NULL,NULL,?,?,?,?,?,?,?,?,?,0,'planned')"
            );
            // 17 placeholders: i + 16×s = 17 characters in the type string.
            // Count them against the column list every time this is touched.
            if ($s) {
                $platform = (string)$src['platform'];
                $budget   = $v('budget');
                $bmode    = $v('billing_mode') ?: 'one_time';
                $urate    = $v('unit_rate');
                $uwd      = $v('unit_weekday');
                $ulabel   = $v('unit_label');
                $cnotes   = $v('notes');
                $turl     = $v('target_url');
                $usrc     = $v('utm_source');
                $umed     = $v('utm_medium');
                $ucamp    = $v('utm_campaign');
                $ucont    = $v('utm_content');
                $pby      = $v('paid_by');
                $pbrk     = $v('paid_broker_amount');
                $pmh      = $v('paid_mh_amount');
                $s->bind_param('issssssssssssssss', $id, $platform, $name, $budget, $bmode,
                               $urate, $uwd, $ulabel, $cnotes, $turl,
                               $usrc, $umed, $ucamp, $ucont, $pby, $pbrk, $pmh);
                $s->execute();
                $new_cid = (int)$conn->insert_id;
                $s->close();
            }

            // Every creative comes along with its own file and target URL —
            // that is most of the value of duplicating at all.
            if ($new_cid) {
                $a = $conn->prepare(
                    "INSERT INTO marketing_campaign_assets (campaign_id,label,file_url,target_url,file_type)
                     SELECT ?, label, file_url, target_url, file_type
                       FROM marketing_campaign_assets
                      WHERE campaign_id = ?
                      ORDER BY id"
                );
                if ($a) { $a->bind_param('ii', $new_cid, $cid); $a->execute(); $a->close(); }
            }
        }

        if ($new_cid) {
            // Land on the copy with its edit form already open — the dates and
            // the spend are the whole reason for the duplicate.
            header("Location: agent.php?id={$id}&tab=advertising&edit_camp={$new_cid}#camp-{$new_cid}"); exit;
        }
        header("Location: agent.php?id={$id}&tab=advertising"); exit;
    }

    if ($action === 'delete_campaign') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $s   = $conn->prepare("DELETE FROM marketing_campaigns WHERE id=? AND intake_id=?");
        $s->bind_param('ii', $cid, $id); $s->execute(); $s->close();
        header("Location: agent.php?id={$id}&tab=advertising"); exit;
    }

    /* --- Collateral Orders --- */
    $coll_order_fields = ['label','vendor','vendor_url','order_number','qty','cost','status',
                          'ordered_at','tracking_number','tracking_url','delivered_at','file_url','notes',
                          'paid_by','paid_broker_amount','paid_mh_amount','billed_with_order_id'];
    $valid_coll_types  = ['business_cards','yard_signs','oh_signs','postcards','brochures','other'];

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

    if ($action === 'add_collateral_order') {
        $type = trim($_POST['type'] ?? '');
        $err  = '';
        if (in_array($type, $valid_coll_types)) {
            $rec = mk_store_receipt();
            if ($rec && isset($rec['error'])) {
                $err = $rec['error'];
            } else {
                $cols = ['intake_id','type']; $vals = [$id, $type]; $types = 'is'; $phs = ['?','?'];
                foreach ($coll_order_fields as $f) {
                    $v = trim($_POST[$f] ?? '');
                    $cols[]  = "`{$f}`"; $phs[] = '?';
                    $vals[]  = $v === '' ? null : $v; $types .= 's';
                }
                if ($rec) {
                    foreach ([['receipt_file', $rec['file']], ['receipt_orig_name', $rec['orig']],
                              ['receipt_uploaded_at', date('Y-m-d H:i:s')]] as [$c, $v]) {
                        $cols[] = "`{$c}`"; $phs[] = '?'; $vals[] = $v; $types .= 's';
                    }
                }
                $sql = "INSERT INTO marketing_collateral_orders (" . implode(',', $cols) . ") VALUES (" . implode(',', $phs) . ")";
                $s   = $conn->prepare($sql);
                if ($s) { $s->bind_param($types, ...$vals); $s->execute(); $s->close(); }
            }
        }
        $q = $err ? '&err=' . rawurlencode($err) : '&saved=1';
        header("Location: agent.php?id={$id}&tab=collateral{$q}"); exit;
    }

    if ($action === 'update_collateral_order') {
        $oid  = (int)($_POST['order_id'] ?? 0);
        $err  = '';
        $sets = []; $vals = []; $types = '';
        foreach ($coll_order_fields as $f) {
            $v = trim($_POST[$f] ?? '');
            $sets[]  = "`{$f}` = ?";
            $vals[]  = $v === '' ? null : $v; $types .= 's';
        }

        // A new upload replaces the old file; no upload leaves it untouched.
        $rec = mk_store_receipt();
        if ($rec && isset($rec['error'])) {
            $err = $rec['error'];
        } elseif ($rec) {
            $old = $conn->prepare("SELECT receipt_file FROM marketing_collateral_orders WHERE id=? AND intake_id=? LIMIT 1");
            if ($old) {
                $old->bind_param('ii', $oid, $id);
                $old->execute();
                $orow = $old->get_result()->fetch_assoc();
                $old->close();
                if ($orow) mk_delete_receipt_file($orow['receipt_file'] ?? null);
            }
            foreach ([['receipt_file', $rec['file']], ['receipt_orig_name', $rec['orig']],
                      ['receipt_uploaded_at', date('Y-m-d H:i:s')]] as [$c, $v]) {
                $sets[] = "`{$c}` = ?"; $vals[] = $v; $types .= 's';
            }
        }

        if (!$err) {
            $vals[] = $oid; $vals[] = $id; $types .= 'ii';
            $s = $conn->prepare("UPDATE marketing_collateral_orders SET " . implode(', ', $sets) . " WHERE id=? AND intake_id=?");
            if ($s) { $s->bind_param($types, ...$vals); $s->execute(); $s->close(); }
        }
        $q = $err ? '&err=' . rawurlencode($err) : '&saved=1';
        header("Location: agent.php?id={$id}&tab=collateral{$q}"); exit;
    }

    if ($action === 'delete_collateral_receipt') {
        $oid = (int)($_POST['order_id'] ?? 0);
        $g = $conn->prepare("SELECT receipt_file FROM marketing_collateral_orders WHERE id=? AND intake_id=? LIMIT 1");
        if ($g) {
            $g->bind_param('ii', $oid, $id);
            $g->execute();
            $grow = $g->get_result()->fetch_assoc();
            $g->close();
            if ($grow) mk_delete_receipt_file($grow['receipt_file'] ?? null);
        }
        $s = $conn->prepare(
            "UPDATE marketing_collateral_orders
                SET receipt_file=NULL, receipt_orig_name=NULL, receipt_uploaded_at=NULL
              WHERE id=? AND intake_id=?"
        );
        if ($s) { $s->bind_param('ii', $oid, $id); $s->execute(); $s->close(); }
        header("Location: agent.php?id={$id}&tab=collateral"); exit;
    }

    if ($action === 'delete_collateral_order') {
        $oid = (int)($_POST['order_id'] ?? 0);
        $s   = $conn->prepare("DELETE FROM marketing_collateral_orders WHERE id=? AND intake_id=?");
        $s->bind_param('ii', $oid, $id); $s->execute(); $s->close();
        header("Location: agent.php?id={$id}&tab=collateral"); exit;
    }

    if ($action === 'toggle_email_forwarded') {
        $val = (int)($_POST['email_forwarded'] ?? 0);
        $conn->query("UPDATE marketing_intakes SET email_forwarded={$val} WHERE id={$id}");
        header("Location: agent.php?id={$id}&tab=profile"); exit;
    }

    /* --- Assets: extra one-off links --- */
    if ($action === 'add_asset_link') {
        $label = trim($_POST['label'] ?? '');
        $url   = trim($_POST['url']   ?? '');
        if ($label !== '' && $url !== '') {
            // Look up next sort_order separately — MySQL won't allow a subquery
            // against the same table being inserted into.
            $nr    = $conn->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM marketing_asset_links WHERE intake_id={$id}");
            $order = $nr ? (int)$nr->fetch_assoc()['n'] : 1;
            $s = $conn->prepare(
                "INSERT INTO marketing_asset_links (intake_id, label, url, sort_order) VALUES (?, ?, ?, ?)"
            );
            if ($s) { $s->bind_param('issi', $id, $label, $url, $order); $s->execute(); $s->close(); }
        }
        header("Location: agent.php?id={$id}&tab=assets&saved=1"); exit;
    }

    if ($action === 'delete_asset_link') {
        $lid = (int)($_POST['link_id'] ?? 0);
        $s   = $conn->prepare("DELETE FROM marketing_asset_links WHERE id=? AND intake_id=?");
        if ($s) { $s->bind_param('ii', $lid, $id); $s->execute(); $s->close(); }
        header("Location: agent.php?id={$id}&tab=assets"); exit;
    }

    /* --- Profile tab: website, photos, board identities, subscriptions ---
       Everything the site admin used to edit lives here now: marketing is the
       one place, and the website reads it from the feed (Nikki, 2026-09-23). */
    if ($action === 'web_status') {
        $to = $_POST['to'] ?? '';
        if (in_array($to, ['approved', 'pending', 'inactive'], true) && mk_column_exists($conn, 'marketing_intakes', 'web_status')) {
            $appr = $to === 'approved' ? 'NOW()' : 'web_approved_at';
            $conn->query("UPDATE marketing_intakes SET web_status = '{$to}', web_approved_at = {$appr} WHERE id = {$id}");
        }
        header("Location: agent.php?id={$id}&tab=profile&saved=1"); exit;
    }

    if ($action === 'update_web') {
        $err = '';
        $slug = mk_slug_base((string)($_POST['slug'] ?? ''));
        if ($slug !== '') {
            $chk = $conn->prepare("SELECT id FROM marketing_intakes WHERE slug = ? AND id <> ? LIMIT 1");
            $chk->bind_param('si', $slug, $id); $chk->execute();
            if ($chk->get_result()->fetch_row()) { $err = "The web address \"{$slug}\" belongs to another agent."; $slug = ''; }
            $chk->close();
        }
        if ($err === '') {
            $sort   = (int)($_POST['sort_order'] ?? 0);
            $office = trim((string)($_POST['office'] ?? '')) ?: null;
            $area   = trim((string)($_POST['service_area'] ?? '')) ?: null;
            $fub    = isset($_POST['in_fub']) ? 1 : 0;
            $sql = "UPDATE marketing_intakes SET sort_order = ?, office = ?, service_area = ?, in_fub = ?"
                 . ($slug !== '' ? ', slug = ?' : '') . " WHERE id = ?";
            $st = $conn->prepare($sql);
            if ($slug !== '') { $st->bind_param('issisi', $sort, $office, $area, $fub, $slug, $id); }
            else              { $st->bind_param('issii', $sort, $office, $area, $fub, $id); }
            $st->execute(); $st->close();
        }
        header("Location: agent.php?id={$id}&tab=profile" . ($err ? '&err=' . urlencode($err) : '&saved=1')); exit;
    }

    // Headshots, stored here and served from this portal, which is what the
    // website accepts (a Dropbox link is a page, not an image).
    if ($action === 'upload_photos') {
        require_once __DIR__ . '/inc/photos.php';
        $photo_note = '';   // e.g. a photo tagged Adobe RGB rather than sRGB
        $row  = $conn->query("SELECT slug, headshot_url FROM marketing_intakes WHERE id = {$id}")->fetch_assoc();
        $slug = trim((string)($row['slug'] ?? '')) ?: ('agent-' . $id);
        $agent_photo_url = (string)($row['headshot_url'] ?? '');
        // The crop tool sends the box it framed, in the picture's own pixels,
        // and the cropping happens here. (It also sends the square it made,
        // used only as a fallback: a photo hosted elsewhere taints the canvas
        // and that export fails silently in the browser.)
        $crop_w = (float)($_POST['crop_w'] ?? 0);
        if ($crop_w > 0) {
            $from = ($_POST['crop_src'] ?? '') === 'file' && !empty($_FILES['headshot_face']['tmp_name'])
                  ? (string)$_FILES['headshot_face']['tmp_name']
                  : mk_photo_file($agent_photo_url);
            $res = mk_crop_photo($from, ['x' => (float)($_POST['crop_x'] ?? 0), 'y' => (float)($_POST['crop_y'] ?? 0),
                                         'w' => $crop_w, 'h' => (float)($_POST['crop_h'] ?? $crop_w)], $slug . '-square');
            if (!empty($res['error'])) { header("Location: agent.php?id={$id}&tab=profile&err=" . urlencode($res['error'])); exit; }
            $st = $conn->prepare("UPDATE marketing_intakes SET headshot_face_url = ? WHERE id = ?");
            $st->bind_param('si', $res['url'], $id); $st->execute(); $st->close();
            $photo_note = $photo_note ?: (string)($res['note'] ?? '');
        } elseif (!empty($_POST['headshot_face_data'])) {
            $res = mk_store_photo_data((string)$_POST['headshot_face_data'], $slug . '-square');
            if ($res && !empty($res['error'])) { header("Location: agent.php?id={$id}&tab=profile&err=" . urlencode($res['error'])); exit; }
            if ($res) {
                $st = $conn->prepare("UPDATE marketing_intakes SET headshot_face_url = ? WHERE id = ?");
                $st->bind_param('si', $res['url'], $id); $st->execute(); $st->close();
            }
        }
        // After the square, not before: both boxes were framed against the
        // photo as it is now, and cropping the profile photo rewrites that
        // file (2026-09-27).
        $pcrop_w = (float)($_POST['pcrop_w'] ?? 0);
        if ($pcrop_w > 0) {
            $from = ($_POST['pcrop_src'] ?? '') === 'file' && !empty($_FILES['headshot']['tmp_name'])
                  ? (string)$_FILES['headshot']['tmp_name']
                  : mk_photo_file($agent_photo_url);
            $res = mk_crop_photo($from, ['x' => (float)($_POST['pcrop_x'] ?? 0), 'y' => (float)($_POST['pcrop_y'] ?? 0),
                                         'w' => $pcrop_w, 'h' => (float)($_POST['pcrop_h'] ?? $pcrop_w)],
                                 $slug, 1200, 1500);
            if (!empty($res['error'])) { header("Location: agent.php?id={$id}&tab=profile&err=" . urlencode($res['error'])); exit; }
            $st = $conn->prepare("UPDATE marketing_intakes SET headshot_url = ? WHERE id = ?");
            $st->bind_param('si', $res['url'], $id); $st->execute(); $st->close();
            $photo_note = $photo_note ?: (string)($res['note'] ?? '');
        }

        foreach ([['headshot', '', false, 'headshot_url'], ['headshot_face', '-square', true, 'headshot_face_url']] as [$field, $suffix, $face, $col]) {
            if ($field === 'headshot' && $pcrop_w > 0) continue;   // already cropped above
            $res = mk_store_photo($field, $slug . $suffix, $face);
            if ($res === null) continue;
            if (!empty($res['error'])) { header("Location: agent.php?id={$id}&tab=profile&err=" . urlencode($res['error'])); exit; }
            $st = $conn->prepare("UPDATE marketing_intakes SET `{$col}` = ? WHERE id = ?");
            $st->bind_param('si', $res['url'], $id); $st->execute(); $st->close();
            $photo_note = $photo_note ?: (string)($res['note'] ?? '');
            // A profile photo on its own also gives us the square one, so a new
            // agent is never left without the picture the cards and lists use.
            if (!$face && empty($_FILES['headshot_face']['name']) && empty($_POST['headshot_face_data'])
                && $crop_w <= 0 && !empty($res['square_url'])) {
                $st = $conn->prepare("UPDATE marketing_intakes SET headshot_face_url = ? WHERE id = ?");
                $st->bind_param('si', $res['square_url'], $id); $st->execute(); $st->close();
            }
        }
        foreach ([['remove_headshot', 'headshot_url'], ['remove_headshot_face', 'headshot_face_url']] as [$flag, $col]) {
            if (!empty($_POST[$flag])) $conn->query("UPDATE marketing_intakes SET `{$col}` = NULL WHERE id = {$id}");
        }
        header("Location: agent.php?id={$id}&tab=profile&saved=1"
             . ($photo_note !== '' ? '&note=' . urlencode($photo_note) : '')); exit;
    }

    // Leadership page settings (leadership_v1.sql): agents and staff alike.
    if ($action === 'save_leadership' && mk_column_exists($conn, 'marketing_intakes', 'leadership_show')) {
        $show = isset($_POST['leadership_show']) ? 1 : 0;
        $sort = (int)($_POST['leadership_sort'] ?? 0);
        $st = $conn->prepare("UPDATE marketing_intakes SET leadership_show = ?, leadership_sort = ? WHERE id = ?");
        $st->bind_param('iii', $show, $sort, $id);
        $st->execute(); $st->close();
        header("Location: agent.php?id={$id}&tab=profile&saved=1#leadership"); exit;
    }

    // Staff are not agents: no board identities (leadership_v1.sql).
    if ($action === 'add_identity') {
        $et = $conn->query("SELECT entity_type FROM marketing_intakes WHERE id = {$id}");
        if ($et && ($et->fetch_row()[0] ?? '') === 'staff') { header("Location: agent.php?id={$id}&tab=profile"); exit; }
    }
    if ($action === 'add_identity' && mk_table_exists($conn, 'agent_mls_ids')) {
        $market = strtolower(trim((string)($_POST['market'] ?? '')));
        $mls_id = trim((string)($_POST['mls_agent_id'] ?? ''));
        $alias  = isset($_POST['is_alias']) ? 1 : 0;
        // Only a registry slug: an identity under any other market would never
        // match the site's listings (check_health.php reports such rows).
        if (isset(mk_board_registry()[$market]) && $mls_id !== '') {
            // last_seen_at stays NULL: an identity the feed has never returned
            // is never used to mark anyone Inactive.
            $st = $conn->prepare("INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id, member_status, is_alias)
                                  VALUES (?, ?, ?, 'Active', ?)");
            $st->bind_param('issi', $id, $market, $mls_id, $alias); $st->execute(); $st->close();
        }
        header("Location: agent.php?id={$id}&tab=profile&saved=1"); exit;
    }

    if ($action === 'remove_identity' && mk_table_exists($conn, 'agent_mls_ids')) {
        $iid = (int)($_POST['identity_id'] ?? 0);
        if ($iid) $conn->query("DELETE FROM agent_mls_ids WHERE id = {$iid} AND intake_id = {$id}");
        header("Location: agent.php?id={$id}&tab=profile&saved=1"); exit;
    }

    // Hot Sheets: the same switch as the checklist item, kept in step with it.
    if ($action === 'hs_toggle') {
        $on = ($_POST['to'] ?? '') === 'on';
        $msg = '';
        if ($on) {
            $msg = mk_hs_subscribe($conn, $id);
            $ok  = str_starts_with($msg, 'Subscribed') || str_contains($msg, 'already gets') || str_starts_with($msg, 'Re-activated');
        } else {
            mk_hs_pause($conn, $id);
            $ok = true;
        }
        // The checklist item and this switch are the same thing, so keep them
        // in step: only tick it when they really are subscribed.
        if ($ok) {
            $conn->query("UPDATE marketing_tasks SET status = '" . ($on ? 'done' : 'open') . "', completed_at = " . ($on ? 'NOW()' : 'NULL')
                       . " WHERE intake_id = {$id} AND category = 'onboarding' AND title = '" . $conn->real_escape_string(MK_HS_TASK) . "'");
        }
        header("Location: agent.php?id={$id}&tab=profile" . ($ok ? '&saved=1' : '&err=' . urlencode((string)$msg))); exit;
    }

    /* --- Inline field updates (contact, social, bio, collateral) --- */
    if ($action === 'update_intake_fields') {
        $allowed = [
            'agent_name','initials','agent_title','cell_phone','mh_email','alt_email','email_forwarded',
            'website_url','start_date','headshot_url',
            'social_instagram','social_facebook','social_linkedin','social_tiktok','social_other',
            'bio_url','bio_text','bio_short',
            // Assets tab — file links
            'qr_code_url','email_signature_url',
            // Assets tab — headshot / photoshoot
            'headshot_notes','photoshoot_not_needed','photoshoot_photographer',
            'headshot_photoshoot_date','photoshoot_proofs_date','photoshoot_finals_date',
            // Assets tab — other docs
            'assets_cv_url','assets_other_notes',
            // Collateral
            'coll_business_cards','coll_bc_qty','coll_bc_front','coll_bc_back',
            'coll_bc_chk_design','coll_bc_chk_proof','coll_bc_chk_accounting','coll_bc_chk_ordered','coll_bc_chk_delivered',
            'coll_yard_signs','coll_ys_qty','coll_ys_design','coll_ys_info',
            'coll_oh_signs','coll_oh_qty','coll_oh_qr_code','coll_oh_qr_url','coll_oh_info',
            'coll_postcards','coll_pc_listing','coll_pc_target_area',
            'coll_pc_chk_design','coll_pc_chk_proof','coll_pc_chk_accounting','coll_pc_chk_ordered','coll_pc_chk_delivered',
            'coll_brochures','coll_br_listing','coll_br_chk_design','coll_br_chk_proof','coll_br_chk_created',
            'coll_other',
        ];
        // initials arrived with alter_intake_initials_v1.sql. Drop it if that
        // has not been run rather than letting one unknown column silently
        // discard the whole form.
        if (in_array('initials', $allowed, true) && array_key_exists('initials', $_POST)
            && !mk_column_exists($conn, 'marketing_intakes', 'initials')) {
            $allowed = array_values(array_diff($allowed, ['initials']));
        }

        $sets = []; $vals = []; $types = '';
        // Platform URL prefixes to strip so handles are stored bare (no @ or domain)
        $social_url_prefixes = [
            'social_instagram' => ['https://www.instagram.com/','https://instagram.com/'],
            'social_facebook'  => ['https://www.facebook.com/','https://facebook.com/','https://fb.com/'],
            'social_linkedin'  => ['https://www.linkedin.com/in/','https://linkedin.com/in/',
                                   'https://www.linkedin.com/company/','https://linkedin.com/company/'],
            'social_tiktok'    => ['https://www.tiktok.com/@','https://tiktok.com/@',
                                   'https://www.tiktok.com/','https://tiktok.com/'],
        ];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $_POST)) continue;
            $v = trim($_POST[$f]);
            // Title is a small text box: a newline in it means "break the
            // broker card here" (2026-09-28). Keep single newlines, drop the
            // browser's \r and any padding around them.
            if ($f === 'agent_title' && $v !== '') {
                $v = preg_replace('/[ \t]*\n[ \t]*/', "\n", str_replace(["\r\n", "\r"], "\n", $v));
                $v = preg_replace('/\n{2,}/', "\n", $v);
            }
            // Normalize handle fields: strip platform URL prefix then leading @
            if (isset($social_url_prefixes[$f]) && $v !== '') {
                foreach ($social_url_prefixes[$f] as $prefix) {
                    if (stripos($v, $prefix) === 0) { $v = substr($v, strlen($prefix)); break; }
                }
                $v = ltrim($v, '@');
            }
            // bio_text comes from the rich-text editor — keep safe formatting tags only
            if ($f === 'bio_text' && $v !== '') {
                $v = strip_tags($v, '<p><br><strong><b><em><i><u><ol><ul><li><a><h3><h4><blockquote>');
                // Quill emits an empty paragraph when the editor is cleared
                if (in_array(trim($v), ['<p><br></p>', '<p></p>', '<br>'], true)) $v = '';
            }
            $sets[]  = "`{$f}` = ?";
            $vals[]  = $v === '' ? null : $v;
            $types  .= 's';
        }
        if ($sets) {
            $vals[] = $id; $types .= 'i';
            $s = $conn->prepare("UPDATE marketing_intakes SET " . implode(', ', $sets) . " WHERE id=?");
            if ($s) { $s->bind_param($types, ...$vals); $s->execute(); $s->close(); }
        }
        $rtab = $_POST['redirect_tab'] ?? 'profile';
        header("Location: agent.php?id={$id}&tab={$rtab}&saved=1"); exit;
    }
}

// ── Load agent ────────────────────────────────────────────────────────────────
$r     = $conn->query("SELECT * FROM marketing_intakes WHERE id={$id} LIMIT 1");
$agent = $r ? $r->fetch_assoc() : null;
if (!$agent) { header('Location: index.php'); exit; }

// Staff (leadership_v1.sql): a person on the Leadership page who is not an
// agent. The page shows only what applies to them: contact, headshots, QR
// codes and the Leadership settings. No tasks, billing, MLS or website tabs.
$is_staff = ($agent['entity_type'] ?? 'agent') === 'staff';
// Asked HERE, not in the template: the connection is closed before the page
// renders, and a query after that is a fatal that cuts the page off mid-way
// (it took the crop tool with it on 2026-10-02).
$has_leadership = mk_column_exists($conn, 'marketing_intakes', 'leadership_show');

// ── Auto-seed onboarding tasks if none exist yet ──────────────────────────────
// Not for staff: the checklist is agent onboarding.
if (!$is_staff) mkt_seed_onboarding_tasks($conn, $id);

// ── Load tasks — build parent/child tree ─────────────────────────────────────
$all_tasks    = [];
$parent_tasks = [];   // top-level tasks keyed by id
$child_tasks  = [];   // children keyed by parent_id
$r = $conn->query("SELECT * FROM marketing_tasks WHERE intake_id={$id} ORDER BY sort_order ASC, created_at ASC");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $all_tasks[(int)$row['id']] = $row;
        if ($row['parent_id'] === null) {
            $parent_tasks[(int)$row['id']] = $row;
        } else {
            $child_tasks[(int)$row['parent_id']][] = $row;
        }
    }
}

// Open task count: all open parent tasks
$open_tasks = count(array_filter($parent_tasks, fn($t) => $t['status'] === 'open'));
$done_tasks = count(array_filter($parent_tasks, fn($t) => $t['status'] === 'done'));

// The Overview card is a "what's left" list, not a record of what was done.
// Completed tasks drop off it entirely and live under the Tasks tab, where
// the Completed filter shows them. Onboarding items sort first so a new
// agent's checklist stays in its seeded order, with ad-hoc tasks after.
$overview_tasks = array_filter($parent_tasks, fn($t) => $t['status'] === 'open');
uasort($overview_tasks, function ($a, $b) {
    $ao = $a['category'] === 'onboarding' ? 0 : 1;
    $bo = $b['category'] === 'onboarding' ? 0 : 1;
    if ($ao !== $bo) return $ao <=> $bo;
    return ((int)$a['sort_order'] <=> (int)$b['sort_order'])
        ?: strcmp((string)$a['created_at'], (string)$b['created_at']);
});

// ── Financial summary ─────────────────────────────────────────────────────────
// The arithmetic lives in inc/financials.php, because billing.php runs the same
// calculation across every agent. One implementation: a second copy here would
// drift, and the first sign of it would be an invoice that disagrees with the
// screen.
//
// $coll_rows and $camp_overrides come back too — the Collateral tab and the
// month grid both need them, and querying twice would be wasteful and could
// read a different row mid-request.
$fin            = mh_agent_financials($conn, $id);
$fin_months     = $fin['months'];
$fin_grand      = $fin['grand'];
$coll_rows      = $fin['coll_rows'];
$camp_overrides = $fin['camp_overrides'];


// ── Assets: extra one-off links ───────────────────────────────────────────────
// Guarded: on PHP 8.1+ mysqli throws, so this would fatal the whole page if
// assets_tab_v2.sql hasn't been run yet. Degrade to an empty list instead.
$asset_links = [];
try {
    $r = $conn->query("SELECT * FROM marketing_asset_links WHERE intake_id={$id} ORDER BY sort_order ASC, id ASC");
    if ($r) $asset_links = $r->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    error_log('marketing_asset_links not available: ' . $e->getMessage());
}

// ── Collateral orders ─────────────────────────────────────────────────────────
$coll_orders = [];
$coll_all = [];   // flat, keyed by id — used by the "billed with" selector
$r = $conn->query("SELECT * FROM marketing_collateral_orders WHERE intake_id={$id} ORDER BY type ASC, created_at ASC");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $coll_orders[$row['type']][] = $row;
        $coll_all[(int)$row['id']]   = $row;
    }
}

// ── Campaigns ─────────────────────────────────────────────────────────────────
$campaigns = [];
$r = $conn->query("SELECT * FROM marketing_campaigns WHERE intake_id={$id} ORDER BY created_at DESC");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $cid = $row['id'];
        $ar  = $conn->query("SELECT * FROM marketing_campaign_assets WHERE campaign_id={$cid} ORDER BY uploaded_at ASC");
        $row['assets'] = $ar ? $ar->fetch_all(MYSQLI_ASSOC) : [];
        $campaigns[] = $row;
    }
}
// Financials renders per-month editors against the campaign row, and it only
// carries the campaign id on each line. Index once here rather than searching
// $campaigns for every line.
$camp_by_id = [];
foreach ($campaigns as $_c) { $camp_by_id[(int)$_c['id']] = $_c; }
unset($_c);

// ── Notes ─────────────────────────────────────────────────────────────────────
$notes = [];
$r = $conn->query("SELECT mn.*, CONCAT(u.first_name,' ',u.last_name) AS author FROM marketing_notes mn LEFT JOIN users u ON u.id=mn.created_by WHERE mn.intake_id={$id} ORDER BY mn.created_at DESC");
if ($r) $notes = $r->fetch_all(MYSQLI_ASSOC);

// ── Current listings (Anyprop, 2026-10-01) ──────────────────────────────────
// From hs_listing_state: every Mont Haus listing in the public site's Anyprop
// feed, kept current by cron/sync_hot_sheet_listings.php. Matched on this
// agent's board identities (agent_mls_ids: market + MLS id, team ids included
// as alias rows) as list or co-list agent; a team's page uses its members'
// identities too. This replaced the Spark-era sources, the `listings` /
// `listing_brokers` snapshot (frozen 2026-08-21) and a live Spark v1 call that
// returned HTTP 400 on every view and showed nothing, which is why new
// listings never appeared (Jackson Horn, 633 W Main Street, 2026-10-01).
$listings = [];
if (mk_table_exists($conn, 'hs_listing_state') && mk_table_exists($conn, 'agent_mls_ids')) {
    $owner_ids = [$id];
    if (($agent['entity_type'] ?? 'agent') === 'team' && mk_table_exists($conn, 'team_members')) {
        $tm = $conn->query("SELECT member_id FROM team_members WHERE team_id = {$id}");
        foreach ($tm ? $tm->fetch_all(MYSQLI_NUM) : [] as $tmr) $owner_ids[] = (int)$tmr[0];
    }
    $or = [];
    $ri = $conn->query("SELECT DISTINCT market, mls_agent_id FROM agent_mls_ids
                         WHERE intake_id IN (" . implode(',', $owner_ids) . ") AND mls_agent_id <> ''");
    foreach ($ri ? $ri->fetch_all(MYSQLI_ASSOC) : [] as $ident) {
        $mk_e = $conn->real_escape_string($ident['market']);
        $id_e = $conn->real_escape_string($ident['mls_agent_id']);
        $or[] = "(market = '{$mk_e}' AND (list_agent_mls_id = '{$id_e}' OR colist_agent_mls_id = '{$id_e}'))";
    }
    if ($or) {
        $r = $conn->query("
            SELECT market, mls_id, address, city, status, is_rental, list_price, primary_photo, url
              FROM hs_listing_state
             WHERE in_feed = 1
               AND status IN ('Coming Soon', 'Active', 'Active Under Contract', 'Pending')
               AND (" . implode(' OR ', $or) . ")
             ORDER BY FIELD(status, 'Coming Soon', 'Active', 'Active Under Contract', 'Pending'),
                      is_rental, list_price DESC");
        if ($r) $listings = $r->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Profile tab data ────────────────────────────────────────────────────────
// Board labels and the picker's choices come from the registry (inc/boards.php, via agent_roster.php).
function e_attr($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

$agent_slug = trim((string)($agent['slug'] ?? ''));
$web_status = (string)($agent['web_status'] ?? 'pending');
$web_label  = ['approved' => 'On the website', 'pending' => 'Waiting for approval', 'inactive' => 'Off the website'];
$web_actions = ['approved' => 'Put on the site', 'pending' => 'Back to waiting', 'inactive' => 'Take off the site'];
unset($web_actions[$web_status]);
if ($web_status === 'pending') unset($web_actions['inactive']);   // one step at a time

// What the website still wants from us.
$hosted = fn($u) => $u && (str_starts_with((string)$u, 'photo.php') || str_starts_with((string)$u, rtrim(SITE_URL, '/') . '/'));
$web_missing = [];
if (!$hosted($agent['headshot_url'] ?? ''))      $web_missing[] = 'a headshot we host';
if (trim(strip_tags((string)($agent['bio_text'] ?? ''))) === '') $web_missing[] = 'a bio';
if ($agent_slug === '')                          $web_missing[] = 'a web address';

$office_options = ['aspen', 'vail'];
$r_off = $conn->query("SELECT DISTINCT office FROM marketing_intakes WHERE office IS NOT NULL AND office <> ''");
if ($r_off) while ($o = $r_off->fetch_row()) $office_options[] = strtolower(trim($o[0]));
if (!empty($agent['office'])) $office_options[] = strtolower(trim((string)$agent['office']));
$office_options = array_values(array_unique(array_filter($office_options)));
sort($office_options);

$identities = [];
if (mk_table_exists($conn, 'agent_mls_ids')) {
    $r_id = $conn->query("SELECT * FROM agent_mls_ids WHERE intake_id = {$id} ORDER BY is_alias, market");
    if ($r_id) $identities = $r_id->fetch_all(MYSQLI_ASSOC);
}
$hs_on = mk_hs_subscribed($conn, $id);
$hs_email = mk_agent_email($agent);

// QR codes (qr_codes.php). Read here because the connection closes just below.
$qr_rows = [];
if (mk_table_exists($conn, 'qr_codes')) {
    $r_qr = $conn->query("SELECT id, code, label, dest_type, dest_url, is_active, scan_count
                            FROM qr_codes WHERE intake_id = {$id} ORDER BY created_at");
    if ($r_qr) $qr_rows = $r_qr->fetch_all(MYSQLI_ASSOC);
}

// Who sits either side of this agent, so the page can be stepped through
// without going back to the roster each time (Nikki, 2026-09-29). Same set and
// same order as the roster's Active tab: everyone still with Mont Haus, by last
// name, teams left out. An archived agent is not in the list, so the arrows are
// simply absent for them.
$nav_agents = [];
$nav_name = "COALESCE(NULLIF(TRIM(mi.agent_name), ''), mi.mls_full_name)";
$r_nav = $conn->query("
    SELECT mi.id, {$nav_name} AS name
      FROM marketing_intakes mi
     WHERE mi.is_active = 1 AND mi.status <> 'archived'"
     . mk_agents_only_sql($conn, 'mi') . "
       AND TRIM(COALESCE({$nav_name}, '')) <> ''
     ORDER BY SUBSTRING_INDEX(TRIM({$nav_name}), ' ', -1), {$nav_name}, mi.id");
if ($r_nav) $nav_agents = $r_nav->fetch_all(MYSQLI_ASSOC);

$conn->close();

// ── UI helpers ────────────────────────────────────────────────────────────────
// Profile is the page: Overview was folded into it (Nikki, 2026-09-25).
$active_tab = $_GET['tab'] ?? 'profile';

// Position in that list, and the two neighbours.
$nav_pos = null; $nav_prev = null; $nav_next = null;
foreach ($nav_agents as $i => $n) {
    if ((int)$n['id'] === $id) {
        $nav_pos  = $i;
        $nav_prev = $nav_agents[$i - 1] ?? null;
        $nav_next = $nav_agents[$i + 1] ?? null;
        break;
    }
}
if ($active_tab === 'overview') $active_tab = 'profile';
if ($is_staff) $active_tab = 'profile';



function val(array $row, string $key): string { return htmlspecialchars($row[$key] ?? ''); }
function money(float $n): string { return '$' . number_format($n, 0); }
// Financials needs exact figures — money() rounds to whole dollars, which is
// right for listing prices but wrong when reconciling an invoice.
function money2(float $n): string { return '$' . number_format($n, 2); }
/**
 * A DECIMAL as it should appear in a form field: '400.00' -> '400', '3.50'
 * -> '3.5'. MySQL always hands back the scale, and putting '3.00' in a number
 * input makes a plain count look like a calculation.
 */
function mk_num($n): string {
    if ($n === null || $n === '') return '';
    $s = rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}
/**
 * Avatar initials. Kept identical to index.php's copy — the roster card and the
 * profile show the same avatar for the same person, so if these two ever
 * disagree it is visible immediately and looks like a data bug.
 */
function initials(string $name, ?string $override = null): string {
    $o = strtoupper(trim((string)$override));
    if ($o !== '') return substr($o, 0, 3);

    $p = preg_split('/\s+/', trim($name));
    $i = strtoupper($p[0][0] ?? '');
    if (count($p) > 1) $i .= strtoupper($p[count($p)-1][0] ?? '');
    return substr($i, 0, 2);
}

// Active = with Mont Haus in the MLS (2026-09-22): old 'roster' / 'pending'
// rows are Active; a row the MLS stopped listing under MH is Inactive.
$status       = $agent['status'] ?: 'active';
if (in_array($status, ['roster', 'pending'], true)) $status = 'active';
if ($status === 'active' && !empty($agent['departure_detected_at'])) $status = 'inactive';
$status_label = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];
$status_color = ['active' => '#10b981', 'inactive' => '#dc2626', 'archived' => '#9ca3af'];
$chip_color   = $status_color[$status] ?? '#9ca3af';
$chip_text    = $status_label[$status] ?? $status;

$order_status_labels = [
    'pending'       => 'Pending',
    'ordered'       => 'Ordered',
    'in_production' => 'In Production',
    'shipped'       => 'Shipped',
    'delivered'     => 'Delivered',
];
$order_status_colors = [
    'pending'       => ['bg'=>'#f3f4f6','text'=>'#6b7280','border'=>'#e5e7eb'],
    'ordered'       => ['bg'=>'#eff6ff','text'=>'#1d4ed8','border'=>'#bfdbfe'],
    'in_production' => ['bg'=>'#fff7ed','text'=>'#c2410c','border'=>'#fed7aa'],
    'shipped'       => ['bg'=>'#faf5ff','text'=>'#7c3aed','border'=>'#ddd6fe'],
    'delivered'     => ['bg'=>'#f0fdf4','text'=>'#16a34a','border'=>'#bbf7d0'],
];

$platform_labels = [
    'vail_daily' => 'Vail Daily', 'aspen_daily' => 'Aspen Daily News',
    'aspen_times' => 'Aspen Times', 'social_meta' => 'Meta (FB/IG)',
    'social_instagram' => 'Instagram', 'google' => 'Google', 'other' => 'Other',
];
$campaign_status_labels = ['planned' => 'Planned', 'active' => 'Active', 'ended' => 'Ended'];
$campaign_status_colors = ['planned' => '#6b7280', 'active' => '#10b981', 'ended' => '#9ca3af'];

// The onboarding checklist is a flat list — no sub-tasks.
// Parent-level tasks that need a date picker on the checklist row
$due_date_parent_tasks = ['Marketing Overview Meeting', 'Instagram Announcement', 'B2B Announcement'];

// Listings for the URL builder (JS): each one's page on the new website, as
// the site's feed gives it. These follow the site's own address, so they become
// monthaus.com links at go-live; until then they open the preview site.
$listings_json = json_encode(array_values(array_filter(array_map(fn($l) => [
    'label' => $l['address'] . ($l['city'] ? ', '.$l['city'] : '') . ' ('.($l['status'] === 'Active Under Contract' ? 'Under Contract' : $l['status']).')',
    'url'   => (string)($l['url'] ?? ''),
], $listings), fn($x) => $x['url'] !== '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= val($agent,'agent_name') ?> — Mont Haus Marketing</title>
  <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <!-- The bio editor and the crop tool are served from this portal rather than
       from a CDN (2026-09-28). When cdn.jsdelivr.net could not be reached the
       crop buttons went quietly dead, because mkCropTool() binds nothing
       without Cropper. The CDN stays below as a fallback, so the page still
       works if these local copies are ever missing. -->
  <link rel="stylesheet" href="/assets/vendor/quill.snow.css">
  <link rel="stylesheet" href="/assets/vendor/cropper.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root { --bs-primary: #0184BB; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .pc-container { margin-left:0; top:0; margin-top:70px; min-height:calc(100vh - 70px); }
    .mh-header-inner {
      width:100%; padding:0 15px;
      display:flex; align-items:center; justify-content:space-between; height:70px;
    }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link {
      display:inline-flex; align-items:center; gap:5px;
      padding:5px 12px; font-size:.8rem; font-weight:500;
      color:#75BDB6; text-decoration:none; border-radius:6px;
      white-space:nowrap; transition:color .2s, background .2s;
    }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }
    .wrap { max-width:1100px; margin:0 auto; padding:28px 24px 80px; }

    /* ── Buttons ── */
    .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 18px;
           font-size:13px; font-weight:600; border:none; border-radius:3px;
           cursor:pointer; text-decoration:none; text-transform:uppercase; letter-spacing:.4px; }
    .btn-primary { background:#f97316; color:#fff; }
    .btn-primary:hover { background:#ea6c0a; }
    .btn-sm  { padding:5px 12px; font-size:12px; }
    .btn-xs  { padding:3px 8px; font-size:11px; }
    .btn-outline { background:transparent; border:1px solid #d1d5db; color:#374151; }
    .btn-outline:hover { background:#f3f4f6; }
    .btn-danger { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
    .btn-danger:hover { background:#fecaca; }
    .btn-ghost { background:transparent; border:none; color:#9ca3af; padding:3px 5px;
                 cursor:pointer; font-size:13px; line-height:1; border-radius:3px; }
    .btn-ghost:hover { color:#991b1b; background:#fee2e2; }

    /* ── Breadcrumb ── */
    .breadcrumb { display:flex; align-items:center; gap:6px; font-size:12px;
                  color:#9ca3af; margin-bottom:20px; }
    .breadcrumb a { color:#6b7280; text-decoration:none; }
    .breadcrumb a:hover { color:#111; }
    .breadcrumb { margin-bottom:0; }
    .crumb-row { display:flex; align-items:center; justify-content:space-between;
                 gap:12px; flex-wrap:wrap; margin-bottom:20px; }

    /* ── Stepping between agents ── */
    .agent-step { display:flex; align-items:center; gap:6px; }
    .step-btn { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
                border:1px solid #d1d5db; border-radius:3px; background:#fff; color:#374151;
                text-decoration:none; font-size:14px; line-height:1; transition:background .15s, color .15s, border-color .15s; }
    .step-btn:hover { background:#f3f4f6; color:#111; border-color:#9ca3af; }
    .step-btn.off   { opacity:.3; cursor:default; }
    .step-jump { max-width:190px; padding:5px 8px; font-family:inherit; font-size:12px;
                 border:1px solid #d1d5db; border-radius:3px; background:#fff; color:#374151; cursor:pointer; }
    .step-count { font-size:11px; color:#9ca3af; white-space:nowrap; }
    @media (max-width:620px) { .step-jump { max-width:130px; } .step-count { display:none; } }

    /* ── Agent header ── */
    .agent-header {
      background:#fff; border-radius:6px; padding:24px 28px;
      box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:6px;
      display:flex; align-items:flex-start; gap:20px;
    }
    .agent-avatar-lg {
      width:72px; height:72px; border-radius:50%; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      font-size:24px; font-weight:700; color:#fff; overflow:hidden; background:#1a1a1a;
    }
    .agent-avatar-lg img { width:100%; height:100%; object-fit:cover; }
    .agent-header-info { flex:1; min-width:0; }
    .agent-header-info h1 { font-size:22px; font-weight:700; margin:0 0 3px; }
    .agent-header-info .title { font-size:13px; color:#6b7280; margin-bottom:8px; }
    .agent-meta-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .status-chip { display:inline-block; font-size:10px; font-weight:700;
                   text-transform:uppercase; letter-spacing:.5px; padding:3px 9px; border-radius:10px; color:#fff; }
    .meta-item { font-size:12px; color:#6b7280; display:flex; align-items:center; gap:4px; }
    .agent-header-actions { display:flex; gap:8px; flex-shrink:0; }
    .tasks-summary { display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:700;
                     padding:3px 9px; border-radius:10px; }
    .tasks-summary.has   { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
    .tasks-summary.clear { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }

    /* ── Tabs ── */
    .tab-bar { display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid #e5e7eb; }
    .tab-btn {
      padding:10px 18px; font-size:13px; font-weight:600; cursor:pointer;
      border:none; background:none; color:#6b7280; border-bottom:2px solid transparent;
      margin-bottom:-2px; white-space:nowrap; transition:color .15s, border-color .15s;
      display:inline-flex; align-items:center; gap:6px;
    }
    .tab-btn:hover { color:#111; }
    .tab-btn.active { color:#111; border-bottom-color:#111; }
    .tab-btn .badge { background:#f97316; color:#fff; border-radius:10px;
                      padding:1px 6px; font-size:10px; font-weight:700; }
    .tab-panel { display:none; }
    .tab-panel.active { display:block; }

    /* ── Tabs, on mobile ───────────────────────────────────────────────────
       Seven tabs with icons and badges need roughly 900px. On a phone the
       strip ran off the right edge with Collateral, Advertising and Notes
       simply unreachable — there was no scroll affordance and no hint they
       existed.

       So below 860px the strip is replaced by a button that names the tab you
       are on and drops the full list beneath it. The list is BUILT FROM THE
       BUTTONS at runtime, not written out a second time in PHP, so the icons,
       the badge counts and any tab added later come along on their own. */
    .tab-bar-mobile { display:none; }

    @media (max-width: 860px) {
      .tab-bar { display:none; }
      .tab-bar-mobile { display:block; position:relative; margin-bottom:18px; }

      .tab-menu-btn {
        width:100%; display:flex; align-items:center; gap:10px;
        padding:12px 14px; font-family:inherit; font-size:14px; font-weight:700;
        color:#111; background:#fff; border:1px solid #e5e7eb; border-radius:8px;
        cursor:pointer; text-align:left; box-shadow:0 1px 2px rgba(0,0,0,.05);
      }
      .tab-menu-btn .tab-menu-caret { margin-left:auto; color:#9ca3af; font-size:16px; transition:transform .18s; }
      .tab-menu-btn[aria-expanded="true"] { border-color:#111; border-radius:8px 8px 0 0; }
      .tab-menu-btn[aria-expanded="true"] .tab-menu-caret { transform:rotate(180deg); }

      .tab-menu-panel {
        display:none; position:absolute; top:100%; left:0; right:0; z-index:40;
        background:#fff; border:1px solid #111; border-top:none;
        border-radius:0 0 8px 8px; overflow:hidden;
        box-shadow:0 12px 26px rgba(0,0,0,.16);
      }
      .tab-menu-panel.open { display:block; }

      .tab-menu-item {
        width:100%; display:flex; align-items:center; gap:10px;
        padding:12px 14px; font-family:inherit; font-size:14px; font-weight:600;
        color:#374151; background:none; border:none; border-top:1px solid #f3f4f6;
        cursor:pointer; text-align:left;
      }
      .tab-menu-item:first-child { border-top:none; }
      .tab-menu-item.active { color:#111; font-weight:700; background:#f9fafb; box-shadow:inset 3px 0 0 #111; }
      .tab-menu-item .ti { font-size:16px; color:#9ca3af; flex:none; }
      .tab-menu-item.active .ti { color:#111; }
      /* The badge markup is cloned from the tab button, so it keeps whatever
         inline colour that tab gave it. */
      .tab-menu-item .badge { margin-left:auto; }
    }

    /* ── Cards ── */
    .card { background:#fff; border-radius:6px; padding:22px 26px;
            box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:18px; }
    .card-title { font-size:14px; font-weight:700; color:#111; margin:0 0 16px;
                  padding-bottom:10px; border-bottom:1px solid #f3f4f6; }
    .two-col { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    /* Profile tab */
    .pill { display:inline-block; font-size:11px; font-weight:600; border-radius:10px; padding:2px 9px; white-space:nowrap; }
    .pill.w-approved { background:#f0fdf4; color:#15803d; } .pill.w-pending { background:#fff7ed; color:#c2410c; }
    .pill.w-inactive { background:#f3f4f6; color:#6b7280; }
    .tag-team { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
                background:#111; color:#fff; border-radius:4px; padding:1px 5px; }
    .mini-table td { padding:5px 6px; border-bottom:1px solid #f3f4f6; font-size:13px; vertical-align:middle; }
    .mini-table tr:last-child td { border-bottom:0; }
    .hint { font-size:12px; color:#9ca3af; }
    .face-crop-stage { margin-top:10px; max-width:100%; }
    .face-crop-stage img { display:block; max-width:100%; max-height:340px; }
    @media(max-width:700px) { .two-col { grid-template-columns:1fr; } }

    /* ── Overview info rows ── */
    .info-row { display:flex; align-items:baseline; gap:8px; margin-bottom:10px; font-size:13px; }
    .info-label { font-weight:600; color:#6b7280; min-width:110px; flex-shrink:0; font-size:12px; text-transform:uppercase; letter-spacing:.3px; }
    .info-value { color:#111; }
    .info-value .unset { color:#b6b0a2; font-style:italic; }
    .info-value a { color:#0184BB; text-decoration:none; }
    .info-value a:hover { text-decoration:underline; }

    /* ── Onboarding Checklist ── */
    .ob-list { display:flex; flex-direction:column; gap:0; }

    .ob-item { border-bottom:1px solid #f3f4f6; }
    .ob-item:last-child { border-bottom:none; }

    .ob-header {
      display:flex; align-items:center; gap:8px; padding:10px 0; cursor:default;
    }
    .ob-check-btn {
      background:none; border:none; cursor:pointer; padding:0; font-size:18px;
      line-height:1; flex-shrink:0; color:#d1d5db; transition:color .15s;
    }
    .ob-check-btn:hover { color:#10b981; }
    .ob-check-btn.checked { color:#10b981; }
    .ob-title {
      flex:1; font-size:14px; font-weight:600; color:#111; line-height:1.3;
    }
    .ob-item.ob-done .ob-title { text-decoration:line-through; color:#9ca3af; }
    /* Footer link out to the completed list, which now lives only on Tasks */
    .ob-done-link { margin-top:10px; padding-top:10px; border-top:1px solid #f0f0f0; }
    .ob-done-link button { background:none; border:none; padding:0; cursor:pointer;
                           font-size:12px; font-weight:600; color:#9ca3af; display:inline-flex;
                           align-items:center; gap:5px; font-family:inherit; }
    .ob-done-link button:hover { color:#0184BB; }
    .done-chip { background:#f3f4f6; color:#6b7280; border-radius:10px;
                 padding:1px 8px; font-size:10px; font-weight:700;
                 text-transform:uppercase; letter-spacing:.03em; }
    .ob-sub-count {
      font-size:11px; font-weight:600; padding:2px 7px; border-radius:10px;
      white-space:nowrap; flex-shrink:0;
    }
    .ob-sub-count.has-open { background:#fff7ed; color:#c2410c; }
    .ob-sub-count.all-done { background:#f0fdf4; color:#16a34a; }
    .ob-expand-btn {
      background:none; border:none; cursor:pointer; color:#9ca3af;
      padding:2px 4px; font-size:13px; flex-shrink:0; transition:transform .2s, color .15s;
    }
    .ob-expand-btn:hover { color:#111; }
    .ob-expand-btn.open { transform:rotate(180deg); }

    /* Sub-tasks */
    .ob-subs {
      padding:0 0 10px 28px;
      display:flex; flex-direction:column; gap:0;
    }
    .ob-sub-row {
      display:flex; align-items:flex-start; gap:8px; padding:7px 0;
      border-bottom:1px solid #f9fafb;
    }
    .ob-sub-row:last-child { border-bottom:none; }
    .ob-sub-check-btn {
      background:none; border:none; cursor:pointer; padding:0; font-size:15px;
      line-height:1; flex-shrink:0; margin-top:1px; color:#d1d5db; transition:color .15s;
    }
    .ob-sub-check-btn:hover { color:#10b981; }
    .ob-sub-check-btn.checked { color:#10b981; }
    .ob-sub-content { flex:1; min-width:0; }
    .ob-sub-title { font-size:13px; color:#374151; line-height:1.3; }
    .ob-sub-row.sub-done .ob-sub-title { text-decoration:line-through; color:#9ca3af; }
    .ob-sub-actions { display:flex; align-items:center; gap:6px; margin-top:5px; flex-wrap:wrap; }
    .ob-file-link {
      display:inline-flex; align-items:center; gap:3px; font-size:11px; font-weight:600;
      color:#0184BB; text-decoration:none;
      background:#eff6ff; padding:2px 8px; border-radius:3px; white-space:nowrap;
    }
    .ob-file-link:hover { background:#dbeafe; }
    .ob-edit-url-btn {
      font-size:11px; font-weight:600; color:#9ca3af; background:none; border:1px dashed #d1d5db;
      border-radius:3px; padding:2px 7px; cursor:pointer;
    }
    .ob-edit-url-btn:hover { color:#374151; border-color:#9ca3af; }
    .ob-inline-form { display:flex; gap:6px; align-items:center; margin-top:4px; }
    .ob-inline-input {
      flex:1; padding:5px 8px; font-size:12px; border:1px solid #d1d5db;
      border-radius:3px; font-family:inherit; color:#111; min-width:0;
    }
    .ob-inline-input:focus { outline:none; border-color:#75BDB6; }
    .ob-date-label { font-size:11px; color:#6b7280; }

    /* ── MLS table ── */
    .mls-table { width:100%; border-collapse:collapse; font-size:13px; }
    .mls-table th { text-align:left; font-size:11px; color:#9ca3af; text-transform:uppercase;
                    letter-spacing:.4px; padding:0 10px 8px 0; border-bottom:1px solid #f3f4f6; }
    .mls-table td { padding:8px 10px 8px 0; border-bottom:1px solid #f9fafb; color:#111; vertical-align:middle; }
    .mls-table tr:last-child td { border-bottom:none; }
    .board-chip { display:inline-block; background:#f3f4f6; color:#374151; font-size:10px;
                  font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:2px 7px; border-radius:3px; }
    .mono { font-family:monospace; font-size:12px; color:#6b7280; }

    /* ── Bio ── */
    .bio-content { font-size:14px; line-height:1.7; color:#374151; }
    .bio-content p { margin:0 0 12px; }
    .bio-content p:last-child { margin:0; }

    /* ── Tasks tab ── */
    .task-filters { display:flex; gap:6px; margin-bottom:14px; }
    .tf-btn { padding:4px 12px; font-size:12px; font-weight:600; border:1px solid #e5e7eb;
              border-radius:20px; background:#fff; color:#6b7280; cursor:pointer; }
    .tf-btn:hover, .tf-btn.on { background:#111; color:#fff; border-color:#111; }
    .task-list { display:flex; flex-direction:column; gap:0; }
    .task-row { display:flex; align-items:center; gap:10px; padding:10px 0;
                border-bottom:1px solid #f3f4f6; font-size:13px; }
    .task-row:last-child { border-bottom:none; }
    .task-row.done-row .task-title { text-decoration:line-through; color:#9ca3af; }
    .task-check { flex-shrink:0; }
    .task-check input[type=checkbox] { width:16px; height:16px; cursor:pointer; accent-color:#10b981; }
    .task-title { flex:1; font-weight:500; color:#111; }
    .task-meta  { display:flex; gap:6px; align-items:center; flex-shrink:0; }
    .priority-chip { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:2px 6px; border-radius:3px; }
    .priority-chip.high   { background:#fee2e2; color:#991b1b; }
    .priority-chip.normal { background:#f3f4f6; color:#9ca3af; }
    .cat-chip { font-size:10px; color:#6b7280; background:#f9fafb; border:1px solid #f3f4f6; padding:1px 6px; border-radius:3px; }
    .due-date { font-size:11px; color:#9ca3af; }
    .due-date.overdue { color:#dc2626; font-weight:600; }
    .add-form { background:#f9fafb; border:1px solid #f3f4f6; border-radius:6px; padding:16px; margin-top:14px; }
    .add-form h4 { font-size:13px; font-weight:700; margin:0 0 12px; }
    .form-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
    .form-row label { display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
    .form-row label.grow { flex:1; min-width:140px; }
    /* A tickbox and its wording, not a field label. The rule above stacks its
       children and shouts the text in grey uppercase, which is right for
       "LABEL above input" and wrong here: it put the box on one line and the
       word "Remove" on the next (2026-09-28). */
    .form-row label.admin-check, label.admin-check {
        display:inline-flex; flex-direction:row; align-items:center; gap:7px;
        width:auto; min-width:0; margin:6px 0 10px;
        font-size:13px; font-weight:400; color:#374151;
        text-transform:none; letter-spacing:0; cursor:pointer;
    }
    .form-row label.admin-check input[type="checkbox"],
    label.admin-check input[type="checkbox"] { flex:0 0 auto; width:15px; height:15px; margin:0; }
    .form-input { padding:7px 10px; font-size:13px; border:1px solid #d1d5db; border-radius:4px; font-family:inherit; color:#111; width:100%; }
    .form-input:focus { outline:none; border-color:#75BDB6; box-shadow:0 0 0 2px rgba(117,189,182,.2); }
    textarea.form-input { resize:vertical; min-height:70px; }

    /* ── Collateral ── */
    .coll-section { margin-bottom:20px; padding-bottom:20px; border-bottom:1px solid #f3f4f6; }
    .coll-section:last-child { border-bottom:none; margin-bottom:0; padding-bottom:0; }
    .coll-header { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .coll-title { font-size:14px; font-weight:700; }
    .steps { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
    .step { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600;
            text-transform:uppercase; letter-spacing:.3px; padding:3px 9px; border-radius:10px; }
    .step.done { background:#d1fae5; color:#065f46; }
    .step.todo { background:#f3f4f6; color:#d1d5db; }
    .coll-detail { font-size:12px; color:#6b7280; margin-top:6px; line-height:1.5; }

    /* ── Campaigns ── */
    .campaign-card { border:1px solid #f3f4f6; border-radius:6px; padding:16px; margin-bottom:12px; }
    .campaign-header { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .campaign-platform { font-size:14px; font-weight:700; }
    .campaign-name { font-size:12px; color:#6b7280; }
    .camp-status-chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
                        padding:2px 8px; border-radius:10px; color:#fff; }
    .campaign-details { display:flex; gap:20px; font-size:12px; color:#6b7280; flex-wrap:wrap; margin-bottom:8px; }
    .campaign-details strong { color:#111; }
    /* The Advertising tab's title row. Was inline on the element; moved here so
       the mobile block can restack it (an inline style outranks a media query). */
    .adv-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .camp-actions { display:flex; gap:6px; align-items:center; }
    .camp-actions select { padding:4px 8px; font-size:12px; border:1px solid #d1d5db; border-radius:3px; font-family:inherit; }
    .no-campaigns { text-align:center; padding:32px; color:#9ca3af; font-size:13px; }

    /* ── Notes ── */
    .note-item { padding:14px 0; border-bottom:1px solid #f3f4f6; }
    .note-item:last-child { border-bottom:none; }
    .note-header { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
    .note-date { font-size:11px; color:#9ca3af; }
    .note-author { font-size:11px; color:#6b7280; font-weight:600; }
    .note-content { font-size:13px; color:#374151; line-height:1.6; white-space:pre-wrap; }

    /* ── Listings ── */
    .section-title { font-size:16px; font-weight:700; margin:32px 0 14px; color:#111; }
    .listings-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
    @media(max-width:800px) { .listings-grid { grid-template-columns:repeat(2,1fr); } }
    @media(max-width:500px) { .listings-grid { grid-template-columns:1fr; } }

    .listing-card { background:#fff; border-radius:6px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.08); }
    .listing-photo { width:100%; height:140px; object-fit:cover; display:block; background:#f3f4f6; }
    .listing-body { padding:12px 14px; }
    .listing-status { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#6b7280; margin-bottom:3px; }
    .listing-status.active { color:#10b981; }
    .listing-status.pending { color:#f97316; }
    .listing-addr { font-size:13px; font-weight:700; color:#111; margin-bottom:2px; }
    .listing-city { font-size:11px; color:#9ca3af; margin-bottom:6px; }
    .listing-price { font-size:14px; font-weight:700; color:#111; }
    .listing-link { display:block; margin-top:8px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#f97316; text-decoration:none; }
    .listing-link:hover { text-decoration:underline; }
    .no-listings { text-align:center; padding:32px; color:#9ca3af; font-size:13px; background:#fff; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); }

    /* ── Headshot path dividers ── */
    .ob-path-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
                     color:#9ca3af; padding:8px 0 2px; display:block; }

    /* ── Split-invoice prompt, top of the Collateral tab ──────────────────
       flex-wrap rather than a media query: the row is two children, and letting
       it wrap is the whole mobile behaviour. A rule placed here would lose to
       the @media block at the end of this stylesheet anyway, so anything that
       needed overriding would have to go down there instead. */
    .coll-split-cta { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
                      justify-content:space-between;
                      background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px;
                      padding:11px 14px; margin-bottom:16px; }
    .coll-split-cta strong { display:block; font-size:13px; color:#075985; }
    .coll-split-cta span   { display:block; font-size:12px; color:#0369a1; max-width:64ch; }

    /* ── Collateral order cards ── */
    .coll-orders { margin-top:10px; padding-top:10px; border-top:1px dashed #e5e7eb; }
    .coll-orders-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
                         color:#9ca3af; margin-bottom:8px; display:flex; align-items:center; gap:8px; }
    .coll-orders-title::after { content:''; flex:1; height:1px; background:#f3f4f6; }
    .order-card { border:1px solid #e5e7eb; border-radius:6px; padding:12px 14px; margin-bottom:8px; background:#fff; }
    .order-card-header { display:flex; align-items:flex-start; gap:8px; margin-bottom:6px; }
    .order-label { font-size:13px; font-weight:700; color:#111; flex:1; min-width:0; }
    .order-num { font-size:11px; color:#6b7280; }
    .order-status-chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
                         padding:2px 8px; border-radius:10px; border:1px solid; white-space:nowrap; flex-shrink:0; }
    .order-details { display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:#6b7280; margin-bottom:6px; }
    .order-details strong { color:#111; font-weight:600; }
    .order-link { display:inline-flex; align-items:center; gap:3px; font-size:11px; font-weight:600;
                  color:#0184BB; text-decoration:none; background:#eff6ff; padding:2px 8px; border-radius:3px; margin-right:4px; }
    .order-link:hover { background:#dbeafe; }
    .order-notes { font-size:12px; color:#6b7280; margin-top:4px; white-space:pre-wrap; }
    .order-actions { display:flex; gap:6px; margin-top:8px; }
    .add-order-btn { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700;
                     color:#f97316; background:none; border:1px dashed #fed7aa; border-radius:4px;
                     padding:4px 10px; cursor:pointer; text-transform:uppercase; letter-spacing:.3px;
                     transition:background .15s, border-color .15s; }
    .add-order-btn:hover { background:#fff7ed; border-color:#f97316; }

    /* ── Section edit buttons ── */
    .section-edit-btn {
      margin-left:auto; font-size:11px; font-weight:700; color:#6b7280; letter-spacing:.3px;
      background:none; border:1px solid #e5e7eb; border-radius:4px; padding:2px 8px;
      cursor:pointer; text-transform:uppercase; transition:border-color .15s, color .15s;
    }
    .section-edit-btn:hover { border-color:#9ca3af; color:#111; }
    .inline-edit-form {
      background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px;
      padding:14px 16px; margin-top:10px;
    }
    .inline-edit-form .form-row { margin-bottom:8px; }
    .chk-row { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:10px; }
    .chk-row label { display:flex; align-items:center; gap:5px; font-size:13px; cursor:pointer; }
    .inline-edit-form .btn-row { display:flex; gap:8px; margin-top:4px; }

    /* ── Assets tab ── */
    .asset-link-list { display:flex; flex-direction:column; }
    .asset-link-row {
      display:flex; align-items:center; gap:8px; font-size:13px;
      padding:7px 0; border-bottom:1px solid #f3f4f6;
    }
    .asset-link-row:last-child { border-bottom:none; }
    .asset-link-row > i { font-size:15px; color:#9ca3af; flex-shrink:0; }
    .asset-link-label { font-weight:600; color:#374151; min-width:130px; }
    .asset-link-a { display:inline-flex; align-items:center; gap:4px; font-size:12px; color:#0184BB; text-decoration:none; }
    .asset-link-a:hover { text-decoration:underline; }
    .asset-none  { font-size:12px; color:#d1d5db; }
    .asset-empty { font-size:13px; color:#9ca3af; margin:0; }
    .asset-notes { font-size:13px; color:#374151; line-height:1.6; white-space:pre-wrap; }

    .subsec { margin-top:14px; padding-top:12px; border-top:1px solid #f3f4f6; }
    .subsec.edit { margin:12px 0; padding:12px 14px; border:1px solid #e5e7eb;
                   border-radius:6px; background:#fff; }
    .subsec-title { font-size:10px; font-weight:700; text-transform:uppercase;
                    letter-spacing:.5px; color:#9ca3af; margin-bottom:8px; }

    .kv-grid { display:grid; grid-template-columns:auto 1fr; gap:6px 16px; font-size:13px; }
    .kv-k { color:#6b7280; }
    .kv-v { color:#111; font-weight:500; }

    /* Left-aligned label + field rows (replaces centered form-row layout) */
    .fld { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .fld-label { flex-shrink:0; width:140px; font-size:12px; font-weight:600;
                 color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
    .fld .form-input { flex:1; }
    .ps-chk { display:flex; align-items:center; gap:6px; font-size:13px;
              cursor:pointer; margin-bottom:10px; }
    .ps-dim { opacity:.4; pointer-events:none; }

    .addlink-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap;
                   margin-top:12px; padding-top:12px; border-top:1px solid #f3f4f6; }

    /* ── Advertising: copyable link rows ── */
    .link-row { display:flex; align-items:center; gap:8px; padding:6px 0;
                border-bottom:1px solid #f9fafb; font-size:12px; }
    .link-row:last-of-type { border-bottom:none; }
    .link-row-label { flex:1; min-width:0; font-weight:600; color:#374151;
                      display:inline-flex; align-items:center; gap:4px;
                      overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ad-files { margin:10px 0; padding:12px 14px; background:#eceef1;
                border:1px solid #dfe3e8; border-radius:6px; }
    .ad-files-title { font-size:10px; font-weight:700; text-transform:uppercase;
                      letter-spacing:.5px; color:#9ca3af; margin-bottom:4px; }
    .ad-sublabel { font-weight:400; color:#6b7280; margin-left:6px; }

    /* ── Financials tab ── */
    .broker     { color:#7c3aed; }
    .mh         { color:#0184BB; }
    .unassigned { color:#b45309; }

    .fin-grand { display:flex; gap:14px; flex-wrap:wrap; margin-top:4px; }
    .fin-grand-box { flex:1; min-width:180px; background:#f9fafb; border:1px solid #e5e7eb;
                     border-radius:6px; padding:14px 16px; }
    .fin-grand-label { font-size:11px; font-weight:600; text-transform:uppercase;
                       letter-spacing:.4px; color:#6b7280; margin-bottom:6px; }
    .fin-grand-num { font-size:22px; font-weight:700; }
    .fin-note { font-size:12px; color:#b45309; background:#fff7ed; border:1px solid #fed7aa;
                border-radius:6px; padding:8px 10px; margin:12px 0 0; line-height:1.5; }

    .fin-month-head { display:flex; align-items:baseline; justify-content:space-between;
                      gap:12px; flex-wrap:wrap; margin-bottom:12px;
                      padding-bottom:10px; border-bottom:2px solid #f3f4f6; }
    .fin-month-name { font-size:17px; font-weight:700; }
    .fin-month-totals { display:flex; gap:16px; font-size:12px; color:#6b7280; }
    .fin-month-totals strong { font-size:13px; }

    .fin-table { width:100%; border-collapse:collapse; }
    .fin-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
                    color:#9ca3af; text-align:left; padding:6px 10px; border-bottom:1px solid #e5e7eb; }
    .fin-table td { font-size:13px; padding:9px 10px; border-bottom:1px solid #f3f4f6;
                    vertical-align:top; }
    .fin-table th.num, .fin-table td.num { text-align:right; white-space:nowrap; }
    .fin-table tbody tr:last-child td { border-bottom:none; }
    .fin-table tr.fin-unassigned td { background:#fffbeb; }
    .fin-item-name { font-weight:600; color:#111; }
    .fin-item-link { color:#111; text-decoration:none; border-bottom:1px solid #d1d5db; }
    .fin-item-link:hover { color:#0184BB; border-bottom-color:#0184BB; }
    /* Brief highlight on the item you arrived at from Financials */
    .fin-target { box-shadow:0 0 0 2px #F49808; transition:box-shadow 1.2s ease; }
    .fin-item-sub  { font-size:11px; color:#9ca3af; margin-top:1px; }
    .fin-kind { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.3px;
                padding:2px 7px; border-radius:9px; white-space:nowrap; }
    .fin-kind.coll { background:#ede9fe; color:#5b21b6; }
    .fin-kind.adv  { background:#e0f2fe; color:#075985; }
    .fin-flag { font-size:11px; color:#b45309; font-style:italic; }
    .fin-billed { color:#0184BB; }
    /* A Financials line produced by a recurring monthly campaign. */
    .fin-recurring { color:#1d8348; }
    /* Chip on the campaign card: how it bills and over how many months. */
    .camp-monthly-chip {
      display:inline-flex; align-items:center; gap:4px;
      background:#d5f5e3; color:#1d8348;
      padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600;
    }
    .fin-orphan { color:#b45309; }
    /* A per-day line: rate × days, and where the day count came from. */
    .fin-units { color:#6b7280; }
    .fin-units b { font-weight:600; color:#374151; }
    /* A month with no figure entered. Amber, not red — nothing is broken, a
       decision has not been made yet. But it must never look like $0. */
    .fin-needs-qty { color:#b45309; font-weight:600; }

    /* ── The month grid on a recurring placement ─────────────────────────── */
    .mo-head { margin-top:10px; padding-top:8px; border-top:1px solid #f3f4f6;
               display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .mo-open-chip { display:inline-flex; align-items:center; gap:4px;
                    background:#fef3c7; color:#92400e;
                    padding:1px 8px; border-radius:10px; font-size:11px; font-weight:700; }
    .mo-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:6px; }
    .mo-table th { text-align:left; font-size:10px; text-transform:uppercase;
                   letter-spacing:.4px; color:#9ca3af; padding:4px 8px 4px 0; font-weight:700; }
    .mo-table td { padding:4px 8px 4px 0; border-top:1px solid #f3f4f6; vertical-align:middle; }
    .mo-table td.num { text-align:right; padding-right:0; white-space:nowrap; }
    .mo-m { white-space:nowrap; font-weight:600; color:#374151; }
    /* Row for a month awaiting a figure — tinted so a column of them reads as
       a to-do list at a glance rather than needing to be read line by line. */
    .mo-table tr.mo-unset td { background:#fffbeb; }
    .mo-none { color:#9ca3af; font-style:italic; }
    .mo-in       { font-size:12px; padding:4px 6px; width:100px; }
    .mo-in-w     { width:130px; }
    .mo-in-note  { width:100%; min-width:140px; }
    .mo-flag { display:inline-block; font-size:10px; font-weight:700; border-radius:8px;
               padding:1px 6px; }
    .mo-flag.unset { background:#fef3c7; color:#92400e; }
    .mo-flag.zero  { background:#f3f4f6; color:#6b7280; }
    .mo-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:10px; }
    .mo-hint { font-size:11px; color:#6b7280; max-width:60ch; }
    .unit-hint { font-size:11px; color:#6b7280; background:#f9fafb; border-left:2px solid #d1d5db;
                 padding:6px 10px; margin:0 0 10px; max-width:70ch; }
    .fin-invoice { color:#0184BB; text-decoration:none; margin-right:10px; font-size:11px; }
    .fin-invoice:hover { text-decoration:underline; }
    .fin-table tfoot td { border-top:2px solid #e5e7eb; padding-top:10px; font-size:13px; }
    .fin-foot-label { text-align:right; font-size:11px; font-weight:700; text-transform:uppercase;
                      letter-spacing:.4px; color:#9ca3af; }

    .fin-month-summary { display:flex; gap:24px; flex-wrap:wrap; margin-top:14px;
                         padding-top:12px; border-top:1px solid #f3f4f6; font-size:14px; }
    .fin-month-summary strong { font-weight:600; color:#374151; }
    .ad-count { display:inline-block; background:#d7dbe0; color:#4b5563;
                border-radius:9px; padding:0 6px; margin-left:4px; font-size:10px; }
    .copy-btn.copied { background:#d1fae5; border-color:#6ee7b7; color:#065f46; }

    /* ── Collateral: who pays ── */
    .pay-block { background:#fff; border:1px solid #e5e7eb; border-radius:6px;
                 padding:10px 12px; margin:10px 0; }
    .pay-title { font-size:10px; font-weight:700; text-transform:uppercase;
                 letter-spacing:.5px; color:#9ca3af; margin-bottom:7px; }
    .pay-opts { display:flex; gap:16px; flex-wrap:wrap; }
    .pay-opt  { display:flex; align-items:center; gap:5px; font-size:13px; cursor:pointer; }
    .pay-split { display:flex; gap:12px; align-items:flex-end; margin-top:10px;
                 padding-top:10px; border-top:1px solid #f3f4f6; flex-wrap:wrap; }
    .pay-hint { font-size:11px; color:#9ca3af; padding-bottom:8px; }
    .pay-hint.bad { color:#b45309; }

    .pay-summary { display:flex; align-items:center; gap:8px; margin:6px 0 2px; flex-wrap:wrap; }
    .pay-chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
                color:#fff; padding:2px 8px; border-radius:10px; }
    .pay-amounts { font-size:12px; color:#6b7280; }
    .pay-amounts strong { color:#111; }
    .pay-warn { color:#b45309; font-size:11px; margin-left:4px; }

    /* Each ad/creative inside a placement */
    .ad-item { padding:6px 0; border-bottom:1px solid #f1f2f4; }
    .ad-item:last-of-type { border-bottom:none; }
    /* Divider before a creative's own landing-page buttons, on the same row.
       The URL itself is never printed — Open and Copy are the whole interface. */
    .ad-target-sep { flex-shrink:0; color:#9ca3af; font-size:10px; font-weight:700;
                     text-transform:uppercase; letter-spacing:.4px;
                     padding-left:10px; margin-left:2px; border-left:1px solid #e5e7eb; }
    /* A checkbox that belongs on the same line as the buttons beside it. */
    .chk-inline { display:inline-flex; align-items:center; gap:6px; font-size:13px;
                  color:#374151; cursor:pointer; margin-left:4px; white-space:nowrap; }
    .chk-inline input { margin:0; }

    /* Repeatable ad rows in the Add Placement form */
    .ads-builder { background:#fff; border:1px solid #e5e7eb; border-radius:6px;
                   padding:12px 14px; margin-top:12px; }
    .ad-row { display:flex; align-items:center; gap:6px; margin-bottom:6px; }
    .ad-row .form-input { flex:1; min-width:0; }

    /* Quill */
    #bioEditor { background:#fff; min-height:180px; font-size:14px; }
    #bioEditor .ql-editor { min-height:180px; line-height:1.7; }
    .ql-toolbar.ql-snow, .ql-container.ql-snow { border-color:#e5e7eb; }
    .ql-toolbar.ql-snow { border-radius:6px 6px 0 0; background:#fff; }
    .ql-container.ql-snow { border-radius:0 0 6px 6px; }
    .bio-content.rich ul, .bio-content.rich ol { margin:0 0 12px; padding-left:22px; }
    .bio-content.rich h3 { font-size:15px; font-weight:700; margin:0 0 8px; }
    .bio-content.rich h4 { font-size:14px; font-weight:700; margin:0 0 6px; }
    .bio-content.rich a { color:#0184BB; }

    /* ── Saved flash ── */
    .saved-flash { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700;
                   color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:4px; }

    /* ── Forwarded badge ── */
    .fwd-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700;
                 background:#d1fae5; color:#065f46; padding:1px 7px; border-radius:4px; vertical-align:middle; }
    .fwd-toggle { appearance:none; -webkit-appearance:none; width:13px; height:13px;
                  border:1.5px solid #d1d5db; border-radius:3px; cursor:pointer; flex-shrink:0;
                  position:relative; background:#fff; transition:background .15s, border-color .15s; }
    .fwd-toggle:checked { background:#10b981; border-color:#10b981; }
    .fwd-toggle:checked::after { content:''; position:absolute; left:2px; top:-1px;
      width:7px; height:10px; border:2px solid #fff; border-top:none; border-left:none;
      transform:rotate(45deg); }
    .fwd-form { display:inline-flex; align-items:center; gap:4px; margin-left:6px; }
    .fwd-label { font-size:11px; color:#6b7280; cursor:pointer; }

    /* ── Bio tabs ── */
    .bio-tabs { display:flex; gap:0; border-bottom:1px solid #f3f4f6; margin-bottom:14px; }
    .bio-tab-btn { padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; border:none;
                   background:none; color:#6b7280; border-bottom:2px solid transparent; margin-bottom:-1px;
                   transition:color .15s, border-color .15s; }
    .bio-tab-btn.active { color:#111; border-bottom-color:#111; }
    .bio-panel { display:none; }
    .bio-panel.active { display:block; }

    /* ── URL Builder ── */
    .url-builder { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:14px 16px; margin:12px 0 0; }
    .url-builder-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
                         color:#6b7280; margin-bottom:10px; display:flex; align-items:center; gap:5px; }
    .url-preview { font-family:monospace; font-size:11px; color:#374151; background:#fff;
                   border:1px solid #e5e7eb; border-radius:4px; padding:6px 10px;
                   word-break:break-all; flex:1; min-height:30px; }

    /* ── Campaign extra fields ── */
    .camp-url-link { display:inline-flex; align-items:center; gap:3px; font-size:11px; font-weight:600;
                     color:#0184BB; text-decoration:none; background:#eff6ff; padding:2px 8px; border-radius:3px; }
    .camp-url-link:hover { background:#dbeafe; }
    .sent-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700;
                  text-transform:uppercase; letter-spacing:.4px; padding:2px 8px; border-radius:10px; }
    .sent-badge.yes { background:#d1fae5; color:#065f46; }
    .sent-badge.no  { background:#f3f4f6; color:#9ca3af; }
    /* ══ Mobile: Collateral and Advertising cards ═══════════════════════════
       Everything in this block is inside a max-width query, so the desktop
       layout of both tabs is exactly what it was.

       The common problem on a phone was the same in both places: rows built as
       `display:flex` with several children and no wrap rules. Each row broke
       wherever it ran out of room, so a card's badges, links and buttons all
       started at a different left edge and the card read as a pile rather than
       as a list. The fixes are: give the wrapping rows an explicit direction,
       pin the status chip to a place of its own, and shrink the buttons to
       something proportionate to a 390px screen instead of a 1400px one. */
    @media (max-width: 700px) {
      .card { padding:16px 14px; }

      /* ── Collateral ───────────────────────────────────────────────────── */

      /* The status chip ("ORDERED") sat after a flex:1 title block, which put
         it hard against the right edge on a line of its own whenever the title
         wrapped. Stacking it under the title, left-aligned with everything
         else, is what makes the card read straight down one edge. */
      .order-card { padding:12px; }
      .order-card-header { flex-direction:column; align-items:flex-start; gap:6px; }
      .order-status-chip { order:-1; }          /* chip first, then title */
      .order-label { font-size:14px; }

      /* Qty / Cost / Ordered / Delivered — a 16px flex gap gave four items
         three different left edges once they wrapped. Two fixed columns keep
         the labels aligned and fit a phone exactly. */
      .order-details {
        display:grid; grid-template-columns:repeat(2, minmax(0,1fr));
        gap:5px 12px; margin-bottom:8px;
      }

      /* The link pills relied on margin-right and inline wrapping, which left
         ragged gaps. Flex-wrap gives them one gap in both directions. */
      .order-card > div:not([class]) { display:flex; flex-wrap:wrap; gap:6px; }
      .order-link { margin-right:0; padding:5px 10px; font-size:11px; }

      .order-actions { flex-wrap:wrap; gap:8px; }
      .pay-summary  { gap:6px; }
      .pay-amounts  { font-size:11.5px; }

      .coll-header { flex-wrap:wrap; gap:8px; }
      .section-edit-btn { margin-left:0; }      /* auto-margin pushed it off the row */
      .steps { gap:5px; }

      /* ── Advertising ──────────────────────────────────────────────────── */

      .campaign-card { padding:12px; }
      .campaign-header { flex-direction:column; align-items:flex-start; gap:6px; }
      .camp-status-chip { order:-1; }
      .campaign-platform { font-size:15px; }

      /* Start / End / Rate were a 20px flex gap; same wrapping problem, and the
         monthly chip has to keep a full line to itself because its text is a
         sentence ("Every Saturday · 1 month · $250.00"). */
      .campaign-details { gap:5px 14px; margin-bottom:10px; }
      .camp-monthly-chip { width:100%; justify-content:flex-start; font-size:11.5px; }

      /* "ADD PLACEMENT" was a full-size .btn — 18px of side padding and 13px
         uppercase text — which on a phone came out nearly half the card wide
         and forced the title beside it to wrap onto two lines. Header stacks,
         button goes back to a proportionate size. */
      .adv-head { flex-direction:column; align-items:flex-start; gap:10px; }
      .adv-head .btn { padding:7px 12px; font-size:12px; }

      /* Target URL and each creative were one flex row of label + Open + Copy
         + Target + Open + Copy + Edit + ✕ — eight children on a 390px screen,
         which wrapped at a different point on every row.

         Giving the label `flex:0 0 100%` makes it take a whole line by itself,
         so every button after it falls to the next line and they all wrap
         against the same left edge. */
      .link-row { flex-wrap:wrap; gap:6px; padding:8px 0; }
      .link-row-label {
        flex:0 0 100%; min-width:0; font-size:12.5px;
        white-space:normal; overflow:visible;
      }
      .link-row .btn-xs { padding:5px 9px; font-size:11px; }
      .ad-target-sep { padding-left:0; margin-left:0; border-left:none; }

      .ad-files { padding:10px; }
      .ad-item  { padding:8px 0; }

      /* Status select, Mark Sent, Edit, Delete. Wrapping is fine — they just
         need to wrap to a consistent left edge and be the smaller size. */
      .camp-actions { flex-wrap:wrap; gap:8px; }
      .camp-actions .btn-xs { padding:6px 10px; font-size:11.5px; }
      .camp-actions select  { flex:1; min-width:130px; }

      .mo-table { font-size:11.5px; }
      .mo-actions { gap:8px; }
    }

  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
<div class="wrap">

  <div class="crumb-row">
    <div class="breadcrumb">
      <a href="index.php">Agent Roster</a>
      <i class="ti ti-chevron-right" style="font-size:11px;"></i>
      <span><?= val($agent,'agent_name') ?></span>
    </div>

    <?php if ($nav_pos !== null && count($nav_agents) > 1): ?>
    <!-- Step through the roster without going back to it. Same order as the
         roster's Active tab; the tab you are on comes with you. -->
    <div class="agent-step">
      <?php if ($nav_prev): ?>
        <a class="step-btn" data-step="prev" title="<?= e_attr($nav_prev['name']) ?>"
           href="agent.php?id=<?= (int)$nav_prev['id'] ?>&amp;tab=<?= e_attr($active_tab) ?>"
           aria-label="Previous agent: <?= e_attr($nav_prev['name']) ?>"><i class="ti ti-chevron-left"></i></a>
      <?php else: ?>
        <span class="step-btn off" aria-hidden="true"><i class="ti ti-chevron-left"></i></span>
      <?php endif; ?>

      <select class="step-jump" id="agentJump" data-tab="<?= e_attr($active_tab) ?>" aria-label="Jump to another agent">
        <?php foreach ($nav_agents as $n): ?>
          <option value="<?= (int)$n['id'] ?>"<?= (int)$n['id'] === $id ? ' selected' : '' ?>><?= e_attr($n['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="step-count"><?= $nav_pos + 1 ?> of <?= count($nav_agents) ?></span>

      <?php if ($nav_next): ?>
        <a class="step-btn" data-step="next" title="<?= e_attr($nav_next['name']) ?>"
           href="agent.php?id=<?= (int)$nav_next['id'] ?>&amp;tab=<?= e_attr($active_tab) ?>"
           aria-label="Next agent: <?= e_attr($nav_next['name']) ?>"><i class="ti ti-chevron-right"></i></a>
      <?php else: ?>
        <span class="step-btn off" aria-hidden="true"><i class="ti ti-chevron-right"></i></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Agent header -->
  <div class="agent-header">
    <div class="agent-avatar-lg" style="background:<?= $status==='pending'?'#f97316':($status==='archived'?'#9ca3af':'#1a1a1a') ?>;">
      <?php if ($agent['headshot_url']): ?>
        <img src="<?= val($agent,'headshot_url') ?>" alt="">
      <?php else: ?>
        <?= htmlspecialchars(initials($agent['agent_name'], $agent['initials'] ?? null)) ?>
      <?php endif; ?>
    </div>
    <div class="agent-header-info">
      <h1><?= val($agent,'agent_name') ?></h1>
      <?php if ($agent['agent_title']): ?><div class="title"><?= val($agent,'agent_title') ?></div><?php endif; ?>
      <div class="agent-meta-row">
        <span class="status-chip" style="background:<?= $chip_color ?>;"><?= $chip_text ?></span>
        <?php if ($agent['start_date']): ?>
          <span class="meta-item"><i class="ti ti-calendar"></i> Started <?= date('M j, Y', strtotime($agent['start_date'])) ?></span>
        <?php endif; ?>
        <?php if ($agent['mh_email']): ?>
          <span class="meta-item"><i class="ti ti-mail"></i> <a href="mailto:<?= val($agent,'mh_email') ?>" style="color:inherit;"><?= val($agent,'mh_email') ?></a></span>
        <?php endif; ?>
        <?php if ($open_tasks > 0): ?>
          <span class="tasks-summary has"><i class="ti ti-list-check"></i> <?= $open_tasks ?> open task<?= $open_tasks!==1?'s':'' ?></span>
        <?php else: ?>
          <span class="tasks-summary clear"><i class="ti ti-check"></i> All tasks done</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="agent-header-actions">
      <?php if (isset($_GET['saved'])): ?>
        <span class="saved-flash"><i class="ti ti-check"></i> Saved</span>
      <?php endif; ?>
      <?php if (!empty($_GET['note'])): ?>
        <span class="saved-flash" style="background:#fbf7ec;color:#7a5f30;max-width:640px;white-space:normal;text-align:left;">
          <i class="ti ti-color-swatch"></i> <?= htmlspecialchars($_GET['note']) ?>
        </span>
      <?php endif; ?>
      <?php if (!empty($_GET['err'])): ?>
        <span class="saved-flash" style="background:#fee2e2;color:#991b1b;">
          <i class="ti ti-alert-triangle"></i> <?= htmlspecialchars($_GET['err']) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tab bar -->
  <div class="tab-bar">
    <button class="tab-btn <?= $active_tab==='profile'?'active':'' ?>"   data-tab="profile"><i class="ti ti-user"></i> Profile</button>
    <?php if (!$is_staff): ?>
    <button class="tab-btn <?= $active_tab==='assets'?'active':'' ?>"    data-tab="assets"><i class="ti ti-folder"></i> Assets</button>
    <button class="tab-btn <?= $active_tab==='financials'?'active':'' ?>" data-tab="financials">
      <i class="ti ti-receipt-2"></i> Financials
      <?php if ($fin_grand['unassigned'] > 0): ?><span class="badge" style="background:#F49808;">!</span><?php endif; ?>
    </button>
    <button class="tab-btn <?= $active_tab==='tasks'?'active':'' ?>"      data-tab="tasks">
      <i class="ti ti-list-check"></i> Tasks
      <?php if ($open_tasks > 0): ?><span class="badge"><?= $open_tasks ?></span><?php endif; ?>
    </button>
    <button class="tab-btn <?= $active_tab==='collateral'?'active':'' ?>" data-tab="collateral"><i class="ti ti-layout-cards"></i> Collateral</button>
    <button class="tab-btn <?= $active_tab==='advertising'?'active':'' ?>"  data-tab="advertising">
      <i class="ti ti-speakerphone"></i> Advertising
      <?php if ($campaigns): ?><span class="badge" style="background:#6b7280;"><?= count($campaigns) ?></span><?php endif; ?>
    </button>
    <button class="tab-btn <?= $active_tab==='notes'?'active':'' ?>"      data-tab="notes">
      <i class="ti ti-notes"></i> Notes
      <?php if ($notes): ?><span class="badge" style="background:#6b7280;"><?= count($notes) ?></span><?php endif; ?>
    </button>
    <?php else: ?>
    <span class="hint" style="align-self:center;margin-left:10px;">Staff: not an agent. Shown on the Leadership page only.</span>
    <?php endif; ?>
  </div>

  <?php // Mobile tab menu. Empty on purpose — the list is cloned from the
        // .tab-btn elements above by the script at the bottom of the page, so
        // there is exactly one definition of what the tabs are. Hidden above
        // 860px, where the strip fits. ?>
  <div class="tab-bar-mobile">
    <button type="button" class="tab-menu-btn" id="tabMenuBtn"
            aria-expanded="false" aria-controls="tabMenuPanel">
      <i class="ti ti-list" aria-hidden="true"></i>
      <span id="tabMenuCurrent">Overview</span>
      <i class="ti ti-chevron-down tab-menu-caret" aria-hidden="true"></i>
    </button>
    <div class="tab-menu-panel" id="tabMenuPanel"></div>
  </div>

  <!-- ══════════════════════════════════════════════ PROFILE ══ -->
  <!-- Everything the website shows, edited here and nowhere else: the site
       admin is a read-only view of what the feed sent it (Nikki, 2026-09-23). -->
  <div class="tab-panel <?= $active_tab==='profile'?'active':'' ?>" id="tab-profile">
    <div class="two-col">
      <div>
        <div class="card">
          <div class="card-title" style="display:flex;align-items:center;">
            Contact
            <button class="section-edit-btn" type="button" onclick="toggleInline('edit-contact')">Edit</button>
          </div>
          <div id="edit-contact" style="display:none;" class="inline-edit-form">
            <form method="POST">
              <input type="hidden" name="_action" value="update_intake_fields">
              <input type="hidden" name="redirect_tab" value="overview">
              <div class="form-row">
                <label class="grow">Name <input type="text" name="agent_name" class="form-input" value="<?= val($agent,'agent_name') ?>"></label>
                <label style="max-width:110px;">Initials
                  <input type="text" name="initials" class="form-input" maxlength="3"
                         style="text-transform:uppercase;"
                         placeholder="<?= htmlspecialchars(initials((string)($agent['agent_name'] ?? ''))) ?>"
                         value="<?= htmlspecialchars($agent['initials'] ?? '') ?>">
                </label>
                <label class="grow">Title
                  <textarea name="agent_title" class="form-input" rows="2" style="resize:vertical;"><?= val($agent,'agent_title') ?></textarea>
                  <span class="hint" style="text-transform:none;letter-spacing:0;font-weight:400;">Press Enter where the website's broker card should break the line. Everywhere else it reads as one line.</span>
                </label>
              </div>
              <div class="form-row">
                <label class="grow">Cell Phone <input type="text" name="cell_phone" class="form-input" value="<?= val($agent,'cell_phone') ?>"></label>
              </div>
              <div class="form-row">
                <label class="grow">MH Email <input type="email" name="mh_email" class="form-input" value="<?= val($agent,'mh_email') ?>"></label>
                <label class="grow">Alt Email <input type="email" name="alt_email" class="form-input" value="<?= val($agent,'alt_email') ?>"></label>
                <label style="flex-shrink:0;justify-content:flex-end;">
                  <span style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:4px;">Forwarded?</span>
                  <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;padding:8px 0;">
                    <input type="hidden" name="email_forwarded" value="0">
                    <input type="checkbox" name="email_forwarded" value="1" <?= $agent['email_forwarded']?'checked':'' ?>> Yes
                  </label>
                </label>
              </div>
              <div class="form-row">
                <label class="grow">Headshot URL <input type="url" name="headshot_url" class="form-input" value="<?= val($agent,'headshot_url') ?>"></label>
              </div>
              <div class="btn-row">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-contact')">Cancel</button>
              </div>
            </form>
          </div>
          <!-- Read view. Every field shows, blank ones included, so the card
               answers "what do we have for this person" without opening Edit
               (Nikki, 2026-09-29). Title keeps its line break here, which is
               the only place to see where the website's card will break. -->
          <div class="info-row">
            <span class="info-label">Name</span>
            <span class="info-value"><?= $agent['agent_name'] !== '' ? val($agent,'agent_name') : '<span class="unset">Not set</span>' ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Initials</span>
            <span class="info-value">
              <?php if (trim((string)($agent['initials'] ?? '')) !== ''): ?>
                <?= htmlspecialchars($agent['initials']) ?>
              <?php else: ?>
                <span class="unset"><?= htmlspecialchars(initials((string)($agent['agent_name'] ?? ''))) ?> (from the name)</span>
              <?php endif; ?>
            </span>
          </div>
          <div class="info-row">
            <span class="info-label">Title</span>
            <span class="info-value"><?= trim((string)$agent['agent_title']) !== ''
                ? nl2br(val($agent,'agent_title'), false)
                : '<span class="unset">Not set</span>' ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Phone</span>
            <span class="info-value"><?= $agent['cell_phone'] !== '' ? val($agent,'cell_phone') : '<span class="unset">Not set</span>' ?></span>
          </div>
          <?php if (!$agent['mh_email']): ?>
            <div class="info-row"><span class="info-label">MH Email</span><span class="info-value"><span class="unset">Not set</span></span></div>
          <?php endif; ?>
          <?php if ($agent['mh_email']): ?>
            <div class="info-row">
              <span class="info-label">MH Email</span>
              <span class="info-value">
                <a href="mailto:<?= val($agent,'mh_email') ?>"><?= val($agent,'mh_email') ?></a>
                <?php if ($agent['email_forwarded']): ?>
                  <span class="fwd-badge"><i class="ti ti-arrows-right" style="font-size:9px;"></i> Forwarding</span>
                <?php endif; ?>
                <form method="POST" class="fwd-form">
                  <input type="hidden" name="_action" value="toggle_email_forwarded">
                  <input type="hidden" name="email_forwarded" value="<?= $agent['email_forwarded'] ? 0 : 1 ?>">
                  <input type="checkbox" class="fwd-toggle" <?= $agent['email_forwarded']?'checked':'' ?>
                         onchange="this.form.submit()" title="Toggle email forwarding">
                  <label class="fwd-label" onclick="this.previousElementSibling.click()">Forwarded</label>
                </form>
              </span>
            </div>
          <?php endif; ?>
          <div class="info-row">
            <span class="info-label">Alt Email</span>
            <span class="info-value"><?= trim((string)($agent['alt_email'] ?? '')) !== ''
                ? '<a href="mailto:' . val($agent,'alt_email') . '">' . val($agent,'alt_email') . '</a>'
                : '<span class="unset">Not set</span>' ?></span>
          </div>
        </div>

        <?php if (!$is_staff): ?>
        <div class="card">
          <div class="card-title" style="display:flex;align-items:center;">
            Social / Digital
            <button class="section-edit-btn" type="button" onclick="toggleInline('edit-social')">Edit</button>
          </div>
          <div id="edit-social" style="display:none;" class="inline-edit-form">
            <form method="POST">
              <input type="hidden" name="_action" value="update_intake_fields">
              <input type="hidden" name="redirect_tab" value="overview">
              <div class="form-row">
                <label class="grow">Website <input type="url" name="website_url" class="form-input" value="<?= val($agent,'website_url') ?>" placeholder="https://…"></label>
              </div>
              <?php foreach (['social_instagram'=>'Instagram','social_facebook'=>'Facebook','social_linkedin'=>'LinkedIn','social_tiktok'=>'TikTok'] as $col=>$lbl): ?>
                <div class="form-row">
                  <label class="grow">
                    <?= $lbl ?>
                    <div style="display:flex;align-items:center;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;margin-top:4px;">
                      <span style="padding:7px 10px;background:#f9fafb;color:#6b7280;font-size:13px;border-right:1px solid #e5e7eb;font-weight:600;line-height:1.4;flex-shrink:0;">@</span>
                      <input type="text" name="<?= $col ?>" value="<?= htmlspecialchars(ltrim($agent[$col] ?? '', '@')) ?>"
                             placeholder="handle" style="border:none;padding:7px 10px;flex:1;font-size:13px;outline:none;color:#111827;background:transparent;">
                    </div>
                  </label>
                </div>
              <?php endforeach; ?>
              <div class="form-row">
                <label class="grow">Other URL <input type="url" name="social_other" class="form-input" value="<?= val($agent,'social_other') ?>" placeholder="https://…"></label>
              </div>
              <div class="btn-row">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-social')">Cancel</button>
              </div>
            </form>
          </div>
          <?php
          // Build full profile URL from stored handle
          $social_platform_urls = [
              'social_instagram' => 'https://instagram.com/',
              'social_facebook'  => 'https://facebook.com/',
              'social_linkedin'  => 'https://linkedin.com/in/',
              'social_tiktok'    => 'https://tiktok.com/@',
          ];
          if ($agent['website_url']): ?>
            <div class="info-row"><span class="info-label">Website</span><span class="info-value"><a href="<?= val($agent,'website_url') ?>" target="_blank"><?= val($agent,'website_url') ?></a></span></div>
          <?php endif; ?>
          <?php foreach ($social_platform_urls as $col => $base_url):
              $handle = ltrim($agent[$col] ?? '', '@');
              if (!$handle) continue;
              $lbl = ['social_instagram'=>'Instagram','social_facebook'=>'Facebook','social_linkedin'=>'LinkedIn','social_tiktok'=>'TikTok'][$col];
              $full_url = $base_url . $handle;
          ?>
            <div class="info-row">
              <span class="info-label"><?= $lbl ?></span>
              <span class="info-value"><a href="<?= htmlspecialchars($full_url) ?>" target="_blank">@<?= htmlspecialchars($handle) ?></a></span>
            </div>
          <?php endforeach; ?>
          <?php if ($agent['social_other']): ?>
            <div class="info-row"><span class="info-label">Other</span><span class="info-value"><a href="<?= val($agent,'social_other') ?>" target="_blank"><?= val($agent,'social_other') ?></a></span></div>
          <?php endif; ?>
        </div>

        <!-- ── Website ──────────────────────────────────────────────────── -->
        <div class="card">
          <div class="card-title" style="display:flex;align-items:center;">
            <i class="ti ti-world" style="margin-right:6px;"></i> Website
            <?php if ($web_status === 'approved' && $agent_slug !== ''): ?>
              <a class="section-edit-btn" href="<?= e_attr(rtrim(PUBLIC_SITE_URL, '/') . '/broker.php?s=' . $agent_slug) ?>" target="_blank" style="text-decoration:none;">
                View public profile <i class="ti ti-external-link"></i></a>
            <?php endif; ?>
          </div>

          <div class="fld" style="align-items:center;">
            <span class="fld-label">Status</span>
            <span class="pill w-<?= e_attr($web_status) ?>"><?= htmlspecialchars($web_label[$web_status] ?? $web_status) ?></span>
            <span style="flex:1;"></span>
            <div class="btn-row" style="margin:0;gap:6px;flex-wrap:nowrap;">
              <?php foreach ($web_actions as $to => $label): ?>
                <form method="POST" style="margin:0;">
                  <input type="hidden" name="_action" value="web_status">
                  <input type="hidden" name="to" value="<?= $to ?>">
                  <button type="submit" class="btn btn-<?= $to === 'approved' ? 'primary' : 'outline' ?> btn-xs"><?= $label ?></button>
                </form>
              <?php endforeach; ?>
            </div>
          </div>
          <?php if ($web_missing): ?>
            <p class="hint" style="margin:6px 0 0;">Still missing for the website: <?= htmlspecialchars(implode(', ', $web_missing)) ?>.</p>
          <?php endif; ?>

          <form method="POST" style="margin-top:14px;">
            <input type="hidden" name="_action" value="update_web">
            <div class="form-row">
              <label class="grow">Web address
                <input type="text" name="slug" class="form-input" value="<?= e_attr($agent_slug) ?>" placeholder="firstname-lastname">
              </label>
              <label style="width:110px;">Sort order
                <input type="number" name="sort_order" class="form-input" value="<?= (int)($agent['sort_order'] ?? 0) ?>">
              </label>
            </div>
            <div class="form-row">
              <label class="grow">Office
                <select name="office" class="form-input">
                  <option value="">Not set</option>
                  <?php foreach ($office_options as $o): ?>
                    <option value="<?= e_attr($o) ?>" <?= ($agent['office'] ?? '') === $o ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('-', ' ', $o))) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="grow">Service area
                <input type="text" name="service_area" class="form-input" value="<?= val($agent,'service_area') ?>" placeholder="Aspen and Snowmass">
              </label>
            </div>
            <label class="admin-check" style="display:flex;align-items:center;gap:8px;margin:4px 0 12px;">
              <input type="checkbox" name="in_fub" value="1" <?= !empty($agent['in_fub']) ? 'checked' : '' ?>> In the FUB lead rotation
            </label>
            <div class="btn-row"><button type="submit" class="btn btn-primary btn-sm">Save website details</button></div>
          </form>
        </div>

        <?php endif; /* staff: no social or website */ ?>

        <!-- ── Photos ───────────────────────────────────────────────────── -->
        <div class="card">
          <div class="card-title"><i class="ti ti-camera" style="margin-right:6px;"></i> Headshots</div>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="_action" value="upload_photos">
            <div class="form-row" style="align-items:flex-start;">
              <div class="grow">
                <span class="fld-label">Profile photo</span>
                <?php if (!empty($agent['headshot_url'])): ?>
                  <img src="<?= e_attr($agent['headshot_url']) ?>" alt="" style="max-width:190px;display:block;margin:6px 0;border:1px solid var(--mh-line,#e5e7eb);">
                  <label class="admin-check"><input type="checkbox" name="remove_headshot" value="1"> Remove</label>
                <?php endif; ?>
                <input type="file" name="headshot" id="photoInput" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:6px;">
                <input type="hidden" name="pcrop_x" id="pcropX"><input type="hidden" name="pcrop_y" id="pcropY">
                <input type="hidden" name="pcrop_w" id="pcropW"><input type="hidden" name="pcrop_h" id="pcropH">
                <input type="hidden" name="pcrop_src" id="pcropSrc">
                <?php if (!empty($agent['headshot_url'])): ?>
                  <button type="button" class="btn btn-outline btn-xs" id="reCropPhotoBtn" style="margin-top:8px;"
                          data-src="<?= e_attr($agent['headshot_url']) ?>">Re-crop this photo</button>
                <?php endif; ?>
                <div id="photoCropWrap" hidden>
                  <div class="face-crop-stage"><img id="photoCropImg" src="" alt=""></div>
                  <p class="hint" style="margin:6px 0 0;">Drag and zoom to frame them. The shape matches the website's broker cards, so everyone lines up.</p>
                </div>
              </div>
              <div class="grow">
                <span class="fld-label">Square version (cards and lists)</span>
                <?php if (!empty($agent['headshot_face_url'])): ?>
                  <img src="<?= e_attr($agent['headshot_face_url']) ?>" alt="" style="width:130px;height:130px;object-fit:cover;display:block;margin:6px 0;border:1px solid var(--mh-line,#e5e7eb);">
                  <label class="admin-check"><input type="checkbox" name="remove_headshot_face" value="1"> Remove</label>
                <?php endif; ?>
                <input type="file" name="headshot_face" id="faceInput" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:6px;">
                <input type="hidden" name="headshot_face_data" id="faceData">
                <input type="hidden" name="crop_x" id="cropX"><input type="hidden" name="crop_y" id="cropY">
                <input type="hidden" name="crop_w" id="cropW"><input type="hidden" name="crop_h" id="cropH">
                <input type="hidden" name="crop_src" id="cropSrc">
                <?php if (!empty($agent['headshot_url'])): ?>
                  <button type="button" class="btn btn-outline btn-sm" id="reCropBtn" style="margin-top:8px;"
                          data-src="<?= e_attr($agent['headshot_url']) ?>">Crop from the profile photo</button>
                <?php endif; ?>
                <div id="faceCropWrap" hidden>
                  <div class="face-crop-stage"><img id="faceCropImg" src="" alt=""></div>
                  <p class="hint" style="margin:6px 0 0;">Drag and zoom to frame the face. It is saved when you press Save photos.</p>
                </div>
              </div>
            </div>
            <p class="hint" style="margin:6px 0 10px;">A profile photo on its own also makes the square version, cropped from the top middle. Upload a square one only when that crop is wrong.</p>
            <div class="btn-row"><button type="submit" class="btn btn-primary btn-sm">Save photos</button></div>
          </form>
        </div>

    <!-- ── Bio ──────────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title" style="display:flex;align-items:center;">
        <i class="ti ti-file-text" style="margin-right:6px;"></i> Bio
        <button class="section-edit-btn" type="button" onclick="toggleInline('edit-bio-assets')">Edit</button>
      </div>

      <?php if (!empty($agent['bio_url'])): ?>
        <div class="asset-link-row" style="margin-bottom:10px;">
          <i class="ti ti-file-description"></i>
          <span class="asset-link-label">Bio Document</span>
          <a href="<?= htmlspecialchars($agent['bio_url']) ?>" target="_blank" class="asset-link-a">
            <i class="ti ti-external-link"></i> Open
          </a>
        </div>
      <?php endif; ?>

      <?php if (!empty($agent['bio_text']) || !empty($agent['bio_short'])): ?>
        <div class="bio-tabs">
          <?php if (!empty($agent['bio_text'])): ?><button class="bio-tab-btn active" data-bio="assets-full">Full Bio</button><?php endif; ?>
          <?php if (!empty($agent['bio_short'])): ?><button class="bio-tab-btn <?= empty($agent['bio_text'])?'active':'' ?>" data-bio="assets-short">Short / Social</button><?php endif; ?>
        </div>
        <?php if (!empty($agent['bio_text'])): ?>
          <div class="bio-panel active" id="bio-assets-full">
            <div class="bio-content rich"><?= strip_tags($agent['bio_text'], '<p><br><strong><b><em><i><u><ol><ul><li><a><h3><h4><blockquote>') ?></div>
          </div>
        <?php endif; ?>
        <?php if (!empty($agent['bio_short'])): ?>
          <div class="bio-panel <?= empty($agent['bio_text'])?'active':'' ?>" id="bio-assets-short">
            <div class="bio-content"><?= nl2br(htmlspecialchars($agent['bio_short'])) ?></div>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <p class="asset-empty">No bio yet. Click Edit to add one.</p>
      <?php endif; ?>

      <div id="edit-bio-assets" style="display:none;" class="inline-edit-form">
        <form method="POST" id="bioForm">
          <input type="hidden" name="_action" value="update_intake_fields">
          <input type="hidden" name="redirect_tab" value="profile">
          <div class="fld">
            <span class="fld-label">Bio Document URL</span>
            <input type="url" name="bio_url" class="form-input" style="max-width:420px;"
                   value="<?= val($agent,'bio_url') ?>" placeholder="https://drive.google.com/…">
          </div>
          <div class="fld" style="flex-direction:column;align-items:stretch;">
            <span class="fld-label" style="margin-bottom:6px;">Full Bio</span>
            <div id="bioEditor"><?= strip_tags($agent['bio_text'] ?? '', '<p><br><strong><b><em><i><u><ol><ul><li><a><h3><h4><blockquote>') ?></div>
            <textarea name="bio_text" id="bioTextHidden" style="display:none;"></textarea>
          </div>
          <div class="fld" style="flex-direction:column;align-items:stretch;">
            <span class="fld-label" style="margin-bottom:6px;">Short / Social Bio</span>
            <textarea name="bio_short" class="form-input" rows="3"
                      placeholder="Short bio for social media…"><?= htmlspecialchars($agent['bio_short'] ?? '') ?></textarea>
          </div>
          <div class="btn-row">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-bio-assets')">Cancel</button>
          </div>
        </form>
      </div>
    </div>
      </div>

      <div>
        <?php if (!$is_staff): ?>
        <!-- ── Board identities ─────────────────────────────────────────── -->
        <div class="card">
          <div class="card-title"><i class="ti ti-license" style="margin-right:6px;"></i> Board identities</div>
          <?php if ($identities): ?>
            <table class="mini-table" style="width:100%;">
              <?php foreach ($identities as $i): ?>
                <tr>
                  <td style="text-transform:uppercase;font-size:11px;letter-spacing:.06em;color:#6b7280;"><?= htmlspecialchars(mk_board_label($i['market'])) ?></td>
                  <td style="font-weight:600;"><?= htmlspecialchars($i['mls_agent_id']) ?>
                    <?php if ((int)$i['is_alias']): ?><span class="tag-team" style="margin-left:6px;">Team ID</span><?php endif; ?>
                    <?php if ($i['member_status'] !== 'Active'): ?><span class="pill w-inactive" style="margin-left:6px;">Off the feed</span><?php endif; ?>
                  </td>
                  <td style="text-align:right;color:#9ca3af;font-size:12px;">
                    <?= $i['last_seen_at'] ? 'seen ' . htmlspecialchars(date('M j', strtotime($i['last_seen_at']))) : 'added by hand' ?>
                  </td>
                  <td style="text-align:right;width:28px;">
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this MLS ID from <?= e_attr(addslashes($agent['agent_name'] ?? '')) ?>?');">
                      <input type="hidden" name="_action" value="remove_identity">
                      <input type="hidden" name="identity_id" value="<?= (int)$i['id'] ?>">
                      <button type="submit" class="btn-ghost" title="Remove"><i class="ti ti-x"></i></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php else: ?>
            <p class="hint" style="margin:0 0 10px;">No MLS IDs yet. The sync adds them; add one by hand for a board it does not cover.</p>
          <?php endif; ?>
          <form method="POST" style="margin-top:10px;">
            <input type="hidden" name="_action" value="add_identity">
            <div class="form-row">
              <label style="width:150px;">Board
                <select name="market" class="form-input">
                  <?php foreach (mk_board_labels() as $slug => $label): ?><option value="<?= $slug ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="grow">MLS ID <input type="text" name="mls_agent_id" class="form-input" placeholder="55061958"></label>
            </div>
            <label class="admin-check" style="display:flex;align-items:center;gap:8px;margin:4px 0 10px;">
              <input type="checkbox" name="is_alias" value="1"> This is a team ID, not theirs alone
            </label>
            <div class="btn-row"><button type="submit" class="btn btn-outline btn-sm">Add MLS ID</button></div>
          </form>
        </div>

        <!-- ── Subscriptions ────────────────────────────────────────────── -->
        <div class="card">
          <div class="card-title"><i class="ti ti-mail" style="margin-right:6px;"></i> Subscriptions</div>
          <div class="fld">
            <span class="fld-label">Hot Sheets</span>
            <span class="pill <?= $hs_on ? 'w-approved' : 'w-inactive' ?>"><?= $hs_on ? 'Weekly' : 'Not subscribed' ?></span>
            <span style="flex:1;"></span>
            <?php if ($hs_on): ?>
              <a class="btn btn-outline btn-xs" href="subscribers.php?q=<?= urlencode($hs_email) ?>">Manage</a>
            <?php else: ?>
              <form method="POST" style="margin:0;">
                <input type="hidden" name="_action" value="hs_toggle">
                <input type="hidden" name="to" value="on">
                <button type="submit" class="btn btn-primary btn-xs">Subscribe</button>
              </form>
            <?php endif; ?>
          </div>
          <p class="hint" style="margin:6px 0 0;">Subscribing here ticks the "<?= htmlspecialchars(MK_HS_TASK) ?>" item on their checklist. Manage opens the Hot Sheet subscriber list, where frequency, pausing and unsubscribing live.</p>
          <div class="fld" style="margin-top:12px;">
            <span class="fld-label">FUB rotation</span>
            <span class="pill <?= !empty($agent['in_fub']) ? 'w-approved' : 'w-inactive' ?>"><?= !empty($agent['in_fub']) ? 'In rotation' : 'Not in rotation' ?></span>
            <span style="flex:1;"></span>
            <span class="hint">Set it under Website</span>
          </div>
        </div>

        <?php endif; /* staff: no board identities or subscriptions */ ?>

        <?php if ($has_leadership && ($agent['entity_type'] ?? 'agent') !== 'team'): ?>
        <!-- ── Leadership (leadership_v1.sql) ───────────────────────────────── -->
        <div class="card" id="leadership">
          <div class="card-title"><i class="ti ti-crown" style="margin-right:6px;"></i> Leadership page</div>
          <form method="POST">
            <input type="hidden" name="_action" value="save_leadership">
            <label class="fld" style="gap:8px;"><input type="checkbox" name="leadership_show" value="1" <?= (int)($agent['leadership_show'] ?? 0) ? 'checked' : '' ?>>
              Show on the website's Leadership page</label>
            <div class="fld"><span class="fld-label">Order</span>
              <input type="number" name="leadership_sort" class="form-input" style="width:100px;" value="<?= (int)($agent['leadership_sort'] ?? 0) ?>">
              <span class="hint" style="margin-left:8px;">Lower comes first. Or arrange everyone on <a href="leadership.php">Leadership &amp; Staff</a>.</span></div>
            <?php if (!$is_staff && ($agent['web_status'] ?? '') !== 'approved'): ?>
              <p class="hint" style="margin:6px 0 0;color:#b45309;">Only agents who are on the website appear on Leadership.</p>
            <?php endif; ?>
            <div class="btn-row"><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
          </form>
        </div>
        <?php endif; ?>

        <!-- ── QR codes (qr_codes.php) ──────────────────────────────────── -->
        <div class="card">
          <div class="card-title"><i class="ti ti-qrcode" style="margin-right:6px;"></i> QR codes</div>
          <?php foreach ($qr_rows as $qr): ?>
            <div class="fld">
              <span class="fld-label"><?= e_attr($qr['label'] !== '' ? $qr['label'] : 'QR code') ?></span>
              <a href="qr_codes.php?intake=<?= $id ?>#qr-<?= (int) $qr['id'] ?>"><?= e_attr(preg_replace('~^https://~', '', qr_public_url($qr['code']))) ?></a>
              <span style="flex:1;"></span>
              <span class="hint"><?= number_format((int) $qr['scan_count']) ?> scans</span>
              <span class="pill <?= (int) $qr['is_active'] ? 'w-approved' : 'w-inactive' ?>" style="margin-left:8px;"><?= (int) $qr['is_active'] ? 'Live' : 'Paused' ?></span>
            </div>
          <?php endforeach; ?>
          <?php if (!$qr_rows): ?><p class="hint" style="margin:0 0 8px;">No QR codes yet.</p><?php endif; ?>
          <div class="btn-row" style="margin-top:8px;">
            <a class="btn btn-outline btn-xs" href="qr_codes.php?intake=<?= $id ?>"><?= $qr_rows ? 'Manage QR codes' : 'Create a QR code' ?></a>
          </div>
        </div>

        <?php if (!$is_staff): ?>
        <div class="card">
          <div class="card-title">Tasks</div>
          <div class="ob-list">
          <?php if (!$overview_tasks): ?>
            <p class="asset-empty" style="margin:0;">
              <?= $done_tasks > 0 ? 'All tasks complete.' : 'No open tasks.' ?>
            </p>
          <?php endif; ?>
          <?php foreach ($overview_tasks as $pt):
            $needs_pdate = in_array($pt['title'], $due_date_parent_tasks);
          ?>
            <div class="ob-item">
              <div class="ob-header">
                <form method="POST" style="margin:0;display:contents;">
                  <input type="hidden" name="_action" value="toggle_task">
                  <input type="hidden" name="task_id" value="<?= $pt['id'] ?>">
                  <input type="hidden" name="new_status" value="done">
                  <input type="hidden" name="redirect_tab" value="profile">
                  <button type="submit" class="ob-check-btn" title="Mark done">
                    <i class="ti ti-circle"></i>
                  </button>
                </form>
                <span class="ob-title"><?= htmlspecialchars($pt['title']) ?></span>
                <?php if ($needs_pdate): ?>
                  <?php if (!empty($pt['due_date'])): ?>
                    <span class="ob-date-label"><i class="ti ti-calendar" style="font-size:11px;"></i> <?= date('M j, Y', strtotime($pt['due_date'])) ?></span>
                  <?php endif; ?>
                  <button class="ob-edit-url-btn" type="button" onclick="toggleInline('pdate-<?= $pt['id'] ?>')">
                    <?= !empty($pt['due_date']) ? '✎ date' : '+ date' ?>
                  </button>
                <?php endif; ?>
              </div>
              <?php if ($needs_pdate): ?>
              <div id="pdate-<?= $pt['id'] ?>" style="display:none;padding:4px 0 8px 28px;">
                <form method="POST" class="ob-inline-form">
                  <input type="hidden" name="_action" value="update_task_due">
                  <input type="hidden" name="task_id" value="<?= $pt['id'] ?>">
                  <input type="date" name="due_date" value="<?= htmlspecialchars($pt['due_date'] ?? '') ?>" class="ob-inline-input" style="max-width:160px;">
                  <button type="submit" class="btn btn-outline btn-xs">Save</button>
                </form>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
          <?php if ($done_tasks > 0): ?>
            <div class="ob-done-link">
              <button type="button" onclick="showCompletedTasks()">
                <i class="ti ti-circle-check"></i>
                <?= $done_tasks ?> completed <?= $done_tasks === 1 ? 'task' : 'tasks' ?>
              </button>
            </div>
          <?php endif; ?>
        </div>

        <!-- ── From the MLS ─────────────────────────────────────────────── -->
        <div class="card">
          <div class="card-title"><i class="ti ti-database" style="margin-right:6px;"></i> From the MLS</div>
          <table class="mini-table" style="width:100%;">
            <tr><td class="fld-label">Name</td><td><?= val($agent,'mls_full_name') ?: '<span class="hint">not synced yet</span>' ?></td></tr>
            <tr><td class="fld-label">Email</td><td><?= val($agent,'mls_email') ?></td></tr>
            <tr><td class="fld-label">Phone</td><td><?= val($agent,'mls_phone') ?></td></tr>
            <tr><td class="fld-label">Last sync</td><td><?= !empty($agent['mls_synced_at']) ? htmlspecialchars(date('M j, Y', strtotime($agent['mls_synced_at']))) : '<span class="hint">never</span>' ?></td></tr>
          </table>
          <p class="hint" style="margin:8px 0 0;">The MLS record is never edited here. What the website shows comes from the fields above.</p>
        </div>
        <?php endif; /* staff: no tasks or MLS */ ?>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════ TASKS ══ -->
  <div class="tab-panel <?= $active_tab==='tasks'?'active':'' ?>" id="tab-tasks">
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="task-filters">
          <button class="tf-btn on" data-tf="open">Open<?= $open_tasks ? ' (' . $open_tasks . ')' : '' ?></button>
          <button class="tf-btn" data-tf="done">Completed<?= $done_tasks ? ' (' . $done_tasks . ')' : '' ?></button>
          <button class="tf-btn" data-tf="all">All</button>
        </div>
      </div>

      <?php if (empty($parent_tasks)): ?>
        <p style="font-size:13px;color:#9ca3af;text-align:center;padding:24px 0;">No tasks yet. Add one below.</p>
      <?php else: ?>
        <div class="task-list" id="taskList">
          <?php foreach ($parent_tasks as $t):
            $is_done  = $t['status'] === 'done';
            $is_high  = $t['priority'] === 'high';
            $due      = $t['due_date'];
            $overdue  = $due && !$is_done && strtotime($due) < time();
          ?>
          <div class="task-row <?= $is_done?'done-row':'' ?>" data-tstat="<?= $t['status'] ?>">
            <span class="task-check">
              <form method="POST" style="margin:0;">
                <input type="hidden" name="_action" value="toggle_task">
                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $is_done?'open':'done' ?>">
                <input type="hidden" name="redirect_tab" value="tasks">
                <input type="checkbox" <?= $is_done?'checked':'' ?> onchange="this.form.submit()" title="<?= $is_done?'Mark open':'Mark done' ?>">
              </form>
            </span>
            <span class="task-title"><?= htmlspecialchars($t['title']) ?></span>
            <span class="task-meta">
              <?php if ($is_done): ?><span class="done-chip">Completed</span><?php endif; ?>
              <?php if ($is_high && !$is_done): ?><span class="priority-chip high">High</span><?php endif; ?>
              <span class="cat-chip"><?= htmlspecialchars(ucfirst($t['category'])) ?></span>
              <?php if ($due): ?>
                <span class="due-date <?= $overdue?'overdue':'' ?>"><?= $overdue?'⚠ ':'' ?><?= date('m/d/y', strtotime($due)) ?></span>
              <?php endif; ?>
              <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this task?')">
                <input type="hidden" name="_action" value="delete_task">
                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                <input type="hidden" name="redirect_tab" value="tasks">
                <button type="submit" class="btn-ghost" title="Delete"><i class="ti ti-x"></i></button>
              </form>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="add-form">
        <h4>Add Task</h4>
        <form method="POST">
          <input type="hidden" name="_action" value="add_task">
          <div class="form-row">
            <label class="grow">Task Title
              <input type="text" name="title" class="form-input" placeholder="e.g. Order business cards" required>
            </label>
            <label>Category
              <select name="category" class="form-input">
                <option value="onboarding">Onboarding</option>
                <option value="collateral">Collateral</option>
                <option value="digital">Digital</option>
                <option value="photo">Photo</option>
                <option value="other" selected>Other</option>
              </select>
            </label>
            <label>Priority
              <select name="priority" class="form-input">
                <option value="normal" selected>Normal</option>
                <option value="high">High</option>
              </select>
            </label>
            <label>Due Date
              <input type="date" name="due_date" class="form-input">
            </label>
          </div>
          <div class="form-row">
            <label class="grow">Notes (optional)
              <input type="text" name="notes" class="form-input" placeholder="Any extra detail…">
            </label>
            <label style="justify-content:flex-end;">
              <button type="submit" class="btn btn-primary btn-sm" style="margin-top:auto;"><i class="ti ti-plus"></i> Add</button>
            </label>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php
  // ── Collateral orders block helper ───────────────────────────────────────────
  function coll_orders_block(string $type, array $orders, int $aid, array $status_labels, array $status_colors): void { ?>
    <div class="coll-orders">
      <div class="coll-orders-title">Orders</div>
      <?php foreach ($orders as $o):
        $sc = $status_colors[$o['status']] ?? $status_colors['pending'];
        $sl = $status_labels[$o['status']] ?? $o['status'];
        $eid = 'edit-ord-' . $o['id'];
      ?>
      <div class="order-card" id="order-<?= (int)$o['id'] ?>">
        <div class="order-card-header">
          <div style="flex:1;min-width:0;">
            <div class="order-label"><?= $o['label'] ? htmlspecialchars($o['label']) : htmlspecialchars(ucwords(str_replace('_',' ',$type))) ?></div>
            <?php if ($o['vendor']): ?>
              <div class="order-num"><?= htmlspecialchars($o['vendor']) ?><?= $o['order_number'] ? ' — #'.htmlspecialchars($o['order_number']) : '' ?></div>
            <?php endif; ?>
          </div>
          <span class="order-status-chip" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-color:<?= $sc['border'] ?>;"><?= $sl ?></span>
        </div>
        <div class="order-details">
          <?php if ($o['qty']): ?><span>Qty: <strong><?= htmlspecialchars($o['qty']) ?></strong></span><?php endif; ?>
          <?php if ($o['cost']): ?><span>Cost: <strong>$<?= number_format((float)$o['cost'],2) ?></strong></span><?php endif; ?>
          <?php if ($o['ordered_at']): ?><span>Ordered: <strong><?= date('m/d/Y', strtotime($o['ordered_at'])) ?></strong></span><?php endif; ?>
          <?php if ($o['delivered_at']): ?><span>Delivered: <strong><?= date('m/d/Y', strtotime($o['delivered_at'])) ?></strong></span><?php endif; ?>
        </div>
        <div>
          <?php if ($o['vendor_url']): ?>
            <a class="order-link" href="<?= htmlspecialchars($o['vendor_url']) ?>" target="_blank"><i class="ti ti-external-link" style="font-size:10px;"></i> Vendor</a>
          <?php endif; ?>
          <?php if ($o['tracking_number']): ?>
            <?php $turl = $o['tracking_url'] ?: 'https://www.google.com/search?q='.rawurlencode($o['tracking_number']); ?>
            <a class="order-link" href="<?= htmlspecialchars($turl) ?>" target="_blank"><i class="ti ti-truck" style="font-size:10px;"></i> <?= htmlspecialchars($o['tracking_number']) ?></a>
          <?php endif; ?>
          <?php if ($o['file_url']): ?>
            <a class="order-link" href="<?= htmlspecialchars($o['file_url']) ?>" target="_blank"><i class="ti ti-file" style="font-size:10px;"></i> Final Files</a>
          <?php endif; ?>
          <?php if (!empty($o['receipt_file'])): ?>
            <a class="order-link" href="receipt.php?order_id=<?= (int)$o['id'] ?>" target="_blank"><i class="ti ti-receipt" style="font-size:10px;"></i> Receipt</a>
            <a class="order-link" href="receipt.php?order_id=<?= (int)$o['id'] ?>&amp;dl=1"><i class="ti ti-download" style="font-size:10px;"></i> Download</a>
          <?php endif; ?>
        </div>

        <?php mk_who_pays_summary($o, 'cost'); ?>
        <?php if ($o['notes']): ?><div class="order-notes"><?= htmlspecialchars($o['notes']) ?></div><?php endif; ?>
        <div class="order-actions">
          <button class="section-edit-btn" type="button" onclick="toggleInline('<?= $eid ?>')">Edit</button>
          <?php if (!empty($o['receipt_file'])): ?>
            <!-- Sits here rather than inside the edit form — HTML forms can't nest -->
            <form method="POST" style="margin:0;" onsubmit="return confirm('Remove the receipt from this order?')">
              <input type="hidden" name="_action" value="delete_collateral_receipt">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <button type="submit" class="btn btn-outline btn-xs" title="Remove receipt">
                <i class="ti ti-receipt-off"></i> Remove Receipt
              </button>
            </form>
          <?php endif; ?>
          <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this order?')">
            <input type="hidden" name="_action" value="delete_collateral_order">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <button type="submit" class="btn btn-danger btn-xs"><i class="ti ti-trash"></i></button>
          </form>
        </div>
        <!-- Inline edit form for this order -->
        <div id="<?= $eid ?>" style="display:none;" class="inline-edit-form" style="margin-top:10px;">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="_action" value="update_collateral_order">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <?php coll_order_form_fields($o, $status_labels); ?>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('<?= $eid ?>')">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <!-- Add new order -->
      <button class="add-order-btn" type="button" onclick="toggleInline('add-ord-<?= $type ?>')"><i class="ti ti-plus"></i> Add Order</button>
      <div id="add-ord-<?= $type ?>" style="display:none;" class="inline-edit-form">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="_action" value="add_collateral_order">
          <input type="hidden" name="type" value="<?= $type ?>">
          <?php coll_order_form_fields([], $status_labels); ?>
          <div class="btn-row">
            <button type="submit" class="btn btn-primary btn-sm">Save Order</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('add-ord-<?= $type ?>')">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  <?php }

  /**
   * "Who Pays" radio group + split amounts. Shared by collateral orders and
   * advertising placements so the two stay identical.
   *
   * $row  — the order/campaign being edited, or [] when adding
   * $key  — unique per rendered form; several forms sit on the page at once and
   *         the radio groups must not collide
   * $total_field — the input whose value the split is checked against:
   *         'cost' for a collateral order, 'budget' for a placement
   */
  /**
   * Weekday names indexed the way date('w') and unit_weekday both are:
   * 0 = Sunday. Do not reorder — the index IS the stored value.
   */
  function mk_weekday_names(): array {
      return [0=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',
              4=>'Thursday',5=>'Friday',6=>'Saturday'];
  }

  /**
   * The three per-day fields. Hidden unless Billing is "Per day", shown by the
   * script at the foot of the Advertising tab.
   *
   * $c is the campaign being edited, or [] when adding.
   */
  function mk_unit_fields(array $c = []): void {
      $mode = $c['billing_mode'] ?? '';
      $wd   = $c['unit_weekday'] ?? null;
  ?>
    <?php // What "Budget" means changes completely with the mode, and the word
          // itself does not. Spell it out rather than leaving the field to be
          // guessed at — misreading it is what puts a wrong figure on an
          // invoice. Text is swapped by the script at the foot of the page. ?>
    <div class="unit-hint js-budget-hint"
         data-one_time="Budget is the whole charge, and it lands in the month of the start date."
         data-monthly_flat="Budget is the amount, every month, from the run start onward — no end date means it keeps going until you set one. A new month starts billing on its first day, never before. Change a single month in the Month-by-month grid."
         data-monthly="Budget here is only a default. It bills nothing on its own — each month's amount is entered in the Month-by-month grid, and a month you have not entered bills nothing and stays flagged."
         data-per_unit="Budget is ignored in this mode. The money is the rate below, times the days that fall in each month."></div>
    <div class="form-row js-unit-fields" style="<?= $mode === 'per_unit' ? '' : 'display:none;' ?>">
      <label>Rate per day
        <input type="number" name="unit_rate" class="form-input" step="0.01" min="0"
               placeholder="125.00" value="<?= htmlspecialchars($c['unit_rate'] ?? '') ?>">
      </label>
      <label>Which day
        <select name="unit_weekday" class="form-input">
          <?php // '' is a real choice, not a prompt: some placements have no
                // weekly rhythm and the quantity is typed each month. ?>
          <option value="">No fixed day — enter days each month</option>
          <?php foreach (mk_weekday_names() as $n => $lbl): ?>
            <option value="<?= $n ?>" <?= ($wd !== null && $wd !== '' && (int)$wd === $n) ? 'selected' : '' ?>>
              Every <?= $lbl ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="grow">Unit name
        <input type="text" name="unit_label" class="form-input" placeholder="day"
               value="<?= htmlspecialchars($c['unit_label'] ?? '') ?>">
      </label>
    </div>
    <div class="unit-hint js-unit-fields" style="<?= $mode === 'per_unit' ? '' : 'display:none;' ?>">
      <i class="ti ti-calendar-event"></i>
      Each month is charged the rate times however many of that day fall inside
      the run. August 2026 has four Wednesdays and five Saturdays — the same
      rate gives a different total, which is the point. When the outlet runs
      fewer than scheduled, change that month's day count in the Month-by-month
      grid below.
    </div>
  <?php
  }

  /**
   * The month grid — every month of one recurring placement, one Save.
   *
   * This is the ONLY place per-month figures are entered. It replaced a
   * one-month-at-a-time popover, which was wrong for the way the work actually
   * happens: Nikki sits down with an invoice and types a column of numbers.
   *
   * For `monthly` the amount box IS the money. Blank means the month has not
   * been decided, and blank is a legitimate long-term state for a month nobody
   * has budgeted yet — it is not an error and it must not be filled in for her.
   * The placement's `budget` shows only as the input's placeholder.
   *
   * For `per_unit` the day count is pre-filled from the calendar as a real
   * value, so saving the grid records what was actually counted. Change the
   * number when the outlet ran short.
   *
   * Rendered as a SIBLING of the campaign edit form, never inside it: nested
   * <form> elements are invalid HTML, the browser drops the inner one, and this
   * grid would post nothing at all.
   */
  function mk_month_grid(array $c, array $months, array $rows, array $resolved, float $total): void {
      $cid  = (int)$c['id'];
      $mode = $c['billing_mode'] ?? 'one_time';
      $per  = ($mode === 'per_unit');
      $flat = ($mode === 'monthly_flat');
      $ulbl = $c['unit_label'] ?: 'day';
      // Months still awaiting a figure. On a standing buy this is normally 0 —
      // it only fires when the placement has no standing amount at all, which
      // is the one way monthly_flat can silently bill nothing.
      $open = 0;
      foreach ($resolved as $r) { if ($r !== null && !$r['set']) $open++; }
  ?>
    <div class="mo-head">
      <button type="button" class="section-edit-btn" onclick="toggleInline('camp-months-<?= $cid ?>')">
        <i class="ti ti-calendar-stats"></i>
        Month by month (<?= count($months) ?>)
      </button>
      <?php if ($open): ?>
        <span class="mo-open-chip" title="These months bill nothing until an amount is entered">
          <i class="ti ti-alert-circle"></i>
          <?= $open ?> month<?= $open === 1 ? '' : 's' ?> not set
        </span>
      <?php endif; ?>
    </div>

    <div id="camp-months-<?= $cid ?>" style="<?= $open ? '' : 'display:none;' ?>">
      <form method="POST">
        <input type="hidden" name="_action"     value="save_campaign_months">
        <input type="hidden" name="campaign_id" value="<?= $cid ?>">

        <table class="mo-table">
          <thead>
            <tr>
              <th>Month</th>
              <?php if ($per): ?>
                <th><?= htmlspecialchars(ucfirst($ulbl)) ?>s</th>
                <th>Rate</th>
              <?php else: ?>
                <th>Amount</th>
              <?php endif; ?>
              <th>Who pays</th>
              <th>Note</th>
              <th class="num">Charge</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($months as $ym):
                  $row = $rows[$ym] ?? null;
                  $res = $resolved[$ym] ?? null;
                  // A per-day month the run never touches: shown, but with
                  // nothing to fill in, because there was no placement.
                  $none = ($res === null); ?>
            <tr class="<?= ($res && !$res['set']) ? 'mo-unset' : '' ?>">
              <td class="mo-m"><?= date('M Y', strtotime($ym . '-01')) ?></td>

              <?php if ($none): ?>
                <td colspan="<?= $per ? 4 : 3 ?>" class="mo-none">
                  no <?= htmlspecialchars($ulbl) ?> falls in this month
                </td>
                <td class="num">—</td>
              <?php else: ?>
                <?php if ($per): ?>
                  <td>
                    <input type="number" class="form-input mo-in" step="0.5" min="0"
                           name="months[<?= $ym ?>][units]"
                           value="<?= htmlspecialchars(mk_num($res['units'])) ?>">
                  </td>
                  <td>
                    <input type="number" class="form-input mo-in" step="0.01" min="0"
                           name="months[<?= $ym ?>][unit_rate]"
                           placeholder="<?= htmlspecialchars((string)($c['unit_rate'] ?? '')) ?>"
                           value="<?= htmlspecialchars($row['unit_rate'] ?? '') ?>">
                  </td>
                <?php else: ?>
                  <td>
                    <?php // Placeholder, never a value. On `monthly` a pre-filled
                          // amount is a figure nobody typed that would save on the
                          // next submit as though somebody had; on `monthly_flat`
                          // blank genuinely means "the standing amount", and
                          // writing it into every row would turn one decision
                          // into a dozen stored copies of it. ?>
                    <input type="number" class="form-input mo-in" step="0.01" min="0"
                           name="months[<?= $ym ?>][amount]"
                           placeholder="<?= $c['budget'] ? htmlspecialchars(mk_num($c['budget'])) : ($flat ? 'no standing amount' : 'not set') ?>"
                           value="<?= htmlspecialchars($row['amount'] ?? '') ?>">
                  </td>
                <?php endif; ?>

                <td>
                  <select name="months[<?= $ym ?>][paid_by]" class="form-input mo-in mo-in-w">
                    <option value="">same as placement</option>
                    <?php foreach (['broker'=>'Broker','mont_haus'=>'Mont Haus','split'=>'Split'] as $v=>$l): ?>
                      <option value="<?= $v ?>" <?= ($row['paid_by'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <input type="text" class="form-input mo-in mo-in-note" maxlength="255"
                         name="months[<?= $ym ?>][note]"
                         placeholder="<?= $per ? 'e.g. outlet missed one week' : '' ?>"
                         value="<?= htmlspecialchars($row['note'] ?? '') ?>">
                </td>
                <td class="num">
                  <?php if (!$res['set']): ?>
                    <span class="mo-flag unset">not set</span>
                  <?php elseif ($res['zeroed']): ?>
                    <span class="mo-flag zero">no charge</span>
                  <?php else: ?>
                    <strong><?= money2((float)$res['amount']) ?></strong>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="<?= $per ? 4 : 3 ?>" class="fin-foot-label"><?= $mode === 'monthly' ? 'Entered so far' : 'So far' ?></td>
              <td class="num"><strong><?= money2($total) ?></strong></td>
            </tr>
          </tfoot>
        </table>

        <div class="mo-actions">
          <button type="submit" class="btn btn-primary btn-xs"><i class="ti ti-check"></i> Save months</button>
          <span class="mo-hint">
            <?php if ($per): ?>
              Day counts come from the calendar — change one when the outlet ran short.
              Blank the rate to use the placement's.
            <?php elseif ($flat): ?>
              Blank uses the standing amount. Fill one in to change that month only,
              or <strong>0</strong> for a month that is deliberately not charged.
              Months appear here as they begin.
            <?php else: ?>
              A month left blank bills nothing and stays flagged. Enter <strong>0</strong>
              to record a month that is deliberately not charged.
            <?php endif; ?>
          </span>
        </div>
      </form>
    </div>
  <?php
  }

  function mk_who_pays_fields(array $row, string $key, string $total_field = 'cost'): void {
      $paid_by = $row['paid_by'] ?? '';
      $grp = $key !== '' ? $key : ('new' . substr(md5(uniqid('', true)), 0, 6));
  ?>
    <div class="pay-block">
      <div class="pay-title">Who Pays</div>
      <div class="pay-opts">
        <?php foreach ([
            'broker'    => 'Broker',
            'mont_haus' => 'Mont Haus',
            'split'     => 'Split',
        ] as $v => $lbl): ?>
          <label class="pay-opt">
            <input type="radio" name="paid_by" value="<?= $v ?>"
                   data-grp="<?= htmlspecialchars($grp) ?>"
                   data-total="<?= htmlspecialchars($total_field) ?>"
                   <?= $paid_by === $v ? 'checked' : '' ?>> <?= $lbl ?>
          </label>
        <?php endforeach; ?>
        <label class="pay-opt" style="color:#9ca3af;">
          <input type="radio" name="paid_by" value=""
                 data-grp="<?= htmlspecialchars($grp) ?>"
                 data-total="<?= htmlspecialchars($total_field) ?>"
                 <?= ($paid_by === '' || $paid_by === null) ? 'checked' : '' ?>> Not set
        </label>
      </div>
      <div class="pay-split" data-split="<?= htmlspecialchars($grp) ?>"
           style="<?= $paid_by === 'split' ? '' : 'display:none;' ?>">
        <label>Broker $
          <input type="number" name="paid_broker_amount" class="form-input" step="0.01" min="0"
                 style="max-width:130px;" value="<?= htmlspecialchars($row['paid_broker_amount'] ?? '') ?>">
        </label>
        <label>Mont Haus $
          <input type="number" name="paid_mh_amount" class="form-input" step="0.01" min="0"
                 style="max-width:130px;" value="<?= htmlspecialchars($row['paid_mh_amount'] ?? '') ?>">
        </label>
        <span class="pay-hint" data-hint="<?= htmlspecialchars($grp) ?>"></span>
      </div>
    </div>
  <?php }

  /** Read-only "who paid" summary for a card. $total_field names the column to reconcile against. */
  function mk_who_pays_summary(array $row, string $total_col = 'cost'): void {
      $pb = $row['paid_by'] ?? '';
      if ($pb === '' || $pb === null) return;
      $labels = ['broker' => 'Broker', 'mont_haus' => 'Mont Haus', 'split' => 'Split'];
      $colors = ['broker' => '#7c3aed', 'mont_haus' => '#0184BB', 'split' => '#F49808'];
  ?>
    <div class="pay-summary">
      <span class="pay-chip" style="background:<?= $colors[$pb] ?? '#6b7280' ?>;">
        <?= $labels[$pb] ?? htmlspecialchars($pb) ?>
      </span>
      <?php if ($pb === 'split'):
        $sum   = (float)($row['paid_broker_amount'] ?? 0) + (float)($row['paid_mh_amount'] ?? 0);
        $total = (float)($row[$total_col] ?? 0);
      ?>
        <span class="pay-amounts">
          Broker <strong>$<?= number_format((float)($row['paid_broker_amount'] ?? 0), 2) ?></strong>
          &nbsp;·&nbsp;
          MH <strong>$<?= number_format((float)($row['paid_mh_amount'] ?? 0), 2) ?></strong>
          <?php if ($total > 0 && abs($sum - $total) > 0.01): ?>
            <span class="pay-warn" title="Split does not add up to the <?= $total_col === 'budget' ? 'budget' : 'order cost' ?>">
              ⚠ $<?= number_format($sum, 2) ?> of $<?= number_format($total, 2) ?>
            </span>
          <?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  <?php }

  // Renders the shared form fields for add/edit order
  function coll_order_form_fields(array $o, array $status_labels): void { ?>
    <div class="form-row">
      <label class="grow">Order Label <input type="text" name="label" class="form-input" value="<?= htmlspecialchars($o['label']??'') ?>" placeholder="e.g. Initial order, Reorder #2"></label>
      <label>Status
        <select name="status" class="form-input">
          <?php foreach ($status_labels as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($o['status']??'pending')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="form-row">
      <label class="grow">Vendor <input type="text" name="vendor" class="form-input" value="<?= htmlspecialchars($o['vendor']??'') ?>" placeholder="e.g. Moo, VistaPrint"></label>
      <label class="grow">Vendor / Order URL <input type="url" name="vendor_url" class="form-input" value="<?= htmlspecialchars($o['vendor_url']??'') ?>" placeholder="https://…"></label>
    </div>
    <div class="form-row">
      <label>Order # <input type="text" name="order_number" class="form-input" value="<?= htmlspecialchars($o['order_number']??'') ?>"></label>
      <label>Qty <input type="text" name="qty" class="form-input" value="<?= htmlspecialchars($o['qty']??'') ?>"></label>
      <label>Cost ($) <input type="number" name="cost" class="form-input" value="<?= htmlspecialchars($o['cost']??'') ?>" step="0.01" min="0"></label>
      <label>Order Date <input type="date" name="ordered_at" class="form-input" value="<?= htmlspecialchars($o['ordered_at']??'') ?>"></label>
      <label>Delivery Date <input type="date" name="delivered_at" class="form-input" value="<?= htmlspecialchars($o['delivered_at']??'') ?>"></label>
    </div>
    <div class="form-row">
      <label>Tracking # <input type="text" name="tracking_number" class="form-input" value="<?= htmlspecialchars($o['tracking_number']??'') ?>"></label>
      <label class="grow">Tracking URL <input type="url" name="tracking_url" class="form-input" value="<?= htmlspecialchars($o['tracking_url']??'') ?>" placeholder="https://…"></label>
      <label class="grow">Final Files URL <input type="url" name="file_url" class="form-input" value="<?= htmlspecialchars($o['file_url']??'') ?>" placeholder="Dropbox / Drive link…"></label>
    </div>
    <?php
      $oid_ref = (int)($o['id'] ?? 0);
      // Combined purchase: one order holds the invoice amount, the rest point
      // at it. Only offered when editing an existing order — a new one has no
      // id yet, so nothing could point at it.
      global $coll_all;
      $billed_with = (int)($o['billed_with_order_id'] ?? 0);
      if ($oid_ref && is_array($coll_all) && count($coll_all) > 1):
    ?>
    <div class="form-row">
      <label class="grow">Billed with
        <small style="font-weight:400;color:#9ca3af;">
          — if this was on the same invoice as another order, point at the one holding the cost
        </small>
        <select name="billed_with_order_id" class="form-input">
          <option value="">— separate invoice, cost counted on its own —</option>
          <?php foreach ($coll_all as $cid => $co):
              if ((int)$cid === $oid_ref) continue;                        // no self-reference
              if (!empty($co['billed_with_order_id'])) continue;           // no chains
              $cl = mk_coll_type_label((string)$co['type']);
              $lb = trim((string)$co['label']);
              $cost_txt = $co['cost'] !== null && $co['cost'] !== '' ? ' — $' . number_format((float)$co['cost'], 2) : '';
          ?>
            <option value="<?= (int)$cid ?>" <?= $billed_with === (int)$cid ? 'selected' : '' ?>>
              <?= htmlspecialchars($cl . ($lb !== '' ? ": {$lb}" : '') . $cost_txt) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <?php endif; ?>

    <?php mk_who_pays_fields($o, 'ord' . $oid_ref); ?>

    <div class="form-row">
      <label class="grow">Receipt <small style="font-weight:400;color:#9ca3af;">(PDF, JPG or PNG — max 10 MB)</small>
        <input type="file" name="receipt" class="form-input" accept="application/pdf,image/jpeg,image/png">
      </label>
      <?php if (!empty($o['receipt_file'])): ?>
        <label style="flex-shrink:0;">
          <span style="display:block;font-size:12px;font-weight:600;color:#6b7280;
                       text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Current</span>
          <a class="order-link" href="receipt.php?order_id=<?= $oid_ref ?>" target="_blank">
            <i class="ti ti-receipt" style="font-size:10px;"></i>
            <?= htmlspecialchars($o['receipt_orig_name'] ?: 'View receipt') ?>
          </a>
          <span style="font-size:11px;color:#9ca3af;">— uploading a new file replaces it</span>
        </label>
      <?php endif; ?>
    </div>

    <div class="form-row">
      <label class="grow">Notes <textarea name="notes" class="form-input" rows="2" placeholder="Any notes about this order…"><?= htmlspecialchars($o['notes']??'') ?></textarea></label>
    </div>
  <?php }
  ?>

  <!-- ══════════════════════════════════════════════ COLLATERAL ══ -->
  <div class="tab-panel <?= $active_tab==='collateral'?'active':'' ?>" id="tab-collateral">
    <div class="card">
      <?php // Combined invoices — one vendor bill covering several agents.
            //
            // Entering only each agent's line-item total under-states every one
            // of them: the discount, rush fee, shipping and sales tax are billed
            // ONCE, at the bottom of the invoice. order_split.php enters the
            // invoice once and writes each agent's LANDED cost into the Cost
            // field of their order on this tab, so nothing downstream changes.
            //
            // Deliberately not in the top nav: the 820px breakpoint in
            // inc/_nav.php was measured against the links already there, and a
            // fourth would need re-measuring. This is where the work happens. ?>
      <div class="coll-split-cta">
        <div>
          <strong>Signs for several agents on one invoice?</strong>
          <span>Split it once and every agent gets their true landed cost — merchandise
                plus their share of the discount, rush, shipping and tax.</span>
        </div>
        <a class="btn btn-outline btn-sm" href="order_split.php" style="text-transform:none;font-size:12px;white-space:nowrap;">
          <i class="ti ti-arrows-split-2"></i> Split an invoice
        </a>
      </div>
      <?php
      function step(bool $done, string $label): string {
          $cls = $done ? 'done' : 'todo';
          $ico = $done ? 'ti-check' : 'ti-circle';
          return "<span class='step {$cls}'><i class='ti {$ico}'></i>" . htmlspecialchars($label) . "</span>";
      }
      ?>
      <!-- Business Cards -->
      <div class="coll-section">
        <div class="coll-header"><i class="ti ti-id" style="font-size:16px;color:#6b7280;"></i><span class="coll-title">Business Cards</span>
          <button class="section-edit-btn" type="button" onclick="toggleInline('edit-bc')"><?= $agent['coll_business_cards'] ? 'Edit' : 'Add' ?></button>
        </div>
        <?php if ($agent['coll_business_cards']): ?>
          <?php if ($agent['coll_bc_qty']||$agent['coll_bc_front']||$agent['coll_bc_back']): ?>
            <div class="coll-detail"><?= $agent['coll_bc_qty']?'Qty: '.htmlspecialchars($agent['coll_bc_qty']).'&nbsp;&nbsp;':'' ?><?= $agent['coll_bc_front']?'Front: '.htmlspecialchars($agent['coll_bc_front']).'&nbsp;&nbsp;':'' ?><?= $agent['coll_bc_back']?'Back: '.htmlspecialchars($agent['coll_bc_back']):'' ?></div>
          <?php endif; ?>
          <div class="steps">
            <?= step((bool)$agent['coll_bc_chk_design'],'Design') ?>
            <?= step((bool)$agent['coll_bc_chk_proof'],'Proof') ?>
            <?= step((bool)$agent['coll_bc_chk_accounting'],'Accounting') ?>
            <?= step((bool)$agent['coll_bc_chk_ordered'],'Ordered') ?>
            <?= step((bool)$agent['coll_bc_chk_delivered'],'Delivered') ?>
          </div>
        <?php endif; ?>
        <?php coll_orders_block('business_cards', $coll_orders['business_cards'] ?? [], $id, $order_status_labels, $order_status_colors); ?>
        <div id="edit-bc" style="display:none;" class="inline-edit-form">
          <form method="POST">
            <input type="hidden" name="_action" value="update_intake_fields">
            <input type="hidden" name="redirect_tab" value="collateral">
            <div class="form-row" style="margin-bottom:8px;">
              <label style="flex-direction:row;align-items:center;gap:6px;font-weight:normal;font-size:13px;cursor:pointer;">
                <input type="hidden" name="coll_business_cards" value="0">
                <input type="checkbox" name="coll_business_cards" value="1" <?= $agent['coll_business_cards']?'checked':'' ?>> Requested
              </label>
            </div>
            <div class="form-row">
              <label>Qty <input type="text" name="coll_bc_qty" class="form-input" value="<?= val($agent,'coll_bc_qty') ?>"></label>
              <label class="grow">Front Design <input type="text" name="coll_bc_front" class="form-input" value="<?= val($agent,'coll_bc_front') ?>"></label>
              <label class="grow">Back Design <input type="text" name="coll_bc_back" class="form-input" value="<?= val($agent,'coll_bc_back') ?>"></label>
            </div>
            <div class="chk-row">
              <?php foreach (['coll_bc_chk_design'=>'Design','coll_bc_chk_proof'=>'Proof','coll_bc_chk_accounting'=>'Accounting','coll_bc_chk_ordered'=>'Ordered','coll_bc_chk_delivered'=>'Delivered'] as $f=>$l): ?>
                <label><input type="hidden" name="<?= $f ?>" value="0"><input type="checkbox" name="<?= $f ?>" value="1" <?= $agent[$f]?'checked':'' ?>> <?= $l ?></label>
              <?php endforeach; ?>
            </div>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-bc')">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <!-- Yard Signs -->
      <div class="coll-section">
        <div class="coll-header">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <rect x="3" y="3" width="18" height="12" rx="1.5"/>
            <line x1="12" y1="15" x2="12" y2="21"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
          </svg>
          <span class="coll-title">Yard Signs</span>
          <button class="section-edit-btn" type="button" onclick="toggleInline('edit-ys')"><?= $agent['coll_yard_signs'] ? 'Edit' : 'Add' ?></button>
        </div>
        <?php if ($agent['coll_yard_signs']): ?>
          <div class="coll-detail"><?= $agent['coll_ys_qty']?'Qty: '.htmlspecialchars($agent['coll_ys_qty']).'&nbsp;&nbsp;':'' ?><?= $agent['coll_ys_design']?'Design: '.htmlspecialchars($agent['coll_ys_design']):'' ?><?= $agent['coll_ys_info']?'<br>'.htmlspecialchars($agent['coll_ys_info']):'' ?></div>
        <?php endif; ?>
        <?php coll_orders_block('yard_signs', $coll_orders['yard_signs'] ?? [], $id, $order_status_labels, $order_status_colors); ?>
        <div id="edit-ys" style="display:none;" class="inline-edit-form">
          <form method="POST">
            <input type="hidden" name="_action" value="update_intake_fields">
            <input type="hidden" name="redirect_tab" value="collateral">
            <div class="form-row" style="margin-bottom:8px;">
              <label style="flex-direction:row;align-items:center;gap:6px;font-weight:normal;font-size:13px;cursor:pointer;">
                <input type="hidden" name="coll_yard_signs" value="0">
                <input type="checkbox" name="coll_yard_signs" value="1" <?= $agent['coll_yard_signs']?'checked':'' ?>> Requested
              </label>
            </div>
            <div class="form-row">
              <label>Qty <input type="text" name="coll_ys_qty" class="form-input" value="<?= val($agent,'coll_ys_qty') ?>"></label>
              <label class="grow">Design <input type="text" name="coll_ys_design" class="form-input" value="<?= val($agent,'coll_ys_design') ?>"></label>
              <label class="grow">Notes <input type="text" name="coll_ys_info" class="form-input" value="<?= val($agent,'coll_ys_info') ?>"></label>
            </div>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-ys')">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <!-- Open House Signs -->
      <div class="coll-section">
        <div class="coll-header">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <path d="M3 7h12l4 4-4 4H3V7z"/>
            <line x1="10" y1="15" x2="10" y2="21"/>
          </svg>
          <span class="coll-title">Open House Signs</span>
          <button class="section-edit-btn" type="button" onclick="toggleInline('edit-oh')"><?= $agent['coll_oh_signs'] ? 'Edit' : 'Add' ?></button>
        </div>
        <?php if ($agent['coll_oh_signs']): ?>
          <div class="coll-detail"><?= $agent['coll_oh_qty']?'Qty: '.htmlspecialchars($agent['coll_oh_qty']):'' ?><?= $agent['coll_oh_qr_code']?' &mdash; QR: '.($agent['coll_oh_qr_url']?'<a href="'.htmlspecialchars($agent['coll_oh_qr_url']).'" target="_blank">'.htmlspecialchars($agent['coll_oh_qr_url']).'</a>':'Yes'):'' ?><?= $agent['coll_oh_info']?'<br>'.htmlspecialchars($agent['coll_oh_info']):'' ?></div>
        <?php endif; ?>
        <?php coll_orders_block('oh_signs', $coll_orders['oh_signs'] ?? [], $id, $order_status_labels, $order_status_colors); ?>
        <div id="edit-oh" style="display:none;" class="inline-edit-form">
          <form method="POST">
            <input type="hidden" name="_action" value="update_intake_fields">
            <input type="hidden" name="redirect_tab" value="collateral">
            <div class="form-row" style="margin-bottom:8px;">
              <label style="flex-direction:row;align-items:center;gap:6px;font-weight:normal;font-size:13px;cursor:pointer;">
                <input type="hidden" name="coll_oh_signs" value="0">
                <input type="checkbox" name="coll_oh_signs" value="1" <?= $agent['coll_oh_signs']?'checked':'' ?>> Requested
              </label>
            </div>
            <div class="form-row">
              <label>Qty <input type="text" name="coll_oh_qty" class="form-input" value="<?= val($agent,'coll_oh_qty') ?>"></label>
              <label class="grow">Notes <input type="text" name="coll_oh_info" class="form-input" value="<?= val($agent,'coll_oh_info') ?>"></label>
            </div>
            <div class="chk-row" style="margin-bottom:8px;">
              <label><input type="hidden" name="coll_oh_qr_code" value="0"><input type="checkbox" name="coll_oh_qr_code" value="1" <?= $agent['coll_oh_qr_code']?'checked':'' ?>> Include QR Code</label>
            </div>
            <div class="form-row">
              <label class="grow">QR Code URL <input type="url" name="coll_oh_qr_url" class="form-input" value="<?= val($agent,'coll_oh_qr_url') ?>" placeholder="https://…"></label>
            </div>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-oh')">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <!-- Postcards -->
      <div class="coll-section">
        <div class="coll-header"><i class="ti ti-mail" style="font-size:16px;color:#6b7280;"></i><span class="coll-title">Postcards</span>
          <button class="section-edit-btn" type="button" onclick="toggleInline('edit-pc')"><?= $agent['coll_postcards'] ? 'Edit' : 'Add' ?></button>
        </div>
        <?php if ($agent['coll_postcards']): ?>
          <?php if ($agent['coll_pc_listing']||$agent['coll_pc_target_area']): ?>
            <div class="coll-detail"><?= $agent['coll_pc_listing']?'Listing: '.htmlspecialchars($agent['coll_pc_listing']).'&nbsp;&nbsp;':'' ?><?= $agent['coll_pc_target_area']?'<br>Target: '.htmlspecialchars($agent['coll_pc_target_area']):'' ?></div>
          <?php endif; ?>
          <div class="steps">
            <?= step((bool)$agent['coll_pc_chk_design'],'Design') ?>
            <?= step((bool)$agent['coll_pc_chk_proof'],'Proof') ?>
            <?= step((bool)$agent['coll_pc_chk_accounting'],'Accounting') ?>
            <?= step((bool)$agent['coll_pc_chk_ordered'],'Ordered') ?>
            <?= step((bool)$agent['coll_pc_chk_delivered'],'Delivered') ?>
          </div>
        <?php endif; ?>
        <?php coll_orders_block('postcards', $coll_orders['postcards'] ?? [], $id, $order_status_labels, $order_status_colors); ?>
        <div id="edit-pc" style="display:none;" class="inline-edit-form">
          <form method="POST">
            <input type="hidden" name="_action" value="update_intake_fields">
            <input type="hidden" name="redirect_tab" value="collateral">
            <div class="form-row" style="margin-bottom:8px;">
              <label style="flex-direction:row;align-items:center;gap:6px;font-weight:normal;font-size:13px;cursor:pointer;">
                <input type="hidden" name="coll_postcards" value="0">
                <input type="checkbox" name="coll_postcards" value="1" <?= $agent['coll_postcards']?'checked':'' ?>> Requested
              </label>
            </div>
            <div class="form-row">
              <label class="grow">Listing <input type="text" name="coll_pc_listing" class="form-input" value="<?= val($agent,'coll_pc_listing') ?>"></label>
              <label class="grow">Target Area <input type="text" name="coll_pc_target_area" class="form-input" value="<?= val($agent,'coll_pc_target_area') ?>"></label>
            </div>
            <div class="chk-row">
              <?php foreach (['coll_pc_chk_design'=>'Design','coll_pc_chk_proof'=>'Proof','coll_pc_chk_accounting'=>'Accounting','coll_pc_chk_ordered'=>'Ordered','coll_pc_chk_delivered'=>'Delivered'] as $f=>$l): ?>
                <label><input type="hidden" name="<?= $f ?>" value="0"><input type="checkbox" name="<?= $f ?>" value="1" <?= $agent[$f]?'checked':'' ?>> <?= $l ?></label>
              <?php endforeach; ?>
            </div>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-pc')">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <!-- Brochures -->
      <div class="coll-section">
        <div class="coll-header"><i class="ti ti-file-text" style="font-size:16px;color:#6b7280;"></i><span class="coll-title">Brochures</span>
          <button class="section-edit-btn" type="button" onclick="toggleInline('edit-br')"><?= $agent['coll_brochures'] ? 'Edit' : 'Add' ?></button>
        </div>
        <?php if ($agent['coll_brochures']): ?>
          <?php if ($agent['coll_br_listing']): ?><div class="coll-detail">Listing: <?= htmlspecialchars($agent['coll_br_listing']) ?></div><?php endif; ?>
          <div class="steps">
            <?= step((bool)$agent['coll_br_chk_design'],'Design') ?>
            <?= step((bool)$agent['coll_br_chk_proof'],'Proof') ?>
            <?= step((bool)$agent['coll_br_chk_created'],'Created') ?>
          </div>
        <?php endif; ?>
        <?php coll_orders_block('brochures', $coll_orders['brochures'] ?? [], $id, $order_status_labels, $order_status_colors); ?>
        <div id="edit-br" style="display:none;" class="inline-edit-form">
          <form method="POST">
            <input type="hidden" name="_action" value="update_intake_fields">
            <input type="hidden" name="redirect_tab" value="collateral">
            <div class="form-row" style="margin-bottom:8px;">
              <label style="flex-direction:row;align-items:center;gap:6px;font-weight:normal;font-size:13px;cursor:pointer;">
                <input type="hidden" name="coll_brochures" value="0">
                <input type="checkbox" name="coll_brochures" value="1" <?= $agent['coll_brochures']?'checked':'' ?>> Requested
              </label>
            </div>
            <div class="form-row">
              <label class="grow">Listing <input type="text" name="coll_br_listing" class="form-input" value="<?= val($agent,'coll_br_listing') ?>"></label>
            </div>
            <div class="chk-row">
              <?php foreach (['coll_br_chk_design'=>'Design','coll_br_chk_proof'=>'Proof','coll_br_chk_created'=>'Created'] as $f=>$l): ?>
                <label><input type="hidden" name="<?= $f ?>" value="0"><input type="checkbox" name="<?= $f ?>" value="1" <?= $agent[$f]?'checked':'' ?>> <?= $l ?></label>
              <?php endforeach; ?>
            </div>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-br')">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <!-- Other -->
      <div class="coll-section" style="border-bottom:none;">
        <div class="coll-header"><i class="ti ti-dots" style="font-size:16px;color:#6b7280;"></i><span class="coll-title">Other</span>
          <button class="section-edit-btn" type="button" onclick="toggleInline('edit-co')"><?= $agent['coll_other'] ? 'Edit' : 'Add' ?></button>
        </div>
        <?php if ($agent['coll_other']): ?>
          <div class="coll-detail"><?= htmlspecialchars($agent['coll_other']) ?></div>
        <?php endif; ?>
        <?php coll_orders_block('other', $coll_orders['other'] ?? [], $id, $order_status_labels, $order_status_colors); ?>
        <div id="edit-co" style="display:none;" class="inline-edit-form">
          <form method="POST">
            <input type="hidden" name="_action" value="update_intake_fields">
            <input type="hidden" name="redirect_tab" value="collateral">
            <div class="form-row">
              <label class="grow">Notes
                <textarea name="coll_other" class="form-input" rows="3" placeholder="Any other collateral items…"><?= htmlspecialchars($agent['coll_other'] ?? '') ?></textarea>
              </label>
            </div>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-co')">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════ ADVERTISING ══ -->
  <div class="tab-panel <?= $active_tab==='advertising'?'active':'' ?>" id="tab-advertising">
    <div class="card">

      <?php // The flex rules that were inline here now live in .adv-head, in
            // the stylesheet, unchanged. They had to move: an inline
            // align-items beats any rule in a media query, so the mobile block
            // could not have stacked this row while it stayed on the element. ?>
      <div class="adv-head">
        <div class="card-title" style="margin:0;">Advertising Placements</div>
        <button type="button" class="btn btn-primary btn-sm" onclick="toggleInline('add-campaign-panel')">
          <i class="ti ti-plus"></i> Add Placement
        </button>
      </div>

      <!-- ── Add Placement (collapsed until the button above is clicked) ── -->
      <div class="add-form" id="add-campaign-panel" style="display:none;margin-bottom:18px;">
        <h4>Add Advertising Placement</h4>
        <form method="POST" id="adForm">
          <input type="hidden" name="_action" value="add_campaign">
          <div class="form-row">
            <label>Publication / Platform
              <select name="platform" class="form-input" required>
                <option value="">— select —</option>
                <?php foreach ($platform_labels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="grow">Ad / Campaign Name
              <input type="text" name="name" class="form-input" placeholder="e.g. Full Page — Aug 2026">
            </label>
            <label>Budget <input type="number" name="budget" class="form-input" placeholder="0.00" step="0.01" min="0"></label>
            <label>Billing
              <select name="billing_mode" class="form-input js-billing-mode">
                <option value="one_time">One-time — whole budget in the start month</option>
                <option value="monthly_flat">Monthly — same amount, ongoing</option>
                <option value="monthly">Monthly — a different amount each month</option>
                <option value="per_unit">Per day — rate × days that month</option>
              </select>
            </label>
          </div>
          <?php mk_unit_fields(); ?>
          <div class="form-row">
            <label>Run Start <input type="date" name="start_date" class="form-input"></label>
            <label>Run End   <input type="date" name="end_date"   class="form-input"></label>
            <label class="grow">Notes <textarea name="notes" class="form-input" rows="2" placeholder="Any notes about this placement…"></textarea></label>
          </div>

          <?php // Paste-first. Most placements reuse a URL from an email or a
                // previous campaign, and forcing those through the builder meant
                // rebuilding a URL that already existed. The builder is still
                // here, one click away, for when it earns its keep. ?>
          <div class="form-row" style="align-items:flex-end;">
            <label class="grow">Target URL
              <input type="url" name="target_url" id="targetUrlInput" class="form-input"
                     placeholder="https://monthaus.com/… — paste one, or build it below">
            </label>
            <button type="button" class="btn btn-outline btn-sm" id="toggleUrlBuilder">
              <i class="ti ti-tools"></i> Build with UTMs
            </button>
          </div>

          <!-- URL Builder -->
          <div class="url-builder" id="urlBuilder" style="display:none;">
            <div class="url-builder-title"><i class="ti ti-link"></i> Target URL Builder</div>
            <div class="form-row" style="margin-bottom:8px;">
              <label>Destination
                <select id="urlPathType" class="form-input" style="min-width:130px;">
                  <option value="home">Homepage</option>
                  <option value="listing">Agent Listing</option>
                  <option value="custom">Custom path</option>
                </select>
              </label>
              <label class="grow" id="listingSelectWrap" style="display:none;">Listing
                <select id="urlListingSelect" class="form-input"></select>
                <span class="hint" style="display:block;margin-top:4px;">Links to the listing on the new website. Until it goes live that is the password-protected preview, so for an ad running now paste the listing's current monthaus.com link instead.</span>
              </label>
              <label class="grow" id="customPathWrap" style="display:none;">Path after monthaus.com/
                <input type="text" id="urlCustomPath" class="form-input" placeholder="e.g. our-agents">
              </label>
            </div>
            <div class="form-row" style="margin-bottom:8px;">
              <label>UTM Source <input type="text" id="utm_source" name="utm_source" class="form-input" placeholder="vail_daily"></label>
              <label>UTM Medium <input type="text" id="utm_medium" name="utm_medium" class="form-input" placeholder="print"></label>
              <label>UTM Campaign <input type="text" id="utm_campaign" name="utm_campaign" class="form-input" placeholder="summer_2026"></label>
              <label>UTM Content <input type="text" id="utm_content" name="utm_content" class="form-input" placeholder="full_page"></label>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="url-preview" id="urlPreview">https://monthaus.com/</div>
              <?php // Explicit, never automatic. The builder used to write into
                    // the target field on every keystroke, which would now wipe
                    // out a URL that had just been pasted. ?>
              <button type="button" id="useUrlBtn" class="btn btn-primary btn-xs" title="Put this in the Target URL field">
                <i class="ti ti-arrow-up"></i> Use this
              </button>
              <button type="button" id="copyUrlBtn" class="btn btn-outline btn-xs" title="Copy URL"><i class="ti ti-copy"></i> Copy</button>
            </div>
          </div>

          <!-- Ads / creatives — add as many as needed, each with its own target URL -->
          <div class="ads-builder">
            <div class="url-builder-title" style="margin-bottom:8px;">
              <i class="ti ti-photo"></i> Ads / Creatives
              <small style="font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0;">
                — leave a target blank to use the placement landing page above
              </small>
            </div>
            <div id="adRows"></div>
            <button type="button" class="btn btn-outline btn-xs" id="addAdRowBtn" style="margin-top:6px;">
              <i class="ti ti-plus"></i> Add another ad
            </button>
          </div>

          <?php mk_who_pays_fields([], 'campnew', 'budget'); ?>

          <?php // One line, on the same row as the buttons. It was a nested
                // label with its own uppercase heading, which gave a single
                // checkbox three lines and its own section. ?>
          <div class="btn-row" style="margin-top:14px;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-check"></i> Save Placement</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('add-campaign-panel')">Cancel</button>
            <label class="chk-inline">
              <input type="checkbox" name="sent" value="1"> Already sent to media
            </label>
          </div>
        </form>
      </div>

      <?php if (empty($campaigns)): ?>
        <div class="no-campaigns"><i class="ti ti-speakerphone" style="font-size:28px;display:block;margin-bottom:8px;color:#d1d5db;"></i>No advertising placements yet. Click “Add Placement” to create one.</div>
      <?php else: ?>
        <?php foreach ($campaigns as $c):
          $csc     = $campaign_status_colors[$c['status']] ?? '#9ca3af';
          $csl     = $campaign_status_labels[$c['status']] ?? $c['status'];
          $is_sent = !empty($c['sent']);

          // Unified ad-file list: legacy ad_file_url column + rows from
          // marketing_campaign_assets. Legacy entries are flagged so their
          // remove button clears the column instead of deleting an asset row.
          $ad_files = [];
          if (!empty($c['ad_file_url'])) {
              $ad_files[] = ['id' => null, 'label' => 'Ad file', 'url' => $c['ad_file_url'],
                             'target' => '', 'legacy' => true];
          }
          foreach (($c['assets'] ?? []) as $as) {
              // target_url is null-coalesced so the page still renders if
              // campaign_asset_targets.sql hasn't been run yet.
              $ad_files[] = ['id'     => $as['id'],
                             'label'  => $as['label'] ?: 'Ad file',
                             'url'    => $as['file_url'] ?? '',
                             'target' => $as['target_url'] ?? '',
                             'legacy' => false];
          }
        ?>
        <div class="campaign-card" id="camp-<?= (int)$c['id'] ?>">
          <div class="campaign-header">
            <div style="flex:1;min-width:0;">
              <div class="campaign-platform"><?= htmlspecialchars($platform_labels[$c['platform']] ?? $c['platform']) ?></div>
              <?php if ($c['name']): ?><div class="campaign-name"><?= htmlspecialchars($c['name']) ?></div><?php endif; ?>
            </div>
            <span class="camp-status-chip" style="background:<?= $csc ?>;"><?= $csl ?></span>

          </div>

          <?php
            // Resolve the whole run once, here, and reuse it for the chip and
            // the grid below. The run total is the SUM OF THE ENTERED MONTHS —
            // never an amount × a month count, which was never true and is not
            // even expressible now that each month carries its own figure.
            $_mode = $c['billing_mode'] ?: 'one_time';
            $_rec  = in_array($_mode, ['monthly','monthly_flat','per_unit'], true);
            $_covs = $camp_overrides[(int)$c['id']] ?? [];
            $_ms   = $_rec ? mk_campaign_months($c['start_date'] ?: null, $c['end_date'] ?: null) : [];
            $_res  = [];                       // ym => resolved array, or null when the run misses the month
            $_tot  = 0.0; $_billed = 0; $_open = 0;
            foreach ($_ms as $_ym) {
                $_r = mk_camp_resolve($c, $_ym, $_covs);
                $_res[$_ym] = $_r;
                if ($_r === null)      continue;
                if (!$_r['set'])     { $_open++;  continue; }   // awaiting a figure
                $_tot += (float)$_r['amount'];
                $_billed++;
            }
          ?>
          <div class="campaign-details">
            <?php if ($_mode === 'per_unit'): ?>
              <?php if ($c['unit_rate']): ?>
                <span>Rate: <strong><?= money2((float)$c['unit_rate']) ?></strong> /
                  <?= htmlspecialchars($c['unit_label'] ?: 'day') ?></span>
              <?php endif; ?>
            <?php elseif ($c['budget']): ?>
              <?php // Three different meanings, three different words. "Budget"
                    // on a monthly placement was what made it read as the figure
                    // that bills when it was only a default. ?>
              <span><?= $_mode === 'monthly_flat' ? 'Standing' : ($_mode === 'monthly' ? 'Usual' : 'Budget') ?>:
                <strong><?= money((float)$c['budget']) ?></strong><?= in_array($_mode, ['monthly','monthly_flat'], true) ? ' / month' : '' ?></span>
            <?php endif; ?>

            <?php if ($_rec): ?>
              <span class="camp-monthly-chip">
                <?php if ($_mode === 'per_unit'): ?>
                  <i class="ti ti-calendar-event"></i>
                  Every <?= ($c['unit_weekday'] !== null && $c['unit_weekday'] !== '')
                             ? htmlspecialchars(mk_weekday_names()[(int)$c['unit_weekday']]) : 'month, by quantity' ?>
                <?php elseif ($_mode === 'monthly_flat'): ?>
                  <i class="ti ti-infinity"></i>
                  <?= empty($c['end_date']) ? 'Ongoing' : 'Monthly' ?>
                <?php else: ?>
                  <i class="ti ti-repeat"></i> Monthly
                <?php endif; ?>
                <?php if ($_billed): ?>
                  · <?= $_billed ?> month<?= $_billed === 1 ? '' : 's' ?><?= $_mode === 'monthly' ? ' entered' : '' ?>
                  · <?= money2($_tot) ?>
                <?php endif; ?>
              </span>
            <?php endif; ?>
            <?php if ($c['start_date']): ?><span>Start: <strong><?= date('m/d/Y', strtotime($c['start_date'])) ?></strong></span><?php endif; ?>
            <?php if ($c['end_date']): ?><span>End: <strong><?= date('m/d/Y', strtotime($c['end_date'])) ?></strong></span><?php endif; ?>
          </div>
          <?php // white-space:pre-wrap so line breaks typed into the textarea survive ?>
          <?php if ($c['notes']): ?><p style="font-size:12px;color:#6b7280;margin:0 0 8px;white-space:pre-wrap;"><?= htmlspecialchars($c['notes']) ?></p><?php endif; ?>
          <?php mk_who_pays_summary($c, 'budget'); ?>

          <!-- Placement target URL -->
          <?php if (!empty($c['target_url'])): ?>
            <?php // No title attribute: hovering anywhere near this row used to
                  // pop a 150-character tracking URL over the rest of the card. ?>
            <div class="link-row">
              <span class="link-row-label"><i class="ti ti-link"></i> Target URL</span>
              <a class="btn btn-outline btn-xs" href="<?= htmlspecialchars($c['target_url']) ?>" target="_blank"><i class="ti ti-external-link"></i> Open</a>
              <button type="button" class="btn btn-outline btn-xs copy-btn"
                      data-copy="<?= htmlspecialchars($c['target_url']) ?>"><i class="ti ti-copy"></i> Copy</button>
            </div>
          <?php endif; ?>

          <!-- Creatives: any number per placement, each with its own target URL -->
          <div class="ad-files">
            <div class="ad-files-title">Creatives <span class="ad-count"><?= count($ad_files) ?></span></div>
            <?php if (!$ad_files): ?>
              <p class="asset-empty" style="margin:0 0 6px;">None yet.</p>
            <?php else: ?>
              <?php // No URLs printed anywhere here, and no URL in a title
                    // attribute either. Six creatives each showed a wrapped
                    // 150-character tracking URL twice over, which buried the
                    // one thing the row is for: the buttons. Open and Copy do
                    // everything the text did. ?>
              <?php foreach ($ad_files as $ci => $af): ?>
                <div class="ad-item">
                  <div class="link-row">
                    <span class="link-row-label">
                      <i class="ti ti-photo"></i> Creative <?= $ci + 1 ?>
                      <?php
                        // Keep any custom label the user typed, but the number leads
                        $lbl = trim((string)$af['label']);
                        if ($lbl !== '' && $lbl !== 'Ad file'):
                      ?>
                        <span class="ad-sublabel"><?= htmlspecialchars($lbl) ?></span>
                      <?php endif; ?>
                    </span>

                    <?php if (!empty($af['url'])): ?>
                      <a class="btn btn-outline btn-xs" href="<?= htmlspecialchars($af['url']) ?>" target="_blank"
                         title="Open the ad file"><i class="ti ti-external-link"></i> Open</a>
                      <button type="button" class="btn btn-outline btn-xs copy-btn" title="Copy the ad file link"
                              data-copy="<?= htmlspecialchars($af['url']) ?>"><i class="ti ti-copy"></i> Copy</button>
                    <?php else: ?>
                      <span class="asset-none">no file</span>
                    <?php endif; ?>

                    <?php // The creative's own landing page, when it has one.
                          // Same row, so six creatives are six lines rather
                          // than twelve. Absent means it inherits the
                          // placement's target, which is the default. ?>
                    <?php if (!empty($af['target'])): ?>
                      <span class="ad-target-sep">Target</span>
                      <a class="btn btn-outline btn-xs" href="<?= htmlspecialchars($af['target']) ?>" target="_blank"
                         title="Open this creative's landing page"><i class="ti ti-external-link"></i></a>
                      <button type="button" class="btn btn-outline btn-xs copy-btn" title="Copy this creative's landing page"
                              data-copy="<?= htmlspecialchars($af['target']) ?>"><i class="ti ti-copy"></i></button>
                    <?php endif; ?>

                    <?php if (!$af['legacy']): ?>
                      <button type="button" class="btn btn-outline btn-xs" title="Edit this creative"
                              onclick="toggleInline('edit-asset-<?= $af['id'] ?>')"><i class="ti ti-pencil"></i> Edit</button>
                    <?php endif; ?>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this creative?')">
                      <?php if ($af['legacy']): ?>
                        <input type="hidden" name="_action" value="clear_campaign_ad_file">
                        <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                      <?php else: ?>
                        <input type="hidden" name="_action" value="delete_campaign_asset">
                        <input type="hidden" name="asset_id" value="<?= $af['id'] ?>">
                      <?php endif; ?>
                      <button type="submit" class="btn-ghost" title="Remove"><i class="ti ti-x"></i></button>
                    </form>
                  </div>

                  <?php if (!$af['legacy']): ?>
                  <div id="edit-asset-<?= $af['id'] ?>" style="display:none;" class="inline-edit-form">
                    <form method="POST">
                      <input type="hidden" name="_action" value="update_campaign_asset">
                      <input type="hidden" name="asset_id" value="<?= $af['id'] ?>">
                      <div class="form-row">
                        <label style="max-width:190px;">Label
                          <input type="text" name="label" class="form-input" value="<?= htmlspecialchars($af['label'] === 'Ad file' ? '' : $af['label']) ?>" placeholder="e.g. Half page">
                        </label>
                        <label class="grow">Ad File URL
                          <input type="text" name="file_url" class="form-input" value="<?= htmlspecialchars($af['url']) ?>" placeholder="https://…">
                        </label>
                      </div>
                      <div class="form-row">
                        <label class="grow">Target URL <small style="font-weight:400;color:#9ca3af;">(leave blank to use the placement target URL)</small>
                          <input type="text" name="target_url" class="form-input" value="<?= htmlspecialchars($af['target']) ?>" placeholder="https://monthaus.com/…">
                        </label>
                      </div>
                      <div class="btn-row">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-check"></i> Save</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-asset-<?= $af['id'] ?>')">Cancel</button>
                      </div>
                    </form>
                  </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <!-- Add Creative — collapsed until asked for, so the card stays readable -->
            <button type="button" class="btn btn-outline btn-sm" style="margin-top:10px;"
                    onclick="toggleInline('add-creative-<?= $c['id'] ?>')">
              <i class="ti ti-plus"></i> Add Creative
            </button>
            <div id="add-creative-<?= $c['id'] ?>" style="display:none;" class="inline-edit-form">
              <form method="POST">
                <input type="hidden" name="_action" value="add_campaign_asset">
                <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                <div class="form-row">
                  <label style="max-width:190px;">Label <small style="font-weight:400;color:#9ca3af;">(optional)</small>
                    <input type="text" name="label" class="form-input" placeholder="e.g. Half page">
                  </label>
                  <label class="grow">Ad File URL
                    <input type="url" name="file_url" class="form-input" placeholder="https://…">
                  </label>
                </div>
                <div class="form-row">
                  <label class="grow">Target URL <small style="font-weight:400;color:#9ca3af;">(leave blank to use the placement target URL)</small>
                    <input type="url" name="target_url" class="form-input" placeholder="https://monthaus.com/…">
                  </label>
                </div>
                <div class="btn-row">
                  <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-check"></i> Add Creative</button>
                  <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('add-creative-<?= $c['id'] ?>')">Cancel</button>
                </div>
              </form>
            </div>
          </div>


          <div class="camp-actions">
            <form method="POST" style="margin:0;display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="_action" value="update_campaign_status">
              <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
              <select name="status" class="form-input" style="padding:4px 8px;font-size:12px;" onchange="this.form.submit()">
                <?php foreach ($campaign_status_labels as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= $c['status']===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <form method="POST" style="margin:0;">
              <input type="hidden" name="_action" value="toggle_campaign_sent">
              <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
              <input type="hidden" name="sent" value="<?= $is_sent ? 0 : 1 ?>">
              <button type="submit" class="btn btn-outline btn-xs">
                <?= $is_sent ? '<i class="ti ti-x"></i> Unmark Sent' : '<i class="ti ti-check"></i> Mark Sent' ?>
              </button>
            </form>
            <button type="button" class="btn btn-outline btn-xs" onclick="toggleInline('edit-camp-<?= $c['id'] ?>')">
              <i class="ti ti-pencil"></i> Edit
            </button>
            <?php // Same buy, new flight. The copy arrives with its dates blank
                  // and its edit form open, so the next thing on screen is the
                  // two fields that actually change. ?>
            <form method="POST" style="margin:0;">
              <input type="hidden" name="_action" value="duplicate_campaign">
              <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-outline btn-xs" title="Copy this placement and its creatives into a new placement">
                <i class="ti ti-copy"></i> Duplicate
              </button>
            </form>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this placement and all its ad files?')">
              <input type="hidden" name="_action" value="delete_campaign">
              <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-danger btn-xs"><i class="ti ti-trash"></i> Delete</button>
            </form>
          </div>

          <!-- Edit placement -->
          <?php // ?edit_camp=N (set by the Duplicate redirect) opens this one form.
                // Done server-side rather than in JS so the fields are already
                // there when the hash handler scrolls to them. ?>
          <?php $_open_edit = (int)($_GET['edit_camp'] ?? 0) === (int)$c['id']; ?>
          <div id="edit-camp-<?= $c['id'] ?>"<?= $_open_edit ? '' : ' style="display:none;"' ?> class="inline-edit-form">
            <form method="POST">
              <input type="hidden" name="_action" value="update_campaign">
              <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
              <div class="form-row">
                <label>Publication / Platform
                  <select name="platform" class="form-input" required>
                    <?php foreach ($platform_labels as $v=>$l): ?>
                      <option value="<?= $v ?>" <?= $c['platform']===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="grow">Ad / Campaign Name
                  <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($c['name'] ?? '') ?>">
                </label>
                <label>Budget
                  <input type="number" name="budget" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($c['budget'] ?? '') ?>">
                </label>
                <label>Billing
                  <?php $_mode = $c['billing_mode'] ?: 'one_time'; ?>
                  <select name="billing_mode" class="form-input js-billing-mode">
                    <option value="one_time" <?= $_mode === 'one_time' ? 'selected' : '' ?>>One-time — whole budget in the start month</option>
                    <option value="monthly_flat" <?= $_mode === 'monthly_flat' ? 'selected' : '' ?>>Monthly — same amount, ongoing</option>
                    <option value="monthly"  <?= $_mode === 'monthly'  ? 'selected' : '' ?>>Monthly — a different amount each month</option>
                    <option value="per_unit" <?= $_mode === 'per_unit' ? 'selected' : '' ?>>Per day — rate × days that month</option>
                  </select>
                </label>
              </div>
              <?php mk_unit_fields($c); ?>
              <div class="form-row">
                <label>Run Start <input type="date" name="start_date" class="form-input" value="<?= htmlspecialchars($c['start_date'] ?? '') ?>"></label>
                <label>Run End   <input type="date" name="end_date"   class="form-input" value="<?= htmlspecialchars($c['end_date'] ?? '') ?>"></label>
                <label class="grow">Notes <textarea name="notes" class="form-input" rows="2" placeholder="Any notes about this placement…"><?= htmlspecialchars($c['notes'] ?? '') ?></textarea></label>
              </div>
              <div class="form-row">
                <label class="grow">Target URL
                  <input type="text" name="target_url" class="form-input" value="<?= htmlspecialchars($c['target_url'] ?? '') ?>" placeholder="https://monthaus.com/…">
                </label>
              </div>
              <div class="form-row">
                <label>UTM Source   <input type="text" name="utm_source"   class="form-input" value="<?= htmlspecialchars($c['utm_source']   ?? '') ?>"></label>
                <label>UTM Medium   <input type="text" name="utm_medium"   class="form-input" value="<?= htmlspecialchars($c['utm_medium']   ?? '') ?>"></label>
                <label>UTM Campaign <input type="text" name="utm_campaign" class="form-input" value="<?= htmlspecialchars($c['utm_campaign'] ?? '') ?>"></label>
                <label>UTM Content  <input type="text" name="utm_content"  class="form-input" value="<?= htmlspecialchars($c['utm_content']  ?? '') ?>"></label>
              </div>
              <?php mk_who_pays_fields($c, 'camp' . (int)$c['id'], 'budget'); ?>
              <div class="btn-row">
                <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-check"></i> Save</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-camp-<?= $c['id'] ?>')">Cancel</button>
              </div>
            </form>
          </div>

          <?php // ── Month-by-month, for a recurring placement ──────────────
                // A sibling of the edit form, never a child: nested <form>
                // elements are invalid HTML and the browser drops the inner
                // one, so this grid would post nothing. ?>
          <?php if ($_rec && $_ms) mk_month_grid($c, $_ms, $_covs, $_res, $_tot); ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>

  <!-- ══════════════════════════════════════════════ ASSETS ══ -->
  <div class="tab-panel <?= $active_tab==='assets'?'active':'' ?>" id="tab-assets">

    <!-- ── Files & Links ────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title" style="display:flex;align-items:center;">
        <i class="ti ti-link" style="margin-right:6px;"></i> Files &amp; Links
        <button class="section-edit-btn" type="button" onclick="toggleInline('edit-files')">Edit</button>
      </div>
      <?php
      $file_links = [
        ['label' => 'QR Code',        'val' => $agent['qr_code_url']        ?? '', 'icon' => 'ti-qrcode'],
        ['label' => 'Email Signature','val' => $agent['email_signature_url'] ?? '', 'icon' => 'ti-signature'],
      ];
      $any_file_link = false;
      foreach ($file_links as $fl) if (!empty($fl['val'])) $any_file_link = true;
      ?>
      <?php if ($any_file_link): ?>
        <div class="asset-link-list">
          <?php foreach ($file_links as $fl): if (empty($fl['val'])) continue; ?>
            <div class="asset-link-row">
              <i class="ti <?= $fl['icon'] ?>"></i>
              <span class="asset-link-label"><?= $fl['label'] ?></span>
              <a href="<?= htmlspecialchars($fl['val']) ?>" target="_blank" class="asset-link-a">
                <i class="ti ti-external-link"></i> Open
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="asset-empty">No files linked yet.</p>
      <?php endif; ?>

      <div id="edit-files" style="display:none;" class="inline-edit-form">
        <form method="POST">
          <input type="hidden" name="_action" value="update_intake_fields">
          <input type="hidden" name="redirect_tab" value="assets">
          <div class="fld">
            <span class="fld-label">QR Code</span>
            <input type="url" name="qr_code_url" class="form-input" style="max-width:420px;"
                   value="<?= val($agent,'qr_code_url') ?>" placeholder="https://… (SVG or shared link)">
          </div>
          <div class="fld">
            <span class="fld-label">Email Signature</span>
            <input type="url" name="email_signature_url" class="form-input" style="max-width:420px;"
                   value="<?= val($agent,'email_signature_url') ?>" placeholder="https://…">
          </div>
          <div class="btn-row">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-files')">Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Headshot ─────────────────────────────────────────────────────── -->
    <?php
    $ps_skip   = !empty($agent['photoshoot_not_needed']);
    $ps_fields = [
      'photoshoot_photographer'  => 'Photographer',
      'headshot_photoshoot_date' => 'Shoot date',
      'photoshoot_proofs_date'   => 'Proofs delivered',
      'photoshoot_finals_date'   => 'Finals delivered',
    ];
    $has_ps_data = false;
    foreach (array_keys($ps_fields) as $k) if (!empty($agent[$k])) $has_ps_data = true;
    ?>
    <div class="card">
      <div class="card-title" style="display:flex;align-items:center;">
        <i class="ti ti-photo" style="margin-right:6px;"></i> Headshot
        <button class="section-edit-btn" type="button" onclick="toggleInline('edit-headshot')">Edit</button>
      </div>

      <!-- Display -->
      <div class="asset-view">
        <div class="asset-link-row">
          <i class="ti ti-user-circle"></i>
          <span class="asset-link-label">Final Headshot(s)</span>
          <?php if (!empty($agent['headshot_url'])): ?>
            <a href="<?= val($agent,'headshot_url') ?>" target="_blank" class="asset-link-a">
              <i class="ti ti-external-link"></i> Open
            </a>
          <?php else: ?>
            <span class="asset-none">—</span>
          <?php endif; ?>
        </div>

        <div class="subsec">
          <div class="subsec-title">Photoshoot</div>
          <?php if ($ps_skip): ?>
            <p class="asset-empty" style="margin:0;">Not needed.</p>
          <?php elseif ($has_ps_data): ?>
            <div class="kv-grid">
              <?php foreach ($ps_fields as $k => $lbl): if (empty($agent[$k])) continue; ?>
                <div class="kv-k"><?= $lbl ?></div>
                <div class="kv-v">
                  <?= str_ends_with($k, '_date') || $k === 'headshot_photoshoot_date'
                        ? date('M j, Y', strtotime($agent[$k]))
                        : htmlspecialchars($agent[$k]) ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="asset-empty" style="margin:0;">Nothing scheduled yet.</p>
          <?php endif; ?>
        </div>

        <?php if (!empty($agent['headshot_notes'])): ?>
          <div class="subsec">
            <div class="subsec-title">Notes</div>
            <div class="asset-notes"><?= nl2br(htmlspecialchars($agent['headshot_notes'])) ?></div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Edit -->
      <div id="edit-headshot" style="display:none;" class="inline-edit-form">
        <form method="POST">
          <input type="hidden" name="_action" value="update_intake_fields">
          <input type="hidden" name="redirect_tab" value="assets">

          <div class="fld">
            <span class="fld-label">Final Headshot(s)</span>
            <input type="url" name="headshot_url" class="form-input" style="max-width:420px;"
                   value="<?= val($agent,'headshot_url') ?>" placeholder="https://… (folder or file link)">
          </div>

          <div class="subsec edit">
            <div class="subsec-title">Photoshoot</div>
            <label class="ps-chk">
              <input type="hidden" name="photoshoot_not_needed" value="0">
              <input type="checkbox" name="photoshoot_not_needed" value="1" id="psNotNeeded"
                     <?= $ps_skip?'checked':'' ?>>
              <span>Not needed</span>
            </label>
            <div id="psFields" class="<?= $ps_skip?'ps-dim':'' ?>">
              <div class="fld">
                <span class="fld-label">Photographer</span>
                <input type="text" name="photoshoot_photographer" class="form-input" style="max-width:300px;"
                       value="<?= val($agent,'photoshoot_photographer') ?>" placeholder="Name">
              </div>
              <div class="fld">
                <span class="fld-label">Shoot date</span>
                <input type="date" name="headshot_photoshoot_date" class="form-input" style="max-width:180px;"
                       value="<?= val($agent,'headshot_photoshoot_date') ?>">
              </div>
              <div class="fld">
                <span class="fld-label">Proofs delivered</span>
                <input type="date" name="photoshoot_proofs_date" class="form-input" style="max-width:180px;"
                       value="<?= val($agent,'photoshoot_proofs_date') ?>">
              </div>
              <div class="fld">
                <span class="fld-label">Finals delivered</span>
                <input type="date" name="photoshoot_finals_date" class="form-input" style="max-width:180px;"
                       value="<?= val($agent,'photoshoot_finals_date') ?>">
              </div>
            </div>
          </div>

          <div class="fld" style="align-items:flex-start;">
            <span class="fld-label" style="padding-top:8px;">Notes</span>
            <textarea name="headshot_notes" class="form-input" rows="3" style="max-width:520px;"
                      placeholder="Any notes…"><?= htmlspecialchars($agent['headshot_notes'] ?? '') ?></textarea>
          </div>

          <div class="btn-row">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-headshot')">Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Other Docs ───────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title" style="display:flex;align-items:center;">
        <i class="ti ti-paperclip" style="margin-right:6px;"></i> Other Docs
        <button class="section-edit-btn" type="button" onclick="toggleInline('edit-assets-other')">Edit</button>
      </div>

      <?php if (!empty($agent['assets_cv_url']) || $asset_links): ?>
        <div class="asset-link-list">
          <?php if (!empty($agent['assets_cv_url'])): ?>
            <div class="asset-link-row">
              <i class="ti ti-file-cv"></i>
              <span class="asset-link-label">CV / Résumé</span>
              <a href="<?= htmlspecialchars($agent['assets_cv_url']) ?>" target="_blank" class="asset-link-a">
                <i class="ti ti-external-link"></i> Open
              </a>
            </div>
          <?php endif; ?>
          <?php foreach ($asset_links as $al): ?>
            <div class="asset-link-row">
              <i class="ti ti-link"></i>
              <span class="asset-link-label"><?= htmlspecialchars($al['label']) ?></span>
              <a href="<?= htmlspecialchars($al['url']) ?>" target="_blank" class="asset-link-a">
                <i class="ti ti-external-link"></i> Open
              </a>
              <form method="POST" style="margin:0 0 0 auto;" onsubmit="return confirm('Remove this link?')">
                <input type="hidden" name="_action" value="delete_asset_link">
                <input type="hidden" name="link_id" value="<?= $al['id'] ?>">
                <button type="submit" class="btn-ghost" title="Remove"><i class="ti ti-x"></i></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="asset-empty">No documents yet.</p>
      <?php endif; ?>

      <?php if (!empty($agent['assets_other_notes'])): ?>
        <div class="asset-notes" style="margin-top:10px;"><?= nl2br(htmlspecialchars($agent['assets_other_notes'])) ?></div>
      <?php endif; ?>

      <!-- Add a one-off link -->
      <form method="POST" class="addlink-row">
        <input type="hidden" name="_action" value="add_asset_link">
        <input type="text" name="label" class="form-input" style="max-width:200px;" placeholder="Label (e.g. Portfolio)" required>
        <input type="url"  name="url"   class="form-input" style="max-width:340px;" placeholder="https://…" required>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Link</button>
      </form>

      <div id="edit-assets-other" style="display:none;" class="inline-edit-form">
        <form method="POST">
          <input type="hidden" name="_action" value="update_intake_fields">
          <input type="hidden" name="redirect_tab" value="assets">
          <div class="fld">
            <span class="fld-label">CV / Résumé</span>
            <input type="url" name="assets_cv_url" class="form-input" style="max-width:420px;"
                   value="<?= val($agent,'assets_cv_url') ?>" placeholder="https://…">
          </div>
          <div class="fld" style="align-items:flex-start;">
            <span class="fld-label" style="padding-top:8px;">Notes</span>
            <textarea name="assets_other_notes" class="form-input" rows="3" style="max-width:520px;"
                      placeholder="Any notes…"><?= htmlspecialchars($agent['assets_other_notes'] ?? '') ?></textarea>
          </div>
          <div class="btn-row">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('edit-assets-other')">Cancel</button>
          </div>
        </form>
      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════════ FINANCIALS ══ -->
  <div class="tab-panel <?= $active_tab==='financials'?'active':'' ?>" id="tab-financials">

    <?php if (!$fin_months): ?>
      <div class="card">
        <div class="card-title">Financial Summary</div>
        <p class="asset-empty">
          Nothing to report yet. Collateral orders and advertising placements appear here
          once they have a cost or budget.
        </p>
      </div>
    <?php else: ?>

      <!-- Running totals across every month below -->
      <div class="card">
        <div class="card-title">Financial Summary</div>
        <?php // Mont Haus / Broker order is the same everywhere on this tab ?>
        <div class="fin-grand">
          <div class="fin-grand-box">
            <div class="fin-grand-label">Total Paid by Mont Haus</div>
            <div class="fin-grand-num mh"><?= money2((float)$fin_grand['mh']) ?></div>
          </div>
          <div class="fin-grand-box">
            <div class="fin-grand-label">Total Billed to Broker</div>
            <div class="fin-grand-num broker"><?= money2((float)$fin_grand['broker']) ?></div>
          </div>
          <?php if ($fin_grand['unassigned'] > 0): ?>
          <div class="fin-grand-box">
            <div class="fin-grand-label">Unassigned</div>
            <div class="fin-grand-num unassigned"><?= money2((float)$fin_grand['unassigned']) ?></div>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($fin_grand['unassigned'] > 0): ?>
          <p class="fin-note">
            <i class="ti ti-alert-triangle"></i>
            Amounts under “Unassigned” have no Who Pays set, so they are excluded from both
            totals. Set Who Pays on those items in Collateral or Advertising to include them.
          </p>
        <?php endif; ?>
      </div>

      <?php foreach ($fin_months as $m): ?>
      <div class="card">
        <div class="fin-month-head">
          <div class="fin-month-name"><?= htmlspecialchars($m['label']) ?></div>
          <?php // Mont Haus first, matching the column order in the table below ?>
          <div class="fin-month-totals">
            <span>Mont Haus <strong class="mh"><?= money2((float)$m['mh']) ?></strong></span>
            <span>Broker <strong class="broker"><?= money2((float)$m['broker']) ?></strong></span>
            <?php if ($m['unassigned'] > 0): ?>
              <span>Unassigned <strong class="unassigned"><?= money2((float)$m['unassigned']) ?></strong></span>
            <?php endif; ?>
          </div>
        </div>

        <table class="fin-table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Type</th>
              <th class="num">Total</th>
              <th class="num">Mont Haus</th>
              <th class="num">Broker</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($m['items'] as $it):
              $unassigned = empty($it['billed_with'])
                            && (($it['paid_by'] ?? null) === null || $it['paid_by'] === '');
              // Advertising rows store the raw platform; resolve the label here
              $sub = $it['kind'] === 'Advertising'
                   ? ($platform_labels[$it['platform']] ?? $it['platform'])
                   : $it['platform'];
              $nm  = $it['name'] !== '' ? $it['name'] : $sub;
              // Jump straight to the item on its own tab. ?tab= sets the active
              // panel server-side, so the #anchor lands on a visible element.
              $ref  = (int)($it['ref_id'] ?? 0);
              $href = $it['kind'] === 'Collateral'
                    ? "agent.php?id={$id}&tab=collateral#order-{$ref}"
                    : "agent.php?id={$id}&tab=advertising#camp-{$ref}";
          ?>
            <tr<?= $unassigned ? ' class="fin-unassigned"' : '' ?>>
              <td>
                <div class="fin-item-name">
                  <?php if ($ref): ?>
                    <a href="<?= htmlspecialchars($href) ?>" class="fin-item-link"
                       title="Open in <?= $it['kind'] === 'Collateral' ? 'Collateral' : 'Advertising' ?>">
                      <?= htmlspecialchars($nm) ?>
                    </a>
                  <?php else: ?>
                    <?= htmlspecialchars($nm) ?>
                  <?php endif; ?>
                </div>
                <?php if ($sub !== '' && $sub !== $nm): ?>
                  <div class="fin-item-sub"><?= htmlspecialchars($sub) ?></div>
                <?php endif; ?>
                <?php if (($it['mode'] ?? '') === 'per_unit'):
                        $_u = (float)($it['units'] ?? 0);
                        $_r = (float)($it['rate']  ?? 0);
                        $_l = $it['unit_label'] ?: 'day'; ?>
                  <div class="fin-item-sub fin-units">
                    <i class="ti ti-repeat" style="font-size:10px;"></i>
                    <?php if (($it['unit_src'] ?? '') === 'unset'): ?>
                      <span class="fin-needs-qty">quantity not set for this month</span>
                    <?php else: ?>
                      <b><?= mk_num($_u) ?></b>
                      <?= htmlspecialchars($_l) ?><?= $_u == 1.0 ? '' : 's' ?>
                      &times; <b><?= money2($_r) ?></b>
                      <?php if (($it['unit_src'] ?? '') === 'override'): ?>
                        &middot; <span title="typed in, not counted from the calendar">corrected</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                <?php elseif (!empty($it['recurring'])): ?>
                  <div class="fin-item-sub fin-recurring">
                    <i class="ti ti-repeat" style="font-size:10px;"></i> monthly
                  </div>
                <?php endif; ?>

                <?php // A month nobody has entered a figure for. This line is
                      // what the rewrite exists to produce: it bills nothing,
                      // and it says so where the money is being reconciled,
                      // instead of quietly inheriting a number. The link goes
                      // to the grid that fixes it. ?>
                <?php if (!empty($it['recurring']) && isset($it['set']) && !$it['set']): ?>
                  <div class="fin-item-sub fin-needs-qty">
                    <i class="ti ti-alert-circle" style="font-size:10px;"></i>
                    <?= ($it['mode'] ?? '') === 'per_unit' ? 'nothing billed' : 'amount not set — nothing billed' ?>
                    &middot;
                    <a class="fin-item-link" href="agent.php?id=<?= $id ?>&amp;tab=advertising#camp-<?= $ref ?>">enter it</a>
                  </div>
                <?php elseif (!empty($it['zeroed'])): ?>
                  <div class="fin-item-sub fin-recurring">no charge this month</div>
                <?php endif; ?>

                <?php if (!empty($it['ov_note'])): ?>
                  <div class="fin-item-sub fin-recurring"><?= htmlspecialchars($it['ov_note']) ?></div>
                <?php endif; ?>
                <?php if (!empty($it['billed_with'])): ?>
                  <div class="fin-item-sub fin-billed">
                    <i class="ti ti-link" style="font-size:10px;"></i>
                    billed with <?= htmlspecialchars($it['billed_with']) ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($it['orphaned'])): ?>
                  <div class="fin-item-sub fin-orphan">
                    <i class="ti ti-alert-triangle" style="font-size:10px;"></i>
                    was billed with an order that no longer exists — counted on its own
                  </div>
                <?php endif; ?>
                <?php // The uploaded receipt is the invoice record for a collateral order ?>
                <?php if (!empty($it['receipt'])): ?>
                  <div class="fin-item-sub">
                    <a class="fin-invoice" href="receipt.php?order_id=<?= (int)$it['receipt'] ?>" target="_blank">
                      <i class="ti ti-receipt" style="font-size:10px;"></i> Invoice
                    </a>
                    <a class="fin-invoice" href="receipt.php?order_id=<?= (int)$it['receipt'] ?>&amp;dl=1">
                      <i class="ti ti-download" style="font-size:10px;"></i> Download
                    </a>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="fin-kind <?= $it['kind'] === 'Collateral' ? 'coll' : 'adv' ?>">
                  <?= $it['kind'] ?>
                </span>
              </td>
              <td class="num"><?= !empty($it['billed_with']) ? '<span class="fin-flag">included</span>' : money2((float)$it['total']) ?></td>
              <td class="num"><?= $it['mh']     > 0 ? money2((float)$it['mh'])     : '—' ?></td>
              <td class="num">
                <?php if (!empty($it['billed_with'])): ?>
                  <span class="fin-flag">&mdash;</span>
                <?php elseif ($unassigned): ?>
                  <span class="fin-flag" title="Who Pays not set — excluded from the totals">not set</span>
                <?php else: ?>
                  <?= $it['broker'] > 0 ? money2((float)$it['broker']) : '—' ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php // Financials is read-only for advertising. Figures are
                  // entered in one place — the month grid on the placement —
                  // so there is no second surface to keep in step, and no way
                  // to change a month without seeing the rest of its run. ?>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" class="fin-foot-label">Month total</td>
              <td class="num"><strong class="mh"><?= money2((float)$m['mh']) ?></strong></td>
              <td class="num"><strong class="broker"><?= money2((float)$m['broker']) ?></strong></td>
            </tr>
          </tfoot>
        </table>

        <div class="fin-month-summary">
          <span><strong>Total Paid by Mont Haus:</strong> <span class="mh"><?= money2((float)$m['mh']) ?></span></span>
          <span><strong>Total Due from Broker:</strong> <span class="broker"><?= money2((float)$m['broker']) ?></span></span>
        </div>
      </div>
      <?php endforeach; ?>

    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════ NOTES ══ -->
  <div class="tab-panel <?= $active_tab==='notes'?'active':'' ?>" id="tab-notes">
    <div class="card">
      <div class="add-form" style="margin-bottom:20px;">
        <h4>Add Note</h4>
        <form method="POST">
          <input type="hidden" name="_action" value="add_note">
          <textarea name="content" class="form-input" placeholder="Write a note…" rows="3" required></textarea>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px;"><i class="ti ti-plus"></i> Save Note</button>
        </form>
      </div>
      <?php if ($agent['other_marketing']): ?>
        <div style="background:#f9fafb;border:1px solid #f3f4f6;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#9ca3af;">From Intake Form</span>
          </div>
          <div style="font-size:13px;color:#374151;line-height:1.6;white-space:pre-wrap;"><?= htmlspecialchars($agent['other_marketing']) ?></div>
        </div>
      <?php endif; ?>
      <?php if (empty($notes) && !$agent['other_marketing']): ?>
        <p style="font-size:13px;color:#9ca3af;text-align:center;padding:16px 0;">No notes yet.</p>
      <?php elseif (!empty($notes)): ?>
        <?php foreach ($notes as $n): ?>
        <div class="note-item">
          <div class="note-header">
            <span class="note-date"><?= date('M j, Y g:ia', strtotime($n['created_at'])) ?></span>
            <?php if (trim($n['author'])): ?><span class="note-author">· <?= htmlspecialchars(trim($n['author'])) ?></span><?php endif; ?>
            <form method="POST" style="margin:0 0 0 auto;" onsubmit="return confirm('Delete this note?')">
              <input type="hidden" name="_action" value="delete_note">
              <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
              <button type="submit" class="btn-ghost" title="Delete"><i class="ti ti-x"></i></button>
            </form>
          </div>
          <div class="note-content"><?= htmlspecialchars($n['content']) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════ LISTINGS ══ -->
  <div class="section-title"><i class="ti ti-building-estate" style="margin-right:6px;color:#9ca3af;"></i>Current Listings</div>
  <?php if (empty($listings)): ?>
    <div class="no-listings">No active listings found for <?= val($agent,'agent_name') ?>.</div>
  <?php else: ?>
    <div class="listings-grid">
      <?php foreach ($listings as $l):
        $lst   = (string)$l['status'];
        $lstat = $lst === 'Pending' || $lst === 'Active Under Contract' ? 'pending' : 'active';
        $llab  = ($lst === 'Active Under Contract' ? 'Under Contract' : $lst) . ((int)$l['is_rental'] ? ' · For Rent' : '');
      ?>
      <div class="listing-card">
        <?php if ($l['primary_photo']): ?>
          <img class="listing-photo" src="<?= htmlspecialchars($l['primary_photo']) ?>" alt="">
        <?php else: ?>
          <div class="listing-photo" style="display:flex;align-items:center;justify-content:center;color:#d1d5db;"><i class="ti ti-photo" style="font-size:28px;"></i></div>
        <?php endif; ?>
        <div class="listing-body">
          <div class="listing-status <?= $lstat ?>"><?= htmlspecialchars($llab) ?></div>
          <div class="listing-addr"><?= htmlspecialchars($l['address']) ?></div>
          <?php if ($l['city']): ?><div class="listing-city"><?= htmlspecialchars($l['city']) ?>, CO</div><?php endif; ?>
          <?php if ($l['list_price']): ?><div class="listing-price"><?= money((float)$l['list_price']) ?></div><?php endif; ?>
          <?php if ($l['url']): ?><a class="listing-link" href="<?= htmlspecialchars($l['url']) ?>" target="_blank" rel="noopener">View on the website ↗</a><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div><!-- /wrap -->
</div><!-- /pc-container -->

<!-- This portal's own copies first (see the note by the stylesheets above). -->
<script src="/assets/vendor/quill.js"></script>
<script src="/assets/vendor/cropper.min.js"></script>
<script>
// Only if a local copy is missing: pull that one library from the CDN, written
// into the page here so it is in place before the script below runs. Nothing
// loads twice, and nothing is fetched at all when the local copies are there.
if (typeof Quill === 'undefined') {
    document.write('<scr' + 'ipt src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"><\/scr' + 'ipt>');
}
if (typeof Cropper === 'undefined') {
    document.write('<scr' + 'ipt src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"><\/scr' + 'ipt>');
}
</script>
<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const t = btn.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === btn));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + t));
    history.replaceState(null, '', '?id=<?= $id ?>&tab=' + t);
    if (window.mhSyncTabMenu) window.mhSyncTabMenu();
  });
});

// ── The same tabs, as a menu, on a phone ─────────────────────────────────────
//
// Below 860px the horizontal strip is hidden by CSS and this takes over. It
// does NOT re-implement tab switching: each menu item forwards its click to the
// real .tab-btn, so there is one code path and one source of truth for what a
// tab does. The items are cloned from those buttons too, which is why the icons
// and the badge counts appear here without being written out twice.
(function () {
  var btn   = document.getElementById('tabMenuBtn');
  var panel = document.getElementById('tabMenuPanel');
  var label = document.getElementById('tabMenuCurrent');
  var tabs  = Array.prototype.slice.call(document.querySelectorAll('.tab-bar .tab-btn'));
  if (!btn || !panel || !label || !tabs.length) return;

  function open(isOpen) {
    panel.classList.toggle('open', isOpen);
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }

  tabs.forEach(function (tab) {
    var item = document.createElement('button');
    item.type = 'button';
    item.className = 'tab-menu-item';
    item.dataset.tab = tab.dataset.tab;
    // innerHTML from a server-rendered sibling in this same document — the icon
    // markup and the badge, nothing user-supplied and nothing fetched.
    item.innerHTML = tab.innerHTML;
    item.addEventListener('click', function () {
      open(false);
      tab.click();                       // the one implementation of "switch tab"
    });
    panel.appendChild(item);
  });

  // Repaints the button's caption and the checked item from whichever .tab-btn
  // is currently active, so it stays right no matter what moved the tab —
  // a menu tap, the strip at desktop width, or the ?tab= link from Financials.
  window.mhSyncTabMenu = function () {
    var active = document.querySelector('.tab-bar .tab-btn.active') || tabs[0];
    var t = active.dataset.tab;
    // textContent, not innerHTML: the caption wants the tab's words without its
    // icon or its badge, both of which the menu row already shows.
    label.textContent = active.textContent.trim().replace(/\s+/g, ' ');
    panel.querySelectorAll('.tab-menu-item').forEach(function (i) {
      i.classList.toggle('active', i.dataset.tab === t);
    });
  };
  window.mhSyncTabMenu();

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    open(!panel.classList.contains('open'));
  });
  document.addEventListener('click', function (e) {
    if (panel.classList.contains('open') && !panel.contains(e.target)) open(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel.classList.contains('open')) { open(false); btn.focus(); }
  });
})();

// ── Arriving from a Financials link ──────────────────────────────────────────
// The server already set the right tab via ?tab=, so the target is visible.
// Scroll it into view and flash it, since a long tab makes a plain jump easy
// to miss.
(function () {
  var m = (location.hash || '').match(/^#(order|camp)-(\d+)$/);
  if (!m) return;
  var el = document.getElementById(m[1] + '-' + m[2]);
  if (!el) return;

  function go() {
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('fin-target');
    setTimeout(function () { el.classList.remove('fin-target'); }, 2000);
  }
  // Wait for layout to settle so the position is right
  if (document.readyState === 'complete') go();
  else window.addEventListener('load', go);
})();

// ── Who-pays: reveal the split inputs, and check they add up ─────────────────
(function () {
  function splitFor(grp) { return document.querySelector('[data-split="' + grp + '"]'); }
  function hintFor(grp)  { return document.querySelector('[data-hint="' + grp + '"]'); }

  // Compare the two amounts against the form's total field — Cost for a
  // collateral order, Budget for an advertising placement. Which one is named
  // by data-total on the radios.
  function checkTotals(form, grp, totalField) {
    var hint = hintFor(grp);
    if (!hint || !form) return;
    var field = form.querySelector('[name="' + (totalField || 'cost') + '"]');
    var total = parseFloat(field ? field.value : '') || 0;
    var b     = parseFloat((form.querySelector('[name="paid_broker_amount"]') || {}).value) || 0;
    var m     = parseFloat((form.querySelector('[name="paid_mh_amount"]') || {}).value) || 0;
    if (!total) { hint.textContent = ''; hint.classList.remove('bad'); return; }
    var sum = b + m;
    if (Math.abs(sum - total) < 0.01) {
      hint.textContent = 'adds up to $' + total.toFixed(2);
      hint.classList.remove('bad');
    } else {
      hint.textContent = '$' + sum.toFixed(2) + ' of $' + total.toFixed(2);
      hint.classList.add('bad');
    }
  }

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.name === 'paid_by' && el.dataset.grp) {
      var wrap = splitFor(el.dataset.grp);
      if (wrap) wrap.style.display = (el.value === 'split') ? '' : 'none';
      if (el.value === 'split') checkTotals(el.form, el.dataset.grp, el.dataset.total);
    }
  });

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (el.name === 'paid_broker_amount' || el.name === 'paid_mh_amount' ||
        el.name === 'cost' || el.name === 'budget') {
      var form = el.form;
      if (!form) return;
      var radio = form.querySelector('[name="paid_by"]:checked');
      if (radio && radio.dataset.grp) checkTotals(form, radio.dataset.grp, radio.dataset.total);
    }
  });

  // Run once for any form already showing a split
  document.querySelectorAll('[name="paid_by"]:checked').forEach(function (r) {
    if (r.value === 'split' && r.dataset.grp) checkTotals(r.form, r.dataset.grp, r.dataset.total);
  });
})();

// ── Keep scroll position across form posts ───────────────────────────────────
// Every checklist tick, status change and inline save is a POST + redirect, so
// the browser lands back at the top of a long page. Stash the scroll offset on
// submit and restore it after the reload.
(function () {
  var KEY = 'mh_agent_scroll_<?= (int)$id ?>';

  document.addEventListener('submit', function () {
    try { sessionStorage.setItem(KEY, String(window.scrollY || window.pageYOffset || 0)); }
    catch (e) { /* private mode — not worth failing over */ }
  }, true);   // capture phase, so it runs even when a handler stops propagation

  var saved = null;
  try { saved = sessionStorage.getItem(KEY); } catch (e) {}
  // A #order-N / #camp-N link from Financials wins over a restored position —
  // the user asked to go somewhere specific.
  if (location.hash) saved = null;
  if (saved !== null) {
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    var y = parseInt(saved, 10);
    if (!isNaN(y) && y > 0) {
      // Stop the browser restoring its own position and fighting ours
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
      window.scrollTo(0, y);
      // Images loading above the fold can shift things; re-apply once settled
      window.addEventListener('load', function () { window.scrollTo(0, y); });
    }
  }
})();

// ── Toggle inline forms (URL / date pickers) ──────────────────────────────────
function toggleInline(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.display = el.style.display === 'none' ? '' : 'none';
}

// ── Billing mode: per-day fields, and what "Budget" means ───────────────────
// Scoped to the select's own form. Several campaign forms sit on the page at
// once, so a document-wide querySelectorAll would toggle all of them together.
function mhApplyBillingMode(sel) {
  const form = sel.closest('form');
  if (!form) return;
  const on = sel.value === 'per_unit';
  form.querySelectorAll('.js-unit-fields').forEach(el => { el.style.display = on ? '' : 'none'; });
  // The hint is the only thing telling you whether Budget bills or not.
  form.querySelectorAll('.js-budget-hint').forEach(el => {
    el.textContent = el.dataset[sel.value] || '';
  });
}
document.addEventListener('change', function (e) {
  const sel = e.target.closest ? e.target.closest('.js-billing-mode') : null;
  if (sel) mhApplyBillingMode(sel);
});
// Run once at load so a saved placement opens showing the right hint, rather
// than a blank one until something is touched.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.js-billing-mode').forEach(mhApplyBillingMode);
});

// ── Task filter (Tasks tab) ───────────────────────────────────────────────────
function applyTaskFilter(f) {
  document.querySelectorAll('.tf-btn').forEach(b => b.classList.toggle('on', b.dataset.tf === f));
  document.querySelectorAll('#taskList .task-row').forEach(row => {
    row.style.display = (f === 'all' || row.dataset.tstat === f) ? '' : 'none';
  });
}
document.querySelectorAll('.tf-btn').forEach(btn => {
  btn.addEventListener('click', () => applyTaskFilter(btn.dataset.tf));
});
// Hide completed rows on load so the tab opens matching its "Open" filter
applyTaskFilter('open');

// ── "N completed tasks" on Overview → Tasks tab, Completed filter ────────────
function showCompletedTasks() {
  const tab = document.querySelector('.tab-btn[data-tab="tasks"]');
  if (tab) tab.click();
  applyTaskFilter('done');
}

// ── Bio tabs ─────────────────────────────────────────────────────────────────
document.querySelectorAll('.bio-tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const card = btn.closest('.card');
    card.querySelectorAll('.bio-tab-btn').forEach(b => b.classList.toggle('active', b === btn));
    const target = 'bio-' + btn.dataset.bio;
    card.querySelectorAll('.bio-panel').forEach(p => p.classList.toggle('active', p.id === target));
  });
});

// ── Repeatable ad rows in the Add Placement form ─────────────────────────────
(function () {
  const wrap = document.getElementById('adRows');
  const btn  = document.getElementById('addAdRowBtn');
  if (!wrap || !btn) return;
  let n = 0;

  function addRow() {
    const i   = n++;
    const row = document.createElement('div');
    row.className = 'ad-row';
    row.innerHTML =
      '<input type="text" name="ads[' + i + '][label]"      class="form-input" placeholder="Label (e.g. Full page)" style="max-width:150px;">' +
      '<input type="text" name="ads[' + i + '][file_url]"   class="form-input" placeholder="Ad file URL (Dropbox / Drive)">' +
      '<input type="text" name="ads[' + i + '][target_url]" class="form-input" placeholder="Target URL (optional)">' +
      '<button type="button" class="btn-ghost ad-row-del" title="Remove"><i class="ti ti-x"></i></button>' +
      '<button type="button" class="btn btn-outline btn-xs ad-row-use" title="Use the URL built above">Use built URL</button>';
    wrap.appendChild(row);
    syncDeleteButtons();
  }

  // Keep at least one row present, and hide its delete button
  function syncDeleteButtons() {
    const rows = wrap.querySelectorAll('.ad-row');
    rows.forEach(r => {
      const d = r.querySelector('.ad-row-del');
      if (d) d.style.visibility = rows.length > 1 ? '' : 'hidden';
    });
  }

  wrap.addEventListener('click', function (e) {
    const del = e.target.closest('.ad-row-del');
    if (del) { del.closest('.ad-row').remove(); syncDeleteButtons(); return; }

    // Copy the Target URL Builder's current output into this row's target field
    const use = e.target.closest('.ad-row-use');
    if (use) {
      const built = document.getElementById('urlPreview');
      const field = use.closest('.ad-row').querySelector('input[name$="[target_url]"]');
      if (built && field) field.value = built.textContent.trim();
    }
  });

  btn.addEventListener('click', addRow);
  addRow();   // start with one empty row
})();

// ── Click-to-copy (advertising URLs and ad file links) ───────────────────────
(function () {
  function flash(btn) {
    const original = btn.innerHTML;
    btn.classList.add('copied');
    btn.innerHTML = '<i class="ti ti-check"></i> Copied';
    setTimeout(() => { btn.innerHTML = original; btn.classList.remove('copied'); }, 1400);
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.copy-btn');
    if (!btn) return;
    e.preventDefault();
    const text = btn.dataset.copy || '';
    if (!text) return;

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(() => flash(btn));
      return;
    }
    // Fallback for non-secure contexts where the async API is unavailable
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity  = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); flash(btn); } catch (err) { /* ignore */ }
    document.body.removeChild(ta);
  });
})();

// ── Photoshoot "Not needed" dims the rest of the subsection ──────────────────
(function () {
  const chk    = document.getElementById('psNotNeeded');
  const fields = document.getElementById('psFields');
  if (!chk || !fields) return;
  const sync = () => fields.classList.toggle('ps-dim', chk.checked);
  chk.addEventListener('change', sync);
  sync();
})();

// ── Crop tools (Profile tab) ────────────────────────────────────────────────
// Two of them: the profile photo (4:5, the shape of the website's broker
// cards) and the square used on cards and lists. Both send the box in the
// picture's own pixels and let the server do the cutting, because a photo
// hosted elsewhere cannot be exported from a canvas (2026-09-23).
// ── Stepping between agents (2026-09-29) ─────────────────────────────────────
(function () {
  var jump = document.getElementById('agentJump');

  // The tab you are on is switched in the page and written into the address
  // bar, so read it from there rather than from what the server baked into
  // these links. Otherwise moving to the next agent always landed on Profile.
  function currentTab() {
    var t = new URLSearchParams(location.search).get('tab');
    return t || (jump && jump.dataset.tab) || 'profile';
  }
  function goTo(id) {
    if (!id) return;
    location.href = 'agent.php?id=' + encodeURIComponent(id) + '&tab=' + encodeURIComponent(currentTab());
  }
  function idOf(link) {
    var m = (link.getAttribute('href') || '').match(/[?&]id=(\d+)/);
    return m ? m[1] : null;
  }

  if (jump) jump.addEventListener('change', function () { goTo(jump.value); });

  document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('.step-btn[data-step]') : null;
    if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;   // let cmd-click open a tab
    e.preventDefault();
    goTo(idOf(link));
  });

  // Alt with an arrow key does the same as the chevrons. Alt, so it cannot
  // fight the cursor keys inside a text box, the bio editor or the crop tool.
  document.addEventListener('keydown', function (e) {
    if (!e.altKey || e.ctrlKey || e.metaKey) return;
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    var link = document.querySelector('.step-btn[data-step="' + (e.key === 'ArrowLeft' ? 'prev' : 'next') + '"]');
    if (!link) return;
    e.preventDefault();
    goTo(idOf(link));
  });
})();

function mkCropTool(opts) {
  var input = document.getElementById(opts.input);
  if (!input) return;
  // Without Cropper this used to bind nothing, so the crop buttons did nothing
  // at all and said nothing either (2026-09-28). Say it on the page instead.
  if (typeof Cropper === 'undefined') {
    var dead = document.getElementById(opts.button);
    if (dead) {
      dead.disabled = true;
      dead.style.opacity = '.55';
      dead.style.cursor = 'not-allowed';
      var why = document.createElement('p');
      why.className = 'hint';
      why.style.margin = '6px 0 0';
      why.textContent = 'The crop tool did not load, so this button is off. Reload the page. If it keeps happening, assets/vendor/cropper.min.js is missing from the server.';
      dead.insertAdjacentElement('afterend', why);
    }
    return;
  }
  var wrap = document.getElementById(opts.wrap);
  var img  = document.getElementById(opts.img);
  var form = input.closest('form');
  var cropper = null, source = 'file';

  function frame(src) {
    wrap.hidden = false;
    if (cropper) { cropper.destroy(); cropper = null; }
    img.onload = function () {
      cropper = new Cropper(img, { aspectRatio: opts.ratio, viewMode: 1, autoCropArea: 0.8, background: false });
      img.onload = null;
    };
    img.src = src;
  }

  input.addEventListener('change', function () {
    if (input.files && input.files[0]) { source = 'file'; frame(URL.createObjectURL(input.files[0])); }
  });

  var reCrop = opts.button ? document.getElementById(opts.button) : null;
  if (reCrop) {
    reCrop.addEventListener('click', function () {
      source = 'current';
      input.value = '';            // crop what is stored, not a picked file
      frame(reCrop.dataset.src);
      wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }

  form.addEventListener('submit', function () {
    if (!cropper) return;
    var d = cropper.getData(true);
    document.getElementById(opts.fields.x).value = d.x;
    document.getElementById(opts.fields.y).value = d.y;
    document.getElementById(opts.fields.w).value = d.width;
    document.getElementById(opts.fields.h).value = d.height;
    document.getElementById(opts.fields.src).value = source;
    if (opts.fields.data) {
      try {
        document.getElementById(opts.fields.data).value =
          cropper.getCroppedCanvas({ width: 800, height: 800 }).toDataURL('image/jpeg', 0.92);
      } catch (e) { /* cross-origin photo: the box above is enough */ }
    }
  });
}

// The same limit, checked before the browser sends anything, so a big file is
// caught at once rather than after a long upload.
(function () {
  var max = <?= (int)min(mk_ini_bytes(ini_get('upload_max_filesize')), mk_ini_bytes(ini_get('post_max_size'))) ?>;
  var form = document.getElementById('photoInput') ? document.getElementById('photoInput').closest('form') : null;
  if (!form || !max) return;
  form.addEventListener('submit', function (e) {
    var big = ['photoInput', 'faceInput'].map(function (id) { return document.getElementById(id); })
      .filter(function (i) { return i && i.files && i.files[0] && i.files[0].size > max; });
    if (!big.length) return;
    e.preventDefault();
    alert('That image is ' + Math.round(big[0].files[0].size / 1048576) + ' MB, and this server accepts up to '
        + Math.floor(max / 1048576) + ' MB. Please use a smaller one.');
  });
})();

mkCropTool({ input: 'photoInput', wrap: 'photoCropWrap', img: 'photoCropImg', button: 'reCropPhotoBtn',
             ratio: 4 / 5, fields: { x: 'pcropX', y: 'pcropY', w: 'pcropW', h: 'pcropH', src: 'pcropSrc' } });
mkCropTool({ input: 'faceInput', wrap: 'faceCropWrap', img: 'faceCropImg', button: 'reCropBtn',
             ratio: 1, fields: { x: 'cropX', y: 'cropY', w: 'cropW', h: 'cropH', src: 'cropSrc', data: 'faceData' } });

// ── Bio rich-text editor (Quill) ─────────────────────────────────────────────
(function () {
  const holder = document.getElementById('bioEditor');
  const hidden = document.getElementById('bioTextHidden');
  const form   = document.getElementById('bioForm');
  if (!holder || !hidden || !form) return;

  // Quill failed to load (offline / CDN blocked) — fall back to a plain textarea
  if (typeof Quill === 'undefined') {
    const ta = document.createElement('textarea');
    ta.name = 'bio_text';
    ta.className = 'form-input';
    ta.rows = 10;
    ta.value = holder.innerHTML.trim();
    holder.replaceWith(ta);
    hidden.removeAttribute('name');
    return;
  }

  const quill = new Quill(holder, {
    theme: 'snow',
    placeholder: 'Full biography…',
    modules: {
      toolbar: [
        [{ header: [3, 4, false] }],
        ['bold', 'italic', 'underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link', 'blockquote'],
        ['clean']
      ]
    }
  });

  form.addEventListener('submit', () => {
    const html = quill.getText().trim() === '' ? '' : quill.root.innerHTML;
    hidden.value = html;
  });
})();

// ── Auto-open contact card if ?open=contact is in URL ────────────────────────
(function () {
  const params = new URLSearchParams(location.search);
  if (params.get('open') === 'contact') {
    const el = document.getElementById('edit-contact');
    if (el) {
      el.style.display = '';
      setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
    }
  }
})();

// ── URL Builder ───────────────────────────────────────────────────────────────
(function() {
  const listings = <?= $listings_json ?>;
  const pathType   = document.getElementById('urlPathType');
  const listWrap   = document.getElementById('listingSelectWrap');
  const listSel    = document.getElementById('urlListingSelect');
  const custWrap   = document.getElementById('customPathWrap');
  const custPath   = document.getElementById('urlCustomPath');
  const preview    = document.getElementById('urlPreview');
  const target     = document.getElementById('targetUrlInput');
  const copyBtn    = document.getElementById('copyUrlBtn');
  const useBtn     = document.getElementById('useUrlBtn');
  const toggleBtn  = document.getElementById('toggleUrlBuilder');
  const builder    = document.getElementById('urlBuilder');
  if (!pathType) return;

  // The builder starts closed: pasting is the common case, building is not.
  toggleBtn?.addEventListener('click', () => {
    const open = builder.style.display === 'none';
    builder.style.display = open ? '' : 'none';
    toggleBtn.innerHTML = open
      ? '<i class="ti ti-x"></i> Hide builder'
      : '<i class="ti ti-tools"></i> Build with UTMs';
  });

  // Nothing the builder does reaches the Target URL field on its own — only
  // this button does. A pasted URL therefore survives any amount of fiddling
  // with the UTM boxes.
  useBtn?.addEventListener('click', () => {
    if (!target) return;
    target.value = preview.textContent.trim();
    useBtn.innerHTML = '<i class="ti ti-check"></i> Added';
    setTimeout(() => { useBtn.innerHTML = '<i class="ti ti-arrow-up"></i> Use this'; }, 1500);
  });

  // Populate listing options
  listings.forEach(l => {
    const o = document.createElement('option');
    o.value = l.url;
    o.textContent = l.label;
    listSel.appendChild(o);
  });

  function buildUrl() {
    let base = 'https://monthaus.com/';
    const type = pathType.value;
    if (type === 'listing' && listSel.value) {
      base = listSel.value;   // the listing's full page address
    } else if (type === 'custom' && custPath.value.trim()) {
      base += custPath.value.trim().replace(/^\/+/, '');
    }
    const params = [];
    const src  = document.getElementById('utm_source')?.value.trim();
    const med  = document.getElementById('utm_medium')?.value.trim();
    const cam  = document.getElementById('utm_campaign')?.value.trim();
    const con  = document.getElementById('utm_content')?.value.trim();
    if (src) params.push('utm_source='   + encodeURIComponent(src));
    if (med) params.push('utm_medium='   + encodeURIComponent(med));
    if (cam) params.push('utm_campaign=' + encodeURIComponent(cam));
    if (con) params.push('utm_content='  + encodeURIComponent(con));
    const url = base + (params.length ? (base.includes('?') ? '&' : '?') + params.join('&') : '');
    if (preview) preview.textContent = url;   // preview only — see useBtn above
  }

  pathType.addEventListener('change', () => {
    listWrap.style.display = pathType.value === 'listing' ? '' : 'none';
    custWrap.style.display = pathType.value === 'custom'  ? '' : 'none';
    buildUrl();
  });
  [listSel, custPath,
   document.getElementById('utm_source'),
   document.getElementById('utm_medium'),
   document.getElementById('utm_campaign'),
   document.getElementById('utm_content')
  ].forEach(el => el && el.addEventListener('input', buildUrl));

  copyBtn?.addEventListener('click', () => {
    navigator.clipboard.writeText(preview.textContent).then(() => {
      copyBtn.innerHTML = '<i class="ti ti-check"></i> Copied!';
      setTimeout(() => { copyBtn.innerHTML = '<i class="ti ti-copy"></i> Copy'; }, 1500);
    });
  });

  buildUrl();
})();
</script>

</body>
</html>
