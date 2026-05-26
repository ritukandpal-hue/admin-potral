<?php
// ============================================================
// FILE: public_html/vendor/pincode.php
// Vendor PIN Serviceability Management — Dausto
// ============================================================
$configs=[
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__).'/config.php',
];
foreach($configs as $c){if(file_exists($c)){require_once $c;break;}}
if(!defined('PUBLIC_PATH')) die('<p style="color:red;padding:40px">config.php not found</p>');
if(session_status()===PHP_SESSION_NONE){
    session_start(['cookie_httponly'=>true,'cookie_secure'=>isset($_SERVER['HTTPS']),'cookie_samesite'=>'Lax','use_strict_mode'=>true]);
}
foreach(['db.php','auth.php','functions.php'] as $f) require_once PUBLIC_PATH.'/includes/'.$f;

if(empty($_SESSION['user_id'])||($_SESSION['portal']??'')!=='vendor'){
    header('Location: '.SITE_URL.'/vendor/login.php'); exit;
}
$user=currentUser(); $db=getDB();
$vendor_stmt=$db->prepare("SELECT * FROM vendors WHERE user_id=?");
$vendor_stmt->execute([$user['id']]); $vendor=$vendor_stmt->fetch();
if(!$vendor){ echo '<div style="font-family:sans-serif;padding:60px;text-align:center;color:#DC2626"><h2>Vendor account not linked.</h2><p>Contact <a href="https://wa.me/919319300883">Dausto support</a></p></div>'; exit; }
$vid=(int)$vendor['id'];
$msg=''; $msg_type='';

// ── POST HANDLERS ─────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';

    if($action==='add_single'){
        $pin=preg_replace('/\D/','',trim($_POST['pin_code']??''));
        $chk=$db->prepare("SELECT pin_code,city,state FROM pin_master WHERE pin_code=? AND is_active=1");
        $chk->execute([$pin]); $pm=$chk->fetch();
        if(!$pm){ $msg="PIN $pin is not in Dausto's network. Contact admin to add it first."; $msg_type='error'; }
        else{
            $ins=$db->prepare("INSERT INTO vendor_pin_serviceability (vendor_id,pin_code,status,added_method) VALUES (?,?,'pending','manual') ON DUPLICATE KEY UPDATE is_active=1,status='pending',added_method='manual',updated_at=NOW()");
            $ins->execute([$vid,$pin]);
            $msg="PIN $pin ({$pm['city']}, {$pm['state']}) added — pending admin approval."; $msg_type='success';
        }
    }

    if($action==='bulk_paste'){
        $pins_raw=trim($_POST['bulk_pins']??'');
        $pins=array_filter(array_unique(array_map(fn($p)=>preg_replace('/\D/','',$p),preg_split('/[\s,\n]+/',$pins_raw))));
        $added=$invalid=$not_master=0;
        foreach($pins as $pin){
            if(strlen($pin)!==6){$invalid++;continue;}
            $chk=$db->prepare("SELECT 1 FROM pin_master WHERE pin_code=? AND is_active=1");
            $chk->execute([$pin]);
            if(!$chk->fetchColumn()){$not_master++;continue;}
            try{
                $db->prepare("INSERT INTO vendor_pin_serviceability (vendor_id,pin_code,status,added_method) VALUES (?,?,'pending','bulk_csv') ON DUPLICATE KEY UPDATE is_active=1,status='pending',updated_at=NOW()")
                   ->execute([$vid,$pin]);
                $added++;
            }catch(\Exception $e){}
        }
        try{$db->prepare("INSERT INTO pin_bulk_upload_log (uploaded_by,uploader_type,vendor_id,upload_type,total_submitted,total_added,total_skipped,notes) VALUES (?,'vendor',?,'bulk_state',?,?,?,?)")->execute([$vid,$vid,count($pins),$added,$invalid+$not_master,"Paste bulk. $not_master not in master."]);}catch(\Exception $e){}
        $msg="$added PINs submitted · $invalid invalid format · $not_master not in Dausto network."; $msg_type='success';
    }

    if($action==='bulk_state'){
        $sf=trim($_POST['bulk_state']??'');
        if($sf){
            $sp=$db->prepare("SELECT pin_code FROM pin_master WHERE state=? AND is_active=1");
            $sp->execute([$sf]); $pts=$sp->fetchAll(PDO::FETCH_COLUMN);
            $added=0;
            foreach($pts as $pin){
                try{
                    $db->prepare("INSERT INTO vendor_pin_serviceability (vendor_id,pin_code,status,added_method) VALUES (?,?,'pending','bulk_state') ON DUPLICATE KEY UPDATE is_active=1,status='pending',updated_at=NOW()")
                       ->execute([$vid,$pin]);
                    $added++;
                }catch(\Exception $e){}
            }
            try{$db->prepare("INSERT INTO pin_bulk_upload_log (uploaded_by,uploader_type,vendor_id,upload_type,total_submitted,total_added,notes) VALUES (?,'vendor',?,'bulk_state',?,?,?)")->execute([$vid,$vid,$added,$added,"State bulk: $sf"]);}catch(\Exception $e){}
            $msg="$added PINs for $sf submitted for admin approval."; $msg_type='success';
        }
    }

    if($action==='request_all_india'){
        $reason=trim(strip_tags($_POST['reason']??''));
        try{$db->prepare("INSERT INTO pin_bulk_upload_log (uploaded_by,uploader_type,vendor_id,upload_type,total_submitted,notes) VALUES (?,'vendor',?,'all_india',0,?)")->execute([$vid,$vid,"All India request: $reason"]);}catch(\Exception $e){}
        $msg="All India request submitted. Admin will review within 24–48 hours."; $msg_type='success';
    }

    if($action==='remove_pin'){
        $pin=preg_replace('/\D/','',trim($_POST['pin']??''));
        if($pin){$db->prepare("UPDATE vendor_pin_serviceability SET is_active=0,updated_at=NOW() WHERE vendor_id=? AND pin_code=?")->execute([$vid,$pin]);}
        $msg="PIN $pin removed from your serviceability."; $msg_type='success';
    }

    if($action==='update_eta'){
        $pin=preg_replace('/\D/','',trim($_POST['pin']??''));
        $dmin=(int)($_POST['days_min']??0); $dmax=(int)($_POST['days_max']??0);
        $cutoff=trim($_POST['cutoff']??'14:00:00');
        if($pin&&$dmin&&$dmax){
            $db->prepare("UPDATE vendor_pin_serviceability SET custom_days_min=?,custom_days_max=?,dispatch_cutoff=?,updated_at=NOW() WHERE vendor_id=? AND pin_code=?")
               ->execute([$dmin,$dmax,$cutoff,$vid,$pin]);
            $msg="Custom ETA updated for PIN $pin."; $msg_type='success';
        }
    }
}

