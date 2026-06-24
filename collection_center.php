<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.view');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarSaleSchema($pdo);
ensureCarPhotoSchema($pdo);
$companyId = auth_current_company_id();
$canManageRentals = auth_can('rentals.manage');
$canManageCars = auth_can('cars.manage');
$status = $_GET['status'] ?? '';

$rentalsSt = $pdo->prepare('
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
        c.photo_focus_y
    FROM rentals r
    LEFT JOIN cars c
        ON c.id = r.car_id
       AND c.company_id = r.company_id
    WHERE r.company_id = ?
      AND r.archived_at IS NULL
    ORDER BY r.completed ASC, r.id DESC
');
$rentalsSt->execute([$companyId]);
$rentals = $rentalsSt->fetchAll(PDO::FETCH_ASSOC);
$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$centerData = buildRentalCollectionCenterData($rentals, $extensionsByRentalId, $collectionsByExtensionId);
$summary = $centerData['summary'];
$pendingItems = $centerData['pending_items'];
$recentCollections = $centerData['recent_collections'];
$pendingItemsPagination = paginate_collection($pendingItems, 'pending_page', 'pending_per_page', 10, [10, 20, 50, 100]);
$recentCollectionsPagination = paginate_collection($recentCollections, 'recent_page', 'recent_per_page', 10, [10, 20, 50, 100]);
$pendingItems = $pendingItemsPagination['items'];
$recentCollections = $recentCollectionsPagination['items'];

$carSalesSt = $pdo->prepare("
    SELECT
        cs.*,
        c.id AS car_id,
        c.plate,
        c.brand,
        c.model,
        c.photo_path,
        c.photo_position_x,
        c.photo_position_y,
        c.photo_focus_x,
        c.photo_focus_y
    FROM car_sales cs
    LEFT JOIN cars c
        ON c.id = cs.car_id
       AND c.company_id = cs.company_id
    WHERE cs.company_id = ?
      AND cs.sale_status = 'active'
    ORDER BY cs.sale_date DESC, cs.id DESC
");
$carSalesSt->execute([$companyId]);
$carSales = $carSalesSt->fetchAll(PDO::FETCH_ASSOC);
$carSaleCollectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, array_map(static fn (array $sale): int => (int) ($sale['id'] ?? 0), $carSales));
$latestCarSaleCollectionIdMap = [];
$latestCarSaleCollectionBySaleId = [];
$carSaleSummary = [
    'overdue_amount' => 0.0,
    'due_today_amount' => 0.0,
    'pending_total' => 0.0,
    'active_pending_count' => 0,
    'collected_this_month' => 0.0,
    'collected_this_month_count' => 0,
];
$carSalePendingItems = [];
$carSaleRecentCollections = [];
$todayDate = new DateTimeImmutable(date('Y-m-d'));
$monthStart = new DateTimeImmutable(date('Y-m-01 00:00:00'));

foreach ($carSales as $sale) {
    $saleId = (int) ($sale['id'] ?? 0);
    $carLabel = trim((string) (($sale['brand'] ?? '') . ' ' . ($sale['model'] ?? '')));
    if (!empty($sale['plate'])) {
        $carLabel .= ($carLabel !== '' ? ' / ' : '') . (string) $sale['plate'];
    }

    $collectedAmount = car_sale_collected_amount($sale, $carSaleCollectionsBySaleId);
    $pendingAmount = car_sale_pending_amount($sale, $carSaleCollectionsBySaleId);
    $dueDateRaw = $sale['payment_due_date'] ?? null;
    $urgency = 'secondary';
    $daysLeft = null;

    if ($pendingAmount > 0.0) {
        $carSaleSummary['pending_total'] += $pendingAmount;
        $carSaleSummary['active_pending_count']++;

        if (!empty($dueDateRaw)) {
            $dueDate = new DateTimeImmutable((string) $dueDateRaw);
            $daysLeft = (int) $todayDate->diff($dueDate)->format('%r%a');
            if ($daysLeft < 0) {
                $urgency = 'danger';
                $carSaleSummary['overdue_amount'] += $pendingAmount;
            } elseif ($daysLeft === 0) {
                $urgency = 'warning';
                $carSaleSummary['due_today_amount'] += $pendingAmount;
            } elseif ($daysLeft <= 3) {
                $urgency = 'info';
            }
        }

        $carSalePendingItems[] = [
            'sale_id' => $saleId,
            'car_id' => (int) ($sale['car_id'] ?? 0),
            'car_photo_path' => $sale['photo_path'] ?? null,
            'car_photo_position_x' => $sale['photo_position_x'] ?? 'center',
            'car_photo_position_y' => $sale['photo_position_y'] ?? 'center',
            'car_photo_focus_x' => $sale['photo_focus_x'] ?? null,
            'car_photo_focus_y' => $sale['photo_focus_y'] ?? null,
            'buyer_name' => $sale['buyer_name'] ?? '-',
            'car_label' => $carLabel !== '' ? $carLabel : 'Silinmiş Araç',
            'contract_amount' => (float) ($sale['total_amount'] ?? 0),
            'collected_amount' => $collectedAmount,
            'pending_amount' => $pendingAmount,
            'due_date' => $dueDateRaw,
            'urgency' => $urgency,
            'days_left' => $daysLeft,
        ];
    }

    foreach ($carSaleCollectionsBySaleId[$saleId] ?? [] as $collection) {
        if (!car_sale_collection_is_active($collection)) {
            continue;
        }

        $latestCarSaleCollectionIdMap[$saleId] = (int) ($collection['id'] ?? 0);
        $latestCarSaleCollectionBySaleId[$saleId] = $collection;

        $collectedAt = !empty($collection['collected_at']) ? new DateTimeImmutable((string) $collection['collected_at']) : null;
        if ($collectedAt && $collectedAt >= $monthStart) {
            $carSaleSummary['collected_this_month'] += (float) ($collection['amount'] ?? 0);
            $carSaleSummary['collected_this_month_count']++;
        }

        $carSaleRecentCollections[] = [
            'sale_id' => $saleId,
            'car_id' => (int) ($sale['car_id'] ?? 0),
            'car_photo_path' => $sale['photo_path'] ?? null,
            'car_photo_position_x' => $sale['photo_position_x'] ?? 'center',
            'car_photo_position_y' => $sale['photo_position_y'] ?? 'center',
            'car_photo_focus_x' => $sale['photo_focus_x'] ?? null,
            'car_photo_focus_y' => $sale['photo_focus_y'] ?? null,
            'buyer_name' => $sale['buyer_name'] ?? '-',
            'car_label' => $carLabel !== '' ? $carLabel : 'Silinmiş Araç',
            'amount' => (float) ($collection['amount'] ?? 0),
            'payment_method' => (string) ($collection['payment_method'] ?? ''),
            'note' => (string) ($collection['note'] ?? ''),
            'collected_at' => $collection['collected_at'] ?? null,
            'collection_id' => (int) ($collection['id'] ?? 0),
        ];
    }
}

usort($carSalePendingItems, static function (array $left, array $right): int {
    $leftDue = $left['due_date'] ?? '9999-12-31 23:59:59';
    $rightDue = $right['due_date'] ?? '9999-12-31 23:59:59';
    return strcmp((string) $leftDue, (string) $rightDue);
});
usort($carSaleRecentCollections, static function (array $left, array $right): int {
    return strcmp((string) ($right['collected_at'] ?? ''), (string) ($left['collected_at'] ?? ''));
});

$carSalePendingPagination = paginate_collection($carSalePendingItems, 'sale_pending_page', 'sale_pending_per_page', 10, [10, 20, 50, 100]);
$carSaleRecentPagination = paginate_collection($carSaleRecentCollections, 'sale_recent_page', 'sale_recent_per_page', 10, [10, 20, 50, 100]);
$carSalePendingItems = $carSalePendingPagination['items'];
$carSaleRecentCollections = $carSaleRecentPagination['items'];

$pageTitle = 'Tahsilat Merkezi';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="collection-center-page">
  <div class="rentals-hero mb-4">
    <div>
      <div class="rentals-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Tahsilat Merkezi</h2>
      <div class="rentals-hero-subtitle">Bekleyen uzatma tahsilatlarını, geciken ödemeleri ve son tahsilat hareketlerini tek ekranda yönet.</div>
    </div>
  </div>

  <?php if ($status === 'extension_collected'): ?>
  <div class="alert alert-success">Tahsilat kaydedildi.</div>
  <?php elseif ($status === 'extension_collection_cancelled'): ?>
  <div class="alert alert-success">Tahsilat geri alindi.</div>
  <?php elseif ($status === 'extension_collection_invalid'): ?>
  <div class="alert alert-danger">Tahsilat tutari gecersiz. Kalan tutardan buyuk ya da sifir olamaz.</div>
  <?php elseif ($status === 'extension_not_collectible'): ?>
  <div class="alert alert-danger">Bu uzatma kaydı için şu anda tahsilat girilemez.</div>
  <?php elseif ($status === 'car_sale_collected'): ?>
  <div class="alert alert-success">Araç satış tahsilatı kaydedildi.</div>
  <?php elseif ($status === 'car_sale_collection_cancelled'): ?>
  <div class="alert alert-success">Araç satış tahsilatı geri alındı.</div>
  <?php elseif ($status === 'car_sale_collection_invalid'): ?>
  <div class="alert alert-danger">Araç satış tahsilat tutarı geçersiz.</div>
  <?php elseif ($status === 'car_sale_collection_updated'): ?>
  <div class="alert alert-success">Araç satış tahsilatı güncellendi.</div>
  <?php elseif ($status === 'car_sale_collection_update_invalid'): ?>
  <div class="alert alert-danger">Araç satış tahsilat güncelleme verisi geçersiz.</div>
  <?php elseif ($status === 'car_sale_collection_update_conflict'): ?>
  <div class="alert alert-danger">Bu tahsilat düzeyi toplam satış bedelini aşıyor.</div>
  <?php elseif ($status === 'car_sale_updated'): ?>
  <div class="alert alert-success">Araç satış kaydı güncellendi.</div>
  <?php elseif ($status === 'car_sale_update_invalid'): ?>
  <div class="alert alert-danger">Araç satış güncelleme verisi geçersiz.</div>
  <?php elseif ($status === 'car_sale_update_conflict'): ?>
  <div class="alert alert-danger">Toplam satış tutarı, tahsil edilenden düşük olamaz.</div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card bg-danger shadow-sm"><h6>Geciken Tahsilat</h6><h3><?= money($summary['overdue_amount']) ?></h3><p><?= h((string) $summary['active_pending_count']) ?> aktif bekleyen kayıt içinde</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-warning shadow-sm"><h6>Bugün Alınacak</h6><h3><?= money($summary['due_today_amount']) ?></h3><p>Bugün takip edilmesi gereken ödemeler</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-info shadow-sm"><h6>Bekleyen Toplam</h6><h3><?= money($summary['pending_total']) ?></h3><p><?= h((string) $summary['active_pending_count']) ?> açık tahsilat kaydı</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-success shadow-sm"><h6>Bu Ay Tahsil Edilen</h6><h3><?= money($summary['collected_this_month']) ?></h3><p><?= h((string) $summary['collected_this_month_count']) ?> hareket kaydedildi</p></div></div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header rentals-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <span>Bekleyen Tahsilatlar</span>
      <span class="badge text-bg-dark rounded-pill"><?= h((string) count($pendingItems)) ?> kayıt</span>
    </div>
    <div class="card-body">
      <?php if (empty($pendingItems)): ?>
      <div class="text-center text-muted py-3">Bekleyen uzatma tahsilatı yok.</div>
      <?php else: ?>
      <div class="d-grid gap-3 d-lg-none">
        <?php foreach ($pendingItems as $item): ?>
        <?php $itemCarPhotoUrl = !empty($item['car_id']) ? car_photo_public_url(['id' => $item['car_id'], 'photo_path' => $item['car_photo_path'] ?? null]) : null; ?>
        <div class="collection-mobile-card <?= $item['urgency'] === 'danger' ? 'is-danger' : ($item['urgency'] === 'warning' ? 'is-warning' : '') ?>">
          <div class="collection-mobile-head">
            <div>
              <?php if ($itemCarPhotoUrl): ?><img src="<?= h($itemCarPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($item['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($item['car_label']) ?>" class="car-photo-thumb mb-2" style="<?= h(car_photo_position_style($item, 'car_photo_position_x', 'car_photo_position_y')) ?>"><?php endif; ?>
              <strong><?= h($item['customer_name']) ?></strong>
              <div class="text-muted small"><?= h($item['car_label']) ?></div>
            </div>
            <span class="badge <?= $item['urgency'] === 'danger' ? 'bg-danger' : ($item['urgency'] === 'warning' ? 'bg-warning text-dark' : ($item['urgency'] === 'info' ? 'bg-info text-dark' : 'bg-secondary')) ?>">
              <?php if ($item['urgency'] === 'danger'): ?>Gecikti<?php elseif ($item['urgency'] === 'warning'): ?>Bugün<?php elseif ($item['urgency'] === 'info'): ?><?= $item['days_left'] === 1 ? 'Yarın' : (($item['days_left'] ?? 0) . ' gün') ?><?php else: ?>Plansız<?php endif; ?>
            </span>
          </div>
          <div class="collection-mobile-grid">
            <div><span>Toplam</span><strong><?= money($item['contract_amount']) ?></strong></div>
            <div><span>Tahsil</span><strong><?= money($item['collected_amount']) ?></strong></div>
            <div><span>Kalan</span><strong><?= money($item['pending_amount']) ?></strong></div>
            <div><span>Vade</span><strong><?= !empty($item['due_date']) ? dt($item['due_date']) : '-' ?></strong></div>
          </div>
          <div class="collection-mobile-actions">
            <a href="rental_detail.php?id=<?= h((string) $item['rental_id']) ?>" class="action-btn action-info" title="Detay" aria-label="Detay">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 5.7 9.8 6 .2.3.2.7 0 1-.2.3-4.3 6-9.8 6S2.4 12.3 2.2 12c-.2-.3-.2-.7 0-1 .2-.3 4.3-6 9.8-6Zm0 2C8.4 7 5.4 10.2 4.3 11.5 5.4 12.8 8.4 16 12 16s6.6-3.2 7.7-4.5C18.6 10.2 15.6 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Zm0 2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6Z"/></svg>
            </a>
            <?php if ($canManageRentals): ?>
            <a
              href="collection_collect.php?rental_id=<?= h((string) $item['rental_id']) ?>&extension_id=<?= h((string) $item['extension_id']) ?>&return_to=<?= h(urlencode(basename((string) ($_SERVER['PHP_SELF'] ?? 'collection_center.php')) . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''))) ?>"
              class="action-btn action-success"
              title="Tahsilat Düş"
              aria-label="Tahsilat Düş"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V7l-8-4Zm1 12.2V17h-2v-1.8a5.2 5.2 0 0 1-2.7-.9l.8-1.8c.8.5 1.7.8 2.7.8 1.1 0 1.8-.4 1.8-1.2 0-.7-.6-1.1-2-1.6-2-.7-3.3-1.5-3.3-3.3 0-1.6 1.1-2.8 3-3.2V3h2v1.7c1.2 0 2 .3 2.6.6l-.8 1.7a4.7 4.7 0 0 0-2.5-.6c-1.1 0-1.6.5-1.6 1 0 .6.6 1 2.2 1.6 2.2.8 3.1 1.8 3.1 3.4 0 1.6-1.1 3-3.3 3.4Z"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="table-responsive d-none d-lg-block">
        <table class="table table-bordered align-middle mb-0">
          <tr><th>Müşteri</th><th>Araç</th><th>Toplam Uzatma</th><th>Tahsil Edilen</th><th>Kalan</th><th>Vade</th><th>Durum</th><th>İşlem</th></tr>
          <?php foreach ($pendingItems as $item): ?>
          <?php $itemCarPhotoUrl = !empty($item['car_id']) ? car_photo_public_url(['id' => $item['car_id'], 'photo_path' => $item['car_photo_path'] ?? null]) : null; ?>
          <tr>
            <td><?= h($item['customer_name']) ?></td>
            <td><?php if ($itemCarPhotoUrl): ?><div class="d-flex align-items-center gap-2"><img src="<?= h($itemCarPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($item['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($item['car_label']) ?>" class="car-photo-thumb" style="<?= h(car_photo_position_style($item, 'car_photo_position_x', 'car_photo_position_y')) ?>"><span><?= h($item['car_label']) ?></span></div><?php else: ?><?= h($item['car_label']) ?><?php endif; ?></td>
            <td><?= money($item['contract_amount']) ?></td>
            <td><?= money($item['collected_amount']) ?></td>
            <td><strong><?= money($item['pending_amount']) ?></strong></td>
            <td><?= !empty($item['due_date']) ? dt($item['due_date']) : '-' ?></td>
            <td>
              <?php if ($item['urgency'] === 'danger'): ?>
              <span class="badge bg-danger">Gecikti</span>
              <?php elseif ($item['urgency'] === 'warning'): ?>
              <span class="badge bg-warning text-dark">Bugün</span>
              <?php elseif ($item['urgency'] === 'info'): ?>
              <span class="badge bg-info text-dark"><?= $item['days_left'] === 1 ? 'Yarın' : (($item['days_left'] ?? 0) . ' gün kaldı') ?></span>
              <?php else: ?>
              <span class="badge bg-secondary">Plansız</span>
              <?php endif; ?>
            </td>
            <td class="table-actions-cell">
              <div class="action-group">
                <a href="rental_detail.php?id=<?= h((string) $item['rental_id']) ?>" class="action-btn action-primary" title="Detay" aria-label="Detay">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 4.4 10.8 6-1.2 1.6-5.3 6-10.8 6S2.4 12.6 1.2 11C2.4 9.4 6.5 5 12 5Zm0 2C8.5 7 5.5 9.4 3.8 11 5.5 12.6 8.5 15 12 15s6.5-2.4 8.2-4C18.5 9.4 15.5 7 12 7Zm0 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z"/></svg>
                </a>
                <?php if ($canManageRentals): ?>
                <a
                  href="collection_collect.php?rental_id=<?= h((string) $item['rental_id']) ?>&extension_id=<?= h((string) $item['extension_id']) ?>&return_to=<?= h(urlencode(basename((string) ($_SERVER['PHP_SELF'] ?? 'collection_center.php')) . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''))) ?>"
                  class="action-btn action-success"
                  title="Tahsilat Düş"
                  aria-label="Tahsilat Düş"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V7l-8-4Zm1 12.2V17h-2v-1.8a5.2 5.2 0 0 1-2.7-.9l.8-1.8c.8.5 1.7.8 2.7.8 1.1 0 1.8-.4 1.8-1.2 0-.7-.6-1.1-2-1.6-2-.7-3.3-1.5-3.3-3.3 0-1.6 1.1-2.8 3-3.2V3h2v1.7c1.2 0 2 .3 2.6.6l-.8 1.7a4.7 4.7 0 0 0-2.5-.6c-1.1 0-1.6.5-1.6 1 0 .6.6 1 2.2 1.6 2.2.8 3.1 1.8 3.1 3.4 0 1.6-1.1 3-3.3 3.4Z"/></svg>
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?= pagination_render($pendingItemsPagination, ['item_label' => 'bekleyen tahsilat']) ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header rentals-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <span>Son Tahsilat Hareketleri</span>
      <span class="badge text-bg-dark rounded-pill"><?= h((string) count($recentCollections)) ?> hareket</span>
    </div>
    <div class="card-body">
      <?php if (empty($recentCollections)): ?>
      <div class="text-center text-muted py-3">Henüz tahsilat hareketi yok.</div>
      <?php else: ?>
      <div class="d-grid gap-3 d-lg-none">
        <?php foreach ($recentCollections as $collection): ?>
        <?php $collectionCarPhotoUrl = !empty($collection['car_id']) ? car_photo_public_url(['id' => $collection['car_id'], 'photo_path' => $collection['car_photo_path'] ?? null]) : null; ?>
        <div class="collection-mobile-card">
          <div class="collection-mobile-head">
            <div>
              <?php if ($collectionCarPhotoUrl): ?><img src="<?= h($collectionCarPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($collection['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($collection['car_label']) ?>" class="car-photo-thumb mb-2" style="<?= h(car_photo_position_style($collection, 'car_photo_position_x', 'car_photo_position_y')) ?>"><?php endif; ?>
              <strong><?= h($collection['customer_name']) ?></strong>
              <div class="text-muted small"><?= h($collection['car_label']) ?></div>
            </div>
            <strong class="text-success"><?= money($collection['amount']) ?></strong>
          </div>
          <div class="collection-mobile-grid">
            <div><span>Tarih</span><strong><?= dt($collection['collected_at']) ?></strong></div>
            <div><span>Ödeme Tipi</span><strong><?= h($collection['payment_method'] !== '' ? $collection['payment_method'] : '-') ?></strong></div>
            <div class="full"><span>Not</span><strong><?= h($collection['note'] !== '' ? $collection['note'] : '-') ?></strong></div>
          </div>
          <div class="collection-mobile-actions">
            <a href="rental_detail.php?id=<?= h((string) $collection['rental_id']) ?>" class="action-btn action-info" title="Detay" aria-label="Detay">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 5.7 9.8 6 .2.3.2.7 0 1-.2.3-4.3 6-9.8 6S2.4 12.3 2.2 12c-.2-.3-.2-.7 0-1 .2-.3 4.3-6 9.8-6Zm0 2C8.4 7 5.4 10.2 4.3 11.5 5.4 12.8 8.4 16 12 16s6.6-3.2 7.7-4.5C18.6 10.2 15.6 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Zm0 2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6Z"/></svg>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="table-responsive d-none d-lg-block">
        <table class="table table-bordered align-middle mb-0">
          <tr><th>Tarih</th><th>Müşteri</th><th>Araç</th><th>Tutar</th><th>Ödeme Tipi</th><th>Not</th><th>İşlem</th></tr>
          <?php foreach ($recentCollections as $collection): ?>
          <?php $collectionCarPhotoUrl = !empty($collection['car_id']) ? car_photo_public_url(['id' => $collection['car_id'], 'photo_path' => $collection['car_photo_path'] ?? null]) : null; ?>
          <tr>
            <td><?= dt($collection['collected_at']) ?></td>
            <td><?= h($collection['customer_name']) ?></td>
            <td><?php if ($collectionCarPhotoUrl): ?><div class="d-flex align-items-center gap-2"><img src="<?= h($collectionCarPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($collection['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($collection['car_label']) ?>" class="car-photo-thumb" style="<?= h(car_photo_position_style($collection, 'car_photo_position_x', 'car_photo_position_y')) ?>"><span><?= h($collection['car_label']) ?></span></div><?php else: ?><?= h($collection['car_label']) ?><?php endif; ?></td>
            <td><?= money($collection['amount']) ?></td>
            <td><?= h($collection['payment_method'] !== '' ? $collection['payment_method'] : '-') ?></td>
            <td><?= h($collection['note'] !== '' ? $collection['note'] : '-') ?></td>
            <td class="table-actions-cell">
              <div class="action-group">
                <a href="rental_detail.php?id=<?= h((string) $collection['rental_id']) ?>" class="action-btn action-primary" title="Detay" aria-label="Detay">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 4.4 10.8 6-1.2 1.6-5.3 6-10.8 6S2.4 12.6 1.2 11C2.4 9.4 6.5 5 12 5Zm0 2C8.5 7 5.5 9.4 3.8 11 5.5 12.6 8.5 15 12 15s6.5-2.4 8.2-4C18.5 9.4 15.5 7 12 7Zm0 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z"/></svg>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?= pagination_render($recentCollectionsPagination, ['item_label' => 'tahsilat hareketi']) ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3 mb-4 mt-1">
    <div class="col-6 col-xl-3"><div class="stat-card bg-danger shadow-sm"><h6>Satış Geciken</h6><h3><?= money($carSaleSummary['overdue_amount']) ?></h3><p><?= h((string) $carSaleSummary['active_pending_count']) ?> aktif satış içinde</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-warning shadow-sm"><h6>Satış Bugün</h6><h3><?= money($carSaleSummary['due_today_amount']) ?></h3><p>Bugün takip edilecek vade</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-info shadow-sm"><h6>Satış Bekleyen</h6><h3><?= money($carSaleSummary['pending_total']) ?></h3><p><?= h((string) $carSaleSummary['active_pending_count']) ?> açık satış alacağı</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-success shadow-sm"><h6>Bu Ay Tahsil Edilen</h6><h3><?= money($carSaleSummary['collected_this_month']) ?></h3><p><?= h((string) $carSaleSummary['collected_this_month_count']) ?> hareket kaydedildi</p></div></div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header rentals-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <span>Araç Satış Alacakları</span>
      <span class="badge text-bg-dark rounded-pill"><?= h((string) count($carSalePendingItems)) ?> kayıt</span>
    </div>
    <div class="card-body">
      <?php if (empty($carSalePendingItems)): ?>
      <div class="text-center text-muted py-3">Bekleyen araç satış tahsilatı yok.</div>
      <?php else: ?>
      <div class="d-grid gap-3 d-lg-none">
        <?php foreach ($carSalePendingItems as $item): ?>
        <?php $saleItemPhotoUrl = !empty($item['car_id']) ? car_photo_public_url(['id' => $item['car_id'], 'photo_path' => $item['car_photo_path'] ?? null]) : null; ?>
        <div class="collection-mobile-card <?= $item['urgency'] === 'danger' ? 'is-danger' : ($item['urgency'] === 'warning' ? 'is-warning' : '') ?>">
          <div class="collection-mobile-head">
            <div>
              <?php if ($saleItemPhotoUrl): ?><img src="<?= h($saleItemPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($item['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($item['car_label']) ?>" class="car-photo-thumb mb-2" style="<?= h(car_photo_position_style($item, 'car_photo_position_x', 'car_photo_position_y')) ?>"><?php endif; ?>
              <strong><?= h($item['buyer_name']) ?></strong>
              <div class="text-muted small"><?= h($item['car_label']) ?></div>
            </div>
            <span class="badge <?= $item['urgency'] === 'danger' ? 'bg-danger' : ($item['urgency'] === 'warning' ? 'bg-warning text-dark' : ($item['urgency'] === 'info' ? 'bg-info text-dark' : 'bg-secondary')) ?>">
              <?php if ($item['urgency'] === 'danger'): ?>Gecikti<?php elseif ($item['urgency'] === 'warning'): ?>Bugün<?php elseif ($item['urgency'] === 'info'): ?><?= $item['days_left'] === 1 ? 'Yarın' : (($item['days_left'] ?? 0) . ' gün') ?><?php else: ?>Plansız<?php endif; ?>
            </span>
          </div>
          <div class="collection-mobile-grid">
            <div><span>Toplam</span><strong><?= money($item['contract_amount']) ?></strong></div>
            <div><span>Tahsil</span><strong><?= money($item['collected_amount']) ?></strong></div>
            <div><span>Kalan</span><strong><?= money($item['pending_amount']) ?></strong></div>
            <div><span>Vade</span><strong><?= !empty($item['due_date']) ? dt($item['due_date']) : '-' ?></strong></div>
          </div>
          <div class="collection-mobile-actions">
            <a href="car_detail.php?id=<?= h((string) $item['car_id']) ?>" class="action-btn action-info" title="Detay" aria-label="Detay">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 5.7 9.8 6 .2.3.2.7 0 1-.2.3-4.3 6-9.8 6S2.4 12.3 2.2 12c-.2-.3-.2-.7 0-1 .2-.3 4.3-6 9.8-6Zm0 2C8.4 7 5.4 10.2 4.3 11.5 5.4 12.8 8.4 16 12 16s6.6-3.2 7.7-4.5C18.6 10.2 15.6 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Zm0 2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6Z"/></svg>
            </a>
            <?php if ($canManageCars): ?>
            <a href="car_sale_collect.php?car_id=<?= h((string) $item['car_id']) ?>&return_to=<?= h(urlencode(basename((string) ($_SERVER['PHP_SELF'] ?? 'collection_center.php')) . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''))) ?>" class="action-btn action-success" title="Tahsilat Düş" aria-label="Tahsilat Düş">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V7l-8-4Zm1 12.2V17h-2v-1.8a5.2 5.2 0 0 1-2.7-.9l.8-1.8c.8.5 1.7.8 2.7.8 1.1 0 1.8-.4 1.8-1.2 0-.7-.6-1.1-2-1.6-2-.7-3.3-1.5-3.3-3.3 0-1.6 1.1-2.8 3-3.2V3h2v1.7c1.2 0 2 .3 2.6.6l-.8 1.7a4.7 4.7 0 0 0-2.5-.6c-1.1 0-1.6.5-1.6 1 0 .6.6 1 2.2 1.6 2.2.8 3.1 1.8 3.1 3.4 0 1.6-1.1 3-3.3 3.4Z"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="table-responsive d-none d-lg-block">
        <table class="table table-bordered align-middle mb-0">
          <tr><th>Alıcı</th><th>Araç</th><th>Toplam Satış</th><th>Tahsil Edilen</th><th>Kalan</th><th>Vade</th><th>Durum</th><th>İşlem</th></tr>
          <?php foreach ($carSalePendingItems as $item): ?>
          <?php $saleItemPhotoUrl = !empty($item['car_id']) ? car_photo_public_url(['id' => $item['car_id'], 'photo_path' => $item['car_photo_path'] ?? null]) : null; ?>
          <tr>
            <td><?= h($item['buyer_name']) ?></td>
            <td><?php if ($saleItemPhotoUrl): ?><div class="d-flex align-items-center gap-2"><img src="<?= h($saleItemPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($item['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($item['car_label']) ?>" class="car-photo-thumb" style="<?= h(car_photo_position_style($item, 'car_photo_position_x', 'car_photo_position_y')) ?>"><span><?= h($item['car_label']) ?></span></div><?php else: ?><?= h($item['car_label']) ?><?php endif; ?></td>
            <td><?= money($item['contract_amount']) ?></td>
            <td><?= money($item['collected_amount']) ?></td>
            <td><strong><?= money($item['pending_amount']) ?></strong></td>
            <td><?= !empty($item['due_date']) ? dt($item['due_date']) : '-' ?></td>
            <td>
              <?php if ($item['urgency'] === 'danger'): ?>
              <span class="badge bg-danger">Gecikti</span>
              <?php elseif ($item['urgency'] === 'warning'): ?>
              <span class="badge bg-warning text-dark">Bugün</span>
              <?php elseif ($item['urgency'] === 'info'): ?>
              <span class="badge bg-info text-dark"><?= $item['days_left'] === 1 ? 'Yarın' : (($item['days_left'] ?? 0) . ' gün kaldı') ?></span>
              <?php else: ?>
              <span class="badge bg-secondary">Plansız</span>
              <?php endif; ?>
            </td>
            <td class="table-actions-cell">
              <div class="action-group">
                <a href="car_detail.php?id=<?= h((string) $item['car_id']) ?>" class="action-btn action-primary" title="Detay" aria-label="Detay">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 4.4 10.8 6-1.2 1.6-5.3 6-10.8 6S2.4 12.6 1.2 11C2.4 9.4 6.5 5 12 5Zm0 2C8.5 7 5.5 9.4 3.8 11 5.5 12.6 8.5 15 12 15s6.5-2.4 8.2-4C18.5 9.4 15.5 7 12 7Zm0 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z"/></svg>
                </a>
                <?php if ($canManageCars): ?>
                <a href="car_sale_collect.php?car_id=<?= h((string) $item['car_id']) ?>&return_to=<?= h(urlencode(basename((string) ($_SERVER['PHP_SELF'] ?? 'collection_center.php')) . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''))) ?>" class="action-btn action-success" title="Tahsilat Düş" aria-label="Tahsilat Düş">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V7l-8-4Zm1 12.2V17h-2v-1.8a5.2 5.2 0 0 1-2.7-.9l.8-1.8c.8.5 1.7.8 2.7.8 1.1 0 1.8-.4 1.8-1.2 0-.7-.6-1.1-2-1.6-2-.7-3.3-1.5-3.3-3.3 0-1.6 1.1-2.8 3-3.2V3h2v1.7c1.2 0 2 .3 2.6.6l-.8 1.7a4.7 4.7 0 0 0-2.5-.6c-1.1 0-1.6.5-1.6 1 0 .6.6 1 2.2 1.6 2.2.8 3.1 1.8 3.1 3.4 0 1.6-1.1 3-3.3 3.4Z"/></svg>
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?= pagination_render($carSalePendingPagination, ['item_label' => 'satış tahsilatı']) ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header rentals-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <span>Son Araç Satış Tahsilatları</span>
      <span class="badge text-bg-dark rounded-pill"><?= h((string) count($carSaleRecentCollections)) ?> hareket</span>
    </div>
    <div class="card-body">
      <?php if (empty($carSaleRecentCollections)): ?>
      <div class="text-center text-muted py-3">Henüz araç satış tahsilatı yok.</div>
      <?php else: ?>
      <div class="d-grid gap-3 d-lg-none mb-3">
        <?php foreach ($carSaleRecentCollections as $collection): ?>
        <?php $saleCollectionPhotoUrl = !empty($collection['car_id']) ? car_photo_public_url(['id' => $collection['car_id'], 'photo_path' => $collection['car_photo_path'] ?? null]) : null; ?>
        <div class="collection-mobile-card">
          <div class="collection-mobile-head">
            <div>
              <?php if ($saleCollectionPhotoUrl): ?><img src="<?= h($saleCollectionPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($collection['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($collection['car_label']) ?>" class="car-photo-thumb mb-2" style="<?= h(car_photo_position_style($collection, 'car_photo_position_x', 'car_photo_position_y')) ?>"><?php endif; ?>
              <strong><?= h($collection['buyer_name']) ?></strong>
              <div class="text-muted small"><?= h($collection['car_label']) ?></div>
            </div>
            <strong class="text-success"><?= money($collection['amount']) ?></strong>
          </div>
          <div class="collection-mobile-grid">
            <div><span>Tarih</span><strong><?= dt($collection['collected_at']) ?></strong></div>
            <div><span>Ödeme Tipi</span><strong><?= h($collection['payment_method'] !== '' ? $collection['payment_method'] : '-') ?></strong></div>
            <div class="full"><span>Not</span><strong><?= h($collection['note'] !== '' ? $collection['note'] : '-') ?></strong></div>
          </div>
          <div class="collection-mobile-actions">
            <a href="car_detail.php?id=<?= h((string) $collection['car_id']) ?>" class="action-btn action-info" title="Detay" aria-label="Detay">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 5.7 9.8 6 .2.3.2.7 0 1-.2.3-4.3 6-9.8 6S2.4 12.3 2.2 12c-.2-.3-.2-.7 0-1 .2-.3 4.3-6 9.8-6Zm0 2C8.4 7 5.4 10.2 4.3 11.5 5.4 12.8 8.4 16 12 16s6.6-3.2 7.7-4.5C18.6 10.2 15.6 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Zm0 2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6Z"/></svg>
            </a>
            <?php if ($canManageCars && (int) ($latestCarSaleCollectionIdMap[(int) ($collection['sale_id'] ?? 0)] ?? 0) === (int) ($collection['collection_id'] ?? 0)): ?>
            <a href="car_sale_collect.php?car_id=<?= h((string) $collection['car_id']) ?>&edit_collection_id=<?= h((string) ($collection['collection_id'] ?? 0)) ?>&return_to=<?= h(urlencode(basename((string) ($_SERVER['PHP_SELF'] ?? 'collection_center.php')) . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''))) ?>" class="action-btn action-warning" title="Düzenle" aria-label="Düzenle">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0">
          <tr><th>Tarih</th><th>Alıcı</th><th>Araç</th><th>Tutar</th><th>Ödeme Tipi</th><th>Not</th><th>İşlem</th></tr>
          <?php foreach ($carSaleRecentCollections as $collection): ?>
          <?php $saleCollectionPhotoUrl = !empty($collection['car_id']) ? car_photo_public_url(['id' => $collection['car_id'], 'photo_path' => $collection['car_photo_path'] ?? null]) : null; ?>
          <tr>
            <td><?= dt($collection['collected_at']) ?></td>
            <td><?= h($collection['buyer_name']) ?></td>
            <td><?php if ($saleCollectionPhotoUrl): ?><div class="d-flex align-items-center gap-2"><img src="<?= h($saleCollectionPhotoUrl) ?>?v=<?= h(rawurlencode((string) ($collection['car_photo_path'] ?? 'car'))) ?>" alt="<?= h($collection['car_label']) ?>" class="car-photo-thumb" style="<?= h(car_photo_position_style($collection, 'car_photo_position_x', 'car_photo_position_y')) ?>"><span><?= h($collection['car_label']) ?></span></div><?php else: ?><?= h($collection['car_label']) ?><?php endif; ?></td>
            <td><?= money($collection['amount']) ?></td>
            <td><?= h($collection['payment_method'] !== '' ? $collection['payment_method'] : '-') ?></td>
            <td><?= h($collection['note'] !== '' ? $collection['note'] : '-') ?></td>
            <td class="table-actions-cell">
              <div class="action-group">
                <a href="car_detail.php?id=<?= h((string) $collection['car_id']) ?>" class="action-btn action-primary" title="Detay" aria-label="Detay">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 4.4 10.8 6-1.2 1.6-5.3 6-10.8 6S2.4 12.6 1.2 11C2.4 9.4 6.5 5 12 5Zm0 2C8.5 7 5.5 9.4 3.8 11 5.5 12.6 8.5 15 12 15s6.5-2.4 8.2-4C18.5 9.4 15.5 7 12 7Zm0 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z"/></svg>
                </a>
                <?php if ($canManageCars && (int) ($latestCarSaleCollectionIdMap[(int) ($collection['sale_id'] ?? 0)] ?? 0) === (int) ($collection['collection_id'] ?? 0)): ?>
                <a href="car_sale_collect.php?car_id=<?= h((string) $collection['car_id']) ?>&edit_collection_id=<?= h((string) ($collection['collection_id'] ?? 0)) ?>&return_to=<?= h(urlencode(basename((string) ($_SERVER['PHP_SELF'] ?? 'collection_center.php')) . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''))) ?>" class="action-btn action-warning" title="Düzenle" aria-label="Düzenle">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?= pagination_render($carSaleRecentPagination, ['item_label' => 'satış tahsilat hareketi']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
