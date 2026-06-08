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
$canPlatform = function_exists('auth_can') && auth_can('platform.manage');
$mobileAccountName = (string) (auth_current_user()['full_name'] ?? auth_current_user()['username'] ?? 'Kullanici');
$mobileAccountInitial = function_exists('mb_substr') ? mb_substr($mobileAccountName, 0, 1) : substr($mobileAccountName, 0, 1);
$mobileAccountInitial = function_exists('mb_strtoupper') ? mb_strtoupper($mobileAccountInitial) : strtoupper($mobileAccountInitial);

$navClass = static function (array $pages, string $activeClass, string $inactiveClass) use ($current): string {
    return in_array($current, $pages, true) ? $activeClass : $inactiveClass;
};
?>
<div class="col-12 col-lg-2 app-sidebar-shell d-none d-lg-block">
  <div class="app-sidebar bg-white shadow-sm p-3">
    <h5 class="mb-3">Menu</h5>

    <div class="app-sidebar-section">
      <div class="app-sidebar-section-label">Operasyon</div>
      <div class="app-sidebar-links">
        <a href="index.php" class="btn <?= $navClass(['index.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Anasayfa</a>
        <?php if ($canRentals): ?><a href="rentals.php" class="btn <?= $navClass(['rentals.php', 'rental_form.php', 'rental_detail.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Kiralamalar</a><?php endif; ?>
        <?php if ($canRentals): ?><a href="collection_center.php" class="btn <?= $navClass(['collection_center.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Tahsilat Merkezi</a><?php endif; ?>
        <?php if ($canCars): ?><a href="cars.php" class="btn <?= $navClass(['cars.php', 'car_form.php', 'car_detail.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Araclar</a><?php endif; ?>
        <?php if ($canExpenses): ?><a href="expenses.php" class="btn <?= $navClass(['expenses.php', 'expense_form.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Isletme Giderleri</a><?php endif; ?>
        <?php if ($canLedger): ?><a href="business_accounts.php" class="btn <?= $navClass(['business_accounts.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Gelir Gider</a><?php endif; ?>
      </div>
    </div>

    <?php if ($canUsers || $canCompany || $canCustomers): ?>
    <div class="app-sidebar-section">
      <div class="app-sidebar-section-label">Yonetim</div>
      <div class="app-sidebar-links">
        <?php if ($canUsers): ?><a href="users.php" class="btn <?= $navClass(['users.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Kullanicilar</a><?php endif; ?>
        <?php if ($canCompany): ?><a href="company_settings.php" class="btn <?= $navClass(['company_settings.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Firma Ayarlari</a><?php endif; ?>
        <?php if ($canCustomers): ?><a href="customer_companies.php" class="btn <?= $navClass(['customer_companies.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Kurumsal Musteriler</a><?php endif; ?>
        <?php if ($canUsers): ?><a href="audit_logs.php" class="btn <?= $navClass(['audit_logs.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Audit Loglari</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="app-sidebar-section">
      <div class="app-sidebar-section-label">Hesabim</div>
      <div class="app-sidebar-links">
        <a href="account_security.php" class="btn <?= $navClass(['account_security.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Hesap Guvenligi</a>
        <?php if ($canPlatform): ?><a href="companies.php" class="btn <?= $navClass(['companies.php', 'company_detail.php'], 'btn-dark', 'btn-outline-dark') ?> sidebar-btn">Firmalar</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="col-12 col-lg-10 p-3 p-lg-4 app-content">
  <nav class="mobile-bottom-nav d-lg-none" aria-label="Hizli Erisim">
    <a href="index.php" class="mobile-bottom-link <?= $navClass(['index.php'], 'is-active', '') ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 3 10v10h6v-6h6v6h6V10l-9-7Zm1 9H11v6h2v-6Z"/></svg>
      <span>Anasayfa</span>
    </a>
    <?php if ($canRentals): ?><a href="rentals.php" class="mobile-bottom-link <?= $navClass(['rentals.php', 'rental_form.php', 'rental_detail.php'], 'is-active', '') ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11a2 2 0 0 1 2 2v12H7a2 2 0 0 0-2 2V4Zm2 2v10h9V6H7Zm12 1h1a2 2 0 0 1 2 2v11h-2V9h-1V7ZM9 8h5v2H9V8Zm0 4h5v2H9v-2Z"/></svg>
      <span>Kiralamalar</span>
    </a><?php endif; ?>
    <?php if ($canRentals): ?><a href="collection_center.php" class="mobile-bottom-link <?= $navClass(['collection_center.php'], 'is-active', '') ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V7l-8-4Zm1 12.2V17h-2v-1.8a5.2 5.2 0 0 1-2.7-.9l.8-1.8c.8.5 1.7.8 2.7.8 1.1 0 1.8-.4 1.8-1.2 0-.7-.6-1.1-2-1.6-2-.7-3.3-1.5-3.3-3.3 0-1.6 1.1-2.8 3-3.2V3h2v1.7c1.2 0 2 .3 2.6.6l-.8 1.7a4.7 4.7 0 0 0-2.5-.6c-1.1 0-1.6.5-1.6 1 0 .6.6 1 2.2 1.6 2.2.8 3.1 1.8 3.1 3.4 0 1.6-1.1 3-3.3 3.4Z"/></svg>
      <span>Tahsilat</span>
    </a><?php endif; ?>
    <?php if ($canCars): ?><a href="cars.php" class="mobile-bottom-link <?= $navClass(['cars.php', 'car_form.php', 'car_detail.php'], 'is-active', '') ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 6.5h10.8c1.4 0 2.7.9 3.2 2.2l1.2 3.2c.8.4 1.2 1.1 1.2 2v2.6c0 1.1-.9 2-2 2H20a2 2 0 0 1-1.7-1l-.5-.8H6.2l-.5.8a2 2 0 0 1-1.7 1H3c-1.1 0-2-.9-2-2V14c0-.9.4-1.6 1.2-2l1.2-3.2A3.4 3.4 0 0 1 6.6 6.5Zm0 1.8c-.7 0-1.3.4-1.5 1l-.8 2.1h15.4l-.8-2.1c-.2-.6-.8-1-1.5-1H6.6Zm-.8 6.4a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2Zm12.4 0a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2Z"/></svg>
      <span>Araclar</span>
    </a><?php endif; ?>
    <?php if ($canLedger): ?><a href="business_accounts.php" class="mobile-bottom-link <?= $navClass(['business_accounts.php'], 'is-active', '') ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4V5Zm2 2v10h12V7H6Zm2 6h3v2H8v-2Zm0-4h6v2H8V9Zm8 0h2v6h-2V9Z"/></svg>
      <span>Gelir Gider</span>
    </a><?php endif; ?>
  </nav>

  <div class="offcanvas offcanvas-end mobile-more-menu d-lg-none" tabindex="-1" id="mobileMoreMenu" aria-labelledby="mobileMoreMenuLabel">
    <div class="offcanvas-header">
      <div>
        <div class="mobile-more-menu-eyebrow"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
        <h5 class="offcanvas-title mb-0" id="mobileMoreMenuLabel">Menu</h5>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Kapat"></button>
    </div>
    <div class="offcanvas-body">
      <div class="mobile-account-card">
        <div class="mobile-account-card-head">
          <div class="mobile-account-avatar"><?= h($mobileAccountInitial !== '' ? $mobileAccountInitial : 'U') ?></div>
          <div class="mobile-account-meta">
            <strong><?= h($mobileAccountName) ?></strong>
            <span><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></span>
            <small><?= h(function_exists('auth_role_label') ? auth_role_label(auth_current_user()['role'] ?? null) : (auth_current_user()['role'] ?? '-')) ?></small>
          </div>
        </div>
        <div class="mobile-account-actions">
          <a href="account_security.php" class="mobile-more-link <?= $navClass(['account_security.php'], 'is-active', '') ?>">Profil</a>
          <a href="login.php" class="mobile-more-link">Yeni Oturum Ac</a>
          <form action="actions/logout.php" method="post" class="mobile-account-form">
            <?= auth_csrf_input() ?>
            <button type="submit" class="mobile-more-link mobile-more-link-danger">Oturumu Kapat</button>
          </form>
        </div>
      </div>

      <div class="mobile-more-menu-section">
        <div class="mobile-more-menu-label">Operasyon</div>
        <div class="mobile-more-menu-links">
          <a href="index.php" class="mobile-more-link <?= $navClass(['index.php'], 'is-active', '') ?>">Anasayfa</a>
          <?php if ($canRentals): ?><a href="rentals.php" class="mobile-more-link <?= $navClass(['rentals.php', 'rental_form.php', 'rental_detail.php'], 'is-active', '') ?>">Kiralamalar</a><?php endif; ?>
          <?php if ($canRentals): ?><a href="collection_center.php" class="mobile-more-link <?= $navClass(['collection_center.php'], 'is-active', '') ?>">Tahsilat Merkezi</a><?php endif; ?>
          <?php if ($canCars): ?><a href="cars.php" class="mobile-more-link <?= $navClass(['cars.php', 'car_form.php', 'car_detail.php'], 'is-active', '') ?>">Araclar</a><?php endif; ?>
          <?php if ($canExpenses): ?><a href="expenses.php" class="mobile-more-link <?= $navClass(['expenses.php', 'expense_form.php'], 'is-active', '') ?>">Isletme Giderleri</a><?php endif; ?>
          <?php if ($canLedger): ?><a href="business_accounts.php" class="mobile-more-link <?= $navClass(['business_accounts.php'], 'is-active', '') ?>">Gelir Gider</a><?php endif; ?>
        </div>
      </div>

      <?php if ($canUsers || $canCompany || $canCustomers || $canPlatform): ?>
      <div class="mobile-more-menu-section">
        <div class="mobile-more-menu-label">Yonetim</div>
        <div class="mobile-more-menu-links">
          <?php if ($canUsers): ?><a href="users.php" class="mobile-more-link <?= $navClass(['users.php'], 'is-active', '') ?>">Kullanicilar</a><?php endif; ?>
          <?php if ($canCompany): ?><a href="company_settings.php" class="mobile-more-link <?= $navClass(['company_settings.php'], 'is-active', '') ?>">Firma Ayarlari</a><?php endif; ?>
          <?php if ($canCustomers): ?><a href="customer_companies.php" class="mobile-more-link <?= $navClass(['customer_companies.php'], 'is-active', '') ?>">Kurumsal Musteriler</a><?php endif; ?>
          <?php if ($canUsers): ?><a href="audit_logs.php" class="mobile-more-link <?= $navClass(['audit_logs.php'], 'is-active', '') ?>">Audit Loglari</a><?php endif; ?>
          <?php if ($canPlatform): ?><a href="companies.php" class="mobile-more-link <?= $navClass(['companies.php', 'company_detail.php'], 'is-active', '') ?>">Firmalar</a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
