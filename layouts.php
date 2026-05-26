<?php
// FILE: public_html/vendor/layout.php
// Shared layout functions for vendor portal

if (!function_exists('vendorHead')) {
function vendorHead(string $title): string {
    $site_url = defined('SITE_URL') ? SITE_URL : '';
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($title) . ' — Dausto Vendor</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:\'DM Sans\',sans-serif;background:#F0F2F5;color:#0F172A}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:240px;background:linear-gradient(180deg,#0F172A,#1A2540);display:flex;flex-direction:column;z-index:100;box-shadow:4px 0 24px rgba(0,0,0,0.15)}
.sidebar-logo{padding:20px 18px 14px;border-bottom:1px solid rgba(255,255,255,0.07)}
.sidebar-nav{flex:1;overflow-y:auto;padding:8px 0}
.nav-label{font-size:9.5px;font-weight:700;color:rgba(255,255,255,0.2);letter-spacing:.12em;text-transform:uppercase;padding:12px 20px 4px}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 12px 9px 16px;margin:1px 8px;border-radius:9px;font-size:13.5px;font-weight:600;color:rgba(255,255,255,0.55);text-decoration:none;transition:all .15s;white-space:nowrap}
.nav-link:hover{background:rgba(255,255,255,0.08);color:#fff}
.nav-link.active{background:#059669;color:#fff;box-shadow:0 2px 12px rgba(5,150,105,0.4)}
.nav-icon{font-size:16px;width:22px;text-align:center;flex-shrink:0}
.sidebar-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,0.07);flex-shrink:0}
.main{margin-left:240px;min-height:100vh}
.topbar{background:#fff;border-bottom:1px solid #E2E8F0;padding:14px 26px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,0.05)}
.page-content{padding:24px}
.card{background:#fff;border-radius:14px;border:1px solid #E8ECF2;box-shadow:0 1px 4px rgba(0,0,0,0.04);overflow:hidden;margin-bottom:20px}
.card-head{padding:15px 20px;border-bottom:1px solid #F0F3F6;display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14.5px;font-weight:800;color:#0F172A}
.stat-card{background:#fff;border-radius:14px;border:1px solid #E8ECF2;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,0.04)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-size:13.5px;font-weight:700;border:none;cursor:pointer;text-decoration:none;font-family:\'DM Sans\',sans-serif;transition:all .15s}
.btn-primary{background:#059669;color:#fff;box-shadow:0 2px 8px rgba(5,150,105,0.25)}
.btn-primary:hover{background:#047857}
.btn-ghost{background:#F8FAFC;color:#374151;border:1px solid #E2E8F0}
.btn-ghost:hover{background:#EEF9F5;color:#059669;border-color:#059669}
.btn-sm{padding:6px 13px;font-size:12.5px}
.form-label{display:block;font-size:11px;font-weight:700;color:#374151;letter-spacing:.06em;margin-bottom:4px;text-transform:uppercase}
.form-input{width:100%;border:1.5px solid #E2E8F0;border-radius:9px;padding:10px 13px;font-size:13.5px;color:#0F172A;font-family:\'DM Sans\',sans-serif;outline:none;background:#FAFBFC;transition:border-color .15s}
.form-input:focus{border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.1);background:#fff}
.dtable{width:100%;border-collapse:collapse}
.dtable th{background:#F8FAFC;padding:9px 16px;font-size:10.5px;font-weight:700;color:#64748B;letter-spacing:.06em;text-align:left;border-bottom:1px solid #F0F3F6}
.dtable td{padding:11px 16px;font-size:13px;color:#374151;border-bottom:1px solid #F8FAFC;vertical-align:middle}
.dtable tr:last-child td{border-bottom:none}
.dtable tbody tr:hover td{background:#F8FFF8}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
.pill-green{background:#D1FAE5;color:#14532D}
.pill-yellow{background:#FEF3C7;color:#92400E}
.pill-red{background:#FEE2E2;color:#7F1D1D}
.pill-blue{background:#DBEAFE;color:#1E40AF}
.pill-gray{background:#F1F5F9;color:#475569}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px}
.alert-success{background:#F0FDF4;border:1px solid #BBF7D0;color:#14532D}
.alert-error{background:#FEF2F2;border:1px solid #FECACA;color:#7F1D1D}
.link-sm{font-size:12.5px;font-weight:700;color:#059669;text-decoration:none}
.link-sm:hover{text-decoration:underline}
</style>';
}
}

if (!function_exists('vendorNav')) {
function vendorNav(array $vendor, array $stats = []): string {
    $site_url = defined('SITE_URL') ? SITE_URL : '';
    $cur = basename($_SERVER['PHP_SELF']);
    $pending_orders = (int)($stats['pending_orders'] ?? 0);
    $low_stock      = (int)($stats['low_stock'] ?? 0);

    $links = [
        ['index.php',    '🏠', 'Dashboard',  ''],
        ['products.php', '📦', 'My Products', $low_stock > 0 ? (string)$low_stock : ''],
        ['orders.php',   '📋', 'Orders',      $pending_orders > 0 ? (string)$pending_orders : ''],
        ['payouts.php',  '💰', 'Payouts',     ''],
        ['profile.php',  '🏪', 'Store Profile',''],
    ];

    $nav = '';
    foreach ($links as [$href, $icon, $label, $badge]) {
        $active = $cur === $href ? ' active' : '';
        $bdg = $badge ? '<span style="margin-left:auto;background:#DC2626;color:#fff;font-size:10px;font-weight:800;padding:1px 7px;border-radius:10px">'.$badge.'</span>' : '';
        $nav .= '<a href="'.$href.'" class="nav-link'.$active.'"><span class="nav-icon">'.$icon.'</span> '.$label.$bdg.'</a>';
    }

    // ── PRINT JOBS — only visible to print-enabled vendors ──────────────────
    if (!empty($vendor['is_print_vendor'])) {
        $pending_print = (int)($stats['pending_print_jobs'] ?? 0);
        $active_pj = $cur === 'print-jobs.php' ? ' active' : '';
        $bdg_pj = $pending_print > 0
            ? '<span style="margin-left:auto;background:#DC2626;color:#fff;font-size:10px;font-weight:800;padding:1px 7px;border-radius:10px">'.$pending_print.'</span>'
            : '';
        $nav .= '<div class="nav-label">PRINT JOBS</div>';
        $nav .= '<a href="print-jobs.php" class="nav-link'.$active_pj.'"><span class="nav-icon">🖨️</span> Print Jobs'.$bdg_pj.'</a>';
    }
    // ────────────────────────────────────────────────────────────────────────

    $initial = strtoupper(substr($vendor['company_name'] ?? 'V', 0, 1));
    $status_color = ($vendor['status'] ?? '') === 'active' ? '#059669' : '#D97706';
    $status_label = ucfirst($vendor['status'] ?? 'pending');

    return '
<aside class="sidebar">
  <div class="sidebar-logo">
    <div style="display:flex;align-items:center;gap:10px">
      <img src="../dausto.png" alt="Dausto" style="width:32px;height:32px;border-radius:8px;object-fit:contain;background:#fff;padding:2px;flex-shrink:0" onerror="this.style.display=\'none\'">
      <div>
        <div style="font-family:\'Playfair Display\',serif;font-size:16px;font-weight:900;color:#fff;line-height:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px">'.htmlspecialchars($vendor['company_name'] ?? 'My Store').'</div>
        <div style="font-size:9px;color:rgba(255,255,255,0.28);font-weight:700;letter-spacing:.1em">VENDOR PORTAL</div>
      </div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Menu</div>
    '.$nav.'
  </nav>
  <div class="sidebar-user">
    <div style="display:flex;align-items:center;gap:9px">
      <div style="width:32px;height:32px;background:#059669;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0">'.$initial.'</div>
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'.htmlspecialchars($vendor['company_name'] ?? '').'</div>
        <div style="font-size:10px;font-weight:700;color:'.$status_color.'">'.$status_label.'</div>
      </div>
      <a href="logout.php" title="Logout" style="color:rgba(255,255,255,0.25);font-size:18px;text-decoration:none">⏻</a>
    </div>
  </div>
</aside>';
}
}

if (!function_exists('csrfField')) {
function csrfField(): string {
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['csrf_token']).'">'; 
}
}
