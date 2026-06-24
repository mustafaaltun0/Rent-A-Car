<?php

function auth_has_users(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function auth_slugify(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $map = [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u',
        'Ã§' => 'c', 'ÄŸ' => 'g', 'Ä±' => 'i', 'Ã¶' => 'o', 'ÅŸ' => 's', 'Ã¼' => 'u',
        'ÃƒÆ’Ã‚Â§' => 'c', 'Ãƒâ€Ã…Â¸' => 'g', 'Ãƒâ€Ã‚Â±' => 'i', 'ÃƒÆ’Ã‚Â¶' => 'o', 'Ãƒâ€¦Ã…Â¸' => 's', 'ÃƒÆ’Ã‚Â¼' => 'u',
        'ÃƒÂ§' => 'c', 'Ã„Å¸' => 'g', 'Ã„Â±' => 'i', 'ÃƒÂ¶' => 'o', 'Ã…Å¸' => 's', 'ÃƒÂ¼' => 'u',
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

function auth_session_ip_scope(): string
{
    $ipAddress = auth_client_ip();

    if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ipAddress);
        return count($parts) === 4 ? ($parts[0] . '.' . $parts[1] . '.' . $parts[2]) : $ipAddress;
    }

    if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ipAddress);
        $parts = array_pad($parts, 4, '0');
        return implode(':', array_slice($parts, 0, 4));
    }

    return 'unknown';
}

function auth_session_fingerprint_value(): string
{
    return hash('sha256', auth_user_agent() . '|' . auth_session_ip_scope());
}

function auth_establish_session(array $user): void
{
    session_regenerate_id(true);
    auth_rotate_csrf_token();
    $_SESSION['auth_user'] = $user;
    $_SESSION['auth_session_fingerprint'] = auth_session_fingerprint_value();
    $_SESSION['auth_session_created_at'] = time();
    $_SESSION['auth_session_regenerated_at'] = time();
    auth_mark_session_activity();
}

function auth_validate_session_context(): bool
{
    if (!auth_current_user()) {
        return true;
    }

    $currentFingerprint = auth_session_fingerprint_value();
    $storedFingerprint = (string) ($_SESSION['auth_session_fingerprint'] ?? '');

    if ($storedFingerprint === '') {
        $_SESSION['auth_session_fingerprint'] = $currentFingerprint;
        $_SESSION['auth_session_created_at'] = (int) ($_SESSION['auth_session_created_at'] ?? time());
        $_SESSION['auth_session_regenerated_at'] = (int) ($_SESSION['auth_session_regenerated_at'] ?? time());
        return true;
    }

    return hash_equals($storedFingerprint, $currentFingerprint);
}

function auth_rotate_session_id_if_needed(bool $force = false): void
{
    if (!auth_current_user()) {
        return;
    }

    $lastRegeneratedAt = (int) ($_SESSION['auth_session_regenerated_at'] ?? 0);
    $shouldRegenerate = $force
        || $lastRegeneratedAt <= 0
        || (time() - $lastRegeneratedAt) >= auth_session_regeneration_interval_seconds();

    if (!$shouldRegenerate) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION['auth_session_regenerated_at'] = time();
}

function auth_reload_user(PDO $pdo): ?array
{
    $sessionUser = auth_current_user();
    if (!$sessionUser || empty($sessionUser['id'])) {
        return null;
    }

    $st = $pdo->prepare("
        SELECT
            u.*,
            c.name AS company_name,
            c.legal_name AS company_legal_name,
            c.logo_path AS company_logo_path,
            c.phone AS company_phone,
            c.email AS company_email,
            c.website AS company_website,
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
        INNER JOIN companies c ON c.id = u.company_id
        LEFT JOIN company_roles cr ON cr.id = u.custom_role_id AND cr.company_id = u.company_id
        WHERE u.id = ? AND u.is_active = 1 AND u.archived_at IS NULL AND c.is_active = 1
    ");
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
    $st = $pdo->prepare("
        SELECT
            u.*,
            c.name AS company_name,
            c.legal_name AS company_legal_name,
            c.logo_path AS company_logo_path,
            c.phone AS company_phone,
            c.email AS company_email,
            c.website AS company_website,
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
        INNER JOIN companies c ON c.id = u.company_id
        LEFT JOIN company_roles cr ON cr.id = u.custom_role_id AND cr.company_id = u.company_id
        WHERE u.username = ? AND u.is_active = 1 AND u.archived_at IS NULL AND c.is_active = 1
        LIMIT 1
    ");
    $st->execute([trim($username)]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    auth_establish_session($user);
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
        return ['ok' => false, 'message' => 'Kurulum zaten tamamlanmış.'];
    }

    $companyName = trim($companyName);
    $fullName = trim($fullName);
    $username = trim($username);

    if ($companyName === '' || $fullName === '' || $username === '') {
        return ['ok' => false, 'message' => 'Tüm alanları doldur.'];
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
        return ['ok' => false, 'message' => 'Bu kullanıcı adı zaten kullanılıyor.'];
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
        return ['ok' => false, 'message' => 'Kurulum sırasında hata oluştu.'];
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

    if (function_exists('app_run_legacy_data_migrations_on_boot') && app_run_legacy_data_migrations_on_boot()) {
        auth_run_legacy_owner_company_split($pdo);
        auth_run_primary_company_realignment($pdo);
        auth_run_primary_ledger_company_realignment($pdo);
        auth_run_platform_admin_seed($pdo);
    }

    if (auth_current_user() && auth_session_expired()) {
        auth_audit_log($pdo, 'auth.session_expired', 'Oturum zaman aşımına uğradı.', [
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
        if (!auth_validate_session_context()) {
            auth_audit_log($pdo, 'auth.session_fingerprint_mismatch', 'Oturum bağlam doğrulaması başarısız oldu.', [
                'entity_type' => 'auth',
                'user_id' => (int) ($user['id'] ?? 0),
                'company_id' => (int) ($user['company_id'] ?? 0),
                'metadata' => [
                    'ip_address' => auth_client_ip(),
                    'user_agent' => auth_user_agent(),
                ],
            ]);
            auth_logout();
            if (!in_array($scriptName, $publicScripts, true)) {
                auth_redirect('login.php?error=session_expired');
            }
        }

        auth_rotate_session_id_if_needed();
        auth_mark_session_activity();
    }

    if ($user && in_array($scriptName, $publicScripts, true)) {
        auth_redirect('index.php');
    }
}
