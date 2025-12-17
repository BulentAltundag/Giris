<?php
require_once __DIR__ . '/../ayar.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ----------------------------------------------------
   ŞİFREMİ UNUTTUM 2FA AKIŞI (SESSION ANAHTARLARI)
   ----------------------------------------------------
   FORGOT_STEP         : 1 (TC), 2 (OTP), 3 (Yeni Şifre)
   FORGOT_TC           : 11 haneli T.C.
   FORGOT_TEL          : 05XXXXXXXXX
   FORGOT_OTP          : ['code','exp','tries','sent_at']
   FORGOT_LOCK_UNTIL   : timestamp (3 hata → 10 dk)
   FORGOT_DEADLINE     : OTP exp (görsel sayaç için ops.)
----------------------------------------------------- */

function back_to_self() {
  session_write_close();
  header("Location: forgotentry.php");
  exit;
}

/* SMS gönderimi (mevcut SMS sınıfı) */
function send_sms(string $tel, string $msg): bool {
  if (!class_exists('SMS')) return false;
  try {
    $sms = new SMS(0);
    $sms->Mesaj    = $msg;
    $sms->Gidenler = $tel;
    return $sms->Gonder() ? true : false;
  } catch (Throwable $e) {
    return false;
  }
}

/* T.C. ile kullanıcıyı bul (telefonu almak için) */
function find_user_by_tc(string $tc): ?array {
  try {
    // app_db() varsa PDO ile direkt erişelim; yoksa GokDB deneyebiliriz
    if (function_exists('app_db')) {
      $pdo = app_db();
      $stmt = $pdo->prepare("SELECT YETKILI_ID, YETKILI_TC, YETKILI_TELEFON, YETKILI_RUTBE, YETKILI_AD_SOYAD 
                               FROM yetkililer_tab WHERE YETKILI_TC = :tc LIMIT 1");
      $stmt->execute([':tc'=>$tc]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } elseif (class_exists('GokDB')) {
      $db = new GokDB();
      $rows = $db->select("yetkililer_tab")->where("YETKILI_TC", $tc)->run();
      if ($rows && isset($rows[0])) return $rows[0];
      return null;
    }
    return null;
  } catch (Throwable $e) {
    return null;
  }
}

/* T.C. için yeni şifreyi güncelle (hash) */
function update_password_by_tc(string $tc, string $hashed): bool {
  try {
    if (!function_exists('app_db')) return false;
    $pdo = app_db();
    $stmt = $pdo->prepare("UPDATE yetkililer_tab SET YETKILI_SIFRE=:hs WHERE YETKILI_TC=:tc");
    return $stmt->execute([':hs'=>$hashed, ':tc'=>$tc]);
  } catch (Throwable $e) {
    return false;
  }
}

/* GET → formu çiz (en sonda require forgotform.php) */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  // Mantıksız state varsa 1. adıma resetle
  $needReset = false;
  if (!isset($_SESSION['FORGOT_STEP'])) {
    $needReset = true;
  } else {
    $st = (int)$_SESSION['FORGOT_STEP'];
    if ($st === 2) {
      if (empty($_SESSION['FORGOT_TC']) || empty($_SESSION['FORGOT_TEL']) || empty($_SESSION['FORGOT_OTP'])) {
        $needReset = true;
      }
    } elseif ($st === 3) {
      if (empty($_SESSION['FORGOT_TC'])) {
        $needReset = true;
      }
    }
  }
  if ($needReset) {
    unset($_SESSION['FORGOT_TC'], $_SESSION['FORGOT_TEL'], $_SESSION['FORGOT_OTP'],
          $_SESSION['FORGOT_LOCK_UNTIL'], $_SESSION['FORGOT_DEADLINE']);
    $_SESSION['FORGOT_STEP'] = 1;
  }
  require __DIR__ . '/forgotform.php';
  exit;
}

/* POST: action parametresi */
$action = $_POST['action'] ?? '';

/* 1) TC GÖNDER (adım 1 → 2) */
if ($action === 'start') {
  $tc = preg_replace('/\D+/', '', (string)($_POST['tc'] ?? ''));
  if (!preg_match('/^\d{11}$/', $tc)) {
    flash_set('errors','T.C. Kimlik No 11 haneli ve sadece rakam olmalıdır.');
    back_to_self();
  }

  $u = find_user_by_tc($tc);
  if (!$u) {
    flash_set('errors','Bu T.C. Kimlik numarası ile kayıt bulunamadı.');
    back_to_self();
  }

  // Telefonu normalize et 05XXXXXXXXX
  $tel = preg_replace('/\D+/', '', (string)($u['YETKILI_TELEFON'] ?? ''));
  if ($tel !== '') {
    if (strpos($tel,'05') !== 0) {
      if (strpos($tel,'5')===0) $tel = '0'.$tel;
      if (strpos($tel,'05')!==0) $tel = '05'.ltrim($tel,'0');
    }
    $tel = substr($tel,0,11);
  }
  if (!preg_match('/^05\d{9}$/', $tel)) {
    flash_set('errors','Hesabınıza tanımlı geçerli bir telefon bulunamadı.');
    back_to_self();
  }

  // OTP üret + SMS (3 dk)
  $otp = random_int(100000, 999999);
  $_SESSION['FORGOT_OTP'] = [
    'code'    => (string)$otp,
    'exp'     => time() + 180,
    'tries'   => 0,
    'sent_at' => time(),
  ];
  $_SESSION['FORGOT_TC']       = $tc;
  $_SESSION['FORGOT_TEL']      = $tel;
  $_SESSION['FORGOT_STEP']     = 2;
  $_SESSION['FORGOT_DEADLINE'] = $_SESSION['FORGOT_OTP']['exp'];
  unset($_SESSION['FORGOT_LOCK_UNTIL']);

  $msg = "Şifre sıfırlama doğrulama kodunuz: {$otp} (3 dk geçerli)";
  $ok  = send_sms($tel, $msg);
  if ($ok) {
    flash_set('info_ok', true);
  } else {
    flash_set('errors','Doğrulama kodu gönderilemedi. Lütfen tekrar deneyin.');
  }
  back_to_self();
}

/* 2) OTP DOĞRULA (adım 2 → 3) */
if ($action === 'verify') {
  if (empty($_SESSION['FORGOT_OTP']) || empty($_SESSION['FORGOT_TC']) || empty($_SESSION['FORGOT_TEL'])) {
    flash_set('errors','Oturum süresi doldu veya eksik akış.');
    back_to_self();
  }

  // brute-force kilit kontrol
  $lockUntil = (int)($_SESSION['FORGOT_LOCK_UNTIL'] ?? 0);
  if ($lockUntil > time()) {
    $kalan = $lockUntil - time();
    flash_set('errors', "Çok fazla hatalı deneme. Lütfen ".floor($kalan/60)." dk ".($kalan%60)." sn sonra tekrar deneyin.");
    back_to_self();
  }

  $otp = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));
  if (!preg_match('/^\d{6}$/', $otp)) {
    flash_set('errors','Kod 6 haneli olmalıdır.');
    back_to_self();
  }

  $pack  = $_SESSION['FORGOT_OTP'];
  $tries = (int)($pack['tries'] ?? 0);
  $exp   = (int)($pack['exp'] ?? 0);

  if (time() > $exp) {
    flash_set('errors','Kodun süresi dolmuş. Lütfen yeniden kod isteyin.');
    back_to_self();
  }

  if ((string)$otp !== (string)$pack['code']) {
    $_SESSION['FORGOT_OTP']['tries'] = $tries + 1;
    if ($tries + 1 >= 3) {
      $_SESSION['FORGOT_LOCK_UNTIL'] = time() + 600; // 10 dk kilit
      flash_set('errors','Çok fazla hatalı deneme. 10 dakika sonra tekrar deneyin.');
    } else {
      flash_set('errors','Doğrulama kodu yanlış.');
    }
    back_to_self();
  }

  // Başarılı → adım 3
  $_SESSION['FORGOT_STEP'] = 3;
  back_to_self();
}

