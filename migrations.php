<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/schema_migrations.php';

if (!app_schema_migration_available_for_user()) {
    auth_require_permission('platform.manage');
}

$status = trim((string) ($_GET['status'] ?? ''));
$appliedCount = max(0, (int) ($_GET['applied'] ?? 0));
$statusData = app_schema_migration_status($pdo);

$pageTitle = 'Migrasyon Merkezi';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="companies-page">
  <div class="cars-hero mb-4">
    <div>
      <div class="cars-hero-label">Platform</div>
      <h2 class="mb-2">Migrasyon Merkezi</h2>
      <div class="text-muted">Veritabanı yapısını tek merkezden uygula, takip et ve kurulum drift’ini azalt.</div>
    </div>
    <div class="cars-hero-actions">
      <a href="companies.php" class="btn btn-outline-dark">Firmalara Dön</a>
    </div>
  </div>

  <?php if ($status === 'success'): ?>
  <div class="alert alert-success"><?= $appliedCount > 0 ? h((string) $appliedCount) . ' migrasyon uygulandı.' : 'Bekleyen migrasyon yoktu.' ?></div>
  <?php elseif ($status === 'error'): ?>
  <div class="alert alert-danger">Migrasyon sırasında hata oluştu. Log ve tablo yapısını kontrol edin.</div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card bg-dark shadow-sm"><h6>Toplam</h6><h3><?= h((string) $statusData['total_count']) ?></h3><p>Kayıtlı migrasyon</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-success shadow-sm"><h6>Uygulanan</h6><h3><?= h((string) $statusData['applied_count']) ?></h3><p>Veritabanına işlenen</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-warning shadow-sm"><h6>Bekleyen</h6><h3><?= h((string) $statusData['pending_count']) ?></h3><p>Çalıştırılmayı bekleyen</p></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-primary shadow-sm"><h6>Durum</h6><h3><?= $statusData['pending_count'] > 0 ? 'Kontrol' : 'Hazır' ?></h3><p><?= $statusData['pending_count'] > 0 ? 'Kurulum drift var' : 'Şema senkron görünüyor' ?></p></div></div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Kayıtlı Migrasyonlar</span>
      <form action="actions/schema_migrations_run.php" method="post" class="mb-0">
        <?= auth_csrf_input() ?>
        <button class="btn btn-dark" type="submit">Bekleyenleri Uygula</button>
      </form>
    </div>
    <div class="card-body">
      <div class="mobile-record-list d-grid d-lg-none">
        <?php foreach ($statusData['items'] as $item): ?>
        <div class="mobile-record-card">
          <div class="mobile-record-card-head">
            <div class="mobile-record-card-title">
              <strong><?= h($item['label']) ?></strong>
              <small><?= h($item['key']) ?></small>
            </div>
            <div class="mobile-record-card-badges">
              <span class="badge <?= $item['is_applied'] ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $item['is_applied'] ? 'Uygulandı' : 'Bekliyor' ?></span>
            </div>
          </div>
          <div class="mobile-record-grid">
            <div class="full"><span>Çalışma Zamanı</span><strong><?= !empty($item['executed_at']) ? dt($item['executed_at']) : '-' ?></strong></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive d-none d-lg-block">
        <table class="table table-bordered align-middle mb-0">
          <tr><th>Migrasyon</th><th>Anahtar</th><th>Durum</th><th>Çalışma Zamanı</th></tr>
          <?php foreach ($statusData['items'] as $item): ?>
          <tr>
            <td><?= h($item['label']) ?></td>
            <td><code><?= h($item['key']) ?></code></td>
            <td><span class="badge <?= $item['is_applied'] ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $item['is_applied'] ? 'Uygulandı' : 'Bekliyor' ?></span></td>
            <td><?= !empty($item['executed_at']) ? dt($item['executed_at']) : '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">Neden gerekli?</div>
    <div class="card-body">
      <div class="detail-grid detail-grid-single">
        <div class="detail-item">
          <span class="detail-label">Amaç</span>
          <strong>DDL değişikliklerini ekrana giren her istekte değil, kontrollü bir merkezden yönetmek</strong>
        </div>
        <div class="detail-item">
          <span class="detail-label">Fayda</span>
          <strong>Canlıya çıkarken, yeni hosting kurarken ve ekip büyürken daha tutarlı bir kurulum akışı sağlar</strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
