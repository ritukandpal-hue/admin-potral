<?php
// FILE: public_html/vendor/print-jobs.php
// Print vendor job board — view assigned jobs, accept/decline, update status, upload proof

$configs = [
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__) . '/config.php',
];
foreach ($configs as $cfg) { if (file_exists($cfg)) { require_once $cfg; break; } }
if (!defined('PUBLIC_PATH')) die('<p style="color:red;font-family:sans-serif;padding:40px">config.php not found</p>');
if (session_status() === PHP_SESSION_NONE) {
    session_start(['cookie_httponly'=>true,'cookie_secure'=>isset($_SERVER['HTTPS']),'cookie_samesite'=>'Lax','use_strict_mode'=>true]);
}
foreach (['db.php','auth.php','functions.php'] as $f) require_once PUBLIC_PATH.'/includes/'.$f;

// Vendor auth check
if (empty($_SESSION['user_id']) || ($_SESSION['portal'] ?? '') !== 'vendor') {
    header('Location: ' . (defined('SITE_URL') ? SITE_URL : '') . '/vendor/login.php'); exit;
}

$db   = getDB();
$user = currentUser();

// Fetch vendor record
$vs = $db->prepare("SELECT * FROM vendors WHERE user_id=? LIMIT 1");
$vs->execute([$user['id']]);
$vendor = $vs->fetch();

// Guard — must be a print vendor
if (!$vendor || empty($vendor['is_print_vendor'])) {
    header('Location: index.php'); exit;
}
$vid = (int)$vendor['id'];

require_once __DIR__ . '/layout.php';

