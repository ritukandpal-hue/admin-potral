<?php
// ============================================================
// FILE: public_html/vendor/index.php
// REVISED: April 2026 — Print Jobs nav section added
// ============================================================
$configs=[
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__).'/config.php',
];
foreach($configs as $c){if(file_exists($c)){require_once $c;break;}}
if(!defined('PUBLIC_PATH')) die('<p style="color:red;font-family:sans-serif;padding:40px">config.php not found</p>');
if(session_status()===PHP_SESSION_NONE){
    session_start(['cookie_httponly'=>true,'cookie_secure'=>isset($_SERVER['HTTPS']),'cookie_samesite'=>'Lax','use_strict_mode'=>true]);
}
foreach(['db.php','auth.php','functions.php'] as $f) require_once PUBLIC_PATH.'/includes/'.$f;

if(empty($_SESSION['user_id'])||($_SESSION['portal']??'')!=='vendor'){
    header('Location: '.SITE_URL.'/vendor/login.php'); exit;
}
$user=currentUser(); $db=getDB();

$vendor_stmt=$db->prepare("SELECT * FROM vendors WHERE user_id=?");
$vendor_stmt->execute([$user['id']]);
$vendor=$vendor_stmt->fetch();
if(!$vendor){
    echo '<div style="font-family:sans-serif;padding:60px;text-align:center;color:#DC2626">
    <h2>Vendor account not linked.</h2>
    <p style="margin-top:10px">Contact Dausto support: <a href="https://wa.me/919319300883">WhatsApp</a></p>
    </div>';
    exit;
}
$vid=(int)$vendor['id'];

