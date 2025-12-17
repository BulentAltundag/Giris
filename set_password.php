<?php
require_once __DIR__ . '/../ayar.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/*
  Bu dosya hem dealer hem user akışını tamamlar.
  Akış bilgisi: $_SESSION['flow'] => ['ctx'=>'dealer'|'user', 'step'=>3]
  Kayıt verisi: dealer -> $_SESSION['pending_dealer']
                user   -> $_SESSION['pending_user']
*/

function back_to_step3(string $ctx, string $msg) {
  flash_set('errors', $msg);
  // step-3 ekranına geri dön
  if (!isset($_SESSION['flow'])) $_SESSION['flow'] = [];
  $_SESSION['flow']['ctx']  = $ctx;
  $_SESSION['flow']['step'] = 3;
  $back = ($ctx === 'user') ? 'usradmregform.php' : 'dealershipform.php';
  header("Location: $back");
  exit;
}

function normalize_tel_05(string $tel): string {
  $d = preg_replace('/\D+/', '', $tel);
  if ($d !== '') {
    if (strpos($d,'05') !== 0) {
      if (strpos($d,'5')===0) $d = '0'.$d;
      if (strpos($d,'05')!==0) $d = '05'.ltrim($d,'0');
    }
    $d = substr($d, 0, 11);
  }
  return $d;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  // Direkt gelindiyse başa dön
  $ctx = (!empty($_SESSION['flow']['ctx']) && $_SESSION['flow']['ctx']==='user') ? 'user' : 'dealer';
  $back = ($ctx === 'user') ? 'usradmregform.php' : 'dealershipform.php';
  flash_set('errors', 'Geçersiz istek.');
  header("Location: $back");
  exit;
}

/* Akış ve context kontrolü */
$ctx  = (!empty($_SESSION['flow']['ctx']) && $_SESSION['flow']['ctx']==='user') ? 'user' : 'dealer';
$step = (int)($_SESSION['flow']['step'] ?? 1);
$pendingKey = ($ctx === 'user') ? 'pending_user' : 'pending_dealer';

if ($step !== 3 || empty($_SESSION[$pendingKey])) {
  $back = ($ctx === 'user') ? 'usradmregform.php' : 'dealershipform.php';
  flash_set('errors', 'Oturum süresi doldu veya eksik akış.');
  header("Location: $back");
  exit;
}

/* Şifreleri al + eşleşme + politika */
$pass1 = (string)($_POST['sifre']  ?? '');
$pass2 = (string)($_POST['sifre2'] ?? '');

if ($pass1 === '' || $pass2 === '' || $pass1 !== $pass2) {
  back_to_step3($ctx, 'Şifreler eşleşmiyor.');
}
$okLen = mb_strlen($pass1) >= 12;
$okBig = preg_match('/[A-ZÇĞİÖŞÜ]/u', $pass1);
$okNum = preg_match('/\d/', $pass1);
$okSp  = preg_match('/[^A-Za-z0-9ÇĞİÖŞÜçğıöşü]/u', $pass1);
if (!($okLen && $okBig && $okNum && $okSp)) {
  back_to_step3($ctx, 'Şifre en az 12 karakter olmalı; 1 büyük harf, 1 rakam ve 1 özel karakter içermelidir.');
}

/* Pending veriyi oku */
$P = $_SESSION[$pendingKey];  // bekleyen form alanları

// Zorunlu alanların geldiğinden emin ol (kayıt formu ile birebir)
$required = ['YETKILI_FIRMA','YETKILI_VERGI_NO','YETKILI_AD_SOYAD','YETKILI_TC','YETKILI_DOGUM_TARIHI','YETKILI_EPOSTA','YETKILI_TELEFON'];
foreach ($required as $k) {
  if (!isset($P[$k]) || trim((string)$P[$k]) === '') {
    back_to_step3($ctx, "Eksik alan: $k");
  }
}

/* User için RÜTBEYİ kesin 1 yapalım; dealer için pending’de ne geldiyse */
$rutbe = ($ctx === 'user') ? 1 : (int)($P['YETKILI_RUTBE'] ?? 123456);

