?php
// ============================================================
// FILE PATH ON HOSTINGER: public_html/vendor/earnings.php
// ============================================================
declare(strict_types=1);
$configs=[dirname(__DIR__,2).'/config.php',dirname(__DIR__,1).'/config.php',
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/config.php'];
foreach($configs as $c){if(file_exists($c)){require_once $c;break;}}
if(!defined('PUBLIC_PATH')) die('<p style="color:red;padding:40px">config.php not found</p>');
require_once PUBLIC_PATH.'/includes/db.php';
require_once PUBLIC_PATH.'/includes/auth.php';
require_once PUBLIC_PATH.'/includes/functions.php';
startSession(); requireLogin('vendor');
$user=currentUser(); $db=getDB();
$vendor_stmt=$db->prepare("SELECT * FROM vendors WHERE user_id=?");
$vendor_stmt->execute([$user['id']]); $vendor=$vendor_stmt->fetch();
if(!$vendor) die('<p style="color:red;padding:40px">Vendor not found.</p>');
$vid=$vendor['id'];

$stats=$db->query("SELECT
    COALESCE(SUM(oi.vendor_price*oi.quantity),0) total_earned,
    COALESCE(SUM(CASE WHEN o.status='delivered' THEN oi.vendor_price*oi.quantity ELSE 0 END),0) confirmed,
    COALESCE(SUM(CASE WHEN o.status NOT IN ('delivered','cancelled') THEN oi.vendor_price*oi.quantity ELSE 0 END),0) pending,
    COUNT(DISTINCT oi.order_id) total_orders
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE oi.vendor_id=$vid")->fetch();

$paid_out=(float)$db->query("SELECT COALESCE(SUM(net_payout_amount),0) FROM vendor_payouts WHERE vendor_id=$vid AND status='paid'")->fetchColumn();
$pending_payout=(float)$db->query("SELECT COALESCE(SUM(net_payout_amount),0) FROM vendor_payouts WHERE vendor_id=$vid AND status IN ('pending','processing')")->fetchColumn();

$payouts=$db->query("SELECT * FROM vendor_payouts WHERE vendor_id=$vid ORDER BY created_at DESC LIMIT 20")->fetchAll();

$monthly=$db->query("SELECT DATE_FORMAT(o.created_at,'%b %Y') month,MONTH(o.created_at) m,YEAR(o.created_at) y,
    SUM(oi.vendor_price*oi.quantity) earned, COUNT(DISTINCT o.id) orders
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE oi.vendor_id=$vid AND o.created_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH)
    GROUP BY YEAR(o.created_at),MONTH(o.created_at) ORDER BY y,m")->fetchAll();
?>

<!DOCTYPE html><html lang="en"><head>

<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">

<title>Earnings — Dausto Vendor</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}body{font-family:'DM Sans',sans-serif;background:#F0F2F5;color:#0F172A}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:240px;background:linear-gradient(180deg,#1A1A2E,#16213E);display:flex;flex-direction:column;z-index:100}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 14px 9px 18px;margin:1px 8px;border-radius:9px;font-size:13.5px;font-weight:600;color:rgba(255,255,255,0.55);text-decoration:none;white-space:nowrap}
.nav-link:hover{background:rgba(255,255,255,0.08);color:#fff}.nav-link.active{background:#D97706;color:#fff}
.nav-group{font-size:9.5px;font-weight:700;color:rgba(255,255,255,0.2);letter-spacing:.12em;padding:12px 22px 4px}
.main{margin-left:240px;min-height:100vh}.topbar{background:#fff;border-bottom:1px solid #E2E8F0;padding:14px 26px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:40}
.card{background:#fff;border-radius:14px;border:1px solid #E8ECF2;box-shadow:0 1px 4px rgba(0,0,0,0.04);overflow:hidden}
.card-head{padding:15px 18px;border-bottom:1px solid #F0F3F6;display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:800;color:#0F172A}
.tbl{width:100%;border-collapse:collapse}.tbl th{background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:left;border-bottom:1px solid #F0F3F6}
.tbl td{padding:11px 14px;font-size:13px;color:#374151;border-bottom:1px solid #F8FAFC}
.tbl tbody tr:last-child td{border-bottom:none}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.bar-wrap{display:flex;align-items:flex-end;gap:8px;height:100px}
.bar-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px}
.bar{width:100%;border-radius:5px 5px 0 0;background:linear-gradient(180deg,#D97706,#F59E0B);min-height:4px}
.bar-lbl{font-size:9px;color:#94A3B8;font-weight:600;text-align:center}
.bar-val{font-size:9.5px;font-weight:700;color:#0F172A;text-align:center}
</style></head><body>
<aside class="sidebar">
  <div style="padding:18px 16px 14px;border-bottom:1px solid rgba(255,255,255,0.07)">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:34px;height:34px;background:linear-gradient(135deg,#D97706,#F59E0B);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px">🏪</div>
      <div><div style="font-family:'Playfair Display',serif;font-size:14px;font-weight:900;color:#fff;line-height:1;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($vendor['company_name'])?></div>
      <div style="font-size:9px;color:rgba(255,255,255,0.3);font-weight:700;letter-spacing:.1em">VENDOR PORTAL</div></div>
    </div>
  </div>
  <nav style="flex:1;overflow-y:auto;padding:8px 0">
    <div class="nav-group">MAIN</div>
    <a href="index.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">🏠</span> Dashboard</a>
    <a href="orders.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">📋</span> My Orders</a>
    <div class="nav-group">CATALOGUE</div>
    <a href="products.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">📦</span> My Products</a>
    <a href="inventory.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">📊</span> Inventory</a>
    <div class="nav-group">FINANCE</div>
    <a href="earnings.php" class="nav-link active"><span style="width:22px;text-align:center;font-size:16px">💰</span> Earnings</a>
    <div class="nav-group">ACCOUNT</div>
    <a href="profile.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">👤</span> Profile</a>
    <a href="../admin/logout.php" class="nav-link" style="color:rgba(255,100,100,0.7)"><span style="width:22px;text-align:center;font-size:16px">⏻</span> Logout</a>
  </nav>
</aside>
<main class="main">
<div class="topbar">
  <div><h1 style="font-size:20px;font-weight:900;font-family:'Playfair Display',serif">Earnings</h1>
  <p style="font-size:12px;color:#94A3B8;margin-top:2px">Your payout history and earnings summary</p></div>
</div>
<div style="padding:24px">

<!-- Stats -->

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
<?php foreach([
  ['Total Earned','Rs.'.number_format((float)($stats['total_earned']??0),0),'#D97706','#FFFBEB'],
  ['Confirmed Paid','Rs.'.number_format((float)($stats['confirmed']??0),0),'#16A34A','#F0FDF4'],
  ['Total Paid Out','Rs.'.number_format($paid_out,0),'#2E3D9A','#EEF0FB'],
  ['Pending Payout','Rs.'.number_format($pending_payout,0),'#7C3AED','#F5F3FF'],
] as [$t,$v,$c,$bg]): ?>
<div style="background:#fff;border-radius:12px;padding:16px 18px;border:1px solid #E8ECF2;border-left:3px solid <?=$c?>">
  <div style="font-size:10px;font-weight:700;color:#94A3B8;letter-spacing:.07em;margin-bottom:6px"><?=$t?></div>
  <div style="font-size:24px;font-weight:900;color:<?=$c?>;font-family:'Playfair Display',serif"><?=$v?></div>
</div>
<?php endforeach ?>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:18px">
  <!-- Payout history -->
  <div class="card">
    <div class="card-head"><div class="card-title">🏦 Payout History</div></div>
    <?php if(empty($payouts)): ?>
    <div style="padding:40px;text-align:center;color:#94A3B8"><div style="font-size:36px;margin-bottom:10px">🏦</div><div style="font-size:14px;font-weight:700;color:#374151">No payouts yet</div><div style="font-size:12px;margin-top:6px">Payouts are processed by Dausto finance team after delivery</div></div>
    <?php else: ?>
    <table class="tbl">
      <thead><tr><th>PERIOD</th><th>GROSS</th><th>DEDUCTIONS</th><th>NET PAID</th><th>UTR</th><th>STATUS</th><th>DATE</th></tr></thead>
      <tbody>
      <?php foreach($payouts as $p):
        $sc=['paid'=>['#D1FAE5','#14532D'],'pending'=>['#FEF3C7','#92400E'],'processing'=>['#DBEAFE','#1E40AF'],'on_hold'=>['#FEE2E2','#7F1D1D']];
        [$sb,$st]=$sc[$p['status']]??['#F1F5F9','#475569'];
      ?>
      <tr>
        <td style="font-size:12px;color:#64748B"><?=date('d M',strtotime($p['period_start']))?> – <?=date('d M Y',strtotime($p['period_end']))?></td>
        <td style="font-weight:700">Rs.<?=number_format((float)$p['gross_vendor_amount'],0)?></td>
        <td style="color:<?=$p['deductions']>0?'#DC2626':'#94A3B8'?>">Rs.<?=number_format((float)$p['deductions'],0)?></td>
        <td style="font-weight:800;color:#16A34A;font-size:15px">Rs.<?=number_format((float)$p['net_payout_amount'],0)?></td>
        <td style="font-family:monospace;font-size:12px;color:#7C3AED"><?=htmlspecialchars($p['utr_number']??'—')?></td>
        <td><span class="pill" style="background:<?=$sb?>;color:<?=$st?>"><?=ucfirst($p['status'])?></span></td>
        <td style="color:#94A3B8;font-size:12px"><?=$p['payment_date']?date('d M Y',strtotime($p['payment_date'])):timeAgo($p['created_at'])?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <?php endif ?>
  </div>

  <!-- Monthly chart -->

  <div class="card">
    <div class="card-head"><div class="card-title">📈 Monthly Earnings</div></div>
    <div style="padding:18px">
      <?php if(!empty($monthly)):
        $max=max(array_column($monthly,'earned'));?>
      <div class="bar-wrap">
        <?php foreach($monthly as $m): $h=$max>0?max(6,round(($m['earned']/$max)*90)):6; ?>
        <div class="bar-item">
          <div class="bar-val">Rs.<?=number_format((float)$m['earned']/1000,0)?>K</div>
          <div class="bar" style="height:<?=$h?>px"></div>
          <div class="bar-lbl"><?=substr($m['month'],0,3)?></div>
        </div>
        <?php endforeach ?>
      </div>
      <?php else: ?>
      <div style="text-align:center;color:#94A3B8;padding:30px;font-size:13px">No data yet</div>
      <?php endif ?>
    </div>
    <!-- Bank details -->
    <div style="padding:14px 16px;border-top:1px solid #F0F3F6">
      <div style="font-size:11px;font-weight:700;color:#94A3B8;letter-spacing:.06em;margin-bottom:8px">PAYOUT DETAILS</div>
      <?php if($vendor['bank_account']): ?>
      <div style="font-size:13px;color:#374151">
        <div><?=htmlspecialchars($vendor['bank_name']??'—')?></div>
        <div style="font-family:monospace;font-weight:700;margin-top:2px">••••<?=substr($vendor['bank_account'],-4)?></div>
        <div style="font-size:11px;color:#94A3B8"><?=htmlspecialchars($vendor['bank_ifsc']??'')?></div>
      </div>
      <?php else: ?>
      <div style="font-size:13px;color:#DC2626">⚠️ No bank account added. <a href="profile.php" style="color:#D97706;font-weight:700">Add now →</a></div>
      <?php endif ?>
      <div style="font-size:11px;color:#64748B;margin-top:8px">Payout frequency: <strong><?=ucfirst($vendor['payout_frequency']??'biweekly')?></strong></div>
    </div>
  </div>
</div>
</div>
</main>
</body></html>
