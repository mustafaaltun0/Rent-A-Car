<?php

function getOpenBusinessAccountPeriod(PDO $pdo, ?int $companyId = null): array {
    ensureBusinessAccountsSchema($pdo);

    if ($companyId !== null && $companyId > 0) {
        $st = $pdo->prepare("SELECT * FROM ledger_periods WHERE company_id = ? AND status='OPEN' ORDER BY id DESC LIMIT 1");
        $st->execute([$companyId]);
        $period = $st->fetch();
        if ($period) {
            return $period;
        }

        $st = $pdo->prepare("INSERT INTO ledger_periods (company_id, label, started_at, status) VALUES (?, ?, NOW(), 'OPEN')");
        $st->execute([$companyId, 'Açık Hesap']);

        $st = $pdo->prepare("SELECT * FROM ledger_periods WHERE company_id = ? AND status='OPEN' ORDER BY id DESC LIMIT 1");
        $st->execute([$companyId]);
        return $st->fetch();
    }

    $period = $pdo->query("SELECT * FROM ledger_periods WHERE status='OPEN' ORDER BY id DESC LIMIT 1")->fetch();
    if ($period) {
        return $period;
    }

    $st = $pdo->prepare("INSERT INTO ledger_periods (label, started_at, status) VALUES (?, NOW(), 'OPEN')");
    $st->execute(['Açık Hesap']);
    return $pdo->query("SELECT * FROM ledger_periods WHERE status='OPEN' ORDER BY id DESC LIMIT 1")->fetch();
}

function ensureLegacyBusinessAccountPeriod(PDO $pdo, int $companyId, int $periodId): void {
    return;
}

