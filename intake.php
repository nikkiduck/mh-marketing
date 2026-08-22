<?php
/**
 * marketing/intake.php
 * Create or edit a marketing intake. Admin only.
 *
 * GET  ?id=X  → edit existing intake X
 * GET  (no id) → blank new intake form
 * POST         → save (insert or update), redirect to index.php
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/_onboarding.php';
require_once __DIR__ . '/inc/db.php';
require_login();
require_role('admin');

$intake_id = (int)($_GET['id'] ?? 0);
$intake    = null;
$flash_err = '';

/* ── Spark: look up agent by name in the Members directory (no listings needed) ── */
function mkt_spark_agent_key(string $token, string $office_filter, string $agent_name): string {
    if (!$agent_name) return '';
    $esc    = str_replace("'", "\\'", trim($agent_name));
    $filter = "({$office_filter}) And MemberFullName Eq '{$esc}'";
    $url    = 'https://replication.sparkapi.com/v1/members?' . http_build_query([
        '_filter' => $filter,
        '_select' => 'MemberKey,MemberFullName',
        '_limit'  => 5,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", "Accept: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return '';
    $results = json_decode($raw, true)['D']['Results'] ?? [];
    $lc = strtolower(trim($agent_name));
    foreach ($results as $r) {
        $sf = $r['StandardFields'] ?? [];
        if (strtolower($sf['MemberFullName'] ?? '') === $lc) return $sf['MemberKey'] ?? '';
    }
    return '';
}

/* ── Dynamic SQL save helper ─────────────────────────────────────────────── */
/**
 * Build and execute INSERT or UPDATE for marketing_intakes.
 * $data keys must match column names exactly.
 * Returns true on success, or an error string on failure.
 */
function mkt_save(mysqli $conn, array $data, int $id = 0, int $uid = 0): bool|string|int {
    if (!$id) $data['created_by'] = $uid;

    $types = '';
    $vals  = [];
    foreach ($data as $val) {
        if (is_int($val))       { $types .= 'i'; $vals[] = $val; }
        elseif (is_null($val))  { $types .= 's'; $vals[] = null; }
        else                    { $types .= 's'; $vals[] = (string)$val; }
    }

    if ($id) {
        $sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $sql  = "UPDATE marketing_intakes SET {$sets} WHERE id = ?";
        $types .= 'i';
        $vals[] = $id;
    } else {
        $cols = '`' . implode('`, `', array_keys($data)) . '`';
        $qs   = implode(', ', array_fill(0, count($data), '?'));
        $sql  = "INSERT INTO marketing_intakes ({$cols}) VALUES ({$qs})";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return $conn->error;
    $stmt->bind_param($types, ...$vals);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $new_id = $id ? 0 : (int)$conn->insert_id;
    $stmt->close();
    if (!$ok) return $err;
    return $id ? true : $new_id; // new insert returns the new row id
}

/* ── Handle POST ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save') {

    $id          = (int)($_POST['id'] ?? 0);
    $first_name  = trim($_POST['first_name']   ?? '');
    $last_name   = trim($_POST['last_name']    ?? '');
    $agent_name  = trim($first_name . ' ' . $last_name);
    $team_name   = trim($_POST['team_name']    ?? '') ?: null;
    $agent_title = trim($_POST['agent_title']  ?? '') ?: null;
    // start_date removed
    $intake_date = trim($_POST['intake_date']  ?? '') ?: date('Y-m-d');
    $cell_phone  = trim($_POST['cell_phone']   ?? '') ?: null;
    $mh_email    = trim($_POST['mh_email']     ?? '') ?: null;
    $website_url = trim($_POST['website_url']  ?? '') ?: null;
    $social_ig   = trim($_POST['social_ig']    ?? '') ?: null;
    $social_fb   = trim($_POST['social_fb']    ?? '') ?: null;
    $social_li   = trim($_POST['social_li']    ?? '') ?: null;
    $social_tt   = trim($_POST['social_tt']    ?? '') ?: null;
    $social_oth  = trim($_POST['social_oth']   ?? '') ?: null;
    $has_list    = isset($_POST['has_listings']) ? 1 : 0;
    $list_det    = ($has_list ? trim($_POST['listing_details'] ?? '') : '') ?: null;

    // ── Onboarding checklist (9 items) ─────────────────────────────────────
    $chk_email   = isset($_POST['chk_email_setup'])    ? 1 : 0;
    $chk_sig          = isset($_POST['chk_email_sig'])       ? 1 : 0;
    $email_sig_url    = trim($_POST['email_sig_dropbox_url'] ?? '') ?: null;
    $email_sig_client = trim($_POST['email_sig_client']      ?? '') ?: null;
    $chk_mls     = isset($_POST['chk_mls_confirmed'])   ? 1 : 0;
    $chk_b2b     = isset($_POST['chk_b2b'])             ? 1 : 0;
    $chk_mktg    = isset($_POST['chk_mktg_overview'])   ? 1 : 0;
    $chk_soc     = isset($_POST['chk_social_overview']) ? 1 : 0;
    $chk_web     = isset($_POST['chk_website_updated']) ? 1 : 0;
    $chk_sw      = isset($_POST['chk_social_welcome'])  ? 1 : 0;
    $sw_date     = $chk_sw  ? (trim($_POST['social_welcome_date']  ?? '') ?: null) : null;
    $chk_b2b_ann = isset($_POST['chk_b2b_announcement']) ? 1 : 0;
    $b2b_ann_date = $chk_b2b_ann ? (trim($_POST['b2b_announcement_date'] ?? '') ?: null) : null;

    // ── Headshot ────────────────────────────────────────────────────────────
    $chk_hs_sched  = isset($_POST['chk_hs_scheduled']) ? 1 : 0;
    $hs_date       = $chk_hs_sched ? (trim($_POST['headshot_photoshoot_date'] ?? '') ?: null) : null;
    $chk_hs_proofs = isset($_POST['chk_hs_proofs'])    ? 1 : 0;
    $hs_proofs_url = trim($_POST['hs_proofs_url']      ?? '') ?: null;
    $chk_hs_final  = isset($_POST['chk_hs_final'])     ? 1 : 0;
    $hs_final_url  = trim($_POST['hs_final_url']       ?? '') ?: null;
    $bio_text      = trim($_POST['bio_text']            ?? '') ?: null;

    // ── Collateral ──────────────────────────────────────────────────────────
    // Business Cards
    $coll_bc     = isset($_POST['coll_bc'])    ? 1 : 0;
    $bc_qty      = $coll_bc ? (trim($_POST['bc_qty']      ?? '') ?: null) : null;
    $bc_front    = $coll_bc ? (trim($_POST['bc_front']    ?? '') ?: null) : null;
    $bc_back     = $coll_bc ? (trim($_POST['bc_back']     ?? '') ?: null) : null;
    $bc_chk_des  = ($coll_bc && isset($_POST['bc_chk_design']))      ? 1 : 0;
    $bc_chk_prf  = ($coll_bc && isset($_POST['bc_chk_proof']))       ? 1 : 0;
    $bc_chk_acc  = ($coll_bc && isset($_POST['bc_chk_accounting']))  ? 1 : 0;
    $bc_chk_ord  = ($coll_bc && isset($_POST['bc_chk_ordered']))     ? 1 : 0;
    $bc_chk_del  = ($coll_bc && isset($_POST['bc_chk_delivered']))   ? 1 : 0;

    // Postcards
    $coll_pc        = isset($_POST['coll_pc'])    ? 1 : 0;
    $pc_listing     = $coll_pc ? (trim($_POST['pc_listing']     ?? '') ?: null) : null;
    $pc_target      = $coll_pc ? (trim($_POST['pc_target_area'] ?? '') ?: null) : null;
    $pc_des_raw     = $coll_pc ? ($_POST['pc_design_needed'] ?? null) : null;
    $pc_des_needed  = $pc_des_raw === '1' ? 1 : ($pc_des_raw === '0' ? 0 : null);
    $pc_chk_des     = ($coll_pc && isset($_POST['pc_chk_design']))      ? 1 : 0;
    $pc_chk_prf     = ($coll_pc && isset($_POST['pc_chk_proof']))       ? 1 : 0;
    $pc_chk_acc     = ($coll_pc && isset($_POST['pc_chk_accounting']))  ? 1 : 0;
    $pc_chk_ord     = ($coll_pc && isset($_POST['pc_chk_ordered']))     ? 1 : 0;
    $pc_chk_del     = ($coll_pc && isset($_POST['pc_chk_delivered']))   ? 1 : 0;

    // Digital Brochures
    $coll_br     = isset($_POST['coll_br'])    ? 1 : 0;
    $br_listing  = $coll_br ? (trim($_POST['br_listing']    ?? '') ?: null) : null;
    $br_chk_des  = ($coll_br && isset($_POST['br_chk_design']))  ? 1 : 0;
    $br_chk_prf  = ($coll_br && isset($_POST['br_chk_proof']))   ? 1 : 0;
    $br_chk_cre  = ($coll_br && isset($_POST['br_chk_created'])) ? 1 : 0;

    // Yard Signs
    $coll_ys    = isset($_POST['coll_ys'])    ? 1 : 0;
    $ys_qty     = $coll_ys ? (trim($_POST['ys_qty']    ?? '') ?: null) : null;
    $ys_design  = $coll_ys ? (trim($_POST['ys_design'] ?? '') ?: null) : null;
    $ys_info    = $coll_ys ? (trim($_POST['ys_info']   ?? '') ?: null) : null;

    // Open House Signs
    $coll_oh    = isset($_POST['coll_oh'])    ? 1 : 0;
    $oh_qty     = $coll_oh ? (trim($_POST['oh_qty']    ?? '') ?: null) : null;
    $oh_qr_raw  = $coll_oh ? ($_POST['oh_qr_code'] ?? null) : null;
    $oh_qr      = $oh_qr_raw === '1' ? 1 : ($oh_qr_raw === '0' ? 0 : null);
    $oh_qr_url  = ($coll_oh && $oh_qr === 1) ? (trim($_POST['oh_qr_url'] ?? '') ?: null) : null;
    $oh_info    = $coll_oh ? (trim($_POST['oh_info']   ?? '') ?: null) : null;

    // Other
    $coll_oth    = trim($_POST['coll_oth']  ?? '') ?: null;

    // ── Digital ads ────────────────────────────────────────────────────────
    $dig_int    = isset($_POST['dig_interest']) ? 1 : 0;
    $dig_vd     = ($dig_int && isset($_POST['dig_vail_daily']))   ? 1 : 0;
    $dig_ad     = ($dig_int && isset($_POST['dig_aspen_daily']))  ? 1 : 0;
    $dig_at     = ($dig_int && isset($_POST['dig_aspen_times']))  ? 1 : 0;
    $dig_spend  = $dig_int ? (trim($_POST['dig_spend']    ?? '') ?: null) : null;
    $dig_dur    = $dig_int ? (trim($_POST['dig_duration'] ?? '') ?: null) : null;
    $dig_mh_raw = $dig_int ? ($_POST['dig_use_mh'] ?? null) : null;
    $dig_mh     = $dig_mh_raw === '1' ? 1 : ($dig_mh_raw === '0' ? 0 : null);
    $dig_ct     = $dig_int ? (trim($_POST['dig_content_type']     ?? '') ?: null) : null;
    $dig_addr   = ($dig_int && $dig_ct === 'property_address')
                    ? (trim($_POST['dig_property_addr'] ?? '') ?: null) : null;

    $other_mktg = trim($_POST['other_marketing'] ?? '') ?: null;

    if (!$first_name || !$last_name) {
        $flash_err = 'First and last name are required.';
        $intake_id = $id;
        if ($id) {
            $r = $conn->query("SELECT * FROM marketing_intakes WHERE id = {$id} LIMIT 1");
            $intake = $r ? $r->fetch_assoc() : null;
        }
        $intake = array_merge($intake ?? [], $_POST);
    } else {
        // ── Spark MLS ID lookup (runs in background) ─────────────────────────
        // Seed from existing record so IDs are preserved if Spark can't re-find them
        $mls_aspen = '';
        $mls_vail  = '';
        if ($id) {
            $er = $conn->query("SELECT mls_id_aspen, mls_id_vail FROM marketing_intakes WHERE id = {$id} LIMIT 1");
            if ($er && $erow = $er->fetch_assoc()) {
                $mls_aspen = (string)($erow['mls_id_aspen'] ?? '');
                $mls_vail  = (string)($erow['mls_id_vail']  ?? '');
            }
        }

        $spark_aspen = mkt_spark_agent_key(
            SPARK_ACCESS_TOKEN,
            "MemberOfficeId Eq '" . ASPEN_OFFICE_ID . "'",
            $agent_name
        );
        if ($spark_aspen) $mls_aspen = $spark_aspen;

        $spark_vail = mkt_spark_agent_key(
            VAIL_SPARK_ACCESS_TOKEN,
            "MemberOfficeMlsId Eq '" . VAIL_OFFICE_MLS_ID . "'",
            $agent_name
        );
        if ($spark_vail) $mls_vail = $spark_vail;

        // Normalize MLS keys before roster lookup/insert
        $mls_aspen = $mls_aspen ?: null;
        $mls_vail  = $mls_vail  ?: null;

        // ── Find or create office_roster entry ─────────────────────────────────
        // For updates, preserve an existing roster link; only resolve if unset.
        $roster_id = null;
        if ($id) {
            $chk = $conn->prepare("SELECT roster_id FROM marketing_intakes WHERE id = ? LIMIT 1");
            $chk->bind_param('i', $id);
            $chk->execute();
            $chk_row = $chk->get_result()->fetch_assoc();
            $chk->close();
            $roster_id = !empty($chk_row['roster_id']) ? (int)$chk_row['roster_id'] : null;
        }

        if (!$roster_id) {
            // Look up by agent name in office_roster
            $rq = $conn->prepare(
                "SELECT id, agent_key, vail_agent_key FROM office_roster
                  WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1"
            );
            $rq->bind_param('s', $agent_name);
            $rq->execute();
            $rrow = $rq->get_result()->fetch_assoc();
            $rq->close();

            if ($rrow) {
                // Matched — link to existing roster entry and fill missing MLS keys
                $roster_id = (int)$rrow['id'];
                if (!$mls_aspen && !empty($rrow['agent_key']))      $mls_aspen = $rrow['agent_key'];
                if (!$mls_vail  && !empty($rrow['vail_agent_key'])) $mls_vail  = $rrow['vail_agent_key'];
            } else {
                // Not in roster yet — create a new entry
                $ins = $conn->prepare(
                    "INSERT INTO office_roster (name, title, agent_key, vail_agent_key, active)
                     VALUES (?, ?, ?, ?, 1)"
                );
                $ins->bind_param('ssss', $agent_name, $agent_title, $mls_aspen, $mls_vail);
                $ins->execute();
                $roster_id = (int)$conn->insert_id;
                $ins->close();
            }
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);

        // ── Build data array and save ────────────────────────────────────────
        $save_data = [
            // Roster link
            'roster_id'                 => $roster_id,
            // Agent info
            'agent_name'                => $agent_name,
            'agent_title'               => $agent_title,
            'team_name'                 => $team_name,
            'mls_id_aspen'              => $mls_aspen,
            'mls_id_vail'               => $mls_vail,
            'intake_date'               => $intake_date,
            'cell_phone'                => $cell_phone,
            'mh_email'                  => $mh_email,
            // Digital presence
            'website_url'               => $website_url,
            'social_instagram'          => $social_ig,
            'social_facebook'           => $social_fb,
            'social_linkedin'           => $social_li,
            'social_tiktok'             => $social_tt,
            'social_other'              => $social_oth,
            // Listings
            'has_listings'              => $has_list,
            'listing_details'           => $list_det,
            // Onboarding checklist
            'check_email_setup'         => $chk_email,
            'check_email_signature'     => $chk_sig,
            'email_sig_dropbox_url'     => $email_sig_url,
            'email_sig_client'          => $email_sig_client,
            'check_mls_confirmed'       => $chk_mls,
            'check_b2b_system'          => $chk_b2b,
            'check_marketing_overview'  => $chk_mktg,
            'check_social_overview'     => $chk_soc,
            'check_website_updated'     => $chk_web,
            'check_social_welcome_post' => $chk_sw,
            'social_welcome_date'       => $sw_date,
            'check_b2b_announcement'    => $chk_b2b_ann,
            'b2b_announcement_date'     => $b2b_ann_date,
            // Headshot
            'check_headshot_scheduled'  => $chk_hs_sched,
            'headshot_photoshoot_date'  => $hs_date,
            'check_headshot_proofs'     => $chk_hs_proofs,
            'headshot_proofs_url'       => $hs_proofs_url,
            'check_headshot_final'      => $chk_hs_final,
            'headshot_final_url'        => $hs_final_url,
            'bio_text'                  => $bio_text,
            // Collateral — Business Cards
            'coll_business_cards'       => $coll_bc,
            'coll_bc_qty'               => $bc_qty,
            'coll_bc_front'             => $bc_front,
            'coll_bc_back'              => $bc_back,
            'coll_bc_chk_design'        => $bc_chk_des,
            'coll_bc_chk_proof'         => $bc_chk_prf,
            'coll_bc_chk_accounting'    => $bc_chk_acc,
            'coll_bc_chk_ordered'       => $bc_chk_ord,
            'coll_bc_chk_delivered'     => $bc_chk_del,
            // Collateral — Postcards
            'coll_postcards'            => $coll_pc,
            'coll_pc_listing'           => $pc_listing,
            'coll_pc_target_area'       => $pc_target,
            'coll_pc_design_needed'     => $pc_des_needed,
            'coll_pc_chk_design'        => $pc_chk_des,
            'coll_pc_chk_proof'         => $pc_chk_prf,
            'coll_pc_chk_accounting'    => $pc_chk_acc,
            'coll_pc_chk_ordered'       => $pc_chk_ord,
            'coll_pc_chk_delivered'     => $pc_chk_del,
            // Collateral — Digital Brochures
            'coll_brochures'            => $coll_br,
            'coll_br_listing'           => $br_listing,
            'coll_br_chk_design'        => $br_chk_des,
            'coll_br_chk_proof'         => $br_chk_prf,
            'coll_br_chk_created'       => $br_chk_cre,
            // Collateral — Yard Signs
            'coll_yard_signs'           => $coll_ys,
            'coll_ys_qty'               => $ys_qty,
            'coll_ys_design'            => $ys_design,
            'coll_ys_info'              => $ys_info,
            // Collateral — Open House Signs
            'coll_oh_signs'             => $coll_oh,
            'coll_oh_qty'               => $oh_qty,
            'coll_oh_qr_code'           => $oh_qr,
            'coll_oh_qr_url'            => $oh_qr_url,
            'coll_oh_info'              => $oh_info,
            // Collateral — Other
            'coll_other'                => $coll_oth,
            // Digital ads
            'digital_ads_interest'      => $dig_int,
            'digital_ads_vail_daily'    => $dig_vd,
            'digital_ads_aspen_daily'   => $dig_ad,
            'digital_ads_aspen_times'   => $dig_at,
            'digital_ads_spend'         => $dig_spend,
            'digital_ads_duration'      => $dig_dur,
            'digital_ads_use_mh'        => $dig_mh,
            'digital_ads_content_type'  => $dig_ct,
            'digital_ads_property_addr' => $dig_addr,
            // Other notes
            'other_marketing'           => $other_mktg,
        ];

        $result = mkt_save($conn, $save_data, $id, $uid);

        if (is_string($result)) {
            error_log('mkt_save error: ' . $result);
            $flash_err = 'Database error: ' . $result;
            $intake_id = $id;
            $intake    = array_merge(['id' => $id], $_POST);
        } else {
            // $result is true (update) or the new int id (insert)
            $new_id = $id ?: (int)$result;
            if (!$id) {
                // New agent: seed onboarding tasks, land on their profile
                mkt_seed_onboarding_tasks($conn, $new_id);
                header("Location: agent.php?id={$new_id}&tab=overview&open=contact");
            } else {
                header('Location: agent.php?id=' . $id . '&saved=1');
            }
            exit;
        }
    }
}

/* ── Load existing intake for editing ────────────────────────────────────── */
if ($intake_id && !$intake) {
    $r = $conn->query("SELECT * FROM marketing_intakes WHERE id = {$intake_id} LIMIT 1");
    $intake = $r ? $r->fetch_assoc() : null;
    if (!$intake) { header('Location: index.php'); exit; }
}

$conn->close();

/* ── Helpers ─────────────────────────────────────────────────────────────── */
$is_new   = !$intake;
$page_ttl = $is_new ? 'New Marketing Intake' : 'Edit: ' . htmlspecialchars($intake['agent_name'] ?? '');
$today    = date('Y-m-d');

// Pre-split agent name for first/last fields.
// Handles both DB load (agent_name) and POST repopulation (first_name/last_name keys).
$name_parts = explode(' ', $intake['agent_name'] ?? '', 2);
$form_first = htmlspecialchars($intake['first_name'] ?? ($name_parts[0] ?? ''));
$form_last  = htmlspecialchars($intake['last_name']  ?? ($name_parts[1] ?? ''));

function fv(array|null $row, string $key, mixed $default = ''): string {
    return htmlspecialchars((string)($row[$key] ?? $default));
}
function fchk(array|null $row, string $key): bool {
    return (bool)($row[$key] ?? false);
}
// Nullable int (e.g. design_needed: 1/0/null)
function fint(array|null $row, string $key): string|null {
    $v = $row[$key] ?? null;
    return $v === null ? null : (string)(int)$v;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $page_ttl ?> — Mont Haus</title>
  <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css">
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

    .wrap { max-width:860px; margin:32px auto; padding:0 24px 80px; }

    /* Renamed from .page-header — the theme defines that class as a fixed bar
       with min-height:55px and padding:13px 0, which this row was inheriting. */
    .mk-page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; }
    .mk-page-header h1 { font-size:20px; font-weight:700; margin:0; }

    .card { background:#fff; border-radius:4px; padding:24px 28px;
            margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
    .card-title { font-size:13px; font-weight:700; text-transform:uppercase;
                  letter-spacing:.6px; color:#6b7280; margin:0 0 18px; padding-bottom:10px;
                  border-bottom:1px solid #f3f4f6; }

    .alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;
                   border-radius:4px; padding:10px 14px; margin-bottom:18px; font-size:14px; }

    /* Grid layouts */
    .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
    .grid-4 { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:14px; }
    @media (max-width:640px) {
      .grid-2, .grid-3, .grid-4 { grid-template-columns:1fr; }
    }

    /* Form fields */
    .field { display:flex; flex-direction:column; gap:5px; }
    .field label { font-size:11px; font-weight:600; text-transform:uppercase;
                   letter-spacing:.5px; color:#6b7280; }
    .field input[type="text"],
    .field input[type="tel"],
    .field input[type="email"],
    .field input[type="url"],
    .field input[type="date"],
    .field textarea,
    .field select {
      border:1px solid #d1d5db; border-radius:4px; padding:8px 10px;
      font-size:14px; font-family:inherit; width:100%;
      transition:border-color .15s;
    }
    .field input:focus,
    .field textarea:focus,
    .field select:focus { outline:none; border-color:#0184BB; box-shadow:0 0 0 2px rgba(1,132,187,.12); }
    .field input[readonly] { background:#f9fafb; color:#6b7280; cursor:default; }
    .field textarea { resize:vertical; min-height:80px; }
    .field .hint { font-size:11px; color:#9ca3af; margin-top:2px; }

    /* Auto-lookup badge on MLS fields */
    .mls-wrap { position:relative; }
    .mls-badge {
      position:absolute; right:8px; top:50%; transform:translateY(-50%);
      font-size:10px; color:#9ca3af; pointer-events:none;
    }
    .mls-wrap input { padding-right:56px; }

    /* Checkboxes */
    .checklist { display:flex; flex-direction:column; gap:10px; margin-top:4px; }
    .check-item { display:flex; align-items:center; gap:10px; cursor:pointer; }
    .check-item input[type="checkbox"] {
      width:16px; height:16px; cursor:pointer; accent-color:#0184BB; flex-shrink:0;
    }
    .check-item span { font-size:14px; color:#111; }
    .check-item input:checked ~ span { font-weight:600; }

    /* Checklist item with an inline date */
    .check-item-dated { display:flex; align-items:center; gap:10px; cursor:pointer; flex-wrap:wrap; }
    .check-item-dated input[type="checkbox"] {
      width:16px; height:16px; cursor:pointer; accent-color:#0184BB; flex-shrink:0;
    }
    .check-item-dated span { font-size:14px; color:#111; }
    .check-item-dated input:checked ~ span { font-weight:600; }
    .inline-date {
      border:1px solid #d1d5db; border-radius:4px; padding:4px 8px;
      font-size:13px; font-family:inherit; color:#374151;
    }
    .inline-date:focus { outline:none; border-color:#0184BB; }

    /* Horizontal check rows */
    .check-row { flex-direction:row; flex-wrap:wrap; gap:10px 24px; }

    /* Inline radio groups */
    .radio-group { display:flex; gap:20px; margin-top:4px; flex-wrap:wrap; }
    .radio-item  { display:flex; align-items:center; gap:7px; cursor:pointer; font-size:14px; }
    .radio-item input { accent-color:#0184BB; width:15px; height:15px; cursor:pointer; }

    /* Collapsible sections */
    .collapse-target { overflow:hidden; transition:max-height .25s ease, opacity .2s; }
    .collapse-target.hidden { max-height:0 !important; opacity:0; }

    /* Collateral blocks */
    .coll-block { padding:12px 0; }
    .coll-block + .coll-block { border-top:1px solid #f3f4f6; }
    .coll-sub {
      margin-top:12px; padding:14px 16px;
      background:#f9fafb; border-radius:4px; border:1px solid #f3f4f6;
    }
    .sub-section-label {
      font-size:11px; font-weight:700; text-transform:uppercase;
      letter-spacing:.5px; color:#6b7280; margin:14px 0 8px;
    }
    .sub-section-label:first-child { margin-top:0; }

    /* Buttons */
    .btn { display:inline-flex; align-items:center; gap:6px; padding:9px 20px;
           font-size:13px; font-weight:600; border:none; border-radius:4px;
           cursor:pointer; text-decoration:none; letter-spacing:.3px; }
    .btn-primary { background:#f97316; color:#fff; }
    .btn-primary:hover { background:#ea6c0a; }
    .btn-outline { background:transparent; border:1px solid #d1d5db; color:#374151; }
    .btn-outline:hover { background:#f3f4f6; }
    .btn-danger { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
    .btn-danger:hover { background:#fecaca; }
    .form-footer { display:flex; gap:10px; align-items:center; margin-top:24px; }

    /* Divider within card */
    .card-divider { border:none; border-top:1px solid #f3f4f6; margin:18px 0; }

    /* Collateral header row — checkbox label + PDF link side by side */
    .coll-header-row { display:flex; align-items:center; gap:8px; }
    .coll-pdf-link { color:#9ca3af; font-size:14px; text-decoration:none; line-height:1; flex-shrink:0; }
    .coll-pdf-link:hover { color:#0184BB; }

    /* URL fields with open-link button */
    .url-wrap { display: flex; align-items: center; gap: 6px; }
    .url-wrap input[type="url"] { flex: 1; min-width: 0; }
    .url-open-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; flex-shrink: 0;
      border: 1px solid #d1d5db; border-radius: 6px;
      color: #6b7280; text-decoration: none;
      transition: color .15s, border-color .15s, background .15s;
    }
    .url-open-btn:hover { color: #0184BB; border-color: #0184BB; background: #f0f9ff; }
    .url-open-btn.hidden { visibility: hidden; pointer-events: none; }

    /* Check items with indented sub-fields */
    .check-with-sub { margin-bottom: 4px; }
    .check-sub-fields {
      margin-left: 26px;
      margin-top: 10px;
      margin-bottom: 12px;
      padding-left: 12px;
      border-left: 2px solid #e5e7eb;
    }

    /* Quill editor */
    #bio-editor {
      background: #fff;
      border: 1px solid #d1d5db;
      border-radius: 0 0 6px 6px;
      padding: 12px 15px;
    }
    .ql-toolbar.ql-snow {
      border: 1px solid #d1d5db;
      border-radius: 6px 6px 0 0;
      background: #f9fafb;
    }
    .ql-container.ql-snow { border: none; }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $nav_extra = $is_new ? 'New Intake' : 'Edit Intake'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
<div class="wrap">

  <?php if ($flash_err): ?>
    <div class="alert-error"><?= htmlspecialchars($flash_err) ?></div>
  <?php endif; ?>

  <div class="mk-page-header">
    <h1><?= $page_ttl ?></h1>
    <div style="display:flex; gap:8px; align-items:center;">
      <a class="btn btn-outline" href="index.php"><i class="ti ti-arrow-left"></i> Back</a>
      <button type="submit" form="intake-form" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Save Intake</button>
    </div>
  </div>

  <form id="intake-form" method="POST" novalidate action="intake.php<?= $intake_id ? "?id={$intake_id}" : '' ?>">
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="id" value="<?= $intake_id ?>">

    <?php
      $idv = fv($intake,'intake_date', $today);
      $idv_display = $idv ?: $today;
    ?>
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px; color:#9ca3af; font-size:12px; font-weight:600; letter-spacing:.3px; text-transform:uppercase;">
      <i class="ti ti-calendar" style="font-size:13px;"></i>
      Intake Date: <?= $idv_display ? date('m/d/Y', strtotime($idv_display)) : date('m/d/Y') ?>
      <input type="hidden" name="intake_date" value="<?= htmlspecialchars($idv_display ?: $today) ?>">
    </div>

    <!-- ── § 1: Agent Info ─────────────────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-user"></i> Agent Information</p>

      <div class="grid-3" style="margin-bottom:16px;">
        <div class="field">
          <label for="first_name">First Name <span style="color:#f97316">*</span></label>
          <input type="text" id="first_name" name="first_name"
                 value="<?= $form_first ?>" autocomplete="off" required>
        </div>
        <div class="field">
          <label for="last_name">Last Name <span style="color:#f97316">*</span></label>
          <input type="text" id="last_name" name="last_name"
                 value="<?= $form_last ?>" autocomplete="off" required>

        </div>
      </div>

      <div class="grid-2" style="margin-bottom:16px;">
        <div class="field">
          <label for="agent_title">Title</label>
          <input type="text" id="agent_title" name="agent_title" value="<?= fv($intake,'agent_title') ?>"
                 placeholder="e.g. Realtor, Managing Broker">
        </div>
        <div class="field">
          <label for="team_name">Team Name</label>
          <input type="text" id="team_name" name="team_name" value="<?= fv($intake,'team_name') ?>">
        </div>
      </div>

      <div class="grid-2">
        <div class="field">
          <label for="cell_phone">Cell Phone</label>
          <input type="tel" id="cell_phone" name="cell_phone" value="<?= fv($intake,'cell_phone') ?>">
        </div>
        <div class="field">
          <label for="mh_email">MH Email</label>
          <input type="email" id="mh_email" name="mh_email" value="<?= fv($intake,'mh_email') ?>"
                 placeholder="agent@monthaus.com">
        </div>
      </div>
    </div>

    <!-- ── § 2: Digital Presence ──────────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-world"></i> Digital Presence</p>

      <div class="field" style="margin-bottom:16px;">
        <label for="website_url">Website URL</label>
        <input type="url" id="website_url" name="website_url"
               value="<?= fv($intake,'website_url') ?>" placeholder="https://...">
      </div>

      <div class="grid-2">
        <div class="field">
          <label>Instagram</label>
          <input type="text" name="social_ig" value="<?= fv($intake,'social_instagram') ?>"
                 placeholder="@handle or URL">
        </div>
        <div class="field">
          <label>Facebook</label>
          <input type="text" name="social_fb" value="<?= fv($intake,'social_facebook') ?>"
                 placeholder="Page name or URL">
        </div>
        <div class="field">
          <label>LinkedIn</label>
          <input type="text" name="social_li" value="<?= fv($intake,'social_linkedin') ?>"
                 placeholder="@handle or URL">
        </div>
        <div class="field">
          <label>TikTok</label>
          <input type="text" name="social_tt" value="<?= fv($intake,'social_tiktok') ?>"
                 placeholder="@handle or URL">
        </div>
        <div class="field" style="grid-column:1/-1;">
          <label>Other Social / Link</label>
          <input type="text" name="social_oth" value="<?= fv($intake,'social_other') ?>">
        </div>
      </div>
    </div>

    <!-- ── § 3: Current Listings ──────────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-home-2"></i> Current Listings</p>

      <label class="check-item" style="margin-bottom:12px;">
        <input type="checkbox" name="has_listings" id="has_listings_chk"
               <?= fchk($intake,'has_listings') ? 'checked' : '' ?>
               onchange="toggleSection('listing-details-section', this.checked)">
        <span>Agent has active listings</span>
      </label>

      <div id="listing-details-section" class="collapse-target"
           style="max-height:<?= fchk($intake,'has_listings') ? '300px' : '0' ?>; opacity:<?= fchk($intake,'has_listings') ? '1' : '0' ?>;">
        <div class="field">
          <label>Listing Details</label>
          <textarea name="listing_details" placeholder="Address, MLS#, price — one per line…"><?= fv($intake,'listing_details') ?></textarea>
        </div>
      </div>
    </div>

    <!-- ── § 4: Onboarding Checklist ──────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-checklist"></i> Onboarding Checklist</p>
      <div class="checklist">

        <label class="check-item">
          <input type="checkbox" name="chk_email_setup"
                 <?= fchk($intake,'check_email_setup') ? 'checked' : '' ?>>
          <span>Email setup</span>
        </label>

        <div class="check-with-sub">
          <label class="check-item">
            <input type="checkbox" name="chk_email_sig"
                   <?= fchk($intake,'check_email_signature') ? 'checked' : '' ?>>
            <span>Email signature created</span>
          </label>
          <div class="check-sub-fields">
            <div class="field" style="margin-bottom:10px;">
              <label>Dropbox link (signature files)</label>
              <input type="url" name="email_sig_dropbox_url"
                     value="<?= fv($intake,'email_sig_dropbox_url') ?>"
                     placeholder="https://dropbox.com/…">
            </div>
            <div>
              <label class="sub-section-label" style="margin-bottom:6px; display:block;">Installed on</label>
              <div class="radio-group">
                <label class="radio-item">
                  <input type="radio" name="email_sig_client" value="apple_mail"
                         <?= fv($intake,'email_sig_client') === 'apple_mail' ? 'checked' : '' ?>>
                  Apple Mail
                </label>
                <label class="radio-item">
                  <input type="radio" name="email_sig_client" value="outlook"
                         <?= fv($intake,'email_sig_client') === 'outlook' ? 'checked' : '' ?>>
                  Outlook
                </label>
                <label class="radio-item">
                  <input type="radio" name="email_sig_client" value="both"
                         <?= fv($intake,'email_sig_client') === 'both' ? 'checked' : '' ?>>
                  Both
                </label>
              </div>
            </div>
          </div>
        </div>

        <label class="check-item">
          <input type="checkbox" name="chk_mls_confirmed"
                 <?= fchk($intake,'check_mls_confirmed') ? 'checked' : '' ?>>
          <span>Confirm MLS IDs</span>
        </label>

        <label class="check-item">
          <input type="checkbox" name="chk_b2b"
                 <?= fchk($intake,'check_b2b_system') ? 'checked' : '' ?>>
          <span>Send B2B invitation</span>
        </label>

        <label class="check-item">
          <input type="checkbox" name="chk_mktg_overview"
                 <?= fchk($intake,'check_marketing_overview') ? 'checked' : '' ?>>
          <span>Marketing capabilities overview</span>
        </label>

        <label class="check-item">
          <input type="checkbox" name="chk_social_overview"
                 <?= fchk($intake,'check_social_overview') ? 'checked' : '' ?>>
          <span>Social media overview</span>
        </label>

        <label class="check-item">
          <input type="checkbox" name="chk_website_updated"
                 <?= fchk($intake,'check_website_updated') ? 'checked' : '' ?>>
          <span>Update website</span>
        </label>

        <label class="check-item-dated">
          <input type="checkbox" name="chk_social_welcome"
                 <?= fchk($intake,'check_social_welcome_post') ? 'checked' : '' ?>>
          <span>Social welcome post + story</span>
          <input type="date" name="social_welcome_date" class="inline-date"
                 value="<?= fv($intake,'social_welcome_date') ?>">
        </label>

        <label class="check-item-dated">
          <input type="checkbox" name="chk_b2b_announcement"
                 <?= fchk($intake,'check_b2b_announcement') ? 'checked' : '' ?>>
          <span>B2B announcement</span>
          <input type="date" name="b2b_announcement_date" class="inline-date"
                 value="<?= fv($intake,'b2b_announcement_date') ?>">
        </label>

      </div>
    </div>

    <!-- ── § 4.5: Headshot ────────────────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-camera"></i> Headshot</p>
      <div class="checklist">

        <label class="check-item-dated">
          <input type="checkbox" name="chk_hs_scheduled"
                 <?= fchk($intake,'check_headshot_scheduled') ? 'checked' : '' ?>>
          <span>Schedule photoshoot with Jim Paussa</span>
          <input type="date" name="headshot_photoshoot_date" class="inline-date"
                 value="<?= fv($intake,'headshot_photoshoot_date') ?>">
        </label>

        <div class="check-with-sub">
          <label class="check-item">
            <input type="checkbox" name="chk_hs_proofs"
                   <?= fchk($intake,'check_headshot_proofs') ? 'checked' : '' ?>>
            <span>Proofs received</span>
          </label>
          <div class="check-sub-fields">
            <div class="field">
              <label>Dropbox link (proofs)</label>
              <input type="url" name="hs_proofs_url"
                     value="<?= fv($intake,'headshot_proofs_url') ?>"
                     placeholder="https://dropbox.com/…">
            </div>
          </div>
        </div>

        <div class="check-with-sub">
          <label class="check-item">
            <input type="checkbox" name="chk_hs_final"
                   <?= fchk($intake,'check_headshot_final') ? 'checked' : '' ?>>
            <span>Final received</span>
          </label>
          <div class="check-sub-fields">
            <div class="field">
              <label>Dropbox link (final)</label>
              <input type="url" name="hs_final_url"
                     value="<?= fv($intake,'headshot_final_url') ?>"
                     placeholder="https://dropbox.com/…">
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── § 4.7: Bio ────────────────────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-file-text"></i> Bio</p>
      <p style="font-size:12px;color:#9ca3af;margin-bottom:12px;">
        Paste the agent's bio below. Basic formatting (bold, italic, lists) is supported.
      </p>
      <div id="bio-editor" style="min-height:180px; font-size:14px; line-height:1.6;"><?= $intake['bio_text'] ?? '' ?></div>
      <textarea name="bio_text" id="bio-text-hidden" style="display:none;"><?= htmlspecialchars($intake['bio_text'] ?? '') ?></textarea>
    </div>

    <!-- ── § 5: Initial Collateral ────────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-printer"></i> Initial Collateral</p>

      <?php
        $bc_shown  = fchk($intake,'coll_business_cards');
        $pc_shown  = fchk($intake,'coll_postcards');
        $br_shown  = fchk($intake,'coll_brochures');
        $ys_shown  = fchk($intake,'coll_yard_signs');
        $oh_shown  = fchk($intake,'coll_oh_signs');
        $oth_shown = !empty($intake['coll_other']);
        $pc_dn_val = fint($intake,'coll_pc_design_needed');
        $oh_qr_val = fint($intake,'coll_oh_qr_code');
        $oh_qr_url_shown = $oh_shown && $oh_qr_val === '1';
      ?>

      <!-- Business Cards -->
      <div class="coll-block">
        <div class="coll-header-row">
          <label class="check-item">
            <input type="checkbox" name="coll_bc" id="coll_bc_chk"
                   <?= $bc_shown ? 'checked' : '' ?>
                   onchange="toggleSection('coll-bc-sub', this.checked)">
            <span>Business Cards</span>
          </label>
          <a href="/assets/marketing/bizcards.html" target="_blank" rel="noopener"
             class="coll-pdf-link" title="View design templates">
            <i class="ti ti-external-link"></i>
          </a>
        </div>
        <div id="coll-bc-sub" class="collapse-target coll-sub"
             style="max-height:<?= $bc_shown ? '600px' : '0' ?>; opacity:<?= $bc_shown ? '1' : '0' ?>;">
          <div class="grid-3" style="margin-bottom:14px;">
            <div class="field">
              <label>Quantity</label>
              <input type="text" name="bc_qty" value="<?= fv($intake,'coll_bc_qty') ?>" placeholder="e.g. 500">
            </div>
            <div class="field">
              <label>Front Option (1–7)</label>
              <input type="text" name="bc_front" value="<?= fv($intake,'coll_bc_front') ?>" placeholder="e.g. 3">
            </div>
            <div class="field">
              <label>Back Option (1–4)</label>
              <input type="text" name="bc_back" value="<?= fv($intake,'coll_bc_back') ?>" placeholder="e.g. 2">
            </div>
          </div>
          <p class="sub-section-label">Progress</p>
          <div class="checklist check-row">
            <label class="check-item">
              <input type="checkbox" name="bc_chk_design"
                     <?= fchk($intake,'coll_bc_chk_design') ? 'checked' : '' ?>>
              <span>Design</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="bc_chk_proof"
                     <?= fchk($intake,'coll_bc_chk_proof') ? 'checked' : '' ?>>
              <span>Proof</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="bc_chk_accounting"
                     <?= fchk($intake,'coll_bc_chk_accounting') ? 'checked' : '' ?>>
              <span>Accounting Notified</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="bc_chk_ordered"
                     <?= fchk($intake,'coll_bc_chk_ordered') ? 'checked' : '' ?>>
              <span>Ordered</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="bc_chk_delivered"
                     <?= fchk($intake,'coll_bc_chk_delivered') ? 'checked' : '' ?>>
              <span>Delivered</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Yard Signs -->
      <div class="coll-block">
        <div class="coll-header-row">
          <label class="check-item">
            <input type="checkbox" name="coll_ys" id="coll_ys_chk"
                   <?= $ys_shown ? 'checked' : '' ?>
                   onchange="toggleSection('coll-ys-sub', this.checked)">
            <span>Yard Signs</span>
          </label>
          <a href="/assets/marketing/yardsigns.html" target="_blank" rel="noopener"
             class="coll-pdf-link" title="View design templates">
            <i class="ti ti-external-link"></i>
          </a>
        </div>
        <div id="coll-ys-sub" class="collapse-target coll-sub"
             style="max-height:<?= $ys_shown ? '500px' : '0' ?>; opacity:<?= $ys_shown ? '1' : '0' ?>;">
          <div class="grid-2" style="margin-bottom:14px;">
            <div class="field">
              <label>Quantity</label>
              <input type="text" name="ys_qty" value="<?= fv($intake,'coll_ys_qty') ?>" placeholder="e.g. 2">
            </div>
            <div class="field">
              <label>Design</label>
              <div class="radio-group" style="margin-top:6px;">
                <label class="radio-item">
                  <input type="radio" name="ys_design" value="1"
                         <?= fv($intake,'coll_ys_design') === '1' ? 'checked' : '' ?>>
                  Design 1
                </label>
                <label class="radio-item">
                  <input type="radio" name="ys_design" value="2"
                         <?= fv($intake,'coll_ys_design') === '2' ? 'checked' : '' ?>>
                  Design 2
                </label>
                <label class="radio-item">
                  <input type="radio" name="ys_design" value="3"
                         <?= fv($intake,'coll_ys_design') === '3' ? 'checked' : '' ?>>
                  Design 3
                </label>
              </div>
            </div>
          </div>
          <div class="field">
            <label>Sign Info</label>
            <textarea name="ys_info" rows="3"
                      placeholder="Agent name, title, phone, logo notes, etc."><?= fv($intake,'coll_ys_info') ?></textarea>
          </div>
        </div>
      </div>

      <!-- Open House Signs -->
      <div class="coll-block">
        <div class="coll-header-row">
          <label class="check-item">
            <input type="checkbox" name="coll_oh" id="coll_oh_chk"
                   <?= $oh_shown ? 'checked' : '' ?>
                   onchange="toggleSection('coll-oh-sub', this.checked)">
            <span>Open House Signs</span>
          </label>
          <a href="/assets/pdfs/MH_sign_OpenHouse_sample.pdf" target="_blank" rel="noopener"
             class="coll-pdf-link" title="View design template">
            <i class="ti ti-external-link"></i>
          </a>
        </div>
        <div id="coll-oh-sub" class="collapse-target coll-sub"
             style="max-height:<?= $oh_shown ? '600px' : '0' ?>; opacity:<?= $oh_shown ? '1' : '0' ?>;">
          <div class="field" style="margin-bottom:14px;">
            <label>Quantity</label>
            <input type="text" name="oh_qty" value="<?= fv($intake,'coll_oh_qty') ?>" placeholder="e.g. 4"
                   style="max-width:180px;">
          </div>
          <p class="sub-section-label">QR Code?</p>
          <div class="radio-group" style="margin-bottom:12px;">
            <label class="radio-item">
              <input type="radio" name="oh_qr_code" value="1"
                     <?= $oh_qr_val === '1' ? 'checked' : '' ?>
                     onchange="toggleSection('oh-qr-url', true)">
              Yes
            </label>
            <label class="radio-item">
              <input type="radio" name="oh_qr_code" value="0"
                     <?= $oh_qr_val === '0' ? 'checked' : '' ?>
                     onchange="toggleSection('oh-qr-url', false)">
              No
            </label>
          </div>
          <div id="oh-qr-url" class="collapse-target"
               style="max-height:<?= $oh_qr_url_shown ? '80px' : '0' ?>; opacity:<?= $oh_qr_url_shown ? '1' : '0' ?>; margin-bottom:14px;">
            <div class="field">
              <label>QR Code URL</label>
              <input type="url" name="oh_qr_url" value="<?= fv($intake,'coll_oh_qr_url') ?>"
                     placeholder="https://...">
            </div>
          </div>
          <div class="field">
            <label>Sign Info</label>
            <textarea name="oh_info" rows="3"
                      placeholder="Property address, date/time, special instructions, etc."><?= fv($intake,'coll_oh_info') ?></textarea>
          </div>
        </div>
      </div>

      <!-- Digital Brochures -->
      <div class="coll-block">
        <label class="check-item">
          <input type="checkbox" name="coll_br" id="coll_br_chk"
                 <?= $br_shown ? 'checked' : '' ?>
                 onchange="toggleSection('coll-br-sub', this.checked)">
          <span>Digital Brochures</span>
        </label>
        <div id="coll-br-sub" class="collapse-target coll-sub"
             style="max-height:<?= $br_shown ? '400px' : '0' ?>; opacity:<?= $br_shown ? '1' : '0' ?>;">
          <div class="field" style="margin-bottom:14px;">
            <label>Listing</label>
            <input type="text" name="br_listing" value="<?= fv($intake,'coll_br_listing') ?>"
                   placeholder="Address or MLS#">
          </div>
          <p class="sub-section-label">Progress</p>
          <div class="checklist check-row">
            <label class="check-item">
              <input type="checkbox" name="br_chk_design"
                     <?= fchk($intake,'coll_br_chk_design') ? 'checked' : '' ?>>
              <span>Design</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="br_chk_proof"
                     <?= fchk($intake,'coll_br_chk_proof') ? 'checked' : '' ?>>
              <span>Proof</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="br_chk_created"
                     <?= fchk($intake,'coll_br_chk_created') ? 'checked' : '' ?>>
              <span>Created</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Postcards -->
      <div class="coll-block">
        <label class="check-item">
          <input type="checkbox" name="coll_pc" id="coll_pc_chk"
                 <?= $pc_shown ? 'checked' : '' ?>
                 onchange="toggleSection('coll-pc-sub', this.checked)">
          <span>Postcards</span>
        </label>
        <div id="coll-pc-sub" class="collapse-target coll-sub"
             style="max-height:<?= $pc_shown ? '700px' : '0' ?>; opacity:<?= $pc_shown ? '1' : '0' ?>;">
          <div class="grid-2" style="margin-bottom:14px;">
            <div class="field">
              <label>Listing</label>
              <input type="text" name="pc_listing" value="<?= fv($intake,'coll_pc_listing') ?>"
                     placeholder="Address or MLS#">
            </div>
            <div class="field">
              <label>Target Area</label>
              <input type="text" name="pc_target_area" value="<?= fv($intake,'coll_pc_target_area') ?>"
                     placeholder="Neighborhood, zip, etc.">
            </div>
          </div>
          <p class="sub-section-label">Design Needed?</p>
          <div class="radio-group" style="margin-bottom:14px;">
            <label class="radio-item">
              <input type="radio" name="pc_design_needed" value="1"
                     <?= $pc_dn_val === '1' ? 'checked' : '' ?>>
              Yes
            </label>
            <label class="radio-item">
              <input type="radio" name="pc_design_needed" value="0"
                     <?= $pc_dn_val === '0' ? 'checked' : '' ?>>
              No
            </label>
          </div>
          <p class="sub-section-label">Progress</p>
          <div class="checklist check-row">
            <label class="check-item">
              <input type="checkbox" name="pc_chk_design"
                     <?= fchk($intake,'coll_pc_chk_design') ? 'checked' : '' ?>>
              <span>Design</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="pc_chk_proof"
                     <?= fchk($intake,'coll_pc_chk_proof') ? 'checked' : '' ?>>
              <span>Proof</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="pc_chk_accounting"
                     <?= fchk($intake,'coll_pc_chk_accounting') ? 'checked' : '' ?>>
              <span>Accounting Notified</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="pc_chk_ordered"
                     <?= fchk($intake,'coll_pc_chk_ordered') ? 'checked' : '' ?>>
              <span>Ordered</span>
            </label>
            <label class="check-item">
              <input type="checkbox" name="pc_chk_delivered"
                     <?= fchk($intake,'coll_pc_chk_delivered') ? 'checked' : '' ?>>
              <span>Delivered</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Other -->
      <div class="coll-block">
        <label class="check-item">
          <input type="checkbox" id="coll_oth_chk"
                 <?= $oth_shown ? 'checked' : '' ?>
                 onchange="toggleSection('coll-other-sub', this.checked)">
          <span>Other</span>
        </label>
        <div id="coll-other-sub" class="collapse-target coll-sub"
             style="max-height:<?= $oth_shown ? '120px' : '0' ?>; opacity:<?= $oth_shown ? '1' : '0' ?>;">
          <div class="field">
            <label>Specify</label>
            <input type="text" name="coll_oth" value="<?= fv($intake,'coll_other') ?>">
          </div>
        </div>
      </div>

    </div><!-- /collateral card -->

    <!-- ── § 6: Digital Advertising ──────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-device-laptop"></i> Digital Advertising</p>

      <label class="check-item" style="margin-bottom:12px;">
        <input type="checkbox" name="dig_interest" id="dig_interest_chk"
               <?= fchk($intake,'digital_ads_interest') ? 'checked' : '' ?>
               onchange="toggleSection('dig-ads-detail', this.checked)">
        <span>Agent interested in digital advertising</span>
      </label>

      <?php
        $dig_shown  = fchk($intake,'digital_ads_interest');
        $dig_ct_val = $intake['digital_ads_content_type'] ?? '';
        $dig_mh_val = $intake['digital_ads_use_mh'] ?? '';
        $addr_shown = $dig_shown && $dig_ct_val === 'property_address';
      ?>
      <div id="dig-ads-detail" class="collapse-target"
           style="max-height:<?= $dig_shown ? '1000px' : '0' ?>; opacity:<?= $dig_shown ? '1' : '0' ?>;">

        <hr class="card-divider">

        <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin:0 0 10px;">Publications</p>
        <div class="checklist check-row" style="margin-bottom:18px;">
          <label class="check-item">
            <input type="checkbox" name="dig_vail_daily"
                   <?= fchk($intake,'digital_ads_vail_daily') ? 'checked' : '' ?>>
            <span>Vail Daily</span>
          </label>
          <label class="check-item">
            <input type="checkbox" name="dig_aspen_daily"
                   <?= fchk($intake,'digital_ads_aspen_daily') ? 'checked' : '' ?>>
            <span>Aspen Daily News</span>
          </label>
          <label class="check-item">
            <input type="checkbox" name="dig_aspen_times"
                   <?= fchk($intake,'digital_ads_aspen_times') ? 'checked' : '' ?>>
            <span>Aspen Times</span>
          </label>
        </div>

        <div class="grid-2" style="margin-bottom:18px;">
          <div class="field">
            <label>Monthly Budget</label>
            <input type="text" name="dig_spend" value="<?= fv($intake,'digital_ads_spend') ?>"
                   placeholder="e.g. $500/mo">
          </div>
          <div class="field">
            <label>Duration</label>
            <input type="text" name="dig_duration" value="<?= fv($intake,'digital_ads_duration') ?>"
                   placeholder="e.g. 3 months, ongoing">
          </div>
        </div>

        <div style="margin-bottom:18px;">
          <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin:0 0 8px;">Use MH Designs?</p>
          <div class="radio-group">
            <label class="radio-item">
              <input type="radio" name="dig_use_mh" value="1"
                     <?= $dig_mh_val === '1' || $dig_mh_val === 1 ? 'checked' : '' ?>>
              Yes — Mont Haus creates the ad
            </label>
            <label class="radio-item">
              <input type="radio" name="dig_use_mh" value="0"
                     <?= ($dig_mh_val === '0' || $dig_mh_val === 0) && $dig_mh_val !== '' ? 'checked' : '' ?>>
              No — Agent provides creative
            </label>
          </div>
        </div>

        <div>
          <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin:0 0 8px;">Ad Content Type</p>
          <div class="radio-group">
            <label class="radio-item">
              <input type="radio" name="dig_content_type" value="property_address"
                     id="dig_ct_prop"
                     <?= $dig_ct_val === 'property_address' ? 'checked' : '' ?>
                     onchange="toggleSection('dig-prop-addr', true)">
              Specific Property
            </label>
            <label class="radio-item">
              <input type="radio" name="dig_content_type" value="content"
                     id="dig_ct_content"
                     <?= $dig_ct_val === 'content' ? 'checked' : '' ?>
                     onchange="toggleSection('dig-prop-addr', false)">
              General Content / Brand
            </label>
          </div>

          <div id="dig-prop-addr" class="collapse-target"
               style="max-height:<?= $addr_shown ? '80px' : '0' ?>; opacity:<?= $addr_shown ? '1' : '0' ?>; margin-top:12px;">
            <div class="field">
              <label>Property Address</label>
              <input type="text" name="dig_property_addr"
                     value="<?= fv($intake,'digital_ads_property_addr') ?>"
                     placeholder="Full property address">
            </div>
          </div>
        </div>

      </div><!-- /dig-ads-detail -->
    </div>

    <!-- ── § 7: Other Marketing Notes ────────────────────────────────── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-notes"></i> Other Marketing Notes</p>
      <div class="field">
        <textarea name="other_marketing" rows="4"
                  placeholder="Any additional context, requests, or notes…"><?= fv($intake,'other_marketing') ?></textarea>
      </div>
    </div>

    <!-- ── Form footer ────────────────────────────────────────────────── -->
    <div class="form-footer">
      <button type="submit" class="btn btn-primary">
        <i class="ti ti-device-floppy"></i>
        <?= $is_new ? 'Save Intake' : 'Update Intake' ?>
      </button>
      <a class="btn btn-outline" href="index.php">Cancel</a>
      <?php if (!$is_new): ?>
        <span style="flex:1;"></span>
        <button type="button" class="btn btn-danger"
                onclick="if(confirm('Delete this intake? This cannot be undone.')) document.getElementById('delete-intake-form').submit();">
          <i class="ti ti-trash"></i> Delete Intake
        </button>
      <?php endif; ?>
    </div>

  </form>

<?php if (!$is_new): ?>
<form id="delete-intake-form" method="POST" action="index.php" style="display:none;">
  <input type="hidden" name="_action" value="delete">
  <input type="hidden" name="id" value="<?= $intake_id ?>">
</form>
<?php endif; ?>

</div><!-- /wrap -->
</div><!-- /pc-container -->

<script>
function toggleSection(id, show) {
    var el = document.getElementById(id);
    if (!el) return;
    if (show) {
        el.classList.remove('hidden');
        el.style.maxHeight = '1000px';
        el.style.opacity   = '1';
    } else {
        el.style.maxHeight = '0';
        el.style.opacity   = '0';
    }
}

// Wire up property-address radio toggle on both radios
document.querySelectorAll('[name="dig_content_type"]').forEach(function(r) {
    r.addEventListener('change', function() {
        toggleSection('dig-prop-addr', this.value === 'property_address');
    });
});
</script>

<script>
// URL fields: wrap each in a flex row and add a clickable open-link icon
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('input[type="url"]').forEach(function(input) {
    var wrap = document.createElement('div');
    wrap.className = 'url-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('a');
    btn.className = 'url-open-btn' + (input.value ? '' : ' hidden');
    btn.target = '_blank';
    btn.rel = 'noopener';
    btn.href = input.value || '#';
    btn.title = 'Open link';
    btn.innerHTML = '<i class="ti ti-external-link" style="font-size:15px;"></i>';
    wrap.appendChild(btn);

    input.addEventListener('input', function() {
      var v = input.value.trim();
      if (v) {
        btn.href = v;
        btn.classList.remove('hidden');
      } else {
        btn.href = '#';
        btn.classList.add('hidden');
      }
    });
  });
});
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
(function() {
  // Guard: if Quill failed to load from CDN, skip gracefully — form still submits
  if (typeof Quill === 'undefined') return;

  var quill;
  try {
    quill = new Quill('#bio-editor', {
      theme: 'snow',
      modules: {
        toolbar: [
          ['bold', 'italic', 'underline'],
          [{ 'list': 'ordered'}, { 'list': 'bullet' }],
          ['clean']
        ],
        clipboard: { matchVisual: false }
      }
    });

    // Pre-populate from saved HTML
    var saved = document.getElementById('bio-text-hidden').value;
    if (saved) quill.root.innerHTML = saved;
  } catch(e) {
    console.warn('Quill init error:', e);
    return;
  }

  // Sync to hidden textarea before form submit
  var form = document.getElementById('intake-form');
  if (form) {
    form.addEventListener('submit', function() {
      try {
        document.getElementById('bio-text-hidden').value = quill.root.innerHTML;
      } catch(e) { /* non-fatal */ }
    });
  }
})();
</script>

</body>
</html>
