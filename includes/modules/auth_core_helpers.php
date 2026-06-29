<?php

function app_base_url(): string
{
    $configuredBaseUrl = function_exists('app_base_url_configured')
        ? app_base_url_configured()
        : null;

    if ($configuredBaseUrl !== null) {
        return $configuredBaseUrl;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptName === '') {
        return '';
    }

    $directory = str_replace('\\', '/', dirname($scriptName));
    if (basename($directory) === 'actions') {
        $directory = str_replace('\\', '/', dirname($directory));
    }

    $directory = trim($directory, '/.');
    return $directory === '' ? '' : '/' . $directory;
}

function app_feature_customer_companies_enabled(): bool
{
    return false;
}

function app_feature_notifications_center_enabled(): bool
{
    return false;
}

function auth_is_https(): bool
{
    return function_exists('app_request_is_https')
        ? app_request_is_https()
        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

function auth_apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' data: https://cdn.jsdelivr.net; connect-src 'self'; manifest-src 'self'");

    if (auth_is_guest()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    } else {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    if (auth_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function auth_redirect(string $path): void
{
    $baseUrl = rtrim(app_base_url(), '/');
    $targetPath = ltrim($path, '/');
    header('Location: ' . ($baseUrl === '' ? '' : $baseUrl) . '/' . $targetPath);
    exit;
}

function auth_referer_path(string $fallback = 'index.php'): string
{
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer === '') {
        return $fallback;
    }

    $path = (string) parse_url($referer, PHP_URL_PATH);
    $query = (string) parse_url($referer, PHP_URL_QUERY);

    if ($path === '') {
        return $fallback;
    }

    $relativePath = ltrim(str_replace('\\', '/', $path), '/');
    $basePath = trim((string) parse_url(app_base_url(), PHP_URL_PATH), '/');
    if ($basePath !== '' && str_starts_with($relativePath, $basePath . '/')) {
        $relativePath = substr($relativePath, strlen($basePath) + 1);
    }

    if ($relativePath === '') {
        $relativePath = $fallback;
    }

    return $query !== '' ? $relativePath . '?' . $query : $relativePath;
}

function auth_roles(): array
{
    return [
        'platform_admin' => [
            'label' => 'Platform Yöneticisi',
            'description' => 'Tüm firmaları kurar, müşteri firmaları ve platform yönetimini kontrol eder.',
            'permissions' => ['*', 'platform.manage'],
        ],
        'super_admin' => [
            'label' => 'Firma Sahibi',
            'description' => 'Kendi firmasının tüm ekranlarını görür, kullanıcı yönetir ve tam yetkilidir.',
            'permissions' => [
                'dashboard.view',
                'notifications.view', 'notifications.manage',
                'customers.view', 'customers.manage',
                'cars.view', 'cars.manage',
                'cars.create', 'cars.update', 'cars.archive', 'cars.restore',
                'cars.sale.create', 'cars.sale.update', 'cars.sale.collect', 'cars.sale.collection.update', 'cars.sale.collection.cancel',
                'rentals.view', 'rentals.manage',
                'rentals.create', 'rentals.update', 'rentals.complete', 'rentals.reopen', 'rentals.archive', 'rentals.restore',
                'rentals.extend', 'rentals.extension.update', 'rentals.extension.cancel', 'rentals.extension.delete',
                'rentals.extension.collect', 'rentals.extension.collection.update', 'rentals.extension.collection.cancel',
                'expenses.view', 'expenses.manage',
                'ledger.view', 'ledger.manage',
                'ledger.entry.create', 'ledger.entry.update', 'ledger.entry.delete',
                'company.manage',
                'users.manage',
                'roles.manage',
            ],
        ],
        'manager' => [
            'label' => 'Operasyon',
            'description' => 'Araç ve kiralama operasyonunu yönetir.',
            'permissions' => [
                'dashboard.view',
                'notifications.view', 'notifications.manage',
                'customers.view', 'customers.manage',
                'cars.view', 'cars.manage',
                'cars.create', 'cars.update', 'cars.sale.create', 'cars.sale.update', 'cars.sale.collect', 'cars.sale.collection.update',
                'rentals.view', 'rentals.manage',
                'rentals.create', 'rentals.update', 'rentals.complete', 'rentals.reopen', 'rentals.extend',
                'rentals.extension.update', 'rentals.extension.collect', 'rentals.extension.collection.update',
                'expenses.view',
                'ledger.view',
                'ledger.entry.create', 'ledger.entry.update',
            ],
        ],
        'accountant' => [
            'label' => 'Muhasebe',
            'description' => 'Gider ve hesap ekranlarını yönetir.',
            'permissions' => [
                'dashboard.view',
                'notifications.view', 'notifications.manage',
                'customers.view',
                'rentals.view',
                'expenses.view', 'expenses.manage',
                'ledger.view', 'ledger.manage',
                'ledger.entry.create', 'ledger.entry.update', 'ledger.entry.delete',
            ],
        ],
        'viewer' => [
            'label' => 'Görüntüleyici',
            'description' => 'Sadece görüntüleme yapar, kayıt değiştiremez.',
            'permissions' => [
                'dashboard.view',
                'notifications.view',
                'customers.view',
                'cars.view',
                'rentals.view',
                'expenses.view',
                'ledger.view',
            ],
        ],
    ];
}

function auth_permission_catalog(): array
{
    return [
        'dashboard.view' => 'Anasayfa',
        'cars.create' => 'Arac ekleme',
        'cars.update' => 'Arac duzenleme',
        'cars.archive' => 'Arac arsivleme',
        'cars.restore' => 'Arac arsivden geri yukleme',
        'cars.sale.create' => 'Arac satis kaydi olusturma',
        'cars.sale.update' => 'Arac satis kaydini duzenleme',
        'cars.sale.collect' => 'Arac satis tahsilati ekleme',
        'cars.sale.collection.update' => 'Arac satis tahsilatini duzenleme',
        'cars.sale.collection.cancel' => 'Arac satis tahsilatini geri alma',
        'rentals.create' => 'Kiralama ekleme',
        'rentals.update' => 'Kiralama duzenleme',
        'rentals.complete' => 'Kiralamayi tamamlama / teslim alma',
        'rentals.reopen' => 'Tamamlanan kiralamayi yeniden acma',
        'rentals.archive' => 'Kiralamayi arsivleme',
        'rentals.restore' => 'Kiralamayi arsivden geri yukleme',
        'rentals.extend' => 'Kiralama uzatma kaydi olusturma',
        'rentals.extension.update' => 'Kiralama uzatma kaydini duzenleme',
        'rentals.extension.cancel' => 'Kiralama uzatma kaydini iptal etme',
        'rentals.extension.delete' => 'Iptal edilmis uzatma kaydini tamamen silme',
        'rentals.extension.collect' => 'Kiralama uzatma tahsilati ekleme',
        'rentals.extension.collection.update' => 'Kiralama uzatma tahsilatini duzenleme',
        'rentals.extension.collection.cancel' => 'Kiralama uzatma tahsilatini geri alma',
        'notifications.view' => 'Bildirimleri görüntüleme',
        'notifications.manage' => 'Bildirimleri okundu / çözüldü yönetme',
        'customers.view' => 'Kurumsal müşterileri görüntüleme',
        'customers.manage' => 'Kurumsal müşteri ekleme / düzenleme / pasife alma',
        'cars.view' => 'Araçları görüntüleme',
        'cars.manage' => 'Araç ekleme / düzenleme / silme',
        'rentals.view' => 'Kiralamaları görüntüleme',
        'rentals.manage' => 'Kiralama ekleme / düzenleme / teslim alma',
        'expenses.view' => 'İşletme giderlerini görüntüleme',
        'expenses.manage' => 'İşletme giderlerini yönetme',
        'ledger.view' => 'İşletme hesap ekranını görüntüleme',
        'ledger.manage' => 'İşletme hesap hareketlerini yönetme',
        'ledger.entry.create' => 'Gelir / gider hareketi ekleme',
        'ledger.entry.update' => 'Gelir / gider hareketi düzenleme',
        'ledger.entry.delete' => 'Gelir / gider hareketi silme',
        'company.manage' => 'Firma ayarları ve kurumsal bilgiler',
        'users.manage' => 'Kullanıcı yönetimi',
        'roles.manage' => 'Rol ve yetki yönetimi',
        'platform.manage' => 'Firma kurma, firma listeleme ve platform yönetimi',
    ];
}

function auth_role_options(): array
{
    return auth_roles();
}

function auth_assignable_role_options(?array $actor = null, ?int $targetCompanyId = null): array
{
    $actor = $actor ?? auth_current_user();
    $roles = auth_roles();
    $targetCompanyId = $targetCompanyId ?? (int) ($actor['company_id'] ?? 0);

    if (!auth_can('platform.manage', $actor) || $targetCompanyId !== (int) ($actor['company_id'] ?? 0)) {
        unset($roles['platform_admin']);
    }

    return $roles;
}

function auth_role_label(?string $role): string
{
    $roles = auth_roles();
    return $roles[$role]['label'] ?? ($role ?: 'Tanımsız');
}

function auth_role_description(?string $role): string
{
    $roles = auth_roles();
    return $roles[$role]['description'] ?? '';
}

function auth_permissions_for_role(?string $role): array
{
    $roles = auth_roles();
    return $roles[$role]['permissions'] ?? [];
}

function auth_user_permissions(?array $user = null): array
{
    $user = $user ?? auth_current_user();
    if (!$user) {
        return [];
    }

    if (($user['role'] ?? null) === 'custom') {
        $rawPermissions = $user['custom_permissions_json'] ?? '[]';
        if (is_string($rawPermissions)) {
            $decoded = json_decode($rawPermissions, true);
            if (is_array($decoded)) {
                return array_values(array_unique(array_filter(array_map('strval', $decoded))));
            }

            $csvParts = array_filter(array_map('trim', explode(',', $rawPermissions)), static fn ($value): bool => $value !== '');
            if (!empty($csvParts)) {
                return array_values(array_unique(array_map('strval', $csvParts)));
            }
        }
        if (is_array($rawPermissions)) {
            return array_values(array_unique(array_filter(array_map('strval', $rawPermissions))));
        }
        return [];
    }

    return auth_permissions_for_role($user['role'] ?? null);
}

function auth_custom_role_storage_key(int $roleId): string
{
    return 'custom:' . max(0, $roleId);
}

function auth_is_custom_role_key(?string $roleKey): bool
{
    return is_string($roleKey) && str_starts_with($roleKey, 'custom:');
}

function auth_parse_role_selection(?string $selectedRole): array
{
    $selectedRole = trim((string) $selectedRole);
    if (auth_is_custom_role_key($selectedRole)) {
        return [
            'role' => 'custom',
            'custom_role_id' => max(0, (int) substr($selectedRole, 7)),
        ];
    }

    return [
        'role' => $selectedRole !== '' ? $selectedRole : 'viewer',
        'custom_role_id' => null,
    ];
}

function auth_company_custom_roles(PDO $pdo, int $companyId, bool $activeOnly = true): array
{
    if ($companyId <= 0) {
        return [];
    }

    $sql = "
        SELECT
            cr.*,
            COALESCE(GROUP_CONCAT(DISTINCT crp.permission_key ORDER BY crp.permission_key SEPARATOR ','), '') AS permissions_json
        FROM company_roles cr
        LEFT JOIN company_role_permissions crp ON crp.role_id = cr.id
        WHERE cr.company_id = ?
    ";

    if ($activeOnly) {
        $sql .= ' AND cr.is_active = 1 AND cr.archived_at IS NULL';
    }

    $sql .= ' GROUP BY cr.id ORDER BY cr.name ASC, cr.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);

    $roles = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roleId = (int) ($row['id'] ?? 0);
        if ($roleId <= 0) {
            continue;
        }

        $roles[auth_custom_role_storage_key($roleId)] = [
            'id' => $roleId,
            'label' => (string) ($row['name'] ?? 'Özel Rol'),
            'description' => (string) ($row['description'] ?? ''),
            'permissions' => auth_user_permissions([
                'role' => 'custom',
                'custom_permissions_json' => (string) ($row['permissions_json'] ?? '[]'),
            ]),
            'is_custom' => true,
            'raw_role_key' => (string) ($row['role_key'] ?? ''),
        ];
    }

    return $roles;
}

function auth_assignable_role_options_db(PDO $pdo, ?array $actor = null, ?int $targetCompanyId = null): array
{
    $actor = $actor ?? auth_current_user();
    $roles = auth_roles();
    $targetCompanyId = $targetCompanyId ?? (int) ($actor['company_id'] ?? 0);

    if (!auth_can('platform.manage', $actor) || $targetCompanyId !== (int) ($actor['company_id'] ?? 0)) {
        unset($roles['platform_admin']);
    }

    if ($targetCompanyId > 0) {
        foreach (auth_company_custom_roles($pdo, $targetCompanyId, true) as $roleKey => $roleData) {
            $roles[$roleKey] = $roleData;
        }
    }

    return $roles;
}

function auth_resolve_role_company_id(?int $requestedCompanyId = null, ?array $actor = null): int
{
    $actor = $actor ?? auth_current_user();
    $actorCompanyId = (int) ($actor['company_id'] ?? 0);
    $requestedCompanyId = (int) ($requestedCompanyId ?? 0);

    if ($requestedCompanyId > 0 && auth_can('platform.manage', $actor)) {
        return $requestedCompanyId;
    }

    return $actorCompanyId;
}

function auth_manageable_permission_catalog(?array $actor = null, ?int $targetCompanyId = null): array
{
    $catalog = auth_permission_catalog();
    unset($catalog['platform.manage']);

    return $catalog;
}

function auth_user_role_label(?array $user): string
{
    if (!$user) {
        return 'Tanımsız';
    }

    if (($user['role'] ?? null) === 'custom') {
        return (string) ($user['custom_role_name'] ?? 'Özel Rol');
    }

    return auth_role_label($user['role'] ?? null);
}

function auth_user_role_description(?array $user): string
{
    if (!$user) {
        return '';
    }

    if (($user['role'] ?? null) === 'custom') {
        return (string) ($user['custom_role_description'] ?? '');
    }

    return auth_role_description($user['role'] ?? null);
}

function auth_can(string $permission, ?array $user = null): bool
{
    $permissions = auth_user_permissions($user);
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function auth_can_any(array $permissions, ?array $user = null): bool
{
    foreach ($permissions as $permission) {
        if (auth_can((string) $permission, $user)) {
            return true;
        }
    }

    return false;
}

function auth_require_permission(string $permission): void
{
    if (!auth_can($permission)) {
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
}

function auth_require_any_permission(array $permissions): void
{
    if (!auth_can_any($permissions)) {
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
}

function auth_abort(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    exit($message);
}

function auth_require_post_request(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        auth_abort('Bu istek yöntemi desteklenmiyor.', 405);
    }
}

function auth_rotate_csrf_token(): string
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf_token'];
}

function auth_csrf_token(): string
{
    $token = $_SESSION['_csrf_token'] ?? '';
    if (!is_string($token) || $token === '') {
        $token = auth_rotate_csrf_token();
    }

    return $token;
}

function auth_csrf_input(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function auth_validate_csrf_request(): void
{
    $submittedToken = (string) ($_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($submittedToken === '' || !hash_equals(auth_csrf_token(), $submittedToken)) {
        auth_abort('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 419);
    }
}

function auth_client_ip(): string
{
    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string) ($parts[0] ?? ''));
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
}

function auth_user_agent(): string
{
    return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')), 0, 255);
}

function auth_session_idle_timeout_seconds(): int
{
    return 30 * 60;
}

function auth_session_regeneration_interval_seconds(): int
{
    return 10 * 60;
}

function auth_password_min_length(): int
{
    return 10;
}

function auth_password_policy_description(): string
{
    return 'Şifre en az 10 karakter olmalı; büyük harf, küçük harf, rakam ve özel karakter içermelidir.';
}

function auth_validate_password_policy(string $password): array
{
    $errors = [];

    if (strlen($password) < auth_password_min_length()) {
        $errors[] = 'Şifre en az ' . auth_password_min_length() . ' karakter olmalı.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Şifre en az bir büyük harf içermeli.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Şifre en az bir küçük harf içermeli.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Şifre en az bir rakam içermeli.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Şifre en az bir özel karakter içermeli.';
    }

    return $errors;
}

function auth_mark_session_activity(): void
{
    $_SESSION['auth_last_activity_at'] = time();
}

function auth_session_expired(): bool
{
    $lastActivityAt = (int) ($_SESSION['auth_last_activity_at'] ?? 0);
    if ($lastActivityAt <= 0) {
        return false;
    }

    return (time() - $lastActivityAt) > auth_session_idle_timeout_seconds();
}

function auth_login_rate_limit_max_attempts(): int
{
    return 5;
}

function auth_login_rate_limit_username_max_attempts(): int
{
    return 8;
}

function auth_login_rate_limit_ip_max_attempts(): int
{
    return 12;
}

function auth_login_rate_limit_lock_minutes(): int
{
    return 15;
}

function auth_login_rate_limit_window_minutes(): int
{
    return 15;
}

function auth_normalize_username(string $username): string
{
    return mb_strtolower(trim($username), 'UTF-8');
}

function auth_trimmed_string($value, int $maxLength = 0): string
{
    $value = trim((string) $value);
    if ($maxLength > 0 && mb_strlen($value, 'UTF-8') > $maxLength) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return $value;
}

function auth_nullable_trimmed_string($value, int $maxLength = 0): ?string
{
    $value = auth_trimmed_string($value, $maxLength);
    return $value === '' ? null : $value;
}

function auth_normalize_website(?string $website): ?string
{
    $website = auth_nullable_trimmed_string($website, 180);
    if ($website === null) {
        return null;
    }

    if (!preg_match('~^https?://~i', $website)) {
        $website = 'https://' . $website;
    }

    return $website;
}

function auth_project_root(): string
{
    return dirname(__DIR__, 2);
}

function auth_legacy_project_root(): string
{
    return auth_project_root() . DIRECTORY_SEPARATOR . 'includes';
}

function auth_storage_relative_filesystem_path(string $relativePath, string $storageSubdir): ?string
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return null;
    }

    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $normalizedStorageSubdir = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storageSubdir), DIRECTORY_SEPARATOR);
    $candidateRoots = [
        auth_project_root(),
        auth_legacy_project_root(),
    ];

    foreach ($candidateRoots as $candidateRoot) {
        $fullPath = $candidateRoot . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
        $realPath = realpath($fullPath);
        $allowedRoot = realpath($candidateRoot . DIRECTORY_SEPARATOR . $normalizedStorageSubdir);

        if ($realPath === false || $allowedRoot === false) {
            continue;
        }

        if (strpos($realPath, $allowedRoot . DIRECTORY_SEPARATOR) !== 0 && $realPath !== $allowedRoot) {
            continue;
        }

        if (is_file($realPath)) {
            return $realPath;
        }
    }

    return null;
}

