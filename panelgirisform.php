<?php 
require_once __DIR__ . '/../ayar.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* Step durumu (panelentry.php içinde set ediliyor) */
$loginStep = (int)($_SESSION['LOGIN_STEP'] ?? 1);

/* Sayaç için kalan süre (sn) */
$otpRemaining = 0;
if (!empty($_SESSION['LOGIN_OTP']['exp'])) {
    $otpRemaining = max(0, (int)$_SESSION['LOGIN_OTP']['exp'] - time());
}
// ⚠️ header/footer için erişim izni
define('GIRIS_VIEW', true);           
$pageTitle   = 'Yönetim Paneli Giriş';

// Ortak header
require __DIR__ . '/header.php';
?>
  <center>
        <p class="captionc mb-4">
       <?= ($loginStep===2 ? 'SMS Doğrulama' : 'Yönetim Paneli Giriş Sayfası'); ?>
         </p>
     </center>
       <?php
  $err = flash_get('errors');
  $info = flash_get('info_ok');
   ?>

  <?php if ($err): ?>
  <div class="alert alert-danger" role="alert">
    <?php if (is_array($err)) { echo implode('<br>', array_map('htmlspecialchars', $err)); }
          else { echo htmlspecialchars($err); } ?>
  </div>
  <?php endif; ?>

  <?php if ($info): ?>
  <div class="alert alert-success" role="alert">
    <?= htmlspecialchars(is_array($info) ? implode(' ', $info) : $info) ?>
  </div>
  <?php endif; ?>

  <?php if ($loginStep !== 2): ?>
  <!-- ==========================
       STEP 1: T.C. Kimlik + Şifre
       ========================== -->
  <form action="panelentry.php" method="post" class="pt-3" autocomplete="off" novalidate>
      <!-- CSRF Token -->
      <input type="hidden" name="csrf_token" value="<?= ex(csrf_token()) ?>">

      <div class="form-floating mb-3">
          <input type="text"
                 class="form-control js-tckn"
                 id="tc"
                 name="YETKILI_TC"
                 required
                 inputmode="numeric"
                 maxlength="11"
                 autocomplete="off"
                 placeholder="T.C Kimlik No.">
          <label for="tc">T.C. Kimlik No</label>
          <div class="invalid-feedback">T.C. Kimlik 11 haneli ve sadece rakam olmalıdır.</div>
      </div>
    
      <div class="form-floating mb-3">
          <span class="password-show-toggle js-password-show-toggle"><span class="uil"></span></span>
          <input type="password"
                 class="form-control"
                 id="password"
                 name="YETKILI_SIFRE"
                 required
                 autocomplete="current-password"
                 placeholder="Şifre">
          <label for="password">Şifre</label>
          <div class="invalid-feedback">Lütfen şifrenizi giriniz.</div>
      </div>

      <div class="d-flex justify-content-between mb-3">
          <div></div>
          <div><a href="forgotentry.php">Şifrenizi mi unuttunuz?</a></div>
      </div>

      <div class="d-grid mb-4">
          <button type="submit" class="btn btn-primary">Giriş Yap</button>
      </div>

      <div class="mb-4">Henüz hesabınız yoksa > <a href="usradmreg.php">Kayıt Olun</a></div>
      <div class="mb-4">Bayi kayıt formu  > <a href="dealership.php">Bayi Olun</a></div>
  </form>

  <?php else: ?>
  <!-- ====================
       STEP 2: OTP Doğrulama
       ==================== -->
  <form action="panelentry.php" method="post" autocomplete="one-time-code" class="pt-3">
      <!-- CSRF Token -->
      <input type="hidden" name="csrf_token" value="<?= ex(csrf_token()) ?>">

      <!-- OTP kutuları -->
      <div class="mb-2 text-center"><label>Kodunuzu giriniz (6 hane)</label></div>
      <div class="otp-boxes">
          <?php for($i=0;$i<6;$i++): ?>
            <input inputmode="numeric" pattern="\d*" maxlength="1" class="form-control otp">
          <?php endfor; ?>
      </div>
      <input type="hidden" name="GIRIS_OTP" id="otpHidden">

      <div class="d-grid mb-3">
          <button type="submit" class="btn btn-primary">Doğrula</button>
      </div>
  </form>

  <p id="timer" class="mt-2 text-center text-muted" aria-live="polite"></p>

  <form action="panelentry.php" method="post" class="mt-2 d-grid">
      <!-- CSRF Token -->
      <input type="hidden" name="csrf_token" value="<?= ex(csrf_token()) ?>">
      <button name="LOGIN_RESEND" value="1" id="resendBtn" class="btn btn-warning" disabled aria-disabled="true">
          Kodu Yeniden Gönder
      </button>
  </form>

  <div class="text-muted small mt-3 text-center">
      Kod süresi <strong>3 dakika</strong>dır. Süre dolduğunda “Kodu Yeniden Gönder” butonu aktif olur.
  </div>
  <?php endif; ?>

