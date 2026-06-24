<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>İlk Kurulum | RentecarWeb</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-page">
  <div class="auth-shell">
    <div class="auth-card shadow-sm auth-card-wide">
      <div class="auth-brand">RentecarWeb</div>
      <h1>İlk Kurulum</h1>
      <p class="auth-subtitle">İlk firma ve yönetici kullanıcıyı oluşturalım.</p>
      <?php if (!empty($_GET['error'])): ?>
      <div class="alert alert-danger"><?= h($_GET['error']) ?></div>
      <?php endif; ?>
      <form action="actions/setup_admin.php" method="post" class="auth-form">
        <?= auth_csrf_input() ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Firma Adı</label>
            <input name="company_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Yönetici Ad Soyad</label>
            <input name="full_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Kullanıcı Adı</label>
            <input name="username" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Şifre</label>
            <input name="password" type="password" class="form-control" minlength="<?= h((string) auth_password_min_length()) ?>" required>
            <div class="form-text"><?= h(auth_password_policy_description()) ?></div>
          </div>
        </div>
        <button class="btn btn-dark w-100 mt-4" type="submit">Kurulumu Tamamla</button>
      </form>
    </div>
  </div>
</body>
</html>
