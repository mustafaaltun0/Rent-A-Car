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
$totals = getRentalTotals($rental, $extensionsByRentalId, $collectionsByExtensionId);
$document = rental_ensure_document($pdo, $companyId, (int) $rental['id'], 'collection_receipt', $currentUserId);
$companyLogoUrl = auth_company_logo_public_url($company);
$companyLogoVersion = rawurlencode((string) ($company['logo_path'] ?? 'logo'));
$carLabel = trim(($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? ''));
$extensionCollections = [];
foreach ($extensionsByRentalId[(int) ($rental['id'] ?? 0)] ?? [] as $extension) {
    $extensionId = (int) ($extension['id'] ?? 0);
    foreach ($collectionsByExtensionId[$extensionId] ?? [] as $collection) {
        if (!rental_extension_collection_is_active($collection)) {
            continue;
        }
        $extensionCollections[] = $collection;
    }
}
usort($extensionCollections, static function (array $left, array $right): int {
    return strcmp((string) ($left['collected_at'] ?? ''), (string) ($right['collected_at'] ?? ''));
});

$paymentMethodLabels = [];
foreach ($extensionCollections as $collection) {
    $paymentMethod = trim((string) ($collection['payment_method'] ?? ''));
    if ($paymentMethod !== '') {
        $paymentMethodLabels[$paymentMethod] = true;
    }
}
$paymentMethodSummary = '-';
if (!empty($paymentMethodLabels)) {
    $paymentMethodSummary = implode(', ', array_keys($paymentMethodLabels));
}
$pageTitle = 'Tahsilat Makbuzu';
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
        </div>
      </div>
      <div class="print-document-meta">
        <p><strong>Belge:</strong> <?= h(rental_document_title('collection_receipt')) ?></p>
        <p><strong>Belge No:</strong> <?= h($document['document_number'] ?? '-') ?></p>
        <p><strong>Tarih:</strong> <?= h(date('d.m.Y H:i')) ?></p>
        <p><strong>Kiralama No:</strong> #<?= h((string) $rental['id']) ?></p>
      </div>
    </div>

    <div class="print-section">
      <h2>Tahsilat Bilgileri</h2>
      <div class="print-grid">
        <div class="print-item"><span class="print-label">Musteri</span><strong><?= h($rental['customer_name']) ?></strong></div>
        <div class="print-item"><span class="print-label">Telefon</span><strong><?= h($rental['customer_phone']) ?: '-' ?></strong></div>
        <div class="print-item"><span class="print-label">Arac</span><strong><?= h($carLabel ?: '-') ?></strong></div>
        <div class="print-item"><span class="print-label">Kiralama Donemi</span><strong><?= dt($rental['start_date']) ?> - <?= dt($rental['end_date']) ?></strong></div>
        <div class="print-item"><span class="print-label">Odeme Tipi</span><strong><?= h($paymentMethodSummary) ?></strong></div>
        <div class="print-item print-item-full"><span class="print-label">Tahsil Edilen Tutar</span><strong><?= money($totals['income']) ?></strong></div>
        <?php if (($totals['pending_income'] ?? 0) > 0): ?><div class="print-item print-item-full"><span class="print-label">Bekleyen Tahsilat</span><strong><?= money($totals['pending_income']) ?></strong></div><?php endif; ?>
      </div>
    </div>

    <?php if (!empty($extensionCollections)): ?>
    <div class="print-section">
      <h2>Tahsilat Hareketleri</h2>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
          <tr><th>Tarih</th><th>Tutar</th><th>Odeme Tipi</th><th>Not</th></tr>
          <?php foreach ($extensionCollections as $collection): ?>
          <tr>
            <td><?= dt($collection['collected_at'] ?? null) ?></td>
            <td><?= money($collection['amount'] ?? 0) ?></td>
            <td><?= h($collection['payment_method'] ?? '') ?: '-' ?></td>
            <td><?= h($collection['note'] ?? '') ?: '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="print-section">
      <h2>Aciklama</h2>
      <div class="print-item print-item-full">
        <?= h($rental['customer_name']) ?> isimli musteriden, <?= h($carLabel ?: 'ilgili arac') ?> kiralama islemine ait toplam tahsilat bu belge ile kayit altina alinmistir.
      </div>
    </div>

    <div class="print-signature-grid">
      <div class="print-signature-box">
        <strong>Firma Yetkilisi</strong>
        <div class="text-muted mt-2">Ad Soyad / Imza</div>
      </div>
      <div class="print-signature-box">
        <strong>Odeme Yapan</strong>
        <div class="text-muted mt-2">Ad Soyad / Imza</div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