// ── Stats ──────────────────────────────────────────────────────
$stats=$db->query("SELECT
    COUNT(DISTINCT oi.order_id)  total_orders,
    COALESCE(SUM(oi.vendor_price*oi.quantity),0) total_earned,
    COALESCE(SUM(CASE WHEN o.status='delivered' THEN oi.vendor_price*oi.quantity ELSE 0 END),0) confirmed_earned,
    COUNT(CASE WHEN o.status IN ('confirmed','in_production','ready_to_dispatch','dispatched') THEN 1 END) active_orders
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE oi.vendor_id=$vid")->fetch();

$product_stats=$db->query("SELECT
    COUNT(*) total,
    SUM(status='active') active,
    SUM(status='pending') pending,
    SUM(stock_qty<=low_stock_alert AND status='active') low_stock
    FROM products WHERE vendor_id=$vid")->fetch();

$pending_payout=$db->query("SELECT COALESCE(SUM(net_payout_amount),0)
    FROM vendor_payouts WHERE vendor_id=$vid AND status IN ('pending','processing')")->fetchColumn();

$recent_orders=$db->query("SELECT oi.*,o.order_number,o.status order_status,o.created_at,
    p.name product_name
    FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id
    WHERE oi.vendor_id=$vid ORDER BY o.created_at DESC LIMIT 8")->fetchAll();

$low_stock_products=$db->query("SELECT id,name,stock_qty,low_stock_alert FROM products
    WHERE vendor_id=$vid AND status='active' AND stock_qty<=low_stock_alert
    ORDER BY stock_qty ASC LIMIT 5")->fetchAll();

// ── PIN stats ──────────────────────────────────────────────────
$pending_pins=0;
try{
    $ps=$db->prepare("SELECT COUNT(*) FROM vendor_pin_serviceability WHERE vendor_id=? AND status='pending'");
    $ps->execute([$vid]);$pending_pins=(int)$ps->fetchColumn();
}catch(\Exception $e){}

$approved_pins=0;
try{
    $ap=$db->prepare("SELECT COUNT(*) FROM vendor_pin_serviceability WHERE vendor_id=? AND status='approved' AND is_active=1");
    $ap->execute([$vid]);$approved_pins=(int)$ap->fetchColumn();
}catch(\Exception $e){}

// ── Print Jobs stats (only for print vendors) ──────────────────
$pending_print_jobs = 0;
$print_earned       = 0;
$print_payout_pending = 0;
if (!empty($vendor['is_print_vendor'])) {
    try {
        $pj = $db->prepare("SELECT COUNT(*) FROM print_requests WHERE vendor_id=? AND status='approved'");
        $pj->execute([$vid]);
        $pending_print_jobs = (int)$pj->fetchColumn();
    } catch(\Exception $e) {}

    try {
        $pe = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM print_requests WHERE vendor_id=? AND status IN ('delivered','invoiced')");
        $pe->execute([$vid]);
        $print_earned = (float)$pe->fetchColumn();
    } catch(\Exception $e) {}

    try {
        $pp = $db->prepare("SELECT COALESCE(SUM(net_payout_amount),0) FROM vendor_payouts WHERE vendor_id=? AND source_type='print' AND status IN ('pending','processing')");
        $pp->execute([$vid]);
        $print_payout_pending = (float)$pp->fetchColumn();
    } catch(\Exception $e) {}
}
?>

<!DOCTYPE html>

<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vendor Dashboard — Dausto</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#F0F2F5;color:#0F172A}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:240px;background:linear-gradient(180deg,#1A1A2E,#16213E);display:flex;flex-direction:column;z-index:100;box-shadow:4px 0 20px rgba(0,0,0,.2)}
.sb-logo{padding:18px 16px 14px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px}
.sb-logo img{width:32px;height:32px;border-radius:7px;object-fit:contain;background:#fff;padding:2px}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.nav-group{font-size:9.5px;font-weight:700;color:rgba(255,255,255,.2);letter-spacing:.12em;text-transform:uppercase;padding:12px 22px 4px}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 14px 9px 18px;margin:1px 8px;border-radius:9px;font-size:13.5px;font-weight:600;color:rgba(255,255,255,.55);text-decoration:none;transition:all .15s;white-space:nowrap}
.nav-link:hover{background:rgba(255,255,255,.08);color:#fff}
.nav-link.active{background:#D97706;color:#fff;box-shadow:0 2px 10px rgba(217,119,6,.4)}
.nav-icon{font-size:16px;width:22px;text-align:center;flex-shrink:0}
.nav-badge{margin-left:auto;font-size:10px;font-weight:800;padding:1px 7px;border-radius:10px;background:#DC2626;color:#fff}
.nav-badge.amber{background:#D97706}
.sb-user{padding:12px 14px;border-top:1px solid rgba(255,255,255,.07)}
.main{margin-left:240px;min-height:100vh}
.topbar{background:#fff;border-bottom:1px solid #E2E8F0;padding:14px 26px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:40;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.content{padding:24px 26px}
.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px}
.stat{background:#fff;border-radius:12px;padding:16px 18px;border:1px solid #E8ECF2;border-left-width:3px}
.stat-label{font-size:10px;font-weight:700;color:#94A3B8;letter-spacing:.07em;text-transform:uppercase;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.stat-value{font-size:22px;font-weight:900;color:#0F172A;font-family:'Playfair Display',serif;line-height:1}
.card{background:#fff;border-radius:14px;border:1px solid #E8ECF2;box-shadow:0 1px 4px rgba(0,0,0,.04);overflow:hidden;margin-bottom:18px}
.card-head{padding:15px 18px;border-bottom:1px solid #F0F3F6;display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:800;color:#0F172A}
.tbl{width:100%;border-collapse:collapse}
.tbl th{background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:left;border-bottom:1px solid #F0F3F6;white-space:nowrap}
.tbl td{padding:11px 14px;font-size:13px;color:#374151;border-bottom:1px solid #F8FAFC;vertical-align:middle}
.tbl tbody tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:#FAFBFC}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.alert-box{border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px}
.alert-amber{background:#FFFBEB;border:1px solid #FDE68A}
.alert-blue{background:#EFF6FF;border:1px solid #BFDBFE}
.alert-green{background:#F0FDF4;border:1px solid #BBF7D0}
.qa-btn{display:block;padding:9px 13px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;transition:background .15s;margin-bottom:7px;color:#0F172A}
.qa-btn:hover{background:#EEF0FB}
.link-sm{font-size:12px;font-weight:700;color:#D97706;text-decoration:none}
.link-sm:hover{text-decoration:underline}
@media(max-width:900px){.sidebar{width:200px}.main{margin-left:200px}.stat-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:640px){.sidebar{transform:translateX(-100%)}.main{margin-left:0}.stat-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sb-logo">
    <img src="../dausto.png" alt="Dausto" onerror="this.style.display='none'">
    <div>
      <div style="font-family:'Playfair Display',serif;font-size:13px;font-weight:900;color:#fff;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:155px"><?=htmlspecialchars((string)(html_entity_decode($vendor['company_name'] ?? '',ENT_QUOTES)))?></div>
      <div style="font-size:9px;color:rgba(255,255,255,.3);font-weight:700;letter-spacing:.1em">VENDOR PORTAL</div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="nav-group">MAIN</div>
    <a href="index.php" class="nav-link active"><span class="nav-icon">🏠</span> Dashboard</a>
    <a href="orders.php" class="nav-link"><span class="nav-icon">📋</span> My Orders</a>

```
<div class="nav-group">CATALOGUE</div>
<a href="products.php" class="nav-link">
  <span class="nav-icon">📦</span> My Products
  <?php if(!empty($product_stats['pending'])&&(int)$product_stats['pending']>0): ?>
    <span class="nav-badge amber"><?=(int)$product_stats['pending']?></span>
  <?php endif ?>
</a>
<a href="inventory.php" class="nav-link"><span class="nav-icon">📊</span> Inventory</a>

<div class="nav-group">DELIVERY</div>
<a href="pincode.php" class="nav-link">
  <span class="nav-icon">📍</span> Delivery PINs
  <?php if($pending_pins>0): ?>
    <span class="nav-badge amber"><?=$pending_pins?></span>
  <?php endif ?>
</a>

<?php if(!empty($vendor['is_print_vendor'])): ?>
<div class="nav-group">PRINT JOBS</div>
<a href="print-jobs.php" class="nav-link">
  <span class="nav-icon">🖨️</span> Print Jobs
  <?php if($pending_print_jobs>0): ?>
    <span class="nav-badge"><?=$pending_print_jobs?></span>
  <?php endif ?>
</a>
<?php endif ?>

<div class="nav-group">FINANCE</div>
<a href="earnings.php" class="nav-link"><span class="nav-icon">💰</span> Earnings</a>
<a href="payouts.php" class="nav-link"><span class="nav-icon">🏦</span> Payouts</a>

<div class="nav-group">ACCOUNT</div>
<a href="profile.php" class="nav-link"><span class="nav-icon">👤</span> Profile</a>
<a href="logout.php" class="nav-link" style="color:rgba(255,100,100,.65)"><span class="nav-icon">⏻</span> Logout</a>
```

  </nav>

  <div class="sb-user">
    <div style="font-size:11px;color:rgba(255,255,255,.3);margin-bottom:5px">KYC Status</div>
    <?php
    $kc=['verified'=>['#D1FAE5','#14532D'],'pending'=>['#FEF3C7','#92400E'],'rejected'=>['#FEE2E2','#7F1D1D']];
    [$kb,$kt]=$kc[$vendor['kyc_status']??'pending']??['#F1F5F9','#475569'];
    ?>
    <span class="pill" style="background:<?=$kb?>;color:<?=$kt?>"><?=ucfirst((string)($vendor['kyc_status']??'Pending'))?></span>
    <?php if($approved_pins>0): ?>
    <div style="font-size:11px;color:rgba(255,255,255,.25);margin-top:5px">📍 <?=$approved_pins?> PINs serviceable</div>
    <?php endif ?>
    <?php if(!empty($vendor['is_print_vendor'])): ?>
    <div style="font-size:11px;color:rgba(255,255,255,.25);margin-top:5px">🖨️ Print vendor enabled</div>
    <?php endif ?>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div>
      <h1 style="font-size:20px;font-weight:900;font-family:'Playfair Display',serif">Vendor Dashboard</h1>
      <p style="font-size:12px;color:#94A3B8;margin-top:2px"><?=date('l, d F Y')?> · Welcome back, <?=htmlspecialchars((string)(explode(' ',(string)($user['name'] ?? ''))[0]))?></p>
    </div>
    <div style="display:flex;gap:10px">
      <a href="products.php?action=add" style="background:#D97706;color:#fff;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;text-decoration:none">+ Add Product</a>
      <a href="<?=SITE_URL?>" target="_blank" style="background:#F8FAFC;color:#64748B;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid #E2E8F0">View Store ↗</a>
    </div>
  </div>

  <div class="content">

```
<?php if(($vendor['kyc_status']??'pending')==='pending'): ?>
<div class="alert-box alert-amber">
  <span style="font-size:24px;flex-shrink:0">⚠️</span>
  <div>
    <div style="font-size:14px;font-weight:700;color:#92400E">KYC Verification Pending</div>
    <div style="font-size:13px;color:#78350F;margin-top:2px">Your account is under review. Products won't be visible to clients until KYC is verified.
      Contact <a href="https://wa.me/919319300883" style="color:#D97706;font-weight:700">Dausto support</a>.
    </div>
  </div>
</div>
<?php endif ?>

<?php if(($vendor['status']??'')==='pending'): ?>
<div class="alert-box alert-blue">
  <span style="font-size:24px;flex-shrink:0">⏳</span>
  <div>
    <div style="font-size:14px;font-weight:700;color:#1E40AF">Account Pending Approval</div>
    <div style="font-size:13px;color:#1D4ED8;margin-top:2px">Your vendor account is being reviewed by Dausto admin. You'll be notified once approved.</div>
  </div>
</div>
<?php endif ?>

<?php if($approved_pins===0): ?>
<div class="alert-box alert-blue">
  <span style="font-size:24px;flex-shrink:0">📍</span>
  <div>
    <div style="font-size:14px;font-weight:700;color:#1E40AF">No Delivery PINs Set Up Yet</div>
    <div style="font-size:13px;color:#1D4ED8;margin-top:2px">
      Add the PIN codes you can deliver to so customers can see your ETA on product pages.
      <a href="pincode.php" style="color:#D97706;font-weight:700">Set up Delivery PINs →</a>
    </div>
  </div>
</div>
<?php elseif($pending_pins>0): ?>
<div class="alert-box alert-amber">
  <span style="font-size:24px;flex-shrink:0">📍</span>
  <div>
    <div style="font-size:14px;font-weight:700;color:#92400E"><?=$pending_pins?> PIN(s) Awaiting Admin Approval</div>
    <div style="font-size:13px;color:#78350F;margin-top:2px">
      Once approved, customers at those PINs will see your delivery ETA.
      <a href="pincode.php" style="color:#D97706;font-weight:700">View PIN Status →</a>
    </div>
  </div>
</div>
<?php endif ?>

<!-- Print Jobs alert for print vendors -->
<?php if(!empty($vendor['is_print_vendor']) && $pending_print_jobs>0): ?>
<div class="alert-box" style="background:#FEF3C7;border:1px solid #FDE68A">
  <span style="font-size:24px;flex-shrink:0">🖨️</span>
  <div>
    <div style="font-size:14px;font-weight:700;color:#92400E"><?=$pending_print_jobs?> New Print Job<?=$pending_print_jobs!==1?'s':''?> Awaiting Acceptance</div>
    <div style="font-size:13px;color:#78350F;margin-top:2px">
      Please accept or decline within 2 hours to avoid reassignment.
      <a href="print-jobs.php" style="color:#D97706;font-weight:700">Go to Print Jobs →</a>
    </div>
  </div>
</div>
<?php endif ?>

<!-- Stat Cards -->
<div class="stat-grid">
  <div class="stat" style="border-left-color:#2E3D9A">
    <div class="stat-label">TOTAL ORDERS <span>📋</span></div>
    <div class="stat-value" style="color:#2E3D9A"><?=(int)($stats['total_orders']??0)?></div>
  </div>
  <div class="stat" style="border-left-color:#D97706">
    <div class="stat-label">ACTIVE ORDERS <span>⚡</span></div>
    <div class="stat-value" style="color:#D97706"><?=(int)($stats['active_orders']??0)?></div>
  </div>
  <div class="stat" style="border-left-color:#16A34A">
    <div class="stat-label">TOTAL EARNED <span>💰</span></div>
    <div class="stat-value" style="color:#16A34A;font-size:18px">Rs.<?=number_format((float)($stats['total_earned']??0),0)?></div>
  </div>
  <div class="stat" style="border-left-color:#7C3AED">
    <div class="stat-label">PAYOUT PENDING <span>🏦</span></div>
    <div class="stat-value" style="color:#7C3AED;font-size:18px">Rs.<?=number_format((float)($pending_payout??0),0)?></div>
  </div>
  <div class="stat" style="border-left-color:#0F766E">
    <div class="stat-label">PRODUCTS <span>📦</span></div>
    <div class="stat-value" style="color:#0F766E"><?=(int)($product_stats['active']??0)?>/<?=(int)($product_stats['total']??0)?></div>
    <?php if((int)($product_stats['low_stock']??0)>0): ?>
    <div style="font-size:11px;color:#DC2626;font-weight:700;margin-top:3px">⚠️ <?=(int)$product_stats['low_stock']?> low stock</div>
    <?php endif ?>
  </div>
</div>

<!-- Print vendor stats row -->
<?php if(!empty($vendor['is_print_vendor'])): ?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px">
  <div class="stat" style="border-left-color:#0D9488">
    <div class="stat-label">PRINT JOBS (ACTIVE) <span>🖨️</span></div>
    <div class="stat-value" style="color:#0D9488"><?=$pending_print_jobs?></div>
    <div style="font-size:11px;color:#94A3B8;margin-top:3px">Awaiting acceptance</div>
  </div>
  <div class="stat" style="border-left-color:#16A34A">
    <div class="stat-label">PRINT EARNINGS <span>💰</span></div>
    <div class="stat-value" style="color:#16A34A;font-size:18px">Rs.<?=number_format($print_earned,0)?></div>
    <div style="font-size:11px;color:#94A3B8;margin-top:3px">Delivered & invoiced</div>
  </div>
  <div class="stat" style="border-left-color:#D97706">
    <div class="stat-label">PRINT PAYOUT PENDING <span>🏦</span></div>
    <div class="stat-value" style="color:#D97706;font-size:18px">Rs.<?=number_format($print_payout_pending,0)?></div>
    <div style="font-size:11px;color:#94A3B8;margin-top:3px">Awaiting payout</div>
  </div>
</div>
<?php endif ?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:18px">

  <!-- Recent Orders -->
  <div class="card">
    <div class="card-head">
      <div class="card-title">📋 Recent Orders</div>
      <a href="orders.php" class="link-sm">View all →</a>
    </div>
    <?php if(empty($recent_orders)): ?>
    <div style="padding:40px;text-align:center;color:#94A3B8">
      <div style="font-size:36px;margin-bottom:10px">📋</div>
      <div style="font-size:14px;font-weight:700;color:#374151">No orders yet</div>
      <div style="font-size:13px;margin-top:6px">Orders for your products will appear here</div>
    </div>
    <?php else: ?>
    <table class="tbl">
      <thead>
        <tr><th>ORDER</th><th>PRODUCT</th><th>QTY</th><th>YOUR PRICE</th><th>STATUS</th><th>DATE</th></tr>
      </thead>
      <tbody>
      <?php foreach($recent_orders as $o): ?>
      <tr>
        <td style="font-weight:700;color:#D97706;font-size:12.5px"><?=htmlspecialchars((string)($o['order_number'] ?? ''))?></td>
        <td style="font-size:12.5px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars((string)($o['product_name'] ?? ''))?></td>
        <td style="font-weight:700;text-align:center"><?=(int)$o['quantity']?></td>
        <td style="font-weight:700;color:#16A34A">Rs.<?=number_format((float)$o['vendor_price']*(int)$o['quantity'],0)?></td>
        <td><?=statusBadge((string)($o['order_status'] ?? ''))?></td>
        <td style="color:#94A3B8;font-size:12px"><?=timeAgo((string)($o['created_at'] ?? ''))?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <?php endif ?>
  </div>

  <!-- Right Panel -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Low Stock -->
    <?php if(!empty($low_stock_products)): ?>
    <div class="card" style="border-left:3px solid #DC2626">
      <div class="card-head"><div class="card-title" style="color:#DC2626">⚠️ Low Stock Alert</div></div>
      <div style="padding:10px 14px">
        <?php foreach($low_stock_products as $ls): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #FEF2F2">
          <div style="font-size:12.5px;font-weight:600;max-width:155px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars((string)($ls['name'] ?? ''))?></div>
          <span style="background:#FEE2E2;color:#7F1D1D;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:800;flex-shrink:0"><?=(int)$ls['stock_qty']?> left</span>
        </div>
        <?php endforeach ?>
        <a href="inventory.php" style="display:block;text-align:center;margin-top:10px;font-size:13px;font-weight:700;color:#DC2626;text-decoration:none">Update Stock →</a>
      </div>
    </div>
    <?php endif ?>

    <!-- Earnings Summary -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">💰 Earnings Summary</div>
        <a href="earnings.php" class="link-sm">Details →</a>
      </div>
      <div style="padding:14px 16px">
        <?php foreach([
          ['Total Gross',   (float)($stats['total_earned']??0),    '#0F172A'],
          ['Confirmed',     (float)($stats['confirmed_earned']??0), '#16A34A'],
          ['Payout Pending',(float)($pending_payout??0),            '#D97706'],
        ] as [$l,$v,$c]): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #F8FAFC;font-size:13px">
          <span style="color:#64748B"><?=$l?></span>
          <span style="font-weight:800;color:<?=$c?>">Rs.<?=number_format($v,0)?></span>
        </div>
        <?php endforeach ?>
        <?php if(!empty($vendor['is_print_vendor']) && ($print_earned > 0 || $print_payout_pending > 0)): ?>
        <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #E2E8F0">
          <div style="font-size:10px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Print Jobs</div>
          <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12.5px">
            <span style="color:#64748B">Print Earned</span>
            <span style="font-weight:800;color:#0D9488">Rs.<?=number_format($print_earned,0)?></span>
          </div>
          <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12.5px">
            <span style="color:#64748B">Print Pending</span>
            <span style="font-weight:800;color:#D97706">Rs.<?=number_format($print_payout_pending,0)?></span>
          </div>
        </div>
        <?php endif ?>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
      <div class="card-head"><div class="card-title">⚡ Quick Actions</div></div>
      <div style="padding:10px 12px">
        <a href="products.php?action=add" class="qa-btn" style="color:#D97706">+ Add New Product</a>
        <a href="products.php" class="qa-btn" style="color:#2E3D9A">📦 Manage Products</a>
        <a href="orders.php" class="qa-btn" style="color:#0F172A">📋 View Orders</a>
        <a href="inventory.php" class="qa-btn" style="color:#0F766E">📊 Update Stock</a>
        <a href="pincode.php" class="qa-btn" style="color:#7C3AED">📍 Delivery PINs <?=$approved_pins>0?'('.$approved_pins.' active)':''?></a>
        <a href="earnings.php" class="qa-btn" style="color:#16A34A">💰 View Earnings</a>
        <?php if(!empty($vendor['is_print_vendor'])): ?>
        <a href="print-jobs.php" class="qa-btn" style="color:#0D9488;border-color:#99F6E4;background:#F0FDFA">
          🖨️ Print Jobs <?=$pending_print_jobs>0?'<span style="background:#DC2626;color:#fff;font-size:10px;padding:1px 7px;border-radius:10px;margin-left:4px">'.$pending_print_jobs.' new</span>':''?>
        </a>
        <?php endif ?>
      </div>
    </div>

    <!-- Bank Details Reminder -->
    <?php if(empty($vendor['bank_account'])): ?>
    <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:14px 16px">
      <div style="font-size:13px;font-weight:700;color:#1E40AF;margin-bottom:4px">🏦 Add Bank Details</div>
      <div style="font-size:12px;color:#1D4ED8;line-height:1.5;margin-bottom:8px">Add your bank account to receive payouts from Dausto.</div>
      <a href="profile.php" style="font-size:12.5px;font-weight:700;color:#2E3D9A;text-decoration:none">Update Profile →</a>
    </div>
    <?php endif ?>

  </div>
</div>
```

  </div>
</main>

</body>
</html>