// ── DATA ───────────────────────────────────────────────────────
$filter_state =trim($_GET['state']??'');
$filter_status=trim($_GET['status']??'approved');
$search       =trim($_GET['q']??'');
$page         =max(1,(int)($_GET['pg']??1));
$per_page=50; $offset=($page-1)*$per_page;

$where="vps.vendor_id=?"; $params=[$vid];
if($filter_state){$where.=" AND pm.state=?"; $params[]=$filter_state;}
if($filter_status){$where.=" AND vps.status=?"; $params[]=$filter_status;}
if($search){$where.=" AND (vps.pin_code LIKE ? OR pm.city LIKE ?)"; $params[]="%$search%"; $params[]="%$search%";}

$tot=$db->prepare("SELECT COUNT(*) FROM vendor_pin_serviceability vps LEFT JOIN pin_master pm ON pm.pin_code=vps.pin_code WHERE $where AND vps.is_active=1");
$tot->execute($params); $total=(int)$tot->fetchColumn();
$pages=max(1,ceil($total/$per_page));

$ps=$db->prepare("SELECT vps.*,pm.city,pm.state,pm.days_min g_min,pm.days_max g_max,pm.is_next_day,pm.zone
    FROM vendor_pin_serviceability vps LEFT JOIN pin_master pm ON pm.pin_code=vps.pin_code
    WHERE $where AND vps.is_active=1
    ORDER BY vps.status='approved' DESC,pm.state,pm.city,vps.pin_code LIMIT $per_page OFFSET $offset");
$ps->execute($params); $my_pins=$ps->fetchAll();

$vstats=$db->prepare("SELECT SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) approved,SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) rejected,COUNT(DISTINCT pm.state) states FROM vendor_pin_serviceability vps LEFT JOIN pin_master pm ON pm.pin_code=vps.pin_code WHERE vps.vendor_id=? AND vps.is_active=1");
$vstats->execute([$vid]); $vstats=$vstats->fetch();

$master_total=(int)$db->query("SELECT COUNT(*) FROM pin_master WHERE is_active=1")->fetchColumn();
$coverage=$master_total>0?round((int)($vstats['approved']??0)/$master_total*100,1):0;

$master_states=$db->query("SELECT DISTINCT state,COUNT(*) cnt FROM pin_master WHERE is_active=1 GROUP BY state ORDER BY state")->fetchAll();
$my_states_q=$db->prepare("SELECT DISTINCT pm.state FROM vendor_pin_serviceability vps LEFT JOIN pin_master pm ON pm.pin_code=vps.pin_code WHERE vps.vendor_id=? AND vps.is_active=1 ORDER BY pm.state");
$my_states_q->execute([$vid]); $my_states=$my_states_q->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>

<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delivery PINs — Dausto Vendor Portal</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#F0F2F5;color:#0F172A;font-size:14px}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:240px;background:linear-gradient(180deg,#1A1A2E,#16213E);display:flex;flex-direction:column;z-index:100;box-shadow:4px 0 20px rgba(0,0,0,.2)}
.sb-logo{padding:18px 16px 14px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px}
.sb-logo img{width:34px;height:34px;border-radius:8px;object-fit:contain;background:#fff;padding:3px}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.nav-group{font-size:9.5px;font-weight:700;color:rgba(255,255,255,.2);letter-spacing:.12em;text-transform:uppercase;padding:12px 22px 4px}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 14px 9px 18px;margin:1px 8px;border-radius:9px;font-size:13.5px;font-weight:600;color:rgba(255,255,255,.55);text-decoration:none;transition:all .15s;white-space:nowrap}
.nav-link:hover{background:rgba(255,255,255,.08);color:#fff}
.nav-link.active{background:#D97706;color:#fff;box-shadow:0 2px 10px rgba(217,119,6,.4)}
.nav-icon{font-size:16px;width:22px;text-align:center;flex-shrink:0}
.main{margin-left:240px;min-height:100vh}
.topbar{background:#fff;border-bottom:1px solid #E2E8F0;padding:14px 26px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:40;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.content{padding:24px 26px}
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}
.stat{background:#fff;border-radius:12px;padding:16px 18px;border:1px solid #E2E8F0}
.stat-num{font-size:28px;font-weight:900;line-height:1;font-family:'Playfair Display',serif}
.stat-lbl{font-size:12px;color:#64748B;margin-top:4px;font-weight:600}
.cov-bar{width:100%;height:7px;background:#F1F5F9;border-radius:4px;overflow:hidden;margin-top:7px}
.cov-fill{height:100%;border-radius:4px;background:#16A34A}
.tabs{display:flex;gap:4px;border-bottom:2px solid #E2E8F0;margin-bottom:22px}
.tab{padding:10px 18px;font-size:13.5px;font-weight:700;cursor:pointer;border:none;background:transparent;color:#64748B;border-bottom:3px solid transparent;margin-bottom:-2px;font-family:'DM Sans',sans-serif}
.tab.active{color:#D97706;border-bottom-color:#D97706}
.tab-pane{display:none}.tab-pane.active{display:block}
.card{background:#fff;border-radius:12px;border:1px solid #E2E8F0;overflow:hidden;margin-bottom:20px}
.card-hd{padding:16px 20px;border-bottom:1px solid #E8ECF0;font-size:14px;font-weight:800;color:#0F172A;display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-body{padding:20px}
.fg{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:16px}
.fg2{grid-template-columns:1fr 1fr}
.field{display:flex;flex-direction:column;gap:5px}
.fl{font-size:11px;font-weight:700;color:#374151;letter-spacing:.06em;text-transform:uppercase}
.fi{border:1.5px solid #E2E8F0;border-radius:8px;padding:9px 12px;font-size:13.5px;color:#0F172A;font-family:'DM Sans',sans-serif;outline:none;background:#FAFBFC;width:100%}
.fi:focus{border-color:#D97706;background:#fff}
.fi-hint{font-size:11px;color:#94A3B8;margin-top:3px}
.btn{padding:9px 18px;border-radius:8px;font-size:13.5px;font-weight:700;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;display:inline-flex;align-items:center;gap:7px;transition:all .15s;text-decoration:none}
.btn-primary{background:#D97706;color:#fff}
.btn-primary:hover{background:#B45309}
.btn-green{background:#16A34A;color:#fff}
.btn-red{background:#DC2626;color:#fff}
.btn-ghost{background:#EEF0FB;color:#2E3D9A;border:1.5px solid #C7D0F7}
.btn-sm{padding:6px 14px;font-size:12.5px}
.btn-xs{padding:3px 10px;font-size:11.5px;border-radius:6px}
.filter-bar{background:#fff;border-radius:12px;border:1px solid #E2E8F0;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.alert{padding:12px 16px;border-radius:10px;font-size:13.5px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.alert-success{background:#F0FDF4;color:#15803D;border:1px solid #BBF7D0}
.alert-error{background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA}
.alert-info{background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#F8FAFC;padding:10px 14px;text-align:left;font-size:11px;font-weight:800;color:#64748B;letter-spacing:.05em;text-transform:uppercase;border-bottom:1.5px solid #E2E8F0;white-space:nowrap}
td{padding:10px 14px;border-bottom:1px solid #F1F5F9;vertical-align:middle}
tr:hover td{background:#FAFBFF}
.badge{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;display:inline-block}
.b-green{background:#F0FDF4;color:#16A34A}.b-red{background:#FEF2F2;color:#DC2626}
.b-blue{background:#EEF2FF;color:#2E3D9A}.b-amber{background:#FFFBEB;color:#D97706}
.b-gray{background:#F1F5F9;color:#64748B}
.state-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.state-card{background:#F8FAFC;border:1.5px solid #E2E8F0;border-radius:9px;padding:12px;cursor:pointer;transition:all .15s;text-align:center}
.state-card:hover{border-color:#D97706;background:#FFFBEB}
.state-card.sel{border-color:#16A34A;background:#F0FDF4}
.pg{display:flex;gap:6px;justify-content:center;margin-top:18px}
.pg-btn{padding:7px 12px;border-radius:7px;border:1.5px solid #E2E8F0;background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:#374151}
.pg-btn.active{background:#D97706;color:#fff;border-color:#D97706}
/* Modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-bg.show{display:flex}
.modal{background:#fff;border-radius:16px;padding:26px;max-width:400px;width:92%}
@media(max-width:768px){.sidebar{width:200px}.main{margin-left:200px}.stats-row{grid-template-columns:repeat(2,1fr)}.state-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sb-logo">
    <img src="../dausto.png" alt="Dausto" onerror="this.style.display='none'">
    <div>
      <div style="font-family:'Playfair Display',serif;font-size:14px;font-weight:900;color:#fff;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:155px"><?=htmlspecialchars(html_entity_decode($vendor['company_name'],ENT_QUOTES))?></div>
      <div style="font-size:9px;color:rgba(255,255,255,.3);font-weight:700;letter-spacing:.1em">VENDOR PORTAL</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="nav-group">MAIN</div>
    <a href="index.php" class="nav-link"><span class="nav-icon">🏠</span> Dashboard</a>
    <a href="orders.php" class="nav-link"><span class="nav-icon">📋</span> My Orders</a>
    <div class="nav-group">CATALOGUE</div>
    <a href="products.php" class="nav-link"><span class="nav-icon">📦</span> My Products</a>
    <a href="inventory.php" class="nav-link"><span class="nav-icon">📊</span> Inventory</a>
    <div class="nav-group">DELIVERY</div>
    <a href="pincode.php" class="nav-link active"><span class="nav-icon">📍</span> Delivery PINs</a>
    <div class="nav-group">FINANCE</div>
    <a href="earnings.php" class="nav-link"><span class="nav-icon">💰</span> Earnings</a>
    <a href="payouts.php" class="nav-link"><span class="nav-icon">🏦</span> Payouts</a>
    <div class="nav-group">ACCOUNT</div>
    <a href="profile.php" class="nav-link"><span class="nav-icon">👤</span> Profile</a>
    <a href="logout.php" class="nav-link" style="color:rgba(255,100,100,.65)"><span class="nav-icon">⏻</span> Logout</a>
  </nav>
  <div style="padding:12px 14px;border-top:1px solid rgba(255,255,255,.07)">
    <div style="font-size:11px;color:rgba(255,255,255,.3);margin-bottom:4px">KYC Status</div>
    <?php $kc=['verified'=>['#D1FAE5','#14532D'],'pending'=>['#FEF3C7','#92400E'],'rejected'=>['#FEE2E2','#7F1D1D']]; [$kb,$kt]=$kc[$vendor['kyc_status']??'pending']??['#F1F5F9','#475569']; ?>
    <span class="badge" style="background:<?=$kb?>;color:<?=$kt?>"><?=ucfirst($vendor['kyc_status']??'Pending')?></span>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div>
      <div style="font-size:19px;font-weight:900;font-family:'Playfair Display',serif">📍 My Delivery PIN Codes</div>
      <div style="font-size:12px;color:#64748B;margin-top:2px">Manage where you can fulfil orders · All additions require admin approval</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="badge b-green"><?=(int)($vstats['approved']??0)?> approved</span>
      <?php if((int)($vstats['pending']??0)>0): ?><span class="badge b-amber"><?=(int)$vstats['pending']?> pending</span><?php endif ?>
    </div>
  </div>

  <div class="content">

```
<?php if($msg): ?>
<div class="alert alert-<?=$msg_type==='success'?'success':'error'?>">
  <?=$msg_type==='success'?'✅':'❌'?> <?=htmlspecialchars($msg)?>
</div>
<?php endif ?>

<div class="alert alert-info">
  📋 <strong>How it works:</strong> Add PIN codes you can deliver to. All additions go to Dausto admin for approval. Once approved, customers at those PINs will see your delivery ETA on product pages. You can add individual PINs, bulk by state, or paste a list.
</div>

<!-- Stats -->
<div class="stats-row">
  <div class="stat"><div class="stat-num" style="color:#16A34A"><?=number_format((int)($vstats['approved']??0))?></div><div class="stat-lbl">Approved PINs</div><div class="cov-bar"><div class="cov-fill" style="width:<?=$coverage?>%"></div></div></div>
  <div class="stat"><div class="stat-num" style="color:#D97706"><?=number_format((int)($vstats['pending']??0))?></div><div class="stat-lbl">Pending Approval</div></div>
  <div class="stat"><div class="stat-num" style="color:#DC2626"><?=number_format((int)($vstats['rejected']??0))?></div><div class="stat-lbl">Rejected</div></div>
  <div class="stat"><div class="stat-num"><?=(int)($vstats['states']??0)?></div><div class="stat-lbl">States Covered</div></div>
  <div class="stat"><div class="stat-num" style="color:#2E3D9A"><?=$coverage?>%</div><div class="stat-lbl">Network Coverage</div></div>
</div>

<!-- Tabs -->
<div class="tabs">
  <button class="tab active" onclick="showTab('my',this)">📍 My PINs</button>
  <button class="tab" onclick="showTab('add',this)">➕ Add PINs</button>
  <button class="tab" onclick="showTab('bulk',this)">📂 Bulk by State</button>
</div>

<!-- TAB: MY PINS -->
<div id="tab-my" class="tab-pane active">
  <form method="GET">
    <div class="filter-bar">
      <div class="field"><label class="fl">Search PIN / City</label><input type="text" name="q" class="fi" placeholder="400001 or Mumbai" value="<?=htmlspecialchars($search)?>" style="width:170px"></div>
      <div class="field"><label class="fl">State</label>
        <select name="state" class="fi" style="width:155px">
          <option value="">All States</option>
          <?php foreach($my_states as $s): ?><option value="<?=htmlspecialchars($s)?>" <?=$filter_state===$s?'selected':''?>><?=htmlspecialchars($s)?></option><?php endforeach ?>
        </select>
      </div>
      <div class="field"><label class="fl">Status</label>
        <select name="status" class="fi" style="width:140px">
          <option value="">All</option>
          <option value="approved" <?=$filter_status==='approved'?'selected':''?>>Approved</option>
          <option value="pending" <?=$filter_status==='pending'?'selected':''?>>Pending</option>
          <option value="rejected" <?=$filter_status==='rejected'?'selected':''?>>Rejected</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="pincode.php" class="btn btn-ghost btn-sm">Clear</a>
      <span style="margin-left:auto;font-size:12px;color:#64748B"><?=number_format($total)?> PINs</span>
    </div>
  </form>

  <div class="card">
    <div class="tbl-wrap">
      <table>
        <tr><th>PIN</th><th>City</th><th>State</th><th>Zone</th><th>ETA (Global)</th><th>My Custom ETA</th><th>Next-Day</th><th>Status</th><th>Actions</th></tr>
        <?php foreach($my_pins as $p): ?>
        <tr>
          <td><strong><?=htmlspecialchars($p['pin_code'])?></strong></td>
          <td><?=htmlspecialchars($p['city']??'—')?></td>
          <td><?=htmlspecialchars($p['state']??'—')?></td>
          <td><span class="badge b-gray"><?=htmlspecialchars($p['zone']??'—')?></span></td>
          <td><?=$p['g_min']??'?'?>–<?=$p['g_max']??'?'?> days</td>
          <td><?=$p['custom_days_min']?'<span style="color:#D97706;font-weight:700">'.$p['custom_days_min'].'–'.$p['custom_days_max'].' days</span>':'<span style="color:#94A3B8">Global</span>'?></td>
          <td><?=$p['is_next_day']?'<span class="badge b-green">✓</span>':'—'?></td>
          <td><?php $bs=['approved'=>'b-green','pending'=>'b-amber','rejected'=>'b-red']; echo '<span class="badge '.($bs[$p['status']]??'b-gray').'">'.htmlspecialchars($p['status']).'</span>'; ?></td>
          <td style="white-space:nowrap">
            <button onclick="openEta('<?=htmlspecialchars($p['pin_code'])?>','<?=$p['custom_days_min']??$p['g_min']?>','<?=$p['custom_days_max']??$p['g_max']?>')" class="btn btn-xs btn-ghost">ETA</button>
            <form method="POST" style="display:inline;margin-left:4px" onsubmit="return confirm('Remove PIN <?=htmlspecialchars($p['pin_code'])?>?')">
              <input type="hidden" name="action" value="remove_pin">
              <input type="hidden" name="pin" value="<?=htmlspecialchars($p['pin_code'])?>">
              <button type="submit" class="btn btn-xs btn-red">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
        <?php if(empty($my_pins)): ?>
        <tr><td colspan="9" style="text-align:center;padding:30px;color:#94A3B8">No PINs yet. Use Add PINs or Bulk by State to get started.</td></tr>
        <?php endif ?>
      </table>
    </div>
  </div>

  <?php if($pages>1): ?>
  <div class="pg">
    <?php for($i=1;$i<=$pages;$i++): ?>
    <a href="?pg=<?=$i?>&state=<?=urlencode($filter_state)?>&status=<?=urlencode($filter_status)?>&q=<?=urlencode($search)?>" class="pg-btn <?=$i===$page?'active':''?>"><?=$i?></a>
    <?php endfor ?>
  </div>
  <?php endif ?>
</div>

<!-- TAB: ADD SINGLE / PASTE LIST -->
<div id="tab-add" class="tab-pane">
  <div class="card">
    <div class="card-hd">➕ Add a Single PIN Code</div>
    <div class="card-body">
      <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:9px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#1E40AF">ℹ️ Only PINs in Dausto's master network can be added. Contact admin if a PIN is missing.</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_single">
        <div class="fg fg2">
          <div class="field">
            <label class="fl">PIN Code *</label>
            <input type="text" name="pin_code" id="pinLookup" class="fi" maxlength="6" placeholder="e.g. 110001" required oninput="lookupPin(this.value)">
            <div class="fi-hint">6 digits. We'll verify it exists in our network.</div>
          </div>
          <div class="field">
            <label class="fl">PIN Status</label>
            <div id="pinLookupResult" style="background:#F8FAFC;border:1.5px solid #E2E8F0;border-radius:8px;padding:9px 12px;font-size:13px;color:#94A3B8;min-height:42px">Enter a PIN to verify…</div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Add PIN (submit for approval)</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-hd">📋 Paste a List of PIN Codes</div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="bulk_paste">
        <div class="field" style="margin-bottom:16px">
          <label class="fl">PIN Codes (comma / newline / space separated)</label>
          <textarea name="bulk_pins" class="fi" rows="6" placeholder="110001, 110002&#10;400001&#10;560001 560002" required style="resize:vertical"></textarea>
          <div class="fi-hint">Invalid or non-existent PINs are skipped automatically. You'll see a count.</div>
        </div>
        <button type="submit" class="btn btn-primary">Submit for Approval</button>
      </form>
    </div>
  </div>
</div>

<!-- TAB: BULK BY STATE -->
<div id="tab-bulk" class="tab-pane">
  <div class="card">
    <div class="card-hd">🗺️ Add All PINs for a State</div>
    <div class="card-body">
      <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:9px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#1E40AF">📍 Clicking a state adds ALL active PINs for that state from Dausto's network, pending admin approval.</div>
      <div class="state-grid" id="stateGrid">
        <?php foreach($master_states as $s):
          $inmy=in_array($s['state'],$my_states);
        ?>
        <div class="state-card <?=$inmy?'sel':''?>" onclick="selectState(this,'<?=htmlspecialchars($s['state'],ENT_QUOTES)?>')">
          <div style="font-size:13px;font-weight:700;color:#0F172A"><?=htmlspecialchars($s['state'])?></div>
          <div style="font-size:11px;color:#64748B"><?=number_format($s['cnt'])?> PINs <?=$inmy?'✓':''?></div>
        </div>
        <?php endforeach ?>
      </div>
      <form method="POST" id="bulkStateForm">
        <input type="hidden" name="action" value="bulk_state">
        <input type="hidden" name="bulk_state" id="selStateInput">
        <button type="submit" class="btn btn-primary" id="bulkStateBtn" disabled>Select a state above to continue →</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-hd">🌏 Request All India Serviceability</div>
    <div class="card-body">
      <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:9px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#92400E">⚠️ All India coverage is reviewed by Dausto admin — we check your fulfilment capacity and logistics. Typically reviewed within 24–48 hours.</div>
      <form method="POST">
        <input type="hidden" name="action" value="request_all_india">
        <div class="field" style="margin-bottom:14px">
          <label class="fl">Reason for Admin</label>
          <textarea name="reason" class="fi" rows="3" placeholder="e.g. We have logistics partners in all major cities…" required style="resize:vertical"></textarea>
        </div>
        <button type="submit" class="btn" style="background:#D97706;color:#fff">Request All India Coverage →</button>
      </form>
    </div>
  </div>
</div>
```

  </div>
</main>

<!-- ETA Modal -->

<div class="modal-bg" id="etaModal">
  <div class="modal">
    <h3 style="font-size:17px;font-weight:800;margin-bottom:16px">⏱️ Custom ETA for PIN <span id="etaPin"></span></h3>
    <form method="POST">
      <input type="hidden" name="action" value="update_eta">
      <input type="hidden" name="pin" id="etaPinInput">
      <div class="fg fg2" style="margin-bottom:12px">
        <div class="field"><label class="fl">Min Days</label><input type="number" name="days_min" id="etaMin" class="fi" min="1" max="30"></div>
        <div class="field"><label class="fl">Max Days</label><input type="number" name="days_max" id="etaMax" class="fi" min="1" max="30"></div>
      </div>
      <div class="field" style="margin-bottom:16px">
        <label class="fl">Dispatch Cutoff Time</label>
        <input type="time" name="cutoff" class="fi" value="14:00">
        <div class="fi-hint">Orders before this time dispatched same day</div>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary">Save ETA</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('etaModal').classList.remove('show')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function showTab(id,btn){
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+id).classList.add('active');
    btn.classList.add('active');
}
function openEta(pin,min,max){
    document.getElementById('etaPin').textContent=pin;
    document.getElementById('etaPinInput').value=pin;
    document.getElementById('etaMin').value=min;
    document.getElementById('etaMax').value=max;
    document.getElementById('etaModal').classList.add('show');
}
document.getElementById('etaModal').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('show');});
function selectState(el,state){
    document.querySelectorAll('.state-card').forEach(c=>c.style.outline='none');
    el.style.outline='3px solid #D97706';
    document.getElementById('selStateInput').value=state;
    const btn=document.getElementById('bulkStateBtn');
    btn.disabled=false;
    btn.textContent='Add all PINs for '+state+' →';
}
async function lookupPin(pin){
    const el=document.getElementById('pinLookupResult');
    if(pin.length<6){el.textContent='Enter 6 digits…';el.style.color='#94A3B8';return;}
    if(pin.length>6)return;
    el.textContent='Checking…';el.style.color='#64748B';
    try{
        const r=await fetch('../check_pin.php?pin='+pin);
        const d=await r.json();
        if(d.found){
            el.innerHTML='<span style="color:#16A34A;font-weight:700">✓ In Dausto network — '+d.city+', '+d.state+'</span><br><span style="font-size:12px;color:#64748B">ETA: '+d.eta+(d.is_next_day?' · Next-day eligible':'')+'</span>';
        }else{
            el.innerHTML='<span style="color:#DC2626;font-weight:700">✗ Not in Dausto network</span><br><span style="font-size:12px;color:#64748B">Contact admin to add this PIN to the master list</span>';
        }
    }catch(e){el.textContent='Error checking PIN.';}
}
</script>

</body>
</html>
