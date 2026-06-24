<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('platform.manage');

ensureCarArchiveSchema($pdo);
ensureExpenseArchiveSchema($pdo);

$entryStatus = $_GET['status'] ?? '';
$currentCompanyId = auth_current_company_id();
$roleOptions = auth_assignable_role_options_db($pdo, auth_current_user(), 0);

$companies = $pdo->query("
    SELECT
        c.*,
        (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.archived_at IS NULL) AS user_count,
        (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.is_active = 1 AND u.archived_at IS NULL) AS active_user_count,
        (SELECT COUNT(*) FROM cars car WHERE car.company_id = c.id AND car.archived_at IS NULL) AS car_count,
        (SELECT COUNT(*) FROM rentals r WHERE r.company_id = c.id) AS rental_count,
        (SELECT COUNT(*) FROM business_expenses e WHERE e.company_id = c.id AND e.archived_at IS NULL) AS expense_count,
        (SELECT COUNT(*) FROM ledger_partners lp WHERE lp.company_id = c.id) AS partner_count,
        (SELECT COUNT(*) FROM ledger_periods lpe WHERE lpe.company_id = c.id) AS period_count,
        (SELECT COUNT(*) FROM ledger_entries le WHERE le.company_id = c.id) AS entry_count
    FROM companies c
    ORDER BY (c.id = " . (int) $currentCompanyId . ") DESC, c.is_active DESC, c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$companyUsers = [];
if (!empty($companies)) {
    $companyIds = array_map('intval', array_column($companies, 'id'));
    $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
    $usersSt = $pdo->prepare("
        SELECT
            u.id,
            u.company_id,
            u.full_name,
            u.username,
            u.role,
            u.custom_role_id,
            u.is_active,
            cr.name AS custom_role_name,
            cr.description AS custom_role_description,
            CASE
                WHEN u.role = 'custom' AND cr.id IS NOT NULL AND cr.is_active = 1 AND cr.archived_at IS NULL THEN
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT crp.permission_key ORDER BY crp.permission_key SEPARATOR ',')
                        FROM company_role_permissions crp
                        WHERE crp.role_id = cr.id
                    ), '')
                ELSE ''
            END AS custom_permissions_json
        FROM users u
        LEFT JOIN company_roles cr ON cr.id = u.custom_role_id AND cr.company_id = u.company_id
        WHERE u.company_id IN ($placeholders) AND u.archived_at IS NULL
        ORDER BY u.company_id ASC, u.created_at ASC, u.id ASC
    ");
    $usersSt->execute($companyIds);
    foreach ($usersSt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $companyUsers[(int) $user['company_id']][] = $user;
    }
}

$companyCount = count($companies);
$activeCompanyCount = count(array_filter($companies, static fn (array $company): bool => (int) ($company['is_active'] ?? 0) === 1));
$totalUsers = array_sum(array_map(static fn (array $company): int => (int) ($company['user_count'] ?? 0), $companies));
$totalCars = array_sum(array_map(static fn (array $company): int => (int) ($company['car_count'] ?? 0), $companies));
$totalRentals = array_sum(array_map(static fn (array $company): int => (int) ($company['rental_count'] ?? 0), $companies));
$companiesPagination = paginate_collection($companies, 'companies_page', 'companies_per_page', 10, [10, 20, 50, 100]);
$companies = $companiesPagination['items'];

