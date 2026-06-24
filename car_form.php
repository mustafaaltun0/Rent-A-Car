<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('cars.manage');

ensureCarOwnerSchema($pdo);
ensureCarArchiveSchema($pdo);
$companyId = auth_current_company_id();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$car = ['id' => '', 'plate' => '', 'brand' => '', 'model' => '', 'owner_name' => '', 'year' => '', 'inspection_date' => '', 'insurance_date' => '', 'maintenance_date' => '', 'maintenance_note' => ''];
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $st->execute([$id, $companyId]);
    $row = $st->fetch();
    if ($row) {
        $car = $row;
    }
}
$pageTitle = $id ? 'Araç Düzenle' : 'Yeni Araç';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<h2 class="mb-4"><?= $id ? 'Araç Düzenle' : 'Yeni Araç Ekle' ?></h2>
<form action="actions/car_save.php" method="post" class="card shadow-sm">
  <div class="card-body">
    <?= auth_csrf_input() ?>
    <input type="hidden" name="id" value="<?= h($car['id']) ?>">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Plaka</label><input name="plate" class="form-control" value="<?= h($car['plate']) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Marka</label><input name="brand" class="form-control" value="<?= h($car['brand']) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Model</label><input name="model" class="form-control" value="<?= h($car['model']) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Yıl</label><input name="year" type="number" class="form-control" value="<?= h($car['year']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Muayene</label><input name="inspection_date" type="date" class="form-control" value="<?= h($car['inspection_date']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Sigorta</label><input name="insurance_date" type="date" class="form-control" value="<?= h($car['insurance_date']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Bakım</label><input name="maintenance_date" type="date" class="form-control" value="<?= h($car['maintenance_date']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Bakım Notu</label><input name="maintenance_note" class="form-control" value="<?= h($car['maintenance_note']) ?>"></div>
    </div>
  </div>
  <div class="card-footer"><button class="btn btn-success">Kaydet</button> <a href="cars.php" class="btn btn-secondary">İptal</a></div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