<script>
(function () {
  // --- TCKN: sadece kullanıcı etkileşiminden sonra uyar ---
  document.querySelectorAll('.js-tckn').forEach(function (el) {
    let touched = false;

    function validate() {
      // sadece rakam ve 11 haneye sınırla
      el.value = (el.value || '').replace(/\D+/g, '').slice(0, 11);

      // boşsa uyarı göstermeyelim
      if (!touched || el.value.length === 0) {
        el.setCustomValidity('');
        el.classList.remove('is-invalid', 'is-valid');
        return;
      }

      // doluysa kontrol et
      if (el.value.length === 11) {
        el.setCustomValidity('');
        el.classList.remove('is-invalid');
        el.classList.add('is-valid');
      } else {
        el.setCustomValidity('T.C. Kimlik 11 haneli ve sadece rakam olmalıdır.');
        el.classList.remove('is-valid');
        el.classList.add('is-invalid');
      }
    }

    el.addEventListener('input', function () { touched = true; validate(); });
    el.addEventListener('blur', function () { touched = true; validate(); });

    // İlk yüklemede uyarı gösterme (touched=false)
    validate();
  });

  // STEP 2: OTP kutuları → hidden input (GIRIS_OTP)
  const boxes = Array.prototype.slice.call(document.querySelectorAll('.otp-boxes .otp'));
  const hidden = document.getElementById('otpHidden');
  if (boxes.length && hidden){
    boxes.forEach(function(b,i){
      b.addEventListener('input', function(e){
        e.target.value = e.target.value.replace(/\D/g,'').slice(0,1);
        if(e.target.value && i<boxes.length-1) boxes[i+1].focus();
        hidden.value = boxes.map(function(x){return x.value||''}).join('');
      });
      b.addEventListener('keydown', function(e){
        if(e.key==='Backspace' && !b.value && i>0) boxes[i-1].focus();
      });
      b.addEventListener('paste', function(e){
        e.preventDefault();
        var t=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
        t.split('').forEach(function(ch,idx){ if (boxes[idx]) boxes[idx].value=ch; });
        hidden.value = boxes.map(function(x){return x.value||''}).join('');
        var idx = Math.min(t.length, boxes.length)-1; if (idx>=0) boxes[idx].focus();
      });
    });
    if (boxes[0]) boxes[0].focus();
  }

  // Sayaç (3 dk) — PHP'den kalan sn geliyor
  var remaining = <?= (int)$otpRemaining ?>; // saniye
  var timerEl = document.getElementById('timer');
  var resendBtn = document.getElementById('resendBtn');
  function tick(){
    if (typeof remaining !== 'number') return;
    if (remaining <= 0){
      if (timerEl) timerEl.textContent = "Süre doldu. Yeni kod talep edebilirsiniz.";
      if (resendBtn){ resendBtn.disabled=false; resendBtn.removeAttribute('aria-disabled'); }
      clearInterval(iv); return;
    }
    var m = Math.floor(remaining/60), s = remaining%60;
    if (timerEl) timerEl.textContent = "Kalan süre: " + m + ":" + (s<10?"0":"") + s;
    remaining--;
  }
  if (timerEl) { tick(); var iv=setInterval(tick,1000); }
})();
</script>

<?php
// Ortak footer
require __DIR__ . '/footer.php';
