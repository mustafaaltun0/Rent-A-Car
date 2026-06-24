<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('cars.manage');

ensureCarArchiveSchema($pdo);
ensureCarSaleSchema($pdo);
ensureRentalArchiveSchema($pdo);
$companyId = auth_current_company_id();
$carId = (int) ($_GET['car_id'] ?? 0);

if ($carId <= 0) {
    redirect('cars.php');
}

$carSt = $pdo->prepare('SELECT * FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$carSt->execute([$carId, $companyId]);
$car = $carSt->fetch(PDO::FETCH_ASSOC);
if (!$car) {
    redirect('cars.php');
}

if (car_is_sold($car)) {
    redirect('car_detail.php?id=' . $carId);
}

$activeRentalSt = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND company_id = ? AND completed = 0 AND archived_at IS NULL');
$activeRentalSt->execute([$carId, $companyId]);
$hasActiveRental = (int) $activeRentalSt->fetchColumn() > 0;

$pageTitle = 'Araç Satışı';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="collection-center-page">
  <div class="rentals-hero mb-4">
    <div>
      <div class="cars-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Araç Satışı</h2>
      <div class="text-muted">Aracı satışa kapat, alıcı bilgisini kaydet ve parçalı tahsilat planını sistemde takip et.</div>
    </div>
    <div class="rentals-hero-actions">
      <a href="car_detail.php?id=<?= h((string) $carId) ?>" class="btn btn-outline-dark">Araç Detayına Dön</a>
    </div>
  </div>

  <?php if ($hasActiveRental): ?>
  <div class="alert alert-danger">Aktif kirada olan bir araci satisa kapatamazsin. Once kiralamayi tamamlaman gerekir.</div>
  <?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <form action="actions/car_sale_save.php" method="post" class="row g-3">
        <?= auth_csrf_input() ?>
        <input type="hidden" name="car_id" value="<?= h((string) $carId) ?>">

        <div class="col-md-6">
          <label class="form-label">Araç</label>
          <input class="form-control" value="<?= h(trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) . (!empty($car['plate']) ? ' / ' . $car['plate'] : '')) ?>" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label">Plaka</label>
          <input class="form-control" value="<?= h($car['plate'] ?? '-') ?>" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label">Alici Adi</label>
          <input name="buyer_name" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Alici Telefonu</label>
          <input name="buyer_phone" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Satis Tarihi</label>
          <input name="sale_date" type="datetime-local" class="form-control" value="<?= h(date('Y-m-d\TH:i')) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Toplam Satis Bedeli</label>
          <input name="total_amount" type="number" step="0.01" min="0.01" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Pesin Tahsil Edilen</label>
          <input name="collected_amount" type="number" step="0.01" min="0" class="form-control" placeholder="Bossa tamami tahsil edildi sayilir">
        </div>
        <div class="col-md-6">
          <label class="form-label">Kalan Icin Vade Tarihi</label>
          <input name="payment_due_date" type="datetime-local" class="form-control">
        </div>
        <div class="col-12">
          <label class="form-label">Not</label>
          <textarea name="note" class="form-control" rows="3" placeholder="Opsiyonel not"></textarea>
        </div>
        <div class="col-12 d-flex justify-content-end gap-2">
          <a href="car_detail.php?id=<?= h((string) $carId) ?>" class="btn btn-outline-secondary">Vazgec</a>
          <button class="btn btn-success" type="submit">Satisi Kaydet</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
