<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('users.manage');

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$entryStatus = $_GET['status'] ?? '';
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
$roleOptions = auth_assignable_role_options_db($pdo, auth_current_user(), $companyId);
$permissionCatalog = auth_permission_catalog();

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
$usersSql .= $showArchived ? ' AND u.archived_at IS NOT NULL' : ' AND u.archived_at IS NULL';
$usersSql .= ' ORDER BY u.created_at ASC, u.id ASC';
$usersSt = $pdo->prepare($usersSql);
$usersSt->execute([$companyId]);
$users = $usersSt->fetchAll();
$usersPagination = paginate_collection($users, 'users_page', 'users_per_page', 10, [10, 20, 50, 100]);
$users = $usersPagination['items'];

$pageTitle = 'Kullanıcı Yönetimi';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Kullanıcı Yönetimi</h2>
    </div>
    <?php if (!$showArchived): ?>
    <div class="d-flex gap-2 flex-wrap">
      <?php if (auth_can('roles.manage')): ?>
      <a href="roles.php" class="btn btn-outline-dark">Roller ve Yetkiler</a>
      <?php endif; ?>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#userModal" data-mode="create">Kullanıcı Ekle</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($entryStatus === 'saved'): ?>
  <div class="alert alert-success">Kullanıcı kaydedildi.</div>
  <?php elseif ($entryStatus === 'invalid'): ?>
  <div class="alert alert-danger">Kullanıcı kaydedilemedi. Alanları kontrol et.</div>
  <?php elseif ($entryStatus === 'username_exists'): ?>
  <div class="alert alert-danger">Bu kullanıcı adı zaten kullanılıyor.</div>
  <?php elseif ($entryStatus === 'self_role_locked'): ?>
  <div class="alert alert-danger">Kendi rolünü bu ekrandan değiştiremezsin.</div>
  <?php elseif ($entryStatus === 'deleted'): ?>
  <div class="alert alert-success">Kullanıcı arşive alındı.</div>
  <?php elseif ($entryStatus === 'restored'): ?>
  <div class="alert alert-success">Kullanıcı arşivden geri yüklendi.</div>
  <?php elseif ($entryStatus === 'self_delete_blocked'): ?>
  <div class="alert alert-danger">Kendi hesabını silemezsin.</div>
  <?php elseif ($entryStatus === 'last_admin_blocked'): ?>
  <div class="alert alert-danger">Firmadaki son yönetici kullanıcıyı silemezsin.</div>
  <?php elseif ($entryStatus === 'weak_password'): ?>
  <div class="alert alert-danger"><?= h(auth_password_policy_description()) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Rol Rehberi</span>
      <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#userRoleGuide" aria-expanded="false" aria-controls="userRoleGuide">Aç / Kapat</button>
    </div>
    <div class="collapse" id="userRoleGuide">
      <div class="card-body">
        <div class="row g-3">
          <?php foreach ($roleOptions as $roleKey => $role): ?>
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?= h($role['label']) ?></strong>
                <span class="badge bg-dark"><?= h($roleKey) ?></span>
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
  </div>

  <div class="card shadow-sm">
    <div class="card-header"><?= $showArchived ? 'Arşivlenmiş Kullanıcılar' : 'Firma Kullanıcıları' ?></div>
    <div class="card-body border-bottom bg-light-subtle rentals-switchbar">
      <?php if ($showArchived): ?>
      <a href="users.php" class="btn btn-outline-dark btn-sm">Normal Listeye Dön</a>
      <?php else: ?>
      <a href="users.php?show_archived=1" class="btn btn-outline-secondary btn-sm">Arşivdekileri Gör</a>
      <?php endif; ?>
    </div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped align-middle">
        <tr><th>Ad Soyad</th><th>Kullanıcı Adı</th><th>Rol</th><th>Durum</th><th>Son Giriş</th><th>İşlem</th></tr>
        <?php if (empty($users)): ?>
        <tr><td colspan="6" class="text-center text-muted">Henüz kullanıcı yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $user): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($user['avatar_path']) && function_exists('auth_user_avatar_public_url')): ?>
              <img src="<?= h(auth_user_avatar_public_url($user)) ?>?v=<?= h(rawurlencode((string) ($user['avatar_path'] ?? 'avatar'))) ?>" alt="<?= h($user['full_name']) ?>" style="width:40px;height:40px;border-radius:12px;object-fit:cover;object-position:<?= h(auth_avatar_object_position($user)) ?>;display:block;">
              <?php else: ?>
              <div style="width:40px;height:40px;border-radius:12px;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">
                <?= h(function_exists('mb_substr') ? mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1), 'UTF-8') : strtoupper(substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?>
              </div>
              <?php endif; ?>
              <div><?= h($user['full_name']) ?></div>
            </div>
          </td>
          <td><?= h($user['username']) ?></td>
          <td>
            <div><?= h(auth_user_role_label($user)) ?></div>
            <small class="text-muted"><?= h(auth_user_role_description($user)) ?></small>
          </td>
          <td><?= (int) ($user['is_active'] ?? 0) === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>' ?></td>
          <td><?= !empty($user['last_login_at']) ? dt($user['last_login_at']) : '-' ?></td>
          <td class="table-actions-cell">
            <div class="action-group">
              <?php if (!$showArchived): ?>
              <button class="action-btn action-warning" type="button" title="Düzenle" aria-label="Düzenle" data-bs-toggle="modal" data-bs-target="#userModal" data-mode="edit" data-id="<?= h($user['id']) ?>" data-full_name="<?= h($user['full_name']) ?>" data-username="<?= h($user['username']) ?>" data-role="<?= h(($user['role'] ?? '') === 'custom' ? auth_custom_role_storage_key((int) ($user['custom_role_id'] ?? 0)) : ($user['role'] ?? '')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </button>
              <?php endif; ?>
              <?php if (!$showArchived && (int) $user['id'] !== $currentUserId): ?>
              <form action="actions/user_toggle.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($user['id']) ?>">
                <input type="hidden" name="is_active" value="<?= (int) ($user['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                <button class="action-btn <?= (int) ($user['is_active'] ?? 0) === 1 ? 'action-danger' : 'action-success' ?>" type="submit" title="<?= (int) ($user['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>" aria-label="<?= (int) ($user['is_active'] ?? 0) === 1 ? 'Pasife Al' : 'Aktif Et' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 5v6h4v2h-6V7Z"/></svg>
                </button>
              </form>
              <form action="actions/user_delete.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($user['id']) ?>">
                <button class="action-btn action-danger" type="submit" title="Arşivle" aria-label="Arşivle" data-confirm="Bu kullanıcıyı arşive almak istediğinize emin misiniz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v4H4V5Zm1 6h14v8H5v-8Zm3 2v2h8v-2H8Z"/></svg>
                </button>
              </form>
              <?php elseif ($showArchived): ?>
              <form action="actions/user_restore.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($user['id']) ?>">
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

<?php if (!$showArchived): ?>
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="userModalLabel">Kullanıcı Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="actions/user_save.php" method="post">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="id" value="">
          <div class="mb-3"><label class="form-label">Ad Soyad</label><input name="full_name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Kullanıcı Adı</label><input name="username" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Rol</label><select name="role" class="form-select" required><?php foreach ($roleOptions as $key => $role): ?><option value="<?= h($key) ?>"><?= h($role['label']) ?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><label class="form-label">Şifre</label><input name="password" type="password" class="form-control" placeholder="Düzenlemede boş bırakılabilir"></div>
          <div class="form-text"><?= h(auth_password_policy_description()) ?></div>
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

