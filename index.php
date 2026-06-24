<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('dashboard.view');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarOwnerSchema($pdo);
ensureCarArchiveSchema($pdo);
ensureCarSaleSchema($pdo);
ensureExpenseArchiveSchema($pdo);
ensureBusinessExpenseOwnerSchema($pdo);

$companyId = auth_current_company_id();

$carsSt = $pdo->prepare('SELECT * FROM cars WHERE company_id = ? AND archived_at IS NULL ORDER BY id DESC');
$carsSt->execute([$companyId]);
$cars = $carsSt->fetchAll(PDO::FETCH_ASSOC);

$activeRentalSt = $pdo->prepare('SELECT DISTINCT car_id FROM rentals WHERE company_id = ? AND archived_at IS NULL AND completed = 0 AND car_id IS NOT NULL');
$activeRentalSt->execute([$companyId]);
$activeRentalCarIds = $activeRentalSt->fetchAll(PDO::FETCH_COLUMN);

$rentalsSt = $pdo->prepare('SELECT * FROM rentals WHERE company_id = ? AND archived_at IS NULL AND start_date IS NOT NULL ORDER BY start_date');
$rentalsSt->execute([$companyId]);
$rentals = $rentalsSt->fetchAll(PDO::FETCH_ASSOC);

$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);

$monthNames = [
    '01' => 'Ocak',
    '02' => 'Şubat',
    '03' => 'Mart',
    '04' => 'Nisan',
    '05' => 'Mayıs',
    '06' => 'Haziran',
    '07' => 'Temmuz',
    '08' => 'Ağustos',
    '09' => 'Eylül',
    '10' => 'Ekim',
    '11' => 'Kasım',
    '12' => 'Aralık',
];

$selectedOwnerCars = $cars;
$selectedOwnerCarIds = array_map(static fn (array $car): int => (int) $car['id'], $cars);
$selectedOwnerCarIdMap = array_fill_keys($selectedOwnerCarIds, true);
$soldCarIdMap = [];
foreach ($cars as $car) {
    if (car_is_sold($car)) {
        $soldCarIdMap[(int) ($car['id'] ?? 0)] = true;
    }
}
$soldCars = count(array_filter($cars, static fn (array $car): bool => car_is_sold($car)));
$fleetCars = max(0, count($cars) - $soldCars);
$ownerRentals = array_values(array_filter($rentals, static function (array $rental) use ($selectedOwnerCarIdMap): bool {
    return isset($selectedOwnerCarIdMap[(int) ($rental['car_id'] ?? 0)]);
}));

$totalCars = count($selectedOwnerCars);
$rentedCars = 0;
foreach ($activeRentalCarIds as $carId) {
    if (isset($selectedOwnerCarIdMap[(int) $carId]) && !isset($soldCarIdMap[(int) $carId])) {
        $rentedCars++;
    }
}
$availableCars = max(0, $fleetCars - $rentedCars);
$totalRentals = count($ownerRentals);
$activeCars = $fleetCars;

$totalIncome = 0.0;
$totalExpense = 0.0;
foreach ($ownerRentals as $rental) {
    $totals = getRentalTotals($rental, $extensionsByRentalId, $collectionsByExtensionId);
    $totalIncome += (float) ($totals['income'] ?? 0);
    $totalExpense += (float) ($totals['expense'] ?? 0);
}

$monthlyExpenseSt = $pdo->prepare("SELECT DATE_FORMAT(expense_date, '%Y-%m') ym, COALESCE(SUM(amount), 0) e FROM business_expenses WHERE company_id = ? AND archived_at IS NULL AND expense_date IS NOT NULL GROUP BY ym");
$monthlyExpenseSt->execute([$companyId]);
$expenseMap = [];
foreach ($monthlyExpenseSt->fetchAll(PDO::FETCH_ASSOC) as $monthRow) {
    $expenseMap[$monthRow['ym']] = (float) $monthRow['e'];
    $totalExpense += (float) $monthRow['e'];
}

$totalProfit = $totalIncome - $totalExpense;
$monthlyData = buildRentalMonthlyData($ownerRentals, $extensionsByRentalId, $collectionsByExtensionId);
foreach ($expenseMap as $monthKey => $expenseAmount) {
    if (!isset($monthlyData[$monthKey])) {
        $monthlyData[$monthKey] = [0, 0, 0, 0, 0];
    }
    $monthlyData[$monthKey][2] = $expenseAmount;
}
ksort($monthlyData);
foreach ($monthlyData as $monthKey => &$values) {
    $values[3] = ($values[1] ?? 0) + ($values[2] ?? 0);
    $values[4] = ($values[0] ?? 0) - ($values[3] ?? 0);
}
unset($values);

