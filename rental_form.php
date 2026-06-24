<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.manage');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarArchiveSchema($pdo);
ensureCarSaleSchema($pdo);
ensureCustomerCompanySchema($pdo);
$companyId = auth_current_company_id();
$customerCompaniesEnabled = app_feature_customer_companies_enabled();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$rental = [
    'id' => '',
    'customer_company_id' => '',
    'customer_name' => '',
    'customer_phone' => '',
    'customer_identity_no' => '',
    'start_date' => '',
    'end_date' => '',
    'departure_km' => '',
    'income' => '',
    'collected_amount' => '',
    'payment_due_date' => '',
    'expense' => '',
    'car_id' => '',
];

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $st->execute([$id, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $rental = $row;
    }
}

$carsSt = $pdo->prepare('SELECT * FROM cars WHERE company_id = ? AND archived_at IS NULL AND sold_at IS NULL ORDER BY brand, model');
$carsSt->execute([$companyId]);
$cars = $carsSt->fetchAll(PDO::FETCH_ASSOC);
$customerCompanies = $customerCompaniesEnabled ? getCustomerCompanies($pdo, $companyId) : [];

$pageTitle = $id ? 'Kiralama Düzenle' : 'Yeni Kiralama';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<h2 class="mb-4"><?= $id ? 'Kiralama Düzenle' : 'Yeni Kiralama Ekle' ?></h2>
<form action="actions/rental_save.php" method="post" class="card shadow-sm">
  <div class="card-body">
    <?= auth_csrf_input() ?>
    <input type="hidden" name="id" value="<?= h($rental['id']) ?>">
    <div class="row g-3">
      <?php if ($customerCompaniesEnabled): ?>
      <div class="col-md-6">
        <label class="form-label">Kurumsal Müşteri</label>
        <select name="customer_company_id" class="form-select">
          <option value="">Bireysel / Seçilmedi</option>
          <?php foreach ($customerCompanies as $customerCompany): ?>
          <?php
            $customerCompanyId = (string) ($customerCompany['id'] ?? '');
            $isSelectedCustomerCompany = (string) ($rental['customer_company_id'] ?? '') === $customerCompanyId;
            $isInactiveCustomerCompany = (int) ($customerCompany['is_active'] ?? 0) !== 1;
          ?>
          <option value="<?= h($customerCompanyId) ?>" <?= $isSelectedCustomerCompany ? 'selected' : '' ?> <?= $isInactiveCustomerCompany && !$isSelectedCustomerCompany ? 'disabled' : '' ?>>
            <?= h($customerCompany['company_name']) ?><?= $isInactiveCustomerCompany ? ' (Pasif)' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-6"><label class="form-label">Müşteri Adı</label><input name="customer_name" class="form-control" value="<?= h($rental['customer_name']) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Telefon</label><input name="customer_phone" class="form-control" value="<?= h($rental['customer_phone']) ?>"></div>
      <div class="col-md-6"><label class="form-label">TC Kimlik No</label><input name="customer_identity_no" class="form-control" value="<?= h($rental['customer_identity_no']) ?>" maxlength="11"></div>
      <div class="col-md-6"><label class="form-label">Araç</label><select name="car_id" class="form-select" required><?php foreach ($cars as $car): ?><option value="<?= h($car['id']) ?>" <?= (string) $rental['car_id'] === (string) $car['id'] ? 'selected' : '' ?>><?= h($car['brand'] . ' ' . $car['model'] . ' - ' . $car['plate']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Başlangıç</label><input name="start_date" type="datetime-local" class="form-control" value="<?= h($rental['start_date'] ? date('Y-m-d\TH:i', strtotime($rental['start_date'])) : '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Bitiş</label><input name="end_date" type="datetime-local" class="form-control" value="<?= h($rental['end_date'] ? date('Y-m-d\TH:i', strtotime($rental['end_date'])) : '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Çıkış KM</label><input name="departure_km" class="form-control" value="<?= h($rental['departure_km']) ?>"></div>
      <div class="col-md-3"><label class="form-label">Gelir</label><input name="income" type="number" step="0.01" class="form-control" value="<?= h($rental['income']) ?>"></div>
      <div class="col-md-3"><label class="form-label">Tahsil Edilen</label><input name="collected_amount" type="number" step="0.01" min="0" class="form-control" value="<?= h($rental['collected_amount']) ?>" placeholder="Boşsa tamamı tahsil edildi sayılır"></div>
      <div class="col-md-6"><label class="form-label">Beklenen Tahsilat Tarihi</label><input name="payment_due_date" type="datetime-local" class="form-control" value="<?= h($rental['payment_due_date'] ? date('Y-m-d\\TH:i', strtotime($rental['payment_due_date'])) : '') ?>"></div>
      <div class="col-md-3"><label class="form-label">Araç Masrafı</label><input name="expense" type="number" step="0.01" class="form-control" value="<?= h($rental['expense']) ?>"></div>
    </div>
  </div>
  <div class="card-footer"><button class="btn btn-success">Kaydet</button> <a href="rentals.php" class="btn btn-secondary">İptal</a></div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