function auth_company_logo_storage_dir(): string
{
    return auth_project_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'company-logos';
}

function auth_company_logo_public_url(?array $company = null): ?string
{
    $company = $company ?? auth_current_user();
    if (empty($company['logo_path']) && empty($company['company_logo_path'])) {
        return null;
    }

    return app_base_url() . '/company_logo.php';
}

function auth_company_logo_filesystem_path(?array $company = null): ?string
{
    $company = $company ?? auth_current_user();
    $relativePath = trim((string) ($company['logo_path'] ?? $company['company_logo_path'] ?? ''));
    return auth_storage_relative_filesystem_path($relativePath, 'storage/company-logos');
}

function auth_user_avatar_storage_dir(): string
{
    return auth_project_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'user-avatars';
}

function auth_user_avatar_public_url(?array $user = null): ?string
{
    $user = $user ?? auth_current_user();
    if (empty($user['avatar_path']) || empty($user['id'])) {
        return null;
    }

    return app_base_url() . '/user_avatar.php?id=' . (int) $user['id'];
}

function auth_user_avatar_filesystem_path(?array $user = null): ?string
{
    $user = $user ?? auth_current_user();
    $relativePath = trim((string) ($user['avatar_path'] ?? ''));
    return auth_storage_relative_filesystem_path($relativePath, 'storage/user-avatars');
}

