<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('platform.manage');

ensureCarArchiveSchema($pdo);
ensureExpenseArchiveSchema($pdo);
$companyId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$entryStatus = $_GET['status'] ?? '';

if ($companyId <= 0) {
    auth_redirect('companies.php?status=invalid');
}

$companySt = $pdo->prepare("
    SELECT
        c.*,
        (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.archived_at IS NULL) AS user_count,
        (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.is_active = 1 AND u.archived_at IS NULL) AS active_user_count,
        (SELECT COUNT(*) FROM cars car WHERE car.company_id = c.id AND car.archived_at IS NULL) AS car_count,
        (SELECT COUNT(*) FROM rentals r WHERE r.company_id = c.id) AS rental_count,
        (SELECT COUNT(*) FROM business_expenses e WHERE e.company_id = c.id AND e.archived_at IS NULL) AS expense_count,
        (SELECT COUNT(*) FROM ledger_entries le WHERE le.company_id = c.id) AS entry_count
    FROM companies c
    WHERE c.id = ?
    LIMIT 1
");
$companySt->execute([$companyId]);
$company = $companySt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    auth_redirect('companies.php?status=invalid');
}

$showArchivedUsers = isset($_GET['show_archived_users']) && $_GET['show_archived_users'] === '1';
$usersSql = "
    SELECT
        u.*,
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
    WHERE u.company_id = ?
";
$usersSql .= $showArchivedUsers ? ' AND u.archived_at IS NOT NULL' : ' AND u.archived_at IS NULL';
$usersSql .= ' ORDER BY u.created_at ASC, u.id ASC';
$usersSt = $pdo->prepare($usersSql);
$usersSt->execute([$companyId]);
$users = $usersSt->fetchAll(PDO::FETCH_ASSOC);
$usersPagination = paginate_collection($users, 'detail_users_page', 'detail_users_per_page', 10, [10, 20, 50, 100]);
$users = $usersPagination['items'];

