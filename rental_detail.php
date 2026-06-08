<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.view');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarTelematicsSchema($pdo);
ensureCustomerCompanySchema($pdo);
$companyId = auth_current_company_id();
$customerCompaniesEnabled = app_feature_customer_companies_enabled();
$canManageRentals = auth_can('rentals.manage');
$entryStatus = $_GET['status'] ?? '';

if (!function_exists('rental_extension_revision_label')) {
    function rental_extension_revision_label(string $actionType): string
    {
        $map = [
            'created' => 'Uzatma olusturuldu',
            'updated' => 'Uzatma guncellendi',
            'collection_added' => 'Tahsilat eklendi',
            'collection_updated' => 'Tahsilat guncellendi',
            'collection_cancelled' => 'Tahsilat geri alindi',
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
            return $reason !== '' ? 'Neden: ' . $reason : 'Iptal edildi.';
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
        c.brand,
        c.model,
        c.plate,
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
$isArchivedRental = rental_is_archived($rental);
$rentalStart = !empty($rental['start_date']) ? new DateTimeImmutable($rental['start_date']) : null;
$initialEnd = !empty($rental['initial_end_date']) ? new DateTimeImmutable($rental['initial_end_date']) : (!empty($rental['end_date']) ? new DateTimeImmutable($rental['end_date']) : null);
$currentEnd = !empty($rental['end_date']) ? new DateTimeImmutable($rental['end_date']) : null;
$initialRentalDays = ($rentalStart && $initialEnd && $initialEnd > $rentalStart) ? (int) ceil(($initialEnd->getTimestamp() - $rentalStart->getTimestamp()) / 86400) : null;
$currentRentalDays = ($rentalStart && $currentEnd && $currentEnd > $rentalStart) ? (int) ceil(($currentEnd->getTimestamp() - $rentalStart->getTimestamp()) / 86400) : null;
$kmMetrics = rental_km_metrics($rental, $rental);
$drivenKm = $kmMetrics['distance_km'];
$carLabel = trim(($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? ''));
$statusLabel = (int) ($rental['completed'] ?? 0) === 1 ? 'Teslim Alindi' : 'Aktif Kirada';
$statusClass = (int) ($rental['completed'] ?? 0) === 1 ? 'status-success' : 'status-danger';

$pageTitle = 'Kiralama Detay';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="rental-detail-page">
  <?php if ($entryStatus === 'extension_saved'): ?>
  <div class="alert alert-success">Uzatma kaydi olusturuldu. Tahsilat plani da kayda alindi.</div>
  <?php elseif ($entryStatus === 'extension_collected'): ?>
  <div class="alert alert-success">Uzatma tahsilati kaydedildi.</div>
  <?php elseif ($entryStatus === 'extension_collection_updated'): ?>
  <div class="alert alert-success">Tahsilat kaydi guncellendi.</div>
  <?php elseif ($entryStatus === 'extension_updated'): ?>
  <div class="alert alert-success">Son uzatma kaydi guncellendi.</div>
  <?php elseif ($entryStatus === 'extension_cancelled'): ?>
  <div class="alert alert-success">Son uzatma kaydi iptal edildi ve kiralama suresi geri alindi.</div>
  <?php elseif ($entryStatus === 'extension_deleted'): ?>
  <div class="alert alert-success">Iptal edilmis uzatma kaydi tamamen silindi.</div>
  <?php elseif ($entryStatus === 'extension_not_editable'): ?>
  <div class="alert alert-danger">Sadece son aktif uzatma kaydi duzenlenebilir veya iptal edilebilir.</div>
  <?php elseif ($entryStatus === 'extension_delete_requires_cancel'): ?>
  <div class="alert alert-danger">Bir uzatmayi tamamen silmeden once iptal etmelisin.</div>
  <?php elseif ($entryStatus === 'extension_delete_error'): ?>
  <div class="alert alert-danger">Uzatma kaydi silinirken beklenmeyen bir sorun olustu.</div>
  <?php elseif ($entryStatus === 'extension_invalid'): ?>
  <div class="alert alert-danger">Uzatma bilgileri gecersiz. Yeni bitis tarihi onceki bitisten ileri olmali.</div>
  <?php elseif ($entryStatus === 'extension_not_collectible'): ?>
  <div class="alert alert-danger">Bu uzatma kaydi tahsilata uygun degil.</div>
  <?php elseif ($entryStatus === 'extension_collection_invalid'): ?>
  <div class="alert alert-danger">Tahsilat tutari gecersiz. Kalan tutardan buyuk olamaz.</div>
  <?php elseif ($entryStatus === 'extension_collection_update_invalid'): ?>
  <div class="alert alert-danger">Tahsilat guncelleme bilgileri gecersiz. Tutar 0'dan buyuk olmali ve izin verilen siniri asmamali.</div>
  <?php elseif ($entryStatus === 'extension_collection_conflict'): ?>
  <div class="alert alert-danger">Toplanan tahsilat nedeniyle uzatma geliri bu seviyenin altina dusurulemez.</div>
  <?php elseif ($entryStatus === 'extension_collection_update_conflict'): ?>
  <div class="alert alert-danger">Bu tahsilat bu seviyeye guncellenemez. Diger tahsilatlarla birlikte uzatma gelirini asiyor.</div>
  <?php elseif ($entryStatus === 'extension_cancel_collection_conflict'): ?>
  <div class="alert alert-danger">Bu uzatma icin tahsilat alinmis. Once tahsilati duzeltmeden uzatma iptal edilemez.</div>
  <?php elseif ($entryStatus === 'extension_collection_cancelled'): ?>
  <div class="alert alert-success">Tahsilat kaydi geri alindi.</div>
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
      <div class="rental-hero-subtitle"><?= h($carLabel ?: 'Arac bilgisi yok') ?></div>
      <?php if ($customerCompaniesEnabled && !empty($rental['customer_company_name'])): ?>
      <div class="text-muted mt-2"><?= h($rental['customer_company_name']) ?></div>
      <?php endif; ?>
    </div>
    <div class="rental-hero-actions">
      <div class="status-line rental-status-pill"><span class="status-dot <?= $statusClass ?>"></span><span><?= h($statusLabel) ?></span></div>
      <a href="rental_print.php?id=<?= h((string) $rental['id']) ?>" class="btn btn-dark" target="_blank" rel="noopener">Yazdir</a>
      <a href="rental_receipt.php?id=<?= h((string) $rental['id']) ?>" class="btn btn-outline-dark" target="_blank" rel="noopener">Makbuz</a>
      <a href="rentals.php" class="btn btn-light">Kiralamalara Don</a>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card bg-primary shadow-sm"><h6>Tahsil Edilen</h6><h3><?= money($totals['income']) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-info shadow-sm"><h6>Bekleyen Tahsilat</h6><h3><?= money($totals['pending_income'] ?? 0) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-dark shadow-sm"><h6>Net Kar</h6><h3><?= money($totals['net_profit']) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-secondary shadow-sm"><h6>Sozlesme Toplami</h6><h3><?= money($totals['contract_income'] ?? 0) ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-success shadow-sm"><h6>Kiralama Suresi</h6><h3><?= $currentRentalDays !== null ? h($currentRentalDays) . ' gun' : '-' ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-warning shadow-sm"><h6>Yapilan KM</h6><h3><?= $drivenKm !== null ? h(number_format($drivenKm, 0, ',', '.')) . ' km' : '-' ?></h3></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card bg-danger shadow-sm"><h6>Gunluk Ort. KM</h6><h3><?= $kmMetrics['average_daily_km'] !== null ? h(number_format((float) $kmMetrics['average_daily_km'], 1, ',', '.')) . ' km' : '-' ?></h3></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="card shadow-sm h-100">
        <div class="card-header">Kiralama Bilgileri</div>
        <div class="card-body">
          <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Telefon</span><strong><?= h($rental['customer_phone']) ?: '-' ?></strong></div>
            <?php if ($customerCompaniesEnabled): ?><div class="detail-item"><span class="detail-label">Kurumsal Musteri</span><strong><?= h($rental['customer_company_name']) ?: '-' ?></strong></div><?php endif; ?>
            <div class="detail-item"><span class="detail-label">TC Kimlik No</span><strong><?= h($rental['customer_identity_no']) ?: '-' ?></strong></div>
            <?php if ($customerCompaniesEnabled): ?><div class="detail-item"><span class="detail-label">Firma Yetkilisi</span><strong><?= h($rental['customer_company_contact_name']) ?: '-' ?></strong></div><?php endif; ?>
            <div class="detail-item"><span class="detail-label">Baslangic</span><strong><?= dt($rental['start_date']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Ilk Bitis</span><strong><?= dt($rental['initial_end_date'] ?? $rental['end_date']) ?><?= $initialRentalDays !== null ? ' / ' . h($initialRentalDays) . ' gun' : '' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Guncel Bitis</span><strong><?= dt($rental['end_date']) ?><?= $currentRentalDays !== null ? ' / ' . h($currentRentalDays) . ' gun' : '' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Arac</span><strong><?= h($carLabel ?: '-') ?></strong></div>
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
            <div class="detail-item"><span class="detail-label">Donus / Anlik KM</span><strong><?= $kmMetrics['effective_end_km'] !== null ? h(number_format((int) $kmMetrics['effective_end_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">KM Veri Kaynagi</span><strong><?= h($kmMetrics['distance_source']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Gunluk Ortalama</span><strong><?= $kmMetrics['average_daily_km'] !== null ? h(number_format((float) $kmMetrics['average_daily_km'], 1, ',', '.')) . ' km' : '-' ?></strong></div>
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
          <span class="detail-label">Donem</span>
          <strong><?= dt($rental['start_date']) ?> - <?= dt($rental['initial_end_date'] ?? $rental['end_date']) ?></strong>
        </div>
        <div class="period-summary-stats">
          <div><span class="detail-label">Gelir</span><strong><?= money($rental['income']) ?></strong></div>
          <div><span class="detail-label">Masraf</span><strong><?= money($rental['expense']) ?></strong></div>
          <div><span class="detail-label">Net Kar</span><strong><?= money($rental['net_profit']) ?></strong></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">Uzatma Gecmisi</div>
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
            <div class="detail-item"><span class="detail-label">Yeni Bitis</span><strong><?= dt($extension['new_end_date']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Beklenen Tahsilat</span><strong><?= !empty($extension['payment_due_date']) ? dt($extension['payment_due_date']) : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Son Tahsilat</span><strong><?= !empty($extension['collected_at']) ? dt($extension['collected_at']) : '-' ?></strong></div>
            <div class="detail-item"><span class="detail-label">Ek Masraf</span><strong><?= money($extension['expense']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Ek Net Kar</span><strong><?= money($extension['net_profit']) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Tahsil Edilen</span><strong><?= money($collectedAmount) ?></strong></div>
            <div class="detail-item"><span class="detail-label">Kalan Tahsilat</span><strong><?= money($pendingAmount) ?></strong></div>
            <div class="detail-item detail-item-full"><span class="detail-label">Not</span><strong><?= h($extension['note']) ?: '-' ?></strong></div>
            <?php if (!empty($extension['cancel_reason'])): ?><div class="detail-item detail-item-full"><span class="detail-label">Iptal Nedeni</span><strong><?= h($extension['cancel_reason']) ?></strong></div><?php endif; ?>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-lg-6">
              <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-2">Tahsilat Gecmisi</div>
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
                      <td><?= h($collection['payment_method'] ?? '') ?: '-' ?><?= !empty($collection['note']) ? ' / ' . h($collection['note']) : '' ?><?= !empty($collection['cancel_reason']) ? ' / Iptal: ' . h($collection['cancel_reason']) : '' ?></td>
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
                        >Duzenle</button>
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
                <div class="fw-semibold mb-2">Revizyon Gecmisi</div>
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
              data-new_end_date="<?= h($extension['new_end_date'] ? date('Y-m-d\TH:i', strtotime($extension['new_end_date'])) : '') ?>"
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
              data-new_end_date="<?= h($extension['new_end_date'] ? date('Y-m-d\TH:i', strtotime($extension['new_end_date'])) : '') ?>"
              data-additional_income="<?= h(number_format((float) ($extension['income'] ?? 0), 2, '.', '')) ?>"
              data-additional_expense="<?= h(number_format((float) ($extension['expense'] ?? 0), 2, '.', '')) ?>"
              data-payment_status="<?= h($effectivePaymentStatus === 'partial' ? 'pending' : $effectivePaymentStatus) ?>"
              data-payment_due_date="<?= h(!empty($extension['payment_due_date']) ? date('Y-m-d\TH:i', strtotime($extension['payment_due_date'])) : '') ?>"
              data-note="<?= h($extension['note']) ?>"
            >Uzatmayi Duzenle</button>
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
              >Uzatmayi Iptal Et</button>
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
                data-new_end_date="<?= h($extension['new_end_date'] ? date('Y-m-d\\TH:i', strtotime($extension['new_end_date'])) : '') ?>"
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
        <h5 class="modal-title">Uzatma Kaydini Duzenle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_update.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Musteri</label>
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
            <label class="form-label">Ek Gelir</label>
            <input name="additional_income" type="number" step="0.01" min="0" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Ek Masraf</label>
            <input name="additional_expense" type="number" step="0.01" min="0" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Tahsilat Durumu</label>
            <select name="payment_status" class="form-select">
              <option value="pending">Odeme Daha Sonra Gelecek</option>
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
        <h5 class="modal-title">Uzatma Tahsilati Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_collect.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Musteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Uzatma Donemi</label>
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
            <label class="form-label">Odeme Tipi</label>
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
          <button class="btn btn-success" type="submit">Tahsilati Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editRentalExtensionCollectionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tahsilati Duzenle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_collection_update.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="collection_id" value="">
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Musteri</label>
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
            <label class="form-label">Odeme Tipi</label>
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
            <input name="note" class="form-control" placeholder="Tahsilat duzeltme notu">
          </div>
          <div class="alert alert-warning mb-0">
            Sadece son aktif tahsilat kaydi duzenlenebilir. Sistem kalan tahsilati otomatik yeniden hesaplar.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-primary" type="submit">Tahsilati Guncelle</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="cancelRentalExtensionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Uzatmayi Iptal Et</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_cancel.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Musteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Uzatma Donemi</label>
            <input name="extension_period_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Iptal Nedeni</label>
            <select name="cancel_reason_option" class="form-select" required>
              <option value="">Seciniz</option>
              <option value="Musteri uzatmadan vazgecti">Musteri uzatmadan vazgecti</option>
              <option value="Gun sayisi revize edildi">Gun sayisi revize edildi</option>
              <option value="Fiyat revize edildi">Fiyat revize edildi</option>
              <option value="Yanlis uzatma kaydi acildi">Yanlis uzatma kaydi acildi</option>
              <option value="Diger">Diger</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Ek Aciklama</label>
            <textarea name="cancel_reason_detail" class="form-control" rows="3" placeholder="Gerekirse kisa aciklama ekleyin"></textarea>
          </div>
          <div class="alert alert-warning mb-0">
            Tahsilat alinmis uzatmalar iptal edilemez. Once tahsilat hareketi duzeltilmelidir.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-danger" type="submit">Uzatmayi Iptal Et</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="cancelRentalExtensionCollectionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tahsilati Geri Al</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/rental_extension_collection_cancel.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="collection_id" value="">
          <input type="hidden" name="extension_id" value="">
          <input type="hidden" name="rental_id" value="<?= h($rental['id']) ?>">
          <div class="mb-3">
            <label class="form-label">Musteri</label>
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
              <option value="">Seciniz</option>
              <option value="Yanlis tahsilat girildi">Yanlis tahsilat girildi</option>
              <option value="Musteri odemesi geri cekildi">Musteri odemesi geri cekildi</option>
              <option value="Yanlis uzatmaya tahsilat isledi">Yanlis uzatmaya tahsilat isledi</option>
              <option value="Tutar revize edildi">Tutar revize edildi</option>
              <option value="Diger">Diger</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Ek Aciklama</label>
            <textarea name="cancel_reason_detail" class="form-control" rows="3" placeholder="Gerekirse kisa not ekleyin"></textarea>
          </div>
          <div class="alert alert-warning mb-0">
            Sadece son aktif tahsilat kaydi geri alinabilir. Kayit silinmez, gecmiste iz kalir.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-danger" type="submit">Tahsilati Geri Al</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="deleteRentalExtensionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Uzatma Kaydini Tamamen Sil</h5>
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
            <label class="form-label">Musteri</label>
            <input name="customer_name_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Uzatma Donemi</label>
            <input name="extension_period_preview" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Iptal Edilen Tahsilat Toplami</label>
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
