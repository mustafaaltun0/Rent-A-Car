<?php
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('rentecar_session');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $isHttps, true);
    }

    $sessionPath = trim((string) session_save_path());
    if ($sessionPath !== '') {
        $pathParts = preg_split('/[;,]/', $sessionPath) ?: [];
        $resolvedPath = trim((string) end($pathParts));
        if ($resolvedPath !== '' && (!is_dir($resolvedPath) || !is_writable($resolvedPath))) {
            session_save_path(sys_get_temp_dir());
        }
    }

    session_start();
}

function app_base_url(): string
{
    return '/rentecarWeb';
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
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
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
    header('Location: ' . app_base_url() . '/' . ltrim($path, '/'));
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
            'label' => 'Platform Yoneticisi',
            'description' => 'Tum firmalari kurar, musteri firmalari ve platform yonetimini kontrol eder.',
            'permissions' => ['*', 'platform.manage'],
        ],
        'super_admin' => [
            'label' => 'Firma Sahibi',
            'description' => 'Kendi firmasinin tum ekranlarini gorur, kullanici yonetir ve tam yetkilidir.',
            'permissions' => [
                'dashboard.view',
                'notifications.view', 'notifications.manage',
                'customers.view', 'customers.manage',
                'cars.view', 'cars.manage',
                'rentals.view', 'rentals.manage',
                'expenses.view', 'expenses.manage',
                'ledger.view', 'ledger.manage',
                'company.manage',
                'users.manage',
            ],
        ],
        'manager' => [
            'label' => 'Operasyon',
            'description' => 'Arac ve kiralama operasyonunu yonetir.',
            'permissions' => [
                'dashboard.view',
                'notifications.view', 'notifications.manage',
                'customers.view', 'customers.manage',
                'cars.view', 'cars.manage',
                'rentals.view', 'rentals.manage',
                'expenses.view',
                'ledger.view',
            ],
        ],
        'accountant' => [
            'label' => 'Muhasebe',
            'description' => 'Gider ve hesap ekranlarini yonetir.',
            'permissions' => [
                'dashboard.view',
                'notifications.view', 'notifications.manage',
                'customers.view',
                'rentals.view',
                'expenses.view', 'expenses.manage',
                'ledger.view', 'ledger.manage',
            ],
        ],
        'viewer' => [
            'label' => 'Goruntuleyici',
            'description' => 'Sadece goruntuleme yapar, kayit degistiremez.',
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
        'notifications.view' => 'Bildirimleri goruntuleme',
        'notifications.manage' => 'Bildirimleri okundu / cozuldu yonetme',
        'customers.view' => 'Kurumsal musterileri goruntuleme',
        'customers.manage' => 'Kurumsal musteri ekleme / duzenleme / pasife alma',
        'cars.view' => 'Araclari goruntuleme',
        'cars.manage' => 'Arac ekleme / duzenleme / silme',
        'rentals.view' => 'Kiralamalari goruntuleme',
        'rentals.manage' => 'Kiralama ekleme / duzenleme / teslim alma',
        'expenses.view' => 'Isletme giderlerini goruntuleme',
        'expenses.manage' => 'Isletme giderlerini yonetme',
        'ledger.view' => 'Isletme hesap ekranini goruntuleme',
        'ledger.manage' => 'Isletme hesap hareketlerini yonetme',
        'company.manage' => 'Firma ayarlari ve kurumsal bilgiler',
        'users.manage' => 'Kullanici yonetimi',
        'platform.manage' => 'Firma kurma, firma listeleme ve platform yonetimi',
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
    return $roles[$role]['label'] ?? ($role ?: 'Tanimsiz');
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
    return auth_permissions_for_role($user['role'] ?? null);
}

