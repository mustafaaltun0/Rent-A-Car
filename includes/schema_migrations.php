<?php

function app_schema_migration_available_for_user(?array $user = null): bool
{
    $user = $user ?? auth_current_user();
    return auth_can('platform.manage', $user);
}

function app_schema_migration_bootstrap(PDO $pdo): void
{
    app_ensure_schema($pdo, 'auth');
}

function app_schema_migration_definitions(): array
{
    return [
        [
            'key' => '2026_06_schema_auth_baseline',
            'label' => 'Kimlik, firma, kullanici, audit ve temel company_id yapisi',
            'callback' => static function (PDO $pdo): void {
                app_ensure_schema($pdo, 'auth');
            },
        ],
        [
            'key' => '2026_06_schema_rental_operations',
            'label' => 'Kiralama, uzatma, tahsilat ve dokuman altyapisi',
            'callback' => static function (PDO $pdo): void {
                app_ensure_schema($pdo, 'rental_documents');
            },
        ],
        [
            'key' => '2026_06_schema_car_fleet',
            'label' => 'Arac arsivi, satis ve telematik altyapisi',
            'callback' => static function (PDO $pdo): void {
                app_ensure_schema($pdo, 'car_sales');
            },
        ],
        [
            'key' => '2026_06_schema_finance_ledger',
            'label' => 'Gelir gider, ortaklik ve gider arsiv yapisi',
            'callback' => static function (PDO $pdo): void {
                app_ensure_schema($pdo, 'finance_core');
            },
        ],
        [
            'key' => '2026_06_retire_legacy_business_account_tables',
            'label' => 'Eski business_account tablolarini yedekleyip devreden cikarma',
            'callback' => static function (PDO $pdo): void {
                retireLegacyBusinessAccountTables($pdo);
            },
        ],
        [
            'key' => '2026_06_schema_support_modules',
            'label' => 'Musteri firmalari ve bildirim modulu',
            'callback' => static function (PDO $pdo): void {
                app_ensure_schema($pdo, 'support_modules');
            },
        ],
    ];
}

function app_schema_migration_applied_map(PDO $pdo): array
{
    app_schema_migration_bootstrap($pdo);

    try {
        $rows = $pdo->query('SELECT migration_key, executed_at FROM app_migrations')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return [];
    }

    $applied = [];
    foreach ($rows as $row) {
        $key = trim((string) ($row['migration_key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $applied[$key] = (string) ($row['executed_at'] ?? '');
    }

    return $applied;
}

function app_schema_migration_status(PDO $pdo): array
{
    $definitions = app_schema_migration_definitions();
    $appliedMap = app_schema_migration_applied_map($pdo);
    $items = [];
    $pendingCount = 0;

    foreach ($definitions as $definition) {
        $key = (string) ($definition['key'] ?? '');
        $executedAt = $appliedMap[$key] ?? null;
        $isApplied = $executedAt !== null;
        if (!$isApplied) {
            $pendingCount++;
        }

        $items[] = [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'is_applied' => $isApplied,
            'executed_at' => $executedAt,
        ];
    }

    return [
        'items' => $items,
        'pending_count' => $pendingCount,
        'applied_count' => count($items) - $pendingCount,
        'total_count' => count($items),
    ];
}

function app_run_schema_migrations(PDO $pdo, ?array $requestedKeys = null): array
{
    app_schema_migration_bootstrap($pdo);

    $requestedMap = null;
    if (is_array($requestedKeys) && !empty($requestedKeys)) {
        $requestedMap = [];
        foreach ($requestedKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $requestedMap[$key] = true;
            }
        }
    }

    $definitions = app_schema_migration_definitions();
    $appliedMap = app_schema_migration_applied_map($pdo);
    $appliedNow = [];
    $skipped = [];

    foreach ($definitions as $definition) {
        $key = (string) ($definition['key'] ?? '');
        if ($key === '') {
            continue;
        }

        if ($requestedMap !== null && !isset($requestedMap[$key])) {
            continue;
        }

        if (isset($appliedMap[$key]) || auth_has_migration($pdo, $key)) {
            $skipped[] = $key;
            continue;
        }

        $callback = $definition['callback'] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException('Migrasyon tanimi gecersiz: ' . $key);
        }

        $callback($pdo);
        auth_mark_migration($pdo, $key);
        $appliedNow[] = $key;
    }

    return [
        'applied_now' => $appliedNow,
        'skipped' => $skipped,
    ];
}
