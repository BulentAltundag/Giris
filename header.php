
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="author" content="Loglama.Net">
  <link rel="shortcut icon" href="/favicon.png">

  <!-- Mutlak yollar (root’tan) => /Giris/ altından da sorunsuz -->
  <link rel="stylesheet" href="/css/adm/css/bootstrap.min.css">
  <link rel="stylesheet" href="/css/adm/css/style.css">
  <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css">

  <style>
    .muted{color:#6c757d;font-size:.95rem}
    .btn-block{width:100%}
  </style>
</head>
<body>
  <div class="site-wrap d-md-flex align-items-stretch">
    <!-- Arka plan görseli: kökten mutlak path -->
    <div class="bg-img" style="background-image: url('/images/img-bg-5.jpg')"></div>
    <div class="form-wrap">
      <div class="form-inner">
        <center>
          <img height="60" src="https://loglama.net/wp-content/uploads/2023/02/loglamanet-logo.png" alt="Loglama.Net">
          <?php if (!empty($pageCaption)): ?>
            <p class="captionc mb-4"><?= htmlspecialchars($pageCaption, ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
        </center>
