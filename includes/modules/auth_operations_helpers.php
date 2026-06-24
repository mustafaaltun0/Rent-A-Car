<?php

function auth_login_throttle_targets(string $username): array
{
    $normalizedUsername = auth_normalize_username($username);
    $ipAddress = auth_client_ip();

    if ($normalizedUsername === '') {
        return [];
    }

    return [
        [
            'scope' => 'credential_ip',
            'username_normalized' => $normalizedUsername,
            'ip_address' => $ipAddress,
            'max_attempts' => auth_login_rate_limit_max_attempts(),
        ],
        [
            'scope' => 'credential_only',
            'username_normalized' => $normalizedUsername,
            'ip_address' => '*',
            'max_attempts' => auth_login_rate_limit_username_max_attempts(),
        ],
        [
            'scope' => 'ip_only',
            'username_normalized' => '__ip__',
            'ip_address' => $ipAddress,
            'max_attempts' => auth_login_rate_limit_ip_max_attempts(),
        ],
    ];
}

function auth_login_rate_limit_status(PDO $pdo, string $username): array
{
    $targets = auth_login_throttle_targets($username);
    if (empty($targets)) {
        return ['blocked' => false, 'retry_after' => 0];
    }

    $windowSeconds = auth_login_rate_limit_window_minutes() * 60;
    $retryAfter = 0;
    $blocked = false;

    $st = $pdo->prepare('SELECT failed_attempts, lock_until, last_attempt_at FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ? LIMIT 1');
    $reset = $pdo->prepare('DELETE FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ?');

    foreach ($targets as $target) {
        $st->execute([$target['username_normalized'], $target['ip_address']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        if (!empty($row['lock_until'])) {
            $lockUntilTs = strtotime((string) $row['lock_until']);
            if ($lockUntilTs !== false && $lockUntilTs > time()) {
                $blocked = true;
                $retryAfter = max($retryAfter, max(1, $lockUntilTs - time()));
                continue;
            }
        }

        $lastAttemptTs = !empty($row['last_attempt_at']) ? strtotime((string) $row['last_attempt_at']) : false;
        if ($lastAttemptTs !== false && (time() - $lastAttemptTs) > $windowSeconds) {
            $reset->execute([$target['username_normalized'], $target['ip_address']]);
        }
    }

    return ['blocked' => $blocked, 'retry_after' => $retryAfter];
}

function auth_record_failed_login(PDO $pdo, string $username): array
{
    $targets = auth_login_throttle_targets($username);
    $lockMinutes = auth_login_rate_limit_lock_minutes();
    $windowSeconds = auth_login_rate_limit_window_minutes() * 60;

    if (empty($targets)) {
        return ['blocked' => false, 'retry_after' => 0, 'failed_attempts' => 0];
    }

    $st = $pdo->prepare('SELECT id, failed_attempts, last_attempt_at FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ? LIMIT 1');
    $update = $pdo->prepare('UPDATE auth_login_throttles SET failed_attempts = ?, lock_until = ?, last_attempt_at = ? WHERE id = ?');
    $insert = $pdo->prepare('INSERT INTO auth_login_throttles (username_normalized, ip_address, failed_attempts, lock_until, last_attempt_at) VALUES (?, ?, ?, ?, ?)');

    $now = date('Y-m-d H:i:s');
    $retryAfter = 0;
    $blocked = false;
    $highestFailedAttempts = 0;

    foreach ($targets as $target) {
        $st->execute([$target['username_normalized'], $target['ip_address']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $failedAttempts = 1;
        $lockUntil = null;

        if ($row) {
            $lastAttemptTs = !empty($row['last_attempt_at']) ? strtotime((string) $row['last_attempt_at']) : false;
            if ($lastAttemptTs !== false && (time() - $lastAttemptTs) <= $windowSeconds) {
                $failedAttempts = ((int) $row['failed_attempts']) + 1;
            }

            if ($failedAttempts >= (int) $target['max_attempts']) {
                $lockUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
            }

            $update->execute([$failedAttempts, $lockUntil, $now, (int) $row['id']]);
        } else {
            if ($failedAttempts >= (int) $target['max_attempts']) {
                $lockUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
            }

            $insert->execute([$target['username_normalized'], $target['ip_address'], $failedAttempts, $lockUntil, $now]);
        }

        $highestFailedAttempts = max($highestFailedAttempts, $failedAttempts);
        if ($lockUntil !== null) {
            $blocked = true;
            $retryAfter = max($retryAfter, max(1, strtotime($lockUntil) - time()));
        }
    }

    return [
        'blocked' => $blocked,
        'retry_after' => $retryAfter,
        'failed_attempts' => $highestFailedAttempts,
    ];
}

function auth_clear_failed_logins(PDO $pdo, string $username): void
{
    $targets = auth_login_throttle_targets($username);
    if (empty($targets)) {
        return;
    }

    $st = $pdo->prepare('DELETE FROM auth_login_throttles WHERE username_normalized = ? AND ip_address = ?');
    foreach ($targets as $target) {
        $st->execute([$target['username_normalized'], $target['ip_address']]);
    }
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

    $insertSt = $pdo->prepare('INSERT INTO ledger_periods (company_id, label, started_at, settled_at, manual_shared_income, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insertSt->execute([
        $targetCompanyId,
        $sourcePeriod['label'] ?? null,
        $sourcePeriod['started_at'] ?? date('Y-m-d H:i:s'),
        $sourcePeriod['settled_at'] ?? null,
        (float) ($sourcePeriod['manual_shared_income'] ?? 0),
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
        error_log('Legacy owner/company split skipped due to error: ' . $e->getMessage());
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
        error_log('Primary company realignment skipped due to error: ' . $e->getMessage());
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
                    $updateTargetOpen = $pdo->prepare('UPDATE ledger_periods SET label = ?, started_at = ?, manual_shared_income = ?, created_at = ? WHERE id = ?');
                    $updateTargetOpen->execute([
                        $sourcePeriodRow['label'] ?? 'Açık Hesap',
                        $sourcePeriodRow['started_at'] ?? date('Y-m-d H:i:s'),
                        (float) ($sourcePeriodRow['manual_shared_income'] ?? 0),
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
        error_log('Primary ledger company realignment skipped due to error: ' . $e->getMessage());
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
        error_log('Platform admin seed skipped due to error: ' . $e->getMessage());
    }
}

