<?php
// FILE: public_html/vendor/logout.php
$config_locations = [
    '/home/u624259711/domains/orchid-alligator-169347.hostingersite.com/public_html/config.php',
    dirname(__DIR__) . '/config.php',
];
foreach ($config_locations as $cfg) { if (file_exists($cfg)) { require_once $cfg; break; } }
if (session_status()===PHP_SESSION_NONE) { session_start(); }
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
}
session_destroy();
$site_url = defined('SITE_URL') ? SITE_URL : '';
header('Location: ' . $site_url . '/vendor/login.php'); exit;
