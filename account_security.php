<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$authUser = auth_current_user();
if (!$authUser) {
    auth_redirect('login.php');
}

$status = trim((string) ($_GET['status'] ?? ''));
$pageTitle = 'Hesap Guvenligi';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Hesap Guvenligi</h2>
    </div>
  </div>

  <?php if ($status === 'changed'): ?>
  <div class="alert alert-success">Sifren basariyla guncellendi.</div>
  <?php elseif ($status === 'invalid_current'): ?>
  <div class="alert alert-danger">Mevcut sifre dogru degil.</div>
  <?php elseif ($status === 'mismatch'): ?>
  <div class="alert alert-danger">Yeni sifre ile tekrar alani ayni degil.</div>
  <?php elseif ($status === 'weak_password'): ?>
  <div class="alert alert-danger"><?= h(auth_password_policy_description()) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header">Sifre Degistir</div>
    <div class="card-body">
      <div class="mb-4">
        <div><strong><?= h($authUser['full_name'] ?? $authUser['username']) ?></strong></div>
        <div class="text-muted"><?= h($authUser['username'] ?? '') ?></div>
      </div>

      <form action="actions/account_change_password.php" method="post" class="row g-3">
        <?= auth_csrf_input() ?>
        <div class="col-12">
          <label class="form-label">Mevcut Sifre</label>
          <input name="current_password" type="password" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Yeni Sifre</label>
          <input name="new_password" type="password" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Yeni Sifre Tekrar</label>
          <input name="new_password_confirm" type="password" class="form-control" required>
        </div>
        <div class="col-12">
          <div class="form-text"><?= h(auth_password_policy_description()) ?></div>
        </div>
        <div class="col-12">
          <button class="btn btn-dark" type="submit">Sifreyi Guncelle</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
