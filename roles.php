<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('roles.manage');

$requestedCompanyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
$companyId = auth_resolve_role_company_id($requestedCompanyId, auth_current_user());
$entryStatus = $_GET['status'] ?? '';

if ($companyId <= 0) {
    auth_redirect('index.php');
}

$companySt = $pdo->prepare('SELECT id, name, legal_name, is_active FROM companies WHERE id = ? LIMIT 1');
$companySt->execute([$companyId]);
$company = $companySt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    auth_redirect('index.php');
}

$permissionCatalog = auth_manageable_permission_catalog(auth_current_user(), $companyId);
$builtinRoles = auth_assignable_role_options(auth_current_user(), $companyId);

$customRoleSt = $pdo->prepare("
    SELECT
        cr.*,
        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.company_id = cr.company_id
              AND u.role = 'custom'
              AND u.custom_role_id = cr.id
              AND u.archived_at IS NULL
        ) AS assigned_user_count,
        COALESCE((
            SELECT GROUP_CONCAT(DISTINCT crp.permission_key ORDER BY crp.permission_key SEPARATOR ',')
            FROM company_role_permissions crp
            WHERE crp.role_id = cr.id
        ), '') AS permissions_json
    FROM company_roles cr
    WHERE cr.company_id = ?
    ORDER BY cr.is_active DESC, cr.name ASC, cr.id ASC
");
$customRoleSt->execute([$companyId]);
$customRoles = [];
foreach ($customRoleSt->fetchAll(PDO::FETCH_ASSOC) as $roleRow) {
    $roleRow['permissions'] = auth_user_permissions([
        'role' => 'custom',
        'custom_permissions_json' => (string) ($roleRow['permissions_json'] ?? '[]'),
    ]);
    $customRoles[] = $roleRow;
}

