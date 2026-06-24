<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

if (!app_feature_notifications_center_enabled()) {
    auth_redirect('index.php');
}

auth_require_permission('notifications.view');

$companyId = auth_current_company_id();
$canManageNotifications = auth_can('notifications.manage');
$status = $_GET['status'] ?? 'open';
$flashStatus = $_GET['flash'] ?? '';

notifications_sync_operational($pdo, $companyId);
$summary = notifications_summary($pdo, $companyId);
$notifications = notifications_fetch($pdo, $companyId, $status, 150);

$pageTitle = 'Bildirimler';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="collection-center-page notifications-page">
  <div class="rentals-hero mb-4">
    <div>
      <div class="cars-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">Bildirimler</h2>
      <div class="car-hero-subtitle">Mobil push için hazır merkezi operasyon uyarıları</div>
    </div>
    <?php if ($canManageNotifications): ?>
    <div class="rentals-hero-actions">
      <form action="actions/notification_mark_all_read.php" method="post">
        <?= auth_csrf_input() ?>
        <button class="btn btn-outline-dark" type="submit">Tümünü Okundu Yap</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($flashStatus === 'read'): ?>
  <div class="alert alert-success">Bildirim okundu olarak işaretlendi.</div>
  <?php elseif ($flashStatus === 'resolved'): ?>
  <div class="alert alert-success">Bildirim çözüldü olarak güncellendi.</div>
  <?php elseif ($flashStatus === 'all_read'): ?>
  <div class="alert alert-success">Açık bildirimler okundu olarak işaretlendi.</div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card bg-primary shadow-sm"><h6>Açık</h6><h3><?= h((string) $summary['open_count']) ?></h3></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-danger shadow-sm"><h6>Kritik Açık</h6><h3><?= h((string) $summary['critical_open_count']) ?></h3></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-secondary shadow-sm"><h6>Okundu</h6><h3><?= h((string) $summary['read_count']) ?></h3></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card bg-success shadow-sm"><h6>Çözüldü</h6><h3><?= h((string) $summary['resolved_count']) ?></h3></div></div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Bildirim Merkezi</span>
      <div class="d-flex gap-2 flex-wrap">
        <a href="notifications.php?status=open" class="btn btn-sm <?= $status === 'open' ? 'btn-dark' : 'btn-outline-dark' ?>">Açık</a>
        <a href="notifications.php?status=read" class="btn btn-sm <?= $status === 'read' ? 'btn-dark' : 'btn-outline-dark' ?>">Okundu</a>
        <a href="notifications.php?status=resolved" class="btn btn-sm <?= $status === 'resolved' ? 'btn-dark' : 'btn-outline-dark' ?>">Çözüldü</a>
      </div>
    </div>
    <div class="card-body">
      <?php if (empty($notifications)): ?>
      <div class="text-muted">Bu filtrede bildirim yok.</div>
      <?php else: ?>
      <div class="d-none d-lg-block table-responsive">
        <table class="table table-bordered table-striped align-middle">
          <tr><th>Seviye</th><th>Baslik</th><th>Mesaj</th><th>Vade</th><th>Durum</th><th>Islem</th></tr>
          <?php foreach ($notifications as $notification): ?>
          <?php
            $targetUrl = notification_target_url($notification);
            $severity = (string) ($notification['severity'] ?? 'info');
            $statusLabel = match ((string) ($notification['status'] ?? 'open')) {
                'read' => 'Okundu',
                'resolved' => 'Çözüldü',
                default => 'Açık',
            };
          ?>
          <tr>
            <td><span class="badge <?= $severity === 'danger' ? 'bg-danger' : ($severity === 'warning' ? 'bg-warning text-dark' : 'bg-info text-dark') ?>"><?= h($severity === 'danger' ? 'Kritik' : ($severity === 'warning' ? 'Takip' : 'Bilgi')) ?></span></td>
            <td><?= h($notification['title'] ?? '') ?></td>
            <td>
              <div><?= h($notification['message'] ?? '') ?></div>
              <?php if ($targetUrl): ?><div class="mt-1"><a href="<?= h($targetUrl) ?>" class="small">Kayda git</a></div><?php endif; ?>
            </td>
            <td><?= !empty($notification['due_at']) ? d($notification['due_at']) : '-' ?></td>
            <td><?= h($statusLabel) ?></td>
            <td class="table-actions-cell">
              <div class="action-group">
                <?php if ($targetUrl): ?>
                <a href="<?= h($targetUrl) ?>" class="action-btn action-primary" title="Kayda Git" aria-label="Kayda Git">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7h-2V6.4l-9.3 9.3-1.4-1.4L17.6 5H14V3ZM5 5h6v2H7v10h10v-4h2v6H5V5Z"/></svg>
                </a>
                <?php endif; ?>
                <?php if ($canManageNotifications && ($notification['status'] ?? 'open') === 'open'): ?>
                <form action="actions/notification_mark_read.php" method="post" class="d-inline">
                  <?= auth_csrf_input() ?>
                  <input type="hidden" name="id" value="<?= h((string) ($notification['id'] ?? 0)) ?>">
                  <button class="action-btn action-secondary" type="submit" title="Okundu" aria-label="Okundu">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.2 16.6-4.1-4.1 1.4-1.4 2.7 2.7 8.3-8.3 1.4 1.4-9.7 9.7Z"/></svg>
                  </button>
                </form>
                <?php endif; ?>
                <?php if ($canManageNotifications && ($notification['status'] ?? 'open') !== 'resolved'): ?>
                <form action="actions/notification_resolve.php" method="post" class="d-inline">
                  <?= auth_csrf_input() ?>
                  <input type="hidden" name="id" value="<?= h((string) ($notification['id'] ?? 0)) ?>">
                  <button class="action-btn action-success" type="submit" title="Çözüldü" aria-label="Çözüldü">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm-1 14-4-4 1.4-1.4 2.6 2.6 5.6-5.6L18 9l-7 7Z"/></svg>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <div class="d-grid gap-3 d-lg-none">
        <?php foreach ($notifications as $notification): ?>
        <?php
          $targetUrl = notification_target_url($notification);
          $severity = (string) ($notification['severity'] ?? 'info');
          $statusLabel = match ((string) ($notification['status'] ?? 'open')) {
              'read' => 'Okundu',
              'resolved' => 'Çözüldü',
              default => 'Açık',
          };
        ?>
        <div class="collection-mobile-card <?= $severity === 'danger' ? 'is-danger' : ($severity === 'warning' ? 'is-warning' : '') ?>">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
              <div class="fw-semibold"><?= h($notification['title'] ?? '') ?></div>
              <div class="small text-muted"><?= h($statusLabel) ?></div>
            </div>
            <span class="badge <?= $severity === 'danger' ? 'bg-danger' : ($severity === 'warning' ? 'bg-warning text-dark' : 'bg-info text-dark') ?>"><?= h($severity === 'danger' ? 'Kritik' : ($severity === 'warning' ? 'Takip' : 'Bilgi')) ?></span>
          </div>
          <div><?= h($notification['message'] ?? '') ?></div>
          <div class="small text-muted">Vade: <?= !empty($notification['due_at']) ? d($notification['due_at']) : '-' ?></div>
          <div class="d-flex gap-2 flex-wrap">
            <?php if ($targetUrl): ?><a href="<?= h($targetUrl) ?>" class="btn btn-sm btn-outline-primary">Kayda Git</a><?php endif; ?>
            <?php if ($canManageNotifications && ($notification['status'] ?? 'open') === 'open'): ?>
            <form action="actions/notification_mark_read.php" method="post">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h((string) ($notification['id'] ?? 0)) ?>">
              <button class="btn btn-sm btn-outline-dark" type="submit">Okundu</button>
            </form>
            <?php endif; ?>
            <?php if ($canManageNotifications && ($notification['status'] ?? 'open') !== 'resolved'): ?>
            <form action="actions/notification_resolve.php" method="post">
              <?= auth_csrf_input() ?>
              <input type="hidden" name="id" value="<?= h((string) ($notification['id'] ?? 0)) ?>">
              <button class="btn btn-sm btn-success" type="submit">Çözüldü</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