$roleOptions = auth_assignable_role_options_db($pdo, auth_current_user(), $companyId);
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$pageTitle = 'Firma Detayı';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label">Platform / <?= h($company['name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Firma Detayı</h2>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="companies.php" class="btn btn-outline-dark">Firmalara Dön</a>
      <?php if (auth_can('roles.manage')): ?>
      <a href="roles.php?company_id=<?= h((string) $companyId) ?>" class="btn btn-outline-dark">Roller ve Yetkiler</a>
      <?php endif; ?>
      <?php if (!$showArchivedUsers): ?>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#managedCompanyUserModal" data-mode="create" data-company-id="<?= h($companyId) ?>" data-company-label="<?= h($company['name'] ?? '') ?>">Kullanıcı Ekle</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($entryStatus === 'company_updated'): ?>
  <div class="alert alert-success">Firma profili güncellendi.</div>
  <?php elseif ($entryStatus === 'user_saved'): ?>
  <div class="alert alert-success">Firma kullanıcısı kaydedildi.</div>
  <?php elseif ($entryStatus === 'user_status_changed'): ?>
  <div class="alert alert-success">Kullanıcı durumu güncellendi.</div>
  <?php elseif ($entryStatus === 'user_deleted'): ?>
  <div class="alert alert-success">Kullanıcı arşive alındı.</div>
  <?php elseif ($entryStatus === 'user_restored'): ?>
  <div class="alert alert-success">Kullanıcı arşivden geri yüklendi.</div>
  <?php elseif ($entryStatus === 'username_exists'): ?>
  <div class="alert alert-danger">Bu kullanıcı adı zaten kullanılıyor.</div>
  <?php elseif ($entryStatus === 'self_role_locked'): ?>
  <div class="alert alert-danger">Kendi rolünü bu ekrandan değiştiremezsin.</div>
  <?php elseif ($entryStatus === 'self_delete_blocked'): ?>
  <div class="alert alert-danger">Kendi hesabını silemezsin.</div>
  <?php elseif ($entryStatus === 'last_admin_blocked'): ?>
  <div class="alert alert-danger">Firmadaki son yönetici hesabı pasife alınamaz veya silinemez.</div>
  <?php elseif ($entryStatus === 'email_invalid'): ?>
  <div class="alert alert-danger">E-posta formatı geçersiz.</div>
  <?php elseif ($entryStatus === 'website_invalid'): ?>
  <div class="alert alert-danger">Web sitesi adresi geçersiz.</div>
  <?php elseif ($entryStatus === 'company_exists'): ?>
  <div class="alert alert-danger">Aynı isimde bir firma zaten kayıtlı.</div>
  <?php elseif ($entryStatus === 'weak_password'): ?>
  <div class="alert alert-danger"><?= h(auth_password_policy_description()) ?></div>
  <?php elseif ($entryStatus === 'invalid'): ?>
  <div class="alert alert-danger">Bilgileri kontrol edip tekrar dene.</div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1">Kullanıcılar</div>
          <div class="fs-3 fw-semibold"><?= h((string) ($company['user_count'] ?? 0)) ?></div>
          <div class="small text-muted">Aktif <?= h((string) ($company['active_user_count'] ?? 0)) ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1">Araçlar</div>
          <div class="fs-3 fw-semibold"><?= h((string) ($company['car_count'] ?? 0)) ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1">Kiralamalar</div>
          <div class="fs-3 fw-semibold"><?= h((string) ($company['rental_count'] ?? 0)) ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1">Finans Kaydı</div>
          <div class="fs-3 fw-semibold"><?= h((string) ((int) ($company['expense_count'] ?? 0) + (int) ($company['entry_count'] ?? 0))) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-xl-5">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Firma Profili</span>
          <span class="badge <?= (int) ($company['is_active'] ?? 0) === 1 ? 'bg-success' : 'bg-secondary' ?>"><?= (int) ($company['is_active'] ?? 0) === 1 ? 'Aktif' : 'Pasif' ?></span>
        </div>
        <div class="card-body">
          <form action="actions/platform_company_update.php" method="post" class="row g-3">
            <?= auth_csrf_input() ?>
            <input type="hidden" name="company_id" value="<?= h($companyId) ?>">
            <div class="col-md-6">
              <label class="form-label">Firma Adı</label>
              <input name="company_name" class="form-control" maxlength="150" value="<?= h($company['name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Resmi Unvan</label>
              <input name="legal_name" class="form-control" maxlength="180" value="<?= h($company['legal_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Telefon</label>
              <input name="phone" class="form-control" maxlength="30" value="<?= h($company['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">E-posta</label>
              <input name="email" type="email" class="form-control" maxlength="150" value="<?= h($company['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Vergi Dairesi</label>
              <input name="tax_office" class="form-control" maxlength="120" value="<?= h($company['tax_office'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Vergi Numarası</label>
              <input name="tax_number" class="form-control" maxlength="30" value="<?= h($company['tax_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Mersis Numarası</label>
              <input name="mersis_number" class="form-control" maxlength="30" value="<?= h($company['mersis_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Web Sitesi</label>
              <input name="website" class="form-control" maxlength="180" value="<?= h($company['website'] ?? '') ?>" placeholder="https://">
            </div>
            <div class="col-12">
              <label class="form-label">Adres</label>
              <textarea name="address" class="form-control" rows="3"><?= h($company['address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">İlçe</label>
              <input name="district" class="form-control" maxlength="120" value="<?= h($company['district'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Şehir</label>
              <input name="city" class="form-control" maxlength="120" value="<?= h($company['city'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Ülke</label>
              <input name="country" class="form-control" maxlength="120" value="<?= h($company['country'] ?? 'Türkiye') ?>">
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button class="btn btn-dark" type="submit">Firma Bilgilerini Kaydet</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-7">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Firma Kullanıcıları</span>
          <div class="d-flex gap-2">
            <?php if ($showArchivedUsers): ?>
            <a href="company_detail.php?id=<?= h((string) $companyId) ?>" class="btn btn-sm btn-outline-dark">Normal Listeye Dön</a>
            <?php else: ?>
            <a href="company_detail.php?id=<?= h((string) $companyId) ?>&show_archived_users=1" class="btn btn-sm btn-outline-secondary">Arşivdekileri Gör</a>
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#managedCompanyUserModal" data-mode="create" data-company-id="<?= h($companyId) ?>" data-company-label="<?= h($company['name'] ?? '') ?>">Kullanıcı Ekle</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body table-responsive">
          <table class="table table-bordered table-striped align-middle">
            <tr><th>Ad Soyad</th><th>Kullanıcı Adı</th><th>Rol</th><th>Durum</th><th>Son Giriş</th><th>İşlem</th></tr>
            <?php if (empty($users)): ?>
            <tr><td colspan="6" class="text-center text-muted">Bu firmada henüz kullanıcı yok.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $user): ?>
            <tr>
              <td><?= h($user['full_name'] ?? '') ?></td>
              <td><?= h($user['username'] ?? '') ?></td>
              <td>
                <div><?= h(auth_user_role_label($user)) ?></div>
                <small class="text-muted"><?= h(auth_user_role_description($user)) ?></small>
              </td>
              <td><?= (int) ($user['is_active'] ?? 0) === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>' ?></td>
              <td><?= !empty($user['last_login_at']) ? dt($user['last_login_at']) : '-' ?></td>
              <td class="table-actions-cell">
                <div class="action-group">
                  <?php if (!$showArchivedUsers): ?>
                  <button
                    class="action-btn action-warning"
                    type="button"
                    title="Düzenle"
                    aria-label="Düzenle"
                    data-bs-toggle="modal"
                    data-bs-target="#managedCompanyUserModal"
                    data-mode="edit"
                    data-id="<?= h($user['id']) ?>"
                    data-company-id="<?= h($companyId) ?>"
                    data-company-label="<?= h($company['name'] ?? '') ?>"
                    data-full-name="<?= h($user['full_name'] ?? '') ?>"
                    data-username="<?= h($user['username'] ?? '') ?>"
                    data-role="<?= h(($user['role'] ?? '') === 'custom' ? auth_custom_role_storage_key((int) ($user['custom_role_id'] ?? 0)) : ($user['role'] ?? '')) ?>"
                  >
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
                  </button>
                  <?php endif; ?>
                  <?php if (!$showArchivedUsers && (int) ($user['id'] ?? 0) !== $currentUserId): ?>
                  <form action="actions/platform_user_toggle.php" method="post" class="d-inline">
                    <?= auth_csrf_input() ?>
                    <input type="hidden" name="context" value="detail">
                    <input type="hidden" name="company_id" value="<?= h($companyId) ?>">
                    <input type="hidden" name="id" value="<?= h($user['id'] ?? 0) ?>">
                    <input type="hidden" name="is_active" value="<?= (int) ($user['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                    <button class="action-btn <?= (int) ($user['is_active'] ?? 0) === 1 ? 'action-danger' : 'action-success' ?>" type="submit" title="<?= (int) ($user['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>" aria-label="<?= (int) ($user['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 5v6h4v2h-6V7Z"/></svg>
                    </button>
                  </form>
                  <form action="actions/platform_user_delete.php" method="post" class="d-inline">
                    <?= auth_csrf_input() ?>
                    <input type="hidden" name="context" value="detail">
                    <input type="hidden" name="company_id" value="<?= h($companyId) ?>">
                    <input type="hidden" name="id" value="<?= h($user['id'] ?? 0) ?>">
                    <button class="action-btn action-danger" type="submit" title="Arşivle" aria-label="Arşivle" data-confirm="Bu kullanıcıyı arşive almak istediğinize emin misiniz?">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v4H4V5Zm1 6h14v8H5v-8Zm3 2v2h8v-2H8Z"/></svg>
                    </button>
                  </form>
                  <?php elseif ($showArchivedUsers): ?>
                  <form action="actions/platform_user_restore.php" method="post" class="d-inline">
                    <?= auth_csrf_input() ?>
                    <input type="hidden" name="context" value="detail">
                    <input type="hidden" name="company_id" value="<?= h($companyId) ?>">
                    <input type="hidden" name="id" value="<?= h($user['id'] ?? 0) ?>">
                    <button class="action-btn action-secondary" type="submit" title="Geri Yükle" aria-label="Geri Yükle" data-confirm="Bu kullanıcıyı arşivden geri yüklemek istiyor musunuz?">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.3 10H3l3.5-3.5L10 15H7.8A5 5 0 1 0 12 7h-1V5h1Z"/></svg>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </table>
          <?= pagination_render($usersPagination, ['item_label' => 'kullanıcı']) ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!$showArchivedUsers): ?>
<div class="modal fade" id="managedCompanyUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Firma Kullanıcısı Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/platform_user_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="context" value="detail">
          <input type="hidden" name="company_id" value="<?= h($companyId) ?>">
          <input type="hidden" name="id" value="">
          <div class="mb-3">
            <label class="form-label">Firma</label>
            <input name="company_label" class="form-control" value="<?= h($company['name'] ?? '') ?>" readonly>
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
            <input name="password" type="password" class="form-control" placeholder="Düzenlemede boş bırakılabilir">
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
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