function auth_avatar_focus_value($value, int $fallback = 50): int
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    $numeric = (int) $value;
    return max(0, min(100, $numeric));
}

function auth_avatar_object_position(?array $user = null): string
{
    $user = $user ?? auth_current_user();
    $focusX = auth_avatar_focus_value($user['avatar_focus_x'] ?? null, 50);
    $focusY = auth_avatar_focus_value($user['avatar_focus_y'] ?? null, 50);
    return $focusX . '% ' . $focusY . '%';
}

function auth_avatar_position_style(?array $user = null): string
{
    return '--avatar-position: ' . auth_avatar_object_position($user) . ';';
}

function auth_store_standard_image_upload(
    array $uploadedFile,
    string $storageDir,
    string $relativeDir,
    string $fileBasePrefix,
    int $maxBytes,
    int $targetWidth,
    int $targetHeight,
    string $fitMode = 'cover'
): array {
    $uploadError = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'status' => 'upload_failed'];
    }

    $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
    $fileSize = (int) ($uploadedFile['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'status' => 'upload_failed'];
    }
    if ($fileSize <= 0 || $fileSize > $maxBytes) {
        return ['ok' => false, 'status' => 'too_large'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMimeMap[$mimeType])) {
        return ['ok' => false, 'status' => 'invalid_type'];
    }

    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        return ['ok' => false, 'status' => 'upload_failed'];
    }

    $binary = @file_get_contents($tmpName);
    $sourceImage = ($binary !== false && function_exists('imagecreatefromstring'))
        ? @imagecreatefromstring($binary)
        : null;

    if (!$sourceImage) {
        $fallbackExtension = $allowedMimeMap[$mimeType] ?? 'jpg';
        $fallbackFileName = $fileBasePrefix . '-' . bin2hex(random_bytes(12)) . '.' . $fallbackExtension;
        $fallbackTargetPath = $storageDir . DIRECTORY_SEPARATOR . $fallbackFileName;

        if (!move_uploaded_file($tmpName, $fallbackTargetPath)) {
            return ['ok' => false, 'status' => 'upload_failed'];
        }

        return [
            'ok' => true,
            'relative_path' => trim($relativeDir, '/\\') . '/' . $fallbackFileName,
            'absolute_path' => $fallbackTargetPath,
        ];
    }

    $sourceWidth = max(1, (int) imagesx($sourceImage));
    $sourceHeight = max(1, (int) imagesy($sourceImage));
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

    if ($canvas === false) {
        imagedestroy($sourceImage);
        return ['ok' => false, 'status' => 'upload_failed'];
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $background = imagecolorallocatealpha($canvas, 255, 255, 255, 0);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $background);

    if ($fitMode === 'contain') {
        $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight, 1);
        $drawWidth = max(1, (int) round($sourceWidth * $scale));
        $drawHeight = max(1, (int) round($sourceHeight * $scale));
        $destX = (int) floor(($targetWidth - $drawWidth) / 2);
        $destY = (int) floor(($targetHeight - $drawHeight) / 2);
        $copied = imagecopyresampled($canvas, $sourceImage, $destX, $destY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
    } else {
        $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $cropWidth = (int) round($targetWidth / $scale);
        $cropHeight = (int) round($targetHeight / $scale);
        $srcX = (int) max(0, floor(($sourceWidth - $cropWidth) / 2));
        $srcY = (int) max(0, floor(($sourceHeight - $cropHeight) / 2));
        $copied = imagecopyresampled($canvas, $sourceImage, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
    }

    if (!$copied) {
        imagedestroy($canvas);
        imagedestroy($sourceImage);
        return ['ok' => false, 'status' => 'upload_failed'];
    }

    $targetFileName = $fileBasePrefix . '-' . bin2hex(random_bytes(12)) . '.webp';
    $targetPath = $storageDir . DIRECTORY_SEPARATOR . $targetFileName;
    $saved = function_exists('imagewebp')
        ? imagewebp($canvas, $targetPath, 84)
        : imagepng($canvas, $targetPath, 6);

    if (!$saved && !function_exists('imagewebp')) {
        $targetFileName = $fileBasePrefix . '-' . bin2hex(random_bytes(12)) . '.png';
        $targetPath = $storageDir . DIRECTORY_SEPARATOR . $targetFileName;
        $saved = imagepng($canvas, $targetPath, 6);
    }

    imagedestroy($canvas);
    imagedestroy($sourceImage);

    if (!$saved || !is_file($targetPath)) {
        return ['ok' => false, 'status' => 'upload_failed'];
    }

    return [
        'ok' => true,
        'relative_path' => trim($relativeDir, '/\\') . '/' . $targetFileName,
        'absolute_path' => $targetPath,
    ];
}

