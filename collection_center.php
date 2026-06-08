<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('rentals.view');

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
$companyId = auth_current_company_id();
$canManageRentals = auth_can('rentals.manage');
$status = $_GET['status'] ?? '';

$rentalsSt = $pdo->prepare('
    SELECT
        r.*,
        c.brand,
        c.model,
        c.plate
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

$pageTitle = 'Tahsilat Merkezi';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="collection-center-page">
  <div class="rentals-hero mb-4">
    <div>
      <div class="cars-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Tahsilat Merkezi</h2>
      <div class="text-muted">Bekleyen uzatma tahsilatlarini, geciken odemeleri ve son tahsilat hareketlerini tek ekranda yonet.</div>
    </div>
    <div class="rentals-hero-actions">
      <a href="rentals.php" class="btn btn-outline-dark">Kiralamalara Don</a>
    </div>
  </div>

  <?php if ($status === 'extension_collected'): ?>
  <div class="alert alert-success">Tahsilat kaydedildi.</div>
  <?php elseif ($status === 'extension_collection_cancelled'): ?>
  <div class="alert alert-success">Tahsilat geri alindi.</div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card bg-danger shadow-sm"><h6>Geciken Tahsilat</h6><h3><?= money($summary['overdue_amount']) ?></h3><p><?= h((string) $summary['active_pending_count']) ?> aktif bekleyen kayit icinde</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-warning shadow-sm"><h6>Bugun Alinacak</h6><h3><?= money($summary['due_today_amount']) ?></h3><p>Bugun takip edilmesi gereken odemeler</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-info shadow-sm"><h6>Bekleyen Toplam</h6><h3><?= money($summary['pending_total']) ?></h3><p><?= h((string) $summary['active_pending_count']) ?> acik tahsilat kaydi</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-success shadow-sm"><h6>Bu Ay Tahsil</h6><h3><?= money($summary['collected_this_month']) ?></h3><p><?= h((string) $summary['collected_this_month_count']) ?> hareket kaydedildi</p></div></div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header rentals-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <span>Bekleyen Tahsilatlar</span>
      <span class="badge text-bg-dark rounded-pill"><?= h((string) count($pendingItems)) ?> kayit</span>
    </div>
    <div class="card-body">
      <?php if (empty($pendingItems)): ?>
      <div class="text-center text-muted py-3">Bekleyen uzatma tahsilati yok.</div>
      <?php else: ?>
      <div class="d-grid gap-3 d-lg-none">
        <?php foreach ($pendingItems as $item): ?>
        <div class="collection-mobile-card <?= $item['urgency'] === 'danger' ? 'is-danger' : ($item['urgency'] === 'warning' ? 'is-warning' : '') ?>">
          <div class="collection-mobile-head">
            <div>
              <strong><?= h($item['customer_name']) ?></strong>
              <div class="text-muted small"><?= h($item['car_label']) ?></div>
            </div>
            <span class="badge <?= $item['urgency'] === 'danger' ? 'bg-danger' : ($item['urgency'] === 'warning' ? 'bg-warning text-dark' : ($item['urgency'] === 'info' ? 'bg-info text-dark' : 'bg-secondary')) ?>">
              <?php if ($item['urgency'] === 'danger'): ?>Gecikti<?php elseif ($item['urgency'] === 'warning'): ?>Bugun<?php elseif ($item['urgency'] === 'info'): ?><?= $item['days_left'] === 1 ? 'Yarin' : (($item['days_left'] ?? 0) . ' gun') ?><?php else: ?>Plansiz<?php endif; ?>
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
            <a href="rental_detail.php?id=<?= h((string) $item['rental_id']) ?>#extension-<?= h((string) $item['extension_id']) ?>" class="action-btn action-success" title="Tahsilat Al" aria-label="Tahsilat Al">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V7l-8-4Zm1 12.2V17h-2v-1.8a5.2 5.2 0 0 1-2.7-.9l.8-1.8c.8.5 1.7.8 2.7.8 1.1 0 1.8-.4 1.8-1.2 0-.7-.6-1.1-2-1.6-2-.7-3.3-1.5-3.3-3.3 0-1.6 1.1-2.8 3-3.2V3h2v1.7c1.2 0 2 .3 2.6.6l-.8 1.7a4.7 4.7 0 0 0-2.5-.6c-1.1 0-1.6.5-1.6 1 0 .6.6 1 2.2 1.6 2.2.8 3.1 1.8 3.1 3.4 0 1.6-1.1 3-3.3 3.4Z"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="table-responsive d-none d-lg-block">
        <table class="table table-bordered align-middle mb-0">
          <tr><th>Musteri</th><th>Arac</th><th>Toplam Uzatma</th><th>Tahsil Edilen</th><th>Kalan</th><th>Vade</th><th>Durum</th><th>Islem</th></tr>
          <?php foreach ($pendingItems as $item): ?>
          <tr>
            <td><?= h($item['customer_name']) ?></td>
            <td><?= h($item['car_label']) ?></td>
            <td><?= money($item['contract_amount']) ?></td>
            <td><?= money($item['collected_amount']) ?></td>
            <td><strong><?= money($item['pending_amount']) ?></strong></td>
            <td><?= !empty($item['due_date']) ? dt($item['due_date']) : '-' ?></td>
            <td>
              <?php if ($item['urgency'] === 'danger'): ?>
              <span class="badge bg-danger">Gecikti</span>
              <?php elseif ($item['urgency'] === 'warning'): ?>
              <span class="badge bg-warning text-dark">Bugun</span>
              <?php elseif ($item['urgency'] === 'info'): ?>
              <span class="badge bg-info text-dark"><?= $item['days_left'] === 1 ? 'Yarin' : (($item['days_left'] ?? 0) . ' gun kaldi') ?></span>
              <?php else: ?>
              <span class="badge bg-secondary">Plansiz</span>
              <?php endif; ?>
            </td>
            <td class="table-actions-cell">
              <div class="action-group">
                <a href="rental_detail.php?id=<?= h((string) $item['rental_id']) ?>" class="action-btn action-primary" title="Detay" aria-label="Detay">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.6 4.4 10.8 6-1.2 1.6-5.3 6-10.8 6S2.4 12.6 1.2 11C2.4 9.4 6.5 5 12 5Zm0 2C8.5 7 5.5 9.4 3.8 11 5.5 12.6 8.5 15 12 15s6.5-2.4 8.2-4C18.5 9.4 15.5 7 12 7Zm0 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z"/></svg>
                </a>
                <?php if ($canManageRentals): ?>
                <a href="rental_detail.php?id=<?= h((string) $item['rental_id']) ?>#extension-<?= h((string) $item['extension_id']) ?>" class="action-btn action-success" title="Tahsilat Al" aria-label="Tahsilat Al">
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
      <div class="text-center text-muted py-3">Henuz tahsilat hareketi yok.</div>
      <?php else: ?>
      <div class="d-grid gap-3 d-lg-none">
        <?php foreach ($recentCollections as $collection): ?>
        <div class="collection-mobile-card">
          <div class="collection-mobile-head">
            <div>
              <strong><?= h($collection['customer_name']) ?></strong>
              <div class="text-muted small"><?= h($collection['car_label']) ?></div>
            </div>
            <strong class="text-success"><?= money($collection['amount']) ?></strong>
          </div>
          <div class="collection-mobile-grid">
            <div><span>Tarih</span><strong><?= dt($collection['collected_at']) ?></strong></div>
            <div><span>Odeme Tipi</span><strong><?= h($collection['payment_method'] !== '' ? $collection['payment_method'] : '-') ?></strong></div>
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
          <tr><th>Tarih</th><th>Musteri</th><th>Arac</th><th>Tutar</th><th>Odeme Tipi</th><th>Not</th><th>Islem</th></tr>
          <?php foreach ($recentCollections as $collection): ?>
          <tr>
            <td><?= dt($collection['collected_at']) ?></td>
            <td><?= h($collection['customer_name']) ?></td>
            <td><?= h($collection['car_label']) ?></td>
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
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