/* Telefon normalize */
$tel = normalize_tel_05((string)$P['YETKILI_TELEFON']);
if (!preg_match('/^05\d{9}$/', $tel)) {
  back_to_step3($ctx, 'Telefon numarası geçersiz.');
}

/* Hashle */
$hash = password_hash($pass1, PASSWORD_DEFAULT);

/* INSERT */
try {
  if (!function_exists('app_db')) throw new Exception('DB bağlantısı bulunamadı.');
  $pdo = app_db();

  // Aynı TC/E-posta/Telefon zaten varsa reddet (emniyet)
  $dupStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM yetkililer_tab
                            WHERE YETKILI_TC = :tc OR YETKILI_EPOSTA = :eposta OR YETKILI_TELEFON = :tel");
  $dupStmt->execute([
    ':tc'     => $P['YETKILI_TC'],
    ':eposta' => $P['YETKILI_EPOSTA'],
    ':tel'    => $tel
  ]);
  $c = (int)$dupStmt->fetchColumn();
  if ($c > 0) {
    back_to_step3($ctx, 'Bu T.C / e-posta / telefon zaten kayıtlı.');
  }

  $stmt = $pdo->prepare("
    INSERT INTO yetkililer_tab
      (YETKILI_FIRMA, YETKILI_VERGI_NO, YETKILI_AD_SOYAD, YETKILI_TC, YETKILI_DOGUM_TARIHI,
       YETKILI_EPOSTA, YETKILI_TELEFON, YETKILI_RUTBE, YETKILI_SIFRE)
    VALUES
      (:firma, :vergino, :adsoy, :tc, :dogum,
       :eposta, :tel, :rutbe, :sifre)
  ");
  $ok = $stmt->execute([
    ':firma'  => $P['YETKILI_FIRMA'],
    ':vergino'=> $P['YETKILI_VERGI_NO'],
    ':adsoy'  => $P['YETKILI_AD_SOYAD'],
    ':tc'     => $P['YETKILI_TC'],
    ':dogum'  => $P['YETKILI_DOGUM_TARIHI'],
    ':eposta' => $P['YETKILI_EPOSTA'],
    ':tel'    => $tel,
    ':rutbe'  => $rutbe,
    ':sifre'  => $hash
  ]);

  if (!$ok) {
    back_to_step3($ctx, 'Kayıt tamamlanırken bir hata oluştu (DB).');
  }

  // Temizlik
  unset($_SESSION[$pendingKey], $_SESSION['user_tel'], $_SESSION['dealer_tel'],
        $_SESSION['user_otp'], $_SESSION['dealer_otp'], $_SESSION['otp_deadline'], $_SESSION['flow']);

  // Başarılı ekran ve yönlendirme
  $redirectUrl = '/panelentry.php';
  $delay = 2200;
  session_write_close();
  ?>
  <!DOCTYPE html><html lang="tr"><head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kayıt Tamamlandı</title>
  <link rel="stylesheet" href="../css/adm/css/bootstrap.min.css">
  <style>
  html,body{height:100%}
  .overlay{position:fixed;inset:0;background:rgba(255,255,255,.95);display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}
  .spinner-large{width:72px;height:72px;border:8px solid rgba(0,0,0,.1);border-top-color:rgba(0,0,0,.55);border-radius:50%;margin:0 auto 16px;animation:spin .9s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .success-text{font-size:1.1rem;color:#155724}
  .muted{color:#6c757d;font-size:.95rem}
  </style></head><body>
  <div class="overlay"><div>
    <div class="spinner-large"></div>
    <div class="success-text">✅ Kayıt tamamlandı. Şimdi giriş sayfasına yönlendiriliyorsunuz…</div>
    <div class="muted" style="margin-top:6px;">Otomatik yönlenmezse <a href="<?= htmlspecialchars($redirectUrl,ENT_QUOTES,'UTF-8') ?>">buraya tıklayın</a>.</div>
  </div></div>
  <script>setTimeout(function(){location.href=<?= json_encode($redirectUrl) ?>;},<?= (int)$delay ?>);</script>
  </body></html>
  <?php
  exit;

} catch (Throwable $e) {
  back_to_step3($ctx, 'Kayıt tamamlanırken bir hata oluştu: ' . $e->getMessage());
}
