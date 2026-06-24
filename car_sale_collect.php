<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('cars.manage');

ensureCarArchiveSchema($pdo);
ensureCarSaleSchema($pdo);
ensureCarPhotoSchema($pdo);
$companyId = auth_current_company_id();
$canReverseCollections = auth_can('platform.manage');

$carId = (int) ($_GET['car_id'] ?? 0);
$returnTo = trim((string) ($_GET['return_to'] ?? ('car_detail.php?id=' . $carId)));
$status = trim((string) ($_GET['status'] ?? ''));
$editCollectionId = (int) ($_GET['edit_collection_id'] ?? 0);

if ($carId <= 0) {
    redirect('cars.php');
}

$saleSt = $pdo->prepare("
    SELECT cs.*, c.id AS car_id, c.plate, c.brand, c.model, c.photo_path, c.photo_position_x, c.photo_position_y, c.photo_focus_x, c.photo_focus_y
    FROM car_sales cs
    INNER JOIN cars c ON c.id = cs.car_id AND c.company_id = cs.company_id
    WHERE cs.company_id = ? AND cs.car_id = ? AND cs.sale_status = 'active'
    ORDER BY cs.id DESC
    LIMIT 1
");
$saleSt->execute([$companyId, $carId]);
$sale = $saleSt->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
    redirect('car_detail.php?id=' . $carId . '&status=car_sale_invalid');
}

$collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [(int) ($sale['id'] ?? 0)]);
$saleCollections = $collectionsBySaleId[(int) ($sale['id'] ?? 0)] ?? [];
$remainingAmount = car_sale_pending_amount($sale, $collectionsBySaleId);
$collectedAmount = car_sale_collected_amount($sale, $collectionsBySaleId);
$activeCollections = array_values(array_filter($saleCollections, static fn (array $collection): bool => car_sale_collection_is_active($collection)));
$latestActiveCollection = car_sale_latest_active_collection($saleCollections);
$editableCollection = null;
$editableLimit = 0.0;
if ($editCollectionId > 0 && $latestActiveCollection && (int) ($latestActiveCollection['id'] ?? 0) === $editCollectionId) {
    foreach ($saleCollections as $collectionRow) {
        if ((int) ($collectionRow['id'] ?? 0) !== $editCollectionId || !car_sale_collection_is_active($collectionRow)) {
            continue;
        }

        $editableCollection = $collectionRow;
        $editableLimit = max(0.0, $remainingAmount + max(0.0, (float) ($collectionRow['amount'] ?? 0)));
        break;
    }
}

if ($remainingAmount <= 0.0 && !$editableCollection) {
    redirect('car_detail.php?id=' . $carId . '&status=car_sale_collected');
}

$carPhotoUrl = !empty($sale['car_id']) ? car_photo_public_url(['id' => $sale['car_id'], 'photo_path' => $sale['photo_path'] ?? null]) : null;

