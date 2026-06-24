<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$error = $_GET['error'] ?? '';
$retryAfter = max(0, (int) ($_GET['retry'] ?? 0));
$retryMinutes = (int) ceil($retryAfter / 60);
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rent A Car | Giriş</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css?v=20260616-login-saas-03" rel="stylesheet">
</head>
<body class="auth-fast-page">
  <main class="auth-fast-shell">
    <section class="auth-fast-card">
      <div class="auth-fast-brand">
        <div class="auth-fast-logo" aria-hidden="true">
          <svg viewBox="0 0 64 64" role="img">
            <path fill="currentColor" d="M20 18a6 6 0 0 1 6-6h12a6 6 0 0 1 6 6v4h4a6 6 0 0 1 6 6v10a6 6 0 0 1-6 6h-2.5a8.5 8.5 0 0 1-15 0h-9a8.5 8.5 0 0 1-15 0H10a6 6 0 0 1-6-6V28a6 6 0 0 1 6-6h4v-4a6 6 0 0 1 6-6Zm0 8h24v-8H20v8Zm-3.5 18a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm31 0a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9ZM10 28v10h4.2a8.5 8.5 0 0 1 4.8-2.78V32h16v3.22A8.5 8.5 0 0 1 39.8 38H54V28H10Z"/>
          </svg>
        </div>

        <div class="auth-fast-brand-copy">
          <h1>Rent A Car</h1>
          <p>Araç Kiralama Yönetim Sistemi</p>
        </div>
      </div>

      <div class="auth-fast-heading">
        <h2>Giriş Yap</h2>
      </div>

      <?php if ($error === 'invalid'): ?>
      <div class="alert alert-danger auth-fast-alert">Kullanıcı adı veya şifre yanlış.</div>
      <?php elseif ($error === 'locked'): ?>
      <div class="alert alert-warning auth-fast-alert">Çok fazla hatalı deneme oldu. Lütfen <?= h($retryMinutes > 0 ? $retryMinutes : 1) ?> dakika sonra tekrar deneyin.</div>
      <?php elseif ($error === 'session_expired'): ?>
      <div class="alert alert-warning auth-fast-alert">Güvenlik nedeniyle oturum kapatıldı. Lütfen yeniden giriş yap.</div>
      <?php endif; ?>

      <form action="actions/auth_login.php" method="post" class="auth-fast-form" data-login-form>
        <?= auth_csrf_input() ?>

        <div class="auth-fast-field">
          <label class="form-label" for="login-username">Kullanıcı Adı</label>
          <input
            id="login-username"
            name="username"
            class="form-control auth-fast-input"
            required
            autofocus
            autocomplete="username"
            placeholder="Kullanıcı adını gir"
            data-remember-username
          >
        </div>

        <div class="auth-fast-field">
          <label class="form-label" for="login-password">Şifre</label>
          <div class="auth-fast-password-wrap">
            <input
              id="login-password"
              name="password"
              type="password"
              class="form-control auth-fast-input auth-fast-input-password"
              required
              autocomplete="current-password"
              placeholder="Şifreni gir"
            >
            <button type="button" class="auth-fast-password-toggle" data-password-toggle aria-label="Şifreyi göster" title="Şifreyi göster">
              <span class="auth-password-toggle-show" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="img">
                  <path d="M12 5c5.5 0 9.57 3.53 11 7-1.43 3.47-5.5 7-11 7S2.43 15.47 1 12c1.43-3.47 5.5-7 11-7Zm0 2C7.97 7 4.82 9.4 3.2 12 4.82 14.6 7.97 17 12 17s7.18-2.4 8.8-5C19.18 9.4 16.03 7 12 7Zm0 2.5A2.5 2.5 0 1 1 9.5 12 2.5 2.5 0 0 1 12 9.5Zm0 2A.5.5 0 1 0 12.5 12a.5.5 0 0 0-.5-.5Z"/>
                </svg>
              </span>
              <span class="auth-password-toggle-hide d-none" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="img">
                  <path d="m3.28 2 18.72 18.72-1.41 1.41-3.18-3.18A13.3 13.3 0 0 1 12 20C6.5 20 2.43 16.47 1 13c.84-2.04 2.51-4.13 4.94-5.59L1.87 3.41 3.28 2ZM7.5 8.97C5.56 10.09 4.18 11.6 3.2 13 4.82 15.6 7.97 18 12 18c1.48 0 2.83-.32 4.02-.85l-2.37-2.37A4.5 4.5 0 0 1 9.22 10.35L7.5 8.97Zm4.08 4.08 1.87 1.87A2.48 2.48 0 0 0 14.5 13c0-.28-.05-.55-.13-.81l-2.79-2.79c-.26-.08-.53-.13-.81-.13a2.49 2.49 0 0 0-.19 4.98Zm.42-9.05c5.5 0 9.57 3.53 11 7-.65 1.57-1.83 3.14-3.52 4.5l-1.45-1.45c1.18-.88 2.11-1.93 2.77-3.05C19.18 8.4 16.03 6 12 6c-1.7 0-3.25.43-4.61 1.09L5.83 5.53A13.1 13.1 0 0 1 12 4Z"/>
                </svg>
              </span>
            </button>
          </div>
        </div>

        <div class="auth-fast-meta">
          <div class="form-check auth-fast-remember">
            <input class="form-check-input" type="checkbox" value="1" id="remember-me" data-remember-checkbox>
            <label class="form-check-label" for="remember-me">Beni Hatırla</label>
          </div>
          <a href="forgot_password.php" class="auth-fast-forgot">Şifremi Unuttum</a>
        </div>

        <button class="btn auth-fast-submit w-100" type="submit">Giriş Yap</button>
      </form>
    </section>
  </main>

  <script>
    (function () {
      var passwordInput = document.getElementById('login-password');
      var toggleButton = document.querySelector('[data-password-toggle]');
      var showLabel = toggleButton ? toggleButton.querySelector('.auth-password-toggle-show') : null;
      var hideLabel = toggleButton ? toggleButton.querySelector('.auth-password-toggle-hide') : null;
      var rememberCheckbox = document.querySelector('[data-remember-checkbox]');
      var usernameInput = document.querySelector('[data-remember-username]');
      var storageKey = 'rentecar_login_username';

      if (toggleButton && passwordInput) {
        toggleButton.addEventListener('click', function () {
          var isPassword = passwordInput.getAttribute('type') === 'password';
          passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
          toggleButton.setAttribute('aria-label', isPassword ? 'Şifreyi gizle' : 'Şifreyi göster');
          if (showLabel) { showLabel.classList.toggle('d-none', isPassword); }
          if (hideLabel) { hideLabel.classList.toggle('d-none', !isPassword); }
        });
      }

      try {
        var savedUsername = window.localStorage.getItem(storageKey);
        if (savedUsername && usernameInput && rememberCheckbox) {
          usernameInput.value = savedUsername;
          rememberCheckbox.checked = true;
        }
      } catch (error) {}

      document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('[data-login-form]') || !usernameInput || !rememberCheckbox) {
          return;
        }

        try {
          if (rememberCheckbox.checked && usernameInput.value.trim() !== '') {
            window.localStorage.setItem(storageKey, usernameInput.value.trim());
          } else {
            window.localStorage.removeItem(storageKey);
          }
        } catch (error) {}
      });
    }());
  </script>
</body>
</html>
