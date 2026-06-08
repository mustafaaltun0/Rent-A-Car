<?php if (!isset($pageTitle)) { $pageTitle = 'RentecarWeb'; } ?>
<?php $authUser = function_exists('auth_current_user') ? auth_current_user() : null; ?>
<?php $appBrandName = $authUser['company_name'] ?? 'RentecarWeb'; ?>
<?php $assetVersion = '20260608-mobile-10'; ?>
<?php $companyLogoUrl = ($authUser && !empty($authUser['company_logo_path']) && function_exists('auth_company_logo_public_url')) ? auth_company_logo_public_url(['company_logo_path' => $authUser['company_logo_path']]) . '?v=' . rawurlencode((string) ($authUser['company_logo_path'] ?? $assetVersion)) : null; ?>
<?php
$headerNotificationFeedEnabled = $authUser && function_exists('auth_can') && auth_can('notifications.view');
$headerNotificationOpenCount = 0;
$headerNotificationCriticalCount = 0;
$headerNotificationItems = [];

if ($headerNotificationFeedEnabled && isset($pdo) && $pdo instanceof PDO && function_exists('notifications_sync_operational') && function_exists('notifications_fetch') && function_exists('auth_current_company_id')) {
    try {
        $headerNotificationCompanyId = auth_current_company_id();
        $headerNotificationSummary = notifications_sync_operational($pdo, $headerNotificationCompanyId);
        $headerNotifications = notifications_fetch($pdo, $headerNotificationCompanyId, 'active', 16);
        $headerNotificationOpenCount = count($headerNotifications);
        $headerNotificationCriticalCount = (int) ($headerNotificationSummary['critical_open_count'] ?? 0);

        foreach ($headerNotifications as $headerNotification) {
            $headerNotification['target_url'] = function_exists('notification_target_url') ? notification_target_url($headerNotification) : null;
            $headerNotificationItems[] = $headerNotification;
        }
    } catch (Throwable $exception) {
        $headerNotificationOpenCount = 0;
        $headerNotificationCriticalCount = 0;
        $headerNotificationItems = [];
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= h($appBrandName) ?>">
  <title><?= h($pageTitle) ?> | <?= h($appBrandName) ?></title>
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="icon" type="image/svg+xml" href="assets/icons/app-icon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css?v=<?= h($assetVersion) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark shadow-sm app-navbar">
  <div class="container-fluid app-navbar-inner">
    <span class="navbar-brand mb-0 h1 app-brand-mark">
      <?php if ($companyLogoUrl): ?><img src="<?= h($companyLogoUrl) ?>" alt="Firma logosu" class="app-brand-logo" width="44" height="44" style="width:44px; height:44px; min-width:44px; max-width:44px; min-height:44px; max-height:44px; object-fit:contain; display:block; flex:0 0 44px; overflow:hidden;"><?php endif; ?>
      <span><?= h($appBrandName) ?></span>
    </span>
    <?php if ($authUser): ?>
    <div class="app-navbar-user">
      <div class="app-navbar-meta">
        <strong><?= h($authUser['company_name'] ?? 'Firma') ?></strong>
        <span><?= h($authUser['full_name'] ?? $authUser['username']) ?> / <?= h(function_exists('auth_role_label') ? auth_role_label($authUser['role'] ?? null) : ($authUser['role'] ?? '-')) ?></span>
      </div>
      <?php if ($headerNotificationFeedEnabled): ?>
      <button class="btn btn-sm btn-outline-light app-notification-trigger" type="button" data-bs-toggle="offcanvas" data-bs-target="#globalNotificationDrawer" aria-controls="globalNotificationDrawer" aria-label="Mesaj Kutusu" title="Mesaj Kutusu">
        <span class="app-notification-trigger-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 1 0-14 0v5l-2 2v1h18v-1l-2-2Z"/></svg>
        </span>
        <?php if ($headerNotificationOpenCount > 0): ?>
        <span class="badge <?= $headerNotificationCriticalCount > 0 ? 'text-bg-danger' : 'text-bg-light' ?>"><?= h((string) $headerNotificationOpenCount) ?></span>
        <?php endif; ?>
      </button>
      <?php endif; ?>
      <a href="account_security.php" class="btn btn-sm btn-outline-light d-none d-md-inline-flex">Profil</a>
      <form action="actions/logout.php" method="post" class="mb-0 d-none d-md-block">
        <?= auth_csrf_input() ?>
        <button class="btn btn-sm btn-outline-light" type="submit">Cikis</button>
      </form>
      <button class="btn btn-sm btn-outline-light app-menu-trigger d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMoreMenu" aria-controls="mobileMoreMenu" aria-label="Menu" title="Menu">
        <span class="app-menu-trigger-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M4 7h16v2H4V7Zm0 8h16v2H4v-2Zm0-4h16v2H4v-2Z"/></svg>
        </span>
      </button>
    </div>
    <?php endif; ?>
  </div>
</nav>
<?php if ($headerNotificationFeedEnabled): ?>
<div class="offcanvas offcanvas-end app-notification-drawer" tabindex="-1" id="globalNotificationDrawer" aria-labelledby="globalNotificationDrawerLabel">
  <div class="offcanvas-header">
    <div>
      <div class="app-notification-drawer-eyebrow"><?= h($appBrandName) ?></div>
      <h5 class="offcanvas-title mb-0" id="globalNotificationDrawerLabel">Mesaj Kutusu</h5>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Kapat"></button>
  </div>
  <div class="offcanvas-body">
    <div class="app-notification-summary">
      <span class="dashboard-warning-chip is-dark">Toplam <?= h((string) $headerNotificationOpenCount) ?></span>
      <?php if ($headerNotificationCriticalCount > 0): ?><span class="dashboard-warning-chip is-danger"><?= h((string) $headerNotificationCriticalCount) ?> oncelikli</span><?php endif; ?>
    </div>

    <?php if ($headerNotificationOpenCount > 0): ?>
    <form action="actions/notification_mark_all_read.php" method="post" class="mb-3">
      <?= auth_csrf_input() ?>
      <button type="submit" class="btn btn-sm btn-outline-dark w-100">Tumunu Okundu Yap</button>
    </form>
    <?php endif; ?>

    <div class="app-notification-section">
      <?php if (empty($headerNotificationItems)): ?>
      <div class="dashboard-alert-empty">Su an acik bildirim yok.</div>
      <?php else: ?>
        <?php foreach ($headerNotificationItems as $notification): ?>
        <div class="dashboard-alert-item <?= ($notification['severity'] ?? '') === 'danger' ? 'is-danger' : '' ?> <?= ($notification['status'] ?? 'open') === 'read' ? 'app-notification-card is-read' : 'app-notification-card' ?>">
          <div class="dashboard-alert-text">
            <strong><?= h($notification['title'] ?? 'Bildirim') ?></strong>
            <span><?= h($notification['message'] ?? '') ?></span>
          </div>
          <div class="app-notification-actions">
            <?php if (!empty($notification['target_url'])): ?><a href="<?= h($notification['target_url']) ?>" class="dashboard-alert-link">Kayda Git</a><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="container-fluid">
  <div class="row">
