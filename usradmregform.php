<?php
require_once __DIR__ . '/../ayar.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) session_start();

/* POST geldiyse backend'i çalıştır ve çık */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require __DIR__ . '/usradmreg.php';
  exit;
}

/* --- GET: Akış güvenliği ---
   Eğer step=2/3 gösterebilmek için gereken session yoksa
   ya da ortada akış yoksa, state'i temizle ve step=1'e dön. */
$needReset = false;
if (!isset($_SESSION['flow'])) {
  $needReset = true;
} else {
  $ctx  = $_SESSION['flow']['ctx']  ?? 'user';
  $step = (int)($_SESSION['flow']['step'] ?? 1);
  if ($ctx !== 'user') $needReset = true;
  if ($step === 2) {
    if (empty($_SESSION['pending_user']) || empty($_SESSION['user_tel']) || empty($_SESSION['user_otp'])) {
      $needReset = true;
    }
  } elseif ($step === 3) {
    if (empty($_SESSION['pending_user']) || empty($_SESSION['user_tel'])) {
      $needReset = true;
    }
  }
}
if ($needReset) {
  unset($_SESSION['pending_user'], $_SESSION['user_tel'], $_SESSION['user_otp'], $_SESSION['otp_deadline']);
  unset($_SESSION['flow']);
}

/* Ekran değişkenleri */
$flow = $_SESSION['flow'] ?? ['ctx'=>'user','step'=>1];
$step = (int)($flow['step'] ?? 1);

/* Flash mesajları */
$errors = [];
if ($tmp = flash_get('errors')) {
  $tmp = is_array($tmp) ? $tmp : [$tmp];
  foreach ($tmp as $t) { $t = trim($t); if ($t !== '') $errors[] = $t; }
}
$otpSent = flash_get('otp_sent_ok') ? true : false;

define('GIRIS_VIEW', true);           // ⚠️ header/footer için erişim izni
$pageTitle   = 'Yönetim Paneli Giriş';
$pageCaption = 'Yönetim Paneli';

