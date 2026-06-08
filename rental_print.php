<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.view');

ensureRentalExtensionSchema($pdo);
ensureRentalDocumentSchema($pdo);
$companyId = auth_current_company_id();
$company = auth_current_company($pdo);
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$st = $pdo->prepare('SELECT r.*, c.brand, c.model, c.plate FROM rentals r LEFT JOIN cars c ON c.id = r.car_id AND c.company_id = r.company_id WHERE r.id = ? AND r.company_id = ?');
$st->execute([$id, $companyId]);
$rental = $st->fetch();
if (!$rental || !$company) {
    redirect('rentals.php');
}

$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$extensions = $extensionsByRentalId[$id] ?? [];
$totals = getRentalTotals($rental, $extensionsByRentalId, $collectionsByExtensionId);
$document = rental_ensure_document($pdo, $companyId, (int) $rental['id'], 'rental_summary', $currentUserId);
$carLabel = trim(($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? ''));
$companyLogoUrl = auth_company_logo_public_url($company);
$companyLogoVersion = rawurlencode((string) ($company['logo_path'] ?? 'logo'));
$pageTitle = 'Kiralama Ciktisi';
require __DIR__ . '/includes/header.php';
?>
<div class="print-shell">
  <div class="print-toolbar">
    <button type="button" class="btn btn-dark" onclick="window.print()">Yazdir</button>
    <a href="rental_detail.php?id=<?= h((string) $rental['id']) ?>" class="btn btn-outline-dark">Detaya Don</a>
  </div>

  <div class="print-document">
    <div class="print-header">
      <div class="print-company">
        <?php if ($companyLogoUrl): ?><img src="<?= h($companyLogoUrl . '?v=' . $companyLogoVersion) ?>" alt="Firma logosu" class="print-company-logo" width="84" height="84" style="width:84px; height:84px; max-width:84px; max-height:84px; object-fit:contain; display:block;"><?php endif; ?>
        <div class="print-company-meta">
          <h1><?= h($company['legal_name'] ?: $company['name']) ?></h1>
          <p><?= h($company['name']) ?></p>
          <?php if (!empty($company['phone'])): ?><p>Telefon: <?= h($company['phone']) ?></p><?php endif; ?>
          <?php if (!empty($company['email'])): ?><p>E-posta: <?= h($company['email']) ?></p><?php endif; ?>
          <?php if (!empty($company['address'])): ?><p>Adres: <?= nl2br(h($company['address'])) ?></p><?php endif; ?>
          <?php if (!empty($company['tax_office']) || !empty($company['tax_number'])): ?><p>Vergi: <?= h(trim((string) ($company['tax_office'] ?? '') . ' / ' . (string) ($company['tax_number'] ?? ''), ' /')) ?></p><?php endif; ?>
        </div>
      </div>
      <div class="print-document-meta">
        <p><strong>Belge:</strong> <?= h(rental_document_title('rental_summary')) ?></p>
        <p><strong>Belge No:</strong> <?= h($document['document_number'] ?? '-') ?></p>
        <p><strong>Belge Tarihi:</strong> <?= h(date('d.m.Y H:i')) ?></p>
        <p><strong>Kiralama No:</strong> #<?= h((string) $rental['id']) ?></p>
        <p><strong>Durum:</strong> <?= (int) ($rental['completed'] ?? 0) === 1 ? 'Teslim Alindi' : 'Aktif Kirada' ?></p>
      </div>
    </div>

    <div class="print-section">
      <h2>Musteri ve Arac Bilgileri</h2>
      <div class="print-grid">
        <div class="print-item"><span class="print-label">Musteri</span><strong><?= h($rental['customer_name']) ?></strong></div>
        <div class="print-item"><span class="print-label">Telefon</span><strong><?= h($rental['customer_phone']) ?: '-' ?></strong></div>
        <div class="print-item"><span class="print-label">TC Kimlik No</span><strong><?= h($rental['customer_identity_no']) ?: '-' ?></strong></div>
        <div class="print-item"><span class="print-label">Arac</span><strong><?= h($carLabel ?: '-') ?></strong></div>
        <div class="print-item"><span class="print-label">Baslangic</span><strong><?= dt($rental['start_date']) ?></strong></div>
        <div class="print-item"><span class="print-label">Bitis</span><strong><?= dt($rental['end_date']) ?></strong></div>
        <div class="print-item"><span class="print-label">Cikis KM</span><strong><?= $rental['departure_km'] !== null && $rental['departure_km'] !== '' ? h(number_format((int) $rental['departure_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
        <div class="print-item"><span class="print-label">Donus KM</span><strong><?= $rental['return_km'] !== null && $rental['return_km'] !== '' ? h(number_format((int) $rental['return_km'], 0, ',', '.')) . ' km' : '-' ?></strong></div>
      </div>
    </div>

    <div class="print-section">
      <h2>Finansal Ozet</h2>
      <div class="print-financials">
        <div class="print-total-card"><span class="print-label">Toplam Gelir</span><strong><?= money($totals['income']) ?></strong></div>
        <div class="print-total-card"><span class="print-label">Toplam Masraf</span><strong><?= money($totals['expense']) ?></strong></div>
        <div class="print-total-card"><span class="print-label">Net Kar</span><strong><?= money($totals['net_profit']) ?></strong></div>
      </div>
    </div>

    <div class="print-section">
      <h2>Uzatma Gecmisi</h2>
      <div class="print-grid">
        <?php if (empty($extensions)): ?>
        <div class="print-item print-item-full"><strong>Bu kiralama icin uzatma kaydi yok.</strong></div>
        <?php else: ?>
          <?php foreach ($extensions as $index => $extension): ?>
          <?php
            $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
            $pendingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);
            $displayExtension = $extension;
            $displayExtension['payment_status'] = rental_extension_effective_payment_status($extension, $collectionsByExtensionId);
          ?>
          <div class="print-item print-item-full">
            <span class="print-label">Uzatma <?= $index + 1 ?></span>
            <strong><?= dt($extension['previous_end_date']) ?> -> <?= dt($extension['new_end_date']) ?></strong>
            <div class="mt-2">Durum: <?= h(rental_extension_status_label($displayExtension)) ?> | Gelir: <?= money($extension['income']) ?> | Masraf: <?= money($extension['expense']) ?> | Net Kar: <?= money($extension['net_profit']) ?></div>
            <div class="mt-1">Tahsil Edilen: <?= money($collectedAmount) ?> | Bekleyen: <?= money($pendingAmount) ?></div>
            <div class="text-muted mt-1"><?= h($extension['note']) ?: 'Not yok.' ?></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="print-signature-grid">
      <div class="print-signature-box">
        <strong>Firma Yetkilisi</strong>
        <div class="text-muted mt-2">Ad Soyad / Imza</div>
      </div>
      <div class="print-signature-box">
        <strong>Musteri</strong>
        <div class="text-muted mt-2">Ad Soyad / Imza</div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
