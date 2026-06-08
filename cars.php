<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('cars.view');

ensureCarOwnerSchema($pdo);
ensureCarTelematicsSchema($pdo);
ensureCarTelematicsEventSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarArchiveSchema($pdo);
$companyId = auth_current_company_id();
$canManageCars = auth_can('cars.manage');
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';

$carsSql = 'SELECT * FROM cars WHERE company_id = ?';
$carsSql .= $showArchived ? ' AND archived_at IS NOT NULL' : ' AND archived_at IS NULL';
$carsSql .= ' ORDER BY id DESC';
$carsSt = $pdo->prepare($carsSql);
$carsSt->execute([$companyId]);
$cars = $carsSt->fetchAll();
$allCars = $cars;

$reportRentalsSt = $pdo->prepare('SELECT * FROM rentals WHERE company_id = ? AND archived_at IS NULL AND start_date IS NOT NULL ORDER BY start_date');
$reportRentalsSt->execute([$companyId]);
$reportRentals = $reportRentalsSt->fetchAll();
$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$activeRentalSt = $pdo->prepare('SELECT DISTINCT car_id FROM rentals WHERE company_id = ? AND archived_at IS NULL AND completed = 0 AND car_id IS NOT NULL');
$activeRentalSt->execute([$companyId]);
$activeRentalCarIds = $activeRentalSt->fetchAll(PDO::FETCH_COLUMN);
$activeRentalMap = array_fill_keys(array_map('strval', $activeRentalCarIds), true);
$selectedReportCarIds = array_map('intval', $_GET['report_car_ids'] ?? []);
$selectedReportCarIds = array_values(array_filter($selectedReportCarIds, static fn ($id) => $id > 0));
$reportSummary = [];
$reportTotals = [
    'daily' => 0.0,
    'weekly' => 0.0,
    'monthly' => 0.0,
    'yearly' => 0.0,
];