// Ortak header
require __DIR__ . '/header.php';
?>
 <center>
   <p class="captionc mb-4"><?= $step===2?'Doğrulama Kodu':($step===3?'Şifre Belirle':'Kullanıcı Kayıt Formu') ?></p>
  </center>
    <div class="container mt-4">
      <?php if($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?>
          <li><?= htmlspecialchars($e,ENT_QUOTES,'UTF-8') ?></li>
        <?php endforeach; ?></ul></div>
      <?php endif; ?>

      <?php if ($step===1): ?>
        <!-- ADIM 1: Kullanıcı Kayıt Formu (backend: /Giris/usradmreg.php) -->
        <!-- ADIM 1: Kayıt Formu -->
        <form method="post" action="" autocomplete="off" id="regForm">
          <?= csrf_field(); ?>
          <input type="hidden" name="step1" value="1">

          <div class="mb-3">
            <label for="firmaInput">Firma Adı</label>
            <input type="text" id="firmaInput" name="YETKILI_FIRMA" class="form-control" required>
            <div class="invalid-feedback">Firma adı zorunludur.</div>
          </div>

          <div class="mb-3">
            <label for="verginoInput">Vergi No</label>
            <input type="text" id="verginoInput" name="YETKILI_VERGI_NO" class="form-control" inputmode="numeric" required>
            <div class="invalid-feedback">Vergi no 10 hane sadece rakam olarak girilmelidir.</div>
          </div>

          <div class="mb-3">
            <label for="adsoyadInput">Ad Soyad</label>
            <input type="text" id="adsoyadInput" name="YETKILI_AD_SOYAD" class="form-control" required>
            <div class="invalid-feedback">Ad Soyad zorunludur.</div>
          </div>

          <div class="mb-3">
            <label for="tcInput">T.C Kimlik No</label>
            <input type="text" id="tcInput" name="YETKILI_TC" class="form-control" inputmode="numeric" required>
            <div class="invalid-feedback">T.C Kimlik No 11 hane ve sadece rakamlardan oluşmalıdır.</div>
          </div>

          <div class="mb-3">
            <label for="dogumInput">Doğum Yılı</label>
            <input type="text" id="dogumInput" name="YETKILI_DOGUM_TARIHI" class="form-control" inputmode="numeric" required>
            <div class="invalid-feedback">Doğum yılı zorunludur.</div>
          </div>

          <div class="mb-3">
            <label for="emailInput">Email</label>
            <input type="email" id="emailInput" name="YETKILI_EPOSTA" class="form-control" required>
            <div class="invalid-feedback">Lütfen geçerli bir e-posta girin.</div>
          </div>

          <div class="mb-3">
            <label for="telInput">Telefon</label>
            <input type="text" id="telInput" name="YETKILI_TELEFON" class="form-control" inputmode="tel" required>
            <div class="invalid-feedback">Telefon 0(5xx)xxx xx xx biçiminde olmalıdır.</div>
          </div>

          <button type="submit" class="btn btn-success btn-block">Devam Et</button>
        </form>
<script>
(function(){
  const form      = document.getElementById('regForm');
  const firmaEl   = document.getElementById('firmaInput');
  const vergiEl   = document.getElementById('verginoInput');
  const adsoyEl   = document.getElementById('adsoyadInput');
  const tcEl      = document.getElementById('tcInput');
  const dogumEl   = document.getElementById('dogumInput');
  const emailEl   = document.getElementById('emailInput');
  const telEl     = document.getElementById('telInput');

  // --- Yardımcılar (TR uyumlu) ---
  const TR = 'tr-TR';
  function trUpper(ch){ return ch.toLocaleUpperCase(TR); }
  function trLower(str){ return str.toLocaleLowerCase(TR); }

  // "Ahmet Buğra Demir" biçimi (tire, apostrof, birden çok boşluk)
  function trTitleCase(str){
    str = trLower(str).replace(/\s+/g,' ').trim();
    if (!str) return '';
    return str.split(' ').map(part=>{
      return part.split(/([\-’'`])/).map(seg=>{
        if (seg === '-' || seg === '’' || seg === "'" || seg === '`') return seg;
        return seg ? trUpper(seg[0]) + seg.slice(1) : seg;
      }).join('');
    }).join(' ');
  }

  // Firma: sadece ilk harf büyük
  function capitalizeFirst(str){
    str = trLower(str).trim();
    if (!str) return '';
    return trUpper(str[0]) + str.slice(1);
  }

  // Telefon maskeleme: "0(5xx)xxx xx xx"
  function formatTelMask(raw){
    // sadece rakam
    let d = String(raw || '').replace(/\D/g,'');
    // 05 ile başlat
    if (d.startsWith('5')) d = '0' + d;
    if (!d.startsWith('05')) d = '05' + d.replace(/^0+/, '').replace(/^5?/, '');
    // 11 haneye sınırla
    d = d.slice(0, 11);

    // parça parça güvenli şekilde yaz
    let out = '';
    if (d.length >= 1) out += d[0];                             // 0
    if (d.length >= 2) out += '(' + d.slice(1, Math.min(4,d.length)); // (5xx
    if (d.length >= 4) out += ')';                              // )
    if (d.length > 4)  out += d.slice(4, Math.min(7,d.length)); // xxx
    if (d.length > 7)  out += ' ' + d.slice(7, Math.min(9,d.length));  // xx
    if (d.length > 9)  out += ' ' + d.slice(9, Math.min(11,d.length)); // xx
    return out;
  }

  function setValidity(el, valid, msg){
    if (valid){
      el.setCustomValidity('');
      el.classList.remove('is-invalid');
    }else{
      el.setCustomValidity(msg || 'Geçersiz giriş.');
      el.classList.add('is-invalid');
    }
  }

  // --- Firma: ilk harf büyük (canlı) ---
  firmaEl.addEventListener('blur', ()=>{
    firmaEl.value = capitalizeFirst(firmaEl.value);
    setValidity(firmaEl, !!firmaEl.value.trim(), 'Firma adı zorunludur.');
  });
  firmaEl.addEventListener('input', ()=>{
    setValidity(firmaEl, !!firmaEl.value.trim(), 'Firma adı zorunludur.');
  });

  // --- Vergi No: sadece rakam, 10 hane ---
  vergiEl.addEventListener('input', ()=>{
    let v = vergiEl.value.replace(/\D/g,'').slice(0,10);
    vergiEl.value = v;
    setValidity(vergiEl, v.length===10, 'Vergi no 10 hane sadece rakam olarak girilmelidir.');
  });

  // --- Ad Soyad: baş harfler büyük; tümü büyükse düzelt ---
  adsoyEl.addEventListener('input', ()=>{
    setValidity(adsoyEl, !!adsoyEl.value.trim(), 'Ad Soyad zorunludur.');
  });
  adsoyEl.addEventListener('blur', ()=>{
    adsoyEl.value = trTitleCase(adsoyEl.value);
    setValidity(adsoyEl, !!adsoyEl.value.trim(), 'Ad Soyad zorunludur.');
  });

  // --- T.C Kimlik: sadece rakam, 11 hane ---
  tcEl.addEventListener('input', ()=>{
    let v = tcEl.value.replace(/\D/g,'').slice(0,11);
    tcEl.value = v;
    setValidity(tcEl, v.length===11, 'T.C Kimlik No 11 hane ve sadece rakamlardan oluşmalıdır.');
  });

  // --- Doğum Yılı: temizle (opsiyonel kapsam) ---
  dogumEl.addEventListener('input', ()=>{
    let v = dogumEl.value.replace(/\D/g,'').slice(0,4);
    dogumEl.value = v;
    setValidity(dogumEl, v.length===4, 'Doğum yılı 4 haneli olmalıdır.');
  });

  // --- Email: Bootstrap native mesaj kullansın ---
  emailEl.addEventListener('input', ()=>{
    if (emailEl.validity.typeMismatch || emailEl.value.trim()==='') {
      emailEl.classList.add('is-invalid');
    } else {
      emailEl.classList.remove('is-invalid');
    }
  });

  // --- Telefon: maske + doğrulama ---
  telEl.addEventListener('input', ()=>{
    const masked = formatTelMask(telEl.value);
    telEl.value = masked;
    const onlyDigits = masked.replace(/\D/g,'');
    const ok = /^05\d{9}$/.test(onlyDigits);
    setValidity(telEl, ok, 'Telefon 0(5xx)xxx xx xx biçiminde olmalıdır.');
  });
  telEl.addEventListener('blur', ()=>{
    const digits = telEl.value.replace(/\D/g,'').slice(0,11);
    telEl.value = formatTelMask(digits);
    const ok = /^05\d{9}$/.test(digits);
    setValidity(telEl, ok, 'Telefon 0(5xx)xxx xx xx biçiminde olmalıdır.');
  });

  // --- Submit: HTML5 doğrulama + bootstrap uyarıları ---
  form.addEventListener('submit', (e)=>{
    // son bir normalizasyon
    firmaEl.value  = capitalizeFirst(firmaEl.value);
    adsoyEl.value  = trTitleCase(adsoyEl.value);
    telEl.value    = formatTelMask(telEl.value.replace(/\D/g,'').slice(0,11));

    // alanları tekrar kontrol et
    if (vergiEl.value.replace(/\D/g,'').length!==10) setValidity(vergiEl,false,'Vergi no 10 hane sadece rakam olarak girilmelidir.');
    if (tcEl.value.replace(/\D/g,'').length!==11) setValidity(tcEl,false,'T.C Kimlik No 11 hane ve sadece rakamlardan oluşmalıdır.');
    if (!/^05\d{9}$/.test(telEl.value.replace(/\D/g,''))) setValidity(telEl,false,'Telefon 0(5xx)xxx xx xx biçiminde olmalıdır.');

    if (!form.checkValidity()){
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
    }
  });
})();
</script>


      <?php elseif ($step===2): ?>
        <?php if($otpSent): ?><div class="alert alert-success">✅ Doğrulama kodu SMS ile gönderildi. <b>3 dakika</b> içinde giriniz.</div><?php endif; ?>
        <!-- ADIM 2: OTP -->
        <form method="post" action="aktivasyon_dogrula.php" autocomplete="one-time-code" id="otpForm">
          <?= csrf_field(); ?>
          <div class="mb-2 text-center"><label>Kodu girin (6 hane)</label></div>
          <div class="otp-boxes">
            <?php for($i=0;$i<6;$i++): ?>
              <input inputmode="numeric" pattern="\d*" maxlength="1" class="form-control otp">
            <?php endfor; ?>
          </div>
          <input type="hidden" name="otp" id="otpHidden">
          <button class="btn btn-primary btn-block">Doğrula</button>
        </form>

        <p id="timer" class="mt-3 text-center text-muted" aria-live="polite"></p>
        <div class="d-grid">
          <form method="post" action="resend_code.php" class="mt-2">
            <?= csrf_field(); ?>
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

          // 3 dk (180 sn) sayaç
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
  <!-- ADIM 3: Şifre -->
  <form method="post" action="set_password.php" autocomplete="new-password" id="passFormUser">
    <?= csrf_field(); ?>
    <div class="mb-3">
      <label class="form-label">Şifre</label>
      <input type="password" name="sifre" id="pass1User" minlength="12" class="form-control" required
             placeholder="En az 12 karakter, 1 büyük harf, 1 rakam, 1 özel karakter">
      <div class="invalid-feedback">Şifre kriterlerini sağlayın.</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Şifre (Tekrar)</label>
      <div class="input-group">
        <input type="password" name="sifre2" id="pass2User" minlength="12" class="form-control" required placeholder="Şifreyi tekrar yazın">
        <button class="btn btn-outline-secondary" type="button" id="toggleUser">Göster</button>
      </div>
      <div class="invalid-feedback">Şifreler eşleşmiyor.</div>
    </div>

    <button class="btn btn-primary btn-block">Kaydı Tamamla</button>
  </form>

  <script>
  (function(){
    const f = document.getElementById('passFormUser');
    const p1 = document.getElementById('pass1User');
    const p2 = document.getElementById('pass2User');
    const tg = document.getElementById('toggleUser');

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

  <?php
// Ortak footer
require __DIR__ . '/footer.php';
