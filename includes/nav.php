<?php
$current = basename($_SERVER['PHP_SELF']);
$canUsers = function_exists('auth_can') && auth_can('users.manage');
$canCars = function_exists('auth_can') ? auth_can('cars.view') : true;
$canRentals = function_exists('auth_can') ? auth_can('rentals.view') : true;
$customerCompaniesEnabled = function_exists('app_feature_customer_companies_enabled') ? app_feature_customer_companies_enabled() : false;
$canCustomers = $customerCompaniesEnabled && (function_exists('auth_can') ? auth_can('customers.view') : true);
$canExpenses = function_exists('auth_can') ? auth_can('expenses.view') : true;
$canLedger = function_exists('auth_can') ? auth_can('ledger.view') : true;
$canCompany = function_exists('auth_can') && auth_can('company.manage');
$canRoles = function_exists('auth_can') && auth_can('roles.manage');
$canPlatform = function_exists('auth_can') && auth_can('platform.manage');
$currentUser = function_exists('auth_current_user') ? auth_current_user() : [];
$mobileAccountName = (string) ($currentUser['full_name'] ?? $currentUser['username'] ?? 'Kullanıcı');
$mobileAccountInitial = function_exists('mb_substr') ? mb_substr($mobileAccountName, 0, 1) : substr($mobileAccountName, 0, 1);
$mobileAccountInitial = function_exists('mb_strtoupper') ? mb_strtoupper($mobileAccountInitial) : strtoupper($mobileAccountInitial);
$mobileAccountAvatarUrl = function_exists('auth_user_avatar_public_url') ? auth_user_avatar_public_url($currentUser) : null;

$isCurrentPage = static function (array $pages) use ($current): bool {
    return in_array($current, $pages, true);
};

$navClass = static function (array $pages, string $activeClass, string $inactiveClass = '') use ($isCurrentPage): string {
    return $isCurrentPage($pages) ? $activeClass : $inactiveClass;
};

$buildNavItem = static function (
    string $label,
    string $href,
    array $pages,
    string $icon,
    bool $visible = true,
    ?string $mobileLabel = null
): ?array {
    if (!$visible) {
        return null;
    }

    return [
        'label' => $label,
        'mobile_label' => $mobileLabel ?? $label,
        'href' => $href,
        'pages' => $pages,
        'icon' => $icon,
    ];
};