$pageTitle = 'Roller ve Yetkiler';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h($company['name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Roller ve Yetkiler</h2>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if (auth_can('platform.manage')): ?>
      <a href="company_detail.php?id=<?= h((string) $companyId) ?>" class="btn btn-outline-dark">Firma Detayı</a>
      <?php endif; ?>
      <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#roleModal" data-mode="create" data-company_id="<?= h((string) $companyId) ?>">Rol Ekle</button>
    </div>
  </div>

  <?php if ($entryStatus === 'saved'): ?>
  <div class="alert alert-success">Rol kaydedildi.</div>
  <?php elseif ($entryStatus === 'role_exists'): ?>
  <div class="alert alert-danger">Bu firma için aynı isimde rol zaten var.</div>
  <?php elseif ($entryStatus === 'no_permissions'): ?>
  <div class="alert alert-danger">Rol için en az bir yetki seçmelisiniz.</div>
  <?php elseif ($entryStatus === 'status_changed'): ?>
  <div class="alert alert-success">Rol durumu güncellendi.</div>
  <?php elseif ($entryStatus === 'deleted'): ?>
  <div class="alert alert-success">Rol arşive alındı.</div>
  <?php elseif ($entryStatus === 'role_in_use'): ?>
  <div class="alert alert-danger">Bu rol aktif kullanıcılar üzerinde kullanıldığı için değiştirilemedi.</div>
  <?php elseif ($entryStatus === 'invalid'): ?>
  <div class="alert alert-danger">Bilgileri kontrol edip tekrar deneyin.</div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Sabit Roller</span>
      <span class="badge bg-dark"><?= h((string) count($builtinRoles)) ?> rol</span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <?php foreach ($builtinRoles as $roleKey => $role): ?>
        <div class="col-12 col-xl-6">
          <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <strong><?= h($role['label']) ?></strong>
              <span class="badge bg-secondary"><?= h($roleKey) ?></span>
            </div>
            <div class="card-body">
              <p class="text-muted mb-3"><?= h($role['description']) ?></p>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($role['permissions'] as $permission): ?>
                  <?php if ($permission === '*'): ?>
                  <span class="badge bg-success">Tüm Yetkiler</span>
                  <?php else: ?>
                  <span class="badge bg-light text-dark border"><?= h($permissionCatalog[$permission] ?? $permission) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Özel Roller</span>
      <span class="badge bg-dark"><?= h((string) count($customRoles)) ?> rol</span>
    </div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped align-middle">
        <tr>
          <th>Rol</th>
          <th>Yetkiler</th>
          <th>Kullanım</th>
          <th>Durum</th>
          <th>İşlem</th>
        </tr>
        <?php if (empty($customRoles)): ?>
        <tr><td colspan="5" class="text-center text-muted">Bu firma için henüz özel rol tanımlanmadı.</td></tr>
        <?php endif; ?>
        <?php foreach ($customRoles as $role): ?>
        <?php $permissionsCsv = implode(',', $role['permissions']); ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($role['name'] ?? '') ?></div>
            <small class="text-muted"><?= h($role['description'] ?? '') ?></small>
          </td>
          <td>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($role['permissions'] as $permission): ?>
              <span class="badge bg-light text-dark border"><?= h($permissionCatalog[$permission] ?? $permission) ?></span>
              <?php endforeach; ?>
            </div>
          </td>
          <td><?= h((string) ((int) ($role['assigned_user_count'] ?? 0))) ?> kullanıcı</td>
          <td><?= (int) ($role['is_active'] ?? 0) === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>' ?></td>
          <td class="table-actions-cell">
            <div class="action-group">
              <button
                class="action-btn action-warning"
                type="button"
                title="Düzenle"
                aria-label="Düzenle"
                data-bs-toggle="modal"
                data-bs-target="#roleModal"
                data-mode="edit"
                data-company_id="<?= h((string) $companyId) ?>"
                data-id="<?= h($role['id']) ?>"
                data-name="<?= h($role['name'] ?? '') ?>"
                data-description="<?= h($role['description'] ?? '') ?>"
                data-permissions="<?= h($permissionsCsv) ?>"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </button>
              <form action="actions/role_toggle.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="company_id" value="<?= h((string) $companyId) ?>">
                <input type="hidden" name="id" value="<?= h($role['id']) ?>">
                <input type="hidden" name="is_active" value="<?= (int) ($role['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                <button class="action-btn <?= (int) ($role['is_active'] ?? 0) === 1 ? 'action-danger' : 'action-success' ?>" type="submit" title="<?= (int) ($role['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>" aria-label="<?= (int) ($role['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>" data-confirm="<?= (int) ($role['is_active'] ?? 0) === 1 ? 'Bu rolü pasife almak istiyor musunuz?' : 'Bu rolü tekrar aktif etmek istiyor musunuz?' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 5v10h-2V7h2Z"/></svg>
                </button>
              </form>
              <form action="actions/role_delete.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="company_id" value="<?= h((string) $companyId) ?>">
                <input type="hidden" name="id" value="<?= h($role['id']) ?>">
                <button class="action-btn action-danger" type="submit" title="Arşivle" aria-label="Arşivle" data-confirm="Bu rolü arşive almak istiyor musunuz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 11h12l1-13H5l1 13Z"/></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Rol Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/role_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="company_id" value="<?= h((string) $companyId) ?>">
          <input type="hidden" name="id" value="">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Rol Adı</label>
              <input name="name" class="form-control" maxlength="120" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Açıklama</label>
              <input name="description" class="form-control" maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label d-block mb-2">Yetkiler</label>
              <div class="row g-2">
                <?php foreach ($permissionCatalog as $permissionKey => $permissionLabel): ?>
                <div class="col-12 col-md-6">
                  <label class="form-check border rounded px-3 py-2 w-100">
                    <input class="form-check-input me-2" type="checkbox" name="permissions[]" value="<?= h($permissionKey) ?>">
                    <span class="form-check-label">
                      <strong><?= h($permissionLabel) ?></strong>
                      <small class="d-block text-muted"><?= h($permissionKey) ?></small>
                    </span>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
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
