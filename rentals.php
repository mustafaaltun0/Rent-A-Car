<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.view');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarArchiveSchema($pdo);
ensureExpenseArchiveSchema($pdo);
ensureCustomerCompanySchema($pdo);
$companyId = auth_current_company_id();
$canManageRentals = auth_can('rentals.manage');
$customerCompaniesEnabled = app_feature_customer_companies_enabled();

$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int) $_GET['month'] : null;
$year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : (int) date('Y');
$showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
$error = $_GET['error'] ?? '';
$status = $_GET['status'] ?? '';

$activeRentalSt = $pdo->prepare('SELECT DISTINCT car_id FROM rentals WHERE company_id = ? AND archived_at IS NULL AND completed = 0 AND car_id IS NOT NULL');
$activeRentalSt->execute([$companyId]);
$activeRentalCarIds = $activeRentalSt->fetchAll(PDO::FETCH_COLUMN);
$activeRentalMap = array_fill_keys(array_map('strval', $activeRentalCarIds), true);

$carsSt = $pdo->prepare('SELECT * FROM cars WHERE company_id = ? AND archived_at IS NULL ORDER BY brand, model');
$carsSt->execute([$companyId]);
$cars = $carsSt->fetchAll(PDO::FETCH_ASSOC);

$customerCompanies = $customerCompaniesEnabled ? getCustomerCompanies($pdo, $companyId) : [];

$sql = '
    SELECT
        r.*,
        c.brand,
        c.model,
        c.plate,
        cc.company_name AS customer_company_name,
        cc.is_active AS customer_company_active
    FROM rentals r
    LEFT JOIN cars c
        ON c.id = r.car_id
       AND c.company_id = r.company_id
    LEFT JOIN customer_companies cc
        ON cc.id = r.customer_company_id
       AND cc.company_id = r.company_id
    WHERE r.company_id = ?
';
$params = [$companyId];
if ($showArchived) {
    $sql .= ' AND r.archived_at IS NOT NULL';
} else {
    $sql .= ' AND r.archived_at IS NULL';
}
if (!$showAll && !$showArchived) {
    $sql .= ' AND r.completed = 0';
}
if ($month) {
    $sql .= ' AND MONTH(r.start_date) = ?';
    $params[] = $month;
}
if ($year) {
    $sql .= ' AND YEAR(r.start_date) = ?';
    $params[] = $year;
}
$sql .= ' ORDER BY r.completed ASC, r.id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$rentals = $st->fetchAll(PDO::FETCH_ASSOC);
$rentalsPagination = paginate_collection($rentals, 'rentals_page', 'rentals_per_page', 10, [10, 20, 50, 100]);
$rentals = $rentalsPagination['items'];

$reportRentalsSt = $pdo->prepare('SELECT * FROM rentals WHERE company_id = ? AND archived_at IS NULL AND start_date IS NOT NULL ORDER BY start_date');
$reportRentalsSt->execute([$companyId]);
$reportRentals = $reportRentalsSt->fetchAll(PDO::FETCH_ASSOC);
$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$receivableWarnings = buildRentalExtensionReceivableWarnings($reportRentals, $extensionsByRentalId, $collectionsByExtensionId, 1);
$receivableWarningsByRentalId = $receivableWarnings['by_rental_id'] ?? [];

$expensesSt = $pdo->prepare('SELECT expense_date, amount FROM business_expenses WHERE company_id = ? AND archived_at IS NULL');
$expensesSt->execute([$companyId]);
$expenses = $expensesSt->fetchAll(PDO::FETCH_ASSOC);

$monthNames = [
    '01' => 'Ocak',
    '02' => 'Subat',
    '03' => 'Mart',
    '04' => 'Nisan',
    '05' => 'Mayis',
    '06' => 'Haziran',
    '07' => 'Temmuz',
    '08' => 'Agustos',
    '09' => 'Eylul',
    '10' => 'Ekim',
    '11' => 'Kasim',
    '12' => 'Aralik',
];

