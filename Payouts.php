<?php
// FILE: public_html/vendor/payouts.php
$config_locations = [
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__) . '/config.php',
];
foreach ($config_locations as $cfg) { if (file_exists($cfg)) { require_once $cfg; break; } }
if (!defined('PUBLIC_PATH')) die('config.php not found');
if (session_status()===PHP_SESSION_NONE) {
    session_start(['cookie_httponly'=>true,'cookie_secure'=>isset($_SERVER['HTTPS']),'cookie_samesite'=>'Lax','use_strict_mode'=>true]);
}
require_once PUBLIC_PATH . '/includes/db.php';
require_once PUBLIC_PATH . '/includes/functions.php';
require_once __DIR__ . '/layout.php';

if (empty($_SESSION['user_id']) || ($_SESSION['portal']??'') !== 'vendor') {
    header('Location: ' . (defined('SITE_URL')?SITE_URL:'') . '/vendor/login.php'); exit;
}

$db       = getDB();
$user_id  = (int)$_SESSION['user_id'];
$vid      = (int)($_SESSION['vendor_id'] ?? 0);
$site_url = defined('SITE_URL') ? SITE_URL : '';

$vq = $db->prepare("SELECT * FROM vendors WHERE id=? LIMIT 1");
$vq->execute([$vid]); $vendor = $vq->fetch();
if (!$vendor) { header('Location: '.$site_url.'/vendor/login.php'); exit; }

// Load payouts
$payouts = $db->prepare("SELECT * FROM vendor_payouts WHERE vendor_id=? ORDER BY created_at DESC LIMIT 50");
$payouts->execute([$vid]); $payouts = $payouts->fetchAll();

// Earnings summary
$eq = $db->prepare("SELECT
    COALESCE(SUM(oi.vendor_price*oi.quantity),0) total_gross,
    COALESCE(SUM(CASE WHEN o.status='delivered' THEN oi.vendor_price*oi.quantity ELSE 0 END),0) confirmed,
    COUNT(DISTINCT oi.order_id) order_count
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE oi.vendor_id=? AND o.status NOT IN ('cancelled')");
$eq->execute([$vid]); $earnings = $eq->fetch();

$paid_out = array_sum(array_column(array_filter($payouts, fn($p)=>$p['status']==='paid'), 'net_payout_amount'));
$pending  = array_sum(array_column(array_filter($payouts, fn($p)=>in_array($p['status'],['pending','processing'])), 'net_payout_amount'));

echo vendorHead('Payouts');
?>

</head>
<body>
<?php echo vendorNav($vendor, ['pending_orders'=>0,'low_stock'=>0]); ?>
<main class="main">
  <div class="topbar">
    <div>
      <h1 style="font-size:20px;font-weight:900;font-family:'Playfair Display',serif">💰 Payouts</h1>
      <p style="font-size:12px;color:#94A3B8;margin-top:2px">Your earnings and payment history</p>
    </div>
  </div>

  <div class="page-content">

```
<!-- Earnings summary -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <?php foreach([
    ['TOTAL GROSS', 'Rs.'.number_format((float)($earnings['total_gross']??0),0), $earnings['order_count'].' orders','#059669'],
    ['CONFIRMED',   'Rs.'.number_format((float)($earnings['confirmed']??0),0),    'Delivered orders only','#2E3D9A'],
    ['PAID OUT',    'Rs.'.number_format($paid_out,0), 'Received in bank','#16A34A'],
    ['PENDING',     'Rs.'.number_format($pending,0),  'Awaiting Dausto payment','#D97706'],
  ] as [$l,$v,$s,$c]): ?>
  <div class="stat-card" style="border-left:3px solid <?=$c?>">
    <div style="font-size:10.5px;font-weight:700;color:#94A3B8;letter-spacing:.06em;margin-bottom:8px"><?=$l?></div>
    <div style="font-size:24px;font-weight:900;color:<?=$c?>;font-family:'Playfair Display',serif;margin-bottom:4px"><?=$v?></div>
    <div style="font-size:11.5px;color:#94A3B8"><?=$s?></div>
  </div>
  <?php endforeach ?>
</div>

<!-- Payout history -->
<div class="card">
  <div class="card-head"><div class="card-title">💳 Payout History</div></div>
  <?php if (empty($payouts)): ?>
  <div style="padding:48px;text-align:center;color:#94A3B8;font-size:13px">
    <div style="font-size:40px;margin-bottom:12px">💰</div>
    No payouts yet. Payouts are processed by Dausto finance team after order delivery.
  </div>
  <?php else: ?>
  <table class="dtable">
    <thead><tr><th>DATE</th><th>REFERENCE</th><th>ORDERS</th><th>GROSS</th><th>COMMISSION</th><th>NET</th><th>STATUS</th></tr></thead>
    <tbody>
    <?php foreach ($payouts as $p):
      $sc = match($p['status']) {
        'paid'       => ['pill-green','Paid ✓'],
        'processing' => ['pill-blue','Processing'],
        'pending'    => ['pill-yellow','Pending'],
        default      => ['pill-gray',ucfirst($p['status'])],
      };
    ?>
    <tr>
      <td style="color:#64748B;font-size:12px"><?=date('d M Y',strtotime($p['created_at']))?></td>
      <td style="font-weight:700;font-size:12.5px"><?=htmlspecialchars($p['reference_number']??'—')?></td>
      <td style="color:#64748B"><?=$p['order_count']??'—'?></td>
      <td>Rs.<?=number_format((float)($p['gross_amount']??0),0)?></td>
      <td style="color:#DC2626">−Rs.<?=number_format((float)($p['commission_amount']??0),0)?></td>
      <td style="font-weight:900;color:#059669;font-size:14px">Rs.<?=number_format((float)$p['net_payout_amount'],0)?></td>
      <td><span class="pill <?=$sc[0]?>"><?=$sc[1]?></span></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Bank details reminder -->
<div class="card">
  <div class="card-head"><div class="card-title">🏦 Bank Details</div><a href="profile.php" class="link-sm">Edit →</a></div>
  <div style="padding:18px;font-size:13.5px;color:#374151;line-height:1.8">
    <?php if ($vendor['bank_account_number']??''): ?>
    <div><strong>Account:</strong> ****<?=substr($vendor['bank_account_number'],-4)?></div>
    <div><strong>IFSC:</strong> <?=htmlspecialchars($vendor['bank_ifsc']??'—')?></div>
    <div><strong>Bank:</strong> <?=htmlspecialchars($vendor['bank_name']??'—')?></div>
    <?php else: ?>
    <div style="color:#D97706;font-weight:600">⚠️ Bank details not added. <a href="profile.php" style="color:#059669">Add now →</a></div>
    <?php endif ?>
  </div>
</div>
```

  </div>
</main>
</body>
</html>
