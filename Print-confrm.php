<?php
// FILE: public_html/vendor/print-confirm.php
// One-click confirm or reject a print job via email token — NO login required.

$configs = [
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__) . '/config.php',
];
foreach ($configs as $c) { if (file_exists($c)) { require_once $c; break; } }
if (!defined('PUBLIC_PATH')) die('<p style="color:red;font-family:sans-serif;padding:40px">config.php not found</p>');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
foreach (['db.php','functions.php'] as $f) require_once PUBLIC_PATH.'/includes/'.$f;

$db     = getDB();
$token  = clean($_GET['token']  ?? '');
$action = clean($_GET['action'] ?? ''); // 'confirm' or 'reject'

$site = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');

$result  = 'error';
$heading = '';
$message = '';
$color   = '#DC2626';

if (!$token || !in_array($action, ['confirm','reject'])) {
    $heading = 'Invalid Link';
    $message = 'This confirmation link is invalid. Please log in to your vendor portal to manage this job.';
} else {
    // Look up the token
    $ts = $db->prepare("
        SELECT vct.*, pr.status pr_status, pr.request_number, pr.category,
               pr.quantity, pr.total_amount, pr.client_id, pr.vendor_id,
               c.company_name client_company
        FROM vendor_confirmation_tokens vct
        JOIN print_requests pr ON pr.id = vct.order_id
        JOIN clients c ON c.id = pr.client_id
        WHERE vct.token=? LIMIT 1
    ");
    $ts->execute([$token]);
    $row = $ts->fetch();

    if (!$row) {
        $heading = 'Link Not Found';
        $message = 'This link does not exist or has already been used. Please log in to your vendor portal.';

    } elseif ($row['actioned_at'] !== null) {
        $heading = 'Already Actioned';
        $message = 'This job was already ' . ($row['action'] === 'confirm' ? 'confirmed ✅' : 'declined ✕') . '. No further action is needed.';
        $result  = 'already_done';
        $color   = '#D97706';

    } elseif (strtotime((string)($row['expires_at'] ?? '')) < time()) {
        $heading = 'Link Expired';
        $message = 'This confirmation link expired after 24 hours. Please log in to your vendor portal to confirm or decline.';
        $color   = '#D97706';

    } elseif (!in_array($row['pr_status'], ['approved'])) {
        $heading = 'Job Status Changed';
        $message = 'This print job is no longer in the "Approved" state (current status: ' . htmlspecialchars((string)($row['pr_status'] ?? '')) . '). No action needed.';
        $result  = 'stale';
        $color   = '#D97706';

    } else {
        // Valid — process the action
        $req_id   = (int)$row['order_id'];
        $vendor_id = (int)$row['vendor_id'];

        if ($action === 'confirm') {
            $db->prepare("UPDATE print_requests SET status='vendor_confirmed', vendor_confirmed_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$req_id]);
            $db->prepare("INSERT INTO print_request_logs (request_id, user_id, from_status, to_status, note) VALUES (?,NULL,'approved','vendor_confirmed','Vendor confirmed via email one-click link')")->execute([$req_id]);
            // Mark token as actioned
            $db->prepare("UPDATE vendor_confirmation_tokens SET action='confirm', actioned_at=NOW() WHERE token=?")->execute([$token]);
            // Invalidate the paired reject token for same request+vendor
            $db->prepare("UPDATE vendor_confirmation_tokens SET actioned_at=NOW(), action='confirm' WHERE order_id=? AND vendor_id=? AND token!=? AND actioned_at IS NULL")->execute([$req_id, $vendor_id, $token]);

            $result  = 'confirmed';
            $heading = '✅ Job Confirmed!';
            $message = 'You have confirmed print job <strong>' . htmlspecialchars((string)($row['request_number'] ?? '')) . '</strong> for <strong>' . htmlspecialchars((string)($row['client_company'] ?? '')) . '</strong>. Please log in to your portal to start production.';
            $color   = '#16A34A';

        } else {
            // Reject — unassign vendor, CS gets notified via log
            $db->prepare("UPDATE print_requests SET vendor_id=NULL, vendor_confirmed_at=NULL, updated_at=NOW() WHERE id=?")->execute([$req_id]);
            $db->prepare("INSERT INTO print_request_logs (request_id, user_id, from_status, to_status, note) VALUES (?,NULL,'approved','approved','Vendor declined via email one-click link — Dausto CS notified for reassignment')")->execute([$req_id]);
            $db->prepare("UPDATE vendor_confirmation_tokens SET action='reject', actioned_at=NOW() WHERE token=?")->execute([$token]);
            $db->prepare("UPDATE vendor_confirmation_tokens SET actioned_at=NOW(), action='reject' WHERE order_id=? AND vendor_id=? AND token!=? AND actioned_at IS NULL")->execute([$req_id, $vendor_id, $token]);

            $result  = 'rejected';
            $heading = 'Job Declined';
            $message = 'You have declined print job <strong>' . htmlspecialchars((string)($row['request_number'] ?? '')) . '</strong>. The Dausto CS team has been notified and will reassign this job immediately.';
            $color   = '#DC2626';
        }
    }
}

$btn_color = $result === 'confirmed' ? '#16A34A' : ($result === 'rejected' ? '#DC2626' : '#2E3D9A');
?>

<!DOCTYPE html>

<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $heading ?> — Dausto Vendor</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;700;800;900&family=Playfair+Display:wght@900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#F0F2F5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:16px;max-width:480px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.1);overflow:hidden;text-align:center}
.card-top{padding:36px 32px 28px;border-bottom:1px solid #F0F3F6}
.icon{font-size:52px;margin-bottom:16px}
.heading{font-size:22px;font-weight:900;font-family:'Playfair Display',serif;color:#0F172A;margin-bottom:10px}
.message{font-size:14px;color:#64748B;line-height:1.6}
.card-foot{padding:24px 32px;background:#F8FAFC}
.btn{display:inline-block;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:800;text-decoration:none;color:#fff}
.logo{font-family:'Playfair Display',serif;font-size:13px;font-weight:900;color:#94A3B8;letter-spacing:.1em;margin-top:16px}
</style>
</head>
<body>
<div class="card">
  <div class="card-top">
    <div class="icon">
      <?php
      if ($result === 'confirmed')   echo '✅';
      elseif ($result === 'rejected') echo '✕';
      elseif ($result === 'already_done') echo '🔄';
      elseif ($result === 'stale')    echo '⏰';
      else                            echo '⚠️';
      ?>
    </div>
    <div class="heading" style="color:<?= $color ?>"><?= $heading ?></div>
    <p class="message"><?= $message ?></p>
  </div>
  <div class="card-foot">
    <a href="<?= $site ?>/vendor/print-jobs.php" class="btn" style="background:<?= $btn_color ?>">
      Go to Print Jobs Portal →
    </a>
    <div class="logo">DAUSTO VENDOR PORTAL</div>
  </div>
</div>
</body>
</html>