function auth_store_user_avatar_upload(array $avatarFile, int $companyId, int $userId): array
{
    $relativeDir = 'storage/user-avatars/' . $companyId . '/' . $userId;
    $storageDir = auth_user_avatar_storage_dir() . DIRECTORY_SEPARATOR . $companyId . DIRECTORY_SEPARATOR . $userId;

    $result = auth_store_standard_image_upload(
        $avatarFile,
        $storageDir,
        $relativeDir,
        'avatar',
        5 * 1024 * 1024,
        512,
        512,
        'cover'
    );

    if (!$result['ok']) {
        $statusMap = [
            'too_large' => 'avatar_too_large',
            'invalid_type' => 'avatar_invalid',
            'upload_failed' => 'avatar_upload_failed',
        ];
        $result['status'] = $statusMap[$result['status'] ?? 'upload_failed'] ?? 'avatar_upload_failed';
    }

    return $result;
}

function auth_store_company_logo_upload(array $logoFile, int $companyId): array
{
    $uploadError = (int) ($logoFile['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'status' => 'logo_upload_failed'];
    }

    $maxLogoBytes = 2 * 1024 * 1024;
    $tmpName = (string) ($logoFile['tmp_name'] ?? '');
    $fileSize = (int) ($logoFile['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'status' => 'logo_upload_failed'];
    }
    if ($fileSize <= 0 || $fileSize > $maxLogoBytes) {
        return ['ok' => false, 'status' => 'logo_too_large'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMimeMap[$mimeType])) {
        return ['ok' => false, 'status' => 'logo_invalid'];
    }

    $storageDir = auth_company_logo_storage_dir() . DIRECTORY_SEPARATOR . $companyId;
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        return ['ok' => false, 'status' => 'logo_upload_failed'];
    }

    $newFileBase = 'logo-' . bin2hex(random_bytes(16));
    $saveAsWebp = function_exists('imagewebp');
    $targetExtension = $saveAsWebp ? 'webp' : 'png';
    $targetPath = $storageDir . DIRECTORY_SEPARATOR . $newFileBase . '.' . $targetExtension;

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
        return ['ok' => false, 'status' => 'logo_invalid'];
    }

    $sourceImage = null;
    if (function_exists('imagecreatefromstring')) {
        $binary = @file_get_contents($tmpName);
        if ($binary !== false) {
            $sourceImage = @imagecreatefromstring($binary);
        }
    }

    if ($sourceImage === false || $sourceImage === null) {
        $fallbackPath = $storageDir . DIRECTORY_SEPARATOR . $newFileBase . '.' . $allowedMimeMap[$mimeType];
        if (!move_uploaded_file($tmpName, $fallbackPath)) {
            return ['ok' => false, 'status' => 'logo_upload_failed'];
        }

        return [
            'ok' => true,
            'relative_path' => 'storage/company-logos/' . $companyId . '/' . basename($fallbackPath),
            'absolute_path' => $fallbackPath,
        ];
    }

    $sourceWidth = max(1, (int) imagesx($sourceImage));
    $sourceHeight = max(1, (int) imagesy($sourceImage));
    $maxDimension = 1200;
    $scale = min($maxDimension / $sourceWidth, $maxDimension / $sourceHeight, 1);
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));

    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($canvas === false) {
        imagedestroy($sourceImage);
        return ['ok' => false, 'status' => 'logo_upload_failed'];
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

    if (!imagecopyresampled($canvas, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) {
        imagedestroy($canvas);
        imagedestroy($sourceImage);
        return ['ok' => false, 'status' => 'logo_upload_failed'];
    }

    $saved = $saveAsWebp
        ? imagewebp($canvas, $targetPath, 82)
        : imagepng($canvas, $targetPath, 6);

    imagedestroy($canvas);
    imagedestroy($sourceImage);

    if (!$saved || !is_file($targetPath)) {
        return ['ok' => false, 'status' => 'logo_upload_failed'];
    }

    return [
        'ok' => true,
        'relative_path' => 'storage/company-logos/' . $companyId . '/' . basename($targetPath),
        'absolute_path' => $targetPath,
    ];
}

function auth_audit_log(PDO $pdo, string $eventType, string $description, array $context = []): void
{
    try {
        $currentUser = auth_current_user();
        $companyId = isset($context['company_id']) ? (int) $context['company_id'] : (int) ($currentUser['company_id'] ?? 0);
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : (int) ($currentUser['id'] ?? 0);
        $entityType = isset($context['entity_type']) ? trim((string) $context['entity_type']) : null;
        $entityId = isset($context['entity_id']) ? (int) $context['entity_id'] : null;
        $metadata = $context['metadata'] ?? null;

        $metadataJson = null;
        if ($metadata !== null) {
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $st = $pdo->prepare('INSERT INTO audit_logs (company_id, user_id, event_type, entity_type, entity_id, description, ip_address, user_agent, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $companyId > 0 ? $companyId : null,
            $userId > 0 ? $userId : null,
            substr(trim($eventType), 0, 80),
            $entityType !== '' ? substr((string) $entityType, 0, 80) : null,
            $entityId && $entityId > 0 ? $entityId : null,
            substr(trim($description), 0, 255),
            auth_client_ip(),
            auth_user_agent(),
            $metadataJson,
        ]);
    } catch (Throwable $e) {
    }
}