if (!empty($selectedReportCarIds)) {
    $today = new DateTimeImmutable(date('Y-m-d'));
    $periodRanges = [
        'daily' => [$today, $today->modify('+1 day')],
        'weekly' => [$today->modify('monday this week'), $today->modify('monday next week')],
        'monthly' => [$today->modify('first day of this month'), $today->modify('first day of next month')],
        'yearly' => [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y') + 1, 1, 1)],
    ];
    $rawSummary = buildCarPeriodProfitSummary($reportRentals, $extensionsByRentalId, $selectedReportCarIds, $periodRanges, $collectionsByExtensionId);
    $carMap = [];
    foreach ($allCars as $car) {
        $carMap[(int) $car['id']] = $car;
    }

    foreach ($selectedReportCarIds as $carId) {
        if (!isset($rawSummary[$carId], $carMap[$carId])) {
            continue;
        }

        $reportSummary[] = [
            'car' => $carMap[$carId],
            'periods' => $rawSummary[$carId],
        ];

        foreach ($reportTotals as $periodKey => $value) {
            $reportTotals[$periodKey] += $rawSummary[$carId][$periodKey]['net_profit'] ?? 0;
        }
    }
}

$carsPagination = paginate_collection($cars, 'cars_page', 'cars_per_page', 10, [10, 20, 50, 100]);
$cars = $carsPagination['items'];

function dateStatus(?string $date): array {
    if (!$date) {
        return ['secondary', 'Tarih yok'];
    }

    $today = new DateTime(date('Y-m-d'));
    $target = new DateTime($date);
    $diffDays = (int) $today->diff($target)->format('%r%a');

    if ($diffDays < 0) {
        return ['danger', 'Gecmis'];
    }

    if ($diffDays <= 30) {
        return ['warning', 'Yaklasiyor'];
    }

    return ['success', 'Guvende'];
}

function mergeStatus(array $first, array $second): array {
    $priority = ['danger' => 3, 'warning' => 2, 'success' => 1, 'secondary' => 0];
    return ($priority[$first[0]] ?? 0) >= ($priority[$second[0]] ?? 0) ? $first : $second;
}

$pageTitle = 'Araclar';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="cars-page">
<div class="cars-hero mb-4">
  <div>
    <div class="cars-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
    <h2 class="mb-2">Araclar</h2>
  </div>
  <div class="cars-hero-actions">
    <?php if ($canManageCars && !$showArchived): ?>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#carModal" data-mode="create">Yeni Arac Ekle</button>
    <?php endif; ?>
  </div>
</div>

<?php if (($_GET['error'] ?? '') === 'car_archive_active_rental'): ?>
<div class="alert alert-danger">Aktif kiradaki bir araci arsive alamazsin. Once kiralamayi kapatman gerekir.</div>
<?php endif; ?>
<?php if (($_GET['status'] ?? '') === 'archived'): ?>
<div class="alert alert-success">Arac arsive alindi.</div>
<?php endif; ?>
<?php if (($_GET['status'] ?? '') === 'restored'): ?>
<div class="alert alert-success">Arac arsivden geri yuklendi.</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-header"><?= $showArchived ? 'Arsivlenmis Araclar' : 'Arac Listesi' ?></div>
  <div class="card-body border-bottom bg-light-subtle rentals-switchbar">
    <?php if ($showArchived): ?>
    <a href="cars.php" class="btn btn-outline-dark btn-sm">Normal Listeye Don</a>
    <?php else: ?>
    <a href="cars.php?show_archived=1" class="btn btn-outline-secondary btn-sm">Arsivdekileri Gor</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="mobile-record-list d-grid d-lg-none">
      <?php foreach ($cars as $car): ?>
      <?php [$inspectionColor, $inspectionText] = dateStatus($car['inspection_date'] ?? null); ?>
      <?php [$insuranceColor, $insuranceText] = dateStatus($car['insurance_date'] ?? null); ?>
      <?php [$statusColor, $statusText] = mergeStatus([$inspectionColor, 'Muayene ' . $inspectionText], [$insuranceColor, 'Sigorta ' . $insuranceText]); ?>
      <?php $isAvailable = !isset($activeRentalMap[(string) $car['id']]); ?>
      <div class="mobile-record-card <?= $showArchived ? 'is-archived' : '' ?>">
        <div class="mobile-record-card-head">
          <div class="mobile-record-card-title">
            <strong><?= h($car['plate']) ?></strong>
            <small><?= h($car['brand'] . ' ' . $car['model']) ?></small>
          </div>
          <div class="mobile-record-card-badges">
            <?php if ($showArchived): ?>
            <span class="badge bg-secondary">Arsivde</span>
            <?php else: ?>
            <span class="badge <?= $isAvailable ? 'bg-success' : 'bg-danger' ?>"><?= $isAvailable ? 'Musait' : 'Kirada' ?></span>
            <?php endif; ?>
            <?php if (!empty($car['telematics_enabled'])): ?><span class="badge text-bg-light">GPS Hazir</span><?php endif; ?>
          </div>
        </div>
        <div class="mobile-record-grid">
          <div><span>Kontrol</span><strong><?= h($statusColor === 'success' ? 'Guvende' : $statusText) ?></strong></div>
          <div><span>Model Yili</span><strong><?= h($car['year'] ?: '-') ?></strong></div>
          <div><span>Muayene</span><strong><?= h($car['inspection_date'] ?: '-') ?></strong></div>
          <div><span>Sigorta</span><strong><?= h($car['insurance_date'] ?: '-') ?></strong></div>
          <?php if (!empty($car['maintenance_date'])): ?><div class="full"><span>Bakim</span><strong><?= h($car['maintenance_date']) ?></strong></div><?php endif; ?>
        </div>
        <div class="mobile-record-actions">
          <a href="car_detail.php?id=<?= h((string) $car['id']) ?>" class="action-btn action-info" title="Detay" aria-label="Detay">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 5.7 9.8 6 .2.3.2.7 0 1-.2.3-4.3 6-9.8 6S2.4 12.3 2.2 12c-.2-.3-.2-.7 0-1 .2-.3 4.3-6 9.8-6Zm0 2C8.4 7 5.4 10.2 4.3 11.5 5.4 12.8 8.4 16 12 16s6.6-3.2 7.7-4.5C18.6 10.2 15.6 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Zm0 2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6Z"/></svg>
          </a>
          <?php if ($canManageCars && !$showArchived): ?>
          <button class="action-btn action-warning" type="button" title="Duzenle" aria-label="Duzenle" data-bs-toggle="modal" data-bs-target="#carModal" data-mode="edit" data-id="<?= h($car['id']) ?>" data-plate="<?= h($car['plate']) ?>" data-brand="<?= h($car['brand']) ?>" data-model="<?= h($car['model']) ?>" data-telematics_enabled="<?= !empty($car['telematics_enabled']) ? '1' : '0' ?>" data-telematics_provider="<?= h($car['telematics_provider'] ?? '') ?>" data-telematics_device_id="<?= h($car['telematics_device_id'] ?? '') ?>" data-year="<?= h($car['year']) ?>" data-inspection_date="<?= h($car['inspection_date']) ?>" data-insurance_date="<?= h($car['insurance_date']) ?>" data-maintenance_date="<?= h($car['maintenance_date']) ?>" data-maintenance_note="<?= h($car['maintenance_note']) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
          </button>
          <form action="actions/car_delete.php" method="post">
            <?= auth_csrf_input() ?>
            <input type="hidden" name="id" value="<?= h($car['id']) ?>">
            <button class="action-btn action-danger" type="submit" title="Arsivle" aria-label="Arsivle" data-confirm="Bu araci arsive almak istediginize emin misiniz?">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v4H4V5Zm1 6h14v8H5v-8Zm3 2v2h8v-2H8Z"/></svg>
            </button>
          </form>
          <?php elseif ($canManageCars && $showArchived): ?>
          <form action="actions/car_restore.php" method="post">
            <?= auth_csrf_input() ?>
            <input type="hidden" name="id" value="<?= h($car['id']) ?>">
            <button class="action-btn action-secondary" type="submit" title="Geri Yukle" aria-label="Geri Yukle" data-confirm="Bu araci arsivden geri yuklemek istiyor musunuz?">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.6 9.4h2.1A5 5 0 1 0 12 7V4l4 3-4 3V7a5 5 0 0 0-4.9 4H5a7 7 0 0 1 7-6Z"/></svg>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-lg-block">
    <table class="table table-bordered table-striped align-middle">
      <tr><th>Plaka</th><th>Marka</th><th>Model</th><th>Durum</th><th>Kontrol</th><th>Islem</th></tr>
      <?php foreach ($cars as $car): ?>
      <?php [$inspectionColor, $inspectionText] = dateStatus($car['inspection_date'] ?? null); ?>
      <?php [$insuranceColor, $insuranceText] = dateStatus($car['insurance_date'] ?? null); ?>
      <?php [$statusColor, $statusText] = mergeStatus([$inspectionColor, 'Muayene ' . $inspectionText], [$insuranceColor, 'Sigorta ' . $insuranceText]); ?>
      <?php $isAvailable = !isset($activeRentalMap[(string) $car['id']]); ?>
      <tr class="clickable-row" onclick="window.location.href='car_detail.php?id=<?= h((string) $car['id']) ?>'">
        <td><?= h($car['plate']) ?></td>
        <td><?= h($car['brand']) ?></td>
        <td><?= h($car['model']) ?></td>
        <td><?= $showArchived ? '<span class="badge bg-secondary">ARSIVDE</span>' : ($isAvailable ? '<span class="badge bg-success">MUSAIT</span>' : '<span class="badge bg-danger">KIRADA</span>') ?></td>
        <td><div class="status-line"><span class="status-dot status-<?= $statusColor ?>"></span><span><?= h($statusColor === 'success' ? 'Guvende' : $statusText) ?></span></div></td>
        <td class="table-actions-cell">
          <?php if ($canManageCars): ?>
          <div class="action-group">
            <?php if (!$showArchived): ?>
            <button class="action-btn action-warning" type="button" title="Duzenle" aria-label="Duzenle" onclick="event.stopPropagation();" data-bs-toggle="modal" data-bs-target="#carModal" data-mode="edit" data-id="<?= h($car['id']) ?>" data-plate="<?= h($car['plate']) ?>" data-brand="<?= h($car['brand']) ?>" data-model="<?= h($car['model']) ?>" data-telematics_enabled="<?= !empty($car['telematics_enabled']) ? '1' : '0' ?>" data-telematics_provider="<?= h($car['telematics_provider'] ?? '') ?>" data-telematics_device_id="<?= h($car['telematics_device_id'] ?? '') ?>" data-year="<?= h($car['year']) ?>" data-inspection_date="<?= h($car['inspection_date']) ?>" data-insurance_date="<?= h($car['insurance_date']) ?>" data-maintenance_date="<?= h($car['maintenance_date']) ?>" data-maintenance_note="<?= h($car['maintenance_note']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
            </button>
            <form action="actions/car_delete.php" method="post" class="d-inline" onclick="event.stopPropagation();">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h($car['id']) ?>">
              <button class="action-btn action-danger" type="submit" title="Arsivle" aria-label="Arsivle" data-confirm="Bu araci arsive almak istediginize emin misiniz?">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v4H4V5Zm1 6h14v8H5v-8Zm3 2v2h8v-2H8Z"/></svg>
              </button>
            </form>
            <?php else: ?>
            <form action="actions/car_restore.php" method="post" class="d-inline" onclick="event.stopPropagation();">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h($car['id']) ?>">
              <button class="action-btn action-secondary" type="submit" title="Geri Yukle" aria-label="Geri Yukle" data-confirm="Bu araci arsivden geri yuklemek istiyor musunuz?">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.3 10H3l3.5-3.5L10 15H7.8A5 5 0 1 0 12 7h-1V5h1Z"/></svg>
              </button>
            </form>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted">-</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?= pagination_render($carsPagination, ['item_label' => 'arac']) ?>
  </div>
<?php if (!$showArchived): ?>
<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Karlilik Raporu</span>
    <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#carProfitReport" aria-expanded="<?= !empty($selectedReportCarIds) ? 'true' : 'false' ?>" aria-controls="carProfitReport">Ac / Kapat</button>
  </div>
  <div class="collapse <?= !empty($selectedReportCarIds) ? 'show' : '' ?>" id="carProfitReport">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-8">
          <label class="form-label d-block">Arac Sec</label>
          <div class="border rounded p-3 bg-light-subtle" style="max-height: 240px; overflow-y: auto;">
            <?php foreach ($allCars as $car): ?>
            <?php $carId = (int) $car['id']; ?>
            <label class="form-check mb-2 d-flex align-items-center gap-2">
              <input class="form-check-input mt-0" type="checkbox" name="report_car_ids[]" value="<?= $carId ?>" <?= in_array($carId, $selectedReportCarIds, true) ? 'checked' : '' ?>>
              <span><?= h($car['plate'] . ' - ' . $car['brand'] . ' ' . $car['model']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Raporunu gormek istedigin araclari kutucuklardan isaretleyebilirsin.</div>
        </div>
        <div class="col-lg-4 d-grid gap-2">
          <button class="btn btn-primary" type="submit">Raporu Goster</button>
          <a href="cars.php" class="btn btn-outline-secondary">Temizle</a>
        </div>
      </form>
    </div>
    <?php if (!empty($reportSummary)): ?>
    <div class="card-body border-top">
      <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3"><div class="stat-card bg-primary shadow-sm"><h6>Gunluk Toplam Kar</h6><h3><?= money($reportTotals['daily']) ?></h3></div></div>
        <div class="col-md-6 col-xl-3"><div class="stat-card bg-success shadow-sm"><h6>Haftalik Toplam Kar</h6><h3><?= money($reportTotals['weekly']) ?></h3></div></div>
        <div class="col-md-6 col-xl-3"><div class="stat-card bg-warning shadow-sm"><h6>Aylik Toplam Kar</h6><h3><?= money($reportTotals['monthly']) ?></h3></div></div>
        <div class="col-md-6 col-xl-3"><div class="stat-card bg-dark shadow-sm"><h6>Yillik Toplam Kar</h6><h3><?= money($reportTotals['yearly']) ?></h3></div></div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
          <tr><th>Arac</th><th>Gunluk Kar</th><th>Haftalik Kar</th><th>Aylik Kar</th><th>Yillik Kar</th></tr>
          <?php foreach ($reportSummary as $summaryRow): ?>
          <tr>
            <td><?= h($summaryRow['car']['plate'] . ' - ' . $summaryRow['car']['brand'] . ' ' . $summaryRow['car']['model']) ?></td>
            <td><?= money($summaryRow['periods']['daily']['net_profit']) ?></td>
            <td><?= money($summaryRow['periods']['weekly']['net_profit']) ?></td>
            <td><?= money($summaryRow['periods']['monthly']['net_profit']) ?></td>
            <td><?= money($summaryRow['periods']['yearly']['net_profit']) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <?php elseif (!empty($selectedReportCarIds)): ?>
    <div class="card-body border-top text-muted">Secilen araclar icin hesaplanacak kar verisi bulunamadi.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>

<?php if ($canManageCars && !$showArchived): ?>
<div class="modal fade" id="carModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="carModalLabel">Yeni Arac Ekle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form action="actions/car_save.php" method="post" data-modal-form="car">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="id" value="">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Plaka</label><input name="plate" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Marka</label><input name="brand" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Model</label><input name="model" class="form-control" required></div>
            <div class="col-md-6">
              <div class="form-check form-switch mt-4 pt-2">
                <input name="telematics_enabled" value="1" class="form-check-input" type="checkbox" id="carTelematicsEnabled">
                <label class="form-check-label" for="carTelematicsEnabled">GPS / telematik cihazi bagli</label>
              </div>
            </div>
            <div class="col-md-3"><label class="form-label">Telematik Saglayici</label><input name="telematics_provider" class="form-control" placeholder="Ornek: Arvento"></div>
            <div class="col-md-3"><label class="form-label">Cihaz ID</label><input name="telematics_device_id" class="form-control" placeholder="Ornek: DEV-001"></div>
            <div class="col-md-4"><label class="form-label">Muayene</label><input name="inspection_date" type="date" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Sigorta</label><input name="insurance_date" type="date" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Bakim</label><input name="maintenance_date" type="date" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Bakim Notu</label><input name="maintenance_note" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button><button class="btn btn-success" type="submit" data-submit-label>Kaydet</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
