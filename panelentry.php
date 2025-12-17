<?php
// /Giris/panelentry.php
require_once __DIR__ . '/../ayar.php';
require_once __DIR__ . '/../class/otp_helper.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------- Yardımcılar ---------- */
function send_login_sms(string $tel, string $msg): bool {
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
function reset_login_flow(): void {
    unset(
        $_SESSION['LOGIN_STEP'],
        $_SESSION['LOGIN_TEL'],
        $_SESSION['LOGIN_OTP'],
        $_SESSION['LOGIN_LOCK_UNTIL'],
        $_SESSION['LOGIN_USER_SERIAL']
    );
}

/* ZATEN GİRİŞLİYSE panelden devam */
if (isset($_SESSION['YETKILI'])) {
    header('Location: /upanel.php');
    exit;
}

/* -------- GET: Ekranı çiz --------
   - OTP oturumu geçerliyse step=2 ekranında kal
   - Aksi halde akışı sıfırla → TC+Şifre formu
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $hasOtpSession = !empty($_SESSION['LOGIN_OTP']) &&
                     !empty($_SESSION['LOGIN_TEL']) &&
                     (int)($_SESSION['LOGIN_STEP'] ?? 1) === 2;

    if (!$hasOtpSession) {
        reset_login_flow();
    }
    require __DIR__ . '/panelgirisform.php';
    exit;
}

/* ------------------ POST AKIŞI ------------------ */

/* 1) T.C. + ŞİFRE → KULLANICI DOĞRULAMA ve OTP BAŞLATMA */
if (isset($_POST['YETKILI_TC'], $_POST['YETKILI_SIFRE']) && !isset($_POST['GIRIS_OTP']) && !isset($_POST['LOGIN_RESEND'])) {

    $tc    = preg_replace('/\D+/', '', (string)$_POST['YETKILI_TC']);
    $sifre = (string)($_POST['YETKILI_SIFRE'] ?? '');

    if (!preg_match('/^\d{11}$/', $tc) || $sifre === '') {
        flash_set('errors', 'T.C. Kimlik No ya da Şifre doğrulanmadı.');
        reset_login_flow();
        header('Location: /Giris/panelentry.php');
        exit;
    }

    try {
        // Sınıfınızla doğrula
        $yetkili = new YETKILILER();
        $yetkili->YETKILI_TC    = $tc;
        $yetkili->YETKILI_SIFRE = $sifre;
        $res = $yetkili->Giris();

        if (!empty($res->ERROR)) {
            flash_set('errors', 'T.C. Kimlik No ya da Şifre doğrulanmadı.');
            reset_login_flow();
            header('Location: /Giris/panelentry.php');
            exit;
        }

        // Telefonu normalize et (05XXXXXXXXX)
        $tel = preg_replace('/\D+/', '', (string)($yetkili->YETKILI_TELEFON ?? ''));
        if ($tel !== '') {
            if (strpos($tel, '05') !== 0) {
                if (strpos($tel, '5') === 0) $tel = '0'.$tel;     // 5xxxxxxxxx → 05xxxxxxxxx
                if (strpos($tel, '05') !== 0) $tel = '05'.ltrim($tel,'0');
            }
            $tel = substr($tel, 0, 11);
        }
        if (!preg_match('/^05\d{9}$/', $tel)) {
            flash_set('errors', 'Hesabınıza tanımlı geçerli bir telefon bulunamadı.');
            reset_login_flow();
            header('Location: /Giris/panelentry.php');
            exit;
        }

        // OTP üret + gönder (3 dk)
        $otp = OtpHelper::generateSecureOtp(6);
        $_SESSION['LOGIN_OTP'] = [
            'code'  => (string)$otp,
            'exp'   => time() + 180,   // 3 dk
            'tries' => 0,
            'sent'  => time()
        ];
        $_SESSION['LOGIN_TEL']         = $tel;
        $_SESSION['LOGIN_STEP']        = 2;
        $_SESSION['LOGIN_USER_SERIAL'] = serialize($yetkili);

        $msg = "Giriş doğrulama kodunuz: {$otp} (3 dk geçerli)";
        if (!send_login_sms($tel, $msg)) {
            flash_set('errors', 'Doğrulama kodu gönderilemedi. Lütfen tekrar deneyin.');
            reset_login_flow();
            header('Location: /Giris/panelentry.php');
            exit;
        }

        flash_set('info_ok', 'Doğrulama kodu SMS ile gönderildi. 3 dakika içinde giriniz.');
        header('Location: /Giris/panelentry.php');
        exit;

    } catch (Throwable $e) {
        flash_set('errors', 'Beklenmeyen bir hata oluştu.');
        reset_login_flow();
        header('Location: /Giris/panelentry.php');
        exit;
    }
}

/* 2) OTP DOĞRULAMA */
if (isset($_POST['GIRIS_OTP'])) {

    if (empty($_SESSION['LOGIN_OTP']) || empty($_SESSION['LOGIN_TEL']) || empty($_SESSION['LOGIN_USER_SERIAL'])) {
        flash_set('errors', 'Oturum süresi doldu veya eksik akış.');
        reset_login_flow();
        header('Location: /Giris/panelentry.php');
        exit;
    }

    // 3 yanlış sonrası 10 dk kilit
    $lockUntil = (int)($_SESSION['LOGIN_LOCK_UNTIL'] ?? 0);
    if ($lockUntil > time()) {
        $kalan = $lockUntil - time();
        flash_set('errors', "Çok fazla hatalı deneme. Lütfen ".floor($kalan/60)." dk ".($kalan%60)." sn sonra tekrar deneyin.");
        header('Location: /Giris/panelentry.php');
        exit;
    }

    $otp   = preg_replace('/\D+/', '', (string)$_POST['GIRIS_OTP']);
    $pack  = $_SESSION['LOGIN_OTP'];
    $exp   = (int)($pack['exp'] ?? 0);
    $tries = (int)($pack['tries'] ?? 0);

    // Süresi dolmuşsa OTP ekranında kal, resend iste
    if ($otp === '' || time() > $exp) {
        $_SESSION['LOGIN_STEP'] = 2;
        flash_set('errors', 'Kodun süresi dolmuş. Lütfen yeniden kod isteyin.');
        header('Location: /Giris/panelentry.php');
        exit;
    }

    // Yanlış kod: sayfada kal, deneme say; 3’te kilitle → login’e dön
    if ((string)$otp !== (string)$pack['code']) {
        $_SESSION['LOGIN_OTP']['tries'] = $tries + 1;
        $kalan = 3 - $_SESSION['LOGIN_OTP']['tries'];

        if ($_SESSION['LOGIN_OTP']['tries'] >= 3) {
            $_SESSION['LOGIN_LOCK_UNTIL'] = time() + 600; // 10 dk kilit
            flash_set('errors', 'Çok fazla hatalı deneme. 10 dakika sonra tekrar deneyin.');
            reset_login_flow();
            header('Location: /Giris/panelentry.php');
            exit;
        }

        $_SESSION['LOGIN_STEP'] = 2; // OTP ekranında kal
        flash_set('errors', 'Doğrulama kodunu yanlış girdiniz. Kalan deneme: ' . $kalan);
        header('Location: /Giris/panelentry.php');
        exit;
    }

    // OTP doğru → kalıcı login
    $_SESSION['YETKILI'] = $_SESSION['LOGIN_USER_SERIAL']; // serialize(YETKILILER)
    session_regenerate_id(true);

    // Temizlik
    reset_login_flow();

    // Başarılı ekran ve yönlendirme
    $redirectUrl = '/upanel.php';
    $delayMs     = 100;
    session_write_close();
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Giriş Başarılı</title>
      <link rel="stylesheet" href="/css/adm/css/bootstrap.min.css">
      <style>
        html,body{height:100%}
        .overlay{position:fixed;inset:0;background:rgba(255,255,255,.95);display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}
        .spinner-large{width:72px;height:72px;border:8px solid rgba(0,0,0,.1);border-top-color:rgba(0,0,0,.55);border-radius:50%;margin:0 auto 16px;animation:spin .9s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        .success-text{font-size:1.1rem;color:#155724}
        .muted{color:#6c757d;font-size:.95rem}
      </style>
    </head>
    <body>
      <div class="overlay"><div>
        <div class="spinner-large"></div>
        <div class="success-text">✅ Başarıyla giriş yaptınız. Panele yönlendiriliyorsunuz…</div>
        <div class="muted" style="margin-top:6px;">
          Otomatik yönlenmezse <a href="<?= htmlspecialchars($redirectUrl,ENT_QUOTES,'UTF-8') ?>">buraya tıklayın</a>.
        </div>
      </div></div>
      <script>
        setTimeout(function(){location.href=<?= json_encode($redirectUrl) ?>;}, <?= (int)$delayMs ?>);
      </script>
    </body>
    </html>
    <?php
    exit;
}

/* 3) OTP YENİDEN GÖNDER */
if (isset($_POST['LOGIN_RESEND'])) {
    if (empty($_SESSION['LOGIN_TEL'])) {
        flash_set('errors', 'Oturum süresi doldu.');
        reset_login_flow();
        header('Location: /Giris/panelentry.php');
        exit;
    }

    // kilitliyse gönderme
    $lockUntil = (int)($_SESSION['LOGIN_LOCK_UNTIL'] ?? 0);
    if ($lockUntil > time()) {
        $kalan = $lockUntil - time();
        flash_set('errors', "Çok fazla hatalı deneme. Lütfen ".floor($kalan/60)." dk ".($kalan%60)." sn sonra tekrar deneyin.");
        header('Location: /Giris/panelentry.php');
        exit;
    }

    $tel   = $_SESSION['LOGIN_TEL'];
    $otp   = random_int(100000, 999999);
    $tries = (int)($_SESSION['LOGIN_OTP']['tries'] ?? 0); // denemeyi sıfırlamıyoruz

    $_SESSION['LOGIN_OTP'] = [
        'code'  => (string)$otp,
        'exp'   => time() + 180,
        'tries' => $tries,
        'sent'  => time()
    ];
    $_SESSION['LOGIN_STEP'] = 2;

    $msg = "Giriş doğrulama kodunuz: {$otp} (3 dk geçerli)";
    if (send_login_sms($tel, $msg)) {
        flash_set('info_ok', 'Yeni doğrulama kodu gönderildi. 3 dakika içinde giriniz.');
    } else {
        flash_set('errors', 'Kod gönderilemedi. Lütfen daha sonra tekrar deneyin.');
    }

    header('Location: /Giris/panelentry.php');
    exit;
}

/* Beklenmeyen durum: formu yeniden çiz */
header('Location: /Giris/panelentry.php');
exit;