function auth_can(string $permission, ?array $user = null): bool
{
    $permissions = auth_user_permissions($user);
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function auth_require_permission(string $permission): void
{
    if (!auth_can($permission)) {
        http_response_code(403);
        exit('Bu islem icin yetkiniz yok.');
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
        auth_abort('Bu istek yontemi desteklenmiyor.', 405);
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
        auth_abort('Guvenlik dogrulamasi basarisiz. Sayfayi yenileyip tekrar deneyin.', 419);
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

function auth_password_min_length(): int
{
    return 10;
}

function auth_password_policy_description(): string
{
    return 'Sifre en az 10 karakter olmali; buyuk harf, kucuk harf, rakam ve ozel karakter icermelidir.';
}

function auth_validate_password_policy(string $password): array
{
    $errors = [];

    if (strlen($password) < auth_password_min_length()) {
        $errors[] = 'Sifre en az ' . auth_password_min_length() . ' karakter olmali.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Sifre en az bir buyuk harf icermeli.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Sifre en az bir kucuk harf icermeli.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Sifre en az bir rakam icermeli.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Sifre en az bir ozel karakter icermeli.';
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
    return dirname(__DIR__);
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
    if ($relativePath === '') {
        return null;
    }

    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $fullPath = auth_project_root() . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
    $realPath = realpath($fullPath);
    $allowedRoot = realpath(auth_company_logo_storage_dir());

    if ($realPath === false || $allowedRoot === false) {
        return null;
    }

    if (strpos($realPath, $allowedRoot . DIRECTORY_SEPARATOR) !== 0 && $realPath !== $allowedRoot) {
        return null;
    }

    return is_file($realPath) ? $realPath : null;
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

function auth_login_rate_limit_status(PDO $pdo, string $username): array
{
    $normalizedUsername = auth_normalize_username($username);
    $ipAddress = auth_client_ip();

    if ($normalizedUsername === '') {
        return ['blocked' => false, 'retry_after' => 0];
    }

    $st = $pdo->prepare('SELECT failed_attempts, lock_until, last_attempt_at FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ? LIMIT 1');
    $st->execute([$normalizedUsername, $ipAddress]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['blocked' => false, 'retry_after' => 0];
    }

    if (!empty($row['lock_until'])) {
        $lockUntilTs = strtotime((string) $row['lock_until']);
        if ($lockUntilTs !== false && $lockUntilTs > time()) {
            return ['blocked' => true, 'retry_after' => max(1, $lockUntilTs - time())];
        }
    }

    $lastAttemptTs = !empty($row['last_attempt_at']) ? strtotime((string) $row['last_attempt_at']) : false;
    if ($lastAttemptTs !== false) {
        $windowSeconds = auth_login_rate_limit_window_minutes() * 60;
        if ((time() - $lastAttemptTs) > $windowSeconds) {
            $reset = $pdo->prepare('DELETE FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ?');
            $reset->execute([$normalizedUsername, $ipAddress]);
        }
    }

    return ['blocked' => false, 'retry_after' => 0];
}

function auth_record_failed_login(PDO $pdo, string $username): array
{
    $normalizedUsername = auth_normalize_username($username);
    $ipAddress = auth_client_ip();
    $lockMinutes = auth_login_rate_limit_lock_minutes();
    $maxAttempts = auth_login_rate_limit_max_attempts();
    $windowSeconds = auth_login_rate_limit_window_minutes() * 60;

    if ($normalizedUsername === '') {
        return ['blocked' => false, 'retry_after' => 0, 'failed_attempts' => 0];
    }

    $st = $pdo->prepare('SELECT id, failed_attempts, last_attempt_at FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ? LIMIT 1');
    $st->execute([$normalizedUsername, $ipAddress]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $failedAttempts = 1;
    $lockUntil = null;
    $now = date('Y-m-d H:i:s');

    if ($row) {
        $lastAttemptTs = !empty($row['last_attempt_at']) ? strtotime((string) $row['last_attempt_at']) : false;
        if ($lastAttemptTs !== false && (time() - $lastAttemptTs) <= $windowSeconds) {
            $failedAttempts = ((int) $row['failed_attempts']) + 1;
        }

        if ($failedAttempts >= $maxAttempts) {
            $lockUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
        }

        $update = $pdo->prepare('UPDATE auth_login_throttles SET failed_attempts = ?, lock_until = ?, last_attempt_at = ? WHERE id = ?');
        $update->execute([$failedAttempts, $lockUntil, $now, (int) $row['id']]);
    } else {
        if ($failedAttempts >= $maxAttempts) {
            $lockUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
        }

        $insert = $pdo->prepare('INSERT INTO auth_login_throttles (username_normalized, ip_address, failed_attempts, lock_until, last_attempt_at) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$normalizedUsername, $ipAddress, $failedAttempts, $lockUntil, $now]);
    }

    $retryAfter = 0;
    if ($lockUntil !== null) {
        $retryAfter = max(1, strtotime($lockUntil) - time());
    }

    return [
        'blocked' => $lockUntil !== null,
        'retry_after' => $retryAfter,
        'failed_attempts' => $failedAttempts,
    ];
}

function auth_clear_failed_logins(PDO $pdo, string $username): void
{
    $normalizedUsername = auth_normalize_username($username);
    if ($normalizedUsername === '') {
        return;
    }

    $st = $pdo->prepare('DELETE FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ?');
    $st->execute([$normalizedUsername, auth_client_ip()]);
}

function auth_has_migration(PDO $pdo, string $migrationKey): bool
{
    $st = $pdo->prepare('SELECT migration_key FROM app_migrations WHERE migration_key = ? LIMIT 1');
    $st->execute([$migrationKey]);
    return (bool) $st->fetchColumn();
}

function auth_mark_migration(PDO $pdo, string $migrationKey): void
{
    $st = $pdo->prepare('INSERT IGNORE INTO app_migrations (migration_key) VALUES (?)');
    $st->execute([$migrationKey]);
}

function auth_resolve_company_slug(PDO $pdo, string $companyName, ?int $excludeCompanyId = null): string
{
    $baseSlug = auth_slugify($companyName);
    $slug = $baseSlug;
    $suffix = 2;

    while (true) {
        $sql = 'SELECT id FROM companies WHERE slug = ?';
        $params = [$slug];
        if ($excludeCompanyId !== null && $excludeCompanyId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeCompanyId;
        }

        $st = $pdo->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if (!$st->fetchColumn()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }
}

function auth_find_or_create_company(PDO $pdo, string $companyName): int
{
    $normalizedName = mb_strtolower(trim($companyName), 'UTF-8');
    $findByName = $pdo->prepare('SELECT id FROM companies WHERE LOWER(name) = ? LIMIT 1');
    $findByName->execute([$normalizedName]);
    $companyId = (int) $findByName->fetchColumn();
    if ($companyId > 0) {
        return $companyId;
    }

    $slug = auth_resolve_company_slug($pdo, $companyName);
    $insert = $pdo->prepare('INSERT INTO companies (name, legal_name, slug, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())');
    $insert->execute([$companyName, $companyName, $slug]);
    return (int) $pdo->lastInsertId();
}

function auth_find_company_id_by_name(PDO $pdo, string $companyName): int
{
    $normalizedName = mb_strtolower(trim($companyName), 'UTF-8');
    $findByName = $pdo->prepare('SELECT id FROM companies WHERE LOWER(name) = ? ORDER BY is_active DESC, id ASC LIMIT 1');
    $findByName->execute([$normalizedName]);
    return (int) $findByName->fetchColumn();
}

function auth_generate_cloned_username(PDO $pdo, string $username, string $suffix, int $companyId): string
{
    $base = preg_replace('/[^a-zA-Z0-9_.-]+/', '', trim($username)) ?: 'kullanici';
    $base = substr($base, 0, 60);
    $candidate = $base . $suffix;

    $st = $pdo->prepare('SELECT company_id FROM users WHERE username = ? LIMIT 1');
    $st->execute([$candidate]);
    $existingCompanyId = (int) $st->fetchColumn();
    if ($existingCompanyId === $companyId) {
        return $candidate;
    }

    if ($existingCompanyId === 0) {
        return $candidate;
    }

    $counter = 2;
    while (true) {
        $candidate = $base . $suffix . $counter;
        $st->execute([$candidate]);
        $existingCompanyId = (int) $st->fetchColumn();
        if ($existingCompanyId === 0 || $existingCompanyId === $companyId) {
            return $candidate;
        }
        $counter++;
    }
}

function auth_deactivate_unused_duplicate_companies(PDO $pdo, int $primaryCompanyId, string $companyName): void
{
    $duplicates = $pdo->prepare('SELECT id FROM companies WHERE id <> ? AND LOWER(name) = ?');
    $duplicates->execute([$primaryCompanyId, mb_strtolower(trim($companyName), 'UTF-8')]);
    $duplicateIds = array_map('intval', $duplicates->fetchAll(PDO::FETCH_COLUMN));

    foreach ($duplicateIds as $duplicateId) {
        $userSt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE company_id = ?');
        $userSt->execute([$duplicateId]);
        if ((int) $userSt->fetchColumn() > 0) {
            continue;
        }

        $tables = ['cars', 'rentals', 'business_expenses', 'ledger_partners', 'ledger_periods', 'ledger_entries'];
        $hasData = false;
        foreach ($tables as $table) {
            $countSt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE company_id = ?");
            $countSt->execute([$duplicateId]);
            if ((int) $countSt->fetchColumn() > 0) {
                $hasData = true;
                break;
            }
        }

        if ($hasData) {
            continue;
        }

        $deactivate = $pdo->prepare('UPDATE companies SET is_active = 0, updated_at = NOW() WHERE id = ?');
        $deactivate->execute([$duplicateId]);
    }
}

function auth_table_exists(PDO $pdo, string $tableName): bool
{
    $quotedTableName = $pdo->quote($tableName);
    return (bool) $pdo->query("SHOW TABLES LIKE {$quotedTableName}")->fetchColumn();
}

function auth_copy_partner_to_company(PDO $pdo, int $sourcePartnerId, int $targetCompanyId): ?int
{
    if ($sourcePartnerId <= 0 || !auth_table_exists($pdo, 'ledger_partners')) {
        return null;
    }

    $sourceSt = $pdo->prepare('SELECT * FROM ledger_partners WHERE id = ? LIMIT 1');
    $sourceSt->execute([$sourcePartnerId]);
    $sourcePartner = $sourceSt->fetch(PDO::FETCH_ASSOC);
    if (!$sourcePartner) {
        return null;
    }

    $targetSt = $pdo->prepare('SELECT id FROM ledger_partners WHERE company_id = ? AND name = ? LIMIT 1');
    $targetSt->execute([$targetCompanyId, $sourcePartner['name']]);
    $targetPartnerId = (int) $targetSt->fetchColumn();
    if ($targetPartnerId > 0) {
        return $targetPartnerId;
    }

    $insertSt = $pdo->prepare('INSERT INTO ledger_partners (company_id, name, is_settlement_partner, sort_order, created_at) VALUES (?, ?, ?, ?, ?)');
    $insertSt->execute([
        $targetCompanyId,
        $sourcePartner['name'],
        (int) ($sourcePartner['is_settlement_partner'] ?? 1),
        (int) ($sourcePartner['sort_order'] ?? 0),
        $sourcePartner['created_at'] ?? date('Y-m-d H:i:s'),
    ]);

    return (int) $pdo->lastInsertId();
}

function auth_copy_period_to_company(PDO $pdo, int $sourcePeriodId, int $targetCompanyId): ?int
{
    if ($sourcePeriodId <= 0 || !auth_table_exists($pdo, 'ledger_periods')) {
        return null;
    }

    $sourceSt = $pdo->prepare('SELECT * FROM ledger_periods WHERE id = ? LIMIT 1');
    $sourceSt->execute([$sourcePeriodId]);
    $sourcePeriod = $sourceSt->fetch(PDO::FETCH_ASSOC);
    if (!$sourcePeriod) {
        return null;
    }

    $targetSt = $pdo->prepare('SELECT id FROM ledger_periods WHERE company_id = ? AND label <=> ? AND started_at <=> ? AND status = ? LIMIT 1');
    $targetSt->execute([
        $targetCompanyId,
        $sourcePeriod['label'] ?? null,
        $sourcePeriod['started_at'] ?? null,
        $sourcePeriod['status'] ?? 'OPEN',
    ]);
    $targetPeriodId = (int) $targetSt->fetchColumn();
    if ($targetPeriodId > 0) {
        return $targetPeriodId;
    }

    $insertSt = $pdo->prepare('INSERT INTO ledger_periods (company_id, label, started_at, settled_at, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $insertSt->execute([
        $targetCompanyId,
        $sourcePeriod['label'] ?? null,
        $sourcePeriod['started_at'] ?? date('Y-m-d H:i:s'),
        $sourcePeriod['settled_at'] ?? null,
        $sourcePeriod['status'] ?? 'OPEN',
        $sourcePeriod['created_at'] ?? date('Y-m-d H:i:s'),
    ]);

    return (int) $pdo->lastInsertId();
}

function auth_move_linked_ledger_entries_to_company(PDO $pdo, int $sourceCompanyId, int $targetCompanyId): void
{
    if (!auth_table_exists($pdo, 'ledger_entries') || !auth_table_exists($pdo, 'business_expenses')) {
        return;
    }

    $st = $pdo->prepare('
        SELECT e.id, e.partner_id, e.period_id, b.company_id AS expense_company_id
        FROM ledger_entries e
        INNER JOIN business_expenses b ON b.id = e.business_expense_id
        WHERE e.company_id = ? AND b.company_id = ?
    ');
    $st->execute([$sourceCompanyId, $targetCompanyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $targetPartnerId = auth_copy_partner_to_company($pdo, (int) ($row['partner_id'] ?? 0), $targetCompanyId);
        $targetPeriodId = auth_copy_period_to_company($pdo, (int) ($row['period_id'] ?? 0), $targetCompanyId);

        $update = $pdo->prepare('UPDATE ledger_entries SET company_id = ?, partner_id = ?, period_id = ? WHERE id = ?');
        $update->execute([
            $targetCompanyId,
            $targetPartnerId,
            $targetPeriodId,
            (int) $row['id'],
        ]);
    }
}

function auth_run_legacy_owner_company_split(PDO $pdo): void
{
    $migrationKey = '2026_06_split_legacy_owner_companies';
    if (auth_has_migration($pdo, $migrationKey)) {
        return;
    }

    try {
        $baseCompanyId = (int) $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($baseCompanyId <= 0) {
            return;
        }

        $baseCompanyName = 'Lina Rent A Car';
        $secondCompanyName = 'Lina Filo';

        $ownerOneCars = 0;
        try {
            $ownerOneCars = (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE owner_name = '1. Ortak'")->fetchColumn();
        } catch (Throwable $e) {
            $ownerOneCars = 0;
        }

        $ownerOneExpenses = 0;
        try {
            $ownerOneExpenses = (int) $pdo->query("SELECT COUNT(*) FROM business_expenses WHERE owner_name = '1. Ortak'")->fetchColumn();
        } catch (Throwable $e) {
            $ownerOneExpenses = 0;
        }

        if ($ownerOneCars === 0 && $ownerOneExpenses === 0) {
            auth_mark_migration($pdo, $migrationKey);
            return;
        }

        $pdo->beginTransaction();

        $baseSlug = auth_resolve_company_slug($pdo, $baseCompanyName, $baseCompanyId);
        $updateBaseCompany = $pdo->prepare('UPDATE companies SET name = ?, legal_name = COALESCE(NULLIF(legal_name, \'\'), ?), slug = ?, is_active = 1, updated_at = NOW() WHERE id = ?');
        $updateBaseCompany->execute([$baseCompanyName, $baseCompanyName, $baseSlug, $baseCompanyId]);
        auth_deactivate_unused_duplicate_companies($pdo, $baseCompanyId, $baseCompanyName);

        $secondCompanyId = auth_find_or_create_company($pdo, $secondCompanyName);
        $secondSlug = auth_resolve_company_slug($pdo, $secondCompanyName, $secondCompanyId);
        $updateSecondCompany = $pdo->prepare('UPDATE companies SET name = ?, legal_name = COALESCE(NULLIF(legal_name, \'\'), ?), slug = ?, is_active = 1, updated_at = NOW() WHERE id = ?');
        $updateSecondCompany->execute([$secondCompanyName, $secondCompanyName, $secondSlug, $secondCompanyId]);
        auth_deactivate_unused_duplicate_companies($pdo, $secondCompanyId, $secondCompanyName);

        $moveCars = $pdo->prepare("UPDATE cars SET company_id = ?, owner_name = ? WHERE company_id = ? AND owner_name = '1. Ortak'");
        $moveCars->execute([$secondCompanyId, $secondCompanyName, $baseCompanyId]);

        $normalizeBaseCars = $pdo->prepare("UPDATE cars SET owner_name = ? WHERE company_id = ? AND (owner_name = '2. Ortak' OR owner_name IS NULL OR owner_name = '')");
        $normalizeBaseCars->execute([$baseCompanyName, $baseCompanyId]);

        $moveRentals = $pdo->prepare('UPDATE rentals r INNER JOIN cars c ON c.id = r.car_id SET r.company_id = ? WHERE r.company_id = ? AND c.company_id = ?');
        $moveRentals->execute([$secondCompanyId, $baseCompanyId, $secondCompanyId]);

        $moveExpenses = $pdo->prepare("UPDATE business_expenses SET company_id = ?, owner_name = ? WHERE company_id = ? AND owner_name = '1. Ortak'");
        $moveExpenses->execute([$secondCompanyId, $secondCompanyName, $baseCompanyId]);

        $normalizeBaseExpenses = $pdo->prepare("UPDATE business_expenses SET owner_name = ? WHERE company_id = ? AND (owner_name = '2. Ortak' OR owner_name IS NULL OR owner_name = '')");
        $normalizeBaseExpenses->execute([$baseCompanyName, $baseCompanyId]);

        auth_move_linked_ledger_entries_to_company($pdo, $baseCompanyId, $secondCompanyId);

        $sourceUsersSt = $pdo->prepare('SELECT * FROM users WHERE company_id = ? ORDER BY id ASC');
        $sourceUsersSt->execute([$baseCompanyId]);
        $sourceUsers = $sourceUsersSt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sourceUsers as $sourceUser) {
            $clonedUsername = auth_generate_cloned_username($pdo, (string) $sourceUser['username'], '_filo', $secondCompanyId);
            $existingTargetUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND company_id = ? LIMIT 1');
            $existingTargetUser->execute([$clonedUsername, $secondCompanyId]);
            if ($existingTargetUser->fetchColumn()) {
                continue;
            }

            $insertUser = $pdo->prepare('INSERT INTO users (company_id, full_name, username, password_hash, role, is_active, last_login_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $insertUser->execute([
                $secondCompanyId,
                $sourceUser['full_name'],
                $clonedUsername,
                $sourceUser['password_hash'],
                $sourceUser['role'],
                (int) $sourceUser['is_active'],
                $sourceUser['last_login_at'],
                $sourceUser['created_at'],
            ]);
        }

        auth_mark_migration($pdo, $migrationKey);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function auth_run_primary_company_realignment(PDO $pdo): void
{
    $migrationKey = '2026_06_realign_primary_company_to_lina_filo';
    if (auth_has_migration($pdo, $migrationKey)) {
        return;
    }

    try {
        $rentCompanyId = auth_find_company_id_by_name($pdo, 'Lina Rent A Car');
        $filoCompanyId = auth_find_company_id_by_name($pdo, 'Lina Filo');

        if ($rentCompanyId <= 0 || $filoCompanyId <= 0 || $rentCompanyId === $filoCompanyId) {
            auth_mark_migration($pdo, $migrationKey);
            return;
        }

        $sourceUsersSt = $pdo->prepare("SELECT * FROM users WHERE company_id = ? AND RIGHT(username, 5) <> '_filo' ORDER BY id ASC");
        $sourceUsersSt->execute([$rentCompanyId]);
        $sourceUsers = $sourceUsersSt->fetchAll(PDO::FETCH_ASSOC);

        if (!$sourceUsers) {
            auth_mark_migration($pdo, $migrationKey);
            return;
        }

        $pdo->beginTransaction();

        $cloneLookup = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $moveClone = $pdo->prepare('UPDATE users SET company_id = ?, username = ? WHERE id = ?');
        $insertClone = $pdo->prepare('INSERT INTO users (company_id, full_name, username, password_hash, role, is_active, last_login_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $moveSource = $pdo->prepare('UPDATE users SET company_id = ? WHERE id = ?');

        foreach ($sourceUsers as $sourceUser) {
            $baseUsername = (string) ($sourceUser['username'] ?? '');
            if ($baseUsername === '') {
                continue;
            }

            $cloneLookup->execute([$baseUsername . '_filo']);
            $cloneUserId = (int) $cloneLookup->fetchColumn();
            $rentUsername = auth_generate_cloned_username($pdo, $baseUsername, '_rent', $rentCompanyId);

            if ($cloneUserId > 0) {
                $moveClone->execute([$rentCompanyId, $rentUsername, $cloneUserId]);
            } else {
                $insertClone->execute([
                    $rentCompanyId,
                    $sourceUser['full_name'],
                    $rentUsername,
                    $sourceUser['password_hash'],
                    $sourceUser['role'],
                    (int) $sourceUser['is_active'],
                    $sourceUser['last_login_at'],
                    $sourceUser['created_at'],
                ]);
            }

            $moveSource->execute([$filoCompanyId, (int) $sourceUser['id']]);
        }

        auth_mark_migration($pdo, $migrationKey);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function auth_run_primary_ledger_company_realignment(PDO $pdo): void
{
    $migrationKey = '2026_06_move_ledger_from_lina_rent_to_lina_filo';
    if (auth_has_migration($pdo, $migrationKey)) {
        return;
    }

    if (!auth_table_exists($pdo, 'ledger_entries') || !auth_table_exists($pdo, 'ledger_partners') || !auth_table_exists($pdo, 'ledger_periods')) {
        auth_mark_migration($pdo, $migrationKey);
        return;
    }

    try {
        $sourceCompanyId = auth_find_company_id_by_name($pdo, 'Lina Rent A Car');
        $targetCompanyId = auth_find_company_id_by_name($pdo, 'Lina Filo');

        if ($sourceCompanyId <= 0 || $targetCompanyId <= 0 || $sourceCompanyId === $targetCompanyId) {
            auth_mark_migration($pdo, $migrationKey);
            return;
        }

        $sourceEntryCountSt = $pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id = ?');
        $sourceEntryCountSt->execute([$sourceCompanyId]);
        if ((int) $sourceEntryCountSt->fetchColumn() === 0) {
            auth_mark_migration($pdo, $migrationKey);
            return;
        }

        $pdo->beginTransaction();

        $targetOpenPeriodSt = $pdo->prepare("SELECT * FROM ledger_periods WHERE company_id = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1");
        $targetOpenPeriodSt->execute([$targetCompanyId]);
        $targetOpenPeriod = $targetOpenPeriodSt->fetch(PDO::FETCH_ASSOC) ?: null;
        $targetOpenPeriodId = (int) ($targetOpenPeriod['id'] ?? 0);
        $targetOpenPeriodEntryCount = 0;
        if ($targetOpenPeriodId > 0) {
            $targetOpenPeriodEntryCountSt = $pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id = ? AND period_id = ?');
            $targetOpenPeriodEntryCountSt->execute([$targetCompanyId, $targetOpenPeriodId]);
            $targetOpenPeriodEntryCount = (int) $targetOpenPeriodEntryCountSt->fetchColumn();
        }

        $partnerMap = [];
        $sourcePartnerRowsSt = $pdo->prepare('SELECT id FROM ledger_partners WHERE company_id = ? ORDER BY id ASC');
        $sourcePartnerRowsSt->execute([$sourceCompanyId]);
        $sourcePartnerRows = $sourcePartnerRowsSt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sourcePartnerRows as $sourcePartnerRow) {
            $sourcePartnerId = (int) ($sourcePartnerRow['id'] ?? 0);
            if ($sourcePartnerId <= 0) {
                continue;
            }

            $partnerMap[$sourcePartnerId] = auth_copy_partner_to_company($pdo, $sourcePartnerId, $targetCompanyId);
        }

        $periodMap = [];
        $sourcePeriodRowsSt = $pdo->prepare('SELECT * FROM ledger_periods WHERE company_id = ? ORDER BY started_at ASC, id ASC');
        $sourcePeriodRowsSt->execute([$sourceCompanyId]);
        $sourcePeriodRows = $sourcePeriodRowsSt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sourcePeriodRows as $sourcePeriodRow) {
            $sourcePeriodId = (int) ($sourcePeriodRow['id'] ?? 0);
            if ($sourcePeriodId <= 0) {
                continue;
            }

            $sourceStatus = (string) ($sourcePeriodRow['status'] ?? 'OPEN');
            if ($sourceStatus === 'OPEN' && $targetOpenPeriodId > 0) {
                $periodMap[$sourcePeriodId] = $targetOpenPeriodId;

                if ($targetOpenPeriodEntryCount === 0) {
                    $updateTargetOpen = $pdo->prepare('UPDATE ledger_periods SET label = ?, started_at = ?, created_at = ? WHERE id = ?');
                    $updateTargetOpen->execute([
                        $sourcePeriodRow['label'] ?? 'Açık Hesap',
                        $sourcePeriodRow['started_at'] ?? date('Y-m-d H:i:s'),
                        $sourcePeriodRow['created_at'] ?? date('Y-m-d H:i:s'),
                        $targetOpenPeriodId,
                    ]);
                    $targetOpenPeriodEntryCount = 1;
                }

                continue;
            }

            $periodMap[$sourcePeriodId] = auth_copy_period_to_company($pdo, $sourcePeriodId, $targetCompanyId);
        }

        $sourceEntriesSt = $pdo->prepare('SELECT id, partner_id, period_id FROM ledger_entries WHERE company_id = ? ORDER BY id ASC');
        $sourceEntriesSt->execute([$sourceCompanyId]);
        $sourceEntries = $sourceEntriesSt->fetchAll(PDO::FETCH_ASSOC);

        $moveEntrySt = $pdo->prepare('UPDATE ledger_entries SET company_id = ?, partner_id = ?, period_id = ? WHERE id = ?');
        foreach ($sourceEntries as $sourceEntry) {
            $entryId = (int) ($sourceEntry['id'] ?? 0);
            if ($entryId <= 0) {
                continue;
            }

            $sourcePartnerId = (int) ($sourceEntry['partner_id'] ?? 0);
            $sourcePeriodId = (int) ($sourceEntry['period_id'] ?? 0);

            $targetPartnerId = $partnerMap[$sourcePartnerId] ?? auth_copy_partner_to_company($pdo, $sourcePartnerId, $targetCompanyId);
            $targetPeriodId = $periodMap[$sourcePeriodId] ?? auth_copy_period_to_company($pdo, $sourcePeriodId, $targetCompanyId);

            $moveEntrySt->execute([
                $targetCompanyId,
                $targetPartnerId,
                $targetPeriodId,
                $entryId,
            ]);
        }

        $cleanupPeriods = $pdo->prepare('DELETE FROM ledger_periods WHERE company_id = ? AND id NOT IN (SELECT DISTINCT period_id FROM ledger_entries WHERE company_id = ?)');
        $cleanupPeriods->execute([$sourceCompanyId, $sourceCompanyId]);

        $cleanupPartners = $pdo->prepare('DELETE FROM ledger_partners WHERE company_id = ? AND id NOT IN (SELECT DISTINCT partner_id FROM ledger_entries WHERE company_id = ? AND partner_id IS NOT NULL)');
        $cleanupPartners->execute([$sourceCompanyId, $sourceCompanyId]);

        auth_mark_migration($pdo, $migrationKey);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function auth_run_platform_admin_seed(PDO $pdo): void
{
    $migrationKey = '2026_06_seed_platform_admin';
    if (auth_has_migration($pdo, $migrationKey)) {
        return;
    }

    try {
        $existingPlatformAdmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'platform_admin'")->fetchColumn();
        if ($existingPlatformAdmin > 0) {
            auth_mark_migration($pdo, $migrationKey);
            return;
        }

        $preferredCompanyId = auth_find_company_id_by_name($pdo, 'Lina Filo');
        $candidateUserId = 0;

        if ($preferredCompanyId > 0) {
            $preferredSt = $pdo->prepare("SELECT id FROM users WHERE company_id = ? AND role = 'super_admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");
            $preferredSt->execute([$preferredCompanyId]);
            $candidateUserId = (int) $preferredSt->fetchColumn();
        }

        if ($candidateUserId <= 0) {
            $fallbackSt = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");
            $candidateUserId = (int) $fallbackSt->fetchColumn();
        }

        if ($candidateUserId > 0) {
            $update = $pdo->prepare("UPDATE users SET role = 'platform_admin' WHERE id = ?");
            $update->execute([$candidateUserId]);
        }

        auth_mark_migration($pdo, $migrationKey);
    } catch (Throwable $e) {
    }
}

function ensureAuthSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(180) NOT NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $companyColumns = [
        'legal_name' => "ALTER TABLE companies ADD COLUMN legal_name VARCHAR(180) NULL AFTER name",
        'email' => "ALTER TABLE companies ADD COLUMN email VARCHAR(150) NULL AFTER legal_name",
        'phone' => "ALTER TABLE companies ADD COLUMN phone VARCHAR(30) NULL AFTER email",
        'tax_office' => "ALTER TABLE companies ADD COLUMN tax_office VARCHAR(120) NULL AFTER phone",
        'tax_number' => "ALTER TABLE companies ADD COLUMN tax_number VARCHAR(30) NULL AFTER tax_office",
        'mersis_number' => "ALTER TABLE companies ADD COLUMN mersis_number VARCHAR(30) NULL AFTER tax_number",
        'address' => "ALTER TABLE companies ADD COLUMN address TEXT NULL AFTER mersis_number",
        'district' => "ALTER TABLE companies ADD COLUMN district VARCHAR(120) NULL AFTER address",
        'city' => "ALTER TABLE companies ADD COLUMN city VARCHAR(120) NULL AFTER district",
        'country' => "ALTER TABLE companies ADD COLUMN country VARCHAR(120) NOT NULL DEFAULT 'Turkiye' AFTER city",
        'website' => "ALTER TABLE companies ADD COLUMN website VARCHAR(180) NULL AFTER country",
        'logo_path' => "ALTER TABLE companies ADD COLUMN logo_path VARCHAR(255) NULL AFTER website",
        'updated_at' => "ALTER TABLE companies ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($companyColumns as $column => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM companies LIKE '{$column}'")->fetch();
            if (!$exists) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(40) NOT NULL DEFAULT 'viewer',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    )");

    $userColumns = [
        'archived_at' => "ALTER TABLE users ADD COLUMN archived_at DATETIME NULL AFTER is_active",
        'archived_by_user_id' => "ALTER TABLE users ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE users ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
    ];

    foreach ($userColumns as $column => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM users LIKE '{$column}'")->fetch();
            if (!$exists) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }

    try {
        $userArchiveIndex = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_company_archived'")->fetch();
        if (!$userArchiveIndex) {
            $pdo->exec("ALTER TABLE users ADD INDEX idx_users_company_archived (company_id, archived_at)");
        }
    } catch (Throwable $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_throttles (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        username_normalized VARCHAR(80) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        failed_attempts INT NOT NULL DEFAULT 0,
        lock_until DATETIME NULL,
        last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_auth_login_throttles_username_ip (username_normalized, ip_address),
        KEY idx_auth_login_throttles_lock_until (lock_until),
        KEY idx_auth_login_throttles_last_attempt_at (last_attempt_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NULL,
        user_id BIGINT NULL,
        event_type VARCHAR(80) NOT NULL,
        entity_type VARCHAR(80) NULL,
        entity_id BIGINT NULL,
        description VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255) NOT NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_logs_company_created (company_id, created_at),
        KEY idx_audit_logs_user_created (user_id, created_at),
        KEY idx_audit_logs_event_created (event_type, created_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        notification_key VARCHAR(190) NOT NULL,
        source_type VARCHAR(30) NOT NULL DEFAULT 'system',
        event_type VARCHAR(80) NOT NULL,
        entity_type VARCHAR(80) NULL,
        entity_id BIGINT NULL,
        severity VARCHAR(20) NOT NULL DEFAULT 'info',
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        title VARCHAR(180) NOT NULL,
        message VARCHAR(255) NOT NULL,
        due_at DATETIME NULL,
        first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        resolved_at DATETIME NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_notifications_company_key (company_id, notification_key),
        KEY idx_notifications_company_status_due (company_id, status, due_at),
        KEY idx_notifications_company_severity_due (company_id, severity, due_at),
        KEY idx_notifications_entity (entity_type, entity_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_migrations (
        migration_key VARCHAR(100) PRIMARY KEY,
        executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $companyCount = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    if ($companyCount === 0) {
        $insert = $pdo->prepare('INSERT INTO companies (name, slug) VALUES (?, ?)');
        $insert->execute(['RentecarWeb Demo', 'rentecarweb-demo']);
    }

    try {
        $pdo->exec("UPDATE companies SET legal_name = name WHERE legal_name IS NULL OR legal_name = ''");
        $pdo->exec("UPDATE companies SET country = 'Turkiye' WHERE country IS NULL OR country = ''");
        $pdo->exec("UPDATE companies SET updated_at = created_at WHERE updated_at IS NULL");
    } catch (Throwable $e) {
    }

    $defaultCompanyId = (int) $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();

    $columns = [
        'cars' => "ALTER TABLE cars ADD COLUMN company_id BIGINT NULL AFTER id",
        'rentals' => "ALTER TABLE rentals ADD COLUMN company_id BIGINT NULL AFTER id",
        'business_expenses' => "ALTER TABLE business_expenses ADD COLUMN company_id BIGINT NULL AFTER id",
        'ledger_partners' => "ALTER TABLE ledger_partners ADD COLUMN company_id BIGINT NULL AFTER id",
        'ledger_periods' => "ALTER TABLE ledger_periods ADD COLUMN company_id BIGINT NULL AFTER id",
        'ledger_entries' => "ALTER TABLE ledger_entries ADD COLUMN company_id BIGINT NULL AFTER id",
    ];

    foreach ($columns as $table => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'company_id'")->fetch();
            if (!$exists) {
                $pdo->exec($sql);
            }
            $update = $pdo->prepare("UPDATE {$table} SET company_id = ? WHERE company_id IS NULL");
            $update->execute([$defaultCompanyId]);
        } catch (Throwable $e) {
        }
    }

    $cleanupDate = date('Y-m-d H:i:s', time() - (auth_login_rate_limit_window_minutes() * 60 * 2));
    try {
        $cleanup = $pdo->prepare('DELETE FROM auth_login_throttles WHERE (lock_until IS NULL AND last_attempt_at < ?) OR (lock_until IS NOT NULL AND lock_until < NOW() AND last_attempt_at < ?)');
        $cleanup->execute([$cleanupDate, $cleanupDate]);
    } catch (Throwable $e) {
    }

    $initialized = true;
}

function auth_has_users(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function auth_slugify(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $map = [
        'Ã§' => 'c', 'ÄŸ' => 'g', 'Ä±' => 'i', 'Ã¶' => 'o', 'ÅŸ' => 's', 'Ã¼' => 'u',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    return trim($value, '-') ?: 'firma';
}

function auth_current_user(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function auth_current_company_id(): int
{
    $user = auth_current_user();
    return (int) ($user['company_id'] ?? 0);
}

function auth_is_platform_admin(?array $user = null): bool
{
    $user = $user ?? auth_current_user();
    return ($user['role'] ?? null) === 'platform_admin';
}

function auth_current_company(PDO $pdo): ?array
{
    $companyId = auth_current_company_id();
    if ($companyId <= 0) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$companyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC);

    return $company ?: null;
}

function auth_is_guest(): bool
{
    return auth_current_user() === null;
}

function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function auth_reload_user(PDO $pdo): ?array
{
    $sessionUser = auth_current_user();
    if (!$sessionUser || empty($sessionUser['id'])) {
        return null;
    }

    $st = $pdo->prepare('SELECT u.*, c.name AS company_name, c.legal_name AS company_legal_name, c.logo_path AS company_logo_path, c.phone AS company_phone, c.email AS company_email, c.website AS company_website FROM users u INNER JOIN companies c ON c.id = u.company_id WHERE u.id = ? AND u.is_active = 1 AND u.archived_at IS NULL AND c.is_active = 1');
    $st->execute([(int) $sessionUser['id']]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        auth_logout();
        return null;
    }

    $_SESSION['auth_user'] = $user;
    return $user;
}

function auth_attempt_login(PDO $pdo, string $username, string $password): bool
{
    $st = $pdo->prepare('SELECT u.*, c.name AS company_name, c.legal_name AS company_legal_name, c.logo_path AS company_logo_path, c.phone AS company_phone, c.email AS company_email, c.website AS company_website FROM users u INNER JOIN companies c ON c.id = u.company_id WHERE u.username = ? AND u.is_active = 1 AND u.archived_at IS NULL AND c.is_active = 1 LIMIT 1');
    $st->execute([trim($username)]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    session_regenerate_id(true);
    auth_rotate_csrf_token();
    $_SESSION['auth_user'] = $user;
    auth_mark_session_activity();
    return true;
}

function auth_active_super_admin_count(PDO $pdo, int $companyId, ?int $excludeUserId = null): int
{
    $sql = "SELECT COUNT(*) FROM users WHERE company_id = ? AND role IN ('platform_admin', 'super_admin') AND is_active = 1 AND archived_at IS NULL";
    $params = [$companyId];

    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeUserId;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}

function auth_create_initial_admin(PDO $pdo, string $companyName, string $fullName, string $username, string $password): array
{
    if (auth_has_users($pdo)) {
        return ['ok' => false, 'message' => 'Kurulum zaten tamamlanmis.'];
    }

    $companyName = trim($companyName);
    $fullName = trim($fullName);
    $username = trim($username);

    if ($companyName === '' || $fullName === '' || $username === '') {
        return ['ok' => false, 'message' => 'Tum alanlari doldur.'];
    }

    $passwordErrors = auth_validate_password_policy($password);
    if (!empty($passwordErrors)) {
        return ['ok' => false, 'message' => implode(' ', $passwordErrors)];
    }

    $slug = auth_slugify($companyName);
    $suffix = 2;
    $slugCheck = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE slug = ?');
    while (true) {
        $slugCheck->execute([$slug]);
        if ((int) $slugCheck->fetchColumn() === 0) {
            break;
        }
        $slug = auth_slugify($companyName) . '-' . $suffix;
        $suffix++;
    }

    $userCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $userCheck->execute([$username]);
    if ((int) $userCheck->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Bu kullanici adi zaten kullaniliyor.'];
    }

    $pdo->beginTransaction();
    try {
        $companyId = (int) $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($companyId <= 0) {
            $companyInsert = $pdo->prepare('INSERT INTO companies (name, slug) VALUES (?, ?)');
            $companyInsert->execute([$companyName, $slug]);
            $companyId = (int) $pdo->lastInsertId();
        } else {
            $companyUpdate = $pdo->prepare('UPDATE companies SET name = ?, slug = ? WHERE id = ?');
            $companyUpdate->execute([$companyName, $slug, $companyId]);
        }

        $userInsert = $pdo->prepare('INSERT INTO users (company_id, full_name, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $userInsert->execute([$companyId, $fullName, $username, password_hash($password, PASSWORD_DEFAULT), 'super_admin']);

        $pdo->commit();
        return ['ok' => true, 'company_id' => $companyId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Kurulum sirasinda hata olustu.'];
    }
}

function auth_bootstrap(PDO $pdo): void
{
    auth_apply_security_headers();
    ensureAuthSchema($pdo);

    $scriptName = basename($_SERVER['PHP_SELF'] ?? '');
    $publicScripts = ['login.php', 'setup.php', 'auth_login.php', 'setup_admin.php'];

    if (!auth_has_users($pdo)) {
        if (!in_array($scriptName, ['setup.php', 'setup_admin.php'], true)) {
            auth_redirect('setup.php');
        }
        return;
    }

    auth_run_legacy_owner_company_split($pdo);
    auth_run_primary_company_realignment($pdo);
    auth_run_primary_ledger_company_realignment($pdo);
    auth_run_platform_admin_seed($pdo);

    if (auth_current_user() && auth_session_expired()) {
        auth_audit_log($pdo, 'auth.session_expired', 'Oturum zaman asimina ugradi.', [
            'entity_type' => 'auth',
            'user_id' => (int) (auth_current_user()['id'] ?? 0),
            'company_id' => (int) (auth_current_user()['company_id'] ?? 0),
        ]);
        auth_logout();
        if (!in_array($scriptName, $publicScripts, true)) {
            auth_redirect('login.php?error=session_expired');
        }
    }

    $user = auth_reload_user($pdo);
    if (!$user && !in_array($scriptName, $publicScripts, true)) {
        auth_redirect('login.php');
    }

    if ($user) {
        auth_mark_session_activity();
    }

    if ($user && in_array($scriptName, $publicScripts, true)) {
        auth_redirect('index.php');
    }
}