$dashboardItem = $buildNavItem('Anasayfa', 'index.php', ['index.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 3 10v10h6v-6h6v6h6V10l-9-7Zm1 9H11v6h2v-6Z"/></svg>');
$rentalsItem = $buildNavItem('Kiralamalar', 'rentals.php', ['rentals.php', 'rental_form.php', 'rental_detail.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11a2 2 0 0 1 2 2v12H7a2 2 0 0 0-2 2V4Zm2 2v10h9V6H7Zm12 1h1a2 2 0 0 1 2 2v11h-2V9h-1V7ZM9 8h5v2H9V8Zm0 4h5v2H9v-2Z"/></svg>', $canRentals);
$collectionsItem = $buildNavItem('Tahsilat Merkezi', 'collection_center.php', ['collection_center.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V7l-8-4Zm1 12.2V17h-2v-1.8a5.2 5.2 0 0 1-2.7-.9l.8-1.8c.8.5 1.7.8 2.7.8 1.1 0 1.8-.4 1.8-1.2 0-.7-.6-1.1-2-1.6-2-.7-3.3-1.5-3.3-3.3 0-1.6 1.1-2.8 3-3.2V3h2v1.7c1.2 0 2 .3 2.6.6l-.8 1.7a4.7 4.7 0 0 0-2.5-.6c-1.1 0-1.6.5-1.6 1 0 .6.6 1 2.2 1.6 2.2.8 3.1 1.8 3.1 3.4 0 1.6-1.1 3-3.3 3.4Z"/></svg>', $canRentals, 'Tahsilat');
$carsItem = $buildNavItem('Araçlar', 'cars.php', ['cars.php', 'car_form.php', 'car_detail.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 6.5h10.8c1.4 0 2.7.9 3.2 2.2l1.2 3.2c.8.4 1.2 1.1 1.2 2v2.6c0 1.1-.9 2-2 2H20a2 2 0 0 1-1.7-1l-.5-.8H6.2l-.5.8a2 2 0 0 1-1.7 1H3c-1.1 0-2-.9-2-2V14c0-.9.4-1.6 1.2-2l1.2-3.2A3.4 3.4 0 0 1 6.6 6.5Zm0 1.8c-.7 0-1.3.4-1.5 1l-.8 2.1h15.4l-.8-2.1c-.2-.6-.8-1-1.5-1H6.6Zm-.8 6.4a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2Zm12.4 0a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2Z"/></svg>', $canCars);
$expensesItem = $buildNavItem('İşletme Giderleri', 'expenses.php', ['expenses.php', 'expense_form.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h10l4 4v14H5V3Zm9 1.5V8h3.5L14 4.5ZM8 11h8v2H8v-2Zm0 4h8v2H8v-2Z"/></svg>', $canExpenses);
$ledgerItem = $buildNavItem('Gelir Gider', 'business_accounts.php', ['business_accounts.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4V5Zm2 2v10h12V7H6Zm2 6h3v2H8v-2Zm0-4h6v2H8V9Zm8 0h2v6h-2V9Z"/></svg>', $canLedger);

$usersItem = $buildNavItem('Kullanıcılar', 'users.php', ['users.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a4 4 0 1 0-3.999-4A4 4 0 0 0 16 11Zm-8 1a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm8 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4ZM8 14c-.29 0-.62.02-.97.05A5.38 5.38 0 0 1 10 18v2H2v-2c0-1.95 2.37-3.33 6-4Z"/></svg>', $canUsers);
$rolesItem = $buildNavItem('Roller ve Yetkiler', 'roles.php', ['roles.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1 6h2v7h-2V8Zm0 9h2v2h-2v-2Zm-5-5h3v2H6v-2Zm9 0h3v2h-3v-2Z"/></svg>', $canRoles);
$companySettingsItem = $buildNavItem('Firma Ayarları', 'company_settings.php', ['company_settings.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m19.4 13-.9-.5a7 7 0 0 0 0-1l.9-.5 1-1.8-1-.6a6.4 6.4 0 0 0-.6-.9l.3-1.1-2-1.1-.8.8a7.2 7.2 0 0 0-1-.2L14 2h-4l-.3 1.1a7.2 7.2 0 0 0-1 .2l-.8-.8-2 1.1.3 1.1c-.2.3-.4.6-.6.9l-1 .6 1 1.8.9.5a7 7 0 0 0 0 1l-.9.5-1 1.8 1 .6c.17.31.37.61.6.9l-.3 1.1 2 1.1.8-.8c.33.09.66.15 1 .2L10 22h4l.3-1.1c.34-.05.67-.11 1-.2l.8.8 2-1.1-.3-1.1c.23-.29.43-.59.6-.9l1-.6-1-1.8ZM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"/></svg>', $canCompany);
$customerCompaniesItem = $buildNavItem('Kurumsal Müşteriler', 'customer_companies.php', ['customer_companies.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21V7l9-4 9 4v14H3Zm10-2h6V8.3l-6-2.7V19Zm-8 0h6V5.6L5 8.3V19Zm2-7h2v2H7v-2Zm0 4h2v2H7v-2Zm8-4h2v2h-2v-2Zm0 4h2v2h-2v-2Z"/></svg>', $canCustomers);
$auditLogsItem = $buildNavItem('Audit Logları', 'audit_logs.php', ['audit_logs.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h12l4 4v14H5V3Zm11 1.5V8h3.5L16 4.5ZM8 11h8v2H8v-2Zm0 4h8v2H8v-2Zm0-8h5v2H8V7Z"/></svg>', $canUsers);
$migrationsItem = $buildNavItem('Migrasyonlar', 'migrations.php', ['migrations.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 6v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V6l-8-4Zm1 14h-2v-2h2v2Zm0-4h-2V7h2v5Z"/></svg>', $canPlatform);
$companiesItem = $buildNavItem('Firmalar', 'companies.php', ['companies.php', 'company_detail.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V6l8-3 8 3v14h-6v-5h-4v5H4Zm2-2h2v-2H6v2Zm0-4h2v-2H6v2Zm0-4h2V8H6v2Zm10 8h2v-2h-2v2Zm0-4h2v-2h-2v2Zm0-4h2V8h-2v2Z"/></svg>', $canPlatform);
$accountSecurityItem = $buildNavItem('Hesap Güvenliği', 'account_security.php', ['account_security.php'], '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm0 5a3 3 0 0 1 3 3v1h1a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-5a1 1 0 0 1 1-1h1v-1a3 3 0 0 1 3-3Zm-1 4h2v-1a1 1 0 0 0-2 0v1Z"/></svg>');

$operationItems = array_values(array_filter([
    $dashboardItem,
    $rentalsItem,
    $collectionsItem,
    $carsItem,
    $expensesItem,
    $ledgerItem,
]));

$managementItems = array_values(array_filter([
    $usersItem,
    $rolesItem,
    $companySettingsItem,
    $customerCompaniesItem,
    $auditLogsItem,
    $migrationsItem,
]));

$accountItems = array_values(array_filter([
    $accountSecurityItem,
    $companiesItem,
]));

$bottomNavItems = array_values(array_filter([
    $dashboardItem,
    $rentalsItem,
    $collectionsItem,
    $carsItem,
    $ledgerItem,
]));
?>
<div class="col-12 col-lg-2 app-sidebar-shell d-none d-lg-block">
  <div class="app-sidebar bg-white shadow-sm p-3">
    <h5 class="mb-3">Menü</h5>

    <?php if (!empty($operationItems)): ?>
    <div class="app-sidebar-section">
      <div class="app-sidebar-section-label">Operasyon</div>
      <div class="app-sidebar-links">
        <?php foreach ($operationItems as $item): ?>
        <a href="<?= h($item['href']) ?>" class="btn <?= h($navClass($item['pages'], 'btn-dark', 'btn-outline-dark')) ?> sidebar-btn"><?= h($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($managementItems)): ?>
    <div class="app-sidebar-section">
      <div class="app-sidebar-section-label">Yönetim</div>
      <div class="app-sidebar-links">
        <?php foreach ($managementItems as $item): ?>
        <a href="<?= h($item['href']) ?>" class="btn <?= h($navClass($item['pages'], 'btn-dark', 'btn-outline-dark')) ?> sidebar-btn"><?= h($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($accountItems)): ?>
    <div class="app-sidebar-section">
      <div class="app-sidebar-section-label">Hesabım</div>
      <div class="app-sidebar-links">
        <?php foreach ($accountItems as $item): ?>
        <a href="<?= h($item['href']) ?>" class="btn <?= h($navClass($item['pages'], 'btn-dark', 'btn-outline-dark')) ?> sidebar-btn"><?= h($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="col-12 col-lg-10 p-3 p-lg-4 app-content">
  <?php if (!empty($bottomNavItems)): ?>
  <nav class="mobile-bottom-nav d-lg-none" aria-label="Hızlı Erişim">
    <?php foreach ($bottomNavItems as $item): ?>
    <a href="<?= h($item['href']) ?>" class="mobile-bottom-link <?= $isCurrentPage($item['pages']) ? 'is-active' : '' ?>">
      <?= $item['icon'] ?>
      <span><?= h($item['mobile_label']) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <div class="offcanvas offcanvas-end mobile-more-menu d-lg-none" tabindex="-1" id="mobileMoreMenu" aria-labelledby="mobileMoreMenuLabel">
    <div class="offcanvas-header">
      <div>
        <div class="mobile-more-menu-eyebrow"><?= h($currentUser['company_name'] ?? 'Firma') ?></div>
        <h5 class="offcanvas-title mb-0" id="mobileMoreMenuLabel">Menü</h5>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Kapat"></button>
    </div>
    <div class="offcanvas-body">
      <div class="mobile-account-card">
        <div class="mobile-account-card-head">
          <div class="mobile-account-avatar">
            <?php if ($mobileAccountAvatarUrl): ?>
            <img src="<?= h($mobileAccountAvatarUrl) ?>?v=<?= h(rawurlencode((string) ($currentUser['avatar_path'] ?? 'avatar'))) ?>" alt="Profil fotoğrafı" class="mobile-account-avatar-image" style="<?= h(auth_avatar_position_style($currentUser)) ?>">
            <?php else: ?>
            <?= h($mobileAccountInitial !== '' ? $mobileAccountInitial : 'U') ?>
            <?php endif; ?>
          </div>
          <div class="mobile-account-meta">
            <strong><?= h($mobileAccountName) ?></strong>
            <span><?= h($currentUser['company_name'] ?? 'Firma') ?></span>
            <small><?= h(function_exists('auth_user_role_label') ? auth_user_role_label($currentUser) : (function_exists('auth_role_label') ? auth_role_label($currentUser['role'] ?? null) : ($currentUser['role'] ?? '-'))) ?></small>
          </div>
        </div>
        <div class="mobile-account-actions">
          <a href="account_security.php" class="mobile-more-link <?= $isCurrentPage(['account_security.php']) ? 'is-active' : '' ?>">Profil</a>
          <a href="login.php" class="mobile-more-link">Yeni Oturum</a>
          <form action="actions/logout.php" method="post" class="mobile-account-form">
            <?= auth_csrf_input() ?>
            <button type="submit" class="mobile-more-link mobile-more-link-danger">Oturumu Kapat</button>
          </form>
        </div>
      </div>

      <?php if (!empty($operationItems)): ?>
      <div class="mobile-more-menu-section">
        <div class="mobile-more-menu-label">Operasyon</div>
        <div class="mobile-more-menu-links">
          <?php foreach ($operationItems as $item): ?>
          <a href="<?= h($item['href']) ?>" class="mobile-more-link <?= $isCurrentPage($item['pages']) ? 'is-active' : '' ?>"><?= h($item['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($managementItems)): ?>
      <div class="mobile-more-menu-section">
        <div class="mobile-more-menu-label">Yönetim</div>
        <div class="mobile-more-menu-links">
          <?php foreach ($managementItems as $item): ?>
          <a href="<?= h($item['href']) ?>" class="mobile-more-link <?= $isCurrentPage($item['pages']) ? 'is-active' : '' ?>"><?= h($item['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($accountItems)): ?>
      <div class="mobile-more-menu-section">
        <div class="mobile-more-menu-label">Hesabım</div>
        <div class="mobile-more-menu-links">
          <?php foreach ($accountItems as $item): ?>
          <a href="<?= h($item['href']) ?>" class="mobile-more-link <?= $isCurrentPage($item['pages']) ? 'is-active' : '' ?>"><?= h($item['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