/* 3) OTP YENİDEN GÖNDER (adım 2’de) */
if ($action === 'resend') {
  if (empty($_SESSION['FORGOT_TEL']) || empty($_SESSION['FORGOT_TC'])) {
    flash_set('errors','Oturum süresi doldu.');
    back_to_self();
  }
  // kilitli ise gönderme
  $lockUntil = (int)($_SESSION['FORGOT_LOCK_UNTIL'] ?? 0);
  if ($lockUntil > time()) {
    $kalan = $lockUntil - time();
    flash_set('errors', "Çok fazla hatalı deneme. Lütfen ".floor($kalan/60)." dk ".($kalan%60)." sn sonra tekrar deneyin.");
    back_to_self();
  }

  $tel   = $_SESSION['FORGOT_TEL'];
  $otp   = random_int(100000, 999999);
  $tries = (int)($_SESSION['FORGOT_OTP']['tries'] ?? 0); // kötüye kullanım için sıfırlamıyoruz

  $_SESSION['FORGOT_OTP'] = [
    'code'    => (string)$otp,
    'exp'     => time() + 180,
    'tries'   => $tries,
    'sent_at' => time(),
  ];
  $_SESSION['FORGOT_DEADLINE'] = $_SESSION['FORGOT_OTP']['exp'];
  $_SESSION['FORGOT_STEP']     = 2;

  $msg = "Şifre sıfırlama doğrulama kodunuz: {$otp} (3 dk geçerli)";
  $ok  = send_sms($tel, $msg);

  if ($ok) flash_set('info_ok', true);
  else     flash_set('errors','Kod gönderilemedi. Lütfen daha sonra tekrar deneyin.');
  back_to_self();
}

