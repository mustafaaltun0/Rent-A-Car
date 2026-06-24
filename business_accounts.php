<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('ledger.view');

ensureBusinessAccountsSchema($pdo);
ensureCarArchiveSchema($pdo);
ensureCarPhotoSchema($pdo);

$companyId = auth_current_company_id();
$canManageLedger = auth_can('ledger.manage');
$canCreateLedgerEntry = $canManageLedger || auth_can('ledger.entry.create');
$canUpdateLedgerEntry = $canManageLedger || auth_can('ledger.entry.update');
$canDeleteLedgerEntry = $canManageLedger || auth_can('ledger.entry.delete');

$carsSt = $pdo->prepare('SELECT id, plate, brand, model, photo_path, photo_position_x, photo_position_y, photo_focus_x, photo_focus_y FROM cars WHERE company_id = ? AND archived_at IS NULL ORDER BY brand, model');
$carsSt->execute([$companyId]);
$cars = $carsSt->fetchAll(PDO::FETCH_ASSOC);

$partners = getBusinessAccountPartners($pdo, $companyId);
$openPeriod = getOpenBusinessAccountPeriod($pdo, $companyId);
$selectedPeriodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : (int) $openPeriod['id'];
$entryStatus = $_GET['entry_status'] ?? '';
$highlightEntryId = isset($_GET['entry_id']) ? (int) $_GET['entry_id'] : 0;

$periodSt = $pdo->prepare('SELECT * FROM ledger_periods WHERE id = ? AND company_id = ?');
$periodSt->execute([$selectedPeriodId, $companyId]);
$selectedPeriod = $periodSt->fetch(PDO::FETCH_ASSOC) ?: $openPeriod;
$isViewingClosedPeriod = ((int) $selectedPeriod['id'] !== (int) $openPeriod['id']) || (($selectedPeriod['status'] ?? 'OPEN') === 'CLOSED');

