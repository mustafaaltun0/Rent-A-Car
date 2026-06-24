<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.view');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarPhotoSchema($pdo);
ensureCarTelematicsSchema($pdo);
ensureCustomerCompanySchema($pdo);
$companyId = auth_current_company_id();
$customerCompaniesEnabled = app_feature_customer_companies_enabled();
$canManageRentals = auth_can('rentals.manage');
$canReverseCollections = auth_can('platform.manage');
$entryStatus = $_GET['status'] ?? '';

if (!function_exists('rental_extension_revision_label')) {
    function rental_extension_revision_label(string $actionType): string
    {
        $map = [
            'created' => 'Uzatma oluşturuldu',
            'updated' => 'Uzatma güncellendi',
            'collection_added' => 'Tahsilat eklendi',
            'collection_updated' => 'Tahsilat güncellendi',
            'collection_cancelled' => 'Tahsilat geri alındı',
            'cancelled' => 'Uzatma iptal edildi',
        ];

        return $map[$actionType] ?? 'Degisiklik kaydi';
    }
}

if (!function_exists('rental_extension_revision_summary')) {
    function rental_extension_revision_summary(array $revision): string
    {
        $actionType = (string) ($revision['action_type'] ?? '');
        $before = json_decode((string) ($revision['payload_before'] ?? ''), true);
        $after = json_decode((string) ($revision['payload_after'] ?? ''), true);
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];

        if ($actionType === 'collection_added') {
            $amount = isset($after['collection_amount']) ? money($after['collection_amount']) : '-';
            $total = isset($after['collected_amount_after']) ? money($after['collected_amount_after']) : '-';
            return 'Tahsil edilen: ' . $amount . ' / Toplam tahsilat: ' . $total;
        }

        if ($actionType === 'cancelled') {
            $reason = trim((string) ($after['cancel_reason'] ?? ''));
            return $reason !== '' ? 'Neden: ' . $reason : 'İptal edildi.';
        }

        if ($actionType === 'collection_updated') {
            $oldAmount = isset($before['collection_amount']) ? money($before['collection_amount']) : '-';
            $newAmount = isset($after['collection_amount']) ? money($after['collection_amount']) : '-';
            $total = isset($after['collected_amount_after']) ? money($after['collected_amount_after']) : '-';
            return 'Tahsilat: ' . $oldAmount . ' -> ' . $newAmount . ' / Toplam tahsilat: ' . $total;
        }

        if ($actionType === 'collection_cancelled') {
            $amount = isset($before['collection_amount']) ? money($before['collection_amount']) : '-';
            $reason = trim((string) ($after['cancel_reason'] ?? ''));
            return 'Geri alinan tahsilat: ' . $amount . ($reason !== '' ? ' / Neden: ' . $reason : '');
        }

        $parts = [];
        if (!empty($after['new_end_date'])) {
            $parts[] = 'Bitis: ' . dt($after['new_end_date']);
        } elseif (!empty($before['new_end_date'])) {
            $parts[] = 'Bitis: ' . dt($before['new_end_date']);
        }

        if (array_key_exists('income', $after)) {
            $parts[] = 'Gelir: ' . money((float) $after['income']);
        } elseif (array_key_exists('income', $before)) {
            $parts[] = 'Gelir: ' . money((float) $before['income']);
        }

        if (array_key_exists('expense', $after)) {
            $parts[] = 'Masraf: ' . money((float) $after['expense']);
        } elseif (array_key_exists('expense', $before)) {
            $parts[] = 'Masraf: ' . money((float) $before['expense']);
        }

        if (!empty($after['payment_status'])) {
            $paymentMap = [
                'pending' => 'Tahsilat bekliyor',
                'partial' => 'Parcali tahsil edildi',
                'collected' => 'Tahsil edildi',
            ];
            $parts[] = $paymentMap[$after['payment_status']] ?? $after['payment_status'];
        }

        if (!empty($after['auto_collected_amount'])) {
            $parts[] = 'Ek tahsilat: ' . money((float) $after['auto_collected_amount']);
        }

        return !empty($parts) ? implode(' / ', $parts) : 'Detay kaydi tutuldu.';
    }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$st = $pdo->prepare('
    SELECT
        r.*,
        c.id AS car_id,
        c.brand,
        c.model,
        c.plate,
        c.photo_path,
        c.photo_position_x,
        c.photo_position_y,
        c.photo_focus_x,
        c.photo_focus_y,
        c.telematics_enabled,
        c.telematics_provider,
        c.telematics_device_id,
        c.telematics_last_odometer_km,
        c.telematics_last_latitude,
        c.telematics_last_longitude,
        c.telematics_ignition_on,
        c.telematics_last_sync_at,
        cc.company_name AS customer_company_name,
        cc.contact_name AS customer_company_contact_name,
        cc.phone AS customer_company_phone,
        cc.email AS customer_company_email
    FROM rentals r
    LEFT JOIN cars c
        ON c.id = r.car_id
       AND c.company_id = r.company_id
    LEFT JOIN customer_companies cc
        ON cc.id = r.customer_company_id
       AND cc.company_id = r.company_id
    WHERE r.id = ?
      AND r.company_id = ?
');
$st->execute([$id, $companyId]);
$rental = $st->fetch(PDO::FETCH_ASSOC);
if (!$rental) {
    redirect('rentals.php');
}

$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$revisionsByExtensionId = getRentalExtensionRevisionsByExtensionId($pdo, $companyId);
$extensions = $extensionsByRentalId[$id] ?? [];
$latestActiveExtension = rental_latest_active_extension($extensions);
$totals = getRentalTotals($rental, $extensionsByRentalId, $collectionsByExtensionId);
$baseCollectedAmount = rental_collected_amount($rental);
$basePendingAmount = rental_pending_amount($rental);
$basePaymentStatus = rental_effective_payment_status($rental);
$isArchivedRental = rental_is_archived($rental);
$hasAnyExtension = !empty($extensions);
$displayInitialEndDateValue = $hasAnyExtension
    ? ($rental['initial_end_date'] ?? $rental['end_date'] ?? null)
    : ($rental['end_date'] ?? $rental['initial_end_date'] ?? null);
$rentalStart = !empty($rental['start_date']) ? new DateTimeImmutable($rental['start_date']) : null;
$initialEnd = !empty($displayInitialEndDateValue) ? new DateTimeImmutable($displayInitialEndDateValue) : null;
$currentEnd = !empty($rental['end_date']) ? new DateTimeImmutable($rental['end_date']) : null;
$initialRentalDays = ($rentalStart && $initialEnd && $initialEnd > $rentalStart) ? (int) ceil(($initialEnd->getTimestamp() - $rentalStart->getTimestamp()) / 86400) : null;
$currentRentalDays = ($rentalStart && $currentEnd && $currentEnd > $rentalStart) ? (int) ceil(($currentEnd->getTimestamp() - $rentalStart->getTimestamp()) / 86400) : null;
$kmMetrics = rental_km_metrics($rental, $rental);
$drivenKm = $kmMetrics['distance_km'];
$carLabel = trim(($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? ''));
$carPhotoUrl = !empty($rental['car_id']) ? car_photo_public_url(['id' => $rental['car_id'], 'photo_path' => $rental['photo_path'] ?? null]) : null;
$statusLabel = (int) ($rental['completed'] ?? 0) === 1 ? 'Teslim Alindi' : 'Aktif Kirada';
$statusClass = (int) ($rental['completed'] ?? 0) === 1 ? 'status-success' : 'status-danger';

$pageTitle = 'Kiralama Detay';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="rental-detail-page">
  <?php if ($entryStatus === 'extension_saved'): ?>
  <div class="alert alert-success">Uzatma kaydı oluşturuldu. Tahsilat planı da kayda alındı.</div>
  <?php elseif ($entryStatus === 'extension_collected'): ?>
  <div class="alert alert-success">Uzatma tahsilati kaydedildi.</div>
  <?php elseif ($entryStatus === 'extension_collection_updated'): ?>
  <div class="alert alert-success">Tahsilat kaydı güncellendi.</div>
  <?php elseif ($entryStatus === 'extension_updated'): ?>
  <div class="alert alert-success">Son uzatma kaydı güncellendi.</div>
  <?php elseif ($entryStatus === 'extension_cancelled'): ?>
  <div class="alert alert-success">Son uzatma kaydı iptal edildi ve kiralama süresi geri alındı.</div>
  <?php elseif ($entryStatus === 'extension_deleted'): ?>
  <div class="alert alert-success">İptal edilmiş uzatma kaydı tamamen silindi.</div>
  <?php elseif ($entryStatus === 'extension_not_editable'): ?>
  <div class="alert alert-danger">Sadece son aktif uzatma kaydi duzenlenebilir veya iptal edilebilir.</div>
  <?php elseif ($entryStatus === 'extension_delete_requires_cancel'): ?>
  <div class="alert alert-danger">Bir uzatmayi tamamen silmeden once iptal etmelisin.</div>
  <?php elseif ($entryStatus === 'extension_delete_error'): ?>
  <div class="alert alert-danger">Uzatma kaydı silinirken beklenmeyen bir sorun oluştu.</div>
  <?php elseif ($entryStatus === 'extension_invalid'): ?>
  <div class="alert alert-danger">Uzatma bilgileri geçersiz. Yeni bitiş tarihi önceki bitişten ileri olmalı.</div>
  <?php elseif ($entryStatus === 'extension_not_collectible'): ?>
  <div class="alert alert-danger">Bu uzatma kaydi tahsilata uygun degil.</div>
  <?php elseif ($entryStatus === 'extension_collection_invalid'): ?>
  <div class="alert alert-danger">Tahsilat tutarı geçersiz. Kalan tutardan büyük olamaz.</div>
  <?php elseif ($entryStatus === 'extension_collection_update_invalid'): ?>
  <div class="alert alert-danger">Tahsilat güncelleme bilgileri geçersiz. Tutar 0'dan büyük olmalı ve izin verilen sınırı aşmamalı.</div>
  <?php elseif ($entryStatus === 'extension_collection_conflict'): ?>
  <div class="alert alert-danger">Toplanan tahsilat nedeniyle uzatma geliri bu seviyenin altina dusurulemez.</div>
  <?php elseif ($entryStatus === 'extension_collection_update_conflict'): ?>
  <div class="alert alert-danger">Bu tahsilat bu seviyeye güncellenemez. Diğer tahsilatlarla birlikte uzatma gelirini aşıyor.</div>
  <?php elseif ($entryStatus === 'extension_cancel_collection_conflict'): ?>
  <div class="alert alert-danger">Bu uzatma için tahsilat alınmış. Önce tahsilatı düzeltmeden uzatma iptal edilemez.</div>
  <?php elseif ($entryStatus === 'extension_collection_cancelled'): ?>
  <div class="alert alert-success">Tahsilat kaydı geri alındı.</div>
  <?php elseif ($entryStatus === 'extension_collection_not_reversible'): ?>
  <div class="alert alert-danger">Sadece son aktif tahsilat kaydi geri alinabilir.</div>
  <?php endif; ?>
  <?php if ($isArchivedRental): ?>
  <div class="alert alert-secondary">Bu kiralama arsivde. Detaylari gorebilirsin ama aktif islemler kapatildi.</div>
  <?php endif; ?>

  <div class="rental-hero mb-4">
    <div class="rental-hero-main">
      <div class="rental-hero-label">Kiralama Detayi</div>
      <h2 class="mb-2"><?= h($rental['customer_name']) ?></h2>
      <div class="rental-hero-subtitle"><?= h($carLabel ?: 'Araç bilgisi yok') ?></div>
      <?php if ($customerCompaniesEnabled && !empty($rental['customer_company_name'])): ?>
      <div class="text-muted mt-2"><?= h($rental['customer_company_name']) ?></div>
      <?php endif; ?>
    </div>
    <div class="rental-hero-actions">
      <div class="status-line rental-status-pill"><span class="status-dot <?= $statusClass ?>"></span><span><?= h($statusLabel) ?></span></div>
      <a href="rental_print.php?id=<?= h((string) $rental['id']) ?>" class="btn btn-dark" target="_blank" rel="noopener">Yazdır</a>
      <a href="rental_receipt.php?id=<?= h((string) $rental['id']) ?>" class="btn btn-outline-dark" target="_blank" rel="noopener">Makbuz</a>
      <a href="rentals.php" class="btn btn-light">Kiralamalara Dön</a>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card bg-primary shadow-sm"><h6>Tahsil Edilen</h6><h3><?= money($totals['income']) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-info shadow-sm"><h6>Bekleyen Tahsilat</h6><h3><?= money($totals['pending_income'] ?? 0) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-dark shadow-sm"><h6>Net Kar</h6><h3><?= money($totals['net_profit']) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-secondary shadow-sm"><h6>Sözleşme Toplamı</h6><h3><?= money($totals['contract_income'] ?? 0) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-success shadow-sm"><h6>Kiralama Süresi</h6><h3><?= $currentRentalDays !== null ? h($currentRentalDays) . ' gün' : '-' ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-warning shadow-sm"><h6>Yapilan KM</h6><h3><?= $drivenKm !== null ? h(number_format($drivenKm, 0, ',', '.')) . ' km' : '-' ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-danger shadow-sm"><h6>Günlük Ort. KM</h6><h3><?= $kmMetrics['average_daily_km'] !== null ? h(number_format((float) $kmMetrics['average_daily_km'], 1, ',', '.')) . ' km' : '-' ?></h3></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="card shadow-sm h-100">
        <div class="card-header">Kiralama Bilgileri</div>
        <div class="card-body">
          <?php if ($carPhotoUrl): ?>
          <div class="car-photo-frame mb-3">
            <img src="<?= h($carPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($rental['photo_path'] ?? 'car'))) ?>" alt="<?= h($carLabel ?: 'Araç') ?>" style="<?= h(car_photo_position_style($rental)) ?>">
          </div>
          <?php endif; ?>
          <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Telefon</span><strong><?= h($rental['customer_phone']) ?: '-' ?></strong></div>
            <?php if ($customerCompaniesEnabled): ?><div class="detail-item"><span class="detail-label">Kurumsal Müşteri</span><strong><?= h($rental['customer_company_name']) ?: '-' ?></strong></div><?php endif; ?>
            <div class="detail-item"><span class="detail-label">TC Kimlik No</span><strong><?= h($rental['customer_identity_no']) ?: '-' ?></strong></div>
            <?php if ($customerCompaniesEnabled): ?><div class="detail-item"><span class="detail-label">Firma Yetkilisi</span><strong><?= h($rental['customer_company_contact_name']) ?: '-' ?></strong></div><?php endif; ?>
            <div class="detail-item"><span class="detail-label">Baslangic</span><strong><?= dt($rental['start_date']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Ilk Bitis</span><strong><?= dt($displayInitialEndDateValue) ?><?= $initialRentalDays !== null ? ' / ' . h($initialRentalDays) . ' gun' : '' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Güncel Bitiş</span><strong><?= dt($rental['end_date']) ?><?= $currentRentalDays !== null ? ' / ' . h($currentRentalDays) . ' gün' : '' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Araç</span><strong><?= h($carLabel ?: '-') ?></strong></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card shadow-sm h-100">
        <div class="card-header">KM ve Finans</div>
        <div class="card-body">
          <div class="detail-grid detail-grid-single">
            <div class="detail-item"><span class="detail-label">Cikis KM</span><strong><?= $rental['departure_km'] !== null && $rental['departure_km'] !== '' ? h(number_format((int) $rental['departure_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Dönüş / Anlık KM</span><strong><?= $kmMetrics['effective_end_km'] !== null ? h(number_format((int) $kmMetrics['effective_end_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">KM Veri Kaynagi</span><strong><?= h($kmMetrics['distance_source']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Günlük Ortalama</span><strong><?= $kmMetrics['average_daily_km'] !== null ? h(number_format((float) $kmMetrics['average_daily_km'], 1, ',', '.')) . ' km' : '-' ?></strong></div>
            <?php if ($customerCompaniesEnabled): ?><div class="detail-item"><span class="detail-label">Firma Telefonu</span><strong><?= h($rental['customer_company_phone']) ?: '-' ?></strong></div><?php endif; ?>
            <?php if ($customerCompaniesEnabled): ?><div class="detail-item"><span class="detail-label">Firma E-posta</span><strong><?= h($rental['customer_company_email']) ?: '-' ?></strong></div><?php endif; ?>
            <div class="detail-item"><span class="detail-label">Toplam Arac Masrafi</span><strong><?= money($totals['expense']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Toplam Net Kar</span><strong><?= money($totals['net_profit']) ?></strong></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header">Ilk Kiralama</div>
    <div class="card-body">
      <div class="period-summary-card">
        <div class="period-summary-line">
          <span class="detail-label">Dönem</span>
          <strong><?= dt($rental['start_date']) ?> - <?= dt($displayInitialEndDateValue) ?></strong>
        </div>
        <div class="period-summary-stats">
          <div><span class="detail-label">Gelir</span><strong><?= money($rental['income']) ?></strong></div>
          <div><span class="detail-label">Tahsil Edilen</span><strong><?= money($baseCollectedAmount) ?></strong></div>
          <div><span class="detail-label">Kalan Tahsilat</span><strong><?= money($basePendingAmount) ?></strong></div>
          <div><span class="detail-label">Masraf</span><strong><?= money($rental['expense']) ?></strong></div>
          <div><span class="detail-label">Net Kar</span><strong><?= money($rental['net_profit']) ?></strong></div>
          <div><span class="detail-label">Tahsilat Durumu</span><strong><?= $basePaymentStatus === 'collected' ? 'Tahsil Edildi' : ($basePaymentStatus === 'partial' ? 'Parçalı Tahsilat' : 'Tahsilat Bekliyor') ?></strong></div>
          <div><span class="detail-label">Beklenen Tarih</span><strong><?= !empty($rental['payment_due_date']) ? dt($rental['payment_due_date']) : '-' ?></strong></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">Uzatma Geçmişi</div>
    <div class="card-body">
      <?php if (empty($extensions)): ?>
      <div class="text-center text-muted py-3">Bu kiralama icin uzatma gecmisi yok.</div>
      <?php else: ?>
      <div class="extension-list">
        <?php foreach ($extensions as $index => $extension): ?>
        <?php
            $extensionId = (int) ($extension['id'] ?? 0);
            $isLatestActiveExtension = $latestActiveExtension && (int) ($latestActiveExtension['id'] ?? 0) === $extensionId && rental_extension_is_active($extension);
            $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
            $pendingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);
            $effectivePaymentStatus = rental_extension_effective_payment_status($extension, $collectionsByExtensionId);
            $displayExtension = $extension;
            $displayExtension['payment_status'] = $effectivePaymentStatus;
            $extensionCollections = $collectionsByExtensionId[$extensionId] ?? [];
            $latestActiveCollection = rental_extension_latest_active_collection($extensionCollections);
            $extensionRevisions = $revisionsByExtensionId[$extensionId] ?? [];
            $originalExtensionTerms = rental_extension_original_terms($extension, $extensionRevisions);
            $effectiveExtensionEndDate = ($isLatestActiveExtension && !empty($rental['end_date']))
                ? (string) $rental['end_date']
                : (string) ($extension['new_end_date'] ?? '');
        ?>
          <div class="extension-card" id="extension-<?= h((string) $extensionId) ?>">
          <div class="extension-card-head">
            <span class="extension-index">Uzatma <?= $index + 1 ?></span>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="badge <?= h(rental_extension_status_badge_class($displayExtension)) ?>"><?= h(rental_extension_status_label($displayExtension)) ?></span>
              <span class="extension-income"><?= money($extension['income']) ?></span>
            </div>
          </div>

          <div class="detail-grid mb-3">
            <div class="detail-item"><span class="detail-label">Onceki Bitis</span><strong><?= dt($extension['previous_end_date']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Yeni Bitis</span><strong><?= dt($effectiveExtensionEndDate) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Beklenen Tahsilat</span><strong><?= !empty($extension['payment_due_date']) ? dt($extension['payment_due_date']) : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Son Tahsilat</span><strong><?= !empty($extension['collected_at']) ? dt($extension['collected_at']) : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Ek Masraf</span><strong><?= money($extension['expense']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Ek Net Kar</span><strong><?= money($extension['net_profit']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Tahsil Edilen</span><strong><?= money($collectedAmount) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Kalan Tahsilat</span><strong><?= money($pendingAmount) ?></strong></div>
            <div class="detail-item detail-item-full"><span class="detail-label">Not</span><strong><?= h($extension['note']) ?: '-' ?></strong></div>
            <?php if (!empty($extension['cancel_reason'])): ?><div class="detail-item detail-item-full"><span class="detail-label">İptal Nedeni</span><strong><?= h($extension['cancel_reason']) ?></strong></div><?php endif; ?>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-lg-6">
              <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-2">Tahsilat Geçmişi</div>
                <?php if (empty($extensionCollections)): ?>
                <div class="text-muted small">Bu uzatma icin henuz tahsilat kaydi yok.</div>
                <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <tr><th>Tarih</th><th>Tutar</th><th>Detay</th><th>Durum</th><th>Islem</th></tr>
                    <?php foreach ($extensionCollections as $collection): ?>
                    <?php
                      $isActiveCollection = rental_extension_collection_is_active($collection);
                      $isLatestActiveCollection = $latestActiveCollection && (int) ($latestActiveCollection['id'] ?? 0) === (int) ($collection['id'] ?? 0) && $isActiveCollection;
                      $editableLimit = $isLatestActiveCollection ? (max(0.0, (float) ($pendingAmount ?? 0)) + max(0.0, (float) ($collection['amount'] ?? 0))) : 0.0;
                    ?>
                    <tr>
                      <td><?= dt($collection['collected_at'] ?? null) ?></td>
                      <td><?= money($collection['amount'] ?? 0) ?></td>
                      <td><?= h($collection['payment_method'] ?? '') ?: '-' ?><?= !empty($collection['note']) ? ' / ' . h($collection['note']) : '' ?><?= !empty($collection['cancel_reason']) ? ' / İptal: ' . h($collection['cancel_reason']) : '' ?></td>
                      <td><?= $isActiveCollection ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Geri Alindi</span>' ?></td>
                      <td>
                        <?php if ($canManageRentals && rental_extension_is_active($extension) && $isLatestActiveCollection): ?>
                        <button
                          class="btn btn-outline-primary btn-sm"
                          type="button"
                          data-bs-toggle="modal"
                          data-bs-target="#editRentalExtensionCollectionModal"
                          data-collection_id="<?= h($collection['id']) ?>"
                          data-extension_id="<?= h($extension['id']) ?>"
                          data-rental_id="<?= h($rental['id']) ?>"
                          data-customer_name="<?= h($rental['customer_name']) ?>"
                          data-collection_amount="<?= h(number_format((float) ($collection['amount'] ?? 0), 2, '.', '')) ?>"
                          data-collected_at="<?= h(!empty($collection['collected_at']) ? date('Y-m-d\TH:i', strtotime($collection['collected_at'])) : '') ?>"
                          data-payment_method="<?= h((string) ($collection['payment_method'] ?? '')) ?>"
                          data-note="<?= h((string) ($collection['note'] ?? '')) ?>"
                          data-max_amount="<?= h(number_format($editableLimit, 2, '.', '')) ?>"
                        >Düzenle</button>
                        <?php if ($canReverseCollections): ?>
                        <button
                          class="btn btn-outline-danger btn-sm"
                          type="button"
                          data-bs-toggle="modal"
                          data-bs-target="#cancelRentalExtensionCollectionModal"
                          data-collection_id="<?= h($collection['id']) ?>"
                          data-extension_id="<?= h($extension['id']) ?>"
                          data-rental_id="<?= h($rental['id']) ?>"
                          data-customer_name="<?= h($rental['customer_name']) ?>"
                          data-collection_amount="<?= h(number_format((float) ($collection['amount'] ?? 0), 2, '.', '')) ?>"
                          data-collected_at="<?= h(!empty($collection['collected_at']) ? date('Y-m-d\TH:i', strtotime($collection['collected_at'])) : '') ?>"
                        >Geri Al</button>
                        <?php endif; ?>
                        <?php else: ?>
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
            <div class="col-lg-6">
              <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-2">Revizyon Geçmişi</div>
                <?php if (empty($extensionRevisions)): ?>
                <div class="text-muted small">Bu uzatma icin revizyon kaydi yok.</div>
                <?php else: ?>
                <div class="d-flex flex-column gap-2">
                  <?php foreach ($extensionRevisions as $revision): ?>
                  <div class="border rounded p-2">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                      <strong><?= h(rental_extension_revision_label((string) ($revision['action_type'] ?? ''))) ?></strong>
                      <small class="text-muted"><?= dt($revision['created_at'] ?? null) ?></small>
                    </div>
                    <div class="small text-muted mt-1"><?= h(rental_extension_revision_summary($revision)) ?></div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if ($canManageRentals && !$isArchivedRental && rental_extension_is_active($extension) && $pendingAmount > 0): ?>
          <div class="mb-2 d-flex gap-2 flex-wrap">
            <button
              class="btn btn-success btn-sm"
              type="button"
              data-bs-toggle="modal"
              data-bs-target="#collectRentalExtensionModal"
              data-extension_id="<?= h($extension['id']) ?>"
              data-rental_id="<?= h($rental['id']) ?>"
              data-customer_name="<?= h($rental['customer_name']) ?>"
              data-previous_end_date="<?= h($extension['previous_end_date'] ? date('Y-m-d\TH:i', strtotime($extension['previous_end_date'])) : '') ?>"
              data-new_end_date="<?= h($effectiveExtensionEndDate ? date('Y-m-d\TH:i', strtotime($effectiveExtensionEndDate)) : '') ?>"
              data-remaining_amount="<?= h(number_format($pendingAmount, 2, '.', '')) ?>"
            >Tahsilat Ekle</button>
            <form action="actions/rental_extension_collect.php" method="post" class="d-inline">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="extension_id" value="<?= h($extension['id']) ?>">
              <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
              <input type="hidden" name="amount" value="<?= h(number_format($pendingAmount, 2, '.', '')) ?>">
              <input type="hidden" name="note" value="Kalan uzatma tutarinin tamami tahsil edildi.">
              <button class="btn btn-outline-success btn-sm" type="submit" data-confirm="Kalan uzatma tahsilatinin tamamini tek seferde kaydetmek istediginize emin misiniz?">Tamamini Tahsil Et</button>
            </form>
          </div>
          <?php endif; ?>

            <?php if ($canManageRentals && !$isArchivedRental && $isLatestActiveExtension): ?>
            <div class="d-flex gap-2 flex-wrap">
            <button
              class="btn btn-outline-primary btn-sm"
              type="button"
              data-bs-toggle="modal"
              data-bs-target="#editRentalExtensionModal"
              data-extension_id="<?= h($extension['id']) ?>"
              data-rental_id="<?= h($rental['id']) ?>"
              data-customer_name="<?= h($rental['customer_name']) ?>"
              data-previous_end_date="<?= h($extension['previous_end_date'] ? date('Y-m-d\TH:i', strtotime($extension['previous_end_date'])) : '') ?>"
              data-new_end_date="<?= h($effectiveExtensionEndDate ? date('Y-m-d\TH:i', strtotime($effectiveExtensionEndDate)) : '') ?>"
              data-original_previous_end_date="<?= h(!empty($originalExtensionTerms['previous_end_date']) ? date('Y-m-d\TH:i', strtotime((string) $originalExtensionTerms['previous_end_date'])) : '') ?>"
              data-original_new_end_date="<?= h(!empty($originalExtensionTerms['new_end_date']) ? date('Y-m-d\TH:i', strtotime((string) $originalExtensionTerms['new_end_date'])) : '') ?>"
              data-original_income="<?= h(number_format((float) ($originalExtensionTerms['income'] ?? 0), 2, '.', '')) ?>"
              data-additional_income="<?= h(number_format((float) ($extension['income'] ?? 0), 2, '.', '')) ?>"
              data-additional_expense="<?= h(number_format((float) ($extension['expense'] ?? 0), 2, '.', '')) ?>"
              data-payment_status="<?= h($effectivePaymentStatus === 'partial' ? 'pending' : $effectivePaymentStatus) ?>"
              data-payment_due_date="<?= h(!empty($extension['payment_due_date']) ? date('Y-m-d\TH:i', strtotime($extension['payment_due_date'])) : '') ?>"
              data-note="<?= h($extension['note']) ?>"
            >Uzatmayı Düzenle</button>
            <button
              class="btn btn-outline-danger btn-sm"
              type="button"
              data-bs-toggle="modal"
              data-bs-target="#cancelRentalExtensionModal"
              data-extension_id="<?= h($extension['id']) ?>"
              data-rental_id="<?= h($rental['id']) ?>"
              data-customer_name="<?= h($rental['customer_name']) ?>"
              data-previous_end_date="<?= h($extension['previous_end_date'] ? date('Y-m-d\TH:i', strtotime($extension['previous_end_date'])) : '') ?>"
              data-new_end_date="<?= h($extension['new_end_date'] ? date('Y-m-d\TH:i', strtotime($extension['new_end_date'])) : '') ?>"
              data-collected_amount="<?= h(number_format($collectedAmount, 2, '.', '')) ?>"
              >Uzatmayı İptal Et</button>
            </div>
            <?php endif; ?>

            <?php if ($canManageRentals && !$isArchivedRental && !rental_extension_is_active($extension)): ?>
            <div class="d-flex gap-2 flex-wrap mt-2">
              <button
                class="btn btn-danger btn-sm"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#deleteRentalExtensionModal"
                data-extension_id="<?= h($extension['id']) ?>"
                data-rental_id="<?= h($rental['id']) ?>"
                data-customer_name="<?= h($rental['customer_name']) ?>"
                data-previous_end_date="<?= h($extension['previous_end_date'] ? date('Y-m-d\\TH:i', strtotime($extension['previous_end_date'])) : '') ?>"
                data-new_end_date="<?= h($effectiveExtensionEndDate ? date('Y-m-d\\TH:i', strtotime($effectiveExtensionEndDate)) : '') ?>"
                data-collected_amount="<?= h(number_format($collectedAmount, 2, '.', '')) ?>"
              >Tamamen Sil</button>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canManageRentals && !$isArchivedRental): ?>
<div class="modal fade" id="editRentalExtensionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Uzatma Kaydını Düzenle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_update.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <input type="hidden" name="pricing_mode" value="auto_prorata">
          <div class="mb-3">
            <label class="form-label">Müşteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Onceki Bitis</label>
            <input name="previous_end_date_preview" type="datetime-local" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Yeni Bitis Tarihi</label>
            <input name="new_end_date" type="datetime-local" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Uzatma Bedeli</label>
            <input name="additional_income" type="number" step="0.01" min="0" class="form-control" required>
            <div class="form-text">Müşterinin bu uzatma için ödeyeceği toplam tutar.</div>
          </div>
          <div class="border rounded p-3 mb-3 bg-light-subtle">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Orijinal Uzatma Süresi</label>
                <input name="original_extension_days_preview" class="form-control" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label">Yeni Uzatma Süresi</label>
                <input name="current_extension_days_preview" class="form-control" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label">Günlük Bedel</label>
                <input name="daily_rate_preview" class="form-control" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label">Onerilen Yeni Tutar</label>
                <div class="input-group">
                  <input name="suggested_income_preview" class="form-control" readonly>
                  <button type="button" class="btn btn-outline-dark" data-apply-suggested-income>Uygula</button>
                </div>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Ek Masraf</label>
            <input name="additional_expense" type="number" step="0.01" min="0" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Tahsilat Durumu</label>
            <select name="payment_status" class="form-select">
              <option value="pending">Ödeme Daha Sonra Gelecek</option>
              <option value="collected">Tamami Tahsil Edildi</option>
            </select>
            <div class="form-text">Parcali tahsilat varsa sistem durumu otomatik korur.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Beklenen Tahsilat Tarihi</label>
            <input name="payment_due_date" type="datetime-local" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Not</label>
            <input name="note" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-primary" type="submit">Degisiklikleri Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="collectRentalExtensionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Uzatma Tahsilatı Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_collect.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Müşteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Uzatma Dönemi</label>
            <input name="extension_period_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Kalan Tahsilat</label>
            <input name="remaining_amount_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Tahsil Edilen Tutar</label>
            <input name="amount" type="number" step="0.01" min="0.01" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Ödeme Tipi</label>
            <select name="payment_method" class="form-select">
              <option value="">Secmek istemiyorum</option>
              <option value="Nakit">Nakit</option>
              <option value="Havale">Havale</option>
              <option value="EFT">EFT</option>
              <option value="Kart">Kart</option>
            </select>
            <div class="form-text">Zorunlu degil. Istersen bos birakabilirsin.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Not</label>
            <input name="note" class="form-control" placeholder="Havale, nakit, eksik odeme gibi notlar">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-success" type="submit">Tahsilatı Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editRentalExtensionCollectionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tahsilatı Düzenle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_collection_update.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="collection_id" value="">
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Müşteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Tahsilat Tarihi</label>
            <input name="collected_at" type="datetime-local" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tahsilat Tutari</label>
            <input name="amount" type="number" step="0.01" min="0.01" class="form-control" required>
            <div class="form-text">Maksimum duzenlenebilir tutar: <span data-max-collection-amount>-</span></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Ödeme Tipi</label>
            <select name="payment_method" class="form-select">
              <option value="">Secmek istemiyorum</option>
              <option value="Nakit">Nakit</option>
              <option value="Havale">Havale</option>
              <option value="EFT">EFT</option>
              <option value="Kart">Kart</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Not</label>
            <input name="note" class="form-control" placeholder="Tahsilat düzeltme notu">
          </div>
          <div class="alert alert-warning mb-0">
            Sadece son aktif tahsilat kaydi duzenlenebilir. Sistem kalan tahsilati otomatik yeniden hesaplar.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-primary" type="submit">Tahsilatı Güncelle</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="cancelRentalExtensionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Uzatmayı İptal Et</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_cancel.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Müşteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Uzatma Dönemi</label>
            <input name="extension_period_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">İptal Nedeni</label>
            <select name="cancel_reason_option" class="form-select" required>
              <option value="">Seçiniz</option>
              <option value="Musteri uzatmadan vazgecti">Müşteri uzatmadan vazgeçti</option>
              <option value="Gun sayisi revize edildi">Gun sayisi revize edildi</option>
              <option value="Fiyat revize edildi">Fiyat revize edildi</option>
              <option value="Yanlis uzatma kaydi acildi">Yanlis uzatma kaydi acildi</option>
              <option value="Diger">Diğer</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Ek Açıklama</label>
            <textarea name="cancel_reason_detail" class="form-control" rows="3" placeholder="Gerekirse kisa aciklama ekleyin"></textarea>
          </div>
          <div class="alert alert-warning mb-0">
            Tahsilat alınmış uzatmalar iptal edilemez. Önce tahsilat hareketi düzeltilmelidir.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-danger" type="submit">Uzatmayı İptal Et</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($canReverseCollections): ?>
<div class="modal fade" id="cancelRentalExtensionCollectionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tahsilatı Geri Al</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_collection_cancel.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="collection_id" value="">
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <input type="hidden" name="return_to" value="<?= h('rental_detail.php?id=' . (int) $rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Müşteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Tahsilat Tarihi</label>
            <input name="collected_at_preview" type="datetime-local" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Tahsilat Tutari</label>
            <input name="collection_amount_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Geri Alma Nedeni</label>
            <select name="cancel_reason_option" class="form-select" required>
              <option value="">Seçiniz</option>
              <option value="Yanlis tahsilat girildi">Yanlis tahsilat girildi</option>
              <option value="Musteri odemesi geri cekildi">Müşteri ödemesi geri çekildi</option>
              <option value="Yanlis uzatmaya tahsilat isledi">Yanlis uzatmaya tahsilat isledi</option>
              <option value="Tutar revize edildi">Tutar revize edildi</option>
              <option value="Diger">Diğer</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Ek Açıklama</label>
            <textarea name="cancel_reason_detail" class="form-control" rows="3" placeholder="Gerekirse kisa not ekleyin"></textarea>
          </div>
          <div class="alert alert-warning mb-0">
            Sadece son aktif tahsilat kaydi geri alinabilir. Kayit silinmez, gecmiste iz kalir.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-danger" type="submit">Tahsilatı Geri Al</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="deleteRentalExtensionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Uzatma Kaydını Tamamen Sil</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_delete.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="alert alert-warning">
            Bu islem iptal edilmis uzatma kaydini, bagli tahsilat gecmisini ve revizyon kayitlarini tamamen kaldirir.
          </div>
          <div class="mb-3">
            <label class="form-label">Müşteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Uzatma Dönemi</label>
            <input name="extension_period_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">İptal Edilen Tahsilat Toplamı</label>
            <input name="collected_amount_preview" class="form-control" readonly>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-danger" type="submit">Tamamen Sil</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