$monthlyProfitChart = [];
foreach ($monthlyData as $ym => $values) {
    $monthNumber = substr($ym, 5, 2);
    $label = $monthNames[$monthNumber] ?? $ym;
    $monthlyProfitChart[$label] = (float) ($values[4] ?? 0);
}

$currentMonthKey = date('Y-m');
$currentYear = date('Y');
$monthlyIncome = (float) ($monthlyData[$currentMonthKey][0] ?? 0);
$monthlyCarExpense = (float) ($monthlyData[$currentMonthKey][1] ?? 0);
$monthlyBizExpense = (float) ($monthlyData[$currentMonthKey][2] ?? 0);
$monthlyTotalExpense = (float) ($monthlyData[$currentMonthKey][3] ?? ($monthlyCarExpense + $monthlyBizExpense));
$monthlyProfit = (float) ($monthlyData[$currentMonthKey][4] ?? ($monthlyIncome - $monthlyTotalExpense));

$yearlyIncome = 0.0;
$yearlyExpense = 0.0;
$yearlyProfit = 0.0;
foreach ($monthlyData as $monthKey => $values) {
    if (strpos((string) $monthKey, $currentYear . '-') !== 0) {
        continue;
    }

    $yearlyIncome += (float) ($values[0] ?? 0);
    $yearlyExpense += (float) ($values[3] ?? 0);
    $yearlyProfit += (float) ($values[4] ?? 0);
}

$pageTitle = 'Anasayfa';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="home-page">
  <div class="home-hero mb-4">
    <div class="home-hero-copy">
      <div class="home-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Anasayfa</h2>
      <div class="home-hero-subtitle">Günlük operasyon, tahsilat ve kâr durumunu tek bakışta takip et.</div>
    </div>
  </div>

  <div class="row g-3 mb-4 home-stats">
    <div class="col-6 col-xl-3"><div class="stat-card bg-primary shadow-sm"><h6>Toplam Araç</h6><h2><?= $totalCars ?></h2><p class="mb-0">Müsait <?= $availableCars ?> / Kirada <?= $rentedCars ?> / Satıldı <?= $soldCars ?></p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-success shadow-sm"><h6>Bu Ay Gelir</h6><h2><?= money($monthlyIncome) ?></h2><p class="mb-0">Toplam kiralama <?= $totalRentals ?></p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-warning shadow-sm"><h6>Bu Ay Gider</h6><h2><?= money($monthlyTotalExpense) ?></h2><p class="mb-0">Genel gider dahil</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-dark shadow-sm"><h6>Bu Ay Net Kâr</h6><h2><?= money($monthlyProfit) ?></h2><p class="mb-0">Bu aya ait net sonuç</p></div></div>
  </div>

  <div class="card shadow-sm mb-4 home-summary-card">
    <div class="card-header">Genel Durum</div>
    <div class="card-body">
      <div class="home-summary-grid">
        <div class="summary-tile">
          <span class="summary-label">Aktif Araç</span>
          <strong><?= h((string) $activeCars) ?></strong>
        </div>
        <div class="summary-tile">
          <span class="summary-label">Kiradaki Araç</span>
          <strong><?= h((string) $rentedCars) ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4 home-summary-card">
    <div class="card-header">Bu Yılın Özeti</div>
    <div class="card-body">
      <div class="home-summary-grid">
        <div class="summary-tile">
          <span class="summary-label">Gelir</span>
          <strong class="text-success"><?= money($yearlyIncome) ?></strong>
        </div>
        <div class="summary-tile">
          <span class="summary-label">Masraf</span>
          <strong class="text-danger"><?= money($yearlyExpense) ?></strong>
        </div>
        <div class="summary-tile">
          <span class="summary-label">Net Kâr</span>
          <strong class="text-primary"><?= money($yearlyProfit) ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm home-chart-card">
    <div class="card-header">Aylık Net Kâr Grafiği</div>
    <div class="card-body">
      <div class="profit-list">
        <?php foreach ($monthlyProfitChart as $month => $profit): ?>
        <?php $width = min(100, (abs($profit) / 500000) * 100); if ($width > 0 && $width < 5) { $width = 5; } ?>
        <div class="profit-row">
          <div class="profit-row-head">
            <strong><?= h($month) ?></strong>
            <span><?= money($profit) ?></span>
          </div>
          <div class="progress home-progress" style="height: 18px;">
            <div class="progress-bar <?= $profit >= 0 ? 'bg-success' : 'bg-danger' ?>" role="progressbar" style="width:<?= $width ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