$msg = '';
$err = '';

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = clean($_POST['action'] ?? '');
    $req_id = cleanInt($_POST['request_id'] ?? 0);

    // Verify this job belongs to this vendor
    $owns = $db->prepare("SELECT id, status FROM print_requests WHERE id=? AND vendor_id=? LIMIT 1");
    $owns->execute([$req_id, $vid]);
    $job = $owns->fetch();

    // Accept
    if ($action === 'accept' && $job && $job['status'] === 'approved') {
        $db->prepare("UPDATE print_requests SET status='vendor_confirmed', vendor_confirmed_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$req_id]);
        $db->prepare("INSERT INTO print_request_logs (request_id, user_id, from_status, to_status, note) VALUES (?,?,'approved','vendor_confirmed','Job accepted by vendor')")->execute([$req_id, $user['id']]);
        $msg = 'Job accepted. Update to In Print when production starts.';
    }

    // Decline
    if ($action === 'decline' && $job && $job['status'] === 'approved') {
        $reason = clean($_POST['decline_reason'] ?? '');
        $other  = clean($_POST['decline_other']  ?? '');
        $note   = 'Declined: ' . ($reason === 'other' ? ($other ?: 'No reason') : $reason) . ' — Dausto CS notified';
        $db->prepare("UPDATE print_requests SET vendor_id=NULL, vendor_confirmed_at=NULL, updated_at=NOW() WHERE id=?")->execute([$req_id]);
        $db->prepare("INSERT INTO print_request_logs (request_id, user_id, from_status, to_status, note) VALUES (?,?,'approved','approved',?)")->execute([$req_id, $user['id'], $note]);
        $msg = 'Job declined. Dausto CS has been notified for reassignment.';
    }

    // Mark in print
    if ($action === 'mark_in_print' && $job && $job['status'] === 'vendor_confirmed') {
        $db->prepare("UPDATE print_requests SET status='in_print', updated_at=NOW() WHERE id=?")->execute([$req_id]);
        $db->prepare("INSERT INTO print_request_logs (request_id, user_id, from_status, to_status, note) VALUES (?,?,'vendor_confirmed','in_print','Production started by vendor')")->execute([$req_id, $user['id']]);
        $msg = 'Status updated to In Print.';
    }

    // Upload proof
    if ($action === 'upload_proof' && $job && $job['status'] === 'in_print') {
        if (!empty($_FILES['proof_image']['name'])) {
            $ext = strtolower(pathinfo((string)($_FILES['proof_image']['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) {
                $err = 'Proof must be JPG or PNG.';
            } elseif ($_FILES['proof_image']['size'] > 5 * 1024 * 1024) {
                $err = 'Proof image must be under 5 MB.';
            } else {
                $dir = PUBLIC_PATH . '/uploads/print-proofs/' . $req_id . '/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $fname = 'proof.' . $ext;
                if (@move_uploaded_file($_FILES['proof_image']['tmp_name'], $dir . $fname)) {
                    $path = '/uploads/print-proofs/' . $req_id . '/' . $fname;
                    $db->prepare("UPDATE print_requests SET proof_image_path=?, status='qc_check', updated_at=NOW() WHERE id=?")->execute([$path, $req_id]);
                    $db->prepare("INSERT INTO print_request_logs (request_id, user_id, from_status, to_status, note) VALUES (?,?,'in_print','qc_check','Print proof uploaded — awaiting Dausto QC')")->execute([$req_id, $user['id']]);
                    $msg = 'Proof uploaded. Dausto QC team will review before dispatch.';
                } else {
                    $err = 'File upload failed. Check folder permissions.';
                }
            }
        } else {
            $err = 'Please select a proof image to upload.';
        }
    }

    // Mark dispatched
    if ($action === 'mark_dispatched' && $job && $job['status'] === 'ready_to_dispatch') {
        $courier = clean($_POST['courier_name']    ?? '');
        $awb     = clean($_POST['tracking_number'] ?? '');
        if (!$courier || !$awb) {
            $err = 'Please enter courier name and AWB/tracking number.';
        } else {
            $db->prepare("UPDATE print_requests SET status='dispatched', courier_name=?, tracking_number=?, updated_at=NOW() WHERE id=?")->execute([$courier, $awb, $req_id]);
            $db->prepare("INSERT INTO print_request_logs (request_id, user_id, from_status, to_status, note) VALUES (?,?,'ready_to_dispatch','dispatched',?)")->execute([$req_id, $user['id'], 'Dispatched via '.$courier.' — AWB: '.$awb]);
            $msg = 'Marked as dispatched. Tracking saved.';
        }
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$tab = clean($_GET['tab'] ?? 'new');

$tab_statuses = [
    'new'        => ['approved'],
    'active'     => ['vendor_confirmed','in_print','qc_check','ready_to_dispatch'],
    'dispatched' => ['dispatched'],
    'done'       => ['delivered','invoiced'],
    'all'        => ['approved','vendor_confirmed','in_print','qc_check','ready_to_dispatch','dispatched','delivered','invoiced'],
];
$statuses = $tab_statuses[$tab] ?? $tab_statuses['new'];
$ph       = implode(',', array_fill(0, count($statuses), '?'));

$js = $db->prepare("
    SELECT pr.*,
        pt.name template_name, pt.thumbnail_path, pt.file_path template_file,
        c.company_name client_company,
        u.name employee_name
    FROM print_requests pr
    JOIN print_templates pt ON pt.id = pr.template_id
    JOIN clients c ON c.id = pr.client_id
    JOIN users u ON u.id = pr.employee_user_id
    WHERE pr.vendor_id=? AND pr.status IN ($ph)
    ORDER BY
        CASE pr.status WHEN 'approved' THEN 1 WHEN 'ready_to_dispatch' THEN 2
            WHEN 'vendor_confirmed' THEN 3 WHEN 'in_print' THEN 4 WHEN 'qc_check' THEN 5 ELSE 6 END,
        pr.tat_deadline ASC, pr.created_at ASC
");
$js->execute(array_merge([$vid], $statuses));
$jobs = $js->fetchAll();

// Counts for tabs + badge in nav
$counts = [];
foreach ($tab_statuses as $t => $sts) {
    $cph = implode(',', array_fill(0, count($sts), '?'));
    $cs  = $db->prepare("SELECT COUNT(*) FROM print_requests WHERE vendor_id=? AND status IN ($cph)");
    $cs->execute(array_merge([$vid], $sts));
    $counts[$t] = (int)$cs->fetchColumn();
}

// Detail view
$detail_id   = cleanInt($_GET['detail'] ?? 0);
$detail      = null;
$detail_logs = [];
if ($detail_id) {
    $ds = $db->prepare("
        SELECT pr.*, pt.name template_name, pt.file_path template_file,
               c.company_name client_company, u.name employee_name
        FROM print_requests pr
        JOIN print_templates pt ON pt.id = pr.template_id
        JOIN clients c ON c.id = pr.client_id
        JOIN users u ON u.id = pr.employee_user_id
        WHERE pr.id=? AND pr.vendor_id=? LIMIT 1
    ");
    $ds->execute([$detail_id, $vid]);
    $detail = $ds->fetch();
    if ($detail) {
        $ls = $db->prepare("SELECT * FROM print_request_logs WHERE request_id=? ORDER BY created_at ASC");
        $ls->execute([$detail_id]);
        $detail_logs = $ls->fetchAll();
    }
}

$cat_labels = [
    'visiting_card' => 'Visiting Cards',
    'letter_head'   => 'Letter Heads',
    'envelope'      => 'Envelopes',
    'comp_slip'     => 'Comp Slips',
];

$status_meta = [
    'approved'          => ['⏳','New Job',          '#92400E','#FEF3C7'],
    'vendor_confirmed'  => ['✅','Accepted',          '#065F46','#D1FAE5'],
    'in_print'          => ['🖨️','In Print',          '#1E40AF','#DBEAFE'],
    'qc_check'          => ['🔍','Awaiting QC',       '#5B21B6','#EDE9FE'],
    'ready_to_dispatch' => ['📦','Ready to Dispatch', '#0F766E','#CCFBF1'],
    'dispatched'        => ['🚚','Dispatched',        '#92400E','#FEF3C7'],
    'delivered'         => ['✅','Delivered',         '#065F46','#D1FAE5'],
    'invoiced'          => ['🧾','Invoiced',          '#1E40AF','#EFF6FF'],
];

function tatBadge2(string $deadline, string $status): array {
    if (in_array($status, ['delivered','invoiced'])) return ['done','—'];
    if (!$deadline) return ['','No TAT set'];
    $diff = strtotime($deadline) - time();
    if ($diff < 0)      return ['breached','TAT BREACHED'];
    if ($diff < 43200)  return ['urgent','URGENT &lt;'.round($diff/3600).'h'];
    if ($diff < 172800) return ['soon',ceil($diff/3600).'h left'];
    return ['ok',ceil($diff/86400).'d left'];
}

$decline_reasons = [
    'capacity_full'        => 'Capacity full — production queue at limit',
    'material_unavailable' => 'Material unavailable',
    'technical_issue'      => 'Technical issue — equipment downtime',
    'design_file_issue'    => 'Design file issue — master file unusable',
    'other'                => 'Other (please specify)',
];

// Pass pending_print_jobs to nav badge
$stats_for_nav = ['pending_print_jobs' => $counts['new'] ?? 0];

echo vendorHead('Print Jobs');
?>

<style>
.tab-bar{display:flex;gap:4px;background:#fff;border-bottom:1px solid #E2E8F0;padding:0 26px}
.v-tab{padding:11px 14px;font-size:12.5px;font-weight:700;color:#64748B;text-decoration:none;border-bottom:2.5px solid transparent;transition:all .15s;white-space:nowrap;display:flex;align-items:center;gap:5px}
.v-tab:hover{color:#059669}
.v-tab.active{color:#059669;border-bottom-color:#059669}
.tb{background:#E2E8F0;color:#64748B;padding:1px 6px;border-radius:10px;font-size:9.5px;font-weight:800}
.v-tab.active .tb{background:#059669;color:#fff}
.tb.red{background:#FEE2E2;color:#991B1B}
/* JOB CARDS */
.job-card{background:#fff;border-radius:12px;border:1.5px solid #E8ECF2;margin-bottom:10px;overflow:hidden;transition:box-shadow .15s}
.job-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.08)}
.job-card.sel{border-color:#059669;border-width:2px}
.job-head{display:flex;align-items:center;gap:12px;padding:12px 16px}
.job-thumb{width:52px;height:38px;border-radius:7px;object-fit:cover;background:#F1F5F9;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:#64748B;flex-shrink:0;overflow:hidden}
.job-thumb img{width:100%;height:100%;object-fit:cover}
.s-badge{display:inline-flex;align-items:center;gap:3px;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:700}
.tat-ok{color:#16A34A;font-weight:700;font-size:11px}
.tat-soon{color:#D97706;font-weight:700;font-size:11px}
.tat-urgent{color:#DC2626;font-weight:800;font-size:11px}
.tat-breached{background:#FEE2E2;color:#991B1B;font-weight:800;font-size:11px;padding:2px 8px;border-radius:5px}
.job-actions{display:flex;gap:7px;flex-wrap:wrap;padding:10px 16px;background:#FAFBFC;border-top:1px solid #F0F3F6}
/* DETAIL */
.detail-card{background:#fff;border-radius:12px;border:2px solid #059669;margin-bottom:18px;overflow:hidden}
.detail-head-bar{padding:14px 20px;background:linear-gradient(135deg,#059669,#047857);color:#fff}
.d2-grid{display:grid;grid-template-columns:1fr 1fr;gap:0}
.d2-cell{padding:10px 18px;border-bottom:1px solid #F8FAFC}
.d2-cell:nth-child(odd){border-right:1px solid #F8FAFC}
.d2-label{font-size:9.5px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:3px}
.d2-val{font-size:13px;font-weight:600;color:#0F172A}
.log-entry{display:flex;gap:10px;padding:8px 18px;border-bottom:1px solid #F8FAFC}
.log-entry:last-child{border-bottom:none}
.log-dot2{width:8px;height:8px;border-radius:50%;background:#059669;flex-shrink:0;margin-top:5px}
/* BTNS */
.btn-v-green{background:#059669;color:#fff;border:none}.btn-v-green:hover{background:#047857}
.btn-v-danger{background:#FEF2F2;color:#DC2626;border:1px solid #FCA5A5}
.btn-v-teal{background:#0D9488;color:#fff;border:none}.btn-v-teal:hover{background:#0F766E}
.btn-v-amber{background:#D97706;color:#fff;border:none}.btn-v-amber:hover{background:#B45309}
.btn-v-blue{background:#2E3D9A;color:#fff;border:none}.btn-v-blue:hover{background:#1E2D7A}
/* MODAL */
.modal-bd{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px}
.modal-box{background:#fff;border-radius:14px;width:460px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-h{padding:14px 18px;border-bottom:1px solid #F0F3F6;display:flex;align-items:center;justify-content:space-between}
.modal-b{padding:18px}
.modal-f{padding:12px 18px;border-top:1px solid #F0F3F6;display:flex;justify-content:flex-end;gap:9px}
</style>

</head>
<body>
<?php echo vendorNav($vendor, $stats_for_nav); ?>

<main class="main">
  <div class="topbar">
    <div>
      <h1 style="font-size:19px;font-weight:900;font-family:'Playfair Display',serif">🖨️ Print Jobs</h1>
      <p style="font-size:12px;color:#94A3B8;margin-top:2px">Accept jobs, update status, upload print proof</p>
    </div>
    <div style="font-size:12.5px;color:#64748B;font-weight:600">
      <?= htmlspecialchars((string)($vendor['company_name'] ?? '')) ?>
    </div>
  </div>

  <!-- TABS -->

  <div class="tab-bar">
    <?php foreach ([
      'new'        => ['⏳','New Jobs'],
      'active'     => ['⚡','Active'],
      'dispatched' => ['🚚','Dispatched'],
      'done'       => ['✅','Completed'],
      'all'        => ['📋','All'],
    ] as $t => [$ico, $lbl]): ?>
    <a href="?tab=<?= $t ?>" class="v-tab <?= $tab === $t ? 'active' : '' ?>">
      <?= $ico ?> <?= $lbl ?>
      <span class="tb <?= ($counts[$t] ?? 0) > 0 && $t === 'new' ? 'red' : '' ?>">
        <?= $counts[$t] ?? 0 ?>
      </span>
    </a>
    <?php endforeach ?>
  </div>

  <div class="page-content">

```
<?php if ($msg): ?>
<div class="alert alert-success">✅ <?= htmlspecialchars((string)$msg) ?></div>
<?php endif ?>
<?php if ($err): ?>
<div class="alert alert-error">⚠️ <?= htmlspecialchars((string)$err) ?></div>
<?php endif ?>

<!-- DETAIL VIEW -->
<?php if ($detail): ?>
<?php
$sm  = $status_meta[$detail['status']] ?? ['📋','—','#374151','#F1F5F9'];
$cd  = json_decode((string)($detail['contact_data'] ?? '{}'), true) ?: [];
[$tc, $tt] = tatBadge2((string)($detail['tat_deadline'] ?? ''), (string)($detail['status'] ?? ''));
?>
<div class="detail-card">
  <div class="detail-head-bar">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
      <div>
        <div style="font-size:16px;font-weight:900"><?= htmlspecialchars((string)($detail['request_number'] ?? '')) ?></div>
        <div style="font-size:11.5px;color:rgba(255,255,255,.7);margin-top:3px">
          <?= htmlspecialchars((string)($cat_labels[$detail['category'] ?? ''] ?? '')) ?> ·
          <?= (int)($detail['quantity'] ?? 0) ?> pcs ·
          <?= htmlspecialchars((string)($detail['client_company'] ?? '')) ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <span style="background:<?= $sm[3] ?>;color:<?= $sm[2] ?>;font-size:11px;font-weight:800;padding:4px 12px;border-radius:20px">
          <?= $sm[0] ?> <?= $sm[1] ?>
        </span>
        <a href="?tab=<?= $tab ?>" class="btn btn-ghost btn-sm">✕ Close</a>
      </div>
    </div>
  </div>

  <!-- Contact snapshot -->
  <?php if (!empty($cd)): ?>
  <div style="padding:12px 18px;border-bottom:1px solid #F0F3F6">
    <div style="font-size:9.5px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">Employee Print Details</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px">
      <?php foreach ($cd as $fk => $fv): ?>
      <div style="background:#F8FAFC;border-radius:7px;padding:7px 10px">
        <div style="font-size:9.5px;color:#94A3B8;font-weight:700;text-transform:uppercase"><?= htmlspecialchars((string)$fk) ?></div>
        <div style="font-size:12.5px;font-weight:600;color:#0F172A;margin-top:2px"><?= htmlspecialchars((string)$fv) ?></div>
      </div>
      <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>

  <!-- Details grid -->
  <div class="d2-grid">
    <div class="d2-cell"><div class="d2-label">Template</div><div class="d2-val"><?= htmlspecialchars((string)($detail['template_name'] ?? '—')) ?></div></div>
    <div class="d2-cell"><div class="d2-label">Quantity</div><div class="d2-val"><?= (int)($detail['quantity'] ?? 0) ?> pcs</div></div>
    <div class="d2-cell"><div class="d2-label">Your Amount</div><div class="d2-val" style="color:#059669;font-weight:800">Rs.<?= number_format((float)($detail['total_amount'] ?? 0), 0) ?></div></div>
    <div class="d2-cell">
      <div class="d2-label">TAT Deadline</div>
      <div class="d2-val">
        <?php if (!empty($detail['tat_deadline'])): ?>
        <?= date('d M Y', strtotime((string)($detail['tat_deadline'] ?? ''))) ?>
        <span class="tat-<?= $tc ?>" style="margin-left:6px">(<?= $tt ?>)</span>
        <?php else: ?><span style="color:#94A3B8">Not set</span><?php endif ?>
      </div>
    </div>
    <?php if (!empty($detail['proof_image_path'])): ?>
    <div class="d2-cell" style="grid-column:span 2"><div class="d2-label">Print Proof</div><div class="d2-val"><a href="<?= htmlspecialchars(SITE_URL . (string)($detail['proof_image_path'] ?? '')) ?>" target="_blank" class="btn btn-ghost btn-sm">🔍 View Proof ↗</a></div></div>
    <?php endif ?>
    <?php if (!empty($detail['template_file'])): ?>
    <div class="d2-cell" style="grid-column:span 2"><div class="d2-label">Master Design File</div><div class="d2-val"><a href="<?= htmlspecialchars(SITE_URL . (string)($detail['template_file'] ?? '')) ?>" target="_blank" class="btn btn-ghost btn-sm">📄 Download Master ↗</a></div></div>
    <?php endif ?>
  </div>

  <!-- Action bar -->
  <div style="padding:12px 18px;background:#FAFBFC;border-top:1px solid #F0F3F6;display:flex;flex-wrap:wrap;gap:8px">
    <?php if ($detail['status'] === 'approved'): ?>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>"><button type="submit" class="btn btn-v-green">✅ Accept Job</button></form>
    <button class="btn btn-v-danger" onclick="document.getElementById('decModal').style.display='flex';document.getElementById('dm_rid').value=<?= (int)$detail['id'] ?>">✕ Decline</button>
    <?php endif ?>
    <?php if ($detail['status'] === 'vendor_confirmed'): ?>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="mark_in_print"><input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>"><button type="submit" class="btn btn-v-blue">🖨️ Start Printing</button></form>
    <?php endif ?>
    <?php if ($detail['status'] === 'in_print'): ?>
    <button class="btn btn-v-teal" onclick="document.getElementById('proofModal').style.display='flex';document.getElementById('pm_rid').value=<?= (int)$detail['id'] ?>">📷 Upload Proof</button>
    <?php endif ?>
    <?php if ($detail['status'] === 'ready_to_dispatch'): ?>
    <button class="btn btn-v-amber" onclick="document.getElementById('dispatchModal').style.display='flex';document.getElementById('disp_rid').value=<?= (int)$detail['id'] ?>">🚚 Mark Dispatched</button>
    <?php endif ?>
    <a href="?tab=<?= $tab ?>" class="btn btn-ghost btn-sm">✕ Close</a>
  </div>

  <!-- Log -->
  <?php if (!empty($detail_logs)): ?>
  <div style="border-top:1px solid #F0F3F6">
    <div style="padding:10px 18px;font-size:9.5px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.07em">Status History</div>
    <?php foreach (array_reverse($detail_logs) as $log): ?>
    <div class="log-entry">
      <div class="log-dot2"></div>
      <div>
        <div style="font-size:12px;font-weight:700;color:#0F172A">
          <?php if ($log['from_status']): ?><?= htmlspecialchars((string)($log['from_status'] ?? '')) ?> → <?php endif ?>
          <?= htmlspecialchars((string)($log['to_status'] ?? '')) ?>
        </div>
        <?php if (!empty($log['note'])): ?>
        <div style="font-size:11px;color:#64748B;margin-top:1px"><?= htmlspecialchars((string)($log['note'] ?? '')) ?></div>
        <?php endif ?>
        <div style="font-size:10.5px;color:#94A3B8;margin-top:1px"><?= date('d M Y, H:i', strtotime((string)($log['created_at'] ?? ''))) ?></div>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php endif ?>

<!-- JOB LIST -->
<?php if (empty($jobs)): ?>
<div style="background:#fff;border-radius:12px;border:1px solid #E8ECF2;padding:44px 20px;text-align:center;color:#94A3B8">
  <div style="font-size:40px;margin-bottom:12px">🖨️</div>
  <div style="font-size:14px;font-weight:700;color:#374151">No <?= $tab === 'new' ? 'new' : $tab ?> jobs</div>
  <div style="font-size:12px;margin-top:6px"><?= $tab === 'new' ? 'New print requests assigned to you will appear here.' : 'Nothing here yet.' ?></div>
</div>
<?php else: ?>
<?php foreach ($jobs as $j):
  $sm2 = $status_meta[$j['status']] ?? ['📋','—','#374151','#F1F5F9'];
  [$tc2, $tt2] = tatBadge2((string)($j['tat_deadline'] ?? ''), (string)($j['status'] ?? ''));
  $sel = $detail_id === (int)$j['id'];
?>
<div class="job-card <?= $sel ? 'sel' : '' ?> <?= $tc2 === 'breached' ? 'breached' : '' ?>">
  <div class="job-head">
    <div class="job-thumb">
      <?php if (!empty($j['thumbnail_path'])): ?>
      <img src="<?= htmlspecialchars(SITE_URL . (string)($j['thumbnail_path'] ?? '')) ?>" alt="">
      <?php else: ?>
      <?= htmlspecialchars(strtoupper(substr((string)($j['category'] ?? 'P'), 0, 2))) ?>
      <?php endif ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:800;color:#059669"><?= htmlspecialchars((string)($j['request_number'] ?? '')) ?></div>
      <div style="font-size:11px;color:#64748B;margin-top:1px">
        <?= htmlspecialchars((string)($cat_labels[$j['category'] ?? ''] ?? '')) ?> ·
        <?= (int)$j['quantity'] ?> pcs ·
        <?= htmlspecialchars((string)($j['client_company'] ?? '')) ?>
      </div>
      <div style="font-size:11px;color:#94A3B8;margin-top:1px">
        <?= htmlspecialchars((string)($j['employee_name'] ?? '')) ?> ·
        Rs.<?= number_format((float)$j['total_amount'], 0) ?>
      </div>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <span class="s-badge" style="background:<?= $sm2[3] ?>;color:<?= $sm2[2] ?>"><?= $sm2[0] ?> <?= $sm2[1] ?></span>
      <?php if ($tc2 && $tc2 !== 'done'): ?>
      <div class="tat-<?= $tc2 ?>" style="margin-top:4px"><?= $tt2 ?></div>
      <?php endif ?>
    </div>
  </div>
  <div class="job-actions">
    <a href="?tab=<?= $tab ?>&detail=<?= (int)$j['id'] ?>" class="btn btn-ghost btn-sm">👁️ View Details</a>
    <?php if ($j['status'] === 'approved'): ?>
    <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="request_id" value="<?= (int)$j['id'] ?>"><button type="submit" class="btn btn-v-green btn-sm">✅ Accept</button></form>
    <button class="btn btn-v-danger btn-sm" onclick="document.getElementById('decModal').style.display='flex';document.getElementById('dm_rid').value=<?= (int)$j['id'] ?>">✕ Decline</button>
    <?php elseif ($j['status'] === 'vendor_confirmed'): ?>
    <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="mark_in_print"><input type="hidden" name="request_id" value="<?= (int)$j['id'] ?>"><button type="submit" class="btn btn-v-blue btn-sm">🖨️ Start Printing</button></form>
    <?php elseif ($j['status'] === 'in_print'): ?>
    <button class="btn btn-v-teal btn-sm" onclick="document.getElementById('proofModal').style.display='flex';document.getElementById('pm_rid').value=<?= (int)$j['id'] ?>">📷 Upload Proof</button>
    <?php elseif ($j['status'] === 'ready_to_dispatch'): ?>
    <button class="btn btn-v-amber btn-sm" onclick="document.getElementById('dispatchModal').style.display='flex';document.getElementById('disp_rid').value=<?= (int)$j['id'] ?>">🚚 Mark Dispatched</button>
    <?php endif ?>
  </div>
</div>
<?php endforeach ?>
<?php endif ?>
```

  </div>
</main>

<!-- DECLINE MODAL -->

<div class="modal-bd" id="decModal" style="display:none">
  <div class="modal-box">
    <div class="modal-h"><div style="font-size:14px;font-weight:800">✕ Decline Job</div><button onclick="document.getElementById('decModal').style.display='none'" style="background:none;border:none;font-size:22px;color:#94A3B8;cursor:pointer">×</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="decline">
      <input type="hidden" name="request_id" id="dm_rid" value="">
      <div class="modal-b">
        <label class="form-label" style="margin-bottom:6px">Reason for declining *</label>
        <select name="decline_reason" class="form-input" id="decReasonSel" onchange="document.getElementById('decOther').style.display=this.value==='other'?'block':'none'" required>
          <option value="">— Select reason —</option>
          <?php foreach ($decline_reasons as $rk => $rv): ?>
          <option value="<?= $rk ?>"><?= $rv ?></option>
          <?php endforeach ?>
        </select>
        <div id="decOther" style="display:none;margin-top:10px">
          <label class="form-label" style="margin-bottom:4px">Please specify</label>
          <input type="text" name="decline_other" class="form-input" placeholder="Describe the issue...">
        </div>
        <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 13px;font-size:12px;color:#92400E;margin-top:12px;line-height:1.5">
          ⚠️ Dausto CS will be notified immediately and will reassign this job.
        </div>
      </div>
      <div class="modal-f"><button type="button" onclick="document.getElementById('decModal').style.display='none'" class="btn btn-ghost btn-sm">Cancel</button><button type="submit" class="btn btn-v-danger btn-sm">Confirm Decline</button></div>
    </form>
  </div>
</div>

<!-- PROOF UPLOAD MODAL -->

<div class="modal-bd" id="proofModal" style="display:none">
  <div class="modal-box">
    <div class="modal-h"><div style="font-size:14px;font-weight:800">📷 Upload Print Proof</div><button onclick="document.getElementById('proofModal').style.display='none'" style="background:none;border:none;font-size:22px;color:#94A3B8;cursor:pointer">×</button></div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload_proof">
      <input type="hidden" name="request_id" id="pm_rid" value="">
      <div class="modal-b">
        <label class="form-label" style="margin-bottom:6px">Proof image (JPG/PNG, max 5 MB) *</label>
        <input type="file" name="proof_image" class="form-input" accept=".jpg,.jpeg,.png" required style="padding:6px">
        <div style="font-size:11.5px;color:#64748B;margin-top:8px;line-height:1.6">Upload a clear photo of the printed proof. Dausto QC team reviews before authorising dispatch.</div>
      </div>
      <div class="modal-f"><button type="button" onclick="document.getElementById('proofModal').style.display='none'" class="btn btn-ghost btn-sm">Cancel</button><button type="submit" class="btn btn-v-teal btn-sm">📷 Upload</button></div>
    </form>
  </div>
</div>

<!-- DISPATCH MODAL -->

<div class="modal-bd" id="dispatchModal" style="display:none">
  <div class="modal-box">
    <div class="modal-h"><div style="font-size:14px;font-weight:800">🚚 Mark as Dispatched</div><button onclick="document.getElementById('dispatchModal').style.display='none'" style="background:none;border:none;font-size:22px;color:#94A3B8;cursor:pointer">×</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="mark_dispatched">
      <input type="hidden" name="request_id" id="disp_rid" value="">
      <div class="modal-b">
        <div style="margin-bottom:12px">
          <label class="form-label" style="margin-bottom:5px">Courier / Logistics Partner *</label>
          <input type="text" name="courier_name" class="form-input" placeholder="e.g. Blue Dart, DTDC, Delhivery" required>
        </div>
        <div>
          <label class="form-label" style="margin-bottom:5px">AWB / Tracking Number *</label>
          <input type="text" name="tracking_number" class="form-input" placeholder="Enter AWB or tracking number" required>
        </div>
      </div>
      <div class="modal-f"><button type="button" onclick="document.getElementById('dispatchModal').style.display='none'" class="btn btn-ghost btn-sm">Cancel</button><button type="submit" class="btn btn-v-amber btn-sm">🚚 Confirm Dispatch</button></div>
    </form>
  </div>
</div>

</body>
</html>
