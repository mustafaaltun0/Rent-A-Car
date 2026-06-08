<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('cars.view');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarTelematicsSchema($pdo);
ensureCarTelematicsEventSchema($pdo);
ensureCarArchiveSchema($pdo);
$companyId = auth_current_company_id();

if (!function_exists('dateStatus')) {
    function dateStatus(?string $date): array {
        if (!$date) {
            return ['secondary', 'Tarih yok'];
        }

        $today = new DateTime(date('Y-m-d'));
        $target = new DateTime($date);
        $diffDays = (int) $today->diff($target)->format('%r%a');

        if ($diffDays < 0) {
            return ['danger', 'Geçmiş'];
        }

        if ($diffDays <= 30) {
            return ['warning', 'Yaklaşıyor'];
        }

        return ['success', 'Güvende'];
    }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$st = $pdo->prepare('SELECT * FROM cars WHERE id=? AND company_id=?');
$st->execute([$id, $companyId]);
$car = $st->fetch();
if (!$car) {
    redirect('cars.php');
}

$activeRentalCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id=? AND completed=0 AND company_id=? AND archived_at IS NULL');
$activeRentalCheck->execute([$id, $companyId]);
$isAvailable = (int) $activeRentalCheck->fetchColumn() === 0;

$sr = $pdo->prepare('SELECT * FROM rentals WHERE car_id=? AND company_id=? AND archived_at IS NULL ORDER BY id DESC');
$sr->execute([$id, $companyId]);
$rentals = $sr->fetchAll();
$historyPagination = paginate_collection($rentals, 'history_page', 'history_per_page', 10, [10, 20, 50, 100]);
$historyRentals = $historyPagination['items'];
$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$mileageSummary = buildCarMileageSummary($rentals, $car);
$telematicsEnabled = !empty($car['telematics_enabled']);
$telematicsHasLiveData = telematics_car_has_live_data($car);
$telematicsLocation = ($car['telematics_last_latitude'] !== null && $car['telematics_last_longitude'] !== null)
    ? number_format((float) $car['telematics_last_latitude'], 6, ',', '.') . ', ' . number_format((float) $car['telematics_last_longitude'], 6, ',', '.')
    : '-';

$totalIncome = 0.0;
$totalExpense = 0.0;
foreach ($rentals as $rental) {
    $totals = getRentalTotals($rental, $extensionsByRentalId, $collectionsByExtensionId);
    $totalIncome += $totals['income'];
    $totalExpense += $totals['expense'];
}
$totalProfit = $totalIncome - $totalExpense;

$inspectionStatus = dateStatus($car['inspection_date'] ?? null);
$insuranceStatus = dateStatus($car['insurance_date'] ?? null);
$maintenanceDate = !empty($car['maintenance_date']) ? d($car['maintenance_date']) : '-';
$statusLabel = $isAvailable ? 'Müsait' : 'Kirada';
$statusClass = $isAvailable ? 'status-success' : 'status-danger';
$companyLabel = (string) (auth_current_user()['company_name'] ?? 'Firma');
$isArchivedCar = car_is_archived($car);

$pageTitle = 'Araç Detay';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="car-detail-page">
  <?php if ($isArchivedCar): ?>
  <div class="alert alert-secondary">Bu arac arsivde. Gecmisini gorebilirsin ama yeni operasyonlarda kullanilmaz.</div>
  <?php endif; ?>
  <div class="car-hero mb-4">
    <div class="car-hero-main">
      <div class="car-hero-label">Araç Detayı</div>
      <h2 class="mb-2"><?= h(trim($car['brand'] . ' ' . $car['model'])) ?></h2>
      <div class="car-hero-subtitle"><?= h($car['plate']) ?></div>
    </div>
    <div class="car-hero-actions">
      <div class="status-line rental-status-pill"><span class="status-dot <?= $statusClass ?>"></span><span><?= h($statusLabel) ?></span></div>
      <a href="cars.php" class="btn btn-light">Araçlara Dön</a>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card bg-primary shadow-sm"><h6>Toplam Gelir</h6><h3><?= money($totalIncome) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-danger shadow-sm"><h6>Toplam Masraf</h6><h3><?= money($totalExpense) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-dark shadow-sm"><h6>Net Kar</h6><h3><?= money($totalProfit) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-success shadow-sm"><h6>Toplam Kiralama</h6><h3><?= h(count($rentals)) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-warning shadow-sm"><h6>Toplam KM</h6><h3><?= $mileageSummary['counted_rentals'] > 0 ? h(number_format((float) $mileageSummary['total_distance_km'], 0, ',', '.')) . ' km' : '-' ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-info shadow-sm"><h6>Gunluk Ort. KM</h6><h3><?= $mileageSummary['average_daily_km'] !== null ? h(number_format((float) $mileageSummary['average_daily_km'], 1, ',', '.')) . ' km' : '-' ?></h3></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="card shadow-sm h-100">
        <div class="card-header">Araç Bilgileri</div>
        <div class="card-body">
          <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Plaka</span><strong><?= h($car['plate']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Firma</span><strong><?= h($companyLabel) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Marka</span><strong><?= h($car['brand']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Model</span><strong><?= h($car['model']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Durum</span><strong><?= h($statusLabel) ?></strong></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card shadow-sm h-100">
        <div class="card-header">Kontrol ve Bakım</div>
        <div class="card-body">
          <div class="detail-grid detail-grid-single">
            <div class="detail-item"><span class="detail-label">Muayene</span><strong><span class="status-line"><span class="status-dot status-<?= h($inspectionStatus[0]) ?>"></span><span><?= h(d($car['inspection_date'])) ?> / <?= h($inspectionStatus[1]) ?></span></span></strong></div>
            <div class="detail-item"><span class="detail-label">Sigorta</span><strong><span class="status-line"><span class="status-dot status-<?= h($insuranceStatus[0]) ?>"></span><span><?= h(d($car['insurance_date'])) ?> / <?= h($insuranceStatus[1]) ?></span></span></strong></div>
            <div class="detail-item"><span class="detail-label">Bakım Tarihi</span><strong><?= h($maintenanceDate) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Bakım Notu</span><strong><?= h($car['maintenance_note'] ?: '-') ?></strong></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-5">
      <div class="card shadow-sm h-100">
        <div class="card-header">Telematik Altyapisi</div>
        <div class="card-body">
          <div class="detail-grid detail-grid-single">
            <div class="detail-item"><span class="detail-label">Durum</span><strong><?= $telematicsEnabled ? 'Hazir' : 'Bagli Degil' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Saglayici</span><strong><?= h($car['telematics_provider'] ?? '') ?: '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Cihaz ID</span><strong><?= h($car['telematics_device_id'] ?? '') ?: '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Son KM</span><strong><?= $car['telematics_last_odometer_km'] !== null ? h(number_format((int) $car['telematics_last_odometer_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Kontak</span><strong><?= $car['telematics_ignition_on'] === null ? '-' : ((int) $car['telematics_ignition_on'] === 1 ? 'Acik' : 'Kapali') ?></strong></div>
            <div class="detail-item"><span class="detail-label">Son Senkron</span><strong><?= !empty($car['telematics_last_sync_at']) ? dt($car['telematics_last_sync_at']) : '-' ?></strong></div>
            <div class="detail-item detail-item-full"><span class="detail-label">Son Konum</span><strong><?= h($telematicsLocation) ?></strong></div>
            <div class="detail-item detail-item-full"><span class="detail-label">Canli Veri</span><strong><?= $telematicsHasLiveData ? 'Son odometre / kontak / konum verisi alinmis.' : 'API baglandiginda anlik km, konum ve kontak bilgisi burada gorunecek.' ?></strong></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card shadow-sm h-100">
        <div class="card-header">KM Performansi</div>
        <div class="card-body">
          <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Toplam Hesaplanan KM</span><strong><?= $mileageSummary['counted_rentals'] > 0 ? h(number_format((float) $mileageSummary['total_distance_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Toplam Hesaplanan Gun</span><strong><?= $mileageSummary['total_days'] > 0 ? h($mileageSummary['total_days']) . ' gun' : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Ortalama Gunluk KM</span><strong><?= $mileageSummary['average_daily_km'] !== null ? h(number_format((float) $mileageSummary['average_daily_km'], 1, ',', '.')) . ' km' : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">KM Hesaplanan Kiralama</span><strong><?= h($mileageSummary['counted_rentals']) ?></strong></div>
            <div class="detail-item detail-item-full"><span class="detail-label">Canli Suren KM</span><strong><?= $mileageSummary['live_distance_km'] > 0 ? h(number_format((float) $mileageSummary['live_distance_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">Kiralama Geçmişi</div>
    <div class="card-body">
      <?php if (empty($rentals)): ?>
      <div class="text-center text-muted py-3">Bu araç için henüz kiralama kaydı yok.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle mb-0">
          <tr><th>Müşteri</th><th>Başlangıç</th><th>Bitiş</th><th>Gün</th><th>KM</th><th>Günlük KM</th><th>Kaynak</th><th>Gelir</th><th>Masraf</th><th>Net Kar</th></tr>
          <?php foreach ($historyRentals as $rental): ?>
          <?php $totals = getRentalTotals($rental, $extensionsByRentalId, $collectionsByExtensionId); ?>
          <?php $kmMetrics = rental_km_metrics($rental, $car); ?>
          <tr>
            <td><?= h($rental['customer_name']) ?></td>
            <td><?= dt($rental['start_date']) ?></td>
            <td><?= dt($rental['end_date']) ?></td>
            <td><?= $kmMetrics['duration_days'] !== null ? h($kmMetrics['duration_days']) . ' gün' : '-' ?></td>
            <td><?= $kmMetrics['distance_km'] !== null ? h(number_format((float) $kmMetrics['distance_km'], 0, ',', '.')) . ' km' : '-' ?></td>
            <td><?= $kmMetrics['average_daily_km'] !== null ? h(number_format((float) $kmMetrics['average_daily_km'], 1, ',', '.')) . ' km' : '-' ?></td>
            <td><?= h($kmMetrics['distance_source']) ?></td>
            <td><?= money($totals['income']) ?></td>
            <td><?= money($totals['expense']) ?></td>
            <td><?= money($totals['net_profit']) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?= pagination_render($historyPagination, ['item_label' => 'kiralama gecmisi']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
