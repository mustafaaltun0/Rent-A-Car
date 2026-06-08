<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('company.manage');

$company = auth_current_company($pdo);
if (!$company) {
    auth_abort('Firma bilgisi bulunamadi.', 404);
}

$status = trim((string) ($_GET['status'] ?? ''));
$pageTitle = 'Firma Ayarlari';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Firma Ayarlari</h2>
    </div>
  </div>

  <?php if ($status === 'saved'): ?>
  <div class="alert alert-success">Firma bilgileri guncellendi.</div>
  <?php elseif ($status === 'invalid'): ?>
  <div class="alert alert-danger">Zorunlu alanlari kontrol et.</div>
  <?php elseif ($status === 'email_invalid'): ?>
  <div class="alert alert-danger">E-posta adresi gecersiz.</div>
  <?php elseif ($status === 'website_invalid'): ?>
  <div class="alert alert-danger">Web sitesi adresi gecersiz.</div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header">Kurumsal Profil</div>
    <div class="card-body">
      <p class="text-muted mb-4">Bu bilgiler firma kimligi, resmi belgeler ve ileride eklenecek cikti/fatura altyapisi icin merkezi kaynaktir.</p>
      <form action="actions/company_settings_save.php" method="post" enctype="multipart/form-data" class="row g-3">
        <?= auth_csrf_input() ?>
        <?php if ($status === 'logo_invalid'): ?>
        <div class="col-12"><div class="alert alert-danger mb-0">Logo sadece JPG, PNG veya WEBP olabilir.</div></div>
        <?php elseif ($status === 'logo_too_large'): ?>
        <div class="col-12"><div class="alert alert-danger mb-0">Logo dosyasi en fazla 2 MB olabilir.</div></div>
        <?php elseif ($status === 'logo_upload_failed'): ?>
        <div class="col-12"><div class="alert alert-danger mb-0">Logo yuklenirken bir hata olustu.</div></div>
        <?php endif; ?>
        <div class="col-12">
          <div class="company-logo-card">
            <div class="company-logo-preview">
              <?php if (!empty($company['logo_path'])): ?>
              <img src="company_logo.php?v=<?= h(rawurlencode((string) ($company['logo_path'] ?? 'logo'))) ?>" alt="Firma logosu" width="120" height="120" style="width:120px; height:120px; max-width:120px; max-height:120px; object-fit:contain; display:block;">
              <?php else: ?>
              <div class="company-logo-placeholder"><?= h(mb_substr((string) ($company['name'] ?? 'F'), 0, 1, 'UTF-8')) ?></div>
              <?php endif; ?>
            </div>
            <div class="company-logo-form">
              <label class="form-label">Firma Logosu</label>
              <input name="logo_file" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
              <div class="form-text">Kurumsal gorunum icin yatay logo onerilir. Maksimum 2 MB.</div>
              <?php if (!empty($company['logo_path'])): ?>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" value="1" id="removeLogo" name="remove_logo">
                <label class="form-check-label" for="removeLogo">Mevcut logoyu kaldir</label>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Gorunen Firma Adi</label>
          <input name="company_name" class="form-control" value="<?= h($company['name'] ?? '') ?>" maxlength="150" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Resmi Unvan</label>
          <input name="legal_name" class="form-control" value="<?= h($company['legal_name'] ?? '') ?>" maxlength="180">
        </div>
        <div class="col-md-6">
          <label class="form-label">Telefon</label>
          <input name="phone" class="form-control" value="<?= h($company['phone'] ?? '') ?>" maxlength="30">
        </div>
        <div class="col-md-6">
          <label class="form-label">E-posta</label>
          <input name="email" type="email" class="form-control" value="<?= h($company['email'] ?? '') ?>" maxlength="150">
        </div>
        <div class="col-md-6">
          <label class="form-label">Web Sitesi</label>
          <input name="website" class="form-control" value="<?= h($company['website'] ?? '') ?>" maxlength="180" placeholder="ornek.com">
        </div>
        <div class="col-md-6">
          <label class="form-label">Sistem Slug</label>
          <input class="form-control" value="<?= h($company['slug'] ?? '') ?>" disabled>
        </div>
        <div class="col-md-4">
          <label class="form-label">Vergi Dairesi</label>
          <input name="tax_office" class="form-control" value="<?= h($company['tax_office'] ?? '') ?>" maxlength="120">
        </div>
        <div class="col-md-4">
          <label class="form-label">Vergi Numarasi</label>
          <input name="tax_number" class="form-control" value="<?= h($company['tax_number'] ?? '') ?>" maxlength="30">
        </div>
        <div class="col-md-4">
          <label class="form-label">MERSIS Numarasi</label>
          <input name="mersis_number" class="form-control" value="<?= h($company['mersis_number'] ?? '') ?>" maxlength="30">
        </div>
        <div class="col-12">
          <label class="form-label">Adres</label>
          <textarea name="address" class="form-control" rows="3"><?= h($company['address'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label">Ilce</label>
          <input name="district" class="form-control" value="<?= h($company['district'] ?? '') ?>" maxlength="120">
        </div>
        <div class="col-md-4">
          <label class="form-label">Sehir</label>
          <input name="city" class="form-control" value="<?= h($company['city'] ?? '') ?>" maxlength="120">
        </div>
        <div class="col-md-4">
          <label class="form-label">Ulke</label>
          <input name="country" class="form-control" value="<?= h($company['country'] ?? 'Turkiye') ?>" maxlength="120">
        </div>
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2">
          <small class="text-muted">Son guncelleme: <?= !empty($company['updated_at']) ? h(dt($company['updated_at'])) : '-' ?></small>
          <button class="btn btn-dark" type="submit">Firma Bilgilerini Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