$yearsSt = $pdo->prepare("SELECT DISTINCT YEAR(start_date) FROM rentals WHERE company_id = ? AND archived_at IS NULL AND start_date IS NOT NULL ORDER BY YEAR(start_date) DESC");
$yearsSt->execute([$companyId]);
$availableYears = array_map('intval', $yearsSt->fetchAll(PDO::FETCH_COLUMN));
$currentYear = (int) date('Y');
if (!in_array($currentYear, $availableYears, true)) {
    $availableYears[] = $currentYear;
}
rsort($availableYears);

$expenseMap = [];
foreach ($expenses as $expense) {
    if (empty($expense['expense_date'])) {
        continue;
    }

    $monthKey = date('Y-m', strtotime($expense['expense_date']));
    if (!isset($expenseMap[$monthKey])) {
        $expenseMap[$monthKey] = 0.0;
    }

    $expenseMap[$monthKey] += (float) $expense['amount'];
}

$monthly = buildRentalMonthlyData($reportRentals, $extensionsByRentalId, $collectionsByExtensionId);
foreach ($expenseMap as $monthKey => $officeExpense) {
    if (!isset($monthly[$monthKey])) {
        $monthly[$monthKey] = [0, 0, 0, 0, 0];
    }

    $monthly[$monthKey][2] = $officeExpense;
}

ksort($monthly);
foreach ($monthly as $monthKey => &$values) {
    $values[3] = $values[1] + $values[2];
    $values[4] = $values[0] - $values[3];
}
unset($values);

