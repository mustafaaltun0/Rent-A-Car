<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rent A Car | Şifre Desteği</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css?v=20260616-login-premium-02" rel="stylesheet">
</head>
<body class="auth-page auth-premium-page">
  <main class="auth-shell auth-shell-premium">
    <section class="auth-showcase auth-showcase-premium">
      <div class="auth-logo-stage">
        <div class="auth-logo-orbit auth-logo-orbit-one"></div>
        <div class="auth-logo-orbit auth-logo-orbit-two"></div>
        <div class="auth-logo-mark" aria-hidden="true">
          <svg viewBox="0 0 64 64" role="img">
            <defs>
              <linearGradient id="supportLogoGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#8dc2ff"/>
                <stop offset="100%" stop-color="#ffffff"/>
              </linearGradient>
            </defs>
            <path fill="url(#supportLogoGradient)" d="M32 8c11.05 0 20 8.95 20 20 0 10.1-7.49 18.46-17.22 19.82L28 56v-8.25C18.9 45.96 12 37.76 12 28 12 16.95 20.95 8 32 8Zm0 10c-4.97 0-9 4.03-9 9h6a3 3 0 1 1 3 3c-1.66 0-3 1.34-3 3v4h6v-2.13A8.99 8.99 0 0 0 32 18Zm-3 24v6h6v-6h-6Z"/>
          </svg>
        </div>
      </div>

      <div class="auth-showcase-copy">
        <h1>Şifre Desteği</h1>
        <p class="auth-showcase-subtitle">Rent A Car</p>
        <p class="auth-showcase-description">Şifre sıfırlama işlemi için sistem yöneticiniz veya yazılım yöneticiniz ile iletişime geçin.</p>
      </div>
    </section>

    <section class="auth-panel auth-panel-premium">
      <div class="auth-card auth-card-premium shadow-sm">
        <div class="auth-card-eyebrow">Yardım</div>
        <div class="auth-heading auth-heading-premium">
          <h2>Şifremi Unuttum</h2>
          <p>Hesap güvenliği nedeniyle şifre sıfırlama süreci yönetici onayı ile ilerler.</p>
        </div>

        <div class="alert auth-alert" style="background: rgba(59,130,246,.16); color:#eff6ff; border:0;">
          Kullanıcı hesabın için yeni şifre talebini firma yöneticine ilet.
        </div>

        <a href="login.php" class="btn auth-submit auth-submit-premium w-100">Giriş Ekranına Dön</a>

        <div class="auth-footer-note auth-footer-note-premium">
          <span>Şifre işlemlerinde kullanıcı güvenliği ve yetki kontrolü zorunludur.</span>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
