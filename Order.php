<?php
// FILE: public_html/vendor/orders.php
$configs=[
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__).'/config.php',
];
foreach($configs as $c){if(file_exists($c)){require_once $c;break;}}
if(!defined('PUBLIC_PATH')) die('<p style="color:red;padding:40px">config.php not found</p>');
require_once PUBLIC_PATH.'/includes/db.php';
require_once PUBLIC_PATH.'/includes/auth.php';
require_once PUBLIC_PATH.'/includes/functions.php';

if(session_status()===PHP_SESSION_NONE){
    session_start(['cookie_httponly'=>true,'cookie_secure'=>isset($_SERVER['HTTPS']),'cookie_samesite'=>'Lax','use_strict_mode'=>true]);
}
if(empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));

if(empty($_SESSION['user_id'])||($_SESSION['portal']??'')!=='vendor'){
    header('Location: '.SITE_URL.'/vendor/login.php'); exit;
}

$user=currentUser(); $db=getDB();
$vendor_stmt=$db->prepare("SELECT * FROM vendors WHERE user_id=?");
$vendor_stmt->execute([$user['id']]); $vendor=$vendor_stmt->fetch();
if(!$vendor) die('<p style="color:red;padding:40px">Vendor not found.</p>');
$vid=(int)$vendor['id'];
$msg=$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['csrf_token']??'',$_POST['csrf_token']??'')){$error='Invalid form.'; goto render;}

    if(isset($_POST['upload_mockup'])&&!empty($_FILES['mockup_file']['name'])&&$_FILES['mockup_file']['error']===0){
        $item_id=cleanInt($_POST['item_id']);
        $chk=$db->prepare("SELECT id FROM order_items WHERE id=? AND vendor_id=?");
        $chk->execute([$item_id,$vid]);
        if(!$chk->fetch()){$error='Order item not found.'; goto render;}

        $ext=strtolower(pathinfo($_FILES['mockup_file']['name'],PATHINFO_EXTENSION));
        if(in_array($ext,['jpg','jpeg','png','pdf','webp'])){
            $dir=PUBLIC_PATH.'/uploads/mockups/';
            if(!is_dir($dir)) mkdir($dir,0755,true);
            $fn='mockup_'.$vid.'_'.$item_id.'_'.time().'.'.$ext;
            if(move_uploaded_file($_FILES['mockup_file']['tmp_name'],$dir.$fn)){
                $db->prepare("UPDATE order_items SET mockup_path=?,updated_at=NOW() WHERE id=? AND vendor_id=?")
                   ->execute(['/uploads/mockups/'.$fn,$item_id,$vid]);
                $oi=$db->prepare("SELECT order_id FROM order_items WHERE id=?");$oi->execute([$item_id]);
                $oid=$oi->fetchColumn();
                $db->prepare("UPDATE orders SET status='mockup_pending',updated_at=NOW() WHERE id=?")->execute([$oid]);
                $msg='Mockup uploaded. Dausto team will review and notify client.';
            }
        } else {
            $error='Only JPG, PNG, PDF, WEBP allowed.';
        }
    }
}
render:

$filter=clean($_GET['filter']??'');
$view_id=cleanInt($_GET['id']??0);
$page=max(1,cleanInt($_GET['page']??1)); $per_page=20;

