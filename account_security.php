<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$authUser = auth_current_user();
if (!$authUser) {
    auth_redirect('login.php');
}

ensureAuthSchema($pdo);

$status = trim((string) ($_GET['status'] ?? ''));
$avatarUrl = auth_user_avatar_public_url($authUser);
$avatarPositionStyle = auth_avatar_position_style($authUser);
$avatarFocusX = auth_avatar_focus_value($authUser['avatar_focus_x'] ?? 50, 50);
$avatarFocusY = auth_avatar_focus_value($authUser['avatar_focus_y'] ?? 50, 50);
$displayName = (string) ($authUser['full_name'] ?? $authUser['username'] ?? 'Kullanıcı');
$displayInitial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1);
$displayInitial = function_exists('mb_strtoupper') ? mb_strtoupper($displayInitial, 'UTF-8') : strtoupper($displayInitial);

$pageTitle = 'Profil ve Güvenlik';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page profile-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Profil ve Güvenlik</h2>
    </div>
  </div>

  <?php if ($status === 'profile_saved'): ?>
  <div class="alert alert-success">Profil bilgilerin güncellendi.</div>
  <?php elseif ($status === 'profile_invalid'): ?>
  <div class="alert alert-danger">Profil bilgileri kaydedilemedi. Alanları kontrol et.</div>
  <?php elseif ($status === 'avatar_invalid'): ?>
  <div class="alert alert-danger">Profil fotoğrafı yalnızca JPG, PNG veya WEBP olabilir.</div>
  <?php elseif ($status === 'avatar_too_large'): ?>
  <div class="alert alert-danger">Profil fotoğrafı en fazla 5 MB olabilir.</div>
  <?php elseif ($status === 'avatar_upload_failed'): ?>
  <div class="alert alert-danger">Profil fotoğrafı yüklenirken beklenmeyen bir sorun oluştu.</div>
  <?php elseif ($status === 'changed'): ?>
  <div class="alert alert-success">Şifren başarıyla güncellendi.</div>
  <?php elseif ($status === 'invalid_current'): ?>
  <div class="alert alert-danger">Mevcut şifre doğru değil.</div>
  <?php elseif ($status === 'mismatch'): ?>
  <div class="alert alert-danger">Yeni şifre ile tekrar alanı aynı değil.</div>
  <?php elseif ($status === 'weak_password'): ?>
  <div class="alert alert-danger"><?= h(auth_password_policy_description()) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-xl-7">
      <div class="card shadow-sm h-100">
        <div class="card-header">Profil Bilgileri</div>
        <div class="card-body">
          <form action="actions/account_profile_save.php" method="post" enctype="multipart/form-data" class="row g-4" data-avatar-editor>
            <?= auth_csrf_input() ?>

            <div class="col-12">
              <div class="profile-hero-card">
                <button type="button" class="profile-avatar-trigger" data-bs-toggle="modal" data-bs-target="#avatarPreviewModal" aria-label="Profil fotoğrafını büyüt">
                  <span class="profile-avatar-wrap">
                    <img src="<?= $avatarUrl ? h($avatarUrl) . '?v=' . h(rawurlencode((string) ($authUser['avatar_path'] ?? 'avatar'))) : '' ?>" alt="Profil fotoğrafı" class="profile-avatar-image<?= $avatarUrl ? '' : ' d-none' ?>" data-avatar-card-preview style="<?= h($avatarPositionStyle) ?>">
                    <span class="profile-avatar-placeholder<?= $avatarUrl ? ' d-none' : '' ?>" data-avatar-card-placeholder><?= h($displayInitial !== '' ? $displayInitial : 'U') ?></span>
                  </span>
                  <span class="profile-avatar-trigger-badge">Büyüt</span>
                </button>
                <div class="profile-hero-meta">
                  <h3 class="mb-1"><?= h($displayName) ?></h3>
                  <div class="text-muted mb-2">@<?= h($authUser['username'] ?? '') ?></div>
                  <span class="badge bg-dark"><?= h(function_exists('auth_user_role_label') ? auth_user_role_label($authUser) : ($authUser['role'] ?? '-')) ?></span>
                  <div class="profile-hero-hint">Fotoğrafı büyütmek ve düzenlemek için görsele dokun.</div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Ad Soyad</label>
              <input name="full_name" class="form-control" value="<?= h((string) ($authUser['full_name'] ?? '')) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Kullanıcı Adı</label>
              <input class="form-control" value="<?= h((string) ($authUser['username'] ?? '')) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label">Doğum Tarihi</label>
              <input name="birth_date" type="date" class="form-control" value="<?= h((string) ($authUser['birth_date'] ?? '')) ?>">
            </div>

            <input id="avatarFileInput" name="avatar_file" type="file" class="avatar-file-input-hidden" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <input type="hidden" name="avatar_focus_x" value="<?= h((string) $avatarFocusX) ?>">
            <input type="hidden" name="avatar_focus_y" value="<?= h((string) $avatarFocusY) ?>">

            <?php if (!empty($authUser['avatar_path'])): ?>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remove_avatar" value="1" id="removeAvatarCheck">
                <label class="form-check-label" for="removeAvatarCheck">Mevcut profil fotoğrafını kaldır</label>
              </div>
            </div>
            <?php endif; ?>

            <div class="col-12">
              <label class="form-label">Biyografi</label>
              <textarea name="bio" class="form-control" rows="5" maxlength="1000" placeholder="Kısa bir biyografi, görev tanımı veya not ekleyebilirsin."><?= h((string) ($authUser['bio'] ?? '')) ?></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button class="btn btn-primary" type="submit">Profili Güncelle</button>
            </div>

            <div class="modal fade avatar-preview-modal" id="avatarPreviewModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Profil Fotoğrafı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                  </div>
                  <div class="modal-body">
                    <div class="avatar-photo-editor avatar-photo-editor-modal">
                      <div class="avatar-photo-editor-preview">
                        <div class="avatar-photo-frame avatar-photo-frame-large avatar-photo-editor-frame<?= $avatarUrl ? ' has-image' : '' ?>" data-avatar-drag-surface>
                          <?php if ($avatarUrl): ?>
                          <img src="<?= h($avatarUrl) ?>?v=<?= h(rawurlencode((string) ($authUser['avatar_path'] ?? 'avatar'))) ?>" alt="Profil fotoğrafı önizleme" data-avatar-preview draggable="false" style="<?= h($avatarPositionStyle) ?>">
                          <?php else: ?>
                          <img src="" alt="Profil fotoğrafı önizleme" data-avatar-preview draggable="false" hidden>
                          <?php endif; ?>
                          <div class="avatar-photo-editor-empty" data-avatar-empty<?= $avatarUrl ? ' hidden' : '' ?>>Fotoğraf seçince burada önizleme görünecek.</div>
                          <button type="button" class="avatar-photo-edit-button" data-avatar-open-picker aria-label="Profil fotoğrafını düzenle">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m3 17.3 11-11a3.1 3.1 0 0 1 4.4 4.4l-11 11L3 22l.3-4.7Zm11-9.6-8.7 8.7-.1 1.5 1.5-.1 8.7-8.7-1.4-1.4Zm2.8-.1a1.1 1.1 0 0 0-1.6 0l-.4.4 1.4 1.4.4-.4a1.1 1.1 0 0 0 0-1.4l.2-.2Z"/></svg>
                          </button>
                        </div>
                      </div>
                      <div class="avatar-photo-editor-controls d-none" data-avatar-controls>
                        <div>
                          <label class="form-label d-flex justify-content-between" for="avatarFocusX"><span>Sağa / Sola Kaydır</span><strong data-avatar-focus-x-label><?= h((string) $avatarFocusX) ?>%</strong></label>
                          <input id="avatarFocusX" type="range" min="0" max="100" step="1" value="<?= h((string) $avatarFocusX) ?>" class="form-range" data-avatar-focus-x>
                        </div>
                        <div>
                          <label class="form-label d-flex justify-content-between" for="avatarFocusY"><span>Yukarı / Aşağı Kaydır</span><strong data-avatar-focus-y-label><?= h((string) $avatarFocusY) ?>%</strong></label>
                          <input id="avatarFocusY" type="range" min="0" max="100" step="1" value="<?= h((string) $avatarFocusY) ?>" class="form-range" data-avatar-focus-y>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-avatar-reset>Kadrajı Sıfırla</button>
                        <div class="form-text">Kalemle yeni fotoğraf seçtikten sonra kadraj ayarı burada açılır.</div>
                      </div>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tamam</button>
                  </div>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-5">
      <div class="card shadow-sm">
        <div class="card-header">Şifre Değiştir</div>
        <div class="card-body">
          <form action="actions/account_change_password.php" method="post" class="row g-3">
            <?= auth_csrf_input() ?>
            <div class="col-12">
              <label class="form-label">Mevcut Şifre</label>
              <input name="current_password" type="password" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Yeni Şifre</label>
              <input name="new_password" type="password" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Yeni Şifre Tekrar</label>
              <input name="new_password_confirm" type="password" class="form-control" required>
            </div>
            <div class="col-12">
              <div class="form-text"><?= h(auth_password_policy_description()) ?></div>
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button class="btn btn-dark" type="submit">Şifreyi Güncelle</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
