<?php
// ============================================================
// FILE PATH ON HOSTINGER: public_html/vendor/inventory.php
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
$msg=$error='';

if($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrf()){
    if(isset($_POST['update_stock'])){
        $updates = $_POST['stock']??[];
        $alerts  = $_POST['alert']??[];
        foreach($updates as $pid => $qty){
            $pid = (int)$pid; $qty = max(0,(int)$qty);
            $alert = max(0,(int)($alerts[$pid]??20));
            // Verify product belongs to vendor
            $chk=$db->prepare("SELECT id,stock_qty FROM products WHERE id=? AND vendor_id=?");$chk->execute([$pid,$vid]);
            $old=$chk->fetch();
            if(!$old) continue;
            $db->prepare("UPDATE products SET stock_qty=?,low_stock_alert=?,updated_at=NOW() WHERE id=? AND vendor_id=?")->execute([$qty,$alert,$pid,$vid]);
            // Log inventory change
            if($old['stock_qty']!=$qty){
                $db->prepare("INSERT INTO inventory_log (product_id,vendor_id,change_type,qty_before,qty_change,qty_after,reference_type,reason,changed_by)
                    VALUES (?,?,?,?,?,?,'manual','Stock update via vendor portal',?)")
                   ->execute([$pid,$vid,$qty>$old['stock_qty']?'stock_in':'adjustment',$old['stock_qty'],$qty-$old['stock_qty'],$qty,$user['id']]);
            }
        }
        $msg='Stock levels updated successfully.';
    }
}

$products=$db->query("SELECT p.*,c.name cat_name FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    WHERE p.vendor_id=$vid AND p.status IN ('active','out_of_stock')
    ORDER BY p.stock_qty ASC, p.name")->fetchAll();
?>

<!DOCTYPE html><html lang="en"><head>

<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">

<title>Inventory — Dausto Vendor</title>
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
.btn{padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-family:'DM Sans',sans-serif}
.btn-primary{background:#D97706;color:#fff}
.stock-input{width:80px;border:1.5px solid #E2E8F0;border-radius:8px;padding:7px 10px;font-size:14px;font-weight:700;text-align:center;font-family:'DM Sans',sans-serif;outline:none}
.stock-input:focus{border-color:#D97706;box-shadow:0 0 0 3px rgba(217,119,6,0.15)}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px}
.alert-success{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E}
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
    <a href="inventory.php" class="nav-link active"><span style="width:22px;text-align:center;font-size:16px">📊</span> Inventory</a>
    <div class="nav-group">FINANCE</div>
    <a href="earnings.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">💰</span> Earnings</a>
    <div class="nav-group">ACCOUNT</div>
    <a href="profile.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">👤</span> Profile</a>
    <a href="../admin/logout.php" class="nav-link" style="color:rgba(255,100,100,0.7)"><span style="width:22px;text-align:center;font-size:16px">⏻</span> Logout</a>
  </nav>
</aside>
<main class="main">
<div class="topbar">
  <div><h1 style="font-size:20px;font-weight:900;font-family:'Playfair Display',serif">Inventory Management</h1>
  <p style="font-size:12px;color:#94A3B8;margin-top:2px">Update stock levels for your active products</p></div>
</div>
<div style="padding:24px">
<?php if($msg): ?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif ?>
<?php if(empty($products)): ?>
<div class="card"><div style="padding:40px;text-align:center;color:#94A3B8"><div style="font-size:40px;margin-bottom:10px">📊</div><div style="font-size:14px;font-weight:700;color:#374151">No active products</div><div style="font-size:13px;margin-top:6px"><a href="products.php" style="color:#D97706;font-weight:700">Add products first →</a></div></div></div>
<?php else: ?>
<div class="card">
  <div class="card-head">
    <div class="card-title">📊 Stock Levels (<?=count($products)?> products)</div>
    <div style="font-size:12.5px;color:#64748B">Edit quantities and click Save All</div>
  </div>
  <form method="POST">
    <?=csrfField()?>
    <input type="hidden" name="update_stock" value="1">
    <table style="width:100%;border-collapse:collapse">
      <thead><tr>
        <th style="background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:left;border-bottom:1px solid #F0F3F6">PRODUCT</th>
        <th style="background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:center;border-bottom:1px solid #F0F3F6">CURRENT STOCK</th>
        <th style="background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:center;border-bottom:1px solid #F0F3F6">UPDATE TO</th>
        <th style="background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:center;border-bottom:1px solid #F0F3F6">LOW STOCK ALERT</th>
        <th style="background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:left;border-bottom:1px solid #F0F3F6">STATUS</th>
      </tr></thead>
      <tbody>
      <?php foreach($products as $p):
        $is_low = $p['stock_qty'] <= $p['low_stock_alert'];
        $is_zero = $p['stock_qty'] == 0;
      ?>
      <tr style="border-bottom:1px solid #F8FAFC">
        <td style="padding:12px 14px">
          <div style="font-weight:700;font-size:13.5px;color:#0F172A"><?=htmlspecialchars($p['name'])?></div>
          <div style="font-size:11px;color:#94A3B8;margin-top:1px"><?=htmlspecialchars($p['cat_name']??'—')?> · MOQ: <?=$p['moq']?></div>
        </td>
        <td style="padding:12px 14px;text-align:center">
          <span style="font-size:22px;font-weight:900;color:<?=$is_zero?'#DC2626':($is_low?'#D97706':'#16A34A')?>;font-family:'Playfair Display',serif"><?=$p['stock_qty']?></span>
        </td>
        <td style="padding:12px 14px;text-align:center">
          <input type="number" name="stock[<?=$p['id']?>]" value="<?=$p['stock_qty']?>" min="0"
                 class="stock-input" style="border-color:<?=$is_low?'#FDE68A':'#E2E8F0'?>">
        </td>
        <td style="padding:12px 14px;text-align:center">
          <input type="number" name="alert[<?=$p['id']?>]" value="<?=$p['low_stock_alert']?>" min="0"
                 class="stock-input" style="width:70px;font-size:13px;font-weight:600">
        </td>
        <td style="padding:12px 14px">
          <?php if($is_zero): ?><span style="background:#FEE2E2;color:#7F1D1D;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700">Out of Stock</span>
          <?php elseif($is_low): ?><span style="background:#FEF3C7;color:#92400E;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700">⚠️ Low Stock</span>
          <?php else: ?><span style="background:#D1FAE5;color:#14532D;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700">✓ In Stock</span>
          <?php endif ?>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <div style="padding:14px 16px;border-top:1px solid #F0F3F6;text-align:right">
      <button type="submit" class="btn btn-primary">💾 Save All Stock Updates</button>
    </div>
  </form>
</div>
<?php endif ?>
</div>
</main>
</body></html>
