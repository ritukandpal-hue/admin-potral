<?php
// FILE: public_html/vendor/confirm-order.php
// One-click vendor confirmation page — no login required
// Vendor receives email/WhatsApp link → clicks confirm/reject → done

$configs = [
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__) . '/config.php',
];
foreach ($configs as $c) { if (file_exists($c)) { require_once $c; break; } }
if (!defined('PUBLIC_PATH')) die('Config not found');

require_once PUBLIC_PATH . '/includes/db.php';
require_once PUBLIC_PATH . '/includes/functions.php';

$db       = getDB();
$site_url = rtrim(SITE_URL, '/');
$token    = clean($_GET['token'] ?? '');
$action   = clean($_GET['action'] ?? ''); // confirm or reject

$result   = '';
$order    = null;
$vendor   = null;
$items    = [];

if ($token) {
    // Load confirmation record
    $cq = $db->prepare("SELECT voc.*, o.order_number, o.id AS order_id,
        o.status AS order_status, c.company_name AS client_name,
        v.company_name AS vendor_name, v.contact_name AS vendor_contact
        FROM vendor_order_confirmations voc
        JOIN orders o ON o.id = voc.order_id
        JOIN vendors v ON v.id = voc.vendor_id
        JOIN clients c ON c.id = o.client_id
        WHERE voc.confirm_token = ? LIMIT 1");
    $cq->execute([$token]);
    $conf = $cq->fetch();

    if (!$conf) {
        $result = 'invalid';
    } elseif ($conf['status'] !== 'pending') {
        $result = 'already_' . $conf['status'];
    } elseif (strtotime($conf['token_expires_at']) < time()) {
        $result = 'expired';
        $db->prepare("UPDATE vendor_order_confirmations SET status='expired' WHERE confirm_token=?")
           ->execute([$token]);
    } elseif ($action === 'confirm' || $action === 'reject') {
        // Process the confirmation
        $note = clean($_POST['vendor_note'] ?? $_GET['note'] ?? '');
        $qty  = (int)($_POST['available_qty'] ?? 0);
        $ready_by = clean($_POST['ready_by'] ?? '');

        try {
            $db->beginTransaction();

            $db->prepare("UPDATE vendor_order_confirmations
                SET status=?, vendor_note=?, available_qty=?,
                    ready_by_date=?, responded_at=NOW()
                WHERE confirm_token=?")
               ->execute([
                   $action === 'confirm' ? 'confirmed' : 'rejected',
                   $note ?: null,
                   $qty ?: null,
                   $ready_by ?: null,
                   $token,
               ]);

            if ($action === 'confirm') {
                // Check if ALL vendors confirmed
                $pending_q = $db->prepare("SELECT COUNT(*) FROM vendor_order_confirmations
                    WHERE order_id = ? AND status = 'pending'");
                $pending_q->execute([(int)$conf['order_id']]);
                $still_pending = (int)$pending_q->fetchColumn();

                if ($still_pending === 0) {
                    // All vendors confirmed — update order
                    $db->prepare("UPDATE orders SET status='vendor_confirmed',
                        vendor_confirmed_at=NOW(), updated_at=NOW() WHERE id=?")
                       ->execute([(int)$conf['order_id']]);

                    $db->prepare("INSERT INTO order_status_log
                        (order_id, status, note, created_at) VALUES (?, 'vendor_confirmed', ?, NOW())")
                       ->execute([(int)$conf['order_id'], 'All vendors confirmed stock availability']);
                }
                $result = 'confirmed';
            } else {
                // Vendor rejected — notify admin
                $db->prepare("UPDATE orders SET status='vendor_notified', updated_at=NOW() WHERE id=?")
                   ->execute([(int)$conf['order_id']]);

                $db->prepare("INSERT INTO order_status_log
                    (order_id, status, note, created_at) VALUES (?, 'vendor_notified',
                    'Vendor rejected — finding alternative', NOW())")
                   ->execute([(int)$conf['order_id']]);
                $result = 'rejected';
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            $result = 'error';
        }
    } else {
        // Show confirmation form
        $result = 'form';
        $order  = $conf;
        // Load items for this vendor
        $iq = $db->prepare("SELECT oi.*, p.name AS product_name
            FROM vendor_order_confirmations voc2
            JOIN order_items oi ON oi.id = voc2.order_item_id
            JOIN products p ON p.id = oi.product_id
            WHERE voc2.order_id = ? AND voc2.vendor_id = ?");
        $iq->execute([(int)$conf['order_id'], (int)$conf['vendor_id']]);
        $items = $iq->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>

<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Order Confirmation — Dausto</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#F0F2F7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:16px;max-width:560px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.12);overflow:hidden}
.header{background:#0F172A;padding:24px;text-align:center}
.header img{height:32px;width:auto;filter:brightness(0) invert(1);margin-bottom:8px}
.header h1{color:#fff;font-size:18px;font-weight:800}
.body{padding:28px}
.success{background:#F0FDF4;border:2px solid #BBF7D0;border-radius:12px;padding:24px;text-align:center}
.error-box{background:#FEF2F2;border:2px solid #FECACA;border-radius:12px;padding:24px;text-align:center}
.item-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #F1F5F9;font-size:14px}
.btn{display:block;width:100%;padding:14px;border-radius:10px;font-size:15px;font-weight:800;text-align:center;cursor:pointer;border:none;font-family:inherit;text-decoration:none;margin-bottom:10px}
.btn-green{background:#16A34A;color:#fff}
.btn-red{background:#FEF2F2;color:#DC2626;border:2px solid #FECACA}
.form-input{width:100%;border:1.5px solid #E2E8F0;border-radius:9px;padding:10px 13px;font-size:14px;font-family:inherit;outline:none;margin-top:4px}
.form-input:focus{border-color:#2E3D9A}
label{font-size:12px;font-weight:700;color:#64748B;display:block;margin-top:14px}
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <img src="<?php echo $site_url; ?>/dausto.png" alt="Dausto" onerror="this.style.display='none'">
    <h1>Order Stock Confirmation</h1>
  </div>
  <div class="body">

  <?php if ($result === 'confirmed'): ?>

  <div class="success">
    <div style="font-size:48px;margin-bottom:12px">✅</div>
    <h2 style="font-size:20px;font-weight:900;color:#14532D;margin-bottom:8px">Confirmed! Thank you.</h2>
    <p style="color:#166534;font-size:14px">We've received your confirmation. Our team will proceed with the order and coordinate pickup timing with you.</p>
    <p style="color:#166534;font-size:14px;margin-top:8px">Questions? WhatsApp: <strong>+91 93193 00883</strong></p>
  </div>

  <?php elseif ($result === 'rejected'): ?>

  <div class="error-box">
    <div style="font-size:48px;margin-bottom:12px">❌</div>
    <h2 style="font-size:20px;font-weight:900;color:#7F1D1D;margin-bottom:8px">Rejection Received</h2>
    <p style="color:#991B1B;font-size:14px">We've noted that you cannot fulfil this order. Our team will find an alternative and will be in touch.</p>
    <p style="color:#991B1B;font-size:14px;margin-top:8px">Questions? WhatsApp: <strong>+91 93193 00883</strong></p>
  </div>

  <?php elseif ($result === 'already_confirmed'): ?>

  <div class="success">
    <div style="font-size:48px;margin-bottom:12px">✅</div>
    <h2 style="font-size:18px;font-weight:900;color:#14532D">Already confirmed</h2>
    <p style="color:#166534;font-size:14px;margin-top:6px">This order was already confirmed by you. Thank you!</p>
  </div>

  <?php elseif ($result === 'expired'): ?>

  <div class="error-box">
    <div style="font-size:48px;margin-bottom:12px">⏰</div>
    <h2 style="font-size:18px;font-weight:900;color:#7F1D1D">Link Expired</h2>
    <p style="color:#991B1B;font-size:14px;margin-top:6px">This link has expired. Please WhatsApp us at <strong>+91 93193 00883</strong> to confirm.</p>
  </div>

  <?php elseif ($result === 'form'): ?>

  <div style="margin-bottom:20px">
    <div style="font-size:13px;font-weight:700;color:#94A3B8;margin-bottom:4px">ORDER</div>
    <div style="font-size:18px;font-weight:900;color:#0F172A"><?php echo htmlspecialchars((string)($order['order_number'] ?? '')); ?></div>
    <div style="font-size:13px;color:#64748B">Client: <?php echo htmlspecialchars((string)($order['client_name'] ?? '')); ?></div>
  </div>

  <div style="background:#F8FAFC;border-radius:10px;padding:14px;margin-bottom:20px">
    <div style="font-size:11px;font-weight:700;color:#94A3B8;margin-bottom:8px">PRODUCTS NEEDED</div>
    <?php foreach ($items as $it): ?>
    <div class="item-row">
      <span><?php echo htmlspecialchars((string)($it['product_name'] ?? '')); ?></span>
      <span style="font-weight:800;color:#0F172A"><?php echo (int)$it['quantity']; ?> units</span>
    </div>
    <?php endforeach; ?>
  </div>

  <p style="font-size:13px;color:#374151;margin-bottom:20px">Please confirm if you have the above stock available for this order.</p>

  <!-- Confirm form -->

  <form method="POST" action="?token=<?php echo urlencode($token); ?>&action=confirm">
    <label>How many units can you supply? (optional)</label>
    <input type="number" name="available_qty" class="form-input" placeholder="Leave blank if full qty available">
    <label>Stock ready by date (optional)</label>
    <input type="date" name="ready_by" class="form-input">
    <label>Note for Dausto team (optional)</label>
    <input type="text" name="vendor_note" class="form-input" placeholder="e.g. Stock ready, can pickup anytime">
    <button type="submit" class="btn btn-green" style="margin-top:18px">✅ YES — I have this stock</button>
  </form>

  <form method="POST" action="?token=<?php echo urlencode($token); ?>&action=reject" style="margin-top:0">
    <label>Reason for rejection (optional)</label>
    <input type="text" name="vendor_note" class="form-input" placeholder="e.g. Out of stock, restock in 2 weeks">
    <button type="submit" class="btn btn-red" style="margin-top:10px">❌ NO — Cannot fulfil this order</button>
  </form>

  <?php else: ?>

  <div class="error-box">
    <div style="font-size:48px;margin-bottom:12px">🔗</div>
    <h2 style="font-size:18px;font-weight:900;color:#7F1D1D">Invalid Link</h2>
    <p style="color:#991B1B;font-size:14px;margin-top:6px">This confirmation link is not valid. Please contact us on WhatsApp: <strong>+91 93193 00883</strong></p>
  </div>
  <?php endif; ?>

  </div>
</div>
</body>
</html>
