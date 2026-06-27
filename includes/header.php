<?php if (!isset($pageTitle)) { $pageTitle = 'RentecarWeb'; } ?>
<?php $authUser = function_exists('auth_current_user') ? auth_current_user() : null; ?>
<?php $appBrandName = $authUser['company_name'] ?? 'RentecarWeb'; ?>
<?php
$assetVersionCandidates = [
    __DIR__ . '/../assets/css/style.css',
    __DIR__ . '/../assets/js/main.js',
    __DIR__ . '/../assets/js/roles.js',
];
$assetVersion = '20260626';
foreach ($assetVersionCandidates as $assetVersionFile) {
    $assetVersion = max($assetVersion, (string) (@filemtime($assetVersionFile) ?: 0));
}
$appBaseUrl = function_exists('app_base_url') ? app_base_url() : '';
?>
<?php $companyLogoUrl = ($authUser && !empty($authUser['company_logo_path']) && function_exists('auth_company_logo_public_url')) ? auth_company_logo_public_url(['company_logo_path' => $authUser['company_logo_path']]) . '?v=' . rawurlencode((string) ($authUser['company_logo_path'] ?? $assetVersion)) : null; ?>
<?php $headerAvatarUrl = ($authUser && !empty($authUser['avatar_path']) && function_exists('auth_user_avatar_public_url')) ? auth_user_avatar_public_url($authUser) . '?v=' . rawurlencode((string) ($authUser['avatar_path'] ?? $assetVersion)) : null; ?>
<?php $headerRoleLabel = $authUser ? (function_exists('auth_user_role_label') ? auth_user_role_label($authUser) : ((function_exists('auth_role_label') ? auth_role_label($authUser['role'] ?? null) : ($authUser['role'] ?? '-')))) : null; ?>
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
<html lang="tr" data-app-base-url="<?= h($appBaseUrl) ?>">
<head>
  <?php if (!headers_sent()) { header('Content-Type: text/html; charset=UTF-8'); } ?>
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
      <?php if ($companyLogoUrl): ?>
      <span class="app-brand-logo-wrap">
        <img src="<?= h($companyLogoUrl) ?>" alt="Firma logosu" class="app-brand-logo" width="44" height="44">
      </span>
      <?php endif; ?>
      <span class="app-brand-copy">
        <span class="app-brand-title"><?= h($appBrandName) ?></span>
        <span class="app-brand-subtitle">Operasyon Paneli • Canlı Test 24 Haz 2026</span>
      </span>
    </span>
    <?php if ($authUser): ?>
    <div class="app-navbar-user">
      <div class="app-navbar-meta d-none d-xl-flex">
        <strong><?= h($authUser['full_name'] ?? $authUser['username']) ?></strong>
        <span><?= h($headerRoleLabel ?? '-') ?></span>
      </div>
      <div class="app-header-actions">
      <?php if ($headerNotificationFeedEnabled): ?>
      <button class="btn btn-sm btn-outline-light app-header-action app-notification-trigger" type="button" data-bs-toggle="offcanvas" data-bs-target="#globalNotificationDrawer" aria-controls="globalNotificationDrawer" aria-label="Mesaj kutusu" title="Mesaj kutusu">
        <span class="app-notification-trigger-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 1 0-14 0v5l-2 2v1h18v-1l-2-2Z"/></svg>
        </span>
        <?php if ($headerNotificationOpenCount > 0): ?>
        <span class="badge <?= $headerNotificationCriticalCount > 0 ? 'text-bg-danger' : 'text-bg-light' ?>"><?= h((string) $headerNotificationOpenCount) ?></span>
        <?php endif; ?>
      </button>
      <?php endif; ?>
      <a href="account_security.php" class="btn btn-sm btn-outline-light app-header-action d-none d-lg-inline-flex" aria-label="Profil" title="Profil">
        <?php if ($headerAvatarUrl): ?>
        <img src="<?= h($headerAvatarUrl) ?>" alt="Profil fotoğrafı" class="app-header-avatar" width="24" height="24" style="<?= h(auth_avatar_position_style($authUser)) ?>">
        <?php else: ?>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-3.33 0-6 1.79-6 4v1h12v-1c0-2.21-2.67-4-6-4Z"/></svg>
        <?php endif; ?>
      </a>
      <form action="actions/logout.php" method="post" class="mb-0 d-none d-lg-block">
        <?= auth_csrf_input() ?>
        <button class="btn btn-sm btn-outline-light app-header-action" type="submit" aria-label="Çıkış" title="Çıkış">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17v-2h4V9h-4V7h4a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-4Zm-1-1-4-4 4-4 1.4 1.4L8.83 11H20v2H8.83l1.57 1.6L9 16Z"/></svg>
        </button>
      </form>
      <button class="btn btn-sm btn-outline-light app-header-action app-menu-trigger d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMoreMenu" aria-controls="mobileMoreMenu" aria-label="Menü" title="Menü">
        <span class="app-menu-trigger-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M4 7h16v2H4V7Zm0 8h16v2H4v-2Zm0-4h16v2H4v-2Z"/></svg>
        </span>
      </button>
      </div>
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
      <?php if ($headerNotificationCriticalCount > 0): ?><span class="dashboard-warning-chip is-danger"><?= h((string) $headerNotificationCriticalCount) ?> öncelikli</span><?php endif; ?>
    </div>

    <?php if ($headerNotificationOpenCount > 0): ?>
    <form action="actions/notification_mark_all_read.php" method="post" class="mb-3">
      <?= auth_csrf_input() ?>
      <button type="submit" class="btn btn-sm btn-outline-dark w-100">Tümünü Okundu Yap</button>
    </form>
    <?php endif; ?>

    <div class="app-notification-section">
      <?php if (empty($headerNotificationItems)): ?>
      <div class="dashboard-alert-empty">Şu an açık bildirim yok.</div>
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