$pageTitle = 'Firma Yönetimi';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page companies-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Platform') ?></div>
      <h2 class="mb-0">Firma Yönetimi</h2>
    </div>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#companyModal" data-mode="create">Firma Ekle</button>
  </div>

  <?php if ($entryStatus === 'company_saved'): ?>
  <div class="alert alert-success">Yeni firma ve ilk yönetici kullanıcısı oluşturuldu.</div>
  <?php elseif ($entryStatus === 'user_saved'): ?>
  <div class="alert alert-success">Firma kullanıcısı oluşturuldu.</div>
  <?php elseif ($entryStatus === 'company_status_changed'): ?>
  <div class="alert alert-success">Firma durumu güncellendi.</div>
  <?php elseif ($entryStatus === 'company_deleted'): ?>
  <div class="alert alert-success">Pasif ve boş firma kalıcı olarak silindi.</div>
  <?php elseif ($entryStatus === 'company_exists'): ?>
  <div class="alert alert-danger">Aynı isimde bir firma zaten kayıtlı.</div>
  <?php elseif ($entryStatus === 'username_exists'): ?>
  <div class="alert alert-danger">Bu kullanıcı adı zaten kullanılıyor.</div>
  <?php elseif ($entryStatus === 'self_company_blocked'): ?>
  <div class="alert alert-danger">Kendi ana firmanı bu ekrandan pasife alamazsın.</div>
  <?php elseif ($entryStatus === 'company_delete_blocked'): ?>
  <div class="alert alert-danger">Sadece pasif ve tamamen boş firmalar silinebilir.</div>
  <?php elseif ($entryStatus === 'weak_password'): ?>
  <div class="alert alert-danger"><?= h(auth_password_policy_description()) ?></div>
  <?php elseif ($entryStatus === 'invalid'): ?>
  <div class="alert alert-danger">Bilgileri kontrol edip tekrar dene.</div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Toplam Firma</div><div class="fs-3 fw-semibold"><?= h((string) $companyCount) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Aktif Firma</div><div class="fs-3 fw-semibold"><?= h((string) $activeCompanyCount) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Toplam Kullanıcı</div><div class="fs-3 fw-semibold"><?= h((string) $totalUsers) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Toplam Araç</div><div class="fs-3 fw-semibold"><?= h((string) $totalCars) ?></div><div class="small text-muted">Kiralama <?= h((string) $totalRentals) ?></div></div></div></div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Kayıtlı Firmalar</span>
      <span class="badge bg-dark"><?= h((string) $companyCount) ?> firma</span>
    </div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped align-middle">
        <tr>
          <th>Firma</th>
          <th>Durum</th>
          <th>Operasyon</th>
          <th>Kullanıcı Özeti</th>
          <th>İletişim</th>
          <th>İşlem</th>
        </tr>
        <?php if (empty($companies)): ?>
        <tr><td colspan="6" class="text-center text-muted">Henüz firma yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($companies as $company): ?>
        <?php
          $companyId = (int) ($company['id'] ?? 0);
          $users = $companyUsers[$companyId] ?? [];
          $visibleUsers = array_slice($users, 0, 2);
          $hiddenUserCount = max(0, count($users) - count($visibleUsers));
          $canDeleteCompany = $companyId !== $currentCompanyId
            && (int) ($company['is_active'] ?? 0) !== 1
            && (int) ($company['user_count'] ?? 0) === 0
            && (int) ($company['car_count'] ?? 0) === 0
            && (int) ($company['rental_count'] ?? 0) === 0
            && (int) ($company['expense_count'] ?? 0) === 0
            && (int) ($company['partner_count'] ?? 0) === 0
            && (int) ($company['period_count'] ?? 0) === 0
            && (int) ($company['entry_count'] ?? 0) === 0;
        ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($company['name'] ?? '') ?></div>
            <small class="text-muted"><?= h($company['legal_name'] ?: ($company['name'] ?? '')) ?></small>
            <?php if ($companyId === $currentCompanyId): ?>
            <div><span class="badge bg-dark mt-2">Ana Firma</span></div>
            <?php endif; ?>
          </td>
          <td><?= (int) ($company['is_active'] ?? 0) === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>' ?></td>
          <td>
            <div class="company-metric-line">Araç <strong><?= h((string) ($company['car_count'] ?? 0)) ?></strong></div>
            <div class="company-metric-line">Kiralama <strong><?= h((string) ($company['rental_count'] ?? 0)) ?></strong></div>
            <div class="company-metric-line">Gider <strong><?= h((string) ($company['expense_count'] ?? 0)) ?></strong></div>
          </td>
          <td>
            <div class="small text-muted mb-2">Toplam <?= h((string) ($company['user_count'] ?? 0)) ?> / Aktif <?= h((string) ($company['active_user_count'] ?? 0)) ?></div>
            <?php if (empty($visibleUsers)): ?>
            <span class="text-muted">Kullanıcı yok</span>
            <?php else: ?>
              <?php foreach ($visibleUsers as $user): ?>
              <div class="company-user-line">
                <strong><?= h($user['full_name'] ?? '') ?></strong>
                <small class="text-muted"><?= h($user['username'] ?? '') ?> / <?= h(auth_user_role_label($user)) ?></small>
              </div>
              <?php endforeach; ?>
              <?php if ($hiddenUserCount > 0): ?>
              <div class="small text-muted">+<?= h((string) $hiddenUserCount) ?> kullanıcı daha</div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <div><?= h($company['phone'] ?? '-') ?></div>
            <div><?= h($company['email'] ?? '-') ?></div>
            <small class="text-muted"><?= h($company['city'] ?? '-') ?></small>
          </td>
          <td class="table-actions-cell">
            <div class="action-group">
              <a class="action-btn action-warning" href="company_detail.php?id=<?= h($companyId) ?>" title="Detay" aria-label="Detay">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 10 5.4 10 7s-4.5 7-10 7S2 13.6 2 12s4.5-7 10-7Zm0 2C8 7 4.8 10.7 4.1 12 4.8 13.3 8 17 12 17s7.2-3.7 7.9-5C19.2 10.7 16 7 12 7Zm0 2.5a2.5 2.5 0 1 1-2.5 2.5A2.5 2.5 0 0 1 12 9.5Z"/></svg>
              </a>
              <?php if ($companyId !== $currentCompanyId): ?>
              <form action="actions/platform_company_toggle.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="company_id" value="<?= h($companyId) ?>">
                <input type="hidden" name="is_active" value="<?= (int) ($company['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                <button class="action-btn <?= (int) ($company['is_active'] ?? 0) === 1 ? 'action-danger' : 'action-success' ?>" type="submit" title="<?= (int) ($company['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>" aria-label="<?= (int) ($company['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>" data-confirm="<?= (int) ($company['is_active'] ?? 0) === 1 ? 'Bu firmayı pasife almak istediğinize emin misiniz?' : 'Bu firmayı tekrar aktif etmek istediğinize emin misiniz?' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 5v10h-2V7h2Z"/></svg>
                </button>
              </form>
              <?php if ($canDeleteCompany): ?>
              <form action="actions/platform_company_delete.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="company_id" value="<?= h($companyId) ?>">
                <button class="action-btn action-danger" type="submit" title="Kalıcı Sil" aria-label="Kalıcı Sil" data-confirm="Bu boş ve pasif firmayı kalıcı olarak silmek istediğinize emin misiniz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 11h12l1-13H5l1 13Z"/></svg>
                </button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?= pagination_render($companiesPagination, ['item_label' => 'firma']) ?>
    </div>
  </div>
</div>

<div class="modal fade" id="companyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Yeni Firma Oluştur</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/platform_company_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Firma Adı</label>
              <input name="company_name" class="form-control" maxlength="150" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Resmi Unvan</label>
              <input name="legal_name" class="form-control" maxlength="180">
            </div>
            <div class="col-md-4">
              <label class="form-label">E-posta</label>
              <input name="email" type="email" class="form-control" maxlength="150">
            </div>
            <div class="col-md-4">
              <label class="form-label">Telefon</label>
              <input name="phone" class="form-control" maxlength="30">
            </div>
            <div class="col-md-4">
              <label class="form-label">Şehir</label>
              <input name="city" class="form-control" maxlength="120">
            </div>
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-md-4">
              <label class="form-label">İlk Yetkili Ad Soyad</label>
              <input name="admin_full_name" class="form-control" maxlength="150" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">İlk Yetkili Kullanıcı Adı</label>
              <input name="admin_username" class="form-control" maxlength="80" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">İlk Yetkili Şifre</label>
              <input name="admin_password" type="password" class="form-control" required>
              <div class="form-text"><?= h(auth_password_policy_description()) ?></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-success" type="submit">Firmayı Oluştur</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="platformUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Firma Kullanıcısı Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/platform_user_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="company_id" value="">
          <div class="mb-3">
            <label class="form-label">Firma</label>
            <input name="company_label" class="form-control" value="" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Ad Soyad</label>
            <input name="full_name" class="form-control" maxlength="150" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Kullanıcı Adı</label>
            <input name="username" class="form-control" maxlength="80" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Rol</label>
            <select name="role" class="form-select" required>
              <?php foreach ($roleOptions as $key => $role): ?>
              <option value="<?= h($key) ?>"><?= h($role['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Şifre</label>
            <input name="password" type="password" class="form-control" required>
            <div class="form-text"><?= h(auth_password_policy_description()) ?></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button class="btn btn-success" type="submit" data-submit-label>Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