/* 4) YENİ ŞİFRE BELİRLE (adım 3 → tamam) */
if ($action === 'setpass') {
  if (empty($_SESSION['FORGOT_TC']) || (int)($_SESSION['FORGOT_STEP'] ?? 1) !== 3) {
    flash_set('errors','Oturum süresi doldu veya eksik akış.');
    back_to_self();
  }

  $sifre  = (string)($_POST['sifre']  ?? '');
  $sifre2 = (string)($_POST['sifre2'] ?? '');

  if ($sifre === '' || $sifre2 === '' || $sifre !== $sifre2) {
    flash_set('errors','Şifreler eşleşmiyor.');
    back_to_self();
  }

  $hash = password_hash($sifre, PASSWORD_DEFAULT);
  $ok   = update_password_by_tc($_SESSION['FORGOT_TC'], $hash);

  if (!$ok) {
    flash_set('errors','Şifre güncellenemedi. Lütfen daha sonra tekrar deneyin.');
    back_to_self();
  }

  // Temizlik
  unset($_SESSION['FORGOT_TC'], $_SESSION['FORGOT_TEL'], $_SESSION['FORGOT_OTP'],
        $_SESSION['FORGOT_LOCK_UNTIL'], $_SESSION['FORGOT_DEADLINE'], $_SESSION['FORGOT_STEP']);

  // Başarılı ekran + yönlendirme (panelentry)
  $redirectUrl = 'panelentry.php'; $delay = 1800; // 1.8 sn
  session_write_close();
  ?>
  <!DOCTYPE html><html lang="tr"><head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Şifre Güncellendi</title>
  <link rel="stylesheet" href="../css/adm/css/bootstrap.min.css">
  <style>
  html,body{height:100%}
  .overlay{position:fixed;inset:0;background:rgba(255,255,255,.96);display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}
  .spinner-large{width:72px;height:72px;border:8px solid rgba(0,0,0,.1);border-top-color:rgba(0,0,0,.55);border-radius:50%;margin:0 auto 16px;animation:spin .9s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .success-text{font-size:1.1rem;color:#155724}
  .muted{color:#6c757d;font-size:.95rem}
  </style>
  </head><body>
  <div class="overlay"><div>
    <div class="spinner-large"></div>
    <div class="success-text">✅ Şifreniz güncellendi. Giriş sayfasına yönlendiriliyorsunuz…</div>
    <div class="muted" style="margin-top:6px;">Otomatik yönlenmezse <a href="<?= htmlspecialchars($redirectUrl,ENT_QUOTES,'UTF-8') ?>">buraya tıklayın</a>.</div>
  </div></div>
  <script>setTimeout(function(){location.href=<?= json_encode($redirectUrl) ?>;},<?= (int)$delay ?>);</script>
  </body></html>
  <?php
  exit;
}

/* Beklenmeyen action */
flash_set('errors','Geçersiz istek.');
back_to_self();
