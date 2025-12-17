<?php
require_once __DIR__ . '/../ayar.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$flow=$_SESSION['flow']??[]; $ctx=$flow['ctx']??(isset($_SESSION['pending_user'])?'user':'dealer');
if ($ctx==='user'){ $telKey='user_tel'; $otpKey='user_otp'; $ret='usradmregform.php'; }
else { $telKey='dealer_tel'; $otpKey='dealer_otp'; $ret='dealershipform.php'; }

if (empty($_SESSION[$telKey])) { flash_set('errors','Oturum süresi doldu.'); header("Location: $ret"); exit; }

$telefon = $_SESSION[$telKey];

try {
  $otp = random_int(100000, 999999);
  $_SESSION[$otpKey] = ['code'=>(string)$otp,'exp'=>time()+180,'tries'=>0,'sent_at'=>time()];
  $_SESSION['otp_deadline'] = $_SESSION[$otpKey]['exp'];

  $msg="nexus.loglama.Net doğrulama kodunuz: {$otp} (3 dk geçerli)";
  $sentOk=false;
  try {
    if (class_exists('SMS')) {
      $sms=new SMS(0); $sms->Mesaj=$msg; $sms->Gidenler=$telefon; $sentOk=$sms->Gonder()?true:false;
    }
  } catch(Throwable $e){ $sentOk=false; }

  if ($sentOk) flash_set('otp_sent_ok', true);
  else flash_set('errors','Kod gönderilemedi. Lütfen daha sonra tekrar deneyin.');

} catch(Throwable $e){
  flash_set('errors','OTP üretim hatası: '.$e->getMessage());
}

session_write_close();
header("Location: $ret"); exit;