$pageTitle = 'Kiralamalar';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="rentals-page">
  <div class="rentals-hero mb-4">
    <div>
      <div class="rentals-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Kiralamalar</h2>
      <div class="rentals-hero-subtitle">Aktif kiralari, uzatmalari, teslimleri ve bekleyen tahsilatlari tek yerden yonet.</div>
    </div>
    <div class="rentals-hero-actions">
      <?php if ($canManageRentals): ?>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#rentalModal" data-mode="create">Yeni Kiralama Ekle</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error === 'car_unavailable'): ?>
  <div class="alert alert-danger">Sectigin arac su anda aktif kirada. Sadece bosta olan araclar kiralanabilir.</div>
  <?php elseif ($error === 'rental_reopen_conflict'): ?>
  <div class="alert alert-danger">Bu kiralama geri alinamadi. Arac su anda baska bir aktif kiralamada gorunuyor.</div>
  <?php elseif ($error === 'invalid_customer_company'): ?>
  <div class="alert alert-danger">Secilen kurumsal musteri bu firmaya ait degil ya da pasif durumda.</div>
  <?php endif; ?>
  <?php if ($status === 'archived'): ?>
  <div class="alert alert-success">Kiralama kaydi arsive alindi.</div>
  <?php endif; ?>
  <?php if ($status === 'restored'): ?>
  <div class="alert alert-success">Kiralama kaydi arsivden geri yuklendi.</div>
  <?php endif; ?>
  <?php if ($status === 'delete_error'): ?>
  <div class="alert alert-danger">Kiralama arsivlenirken beklenmeyen bir sorun olustu.</div>
  <?php endif; ?>
  <?php if ($error === 'rental_restore_conflict'): ?>
  <div class="alert alert-danger">Bu kiralama geri yuklenemedi. Arac su anda baska bir aktif kiralamada gorunuyor.</div>
  <?php endif; ?>
  <?php if ($status === 'extension_saved'): ?>
  <div class="alert alert-success">Kiralama uzatildi. Tahsilat durumu secimine gore kayit olusturuldu.</div>
  <?php endif; ?>
  <?php if (!empty($receivableWarnings['items'])): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header rentals-card-header">Bekleyen Uzatma Tahsilatlari</div>
    <div class="card-body">
      <div class="warning-list">
        <?php foreach ($receivableWarnings['items'] as $warning): ?>
        <div class="warning-item <?= $warning['level'] === 'danger' ? 'is-danger' : '' ?>">
          <span class="status-dot status-<?= $warning['level'] === 'danger' ? 'danger' : 'warning' ?>"></span>
          <span><?= h($warning['message']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header rentals-card-header"><?= $showArchived ? 'Arsivlenmis Kiralamalar' : ($showAll ? 'Tum Kiralamalar' : 'Aktif Kiralamalar') ?></div>
    <div class="card-body border-bottom bg-light-subtle rentals-switchbar">
      <?php if ($showArchived): ?>
        <a href="rentals.php<?= $month || $year || $showAll ? '?' . http_build_query(array_filter(['month' => $month, 'year' => $year, 'show_all' => $showAll ? 1 : null])) : '' ?>" class="btn btn-outline-dark btn-sm">Normal Listeye Don</a>
      <?php elseif ($showAll): ?>
        <a href="rentals.php<?= $month || $year ? '?' . http_build_query(array_filter(['month' => $month, 'year' => $year])) : '' ?>" class="btn btn-outline-dark btn-sm">Aktif Kiralamalari Gor</a>
      <?php else: ?>
        <a href="rentals.php?<?= http_build_query(array_filter(['show_all' => 1, 'month' => $month, 'year' => $year])) ?>" class="btn btn-outline-dark btn-sm">Tum Kiralamalari Gor</a>
      <?php endif; ?>
      <?php if (!$showArchived): ?>
      <a href="rentals.php?<?= http_build_query(array_filter(['show_archived' => 1, 'month' => $month, 'year' => $year])) ?>" class="btn btn-outline-secondary btn-sm">Arsivdekileri Gor</a>
      <?php endif; ?>
    </div>
    <div class="card-body border-bottom rentals-filter-panel">
      <div class="rentals-filter-title">Filtreleme</div>
      <form method="get" class="row g-3 align-items-end">
        <?php if ($showAll): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
        <?php if ($showArchived): ?><input type="hidden" name="show_archived" value="1"><?php endif; ?>
        <div class="col-md-4">
          <label class="form-label">Yil</label>
          <select name="year" class="form-select">
            <option value="">Tum Yillar</option>
            <?php foreach ($availableYears as $availableYear): ?>
            <option value="<?= h($availableYear) ?>" <?= (int) $year === (int) $availableYear ? 'selected' : '' ?>><?= h($availableYear) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Ay</label>
          <select name="month" class="form-select">
            <option value="">Tum Aylar</option>
            <?php foreach ($monthNames as $monthValue => $monthLabel): ?>
            <option value="<?= (int) $monthValue ?>" <?= (int) $month === (int) $monthValue ? 'selected' : '' ?>><?= h($monthLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button class="btn btn-primary w-100">Filtrele</button>
        </div>
      </form>
    </div>
    <div class="card-body">
      <div class="mobile-record-list d-grid d-lg-none">
        <?php foreach ($rentals as $r): ?>
        <?php
          $startValue = $r['start_date'] ? date('Y-m-d\TH:i', strtotime($r['start_date'])) : '';
          $endValue = $r['end_date'] ? date('Y-m-d\TH:i', strtotime($r['end_date'])) : '';
          $totals = getRentalTotals($r, $extensionsByRentalId, $collectionsByExtensionId);
          $extensionCount = count($extensionsByRentalId[(int) $r['id']] ?? []);
          $rentalReceivableWarnings = $receivableWarningsByRentalId[(int) $r['id']] ?? [];
          $topReceivableWarning = $rentalReceivableWarnings[0] ?? null;
          $mobileStatusClass = $showArchived ? 'secondary' : ((int) $r['completed'] === 1 ? 'dark' : 'success');
          $mobileStatusLabel = $showArchived ? 'Arsivde' : ((int) $r['completed'] === 1 ? 'Tamamlandi' : 'Aktif');
        ?>
        <div class="mobile-record-card <?= $showArchived ? 'is-archived' : '' ?>">
          <div class="mobile-record-card-head">
            <div class="mobile-record-card-title">
              <strong><?= h($r['customer_name']) ?></strong>
              <small><?= h(trim(($r['brand'] ?? 'Silinmis Arac') . ' ' . ($r['model'] ?? ''))) ?><?php if (!empty($r['plate'])): ?> / <?= h($r['plate']) ?><?php endif; ?></small>
            </div>
            <div class="mobile-record-card-badges">
              <span class="badge bg-<?= h($mobileStatusClass) ?>"><?= h($mobileStatusLabel) ?></span>
              <?php if ($extensionCount > 0): ?><span class="badge text-bg-light"><?= h((string) $extensionCount) ?> uzatma</span><?php endif; ?>
            </div>
          </div>
          <div class="mobile-record-grid">
            <div><span>Bitis</span><strong><?= dt($r['end_date']) ?></strong></div>
            <div><span>Tahsil</span><strong><?= money($totals['income']) ?></strong></div>
            <div><span>Bekleyen</span><strong><?= money($totals['pending_income'] ?? 0) ?></strong></div>
            <div><span>Telefon</span><strong><?= h($r['customer_phone'] ?: '-') ?></strong></div>
            <?php if ($customerCompaniesEnabled && !empty($r['customer_company_name'])): ?>
            <div class="full"><span>Kurumsal Musteri</span><strong><?= h($r['customer_company_name']) ?><?= (int) ($r['customer_company_active'] ?? 1) === 1 ? '' : ' / Pasif' ?></strong></div>
            <?php endif; ?>
            <?php if ($topReceivableWarning): ?>
            <div class="full"><span>Uyari</span><strong class="<?= $topReceivableWarning['level'] === 'danger' ? 'text-danger' : 'text-warning' ?>"><?= h($topReceivableWarning['short_label']) ?> / <?= money($topReceivableWarning['pending_amount']) ?></strong></div>
            <?php endif; ?>
          </div>
          <div class="mobile-record-actions">
            <a href="rental_detail.php?id=<?= h((string) $r['id']) ?>" class="action-btn action-info" title="Detay" aria-label="Detay">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 5.7 9.8 6 .2.3.2.7 0 1-.2.3-4.3 6-9.8 6S2.4 12.3 2.2 12c-.2-.3-.2-.7 0-1 .2-.3 4.3-6 9.8-6Zm0 2C8.4 7 5.4 10.2 4.3 11.5 5.4 12.8 8.4 16 12 16s6.6-3.2 7.7-4.5C18.6 10.2 15.6 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Zm0 2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6Z"/></svg>
            </a>
            <?php if ($canManageRentals): ?>
            <button
              class="action-btn action-warning"
              type="button"
              title="Duzenle"
              aria-label="Duzenle"
              data-bs-toggle="modal"
              data-bs-target="#rentalModal"
              data-mode="edit"
              data-id="<?= h($r['id']) ?>"
              data-customer_company_id="<?= h($r['customer_company_id']) ?>"
              data-customer_name="<?= h($r['customer_name']) ?>"
              data-customer_phone="<?= h($r['customer_phone']) ?>"
              data-customer_identity_no="<?= h($r['customer_identity_no']) ?>"
              data-car_id="<?= h($r['car_id']) ?>"
              data-start_date="<?= h($startValue) ?>"
              data-end_date="<?= h($endValue) ?>"
              data-departure-km="<?= h($r['departure_km']) ?>"
              data-income="<?= h($r['income']) ?>"
              data-expense="<?= h($r['expense']) ?>"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
            </button>
            <?php endif; ?>
            <?php if ($canManageRentals && !$showArchived && (int) $r['completed'] === 0): ?>
            <button class="action-btn action-primary" type="button" title="Uzat" aria-label="Uzat" data-bs-toggle="modal" data-bs-target="#extendRentalModal" data-rental_id="<?= h($r['id']) ?>" data-customer_name="<?= h($r['customer_name']) ?>" data-current_end_date="<?= h($endValue) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>
            </button>
            <?php endif; ?>
            <?php if ($canManageRentals && !$showArchived && (int) $r['completed'] === 0): ?>
            <form action="actions/rental_complete.php" method="post" data-complete-form>
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h($r['id']) ?>">
              <input type="hidden" name="return_km" value="">
              <button type="submit" class="action-btn action-success" title="Teslim Al" aria-label="Teslim Al" onclick="const departureKm = <?= json_encode($r['departure_km']) ?> ? parseInt(<?= json_encode($r['departure_km']) ?>, 10) : NaN; const promptText = Number.isFinite(departureKm) ? 'Donus KM girin. Cikis KM: ' + departureKm : 'Donus KM girin.'; const result = window.prompt(promptText, ''); if (result === null) { return false; } const cleanedValue = result.replace(/\\D/g, ''); if (!cleanedValue) { window.alert('Lutfen gecerli bir donus KM girin.'); return false; } if (Number.isFinite(departureKm) && parseInt(cleanedValue, 10) < departureKm) { window.alert('Donus KM, cikis KM degerinden kucuk olamaz.'); return false; } this.form.querySelector('[name=&quot;return_km&quot;]').value = cleanedValue; return true;">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.2 16.6-4.3-4.3 1.4-1.4 2.9 2.9 8.5-8.5 1.4 1.4-9.9 9.9Z"/></svg>
              </button>
            </form>
            <?php elseif ($canManageRentals && !$showArchived): ?>
            <form action="actions/rental_reopen.php" method="post">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h($r['id']) ?>">
              <button class="action-btn action-secondary" type="submit" title="Geri Al" aria-label="Geri Al" data-confirm="Bu kiralamayi tekrar aktif hale getirmek istiyor musunuz?">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 6 4 12l6 6 1.4-1.4L7.8 13H20v-2H7.8l3.6-3.6L10 6Z"/></svg>
              </button>
            </form>
            <?php endif; ?>
            <?php if ($canManageRentals && !$showArchived): ?>
            <form action="actions/rental_delete.php" method="post">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h($r['id']) ?>">
              <button class="action-btn action-danger" type="submit" title="Arsivle" aria-label="Arsivle" data-confirm="Bu kiralama kaydini arsive almak istediginize emin misiniz?">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v4H4V5Zm1 6h14v8H5v-8Zm3 2v2h8v-2H8Z"/></svg>
              </button>
            </form>
            <?php elseif ($canManageRentals && $showArchived): ?>
            <form action="actions/rental_restore.php" method="post">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h($r['id']) ?>">
              <button class="action-btn action-secondary" type="submit" title="Geri Yukle" aria-label="Geri Yukle" data-confirm="Bu kiralama kaydini arsivden geri yuklemek istiyor musunuz?">
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
        <tr><th>Musteri</th><th>Arac</th><th>Bitis</th><th>Tahsil Edilen</th><th>Islem</th></tr>
        <?php foreach ($rentals as $r): ?>
        <?php
          $startValue = $r['start_date'] ? date('Y-m-d\TH:i', strtotime($r['start_date'])) : '';
          $endValue = $r['end_date'] ? date('Y-m-d\TH:i', strtotime($r['end_date'])) : '';
          $totals = getRentalTotals($r, $extensionsByRentalId, $collectionsByExtensionId);
          $extensionCount = count($extensionsByRentalId[(int) $r['id']] ?? []);
          $rentalReceivableWarnings = $receivableWarningsByRentalId[(int) $r['id']] ?? [];
          $topReceivableWarning = $rentalReceivableWarnings[0] ?? null;
        ?>
        <tr class="clickable-row" onclick="window.location.href='rental_detail.php?id=<?= h($r['id']) ?>'">
          <td>
            <?= h($r['customer_name']) ?>
            <?php if ($customerCompaniesEnabled && !empty($r['customer_company_name'])): ?>
            <div><small class="text-muted"><?= h($r['customer_company_name']) ?><?= (int) ($r['customer_company_active'] ?? 1) === 1 ? '' : ' / Pasif' ?></small></div>
            <?php endif; ?>
            <?php if (($totals['pending_income'] ?? 0) > 0): ?>
            <div><small class="text-warning">Bekleyen tahsilat: <?= money($totals['pending_income']) ?></small></div>
            <?php endif; ?>
            <?php if ($topReceivableWarning): ?>
            <div><small class="<?= $topReceivableWarning['level'] === 'danger' ? 'text-danger' : 'text-warning' ?>"><?= h($topReceivableWarning['short_label']) ?>: <?= money($topReceivableWarning['pending_amount']) ?></small></div>
            <?php endif; ?>
            <?php if ($extensionCount > 0): ?>
            <div><small class="text-muted"><?= $extensionCount ?> uzatma var</small></div>
            <?php endif; ?>
          </td>
          <td><?= h(trim(($r['brand'] ?? 'Silinmis Arac') . ' ' . ($r['model'] ?? ''))) ?></td>
          <td><?= dt($r['end_date']) ?></td>
          <td><?= money($totals['income']) ?></td>
          <td class="table-actions-cell">
            <?php if ($canManageRentals): ?>
            <div class="action-group">
              <button
                class="action-btn action-warning"
                type="button"
                title="Duzenle"
                aria-label="Duzenle"
                onclick="event.stopPropagation();"
                data-bs-toggle="modal"
                data-bs-target="#rentalModal"
                data-mode="edit"
                data-id="<?= h($r['id']) ?>"
                data-customer_company_id="<?= h($r['customer_company_id']) ?>"
                data-customer_name="<?= h($r['customer_name']) ?>"
                data-customer_phone="<?= h($r['customer_phone']) ?>"
                data-customer_identity_no="<?= h($r['customer_identity_no']) ?>"
                data-car_id="<?= h($r['car_id']) ?>"
                data-start_date="<?= h($startValue) ?>"
                data-end_date="<?= h($endValue) ?>"
                data-departure-km="<?= h($r['departure_km']) ?>"
                data-income="<?= h($r['income']) ?>"
                data-expense="<?= h($r['expense']) ?>"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </button>
              <?php if (!$showArchived && (int) $r['completed'] === 0): ?>
              <button class="action-btn action-primary" type="button" title="Uzat" aria-label="Uzat" onclick="event.stopPropagation();" data-bs-toggle="modal" data-bs-target="#extendRentalModal" data-rental_id="<?= h($r['id']) ?>" data-customer_name="<?= h($r['customer_name']) ?>" data-current_end_date="<?= h($endValue) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>
              </button>
              <?php endif; ?>
              <?php if (!$showArchived): ?>
              <form action="actions/rental_delete.php" method="post" class="d-inline" onclick="event.stopPropagation();">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <button class="action-btn action-danger" type="submit" title="Arsivle" aria-label="Arsivle" data-confirm="Bu kiralama kaydini arsive almak istediginize emin misiniz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v4H4V5Zm1 6h14v8H5v-8Zm3 2v2h8v-2H8Z"/></svg>
                </button>
              </form>
              <?php else: ?>
              <form action="actions/rental_restore.php" method="post" class="d-inline" onclick="event.stopPropagation();">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <button class="action-btn action-secondary" type="submit" title="Geri Yukle" aria-label="Geri Yukle" data-confirm="Bu kiralama kaydini arsivden geri yuklemek istiyor musunuz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.3 10H3l3.5-3.5L10 15H7.8A5 5 0 1 0 12 7h-1V5h1Z"/></svg>
                </button>
              </form>
              <?php endif; ?>
              <?php if (!$showArchived && (int) $r['completed'] === 0): ?>
              <form action="actions/rental_complete.php" method="post" class="d-inline" onclick="event.stopPropagation();" data-complete-form>
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <input type="hidden" name="return_km" value="">
                <button type="submit" class="action-btn action-success" title="Teslim Edildi" aria-label="Teslim Edildi" onclick="const departureKm = <?= json_encode($r['departure_km']) ?> ? parseInt(<?= json_encode($r['departure_km']) ?>, 10) : NaN; const promptText = Number.isFinite(departureKm) ? 'Donus KM girin. Cikis KM: ' + departureKm : 'Donus KM girin.'; const result = window.prompt(promptText, ''); if (result === null) { return false; } const cleanedValue = result.replace(/\\D/g, ''); if (!cleanedValue) { window.alert('Lutfen gecerli bir donus KM girin.'); return false; } if (Number.isFinite(departureKm) && parseInt(cleanedValue, 10) < departureKm) { window.alert('Donus KM, cikis KM degerinden kucuk olamaz.'); return false; } this.form.querySelector('[name=&quot;return_km&quot;]').value = cleanedValue; return true;">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.2 16.6-4.1-4.1 1.4-1.4 2.7 2.7 8.3-8.3 1.4 1.4-9.7 9.7Z"/></svg>
                </button>
              </form>
              <?php elseif (!$showArchived): ?>
              <form action="actions/rental_reopen.php" method="post" class="d-inline" onclick="event.stopPropagation();">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <button class="action-btn action-secondary" type="submit" title="Geri Al" aria-label="Geri Al" data-confirm="Bu kiralamayi tekrar aktif hale getirmek istiyor musunuz?">
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
      <?= pagination_render($rentalsPagination, ['item_label' => 'kiralama']) ?>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Aylik Performans Ozeti</span>
      <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#rentalMonthlyReport" aria-expanded="false" aria-controls="rentalMonthlyReport">Ac / Kapat</button>
    </div>
    <div class="collapse" id="rentalMonthlyReport">
      <div class="card-body table-responsive">
        <table class="table table-bordered table-striped">
          <tr><th>Ay</th><th>Gelir</th><th>Dukkan Gideri</th><th>Toplam Masraf</th><th>Net Kar</th></tr>
          <?php foreach ($monthly as $m => $v): ?>
          <?php $monthNumber = substr($m, 5, 2); $monthLabel = $monthNames[$monthNumber] ?? $m; ?>
          <tr>
            <td><?= h($monthLabel) ?></td>
            <td><?= money($v[0]) ?></td>
            <td><?= money($v[2]) ?></td>
            <td><?= money($v[3]) ?></td>
            <td><?= money($v[4]) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <?php if ($canManageRentals): ?>
  <div class="modal fade" id="rentalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="rentalModalLabel">Yeni Kiralama Ekle</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form action="actions/rental_save.php" method="post" data-modal-form="rental">
          <div class="modal-body">
            <?= auth_csrf_input() ?>
            <input type="hidden" name="id" value="">
            <div class="row g-3">
              <?php if ($customerCompaniesEnabled): ?>
              <div class="col-md-6">
                <label class="form-label">Kurumsal Musteri</label>
                <select name="customer_company_id" class="form-select">
                  <option value="">Bireysel / Secilmedi</option>
                  <?php foreach ($customerCompanies as $customerCompany): ?>
                  <?php $isInactiveCustomer = (int) ($customerCompany['is_active'] ?? 0) !== 1; ?>
                  <option value="<?= h($customerCompany['id']) ?>" data-inactive="<?= $isInactiveCustomer ? '1' : '0' ?>" <?= $isInactiveCustomer ? 'disabled' : '' ?>>
                    <?= h($customerCompany['company_name']) ?><?= $isInactiveCustomer ? ' (Pasif)' : '' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <div class="col-md-6">
                <label class="form-label">Musteri Adi</label>
                <input name="customer_name" class="form-control" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Telefon</label>
                <input name="customer_phone" class="form-control">
              </div>
              <div class="col-md-6">
                <label class="form-label">TC Kimlik No</label>
                <input name="customer_identity_no" class="form-control" inputmode="numeric" maxlength="11" pattern="[0-9]{0,11}">
              </div>
              <div class="col-md-6">
                <label class="form-label">Arac</label>
                <select name="car_id" class="form-select" required>
                  <?php foreach ($cars as $c): ?>
                  <?php $isBusy = isset($activeRentalMap[(string) $c['id']]); ?>
                  <option value="<?= h($c['id']) ?>" data-busy="<?= $isBusy ? '1' : '0' ?>" <?= $isBusy ? 'disabled' : '' ?>>
                    <?= h($c['brand'] . ' ' . $c['model'] . ' - ' . $c['plate']) ?><?= $isBusy ? ' (Kirada)' : '' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Baslangic</label>
                <input name="start_date" type="datetime-local" class="form-control">
              </div>
              <div class="col-md-6">
                <label class="form-label">Kira Suresi (Gun)</label>
                <input name="rental_days" type="number" min="1" class="form-control" placeholder="Ornek: 5">
              </div>
              <div class="col-md-6">
                <label class="form-label">Bitis</label>
                <input name="end_date" type="datetime-local" class="form-control">
              </div>
              <div class="col-md-6">
                <label class="form-label">Cikis KM</label>
                <input name="departure_km" type="text" inputmode="numeric" class="form-control" placeholder="Ornek: 100.000 km" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Ilk Gelir</label>
                <input name="income" type="number" step="0.01" class="form-control">
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

  <div class="modal fade" id="extendRentalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="extendRentalModalLabel">Kiralama Uzat</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form action="actions/rental_extend.php" method="post">
          <div class="modal-body">
            <?= auth_csrf_input() ?>
            <input type="hidden" name="rental_id" value="">
            <div class="mb-3">
              <label class="form-label">Musteri</label>
              <input name="customer_name_preview" class="form-control" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label">Mevcut Bitis</label>
              <input name="current_end_date_preview" type="datetime-local" class="form-control" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label">Uzatma Suresi (Gun)</label>
              <input name="extension_days" type="number" min="1" class="form-control" placeholder="Ornek: 5">
            </div>
            <div class="mb-3">
              <label class="form-label">Yeni Bitis Tarihi</label>
              <input name="new_end_date" type="datetime-local" class="form-control" required>
            </div>
              <div class="mb-3">
                <label class="form-label">Toplam Uzatma Bedeli</label>
                <input name="additional_income" type="number" step="0.01" class="form-control" required>
              </div>
              <div class="mb-3">
                <div class="form-check form-switch">
                  <input name="custom_collection_plan" value="1" class="form-check-input" type="checkbox" id="extendCustomCollectionPlan">
                  <label class="form-check-label" for="extendCustomCollectionPlan">Parcali / eksik tahsilat girecegim</label>
                </div>
                <div class="form-text">Bu alani acmazsan sistem toplam uzatma bedelinin tamamini tahsil edildi kabul eder.</div>
              </div>
              <div class="border rounded p-3 mb-3 d-none" data-extension-collection-plan>
                <div class="mb-3">
                  <label class="form-label">Simdi Tahsil Edilen</label>
                  <input name="initial_collected_amount" type="number" step="0.01" min="0" class="form-control" value="">
                  <div class="form-text">Buraya yazdigin tutar aninda gelir sayilir ve anasayfa, kiralamalar, arac raporlari ile aylik/yillik toplamlara hemen yansir.</div>
                </div>
                <div class="mb-0">
                  <label class="form-label">Kalan Tahsilat</label>
                  <input name="remaining_amount_preview" type="text" class="form-control" value="0 TL" readonly>
                  <div class="form-text">Ornek: toplam uzatma 24.500 TL, simdi tahsil edilen 24.000 TL ise sistem kalan 500 TL'yi bekleyen tahsilat olarak takip eder.</div>
                  <input name="payment_status" type="hidden" value="collected">
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Ek Masraf</label>
                <input name="additional_expense" type="number" step="0.01" class="form-control" value="0">
              </div>
              <div class="mb-3 d-none" data-extension-due-date-wrapper>
                <label class="form-label">Beklenen Tahsilat Tarihi</label>
                <input name="payment_due_date" type="datetime-local" class="form-control">
                <div class="form-text">Sadece kalan tutar varsa kullanilir. Hepsi simdi tahsil edildiyse sistem bu alani bos birakir.</div>
              </div>
            <div class="mb-3">
              <label class="form-label">Not</label>
              <input name="note" class="form-control" placeholder="Ornek: 10 Haziran'a kadar eski fiyat, sonrasinda yeni fiyat">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            <button class="btn btn-primary" type="submit">Uzatmayi Kaydet</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
