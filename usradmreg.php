<?php
require_once __DIR__ . '/../ayar.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* GET ile gelindiyse: eski kayıt akışı state'ini temizle ve forma dön */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Sadece user kayıt akışıyla ilgili anahtarlar
    unset($_SESSION['pending_user'], $_SESSION['user_tel'], $_SESSION['user_otp']);
    unset($_SESSION['otp_deadline']);
    if (!empty($_SESSION['flow']) && ($_SESSION['flow']['ctx'] ?? '') === 'user') {
        unset($_SESSION['flow']);
    }
    session_write_close();
    header("Location: usradmregform.php");
    exit;
}

/* POST alanlarını al (ham) */
$tc      = trim($_POST['YETKILI_TC'] ?? '');
$eposta  = trim($_POST['YETKILI_EPOSTA'] ?? '');
$telefon = trim($_POST['YETKILI_TELEFON'] ?? '');
$adsoyad = trim($_POST['YETKILI_AD_SOYAD'] ?? '');
$firma   = trim($_POST['YETKILI_FIRMA'] ?? '');
$vergino = trim($_POST['YETKILI_VERGI_NO'] ?? '');
$dogum   = trim($_POST['YETKILI_DOGUM_TARIHI'] ?? '');

/* --- Normalize + Kesin Doğrulama (TR duyarlı) --- */
$errors = [];

// Firma adı: yalnızca İLK harfi büyük (tamamını büyütme)
if ($firma !== '') {
    $ilk   = mb_strtoupper(mb_substr($firma, 0, 1, 'UTF-8'), 'UTF-8');
    $kalan = mb_substr($firma, 1, null, 'UTF-8');
    $firma = $ilk . $kalan;
} else {
    $errors[] = 'Firma adı zorunludur.';
}

// Ad Soyad: her kelimenin ilk harfi büyük
if ($adsoyad !== '') {
    $adsoyad = mb_convert_case(mb_strtolower($adsoyad, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
} else {
    $errors[] = 'Ad Soyad zorunludur.';
}

// Vergi No: sadece rakam, tam 10 hane
$vergino = preg_replace('/\D+/', '', $vergino);
if (!preg_match('/^\d{10}$/', $vergino)) {
    $errors[] = 'Vergi No 10 haneli ve sadece rakam olmalıdır.';
}

// T.C. Kimlik: sadece rakam, tam 11 hane
$tc = preg_replace('/\D+/', '', $tc);
if (!preg_match('/^\d{11}$/', $tc)) {
    $errors[] = 'T.C. Kimlik 11 haneli ve sadece rakam olmalıdır.';
}

// Doğum yılı: sadece rakam, 4 hane
$dogum = preg_replace('/\D+/', '', $dogum);
if (!preg_match('/^\d{4}$/', $dogum)) {
    $errors[] = 'Doğum yılı 4 haneli olmalıdır (YYYY).';
}

// E-posta: HTML5 + server
if (!filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Geçersiz e-posta adresi.';
}

// Telefon: sadece rakam; 05 ile başlamalı; 11 hane; “05” otomatik normalize et
$telefon = preg_replace('/\D+/', '', $telefon);
if ($telefon === '') {
    $errors[] = 'Telefon zorunludur.';
} else {
    if (strpos($telefon, '05') !== 0) {
        if (strpos($telefon, '5') === 0) {
            $telefon = '0' . $telefon; // 5xxxxxxxxx -> 05xxxxxxxxx
        }
        if (strpos($telefon, '05') !== 0) {
            $telefon = '05' . ltrim($telefon, '0'); // kalan tüm durumlar -> 05...
        }
    }
    $telefon = substr($telefon, 0, 11);
    if (!preg_match('/^05\d{9}$/', $telefon)) {
        $errors[] = 'Telefon 05 ile başlamalı ve 11 haneli olmalıdır.';
    }
}

// Hata varsa PRG + flash ile geri dön
if (!empty($errors)) {
    flash_set('errors', $errors);
    session_write_close();
    header("Location: usradmregform.php");
    exit;
}

/* Mükerrer kontrol */
$kisi = new YETKILILER();
if ($dup = $kisi->MukerrerSorgulaDetayli($tc, $eposta, $telefon)) {
    flash_set('errors', $dup);
    session_write_close();
    header("Location: usradmregform.php");
    exit;
}

/* --- T.C. Kimlik doğrulama (MERNİS) GEÇİCİ OLARAK DEVRE DIŞI ---
$par  = preg_split('/\s+/', $adsoyad, -1, PREG_SPLIT_NO_EMPTY);
$isim = $par[0] ?? '';
$soy  = $par[count($par)-1] ?? '';
if ($AYAR->tcno_dogrula(["isim"=>$isim, "soyisim"=>$soy, "dogumyili"=>$dogum, "tcno"=>$tc]) !== "true") {
    flash_set('errors', '❌ T.C Kimlik doğrulaması başarısız.');
    session_write_close();
    header("Location: usradmregform.php");
    exit;
}
------------------------------------------------------------------- */

/* Pending (user=1) — normalize edilmiş değerler yazılır */
$_SESSION['pending_user'] = [
    "YETKILI_FIRMA"         => $firma,
    "YETKILI_VERGI_NO"      => $vergino,
    "YETKILI_AD_SOYAD"      => $adsoyad,
    "YETKILI_TC"            => $tc,
    "YETKILI_DOGUM_TARIHI"  => $dogum,
    "YETKILI_EPOSTA"        => $eposta,
    "YETKILI_TELEFON"       => $telefon,
    "YETKILI_RUTBE"         => 1
];

/* OTP + SMS (3 dk) */
$otp = random_int(100000, 999999);
$_SESSION['user_otp'] = [
  'code'    => (string)$otp,
  'exp'     => time() + 180,   // 3 dk
  'tries'   => 0,
  'sent_at' => time()
];
$_SESSION['user_tel']     = $telefon;
$_SESSION['otp_deadline'] = $_SESSION['user_otp']['exp'];

$msg = "nexus.loglama.Net doğrulama kodunuz: {$otp} (3 dk geçerli)";
$sentOk = false;
try {
    if (class_exists('SMS')) {
        $sms = new SMS(0);
        $sms->Mesaj    = $msg;
        $sms->Gidenler = $telefon;
        $sentOk = $sms->Gonder() ? true : false;
    }
} catch (Throwable $e) { $sentOk = false; }

if (!$sentOk) {
    flash_set('errors','Doğrulama kodu SMS ile gönderilemedi.');
    session_write_close();
    header("Location: usradmregform.php");
    exit;
}

/* Adım */
$_SESSION['flow'] = ['ctx'=>'user', 'step'=>2];

flash_set('otp_sent_ok', true);
session_write_close();
header("Location: usradmregform.php");
exit;
