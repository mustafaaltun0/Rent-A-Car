<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('customers.view');

if (!app_feature_customer_companies_enabled()) {
    auth_redirect('index.php');
}

ensureCustomerCompanySchema($pdo);
$companyId = auth_current_company_id();
$canManageCustomers = auth_can('customers.manage');
$entryStatus = $_GET['status'] ?? '';

$customerCompanies = getCustomerCompanies($pdo, $companyId);
$totalCustomerCount = count($customerCompanies);
$activeCustomerCount = 0;
foreach ($customerCompanies as $customerCompany) {
    if ((int) ($customerCompany['is_active'] ?? 0) === 1) {
        $activeCustomerCount++;
    }
}
$customerCompaniesPagination = paginate_collection($customerCompanies, 'customer_page', 'customer_per_page', 10, [10, 20, 50, 100]);
$customerCompanies = $customerCompaniesPagination['items'];

$pageTitle = 'Kurumsal Musteriler';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Kurumsal Musteriler</h2>
    </div>
    <?php if ($canManageCustomers): ?>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#customerCompanyModal" data-mode="create">Musteri Firma Ekle</button>
    <?php endif; ?>
  </div>

  <?php if ($entryStatus === 'saved'): ?>
  <div class="alert alert-success">Kurumsal musteri kaydi kaydedildi.</div>
  <?php elseif ($entryStatus === 'status_changed'): ?>
  <div class="alert alert-success">Kurumsal musteri durumu guncellendi.</div>
  <?php elseif ($entryStatus === 'email_invalid'): ?>
  <div class="alert alert-danger">E-posta formati gecersiz.</div>
  <?php elseif ($entryStatus === 'duplicate'): ?>
  <div class="alert alert-danger">Ayni isimde bir kurumsal musteri zaten kayitli.</div>
  <?php elseif ($entryStatus === 'inactive_blocked'): ?>
  <div class="alert alert-danger">Aktif kiralamasi olan kurumsal musteri pasife alinamaz.</div>
  <?php elseif ($entryStatus === 'invalid'): ?>
  <div class="alert alert-danger">Bilgileri kontrol edip tekrar dene.</div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1">Toplam Musteri</div>
          <div class="fs-3 fw-semibold"><?= h((string) $totalCustomerCount) ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1">Aktif</div>
          <div class="fs-3 fw-semibold"><?= h((string) $activeCustomerCount) ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1">Pasif</div>
          <div class="fs-3 fw-semibold"><?= h((string) max(0, $totalCustomerCount - $activeCustomerCount)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">Firma Listesi</div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped align-middle">
        <tr>
          <th>Firma</th>
          <th>Yetkili</th>
          <th>Iletisim</th>
          <th>Vergi</th>
          <th>Not</th>
          <th>Durum</th>
          <th>Islem</th>
        </tr>
        <?php if (empty($customerCompanies)): ?>
        <tr><td colspan="7" class="text-center text-muted">Henuz kurumsal musteri kaydi yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($customerCompanies as $customerCompany): ?>
        <?php $customerCompanyId = (int) ($customerCompany['id'] ?? 0); ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($customerCompany['company_name'] ?? '') ?></div>
            <?php if (!empty($customerCompany['address'])): ?>
              <small class="text-muted"><?= nl2br(h($customerCompany['address'])) ?></small>
            <?php endif; ?>
          </td>
          <td><?= h($customerCompany['contact_name'] ?? '-') ?></td>
          <td>
            <div><?= h($customerCompany['phone'] ?? '-') ?></div>
            <div><?= h($customerCompany['email'] ?? '-') ?></div>
          </td>
          <td>
            <div><?= h($customerCompany['tax_office'] ?? '-') ?></div>
            <div><?= h($customerCompany['tax_number'] ?? '-') ?></div>
          </td>
          <td><?= h($customerCompany['notes'] ?? '-') ?></td>
          <td><?= (int) ($customerCompany['is_active'] ?? 0) === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>' ?></td>
          <td class="table-actions-cell">
            <?php if ($canManageCustomers): ?>
            <div class="action-group">
              <button
                class="action-btn action-warning"
                type="button"
                title="Duzenle"
                aria-label="Duzenle"
                data-bs-toggle="modal"
                data-bs-target="#customerCompanyModal"
                data-mode="edit"
                data-id="<?= h($customerCompanyId) ?>"
                data-company_name="<?= h($customerCompany['company_name'] ?? '') ?>"
                data-contact_name="<?= h($customerCompany['contact_name'] ?? '') ?>"
                data-phone="<?= h($customerCompany['phone'] ?? '') ?>"
                data-email="<?= h($customerCompany['email'] ?? '') ?>"
                data-tax_office="<?= h($customerCompany['tax_office'] ?? '') ?>"
                data-tax_number="<?= h($customerCompany['tax_number'] ?? '') ?>"
                data-address="<?= h($customerCompany['address'] ?? '') ?>"
                data-notes="<?= h($customerCompany['notes'] ?? '') ?>"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </button>
              <form action="actions/customer_company_toggle.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($customerCompanyId) ?>">
                <input type="hidden" name="is_active" value="<?= (int) ($customerCompany['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                <button
                  class="action-btn <?= (int) ($customerCompany['is_active'] ?? 0) === 1 ? 'action-danger' : 'action-success' ?>"
                  type="submit"
                  title="<?= (int) ($customerCompany['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>"
                  aria-label="<?= (int) ($customerCompany['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>"
                  data-confirm="<?= (int) ($customerCompany['is_active'] ?? 0) === 1 ? 'Bu kurumsal musteriyi pasife almak istediginize emin misiniz?' : 'Bu kurumsal musteriyi tekrar aktif etmek istediginize emin misiniz?' ?>"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 5v10h-2V7h2Z"/></svg>
                </button>
              </form>
            </div>
            <?php else: ?>
            <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?= pagination_render($customerCompaniesPagination, ['item_label' => 'musteri firma']) ?>
    </div>
  </div>
</div>

<?php if ($canManageCustomers): ?>
<div class="modal fade" id="customerCompanyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Musteri Firma Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/customer_company_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="id" value="">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Firma Adi</label>
              <input name="company_name" class="form-control" maxlength="180" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Yetkili Kisi</label>
              <input name="contact_name" class="form-control" maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label">Telefon</label>
              <input name="phone" class="form-control" maxlength="30">
            </div>
            <div class="col-md-6">
              <label class="form-label">E-posta</label>
              <input name="email" type="email" class="form-control" maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label">Vergi Dairesi</label>
              <input name="tax_office" class="form-control" maxlength="120">
            </div>
            <div class="col-md-6">
              <label class="form-label">Vergi Numarasi</label>
              <input name="tax_number" class="form-control" maxlength="30">
            </div>
            <div class="col-12">
              <label class="form-label">Adres</label>
              <textarea name="address" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Not</label>
              <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-success" type="submit" data-submit-label>Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
