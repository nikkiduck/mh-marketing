<?php
/**
 * marketing/agent.php
 * Agent profile — Overview (interactive onboarding checklist) | Tasks | Collateral | Campaigns | Notes
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/_onboarding.php';
require_login();
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        header("Location: agent.php?id={$id}&tab={$rtab}"); exit;
    }

    if ($action === 'update_task_file') {
        $tid  = (int)($_POST['task_id'] ?? 0);
        $furl = trim($_POST['file_url'] ?? '') ?: null;
        $s = $conn->prepare("UPDATE marketing_tasks SET file_url=? WHERE id=? AND intake_id=?");
        $s->bind_param('sii', $furl, $tid, $id); $s->execute(); $s->close();
        header("Location: agent.php?id={$id}&tab=overview"); exit;
    }

    if ($action === 'update_task_due') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $due = trim($_POST['due_date'] ?? '') ?: null;
        $s = $conn->prepare("UPDATE marketing_tasks SET due_date=? WHERE id=? AND intake_id=?");
        $s->bind_param('sii', $due, $tid, $id); $s->execute(); $s->close();
        header("Location: agent.php?id={$id}&tab=overview"); exit;
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
            $s = $conn->prepare("INSERT INTO marketing_campaigns (intake_id,platform,name,budget,start_date,end_date,notes,target_url,utm_source,utm_medium,utm_campaign,utm_content,paid_by,paid_broker_amount,paid_mh_amount,sent,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'planned')");
            // i + 14×s + i = 16, matching the 16 placeholders above
            $s->bind_param('issssssssssssssi', $id, $platform, $name, $budget, $start, $end, $camp_notes, $target_url, $utm_source, $utm_medium, $utm_camp, $utm_content, $paid_by, $paid_broker, $paid_mh, $sent);
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
        $fields = ['platform','name','budget','start_date','end_date','notes','target_url',
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
        header("Location: agent.php?id={$id}&tab=overview"); exit;
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

    /* --- Inline field updates (contact, social, bio, collateral) --- */
    if ($action === 'update_intake_fields') {
        $allowed = [
            'agent_name','agent_title','cell_phone','mh_email','alt_email','email_forwarded',
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
        $rtab = $_POST['redirect_tab'] ?? 'overview';
        header("Location: agent.php?id={$id}&tab={$rtab}&saved=1"); exit;
    }
}

// ── Load agent ────────────────────────────────────────────────────────────────
$r     = $conn->query("SELECT * FROM marketing_intakes WHERE id={$id} LIMIT 1");
$agent = $r ? $r->fetch_assoc() : null;
if (!$agent) { header('Location: index.php'); exit; }

// ── Auto-seed onboarding tasks if none exist yet ──────────────────────────────
mkt_seed_onboarding_tasks($conn, $id);

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
// Every billable line for this agent, bucketed by the month the spend was
// incurred: Order Date for collateral, Run Start for a placement. Both fall
// back to created_at so nothing can drop out of the report entirely.
//
// Who pays drives the split:
//   broker     → the whole amount is billed to the broker
//   mont_haus  → Mont Haus covers the whole amount
//   split      → the two stored amounts are used as entered
//   not set    → counted as unassigned; shown, but in neither total, so an
//                undecided line is visible rather than silently skewing things
$fin_months = [];   // 'YYYY-MM' => ['label','items'=>[], 'broker','mh','unassigned']

function mk_fin_add(array &$months, string $date, array $item): void {
    $key = substr($date, 0, 7);                       // YYYY-MM
    if (!isset($months[$key])) {
        $months[$key] = [
            'key'        => $key,
            'label'      => date('F Y', strtotime($key . '-01')),
            'items'      => [],
            'broker'     => 0.0,
            'mh'         => 0.0,
            'unassigned' => 0.0,
        ];
    }
    $months[$key]['items'][]      = $item;
    $months[$key]['broker']      += $item['broker'];
    $months[$key]['mh']          += $item['mh'];
    $months[$key]['unassigned']  += $item['unassigned'];
}

/**
 * Display name for a collateral type. Explicit map rather than ucwords() —
 * that would render 'oh_signs' as "Oh Signs". Matches the section headings
 * used on the Collateral tab.
 */