$entrySt = $pdo->prepare("
    SELECT e.*, p.name AS partner_name, c.id AS linked_car_id, c.brand AS linked_car_brand, c.model AS linked_car_model, c.plate AS linked_car_plate, c.photo_path AS linked_car_photo_path, c.photo_position_x AS linked_car_photo_position_x, c.photo_position_y AS linked_car_photo_position_y, c.photo_focus_x AS linked_car_photo_focus_x, c.photo_focus_y AS linked_car_photo_focus_y
    FROM ledger_entries e
    LEFT JOIN ledger_partners p ON p.id = e.partner_id AND p.company_id = e.company_id
    LEFT JOIN cars c ON c.id = e.car_id AND c.company_id = e.company_id
    WHERE e.period_id = ? AND e.company_id = ?
    ORDER BY e.entry_date DESC, e.id DESC
");
$entrySt->execute([(int) $selectedPeriod['id'], $companyId]);
$entries = $entrySt->fetchAll(PDO::FETCH_ASSOC);

$summary = buildBusinessAccountSummary($partners, $entries, (float) ($selectedPeriod['manual_shared_income'] ?? 0));
$firmBalanceTotal = (float) ($summary['net_pool'] ?? 0);
$firmBalanceClass = $firmBalanceTotal > 0 ? 'is-positive' : ($firmBalanceTotal < 0 ? 'is-negative' : 'is-neutral');

$closedPeriodsSt = $pdo->prepare("SELECT * FROM ledger_periods WHERE company_id = ? AND status='CLOSED' ORDER BY settled_at DESC, id DESC LIMIT 10");
$closedPeriodsSt->execute([$companyId]);
$closedPeriods = $closedPeriodsSt->fetchAll(PDO::FETCH_ASSOC);

$closedSummaries = [];
foreach ($closedPeriods as $period) {
    $entrySt->execute([(int) $period['id'], $companyId]);
    $periodEntries = $entrySt->fetchAll(PDO::FETCH_ASSOC);
    $closedSummaries[(int) $period['id']] = [
        'entries' => $periodEntries,
        'summary' => buildBusinessAccountSummary($partners, $periodEntries, (float) ($period['manual_shared_income'] ?? 0)),
    ];
}

$partnersPagination = paginate_collection($partners, 'partners_page', 'partners_per_page', 10, [10, 20, 50, 100]);
$visiblePartners = $partnersPagination['items'];
$settlementPagination = paginate_collection($summary['partners'], 'settlement_page', 'settlement_per_page', 10, [10, 20, 50, 100]);
$visibleSettlementPartners = $settlementPagination['items'];
$entriesPagination = paginate_collection($entries, 'entries_page', 'entries_per_page', 10, [10, 20, 50, 100]);
$visibleEntries = $entriesPagination['items'];
$periodsPagination = paginate_collection($closedPeriods, 'periods_page', 'periods_per_page', 10, [10, 20, 50, 100]);
$visibleClosedPeriods = $periodsPagination['items'];

$pageSignals = [];
if ($entryStatus === 'saved') {
    $pageSignals[] = ['type' => 'success', 'text' => 'Kayıt eklendi.'];
} elseif ($entryStatus === 'invalid') {
    $pageSignals[] = ['type' => 'danger', 'text' => 'Kayıt eklenemedi. Alanları kontrol et.'];
} elseif ($entryStatus === 'error') {
    $pageSignals[] = ['type' => 'danger', 'text' => 'Kayıt veritabanına yazılamadı.'];
}

$pageTitle = 'Gelir Gider';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="business-account-page">
<div class="business-hero mb-4">
  <div>
    <div class="business-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
    <h2 class="mb-2">Gelir Gider</h2>
  </div>
</div>

<div class="business-toolbar-actions mb-4">
  <?php if ($canCreateLedgerEntry && !$isViewingClosedPeriod): ?>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#businessEntryModal" data-mode="create" data-type="income">Gelir Ekle</button>
  <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#businessEntryModal" data-mode="create" data-type="expense">Gider Ekle</button>
  <?php endif; ?>
  <?php if ($canManageLedger): ?>
  <button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#businessPartnerModal" data-mode="create">Kisi Ekle</button>
  <?php endif; ?>
  <?php if ($isViewingClosedPeriod): ?>
  <a href="business_accounts.php" class="btn btn-outline-secondary">Açık Hesaba Dön</a>
  <?php if ($canManageLedger): ?>
  <form action="actions/business_period_delete.php" method="post" class="d-inline">
    <?= auth_csrf_input() ?>
    <input type="hidden" name="id" value="<?= (int) $selectedPeriod['id'] ?>">
    <button class="btn btn-danger" type="submit" data-confirm="Bu geçmiş dönemi ve içindeki tüm kayıtları silmek istediğinize emin misiniz?">Dönemi Sil</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="business-page-topbar mb-4">
  <div class="business-period-badge">
    <span class="business-period-label"><?= $isViewingClosedPeriod ? 'Geçmiş Dönem' : 'Açık Dönem' ?></span>
    <strong><?= dt($selectedPeriod['started_at'] ?? date('Y-m-d H:i:s')) ?></strong>
  </div>
  <?php if (!empty($pageSignals)): ?>
  <div class="business-inline-status">
    <?php foreach ($pageSignals as $signal): ?>
    <span class="business-inline-chip is-<?= h($signal['type']) ?>"><?= h($signal['text']) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card shadow-sm mb-4 partners-card">
  <button class="card-header partners-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#partnersCollapse" aria-expanded="false" aria-controls="partnersCollapse">
    <span>Kisiler</span>
    <span class="partners-toggle-meta"><?= count($partners) ?> kayıt</span>
  </button>
  <div id="partnersCollapse" class="collapse">
    <div class="card-body">
      <div class="mobile-record-list d-grid d-lg-none mb-3">
        <?php if (empty($partners)): ?>
        <div class="dashboard-alert-empty">Henüz kişi eklenmemiş.</div>
        <?php endif; ?>
        <?php foreach ($visiblePartners as $partner): ?>
        <div class="mobile-record-card">
          <div class="mobile-record-card-head">
            <div class="mobile-record-card-title">
              <strong><?= h($partner['name']) ?></strong>
              <small><?= (int) $partner['is_settlement_partner'] === 1 ? 'Paylaşıma Dahil' : 'Sadece Takip' ?></small>
            </div>
            <div class="mobile-record-card-badges">
              <span class="badge <?= (int) $partner['is_settlement_partner'] === 1 ? 'bg-dark' : 'text-bg-light' ?>"><?= (int) $partner['is_settlement_partner'] === 1 ? 'Paylaşım' : 'Takip' ?></span>
            </div>
          </div>
          <?php if ($canManageLedger): ?>
          <div class="mobile-record-actions">
            <button class="action-btn action-warning" type="button" title="Düzenle" aria-label="Düzenle" data-bs-toggle="modal" data-bs-target="#businessPartnerModal" data-mode="edit" data-id="<?= h($partner['id']) ?>" data-name="<?= h($partner['name']) ?>" data-is_settlement_partner="<?= h($partner['is_settlement_partner']) ?>" data-sort_order="<?= h($partner['sort_order']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
            </button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="table-responsive d-none d-lg-block">
      <table class="table table-bordered table-striped align-middle mb-0">
        <tr><th>Ad Soyad</th><th>Hesap Rolü</th><th>İşlem</th></tr>
        <?php if (empty($partners)): ?>
        <tr><td colspan="3" class="text-center text-muted">Henüz kişi eklenmemiş.</td></tr>
        <?php endif; ?>
        <?php foreach ($visiblePartners as $partner): ?>
        <tr>
          <td><?= h($partner['name']) ?></td>
          <td><?= (int) $partner['is_settlement_partner'] === 1 ? 'Paylasima Dahil' : 'Sadece Takip' ?></td>
          <td class="table-actions-cell">
            <?php if ($canManageLedger): ?>
            <div class="action-group">
              <button class="action-btn action-warning" type="button" title="Duzenle" aria-label="Duzenle" data-bs-toggle="modal" data-bs-target="#businessPartnerModal" data-mode="edit" data-id="<?= h($partner['id']) ?>" data-name="<?= h($partner['name']) ?>" data-is_settlement_partner="<?= h($partner['is_settlement_partner']) ?>" data-sort_order="<?= h($partner['sort_order']) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </button>
            </div>
            <?php else: ?>
            <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?= pagination_render($partnersPagination, ['item_label' => 'kisi']) ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4 summary-grid">
  <div class="col-md-6 col-xl-3"><div class="stat-card bg-primary shadow-sm finance-kpi-card"><h6>Toplam Gelir</h6><h3><?= money($summary['total_income']) ?></h3><p>Paylasim <?= money($summary['pooled_income_total'] ?? 0) ?> / Takip <?= money($summary['tracked_income_total'] ?? 0) ?></p></div></div>
  <div class="col-md-6 col-xl-3"><div class="stat-card bg-danger shadow-sm finance-kpi-card"><h6>Toplam Gider</h6><h3><?= money($summary['total_expense']) ?></h3><p>Paylasim <?= money($summary['pooled_expense_total'] ?? 0) ?> / Takip <?= money($summary['tracked_expense_total'] ?? 0) ?></p></div></div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card bg-dark shadow-sm finance-kpi-card">
      <h6>Kisi Basi Pay</h6>
      <h3><?= money($summary['share_per_partner']) ?></h3>
      <p><?= h((string) ($summary['settlement_partner_count'] ?? 0)) ?> kisiye esit dagitim</p>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card shadow-sm finance-kpi-card finance-kpi-balance <?= h($firmBalanceClass) ?>">
      <h6>Firma Bakiyesi</h6>
      <h3><?= money(abs($firmBalanceTotal)) ?></h3>
      <p>Paylasim havuzundaki net tutar</p>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header">Paylasim Hesabi</div>
  <div class="card-body">
    <div class="mobile-record-list d-grid d-lg-none mb-3">
      <?php if (empty($summary['partners'])): ?>
      <div class="dashboard-alert-empty">Once kisi eklediginde burada paylasim hesabi olusur.</div>
      <?php endif; ?>
      <?php foreach ($visibleSettlementPartners as $partnerRow): ?>
      <?php
        $isSettlementPartner = (int) ($partnerRow['partner']['is_settlement_partner'] ?? 0) === 1;
        $balance = (float) $partnerRow['balance'];
        if (!$isSettlementPartner) {
            $balanceLabel = 'Ayri Takip';
            $balanceClass = 'text-muted';
        } elseif ($balance > 0) {
            $balanceLabel = 'Firmaya Aktaracak';
            $balanceClass = 'text-danger fw-semibold';
        } elseif ($balance < 0) {
            $balanceLabel = 'Firmadan Alacak';
            $balanceClass = 'text-success fw-semibold';
        } else {
            $balanceLabel = 'Esit';
            $balanceClass = 'text-muted';
        }
      ?>
      <div class="mobile-record-card">
        <div class="mobile-record-card-head">
          <div class="mobile-record-card-title">
            <strong><?= h($partnerRow['partner']['name']) ?></strong>
            <small><?= h($balanceLabel) ?></small>
          </div>
          <div class="mobile-record-card-badges">
            <span class="finance-balance-chip <?= $balanceClass ?>"><?= money(abs($balance)) ?></span>
          </div>
        </div>
        <div class="mobile-record-grid">
          <div><span>Toplanan</span><strong><?= money($partnerRow['income']) ?></strong></div>
          <div><span>Gider</span><strong><?= money($partnerRow['expense']) ?></strong></div>
          <div><span>Hakki Olan</span><strong><?= money($partnerRow['deserved']) ?></strong></div>
          <div><span>Rol</span><strong><?= $isSettlementPartner ? 'Paylasim' : 'Takip' ?></strong></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-lg-block">
    <table class="table table-bordered table-striped align-middle">
      <tr><th>Kisi</th><th>Toplanan Tutar</th><th>Yaptigi Gider</th><th>Hakki Olan</th><th>Sonuc</th></tr>
      <?php if (empty($summary['partners'])): ?>
      <tr><td colspan="5" class="text-center text-muted">Once kisi eklediginde burada paylasim hesabi olusur.</td></tr>
      <?php endif; ?>
      <?php foreach ($visibleSettlementPartners as $partnerRow): ?>
      <?php
        $isSettlementPartner = (int) ($partnerRow['partner']['is_settlement_partner'] ?? 0) === 1;
        $balance = (float) $partnerRow['balance'];
        if (!$isSettlementPartner) {
            $balanceLabel = 'Ayri Takip';
            $balanceClass = 'text-muted';
        } elseif ($balance > 0) {
            $balanceLabel = 'Firmaya Aktaracak';
            $balanceClass = 'text-danger fw-semibold';
        } elseif ($balance < 0) {
            $balanceLabel = 'Firmadan Alacak';
            $balanceClass = 'text-success fw-semibold';
        } else {
            $balanceLabel = 'Esit';
            $balanceClass = 'text-muted';
        }
      ?>
      <tr>
        <td><?= h($partnerRow['partner']['name']) ?></td>
        <td><?= money($partnerRow['income']) ?></td>
        <td><?= money($partnerRow['expense']) ?></td>
        <td><?= money($partnerRow['deserved']) ?></td>
        <td class="<?= $balanceClass ?>">
          <span class="finance-balance-chip <?= $balanceClass ?>"><?= h($balanceLabel) ?></span>
          <div class="mt-1"><?= money(abs($balance)) ?></div>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?= pagination_render($settlementPagination, ['item_label' => 'paylasim kaydi']) ?>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header"><?= $isViewingClosedPeriod ? 'Donem Hareketleri' : 'Acik Donem Hareketleri' ?></div>
  <div class="card-body">
    <div class="mobile-record-list d-grid d-lg-none mb-3">
      <?php if (empty($entries)): ?>
      <div class="dashboard-alert-empty">Henuz gelir veya gider kaydi yok.</div>
      <?php endif; ?>
      <?php foreach ($visibleEntries as $entry): ?>
      <?php $entryCarPhotoUrl = !empty($entry['linked_car_id']) ? car_photo_public_url(['id' => $entry['linked_car_id'], 'photo_path' => $entry['linked_car_photo_path'] ?? null]) : null; ?>
      <div class="mobile-record-card">
        <div class="mobile-record-card-head">
          <div class="mobile-record-card-title">
            <strong><?= h($entry['type'] === 'expense' ? 'Gider' : 'Gelir') ?></strong>
            <small><?= dt($entry['entry_date']) ?></small>
          </div>
          <div class="mobile-record-card-badges">
            <span class="badge <?= $entry['type'] === 'expense' ? 'bg-danger' : 'bg-success' ?>"><?= money($entry['amount']) ?></span>
          </div>
        </div>
        <div class="mobile-record-grid">
          <div><span>Kisi</span><strong><?= h($entry['partner_name'] ?: '-') ?></strong></div>
          <div><span>Araç / Kaynak</span><strong><?php if ($entryCarPhotoUrl): ?><img src="<?= h($entryCarPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($entry['linked_car_photo_path'] ?? 'car'))) ?>" alt="<?= h($entry['car_label'] ?: 'Araç') ?>" class="car-photo-thumb mb-2" style="<?= h(car_photo_position_style($entry, 'linked_car_photo_position_x', 'linked_car_photo_position_y')) ?>"><?php endif; ?><?= h($entry['car_label'] ?: '-') ?></strong></div>
          <div class="full"><span>Not</span><strong><?= h($entry['note'] ?: '-') ?></strong></div>
        </div>
        <?php if (($canUpdateLedgerEntry || $canDeleteLedgerEntry) && !$isViewingClosedPeriod): ?>
        <div class="mobile-record-actions">
          <?php if ($canUpdateLedgerEntry): ?>
          <button class="action-btn action-warning" type="button" title="Duzenle" aria-label="Duzenle" data-bs-toggle="modal" data-bs-target="#businessEntryModal" data-mode="edit" data-id="<?= h($entry['id']) ?>" data-type="<?= h($entry['type']) ?>" data-partner_id="<?= h($entry['partner_id']) ?>" data-car_id="<?= h($entry['car_id']) ?>" data-car_label="<?= h($entry['car_label']) ?>" data-amount="<?= h($entry['amount']) ?>" data-note="<?= h($entry['note']) ?>" data-entry_date="<?= h(date('Y-m-d\\TH:i', strtotime($entry['entry_date']))) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
          </button>
          <?php endif; ?>
          <?php if ($canDeleteLedgerEntry): ?>
          <form action="actions/business_entry_delete.php" method="post">
            <?= auth_csrf_input() ?>
            <input type="hidden" name="id" value="<?= h($entry['id']) ?>">
            <button class="action-btn action-danger" type="submit" title="Sil" aria-label="Sil" data-confirm="Bu hareket kaydini silmek istediginize emin misiniz?">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 11h12l1-13H5l1 13Z"/></svg>
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-lg-block">
    <table class="table table-bordered table-striped align-middle">
      <tr><th>Tarih</th><th>Tur</th><th>Kisi</th><th>Arac / Kaynak</th><th>Tutar</th><th>Not</th><th>Islem</th></tr>
      <?php if (empty($entries)): ?>
      <tr><td colspan="7" class="text-center text-muted">Henuz gelir veya gider kaydi yok.</td></tr>
      <?php endif; ?>
      <?php foreach ($visibleEntries as $entry): ?>
      <?php $entryCarPhotoUrl = !empty($entry['linked_car_id']) ? car_photo_public_url(['id' => $entry['linked_car_id'], 'photo_path' => $entry['linked_car_photo_path'] ?? null]) : null; ?>
      <tr<?= $highlightEntryId > 0 && (int) ($entry['id'] ?? 0) === $highlightEntryId ? ' class="table-success"' : '' ?>>
        <td><?= dt($entry['entry_date']) ?></td>
        <td><span class="status-line justify-content-center"><span class="status-dot status-<?= $entry['type'] === 'expense' ? 'danger' : 'success' ?>"></span></span></td>
        <td><?= h($entry['partner_name'] ?: '-') ?></td>
        <td><?php if ($entryCarPhotoUrl): ?><div class="d-flex align-items-center gap-2"><img src="<?= h($entryCarPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($entry['linked_car_photo_path'] ?? 'car'))) ?>" alt="<?= h($entry['car_label'] ?: 'Araç') ?>" class="car-photo-thumb" style="<?= h(car_photo_position_style($entry, 'linked_car_photo_position_x', 'linked_car_photo_position_y')) ?>"><span><?= h($entry['car_label'] ?: '-') ?></span></div><?php else: ?><?= h($entry['car_label'] ?: '-') ?><?php endif; ?></td>
        <td><?= money($entry['amount']) ?></td>
        <td><?= h($entry['note']) ?></td>
        <td class="table-actions-cell">
          <?php if (($canUpdateLedgerEntry || $canDeleteLedgerEntry) && !$isViewingClosedPeriod): ?>
          <div class="action-group">
            <?php if ($canUpdateLedgerEntry): ?>
            <button class="action-btn action-warning" type="button" title="Duzenle" aria-label="Duzenle" data-bs-toggle="modal" data-bs-target="#businessEntryModal" data-mode="edit" data-id="<?= h($entry['id']) ?>" data-type="<?= h($entry['type']) ?>" data-partner_id="<?= h($entry['partner_id']) ?>" data-car_id="<?= h($entry['car_id']) ?>" data-car_label="<?= h($entry['car_label']) ?>" data-amount="<?= h($entry['amount']) ?>" data-note="<?= h($entry['note']) ?>" data-entry_date="<?= h(date('Y-m-d\TH:i', strtotime($entry['entry_date']))) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
            </button>
            <?php endif; ?>
            <?php if ($canDeleteLedgerEntry): ?>
            <form action="actions/business_entry_delete.php" method="post" class="d-inline">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h($entry['id']) ?>">
              <button class="action-btn action-danger" type="submit" title="Sil" aria-label="Sil" data-confirm="Bu hareket kaydini silmek istediginize emin misiniz?">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 11h12l1-13H5l1 13Z"/></svg>
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
    <?= pagination_render($entriesPagination, ['item_label' => 'hareket']) ?>
  </div>
</div>

<?php if (!$isViewingClosedPeriod && $canManageLedger): ?>
<div class="d-flex justify-content-end mb-4">
  <form action="actions/business_period_close.php" method="post">
    <?= auth_csrf_input() ?>
    <button class="btn btn-dark" type="submit" data-confirm="Bu donemi kapatip yeni hesap donemi baslatmak istiyor musunuz?">Hesabi Kapat</button>
  </form>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Gecmis Donemler</span>
    <span class="badge bg-dark"><?= count($closedPeriods) ?> donem</span>
  </div>
  <div class="card-body">
    <?php if (empty($closedPeriods)): ?>
    <div class="text-muted">Henuz kapatilmis hesap donemi yok.</div>
    <?php endif; ?>
    <div class="accordion" id="closedPeriodsAccordion">
      <?php foreach ($visibleClosedPeriods as $period): ?>
      <?php $periodId = (int) $period['id']; $periodSummary = $closedSummaries[$periodId]['summary']; ?>
      <div class="accordion-item">
        <h2 class="accordion-header" id="periodHeading<?= $periodId ?>">
          <div class="accordion-button collapsed gap-3" type="button" data-bs-toggle="collapse" data-bs-target="#periodCollapse<?= $periodId ?>" aria-expanded="false" aria-controls="periodCollapse<?= $periodId ?>">
            <span><?= dt($period['started_at']) ?> - <?= dt($period['settled_at']) ?> | Net: <?= money($periodSummary['deserved_total']) ?> | Kisi Basi Pay: <?= money($periodSummary['share_per_partner']) ?></span>
            <span class="ms-auto d-flex gap-2">
              <?php if ($canManageLedger): ?>
              <a href="business_accounts.php?period_id=<?= $periodId ?>" class="action-btn action-warning" title="Duzenle" aria-label="Duzenle" onclick="event.stopPropagation();">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </a>
              <form action="actions/business_period_delete.php" method="post" class="d-inline" onclick="event.stopPropagation();">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= $periodId ?>">
                <button class="action-btn action-danger" type="submit" title="Sil" aria-label="Sil" data-confirm="Bu gecmis donemi ve icindeki tum kayitlari silmek istediginize emin misiniz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 11h12l1-13H5l1 13Z"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </span>
          </div>
        </h2>
        <div id="periodCollapse<?= $periodId ?>" class="accordion-collapse collapse" aria-labelledby="periodHeading<?= $periodId ?>" data-bs-parent="#closedPeriodsAccordion">
          <div class="accordion-body">
            <div class="row g-3 mb-3">
              <div class="col-md-3"><strong>Toplanan Tutar:</strong> <?= money($periodSummary['total_income']) ?></div>
              <div class="col-md-3"><strong>Gider:</strong> <?= money($periodSummary['total_expense']) ?></div>
              <div class="col-md-3"><strong>Kisi Basi Pay:</strong> <?= money($periodSummary['share_per_partner']) ?></div>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered table-striped align-middle mb-0">
                <tr><th>Kisi</th><th>Toplanan Tutar</th><th>Yaptigi Gider</th><th>Hakki Olan</th><th>Sonuc</th></tr>
                <?php foreach ($periodSummary['partners'] as $partnerRow): ?>
                <tr>
                  <td><?= h($partnerRow['partner']['name']) ?></td>
                  <td><?= money($partnerRow['income']) ?></td>
                  <td><?= money($partnerRow['expense']) ?></td>
                  <td><?= money($partnerRow['deserved']) ?></td>
                  <td><?= money($partnerRow['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?= pagination_render($periodsPagination, ['item_label' => 'kapali donem']) ?>
  </div>
</div>

<?php if ($canManageLedger): ?>
<div class="modal fade" id="businessPartnerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Kisi Ekle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form action="actions/business_partner_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="id" value="">
          <div class="mb-3"><label class="form-label">Ad Soyad</label><input name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Hesap Rolu</label><select name="is_settlement_partner" class="form-select"><option value="1">Paylasima Dahil</option><option value="0">Sadece Takip</option></select></div>
          <div class="mb-3"><label class="form-label">Sira</label><input name="sort_order" type="number" min="0" class="form-control" value="0"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button><button class="btn btn-success" type="submit" data-submit-label>Kaydet</button></div>
      </form>
    </div>
  </div>
</div>

<?php if ($canCreateLedgerEntry || $canUpdateLedgerEntry): ?>
<div class="modal fade" id="businessEntryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Hareket Ekle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form action="actions/business_entry_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="id" value="">
          <input type="hidden" name="period_id" value="<?= h($selectedPeriod['id']) ?>">
          <div class="mb-3"><label class="form-label">Tur</label><select name="type" class="form-select"><option value="income">Gelir</option><option value="expense">Gider</option></select></div>
          <div class="mb-3"><label class="form-label">Kisi</label><select name="partner_id" class="form-select" required><option value="">Seciniz</option><?php foreach ($partners as $partner): ?><option value="<?= h($partner['id']) ?>"><?= h($partner['name']) ?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><label class="form-label">Araç</label><select name="car_id" class="form-select"><option value="">Araç seçmeden devam et</option><?php foreach ($cars as $car): ?><option value="<?= h($car['id']) ?>"><?= h(trim($car['brand'] . ' ' . $car['model'])) ?><?php if (!empty($car['plate'])): ?> / <?= h($car['plate']) ?><?php endif; ?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><label class="form-label">Ek Kaynak Açıklaması</label><input name="car_label" class="form-control" placeholder="İstersen kısa açıklama yaz"></div>
          <div class="mb-3"><div class="car-photo-frame" style="max-width: 260px;"><img src="" alt="Seçilen araç fotoğrafı" data-ledger-car-preview hidden><div class="text-muted d-flex align-items-center justify-content-center h-100 text-center px-3" data-ledger-car-empty>Araç seçersen fotoğraf burada görünür.</div></div></div>
          <div class="mb-3"><label class="form-label">Tutar</label><input name="amount" type="number" step="0.01" min="0" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Tarih</label><input name="entry_date" type="datetime-local" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Not</label><input name="note" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button><button class="btn btn-success" type="submit" data-submit-label>Kaydet</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<script>
const ledgerCarPreviewMap = <?= json_encode(array_reduce($cars, static function (array $carry, array $car): array {
    $photoUrl = car_photo_public_url($car);
    $carry[(string) ($car['id'] ?? '')] = [
        'photo_url' => $photoUrl ? ($photoUrl . '?v=' . rawurlencode((string) ($car['photo_path'] ?? 'car'))) : '',
        'photo_position' => car_photo_object_position($car),
    ];
    return $carry;
}, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(() => {
  const entryModal = document.getElementById('businessEntryModal');
  if (!entryModal) return;
  const previewWrap = entryModal.querySelector('[data-ledger-car-preview]')?.closest('.mb-3');
  const carSelect = entryModal.querySelector('[name="car_id"]');
  const previewImage = entryModal.querySelector('[data-ledger-car-preview]');
  const previewEmpty = entryModal.querySelector('[data-ledger-car-empty]');

  if (previewWrap) {
    previewWrap.hidden = true;
    previewWrap.style.marginTop = '-.25rem';
  }

  if (previewImage) {
    previewImage.classList.add('car-photo-thumb');
    previewImage.style.width = '96px';
    previewImage.style.height = '60px';
  }

  const previewFrame = previewImage?.closest('.car-photo-frame');
  if (previewFrame) {
    previewFrame.style.maxWidth = '100%';
    previewFrame.style.aspectRatio = 'auto';
    previewFrame.style.background = 'transparent';
    previewFrame.style.border = '0';
    previewFrame.style.borderRadius = '.75rem';
    previewFrame.style.overflow = 'visible';
    previewFrame.style.padding = '0';
  }

  if (previewEmpty) {
    previewEmpty.className = 'small text-muted';
  }

  const syncLedgerCarPreview = () => {
    if (!carSelect || !previewImage || !previewEmpty) return;

    const selectedCarId = String(carSelect.value || '');
    const previewData = ledgerCarPreviewMap[selectedCarId] || null;

    if (previewData && previewData.photo_url) {
      if (previewWrap) previewWrap.hidden = false;
      previewImage.src = previewData.photo_url;
      previewImage.style.objectPosition = previewData.photo_position || 'center center';
      previewImage.hidden = false;
      previewEmpty.hidden = true;
      return;
    }

    if (previewWrap) previewWrap.hidden = true;
    previewImage.hidden = true;
    previewImage.removeAttribute('src');
    previewEmpty.hidden = false;
  };

  if (carSelect) {
    carSelect.addEventListener('change', syncLedgerCarPreview);
  }

  entryModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    const form = entryModal.querySelector('form');
    if (!form) return;

    const title = entryModal.querySelector('.modal-title');
    const submit = entryModal.querySelector('[data-submit-label]');
    const mode = trigger?.getAttribute('data-mode') || 'create';
    const type = trigger?.getAttribute('data-type') || 'income';

    form.reset();
    form.querySelector('[name="id"]').value = mode === 'edit' ? (trigger?.getAttribute('data-id') || '') : '';
    form.querySelector('[name="type"]').value = type;
    form.querySelector('[name="partner_id"]').value = trigger?.getAttribute('data-partner_id') || '';
    form.querySelector('[name="car_id"]').value = trigger?.getAttribute('data-car_id') || '';
    form.querySelector('[name="car_label"]').value = trigger?.getAttribute('data-car_label') || '';
    form.querySelector('[name="amount"]').value = trigger?.getAttribute('data-amount') || '';
    form.querySelector('[name="note"]').value = trigger?.getAttribute('data-note') || '';
    form.querySelector('[name="entry_date"]').value = trigger?.getAttribute('data-entry_date') || '';

    if (title) {
      title.textContent = mode === 'edit' ? 'Hareketi Düzenle' : (type === 'expense' ? 'Gider Ekle' : 'Gelir Ekle');
    }

    if (submit) {
      submit.textContent = mode === 'edit' ? 'Güncelle' : 'Kaydet';
    }
  });

  entryModal.addEventListener('shown.bs.modal', () => {
    syncLedgerCarPreview();
  });

  entryModal.addEventListener('hidden.bs.modal', () => {
    if (previewWrap) previewWrap.hidden = true;
    if (previewImage) {
      previewImage.hidden = true;
      previewImage.removeAttribute('src');
    }
    if (previewEmpty) {
      previewEmpty.hidden = false;
    }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
