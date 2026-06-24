<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.manage');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarPhotoSchema($pdo);
$companyId = auth_current_company_id();
$canReverseCollections = auth_can('platform.manage');

$rentalId = (int) ($_GET['rental_id'] ?? 0);
$extensionId = (int) ($_GET['extension_id'] ?? 0);
$returnTo = trim((string) ($_GET['return_to'] ?? 'collection_center.php'));
$status = trim((string) ($_GET['status'] ?? ''));

if ($rentalId <= 0 || $extensionId <= 0) {
    auth_redirect('collection_center.php');
}

$extensionSt = $pdo->prepare('
    SELECT
        re.*,
        r.customer_name,
        r.company_id AS rental_company_id,
        c.id AS car_id,
        c.brand,
        c.model,
        c.plate,
        c.photo_path,
        c.photo_position_x,
        c.photo_position_y,
        c.photo_focus_x,
        c.photo_focus_y
    FROM rental_extensions re
    INNER JOIN rentals r ON r.id = re.rental_id
    LEFT JOIN cars c ON c.id = r.car_id AND c.company_id = r.company_id
    WHERE re.id = ? AND re.rental_id = ? AND r.company_id = ? AND r.archived_at IS NULL
    LIMIT 1
');
$extensionSt->execute([$extensionId, $rentalId, $companyId]);
$extension = $extensionSt->fetch(PDO::FETCH_ASSOC);

if (!$extension || !rental_extension_is_active($extension)) {
    auth_redirect('collection_center.php?status=extension_not_collectible');
}

$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$remainingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);
$collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
$extensionCollections = array_values(array_filter(
    $collectionsByExtensionId[$extensionId] ?? [],
    static fn (array $collection): bool => ($collection['collection_status'] ?? 'active') === 'active'
));
$latestActiveCollection = rental_extension_latest_active_collection($extensionCollections);

if ($remainingAmount <= 0.0) {
    auth_redirect('collection_center.php?status=extension_collected');
}

$carPhotoUrl = !empty($extension['car_id']) ? car_photo_public_url(['id' => $extension['car_id'], 'photo_path' => $extension['photo_path'] ?? null]) : null;

$pageTitle = 'Tahsilat Düş';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="collection-center-page">
  <div class="rentals-hero mb-4">
    <div>
      <div class="cars-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Tahsilat Düş</h2>
      <div class="text-muted">Müşteriden gelen parçalı ödemeyi buradan kaydedebilirsin.</div>
    </div>
    <div class="rentals-hero-actions">
      <a href="<?= h($returnTo) ?>" class="btn btn-outline-dark">Tahsilat Merkezine Dön</a>
    </div>
  </div>

  <?php if ($status === 'extension_collection_cancelled'): ?>
  <div class="alert alert-success">Tahsilat kaydi geri alindi.</div>
  <?php elseif ($status === 'extension_collection_not_reversible'): ?>
  <div class="alert alert-danger">Sadece son aktif tahsilat kaydi geri alinabilir.</div>
  <?php elseif ($status === 'extension_not_collectible'): ?>
  <div class="alert alert-danger">Bu uzatma kaydi icin su anda islem yapilamaz.</div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form action="actions/rental_extension_collect.php" method="post" class="row g-3">
        <?= auth_csrf_input() ?>
        <input type="hidden" name="extension_id" value="<?= h((string) $extensionId) ?>">
        <input type="hidden" name="rental_id" value="<?= h((string) $rentalId) ?>">
        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">

        <div class="col-md-6">
          <label class="form-label">Müşteri</label>
          <input class="form-control" value="<?= h($extension['customer_name'] ?? '-') ?>" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label">Araç</label>
          <input class="form-control" value="<?= h(trim(($extension['brand'] ?? '') . ' ' . ($extension['model'] ?? '')) . (!empty($extension['plate']) ? ' / ' . $extension['plate'] : '')) ?>" readonly>
        </div>
        <?php if ($carPhotoUrl): ?>
        <div class="col-12">
          <div class="car-photo-frame">
            <img src="<?= h($carPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($extension['photo_path'] ?? 'car'))) ?>" alt="<?= h(trim(($extension['brand'] ?? '') . ' ' . ($extension['model'] ?? ''))) ?>" style="<?= h(car_photo_position_style($extension)) ?>">
          </div>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
          <label class="form-label">Toplam Uzatma</label>
          <input class="form-control" value="<?= h(money($extension['income'] ?? 0)) ?>" readonly>
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
          <div class="form-text">Müşteri ne kadar para verdiyse onu gir. Sistem kalan borcu otomatik düşürür.</div>
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

  <div class="card shadow-sm mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Tahsilat Geçmişi</span>
      <span class="badge bg-dark"><?= h((string) count($extensionCollections)) ?> hareket</span>
    </div>
    <div class="card-body">
      <?php if (empty($extensionCollections)): ?>
      <div class="text-muted">Bu uzatma icin henuz tahsilat girilmemis.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle mb-0">
          <tr><th>Tarih</th><th>Tutar</th><th>Ödeme Tipi</th><th>Not</th><?php if ($canReverseCollections): ?><th>İşlem</th><?php endif; ?></tr>
          <?php foreach ($extensionCollections as $collection): ?>
          <tr>
            <td><?= dt($collection['collected_at'] ?? null) ?></td>
            <td><?= money($collection['amount'] ?? 0) ?></td>
            <td><?= h(($collection['payment_method'] ?? '') !== '' ? $collection['payment_method'] : '-') ?></td>
            <td><?= h(($collection['note'] ?? '') !== '' ? $collection['note'] : '-') ?></td>
            <?php if ($canReverseCollections): ?>
            <td class="table-actions-cell">
              <?php $isLatestActiveCollection = $latestActiveCollection && (int) ($collection['id'] ?? 0) === (int) ($latestActiveCollection['id'] ?? 0); ?>
              <?php if ($isLatestActiveCollection): ?>
              <form action="actions/rental_extension_collection_cancel.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="collection_id" value="<?= h((string) ($collection['id'] ?? 0)) ?>">
                <input type="hidden" name="extension_id" value="<?= h((string) $extensionId) ?>">
                <input type="hidden" name="rental_id" value="<?= h((string) $rentalId) ?>">
                <input type="hidden" name="return_to" value="<?= h('collection_collect.php?rental_id=' . $rentalId . '&extension_id=' . $extensionId . '&return_to=' . urlencode($returnTo)) ?>">
                <input type="hidden" name="cancel_reason_option" value="Yanlis tahsilat girildi">
                <button class="action-btn action-danger" type="submit" title="Geri Al" aria-label="Geri Al" data-confirm="Bu son tahsilat kaydini geri almak istediginize emin misiniz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.3 10H3l3.5-3.5L10 15H7.8A5 5 0 1 0 12 7h-1V5h1Z"/></svg>
                </button>
              </form>
              <?php else: ?>
              <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
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
