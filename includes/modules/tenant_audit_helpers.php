<?php

function tenant_audit_db_config(): array
{
    return [
        'host' => (string) app_env_value('DB_HOST', 'localhost'),
        'name' => (string) app_env_value('DB_NAME', 'rentacar_db'),
        'user' => (string) app_env_value('DB_USER', 'root'),
        'pass' => (string) app_env_value('DB_PASS', ''),
        'charset' => (string) app_env_value('DB_CHARSET', 'utf8mb4'),
    ];
}

function tenant_audit_has_table(PDO $pdo, string $table): bool
{
    $sql = 'SHOW TABLES LIKE ' . $pdo->quote($table);
    return (bool) $pdo->query($sql)->fetchColumn();
}

function tenant_audit_scalar(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function tenant_audit_rows(PDO $pdo, string $sql, int $limit = 10): array
{
    $sql = trim($sql);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function tenant_audit_report(PDO $pdo): array
{
    $report = [
        'generated_at' => date('Y-m-d H:i:s'),
        'database' => tenant_audit_db_config()['name'],
        'null_company_id' => [],
        'cross_company_mismatches' => [],
        'warnings' => [],
    ];

    $nullChecks = [
        'cars' => 'SELECT COUNT(*) FROM cars WHERE company_id IS NULL',
        'rentals' => 'SELECT COUNT(*) FROM rentals WHERE company_id IS NULL',
        'rental_extensions' => 'SELECT COUNT(*) FROM rental_extensions WHERE company_id IS NULL',
        'business_expenses' => 'SELECT COUNT(*) FROM business_expenses WHERE company_id IS NULL',
        'ledger_partners' => 'SELECT COUNT(*) FROM ledger_partners WHERE company_id IS NULL',
        'ledger_periods' => 'SELECT COUNT(*) FROM ledger_periods WHERE company_id IS NULL',
        'ledger_entries' => 'SELECT COUNT(*) FROM ledger_entries WHERE company_id IS NULL',
    ];

    foreach ($nullChecks as $table => $sql) {
        if (!tenant_audit_has_table($pdo, $table)) {
            $report['warnings'][] = sprintf('Tablo bulunamadi: %s', $table);
            continue;
        }

        $report['null_company_id'][$table] = tenant_audit_scalar($pdo, $sql);
    }

    $mismatchChecks = [
        'rentals_vs_cars' => [
            'count_sql' => "SELECT COUNT(*) FROM rentals r INNER JOIN cars c ON c.id = r.car_id WHERE r.company_id IS NOT NULL AND c.company_id IS NOT NULL AND r.company_id <> c.company_id",
            'sample_sql' => "SELECT r.id, r.company_id AS rental_company_id, c.company_id AS car_company_id FROM rentals r INNER JOIN cars c ON c.id = r.car_id WHERE r.company_id IS NOT NULL AND c.company_id IS NOT NULL AND r.company_id <> c.company_id ORDER BY r.id DESC",
        ],
        'rental_extensions_vs_rentals' => [
            'count_sql' => "SELECT COUNT(*) FROM rental_extensions re INNER JOIN rentals r ON r.id = re.rental_id WHERE re.company_id IS NOT NULL AND r.company_id IS NOT NULL AND re.company_id <> r.company_id",
            'sample_sql' => "SELECT re.id, re.company_id AS extension_company_id, r.company_id AS rental_company_id FROM rental_extensions re INNER JOIN rentals r ON r.id = re.rental_id WHERE re.company_id IS NOT NULL AND r.company_id IS NOT NULL AND re.company_id <> r.company_id ORDER BY re.id DESC",
        ],
        'ledger_entries_vs_periods' => [
            'count_sql' => "SELECT COUNT(*) FROM ledger_entries le INNER JOIN ledger_periods lp ON lp.id = le.period_id WHERE le.company_id IS NOT NULL AND lp.company_id IS NOT NULL AND le.company_id <> lp.company_id",
            'sample_sql' => "SELECT le.id, le.company_id AS entry_company_id, lp.company_id AS period_company_id FROM ledger_entries le INNER JOIN ledger_periods lp ON lp.id = le.period_id WHERE le.company_id IS NOT NULL AND lp.company_id IS NOT NULL AND le.company_id <> lp.company_id ORDER BY le.id DESC",
        ],
        'ledger_entries_vs_partners' => [
            'count_sql' => "SELECT COUNT(*) FROM ledger_entries le INNER JOIN ledger_partners lpa ON lpa.id = le.partner_id WHERE le.partner_id IS NOT NULL AND le.company_id IS NOT NULL AND lpa.company_id IS NOT NULL AND le.company_id <> lpa.company_id",
            'sample_sql' => "SELECT le.id, le.company_id AS entry_company_id, lpa.company_id AS partner_company_id FROM ledger_entries le INNER JOIN ledger_partners lpa ON lpa.id = le.partner_id WHERE le.partner_id IS NOT NULL AND le.company_id IS NOT NULL AND lpa.company_id IS NOT NULL AND le.company_id <> lpa.company_id ORDER BY le.id DESC",
        ],
        'ledger_entries_vs_expenses' => [
            'count_sql' => "SELECT COUNT(*) FROM ledger_entries le INNER JOIN business_expenses be ON be.id = le.business_expense_id WHERE le.business_expense_id IS NOT NULL AND le.company_id IS NOT NULL AND be.company_id IS NOT NULL AND le.company_id <> be.company_id",
            'sample_sql' => "SELECT le.id, le.company_id AS entry_company_id, be.company_id AS expense_company_id FROM ledger_entries le INNER JOIN business_expenses be ON be.id = le.business_expense_id WHERE le.business_expense_id IS NOT NULL AND le.company_id IS NOT NULL AND be.company_id IS NOT NULL AND le.company_id <> be.company_id ORDER BY le.id DESC",
        ],
    ];

    foreach ($mismatchChecks as $key => $queries) {
        $count = tenant_audit_scalar($pdo, $queries['count_sql']);
        $report['cross_company_mismatches'][$key] = [
            'count' => $count,
            'sample_rows' => $count > 0 ? tenant_audit_rows($pdo, $queries['sample_sql']) : [],
        ];
    }

    return $report;
}

function tenant_audit_output_text(array $report): string
{
    $lines = [];
    $lines[] = 'Tenant Preflight Audit';
    $lines[] = 'Database: ' . $report['database'];
    $lines[] = 'Generated At: ' . $report['generated_at'];
    $lines[] = '';
    $lines[] = '[Null company_id counts]';

    foreach ($report['null_company_id'] as $table => $count) {
        $lines[] = sprintf('- %s: %d', $table, (int) $count);
    }

    $lines[] = '';
    $lines[] = '[Cross-company mismatches]';
    foreach ($report['cross_company_mismatches'] as $key => $item) {
        $lines[] = sprintf('- %s: %d', $key, (int) ($item['count'] ?? 0));
        foreach ((array) ($item['sample_rows'] ?? []) as $row) {
            $lines[] = '  sample: ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    if (!empty($report['warnings'])) {
        $lines[] = '';
        $lines[] = '[Warnings]';
        foreach ($report['warnings'] as $warning) {
            $lines[] = '- ' . $warning;
        }
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function tenant_audit_issue_count(array $report): int
{
    $total = 0;

    foreach ((array) ($report['null_company_id'] ?? []) as $count) {
        $total += (int) $count;
    }

    foreach ((array) ($report['cross_company_mismatches'] ?? []) as $item) {
        $total += (int) ($item['count'] ?? 0);
    }

    return $total;
}