$order_detail=null; $order_items_detail=[];
if($view_id){
    $od=$db->prepare("SELECT o.*,c.company_name client_company,c.contact_phone
        FROM orders o JOIN clients c ON c.id=o.client_id
        WHERE o.id=? AND o.id IN (SELECT DISTINCT order_id FROM order_items WHERE vendor_id=?)");
    $od->execute([$view_id,$vid]); $order_detail=$od->fetch();
    if($order_detail){
        $oi=$db->prepare("SELECT oi.*,p.name product_name,p.material
            FROM order_items oi JOIN products p ON p.id=oi.product_id
            WHERE oi.order_id=? AND oi.vendor_id=?");
        $oi->execute([$view_id,$vid]); $order_items_detail=$oi->fetchAll();
    } else $view_id=0;
}

$where=["oi.vendor_id=$vid"]; $params=[];
if($filter){$where[]="o.status=?";$params[]=$filter;}
$wsql=implode(' AND ',$where);
$tc=$db->prepare("SELECT COUNT(DISTINCT o.id) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE $wsql");
$tc->execute($params); $total=(int)$tc->fetchColumn();
$pag=paginate($total,$per_page,$page,'orders.php');
$stmt=$db->prepare("SELECT DISTINCT o.id,o.order_number,o.status,o.created_at,c.company_name,
    COUNT(oi2.id) item_count, SUM(oi2.vendor_price*oi2.quantity) vendor_total
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    JOIN clients c ON c.id=o.client_id
    JOIN order_items oi2 ON oi2.order_id=o.id AND oi2.vendor_id=$vid
    WHERE $wsql GROUP BY o.id ORDER BY o.created_at DESC LIMIT $per_page OFFSET {$pag['offset']}");
$stmt->execute($params); $orders=$stmt->fetchAll();
?>

<!DOCTYPE html>

<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Orders — Dausto Vendor</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#F0F2F5;color:#0F172A}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:240px;background:linear-gradient(180deg,#1A1A2E,#16213E);display:flex;flex-direction:column;z-index:100}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 14px 9px 18px;margin:1px 8px;border-radius:9px;font-size:13.5px;font-weight:600;color:rgba(255,255,255,0.55);text-decoration:none;white-space:nowrap;transition:all .15s}
.nav-link:hover{background:rgba(255,255,255,0.08);color:#fff}
.nav-link.active{background:#D97706;color:#fff;box-shadow:0 2px 10px rgba(217,119,6,0.4)}
.nav-group{font-size:9.5px;font-weight:700;color:rgba(255,255,255,0.2);letter-spacing:.12em;padding:12px 22px 4px}
.main{margin-left:240px;min-height:100vh}
.topbar{background:#fff;border-bottom:1px solid #E2E8F0;padding:14px 26px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:40;box-shadow:0 1px 4px rgba(0,0,0,0.05)}
.card{background:#fff;border-radius:14px;border:1px solid #E8ECF2;box-shadow:0 1px 4px rgba(0,0,0,0.04);overflow:hidden}
.card-head{padding:15px 18px;border-bottom:1px solid #F0F3F6;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.card-title{font-size:14px;font-weight:800;color:#0F172A}
.btn{padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-family:'DM Sans',sans-serif}
.btn-primary{background:#D97706;color:#fff}
.btn-ghost{background:#F8FAFC;color:#374151;border:1px solid #E2E8F0}
.btn-sm{padding:6px 14px;font-size:12.5px;border-radius:8px}
.btn-xs{padding:3px 9px;font-size:11px;border-radius:6px}
.tbl{width:100%;border-collapse:collapse}
.tbl th{background:#F8FAFC;padding:9px 14px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:left;border-bottom:1px solid #F0F3F6}
.tbl td{padding:11px 14px;font-size:13px;color:#374151;border-bottom:1px solid #F8FAFC;vertical-align:middle}
.tbl tbody tr:hover td{background:#FAFBFC;cursor:pointer}
.tbl tbody tr:last-child td{border-bottom:none}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px}
.alert-success{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E}
.alert-error{background:#FEF2F2;border:1px solid #FECACA;color:#7F1D1D}
</style>
</head>
<body>

<aside class="sidebar">
  <div style="padding:18px 16px 14px;border-bottom:1px solid rgba(255,255,255,0.07)">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:34px;height:34px;background:linear-gradient(135deg,#D97706,#F59E0B);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px">🏪</div>
      <div>
        <div style="font-family:'Playfair Display',serif;font-size:14px;font-weight:900;color:#fff;line-height:1;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars(html_entity_decode($vendor['company_name'], ENT_QUOTES))?></div>
        <div style="font-size:9px;color:rgba(255,255,255,0.3);font-weight:700;letter-spacing:.1em">VENDOR PORTAL</div>
      </div>
    </div>
  </div>
  <nav style="flex:1;overflow-y:auto;padding:8px 0">
    <div class="nav-group">MAIN</div>
    <a href="index.php"     class="nav-link"><span style="width:22px;text-align:center;font-size:16px">🏠</span> Dashboard</a>
    <a href="orders.php"    class="nav-link active"><span style="width:22px;text-align:center;font-size:16px">📋</span> My Orders</a>
    <div class="nav-group">CATALOGUE</div>
    <a href="products.php"  class="nav-link"><span style="width:22px;text-align:center;font-size:16px">📦</span> My Products</a>
    <a href="inventory.php" class="nav-link"><span style="width:22px;text-align:center;font-size:16px">📊</span> Inventory</a>
    <div class="nav-group">FINANCE</div>
    <a href="earnings.php"  class="nav-link"><span style="width:22px;text-align:center;font-size:16px">💰</span> Earnings</a>
    <div class="nav-group">ACCOUNT</div>
    <a href="profile.php"   class="nav-link"><span style="width:22px;text-align:center;font-size:16px">👤</span> Profile</a>
    <a href="logout.php"    class="nav-link" style="color:rgba(255,100,100,0.7)"><span style="width:22px;text-align:center;font-size:16px">⏻</span> Logout</a>
  </nav>
</aside>

<main class="main">
  <div class="topbar">
    <div>
      <h1 style="font-size:20px;font-weight:900;font-family:'Playfair Display',serif">
        <?=$order_detail?'Order: '.htmlspecialchars($order_detail['order_number']):'My Orders'?>
      </h1>
      <p style="font-size:12px;color:#94A3B8;margin-top:2px"><?=$total?> total orders</p>
    </div>
    <?php if($order_detail): ?><a href="orders.php" class="btn btn-ghost btn-sm">← All Orders</a><?php endif ?>
  </div>

  <div style="padding:24px">
    <?php if($msg): ?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif ?>
    <?php if($error): ?><div class="alert alert-error">❌ <?=htmlspecialchars($error)?></div><?php endif ?>

```
<?php if($order_detail): ?>

<!-- Order Detail -->
<div style="display:grid;grid-template-columns:1fr 280px;gap:18px">
  <div style="display:flex;flex-direction:column;gap:16px">
    <?php foreach($order_items_detail as $item): ?>
    <div class="card">
      <div class="card-head">
        <div class="card-title">📦 <?=htmlspecialchars($item['product_name'])?></div>
        <span style="font-size:13px;font-weight:700;color:#16A34A">Rs.<?=number_format((float)$item['vendor_price']*(int)$item['quantity'],0)?></span>
      </div>
      <div style="padding:18px">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px">
          <?php foreach([
            ['Qty',$item['quantity']],
            ['Your Price','Rs.'.number_format((float)$item['vendor_price'],0)],
            ['Material',$item['material']??'—'],
            ['GST Rate',($item['gst_rate']??0).'%']
          ] as [$l,$v]): ?>
          <div style="background:#F8FAFC;border-radius:8px;padding:10px 12px">
            <div style="font-size:10px;font-weight:700;color:#94A3B8;letter-spacing:.06em"><?=$l?></div>
            <div style="font-size:15px;font-weight:800;color:#0F172A;margin-top:2px"><?=$v?></div>
          </div>
          <?php endforeach ?>
        </div>

        <!-- Customisation details -->
        <?php if(($item['printing_method']??'')||($item['product_colour']??'')||($item['logo_file_path']??'')||($item['company_name_print']??'')): ?>
        <div style="background:#F0F7FF;border-radius:10px;padding:12px 14px;border:1px solid #BFDBFE;margin-bottom:14px">
          <div style="font-size:11px;font-weight:700;color:#1E40AF;margin-bottom:8px">🎨 CUSTOMISATION REQUIRED</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">
            <?php if($item['printing_method']??''): ?><div><span style="color:#64748B">Print Method: </span><strong><?=htmlspecialchars($item['printing_method'])?></strong></div><?php endif ?>
            <?php if($item['product_colour']??''): ?><div><span style="color:#64748B">Colour: </span><strong><?=htmlspecialchars($item['product_colour'])?></strong></div><?php endif ?>
            <?php if($item['placement_zone']??''): ?><div><span style="color:#64748B">Placement: </span><strong><?=htmlspecialchars($item['placement_zone'])?></strong></div><?php endif ?>
            <?php if($item['company_name_print']??''): ?><div><span style="color:#64748B">Company: </span><strong><?=htmlspecialchars($item['company_name_print'])?></strong></div><?php endif ?>
            <?php if($item['font_style']??''): ?><div><span style="color:#64748B">Font: </span><strong><?=htmlspecialchars($item['font_style'])?></strong></div><?php endif ?>
            <?php if($item['logo_file_path']??''): ?>
            <div style="grid-column:span 2">
              <span style="color:#64748B">Logo File: </span>
              <a href="<?=htmlspecialchars(SITE_URL.$item['logo_file_path'])?>" target="_blank" style="color:#2E3D9A;font-weight:700">⬇️ Download Logo</a>
            </div>
            <?php endif ?>
          </div>
        </div>
        <?php endif ?>

        <!-- Mockup section -->
        <div style="border-top:1px solid #F0F3F6;padding-top:14px">
          <div style="font-size:11px;font-weight:700;color:#64748B;letter-spacing:.06em;margin-bottom:10px">MOCKUP / DESIGN PROOF</div>
          <?php if($item['mockup_path']??''): ?>
          <div style="display:flex;align-items:flex-start;gap:14px">
            <img src="<?=htmlspecialchars(SITE_URL.$item['mockup_path'])?>" alt="Mockup"
                 style="width:120px;height:120px;object-fit:cover;border-radius:10px;border:1px solid #E2E8F0">
            <div>
              <?php if($item['mockup_approved']??false): ?>
              <span class="pill" style="background:#D1FAE5;color:#14532D;font-size:13px;padding:5px 14px">✓ Approved by client</span>
              <div style="font-size:12px;color:#94A3B8;margin-top:6px">Approved on <?=date('d M Y',strtotime($item['mockup_approved_at']))?></div>
              <?php else: ?>
              <span class="pill" style="background:#FEF3C7;color:#92400E;font-size:13px;padding:5px 14px">⏳ Awaiting approval</span>
              <div style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.5">Mockup uploaded. Client review pending.</div>
              <?php endif ?>
            </div>
          </div>
          <?php else: ?>
          <div style="background:#FAFBFC;border:2px dashed #E2E8F0;border-radius:10px;padding:20px;text-align:center;margin-bottom:12px">
            <div style="font-size:28px;margin-bottom:6px">🎨</div>
            <div style="font-size:13px;font-weight:600;color:#374151">Upload mockup/design proof</div>
            <div style="font-size:12px;color:#94A3B8;margin-top:3px">JPG, PNG or PDF</div>
          </div>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
            <input type="hidden" name="item_id" value="<?=$item['id']?>">
            <div style="display:flex;gap:10px;align-items:center">
              <input type="file" name="mockup_file" accept="image/*,.pdf" required
                     style="flex:1;padding:8px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif">
              <button type="submit" name="upload_mockup" value="1" class="btn btn-primary btn-sm">Upload</button>
            </div>
          </form>
          <?php endif ?>
        </div>
      </div>
    </div>
    <?php endforeach ?>
  </div>

  <!-- Order info panel -->
  <div class="card" style="height:fit-content">
    <div class="card-head"><div class="card-title">📋 Order Info</div></div>
    <div style="padding:16px 18px;display:flex;flex-direction:column;gap:10px">
      <?php foreach([
        ['Order #',$order_detail['order_number']],
        ['Client',$order_detail['client_company']],
        ['Status',ucwords(str_replace('_',' ',$order_detail['status']))],
        ['Delivery City',$order_detail['delivery_city']??'—'],
        ['Order Date',date('d M Y',strtotime($order_detail['created_at']))],
        ['PO Number',$order_detail['po_number']??'—'],
      ] as [$l,$v]): ?>
      <div>
        <div style="font-size:10px;font-weight:700;color:#94A3B8;letter-spacing:.06em"><?=$l?></div>
        <div style="font-size:13.5px;font-weight:600;color:#0F172A;margin-top:1px"><?=htmlspecialchars((string)$v)?></div>
      </div>
      <?php endforeach ?>
      <div style="border-top:1px solid #F0F3F6;padding-top:10px">
        <div style="font-size:10px;font-weight:700;color:#94A3B8;letter-spacing:.06em;margin-bottom:4px">YOUR EARNINGS</div>
        <?php $vt=$db->prepare("SELECT SUM(vendor_price*quantity) FROM order_items WHERE order_id=? AND vendor_id=?");$vt->execute([$order_detail['id'],$vid]); ?>
        <div style="font-size:22px;font-weight:900;color:#16A34A;font-family:'Playfair Display',serif">Rs.<?=number_format((float)$vt->fetchColumn(),0)?></div>
        <div style="font-size:11px;color:#94A3B8;margin-top:2px">Paid on delivery confirmation</div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>

<!-- Filter tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
  <?php foreach([''=> 'All','confirmed'=>'✅ Confirmed','mockup_pending'=>'🎨 Mockup Needed','in_production'=>'🏭 In Production','ready_to_dispatch'=>'📦 Ready','dispatched'=>'🚚 Shipped','delivered'=>'✨ Delivered'] as $s=>$l): ?>
  <a href="orders.php?filter=<?=$s?>" style="padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:700;text-decoration:none;background:<?=$filter===$s?'#D97706':'#fff'?>;color:<?=$filter===$s?'#fff':'#64748B'?>;border:1px solid <?=$filter===$s?'#D97706':'#E2E8F0'?>"><?=$l?></a>
  <?php endforeach ?>
</div>

<div class="card">
  <div class="card-head"><div class="card-title">📋 All Orders (<?=$total?>)</div></div>
  <?php if(empty($orders)): ?>
  <div style="padding:40px;text-align:center;color:#94A3B8">
    <div style="font-size:40px;margin-bottom:10px">📋</div>
    <div style="font-size:14px;font-weight:700;color:#374151">No orders yet</div>
    <div style="font-size:13px;margin-top:6px">Orders containing your products appear here</div>
  </div>
  <?php else: ?>
  <table class="tbl">
    <thead><tr><th>ORDER</th><th>CLIENT</th><th>ITEMS</th><th>YOUR EARNINGS</th><th>STATUS</th><th>DATE</th><th></th></tr></thead>
    <tbody>
    <?php foreach($orders as $o):
      $sc=['pending'=>['#FEF9C3','#92400E'],'confirmed'=>['#DBEAFE','#1E40AF'],'mockup_pending'=>['#EDE9FE','#5B21B6'],'in_production'=>['#DCFCE7','#166534'],'dispatched'=>['#FEF3C7','#92400E'],'delivered'=>['#D1FAE5','#14532D']];
      [$sb,$st]=$sc[$o['status']]??['#F1F5F9','#475569'];
    ?>
    <tr onclick="window.location='orders.php?id=<?=$o['id']?>'" style="cursor:pointer">
      <td style="font-weight:700;color:#D97706"><?=htmlspecialchars($o['order_number'])?></td>
      <td><?=htmlspecialchars($o['company_name'])?></td>
      <td style="text-align:center;font-weight:700"><?=$o['item_count']?></td>
      <td style="font-weight:700;color:#16A34A">Rs.<?=number_format((float)$o['vendor_total'],0)?></td>
      <td><span class="pill" style="background:<?=$sb?>;color:<?=$st?>"><?=ucwords(str_replace('_',' ',$o['status']))?></span></td>
      <td style="color:#94A3B8;font-size:12px"><?=timeAgo($o['created_at'])?></td>
      <td><a href="orders.php?id=<?=$o['id']?>" class="btn btn-ghost btn-xs" onclick="event.stopPropagation()">View →</a></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php if($pag['total_pages']>1): ?>
  <div style="padding:14px 18px;display:flex;gap:8px;justify-content:center">
    <?php if($pag['has_prev']): ?><a href="?page=<?=$page-1?>&filter=<?=urlencode($filter)?>" class="btn btn-ghost btn-sm">‹ Prev</a><?php endif ?>
    <?php for($i=1;$i<=$pag['total_pages'];$i++): ?>
    <a href="?page=<?=$i?>&filter=<?=urlencode($filter)?>" class="btn btn-sm" style="background:<?=$i===$page?'#D97706':'#F8FAFC'?>;color:<?=$i===$page?'#fff':'#374151'?>;border:1px solid #E2E8F0"><?=$i?></a>
    <?php endfor ?>
    <?php if($pag['has_next']): ?><a href="?page=<?=$page+1?>&filter=<?=urlencode($filter)?>" class="btn btn-ghost btn-sm">Next ›</a><?php endif ?>
  </div>
  <?php endif ?>
  <?php endif ?>
</div>
<?php endif ?>
```

  </div>
</main>
</body>
</html>