function retireLegacyBusinessAccountTables(PDO $pdo): array
{
    ensureBusinessAccountsSchema($pdo);

    $migrationKey = '2026_06_retire_legacy_business_account_tables';
    $definitions = [
        'business_account_partners' => [
            'ledger_table' => 'ledger_partners',
            'ledger_id_column' => 'id',
            'backup_table' => 'legacy_backup_2026_06_business_account_partners',
            'require_ledger_match' => false,
        ],
        'business_account_periods' => [
            'ledger_table' => 'ledger_periods',
            'ledger_id_column' => 'id',
            'backup_table' => 'legacy_backup_2026_06_business_account_periods',
            'require_ledger_match' => false,
        ],
        'business_account_entries' => [
            'ledger_table' => 'ledger_entries',
            'ledger_id_column' => 'id',
            'backup_table' => 'legacy_backup_2026_06_business_account_entries',
            'require_ledger_match' => true,
        ],
    ];

    $retiredTables = [];

    foreach ($definitions as $legacyTable => $meta) {
        $backupTable = $meta['backup_table'];
        if (!auth_table_exists($pdo, $legacyTable)) {
            continue;
        }

        if (auth_table_exists($pdo, $backupTable)) {
            throw new RuntimeException('Yedek tablo zaten mevcut: ' . $backupTable);
        }

        $ledgerTable = $meta['ledger_table'];
        if (!auth_table_exists($pdo, $ledgerTable)) {
            throw new RuntimeException('Hedef ledger tablo bulunamadi: ' . $ledgerTable);
        }

        if (!empty($meta['require_ledger_match'])) {
            $missingSt = $pdo->query("
                SELECT COUNT(*)
                FROM {$legacyTable} legacy
                LEFT JOIN {$ledgerTable} ledger ON ledger.{$meta['ledger_id_column']} = legacy.id
                WHERE ledger.{$meta['ledger_id_column']} IS NULL
            ");
            $missingCount = (int) $missingSt->fetchColumn();
            if ($missingCount > 0) {
                throw new RuntimeException($legacyTable . ' tablosunda ledger tarafina tasinmamis ' . $missingCount . ' kayit var.');
            }
        }

        $pdo->exec("RENAME TABLE {$legacyTable} TO {$backupTable}");
        $retiredTables[] = $legacyTable;
    }

    if (!empty($retiredTables)) {
        auth_mark_migration($pdo, $migrationKey);
    }

    return $retiredTables;
}

function getBusinessAccountPartners(PDO $pdo, ?int $companyId = null): array {
    ensureBusinessAccountsSchema($pdo);
    if ($companyId !== null && $companyId > 0) {
        $st = $pdo->prepare('SELECT * FROM ledger_partners WHERE company_id = ? ORDER BY sort_order ASC, id ASC');
        $st->execute([$companyId]);
        return $st->fetchAll();
    }

    return $pdo->query('SELECT * FROM ledger_partners ORDER BY sort_order ASC, id ASC')->fetchAll();
}

function buildBusinessAccountSummary(array $partners, array $entries, float $manualSharedIncome = 0.0): array {
    $partnerSummary = [];
    $settlementPartnerCount = 0;
    $carryOverTotal = 0.0;

    foreach ($partners as $partner) {
        $partnerId = (int)$partner['id'];
        $isSettlementPartner = (int)($partner['is_settlement_partner'] ?? 0) === 1;
        if ($isSettlementPartner) {
            $settlementPartnerCount++;
        }

        $partnerSummary[$partnerId] = [
            'partner' => $partner,
            'income' => 0.0,
            'expense' => 0.0,
            'pooled_income' => 0.0,
            'pooled_expense' => 0.0,
            'share' => 0.0,
            'deserved' => 0.0,
            'balance' => 0.0,
        ];
    }

    $totalIncome = 0.0;
    $totalExpense = 0.0;
    $pooledIncomeTotal = 0.0;
    $pooledExpenseTotal = 0.0;

    foreach ($entries as $entry) {
        $amount = (float)($entry['amount'] ?? 0);
        $partnerId = (int)($entry['partner_id'] ?? 0);
        $type = $entry['type'] ?? 'income';
        $carLabel = mb_strtolower(trim((string) ($entry['car_label'] ?? '')), 'UTF-8');
        $note = mb_strtolower(trim((string) ($entry['note'] ?? '')), 'UTF-8');
        if (!isset($partnerSummary[$partnerId])) {
            continue;
        }

        $isSettlementPartner = (int)($partnerSummary[$partnerId]['partner']['is_settlement_partner'] ?? 0) === 1;

        if ($type === 'expense') {
            $partnerSummary[$partnerId]['expense'] += $amount;
            $totalExpense += $amount;
            if ($isSettlementPartner) {
                $partnerSummary[$partnerId]['pooled_expense'] += $amount;
                $pooledExpenseTotal += $amount;
            }
            continue;
        }

        $partnerSummary[$partnerId]['income'] += $amount;
        $totalIncome += $amount;
        if ($isSettlementPartner) {
            $partnerSummary[$partnerId]['pooled_income'] += $amount;
            $pooledIncomeTotal += $amount;
        }

        if (
            $type === 'income' &&
            ($carLabel === 'önceki hesaptan' || $carLabel === 'onceki hesaptan' || strpos($note, 'devir') !== false)
        ) {
            $carryOverTotal += $amount;
        }
    }

    $trackedIncomeTotal = max(0.0, $totalIncome - $pooledIncomeTotal);
    $trackedExpenseTotal = max(0.0, $totalExpense - $pooledExpenseTotal);
    $netPool = $pooledIncomeTotal - $pooledExpenseTotal;
    $distributablePool = ($totalIncome + max(0.0, $manualSharedIncome)) - $totalExpense;
    $sharePerPartner = $settlementPartnerCount > 0 ? ($distributablePool / $settlementPartnerCount) : 0.0;
    $deservedTotal = 0.0;
    $firmReceivableTotal = 0.0;
    $firmPayableTotal = 0.0;

    foreach ($partnerSummary as &$row) {
        $isSettlementPartner = (int)($row['partner']['is_settlement_partner'] ?? 0) === 1;
        $row['share'] = $isSettlementPartner ? $sharePerPartner : 0.0;
        $row['deserved'] = $isSettlementPartner ? $row['share'] : 0.0;
        $row['balance'] = $isSettlementPartner
            ? (($row['pooled_income'] - $row['pooled_expense']) - $row['share'])
            : ($row['income'] - $row['expense']);

        $deservedTotal += (float) $row['deserved'];
        if ((float) $row['balance'] > 0) {
            $firmReceivableTotal += (float) $row['balance'];
        } elseif ((float) $row['balance'] < 0) {
            $firmPayableTotal += abs((float) $row['balance']);
        }
    }
    unset($row);

    return [
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'pooled_income_total' => $pooledIncomeTotal,
        'pooled_expense_total' => $pooledExpenseTotal,
        'tracked_income_total' => $trackedIncomeTotal,
        'tracked_expense_total' => $trackedExpenseTotal,
        'net_pool' => $netPool,
        'manual_shared_income' => max(0.0, $manualSharedIncome),
        'distributable_pool' => $distributablePool,
        'share_per_partner' => $sharePerPartner,
        'deserved_total' => $deservedTotal,
        'carry_over_total' => $carryOverTotal,
        'firm_receivable_total' => $firmReceivableTotal,
        'firm_payable_total' => $firmPayableTotal,
        'firm_balance_total' => $firmReceivableTotal - $firmPayableTotal,
        'settlement_partner_count' => $settlementPartnerCount,
        'partners' => $partnerSummary,
    ];
}
