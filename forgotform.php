<?php
// Bu dosya forgotentry.php tarafından require edilir.
// Aşağıdaki değişkenler SESSION’dan hesaplanır:
$step    = (int)($_SESSION['FORGOT_STEP'] ?? 1);
$infoOk  = flash_get('info_ok') ? true : false;
$errors  = [];
if ($tmp = flash_get('errors')) {
  $tmp = is_array($tmp) ? $tmp : [$tmp];
  foreach ($tmp as $t) { $t = trim($t); if ($t !== '') $errors[] = $t; }
}

define('GIRIS_VIEW', true);           // ⚠️ header/footer için erişim izni
$pageTitle   = 'Yönetim Paneli Giriş';
$pageCaption = 'Yönetim Paneli';

// Ortak header
require __DIR__ . '/header.php';
?>
    <center>
      <p class="captionc mb-4">
        <?= $step===1 ? 'Şifremi Unuttum' : ($step===2 ? 'Doğrulama Kodu' : 'Yeni Şifre') ?>
      </p>
    </center>

    <div class="container mt-4">
      <?php if($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0">
          <?php foreach($errors as $e): ?><li><?= htmlspecialchars($e,ENT_QUOTES,'UTF-8') ?></li><?php endforeach; ?>
        </ul></div>
      <?php endif; ?>

      <?php if ($step===1): ?>
        <!-- ADIM 1: TC AL -->
        <form method="post" action="forgotentry.php" autocomplete="off" id="tcForm" class="needs-validation" novalidate>
          <?= csrf_field(); ?>
          <input type="hidden" name="action" value="start">
          <div class="mb-3">
            <label for="tcInput">T.C. Kimlik No</label>
            <input type="text" id="tcInput" name="tc" class="form-control" inputmode="numeric" required>
            <div class="invalid-feedback">T.C. Kimlik No 11 hane ve sadece rakamlardan oluşmalıdır.</div>
          </div>
          <button class="btn btn-primary btn-block">Devam Et</button>
        </form>
        <script>
        (function(){
          const tcEl = document.getElementById('tcInput');
          const form = document.getElementById('tcForm');
          tcEl.addEventListener('input', ()=>{
            let v = tcEl.value.replace(/\D/g,'').slice(0,11);
            tcEl.value = v;
            if (v.length===11) { tcEl.setCustomValidity(''); tcEl.classList.remove('is-invalid'); }
            else { tcEl.setCustomValidity('Lütfen 11 hane girin.'); tcEl.classList.add('is-invalid'); }
          });
          form.addEventListener('submit', (e)=>{
            if (tcEl.value.replace(/\D/g,'').length!==11) {
              e.preventDefault(); e.stopPropagation();
              tcEl.classList.add('is-invalid');
            }
          });
        })();
        </script>

      <?php elseif ($step===2): ?>
        <?php if($infoOk): ?>
          <div class="alert alert-success">✅ Doğrulama kodu SMS ile gönderildi. <b>3 dakika</b> içinde giriniz.</div>
        <?php endif; ?>

        <!-- ADIM 2: OTP -->
        <form method="post" action="forgotentry.php" autocomplete="one-time-code" id="otpForm">
          <?= csrf_field(); ?>
          <input type="hidden" name="action" value="verify">
          <div class="mb-2 text-center"><label>Kodu girin (6 hane)</label></div>
          <div class="otp-boxes" id="otpBoxes">
            <?php for($i=0;$i<6;$i++): ?>
              <input inputmode="numeric" pattern="\d*" maxlength="1" class="form-control otp">
            <?php endfor; ?>
          </div>
          <input type="hidden" name="otp" id="otpHidden">
          <button class="btn btn-primary btn-block">Doğrula</button>
        </form>

        <p id="timer" class="mt-3 text-center text-muted" aria-live="polite"></p>
        <div class="d-grid">
          <form method="post" action="forgotentry.php" class="mt-2">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="resend">
            <button id="resendBtn" class="btn btn-warning" disabled aria-disabled="true">Kodu Yeniden Gönder</button>
          </form>
        </div>

        <script>
        (function(){
          const boxes=[...document.querySelectorAll('.otp-boxes .otp')];
          const hidden=document.getElementById('otpHidden');
          boxes.forEach((box,i)=>{
            box.addEventListener('input',e=>{
              e.target.value=e.target.value.replace(/\D/g,'').slice(0,1);
              if(e.target.value && i<boxes.length-1) boxes[i+1].focus();
              hidden.value=boxes.map(b=>b.value||'').join('');
            });
            box.addEventListener('keydown',e=>{
              if(e.key==='Backspace' && !box.value && i>0) boxes[i-1].focus();
            });
            box.addEventListener('paste',e=>{
              e.preventDefault();
              const t=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
              t.split('').forEach((ch,idx)=>{ if (boxes[idx]) boxes[idx].value=ch; });
              hidden.value=boxes.map(b=>b.value||'').join('');
              const lastFilled = t.length ? Math.min(t.length, boxes.length)-1 : 0;
              boxes[lastFilled].focus();
            });
          });

          // 3 dk sayaç
          let countdown = 180;
          let timerEl = document.getElementById("timer");
          let btn = document.getElementById("resendBtn");
          let iv = setInterval(function() {
            let min = Math.floor(countdown / 60);
            let sec = countdown % 60;
            timerEl.textContent = "Kalan süre: " + min + ":" + (sec < 10 ? "0" : "") + sec;
            if (countdown <= 0) {
              clearInterval(iv);
              timerEl.textContent = "Süre doldu. Yeni kod talep edebilirsiniz.";
              btn.removeAttribute('disabled');
              btn.removeAttribute('aria-disabled');
            }
            countdown--;
          }, 1000);
        })();
        </script>

     <?php elseif ($step===3): ?>
  <!-- ADIM 3: YENİ ŞİFRE -->
  <form method="post" action="forgotentry.php" autocomplete="new-password" id="passFormForgot">
    <?= csrf_field(); ?>
    <input type="hidden" name="action" value="setpass">

    <div class="mb-3">
      <label for="passInputF" class="form-label">Yeni Şifre</label>
      <input type="password" id="passInputF" name="sifre" minlength="12" class="form-control" required
             placeholder="En az 12 karakter, 1 büyük harf, 1 rakam, 1 özel karakter">
      <div class="invalid-feedback">Şifre kriterlerini sağlayın.</div>
    </div>

    <div class="mb-3">
      <label for="passInputF2" class="form-label">Yeni Şifre (Tekrar)</label>
      <div class="input-group">
        <input type="password" id="passInputF2" name="sifre2" minlength="12" class="form-control" required placeholder="Şifreyi tekrar yazın">
        <button class="btn btn-outline-secondary" type="button" id="toggleForgot">Göster</button>
      </div>
      <div class="invalid-feedback">Şifreler eşleşmiyor.</div>
    </div>

    <button class="btn btn-primary btn-block">Şifreyi Güncelle</button>
  </form>

  <script>
  (function(){
    const f  = document.getElementById('passFormForgot');
    const p1 = document.getElementById('passInputF');
    const p2 = document.getElementById('passInputF2');
    const tg = document.getElementById('toggleForgot');

    function policyOK(v){
      return v.length>=12 && /[A-ZÇĞİÖŞÜ]/u.test(v) && /\d/.test(v) && /[^A-Za-z0-9ÇĞİÖŞÜçğıöşü]/u.test(v);
    }
    function check(){
      let ok1 = policyOK(p1.value);
      let ok2 = p1.value === p2.value && p2.value.length>0;
      if(!ok1){ p1.classList.add('is-invalid'); } else { p1.classList.remove('is-invalid'); }
      if(!ok2){ p2.classList.add('is-invalid'); } else { p2.classList.remove('is-invalid'); }
      return ok1 && ok2;
    }
    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
    f.addEventListener('submit', function(e){ if(!check()){ e.preventDefault(); e.stopPropagation(); } });

    tg.addEventListener('click', function(){
      p2.type = p2.type === 'password' ? 'text' : 'password';
      tg.textContent = p2.type === 'password' ? 'Göster' : 'Gizle';
    });
  })();
  </script>
<?php endif; ?>

    </div>

    <div class="mt-3 text-center">
      <a href="panelentry.php">Giriş sayfasına dön</a>
    </div>

   <?php
// Ortak footer
require __DIR__ . '/footer.php';
