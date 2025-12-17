<?php
require_once __DIR__ . '/../ayar.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $ret = (($_SESSION['flow']['ctx'] ?? '')==='user') ? 'usradmregform.php' : 'dealershipform.php';
  flash_set('errors','Geçersiz istek.'); header("Location: $ret"); exit;
}

$flow=$_SESSION['flow']??[]; $ctx=$flow['ctx']??(isset($_SESSION['pending_user'])?'user':'dealer');

if ($ctx==='user'){
  $pendingKey='pending_user'; $telKey='user_tel'; $otpKey='user_otp'; $ret='usradmregform.php';
} else {
  $pendingKey='pending_dealer'; $telKey='dealer_tel'; $otpKey='dealer_otp'; $ret='dealershipform.php';
}

if (empty($_SESSION[$pendingKey]) || empty($_SESSION[$telKey]) || empty($_SESSION[$otpKey]) || !is_array($_SESSION[$otpKey])) {
  flash_set('errors','Oturum süresi doldu veya eksik akış.'); header("Location: $ret"); exit;
}

$otp = trim($_POST['otp'] ?? '');
if (!preg_match('/^\d{6}$/',$otp)) { flash_set('errors','❌ Kod 6 haneli olmalıdır.'); header("Location: $ret"); exit; }

$pack = $_SESSION[$otpKey];
if (($pack['exp'] ?? 0) < time()) { flash_set('errors','Kodun süresi dolmuş. Lütfen yeniden kod isteyin.'); header("Location: $ret"); exit; }

$tries = (int)($pack['tries'] ?? 0);
if ($tries >= 5) { flash_set('errors','Çok fazla hatalı deneme. Lütfen yeniden kod isteyin.'); header("Location: $ret"); exit; }

if ((string)$otp !== (string)$pack['code']) {
  $_SESSION[$otpKey]['tries']=$tries+1;
  flash_set('errors','❌ Doğrulama kodu yanlış.'); header("Location: $ret"); exit;
}

/* Başarılı */
$_SESSION['flow']['step']=3;
unset($_SESSION[$otpKey], $_SESSION['otp_deadline']);

session_write_close();
header("Location: $ret"); exit;