$pageTitle = 'Satis Tahsilati';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="collection-center-page">
  <div class="rentals-hero mb-4">
    <div>
      <div class="cars-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Satis Tahsilati</h2>
      <div class="text-muted">Alicidan gelen parcali odemeleri buradan kaydedebilirsin.</div>
    </div>
    <div class="rentals-hero-actions">
      <a href="<?= h($returnTo) ?>" class="btn btn-outline-dark">Geri Dön</a>
    </div>
  </div>

  <?php if ($status === 'car_sale_collection_cancelled'): ?>
  <div class="alert alert-success">Tahsilat kaydi geri alindi.</div>
  <?php elseif ($status === 'car_sale_collection_not_reversible'): ?>
  <div class="alert alert-danger">Sadece son aktif tahsilat geri alinabilir.</div>
  <?php elseif ($status === 'car_sale_collection_invalid'): ?>
  <div class="alert alert-danger">Tahsilat tutari kalan borctan buyuk ya da sifir olamaz.</div>
  <?php elseif ($status === 'car_sale_collection_updated'): ?>
  <div class="alert alert-success">Tahsilat kaydı güncellendi.</div>
  <?php elseif ($status === 'car_sale_collection_update_invalid'): ?>
  <div class="alert alert-danger">Tahsilat güncelleme verisi geçersiz.</div>
  <?php elseif ($status === 'car_sale_collection_update_conflict'): ?>
  <div class="alert alert-danger">Bu tutar diger tahsilatlarla birlikte satis bedelini asiyor.</div>
  <?php elseif ($status === 'car_sale_updated'): ?>
  <div class="alert alert-success">Satış kaydı güncellendi.</div>
  <?php elseif ($status === 'car_sale_update_invalid'): ?>
  <div class="alert alert-danger">Satış kaydı güncelleme verisi geçersiz.</div>
  <?php elseif ($status === 'car_sale_update_conflict'): ?>
  <div class="alert alert-danger">Toplam satis tutari, tahsil edilen tutardan dusuk olamaz.</div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header">Satış Kaydını Düzenle</div>
    <div class="card-body">
      <form action="actions/car_sale_update.php" method="post" class="row g-3">
        <?= auth_csrf_input() ?>
        <input type="hidden" name="sale_id" value="<?= h((string) ($sale['id'] ?? 0)) ?>">
        <input type="hidden" name="car_id" value="<?= h((string) $carId) ?>">
        <input type="hidden" name="return_to" value="<?= h('car_sale_collect.php?car_id=' . $carId . '&return_to=' . urlencode($returnTo)) ?>">

        <div class="col-md-6">
          <label class="form-label">Alici Adi</label>
          <input name="buyer_name" class="form-control" value="<?= h((string) ($sale['buyer_name'] ?? '')) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Alici Telefonu</label>
          <input name="buyer_phone" class="form-control" value="<?= h((string) ($sale['buyer_phone'] ?? '')) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Satis Tarihi</label>
          <input name="sale_date" type="datetime-local" class="form-control" value="<?= h(!empty($sale['sale_date']) ? date('Y-m-d\TH:i', strtotime((string) $sale['sale_date'])) : '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Toplam Satis Tutari</label>
          <input name="total_amount" type="number" step="0.01" min="<?= h((string) max(0.01, $collectedAmount)) ?>" class="form-control" value="<?= h((string) ($sale['total_amount'] ?? '')) ?>" required>
          <div class="form-text">En az tahsil edilen tutar kadar olabilir: <?= money($collectedAmount) ?></div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Onceden Tahsil Edilen</label>
          <input name="collected_amount" type="number" step="0.01" min="0" max="<?= h((string) ($sale['total_amount'] ?? 0)) ?>" class="form-control" value="<?= h((string) $collectedAmount) ?>" required>
          <div class="form-text">Burayi degistirdiginde sistem son aktif tahsilat kaydini otomatik duzeltir.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Vade Tarihi</label>
          <input name="payment_due_date" type="datetime-local" class="form-control" value="<?= h(!empty($sale['payment_due_date']) ? date('Y-m-d\TH:i', strtotime((string) $sale['payment_due_date'])) : '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Not</label>
          <textarea name="note" class="form-control" rows="3" placeholder="Opsiyonel not"><?= h((string) ($sale['note'] ?? '')) ?></textarea>
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-primary" type="submit">Satis Kaydini Guncelle</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($remainingAmount > 0): ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <form action="actions/car_sale_collect.php" method="post" class="row g-3">
        <?= auth_csrf_input() ?>
        <input type="hidden" name="car_id" value="<?= h((string) $carId) ?>">
        <input type="hidden" name="sale_id" value="<?= h((string) ($sale['id'] ?? 0)) ?>">
        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">

        <div class="col-md-6">
          <label class="form-label">Alici</label>
          <input class="form-control" value="<?= h($sale['buyer_name'] ?? '-') ?>" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label">Araç</label>
          <input class="form-control" value="<?= h(trim(($sale['brand'] ?? '') . ' ' . ($sale['model'] ?? '')) . (!empty($sale['plate']) ? ' / ' . $sale['plate'] : '')) ?>" readonly>
        </div>
        <?php if ($carPhotoUrl): ?>
        <div class="col-12">
          <div class="car-photo-frame">
            <img src="<?= h($carPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($sale['photo_path'] ?? 'car'))) ?>" alt="<?= h(trim(($sale['brand'] ?? '') . ' ' . ($sale['model'] ?? ''))) ?>" style="<?= h(car_photo_position_style($sale)) ?>">
          </div>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
          <label class="form-label">Toplam Satis</label>
          <input class="form-control" value="<?= h(money($sale['total_amount'] ?? 0)) ?>" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">Onceden Tahsil</label>
          <input class="form-control" value="<?= h(money($collectedAmount)) ?>" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">Kalan Tutar</label>
          <input class="form-control" value="<?= h(money($remainingAmount)) ?>" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label">Gelen Tutar</label>
          <input name="amount" type="number" step="0.01" min="0.01" max="<?= h((string) $remainingAmount) ?>" class="form-control" value="<?= h((string) $remainingAmount) ?>" required>
          <div class="form-text">Ne kadar para geldiyse onu gir. Sistem kalan alacagi otomatik dusurur.</div>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-dark" data-quick-amount="<?= h((string) $remainingAmount) ?>">Kalanin Tamami</button>
            <?php if ($remainingAmount > 1): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-quick-amount="<?= h((string) round($remainingAmount / 2, 2)) ?>">Yarisi</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Odeme Tipi</label>
          <select name="payment_method" class="form-select">
            <option value="">Secilmedi</option>
            <option value="Nakit">Nakit</option>
            <option value="Havale">Havale</option>
            <option value="EFT">EFT</option>
            <option value="Kart">Kart</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Not</label>
          <textarea name="note" class="form-control" rows="3" placeholder="Opsiyonel not"></textarea>
        </div>
        <div class="col-12 d-flex justify-content-end gap-2">
          <a href="<?= h($returnTo) ?>" class="btn btn-outline-secondary">Vazgec</a>
          <button class="btn btn-success" type="submit">Tahsilati Kaydet</button>
        </div>
      </form>
    </div>
  </div>
  <?php elseif ($editableCollection): ?>
  <div class="alert alert-info">Bu satisin tamami tahsil edilmis gorunuyor. Son tahsilat kaydini buradan duzenleyebilirsin.</div>
  <?php endif; ?>

  <?php if ($editableCollection): ?>
  <div class="card shadow-sm mt-4">
    <div class="card-header">Tahsilatı Düzenle</div>
    <div class="card-body">
      <form action="actions/car_sale_collection_update.php" method="post" class="row g-3">
        <?= auth_csrf_input() ?>
        <input type="hidden" name="collection_id" value="<?= h((string) ($editableCollection['id'] ?? 0)) ?>">
        <input type="hidden" name="sale_id" value="<?= h((string) ($sale['id'] ?? 0)) ?>">
        <input type="hidden" name="car_id" value="<?= h((string) $carId) ?>">
        <input type="hidden" name="return_to" value="<?= h('car_sale_collect.php?car_id=' . $carId . '&return_to=' . urlencode($returnTo)) ?>">

        <div class="col-md-6">
          <label class="form-label">Tahsilat Tarihi</label>
          <input name="collected_at" type="datetime-local" class="form-control" value="<?= h(!empty($editableCollection['collected_at']) ? date('Y-m-d\TH:i', strtotime((string) $editableCollection['collected_at'])) : '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Tahsilat Tutari</label>
          <input name="amount" type="number" step="0.01" min="0.01" max="<?= h((string) $editableLimit) ?>" class="form-control" value="<?= h((string) ($editableCollection['amount'] ?? '')) ?>" required>
          <div class="form-text">Maksimum duzenlenebilir tutar: <?= money($editableLimit) ?></div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Odeme Tipi</label>
          <select name="payment_method" class="form-select">
            <option value="">Secilmedi</option>
            <option value="Nakit" <?= ($editableCollection['payment_method'] ?? '') === 'Nakit' ? 'selected' : '' ?>>Nakit</option>
            <option value="Havale" <?= ($editableCollection['payment_method'] ?? '') === 'Havale' ? 'selected' : '' ?>>Havale</option>
            <option value="EFT" <?= ($editableCollection['payment_method'] ?? '') === 'EFT' ? 'selected' : '' ?>>EFT</option>
            <option value="Kart" <?= ($editableCollection['payment_method'] ?? '') === 'Kart' ? 'selected' : '' ?>>Kart</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Not</label>
          <input name="note" class="form-control" value="<?= h((string) ($editableCollection['note'] ?? '')) ?>" placeholder="Tahsilat duzeltme notu">
        </div>
        <div class="col-12 d-flex justify-content-end gap-2">
          <a href="car_sale_collect.php?car_id=<?= h((string) $carId) ?>&return_to=<?= h(urlencode($returnTo)) ?>" class="btn btn-outline-secondary">Iptal</a>
          <button class="btn btn-primary" type="submit">Tahsilati Guncelle</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card shadow-sm mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Tahsilat Geçmişi</span>
      <span class="badge bg-dark"><?= h((string) count($saleCollections)) ?> hareket</span>
    </div>
    <div class="card-body">
      <?php if (empty($saleCollections)): ?>
      <div class="text-muted">Bu satis icin henuz tahsilat girilmemis.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle mb-0">
          <tr><th>Tarih</th><th>Tutar</th><th>Ödeme Tipi</th><th>Not</th><th>İşlem</th></tr>
          <?php foreach ($saleCollections as $collection): ?>
          <tr>
            <td><?= dt($collection['collected_at'] ?? null) ?></td>
            <td><?= money($collection['amount'] ?? 0) ?></td>
            <td><?= h(($collection['payment_method'] ?? '') !== '' ? $collection['payment_method'] : '-') ?></td>
            <td><?= h(($collection['note'] ?? '') !== '' ? $collection['note'] : '-') ?></td>
            <td class="table-actions-cell">
              <?php $isLatestActiveCollection = $latestActiveCollection && (int) ($collection['id'] ?? 0) === (int) ($latestActiveCollection['id'] ?? 0) && car_sale_collection_is_active($collection); ?>
              <?php $canEditCollection = $isLatestActiveCollection && auth_can('cars.manage'); ?>
              <?php $canCancelCollection = $isLatestActiveCollection && $canReverseCollections; ?>
              <?php if ($canEditCollection): ?>
              <a href="car_sale_collect.php?car_id=<?= h((string) $carId) ?>&edit_collection_id=<?= h((string) ($collection['id'] ?? 0)) ?>&return_to=<?= h(urlencode($returnTo)) ?>" class="action-btn action-warning" title="Düzenle" aria-label="Düzenle">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </a>
              <?php endif; ?>
              <?php if ($canCancelCollection): ?>
              <form action="actions/car_sale_collection_cancel.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="collection_id" value="<?= h((string) ($collection['id'] ?? 0)) ?>">
                <input type="hidden" name="sale_id" value="<?= h((string) ($sale['id'] ?? 0)) ?>">
                <input type="hidden" name="car_id" value="<?= h((string) $carId) ?>">
                <input type="hidden" name="return_to" value="<?= h('car_sale_collect.php?car_id=' . $carId . '&return_to=' . urlencode($returnTo)) ?>">
                <input type="hidden" name="cancel_reason_option" value="Yanlis tahsilat girildi">
                <button class="action-btn action-danger" type="submit" title="Geri Al" aria-label="Geri Al" data-confirm="Bu son tahsilat kaydini geri almak istediginize emin misiniz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.3 10H3l3.5-3.5L10 15H7.8A5 5 0 1 0 12 7h-1V5h1Z"/></svg>
                </button>
              </form>
              <?php endif; ?>
              <?php if (!$canEditCollection && !$canCancelCollection): ?>
              <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('[data-quick-amount]').forEach((button) => {
  button.addEventListener('click', () => {
    const amountInput = document.querySelector('input[name="amount"]');
    if (!amountInput) return;
    amountInput.value = button.getAttribute('data-quick-amount') || '';
    amountInput.focus();
  });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