function mk_coll_type_label(string $type): string {
    $map = [
        'business_cards' => 'Business Cards',
        'yard_signs'     => 'Yard Signs',
        'oh_signs'       => 'Open House Signs',
        'postcards'      => 'Postcards',
        'brochures'      => 'Brochures',
        'other'          => 'Other',
    ];
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

/** Split one line's total into broker / MH / unassigned per its paid_by. */
function mk_fin_split(?string $paid_by, float $total, $broker_amt, $mh_amt): array {
    switch ($paid_by) {
        case 'broker':    return ['broker' => $total, 'mh' => 0.0,    'unassigned' => 0.0];
        case 'mont_haus': return ['broker' => 0.0,    'mh' => $total, 'unassigned' => 0.0];
        case 'split':     return [
            'broker'     => (float)($broker_amt ?? 0),
            'mh'         => (float)($mh_amt ?? 0),
            'unassigned' => 0.0,
        ];
        default:          return ['broker' => 0.0, 'mh' => 0.0, 'unassigned' => $total];
    }
}

// Collateral orders.
// An order can be marked as billed with another (one invoice covering several
// items). The order holding the cost reports it; the linked ones contribute
// nothing, so a combined purchase is counted once.
$coll_rows = [];
$r = $conn->query("
    SELECT id, type, label, vendor, cost, ordered_at, created_at, billed_with_order_id,
           receipt_file, receipt_orig_name,
           paid_by, paid_broker_amount, paid_mh_amount
      FROM marketing_collateral_orders
     WHERE intake_id = {$id}
");
if ($r) while ($row = $r->fetch_assoc()) $coll_rows[(int)$row['id']] = $row;

foreach ($coll_rows as $row) {
        $linked_to = (int)($row['billed_with_order_id'] ?? 0);
        // A link pointing at a deleted order would silently zero the cost —
        // treat it as unlinked and flag it in the row instead.
        $orphaned  = $linked_to && !isset($coll_rows[$linked_to]);
        $is_linked = $linked_to && !$orphaned;

        $total = (float)($row['cost'] ?? 0);
        if ($total <= 0 && ($row['paid_by'] ?? '') === '' && !$is_linked) continue;
        $when  = $row['ordered_at'] ?: ($row['created_at'] ?: date('Y-m-d'));

        if ($is_linked) {
            // Shown for context, contributes nothing to any total
            $parent = $coll_rows[$linked_to];
            $plabel = mk_coll_type_label((string)$parent['type'])
                    . (trim((string)$parent['label']) !== '' ? ': ' . trim((string)$parent['label']) : '');
            $type_label  = mk_coll_type_label((string)$row['type']);
            $order_label = trim((string)$row['label']);
            mk_fin_add($fin_months, $when, [
                'kind'        => 'Collateral',
                'name'        => $order_label !== '' ? "{$type_label}: {$order_label}" : $type_label,
                'platform'    => trim((string)($row['vendor'] ?? '')),
                'total'       => 0.0,
                'broker'      => 0.0,
                'mh'          => 0.0,
                'unassigned'  => 0.0,
                'paid_by'     => $row['paid_by'] ?? null,
                'date'        => $when,
                'ref_id'      => (int)$row['id'],
                'billed_with' => $plabel,
                'receipt'     => !empty($row['receipt_file']) ? (int)$row['id'] : 0,
            ]);
            continue;
        }

        $parts = mk_fin_split($row['paid_by'] ?? null, $total,
                              $row['paid_broker_amount'], $row['paid_mh_amount']);
        // "Business Cards: Initial Order" — the label alone is rarely distinct
        $type_label = mk_coll_type_label((string)$row['type']);
        $order_label = trim((string)$row['label']);
        mk_fin_add($fin_months, $when, array_merge($parts, [
            'kind'     => 'Collateral',
            'name'     => $order_label !== '' ? "{$type_label}: {$order_label}" : $type_label,
            'platform' => trim((string)($row['vendor'] ?? '')),
            'total'    => $total,
            'paid_by'  => $row['paid_by'] ?? null,
            'date'     => $when,
            'ref_id'   => (int)$row['id'],
            // Link pointed at an order that no longer exists — say so rather
            // than quietly counting the cost as if it were never linked
            'orphaned' => $orphaned,
            'receipt'  => !empty($row['receipt_file']) ? (int)$row['id'] : 0,
        ]));
}

// Advertising placements
$r = $conn->query("
    SELECT id, platform, name, budget, start_date, created_at,
           paid_by, paid_broker_amount, paid_mh_amount
      FROM marketing_campaigns
     WHERE intake_id = {$id}
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $total = (float)($row['budget'] ?? 0);
        if ($total <= 0 && ($row['paid_by'] ?? '') === '') continue;
        $when  = $row['start_date'] ?: ($row['created_at'] ?: date('Y-m-d'));
        $parts = mk_fin_split($row['paid_by'] ?? null, $total,
                              $row['paid_broker_amount'], $row['paid_mh_amount']);
        // $platform_labels isn't defined until the UI helpers further down, so
        // keep the raw value and resolve it at render time.
        mk_fin_add($fin_months, $when, array_merge($parts, [
            'kind'     => 'Advertising',
            'name'     => trim((string)($row['name'] ?? '')),
            'platform' => (string)$row['platform'],
            'total'    => $total,
            'paid_by'  => $row['paid_by'] ?? null,
            'date'     => $when,
            'ref_id'   => (int)$row['id'],
        ]));
    }
}

krsort($fin_months);                                  // newest month first
foreach ($fin_months as &$_m) {                       // newest line first within a month
    usort($_m['items'], fn($a, $b) => strcmp($b['date'], $a['date']));
}
unset($_m);

// Running totals across every month shown
$fin_grand = ['broker' => 0.0, 'mh' => 0.0, 'unassigned' => 0.0];
foreach ($fin_months as $_m) {
    $fin_grand['broker']     += $_m['broker'];
    $fin_grand['mh']         += $_m['mh'];
    $fin_grand['unassigned'] += $_m['unassigned'];
}

// ── MLS IDs ───────────────────────────────────────────────────────────────────
$mls_ids = [];
$r = $conn->query("SELECT * FROM marketing_agent_mls_ids WHERE intake_id={$id} ORDER BY board ASC");
if ($r) $mls_ids = $r->fetch_all(MYSQLI_ASSOC);

// Fall back to office_roster when marketing_agent_mls_ids has no rows.
// office_roster is the canonical source for Spark keys (populated by sync).
if (empty($mls_ids)) {
    $roster_id = (int)($agent['roster_id'] ?? 0);
    if ($roster_id) {
        $rr = $conn->query(
            "SELECT agent_key, vail_agent_key, mls_id_aspen, mls_id_vail
             FROM office_roster WHERE id = {$roster_id} LIMIT 1"
        );
    } else {
        // roster_id not yet linked — match by email
        $email_esc = $conn->real_escape_string($agent['mh_email'] ?? '');
        $rr = $conn->query(
            "SELECT agent_key, vail_agent_key, mls_id_aspen, mls_id_vail
             FROM office_roster
             WHERE LOWER(email COLLATE utf8mb4_unicode_ci) = LOWER('{$email_esc}')
             LIMIT 1"
        );
    }
    if ($rr && $ro = $rr->fetch_assoc()) {
        if (!empty($ro['agent_key']) || !empty($ro['mls_id_aspen'])) {
            $mls_ids[] = [
                'board'     => 'Aspen',
                'mls_id'    => $ro['mls_id_aspen']  ?: null,
                'spark_key' => $ro['agent_key']      ?: null,
            ];
        }
        if (!empty($ro['vail_agent_key']) || !empty($ro['mls_id_vail'])) {
            $mls_ids[] = [
                'board'     => 'Vail',
                'mls_id'    => $ro['mls_id_vail']    ?: null,
                'spark_key' => $ro['vail_agent_key'] ?: null,
            ];
        }
    }
}

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

// ── Notes ─────────────────────────────────────────────────────────────────────
$notes = [];
$r = $conn->query("SELECT mn.*, CONCAT(u.first_name,' ',u.last_name) AS author FROM marketing_notes mn LEFT JOIN users u ON u.id=mn.created_by WHERE mn.intake_id={$id} ORDER BY mn.created_at DESC");
if ($r) $notes = $r->fetch_all(MYSQLI_ASSOC);

// ── Spark lookup by agent key ─────────────────────────────────────────────────
/**
 * Listings where this Spark key is the list or co-list agent, straight from the
 * MLS. Neither `listings` nor `listing_brokers` stores an agent key, so a key
 * entered on the roster cannot be matched locally — this is the only way to
 * honour it. Returns [] on any failure so the caller can fall back to names.
 *
 * Uses the Spark **v1** API, not RESO OData. The keys sync_roster stores are v1
 * account UUIDs, and OData's ListAgentKey is a different value — see the note
 * in b2b/create_b2b.php line 116. Querying OData with a v1 key silently returns
 * nothing, which is exactly what happened here.
 */
function mk_spark_listings_by_key(string $agent_key, string $board, string $mls_id = ''): array {
    $agent_key = trim($agent_key);
    $mls_id    = trim($mls_id);
    if ($agent_key === '' && $mls_id === '') return [];

    $token = (strcasecmp($board, 'Vail') === 0) ? VAIL_SPARK_ACCESS_TOKEN : SPARK_ACCESS_TOKEN;

    // Match on every identifier we hold. An agent can have more than one Spark
    // account — Emma K Casson's roster key (a 2009 account attached to the Mont
    // Haus office) is not the key the MLS writes onto her listings. The MLS
    // member id (e.g. "1126A") is the stable one, so prefer having both.
    $terms = [];
    if ($agent_key !== '') {
        $k = str_replace("'", "''", $agent_key);
        $terms[] = "ListAgentKey Eq '{$k}'";
        $terms[] = "CoListAgentKey Eq '{$k}'";
    }
    if ($mls_id !== '') {
        $m = str_replace("'", "''", $mls_id);
        $terms[] = "ListAgentMlsId Eq '{$m}'";
        $terms[] = "CoListAgentMlsId Eq '{$m}'";
    }

    // v1 filter syntax: capitalised operators, single-quoted values.
    $filter = '(' . implode(' Or ', $terms) . ')'
            . " And (MlsStatus Eq 'Active' Or MlsStatus Eq 'Pending')";

    $url = 'https://replication.sparkapi.com/v1/listings?' . http_build_query([
        '_filter' => $filter,
        '_expand' => 'Photos',
        '_limit'  => 100,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) {
        error_log("mk_spark_listings_by_key: {$board} HTTP {$code} for key {$agent_key}");
        return [];
    }

    $data = json_decode($raw, true);
    $out  = [];
    foreach (($data['D']['Results'] ?? []) as $l) {
        $sf = $l['StandardFields'] ?? [];

        $photo  = null;
        $photos = $sf['Photos'] ?? ($l['Photos'] ?? []);
        if (!empty($photos)) {
            $p0 = $photos[0];
            foreach (['Uri640','Uri800','Uri300','UriLarge','UriThumb'] as $k) {
                if (!empty($p0[$k])) { $photo = $p0[$k]; break; }
            }
        }

        $out[] = [
            'mls_number'        => trim((string)($sf['ListingId'] ?? '')),
            'address'           => trim((string)($sf['UnparsedFirstLineAddress'] ?? $sf['UnparsedAddress'] ?? '')),
            'city'              => trim((string)($sf['City'] ?? '')),
            'state_abbr'        => trim((string)($sf['StateOrProvince'] ?? 'CO')),
            'price'             => isset($sf['ListPrice']) ? (float)$sf['ListPrice'] : null,
            'status'            => trim((string)($sf['MlsStatus'] ?? '')),
            'primary_photo_url' => $photo,
            'lofty_id'          => null,   // filled from the local table below when known
            'id'                => null,
        ];
    }
    return $out;
}

// ── Current listings ──────────────────────────────────────────────────────────
// listing_brokers stores the name exactly as the MLS carries it, which is often
// the agent's professional name — "Emma K Casson" where the marketing record
// says "Emma Casson". An exact string match misses those, so fall back to
// comparing first and last name tokens, which is unaffected by a middle name
// or initial. listing_brokers has no MLS/Spark key column, so name is the only
// join available.
$listings = [];
$ane = $conn->real_escape_string($agent['agent_name']);

// First and last tokens, ignoring middle names and a trailing generational
// suffix ("Jr.", "III") that would otherwise become the "last" token.
$name_parts = preg_split('/\s+/', trim((string)$agent['agent_name']), -1, PREG_SPLIT_NO_EMPTY);
$suffixes   = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv'];
while (count($name_parts) > 1 && in_array(strtolower(end($name_parts)), $suffixes, true)) {
    array_pop($name_parts);
}
$first_esc = $name_parts ? $conn->real_escape_string($name_parts[0])              : '';
$last_esc  = $name_parts ? $conn->real_escape_string($name_parts[count($name_parts)-1]) : '';

// Only widen the match when we actually have two distinct tokens to compare —
// a single-word name would otherwise match far too much.
$name_clause = ($first_esc !== '' && $last_esc !== '' && $first_esc !== $last_esc)
    ? "(lb.full_name = '{$ane}'
        OR (SUBSTRING_INDEX(lb.full_name, ' ', 1)  = '{$first_esc}'
        AND SUBSTRING_INDEX(lb.full_name, ' ', -1) = '{$last_esc}'))"
    : "lb.full_name = '{$ane}'";

$r = $conn->query("
    SELECT l.id, l.mls_number, l.address, l.city, l.state_abbr, l.price, l.status, l.primary_photo_url, l.lofty_id
    FROM listings l JOIN listing_brokers lb ON lb.listing_id = l.id
    WHERE {$name_clause} AND l.status IN ('Active','Pending')
    GROUP BY l.id ORDER BY l.status ASC, l.price DESC
");
if ($r) $listings = $r->fetch_all(MYSQLI_ASSOC);

// ── Merge in anything the agent's Spark key finds ────────────────────────────
// The key is authoritative: whatever the MLS says this agent is on should show,
// regardless of how their name is spelled in listing_brokers. Local rows win on
// a duplicate because they carry the lofty_id needed for the View Listing link.
$seen_mls = [];
foreach ($listings as $L) {
    $k = trim((string)($L['mls_number'] ?? ''));
    if ($k !== '') $seen_mls[$k] = true;
}

foreach ($mls_ids as $board_row) {
    $key    = trim((string)($board_row['spark_key'] ?? ''));
    $mls_no = trim((string)($board_row['mls_id']    ?? ''));
    if ($key === '' && $mls_no === '') continue;

    foreach (mk_spark_listings_by_key($key, (string)$board_row['board'], $mls_no) as $sl) {
        $mls = $sl['mls_number'];
        if ($mls !== '' && isset($seen_mls[$mls])) continue;   // already have it locally

        // Borrow lofty_id / id from the local table when this listing is synced
        if ($mls !== '') {
            $mls_esc = $conn->real_escape_string($mls);
            $lr = $conn->query(
                "SELECT id, lofty_id, primary_photo_url FROM listings
                  WHERE mls_number = '{$mls_esc}' LIMIT 1"
            );
            if ($lr && $lrow = $lr->fetch_assoc()) {
                $sl['id']       = $lrow['id'];
                $sl['lofty_id'] = $lrow['lofty_id'];
                if (empty($sl['primary_photo_url'])) $sl['primary_photo_url'] = $lrow['primary_photo_url'];
            }
            $seen_mls[$mls] = true;
        }
        $listings[] = $sl;
    }
}

// Re-apply the display order across the merged set
usort($listings, function ($a, $b) {
    $sa = strcasecmp((string)($a['status'] ?? ''), (string)($b['status'] ?? ''));
    if ($sa !== 0) return $sa;
    return ((float)($b['price'] ?? 0)) <=> ((float)($a['price'] ?? 0));
});

$conn->close();

// ── UI helpers ────────────────────────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'overview';

function val(array $row, string $key): string { return htmlspecialchars($row[$key] ?? ''); }
function money(float $n): string { return '$' . number_format($n, 0); }
// Financials needs exact figures — money() rounds to whole dollars, which is
// right for listing prices but wrong when reconciling an invoice.
function money2(float $n): string { return '$' . number_format($n, 2); }
function initials(string $name): string {
    $p = preg_split('/\s+/', trim($name));
    $i = strtoupper($p[0][0] ?? '');
    if (count($p) > 1) $i .= strtoupper($p[count($p)-1][0] ?? '');
    return substr($i, 0, 2);
}

$status       = $agent['status'] ?: 'active';
$status_label = ['active' => 'Active', 'pending' => 'Pending', 'archived' => 'Archived'];
$status_color = ['active' => '#10b981', 'pending' => '#f97316', 'archived' => '#9ca3af'];
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

// Build listings JSON for URL builder (JS)
$listings_json = json_encode(array_map(fn($l) => [
    'label'    => $l['address'] . ($l['city'] ? ', '.$l['city'] : '') . ' ('.ucfirst(strtolower($l['status'])).')',
    'lofty_id' => $l['lofty_id'] ?? '',
], $listings));
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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
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

    /* ── Cards ── */
    .card { background:#fff; border-radius:6px; padding:22px 26px;
            box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:18px; }
    .card-title { font-size:14px; font-weight:700; color:#111; margin:0 0 16px;
                  padding-bottom:10px; border-bottom:1px solid #f3f4f6; }
    .two-col { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media(max-width:700px) { .two-col { grid-template-columns:1fr; } }

    /* ── Overview info rows ── */
    .info-row { display:flex; align-items:baseline; gap:8px; margin-bottom:10px; font-size:13px; }
    .info-label { font-weight:600; color:#6b7280; min-width:110px; flex-shrink:0; font-size:12px; text-transform:uppercase; letter-spacing:.3px; }
    .info-value { color:#111; }
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
    .fin-orphan { color:#b45309; }
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
    .ad-target { display:flex; align-items:center; gap:8px; font-size:11px;
                 padding:0 0 4px 18px; }
    .ad-target-label { flex-shrink:0; color:#9ca3af; font-weight:600;
                       display:inline-flex; align-items:center; gap:3px; }
    .ad-target-note { flex:1; min-width:0; color:#6b7280;
                      font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
                      overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ad-target-note.muted { color:#c9cdd3; font-style:italic; font-family:inherit; }

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
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
<div class="wrap">

  <div class="breadcrumb">
    <a href="index.php">Agent Roster</a>
    <i class="ti ti-chevron-right" style="font-size:11px;"></i>
    <span><?= val($agent,'agent_name') ?></span>
  </div>

  <!-- Agent header -->
  <div class="agent-header">
    <div class="agent-avatar-lg" style="background:<?= $status==='pending'?'#f97316':($status==='archived'?'#9ca3af':'#1a1a1a') ?>;">
      <?php if ($agent['headshot_url']): ?>
        <img src="<?= val($agent,'headshot_url') ?>" alt="">
      <?php else: ?>
        <?= htmlspecialchars(initials($agent['agent_name'])) ?>
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
      <?php if (!empty($_GET['err'])): ?>
        <span class="saved-flash" style="background:#fee2e2;color:#991b1b;">
          <i class="ti ti-alert-triangle"></i> <?= htmlspecialchars($_GET['err']) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tab bar -->
  <div class="tab-bar">
    <button class="tab-btn <?= $active_tab==='overview'?'active':'' ?>"   data-tab="overview">  <i class="ti ti-user"></i> Overview</button>
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
  </div>

  <!-- ══════════════════════════════════════════════ OVERVIEW ══ -->
  <div class="tab-panel <?= $active_tab==='overview'?'active':'' ?>" id="tab-overview">
    <div class="two-col">

      <!-- Left: Contact + Social + MLS IDs -->
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
                <label class="grow">Title <input type="text" name="agent_title" class="form-input" value="<?= val($agent,'agent_title') ?>"></label>
              </div>
              <div class="form-row">
                <label class="grow">Cell Phone <input type="text" name="cell_phone" class="form-input" value="<?= val($agent,'cell_phone') ?>"></label>
                <label class="grow">Start Date <input type="date" name="start_date" class="form-input" value="<?= val($agent,'start_date') ?>"></label>
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
          <?php if ($agent['cell_phone']): ?>
            <div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?= val($agent,'cell_phone') ?></span></div>
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
          <?php if ($agent['alt_email'] ?? null): ?>
            <div class="info-row"><span class="info-label">Alt Email</span><span class="info-value"><a href="mailto:<?= val($agent,'alt_email') ?>"><?= val($agent,'alt_email') ?></a></span></div>
          <?php endif; ?>
          <?php if ($agent['start_date']): ?>
            <div class="info-row"><span class="info-label">Start Date</span><span class="info-value"><?= date('M j, Y', strtotime($agent['start_date'])) ?></span></div>
          <?php elseif ($agent['intake_date']): ?>
            <div class="info-row"><span class="info-label">Start Date</span><span class="info-value"><?= date('M j, Y', strtotime($agent['intake_date'])) ?></span></div>
          <?php endif; ?>
        </div>

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

        <div class="card">
          <div class="card-title">MLS IDs</div>
          <?php if ($mls_ids): ?>
            <table class="mls-table">
              <thead><tr><th>Board</th><th>MLS ID</th><th>Spark Key</th></tr></thead>
              <tbody>
              <?php foreach ($mls_ids as $m): ?>
                <tr>
                  <td><span class="board-chip"><?= htmlspecialchars($m['board']) ?></span></td>
                  <td class="mono"><?= htmlspecialchars($m['mls_id'] ?? '—') ?></td>
                  <td class="mono"><?= $m['spark_key'] ? htmlspecialchars($m['spark_key']) : '<span style="color:#d1d5db">—</span>' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p style="font-size:13px;color:#9ca3af;margin:0;">No MLS IDs on file. <a href="roster.php">Add via Roster</a>.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right: open tasks. Completing one removes it here; it stays under
           the Tasks tab under the Completed filter. -->
      <div>
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
                  <input type="hidden" name="redirect_tab" value="overview">
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

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
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
          </div>
          <div class="form-row">
            <label>Run Start <input type="date" name="start_date" class="form-input"></label>
            <label>Run End   <input type="date" name="end_date"   class="form-input"></label>
            <label class="grow">Notes <textarea name="notes" class="form-input" rows="2" placeholder="Any notes about this placement…"></textarea></label>
          </div>

          <!-- URL Builder -->
          <div class="url-builder">
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
              <button type="button" id="copyUrlBtn" class="btn btn-outline btn-xs" title="Copy URL"><i class="ti ti-copy"></i> Copy</button>
            </div>
            <input type="hidden" name="target_url" id="targetUrlHidden">
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

          <div class="form-row" style="margin-top:12px;">
            <label style="flex-shrink:0;">
              <span style="display:block;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Sent to Media?</span>
              <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:8px 0;">
                <input type="checkbox" name="sent" value="1"> Mark as sent
              </label>
            </label>
          </div>

          <div class="btn-row" style="margin-top:10px;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-check"></i> Save Placement</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleInline('add-campaign-panel')">Cancel</button>
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

          <div class="campaign-details">
            <?php if ($c['budget']): ?><span>Budget: <strong><?= money((float)$c['budget']) ?></strong></span><?php endif; ?>
            <?php if ($c['start_date']): ?><span>Start: <strong><?= date('m/d/Y', strtotime($c['start_date'])) ?></strong></span><?php endif; ?>
            <?php if ($c['end_date']): ?><span>End: <strong><?= date('m/d/Y', strtotime($c['end_date'])) ?></strong></span><?php endif; ?>
          </div>
          <?php // white-space:pre-wrap so line breaks typed into the textarea survive ?>
          <?php if ($c['notes']): ?><p style="font-size:12px;color:#6b7280;margin:0 0 8px;white-space:pre-wrap;"><?= htmlspecialchars($c['notes']) ?></p><?php endif; ?>
          <?php mk_who_pays_summary($c, 'budget'); ?>

          <!-- Placement target URL -->
          <?php if (!empty($c['target_url'])): ?>
            <div class="link-row" title="<?= htmlspecialchars($c['target_url']) ?>">
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
              <?php foreach ($ad_files as $ci => $af): ?>
                <div class="ad-item">
                  <div class="link-row" title="<?= htmlspecialchars($af['url']) ?>">
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
                      <a class="btn btn-outline btn-xs" href="<?= htmlspecialchars($af['url']) ?>" target="_blank"><i class="ti ti-external-link"></i> Open</a>
                      <button type="button" class="btn btn-outline btn-xs copy-btn"
                              data-copy="<?= htmlspecialchars($af['url']) ?>"><i class="ti ti-copy"></i> Copy</button>
                    <?php else: ?>
                      <span class="asset-none">no file</span>
                    <?php endif; ?>
                    <?php if (!$af['legacy']): ?>
                      <button type="button" class="btn btn-outline btn-xs"
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

                  <!-- Only shown when this creative overrides the placement's target URL.
                       A creative that simply inherits it needs no row — that's the default. -->
                  <?php if (!empty($af['target'])): ?>
                  <div class="ad-target">
                    <span class="ad-target-label"><i class="ti ti-target-arrow"></i> Target</span>
                    <span class="ad-target-note" title="<?= htmlspecialchars($af['target']) ?>"><?= htmlspecialchars($af['target']) ?></span>
                    <a class="btn btn-outline btn-xs" href="<?= htmlspecialchars($af['target']) ?>" target="_blank"><i class="ti ti-external-link"></i> Open</a>
                    <button type="button" class="btn btn-outline btn-xs copy-btn"
                            data-copy="<?= htmlspecialchars($af['target']) ?>"><i class="ti ti-copy"></i> Copy</button>
                  </div>
                  <?php endif; ?>

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
            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this placement and all its ad files?')">
              <input type="hidden" name="_action" value="delete_campaign">
              <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-danger btn-xs"><i class="ti ti-trash"></i> Delete</button>
            </form>
          </div>

          <!-- Edit placement -->
          <div id="edit-camp-<?= $c['id'] ?>" style="display:none;" class="inline-edit-form">
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
              </div>
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
          <input type="hidden" name="redirect_tab" value="assets">
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
        $lstat = strtolower($l['status']);
        $lurl  = $l['lofty_id'] ? 'https://monthaus.com/listing-detail/' . rawurlencode($l['lofty_id']) : null;
      ?>
      <div class="listing-card">
        <?php if ($l['primary_photo_url']): ?>
          <img class="listing-photo" src="<?= htmlspecialchars($l['primary_photo_url']) ?>" alt="">
        <?php else: ?>
          <div class="listing-photo" style="display:flex;align-items:center;justify-content:center;color:#d1d5db;"><i class="ti ti-photo" style="font-size:28px;"></i></div>
        <?php endif; ?>
        <div class="listing-body">
          <div class="listing-status <?= $lstat ?>"><?= htmlspecialchars($l['status']) ?></div>
          <div class="listing-addr"><?= htmlspecialchars($l['address']) ?></div>
          <?php if ($l['city']): ?><div class="listing-city"><?= htmlspecialchars($l['city']) ?><?= $l['state_abbr']?', '.$l['state_abbr']:'' ?></div><?php endif; ?>
          <?php if ($l['price']): ?><div class="listing-price"><?= money((float)$l['price']) ?></div><?php endif; ?>
          <?php if ($lurl): ?><a class="listing-link" href="<?= htmlspecialchars($lurl) ?>" target="_blank">View on MontHaus.com ↗</a><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div><!-- /wrap -->
</div><!-- /pc-container -->

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const t = btn.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === btn));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + t));
    history.replaceState(null, '', '?id=<?= $id ?>&tab=' + t);
  });
});

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
  const hidden     = document.getElementById('targetUrlHidden');
  const copyBtn    = document.getElementById('copyUrlBtn');
  if (!pathType) return;

  // Populate listing options
  listings.forEach(l => {
    const o = document.createElement('option');
    o.value = l.lofty_id;
    o.textContent = l.label;
    listSel.appendChild(o);
  });

  function buildUrl() {
    let base = 'https://monthaus.com/';
    const type = pathType.value;
    if (type === 'listing' && listSel.value) {
      base += 'listing-detail/' + encodeURIComponent(listSel.value);
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
    const url = base + (params.length ? '?' + params.join('&') : '');
    if (preview) preview.textContent = url;
    if (hidden)  hidden.value = url;
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
