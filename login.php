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
  <title>Giris | RentecarWeb</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-page">
  <div class="auth-shell">
    <div class="auth-card shadow-sm">
      <div class="auth-brand">RentecarWeb</div>
      <h1>Giris Yap</h1>
      <p class="auth-subtitle">Firma paneline guvenli giris yap.</p>
      <?php if ($error === 'invalid'): ?>
      <div class="alert alert-danger">Kullanici adi veya sifre yanlis.</div>
      <?php elseif ($error === 'locked'): ?>
      <div class="alert alert-warning">Cok fazla hatali deneme oldu. Lutfen <?= h($retryMinutes > 0 ? $retryMinutes : 1) ?> dakika sonra tekrar deneyin.</div>
      <?php elseif ($error === 'session_expired'): ?>
      <div class="alert alert-warning">Guvenlik nedeniyle oturumun kapatildi. Lutfen yeniden giris yap.</div>
      <?php endif; ?>
      <form action="actions/auth_login.php" method="post" class="auth-form">
        <?= auth_csrf_input() ?>
        <div class="mb-3">
          <label class="form-label">Kullanici Adi</label>
          <input name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Sifre</label>
          <input name="password" type="password" class="form-control" required>
        </div>
        <button class="btn btn-dark w-100" type="submit">Giris Yap</button>
      </form>
    </div>
  </div>
</body>
</html>
