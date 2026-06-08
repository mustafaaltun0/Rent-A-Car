<?php
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return number_format((float)$value, 0, ',', '.') . ' TL';
}

function dt($value) {
    if (!$value) return '-';
    $ts = strtotime($value);
    return $ts ? date('d.m.Y H:i', $ts) : '-';
}

function d($value) {
    if (!$value) return '-';
    $ts = strtotime($value);
    return $ts ? date('d.m.Y', $ts) : '-';
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function allocateAmountByMonth($startDate, $endDate, float $amount): array {
    if (!$startDate) {
        return [];
    }

    $start = new DateTimeImmutable($startDate);
    $end = $endDate ? new DateTimeImmutable($endDate) : null;

    if ($end === null || $end <= $start) {
        return [$start->format('Y-m') => $amount];
    }

    $totalSeconds = $end->getTimestamp() - $start->getTimestamp();
    if ($totalSeconds <= 0) {
        return [$start->format('Y-m') => $amount];
    }

    $allocations = [];
    $cursor = $start;

    while ($cursor < $end) {
        $monthStart = $cursor->modify('first day of this month')->setTime(0, 0, 0);
        $nextMonth = $monthStart->modify('+1 month');
        $segmentEnd = $end < $nextMonth ? $end : $nextMonth;
        $segmentSeconds = $segmentEnd->getTimestamp() - $cursor->getTimestamp();
        $monthKey = $cursor->format('Y-m');

        if (!isset($allocations[$monthKey])) {
            $allocations[$monthKey] = 0.0;
        }

        $allocations[$monthKey] += $amount * ($segmentSeconds / $totalSeconds);
        $cursor = $segmentEnd;
    }

    return $allocations;
}

function addMonthlyAllocation(array &$target, array $allocations, int $index): void {
    foreach ($allocations as $monthKey => $amount) {
        if (!isset($target[$monthKey])) {
            $target[$monthKey] = [0, 0, 0, 0, 0];
        }
        $target[$monthKey][$index] += $amount;
    }
}

function getPartnerOptions(): array {
    return [];
}

function ensureCarOwnerSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    $ownerCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE 'owner_name'")->fetch();
    if (!$ownerCheck) {
        $pdo->exec("ALTER TABLE cars ADD COLUMN owner_name VARCHAR(100) NULL AFTER model");
    }

    $initialized = true;
}

function ensureCarTelematicsSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'telematics_enabled' => "ALTER TABLE cars ADD COLUMN telematics_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_name",
        'telematics_provider' => "ALTER TABLE cars ADD COLUMN telematics_provider VARCHAR(100) NULL AFTER telematics_enabled",
        'telematics_device_id' => "ALTER TABLE cars ADD COLUMN telematics_device_id VARCHAR(150) NULL AFTER telematics_provider",
        'telematics_last_odometer_km' => "ALTER TABLE cars ADD COLUMN telematics_last_odometer_km INT NULL AFTER telematics_device_id",
        'telematics_last_latitude' => "ALTER TABLE cars ADD COLUMN telematics_last_latitude DECIMAL(10,7) NULL AFTER telematics_last_odometer_km",
        'telematics_last_longitude' => "ALTER TABLE cars ADD COLUMN telematics_last_longitude DECIMAL(10,7) NULL AFTER telematics_last_latitude",
        'telematics_ignition_on' => "ALTER TABLE cars ADD COLUMN telematics_ignition_on TINYINT(1) NULL AFTER telematics_last_longitude",
        'telematics_last_sync_at' => "ALTER TABLE cars ADD COLUMN telematics_last_sync_at DATETIME NULL AFTER telematics_ignition_on",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $initialized = true;
}

function ensureCarTelematicsEventSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS car_telematics_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        car_id BIGINT NOT NULL,
        provider VARCHAR(100) NULL,
        device_id VARCHAR(150) NULL,
        odometer_km INT NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        ignition_on TINYINT(1) NULL,
        payload_json LONGTEXT NULL,
        recorded_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_car_telematics_events_company_car_recorded (company_id, car_id, recorded_at),
        KEY idx_car_telematics_events_provider_device (provider, device_id)
    )");

    $initialized = true;
}

function telematics_car_has_live_data(array $car): bool
{
    return !empty($car['telematics_enabled']) && (
        !empty($car['telematics_last_sync_at'])
        || $car['telematics_last_odometer_km'] !== null
        || ($car['telematics_last_latitude'] !== null && $car['telematics_last_longitude'] !== null)
    );
}

function rental_km_metrics(array $rental, ?array $car = null): array
{
    $departureKm = isset($rental['departure_km']) && $rental['departure_km'] !== '' && $rental['departure_km'] !== null ? (int) $rental['departure_km'] : null;
    $returnKm = isset($rental['return_km']) && $rental['return_km'] !== '' && $rental['return_km'] !== null ? (int) $rental['return_km'] : null;
    $telematicsKm = ($car && isset($car['telematics_last_odometer_km']) && $car['telematics_last_odometer_km'] !== null && $car['telematics_last_odometer_km'] !== '') ? (int) $car['telematics_last_odometer_km'] : null;
    $startAt = !empty($rental['start_date']) ? new DateTimeImmutable($rental['start_date']) : null;

    $endReference = null;
    if ($returnKm !== null && !empty($rental['end_date'])) {
        $endReference = new DateTimeImmutable($rental['end_date']);
    } elseif ((int) ($rental['completed'] ?? 0) === 1 && !empty($rental['end_date'])) {
        $endReference = new DateTimeImmutable($rental['end_date']);
    } elseif ($telematicsKm !== null) {
        $endReference = new DateTimeImmutable(date('Y-m-d H:i:s'));
    } elseif (!empty($rental['end_date'])) {
        $endReference = new DateTimeImmutable($rental['end_date']);
    }

    $distanceKm = null;
    $distanceSource = 'Veri yok';
    $effectiveEndKm = null;

    if ($departureKm !== null && $returnKm !== null) {
        $effectiveEndKm = $returnKm;
        $distanceKm = max(0, $returnKm - $departureKm);
        $distanceSource = 'Manuel teslim KM';
    } elseif ($departureKm !== null && $telematicsKm !== null && $telematicsKm >= $departureKm) {
        $effectiveEndKm = $telematicsKm;
        $distanceKm = max(0, $telematicsKm - $departureKm);
        $distanceSource = 'Telematik anlik KM';
    }

    $durationDays = null;
    if ($startAt && $endReference) {
        $durationSeconds = max(0, $endReference->getTimestamp() - $startAt->getTimestamp());
        $durationDays = max(1, (int) ceil($durationSeconds / 86400));
    }

    $averageDailyKm = ($distanceKm !== null && $durationDays && $durationDays > 0)
        ? round($distanceKm / $durationDays, 1)
        : null;

    return [
        'departure_km' => $departureKm,
        'effective_end_km' => $effectiveEndKm,
        'distance_km' => $distanceKm,
        'duration_days' => $durationDays,
        'average_daily_km' => $averageDailyKm,
        'distance_source' => $distanceSource,
        'telematics_last_odometer_km' => $telematicsKm,
        'is_live' => $returnKm === null && $telematicsKm !== null,
    ];
}

function buildCarMileageSummary(array $rentals, ?array $car = null): array
{
    $totalDistanceKm = 0.0;
    $totalDays = 0;
    $countedRentals = 0;
    $liveDistanceKm = 0.0;

    foreach ($rentals as $rental) {
        $metrics = rental_km_metrics($rental, $car);
        if ($metrics['distance_km'] === null || $metrics['duration_days'] === null) {
            continue;
        }

        $totalDistanceKm += (float) $metrics['distance_km'];
        $totalDays += (int) $metrics['duration_days'];
        $countedRentals++;

        if (!empty($metrics['is_live'])) {
            $liveDistanceKm += (float) $metrics['distance_km'];
        }
    }

    return [
        'total_distance_km' => $totalDistanceKm,
        'total_days' => $totalDays,
        'counted_rentals' => $countedRentals,
        'average_daily_km' => $totalDays > 0 ? round($totalDistanceKm / $totalDays, 1) : null,
        'live_distance_km' => $liveDistanceKm,
    ];
}

function ensureRentalArchiveSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'archived_at' => "ALTER TABLE rentals ADD COLUMN archived_at DATETIME NULL AFTER completed",
        'archived_by_user_id' => "ALTER TABLE rentals ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE rentals ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rentals LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $archiveIndexCheck = $pdo->query("SHOW INDEX FROM rentals WHERE Key_name = 'idx_rentals_company_archived'")->fetch();
    if (!$archiveIndexCheck) {
        $pdo->exec("ALTER TABLE rentals ADD INDEX idx_rentals_company_archived (company_id, archived_at)");
    }

    $initialized = true;
}

function rental_is_archived(array $rental): bool
{
    return !empty($rental['archived_at']);
}

function ensureCarArchiveSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'archived_at' => "ALTER TABLE cars ADD COLUMN archived_at DATETIME NULL AFTER available",
        'archived_by_user_id' => "ALTER TABLE cars ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE cars ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $archiveIndexCheck = $pdo->query("SHOW INDEX FROM cars WHERE Key_name = 'idx_cars_company_archived'")->fetch();
    if (!$archiveIndexCheck) {
        $pdo->exec("ALTER TABLE cars ADD INDEX idx_cars_company_archived (company_id, archived_at)");
    }

    $initialized = true;
}

function car_is_archived(array $car): bool
{
    return !empty($car['archived_at']);
}

function ensureExpenseArchiveSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'archived_at' => "ALTER TABLE business_expenses ADD COLUMN archived_at DATETIME NULL AFTER expense_date",
        'archived_by_user_id' => "ALTER TABLE business_expenses ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE business_expenses ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM business_expenses LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $archiveIndexCheck = $pdo->query("SHOW INDEX FROM business_expenses WHERE Key_name = 'idx_business_expenses_company_archived'")->fetch();
    if (!$archiveIndexCheck) {
        $pdo->exec("ALTER TABLE business_expenses ADD INDEX idx_business_expenses_company_archived (company_id, archived_at)");
    }

    $initialized = true;
}

function expense_is_archived(array $expense): bool
{
    return !empty($expense['archived_at']);
}

function ensureBusinessExpenseOwnerSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    $ownerCheck = $pdo->query("SHOW COLUMNS FROM business_expenses LIKE 'owner_name'")->fetch();
    if (!$ownerCheck) {
        $pdo->exec("ALTER TABLE business_expenses ADD COLUMN owner_name VARCHAR(100) NULL AFTER title");
    }

    $initialized = true;
}

function ensureBusinessAccountsSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_partners (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        is_settlement_partner TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_periods (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(150) NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        settled_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_entries (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        period_id BIGINT NOT NULL,
        partner_id BIGINT NULL,
        business_expense_id BIGINT NULL,
        type VARCHAR(20) NOT NULL,
        car_label VARCHAR(150) NULL,
        amount DOUBLE NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        entry_date DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_ledger_entries_period FOREIGN KEY (period_id) REFERENCES ledger_periods(id) ON DELETE CASCADE,
        CONSTRAINT fk_ledger_entries_partner FOREIGN KEY (partner_id) REFERENCES ledger_partners(id) ON DELETE SET NULL
    )");

    $openPeriodCheck = $pdo->query("SELECT id FROM ledger_periods WHERE status='OPEN' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$openPeriodCheck) {
        $st = $pdo->prepare("INSERT INTO ledger_periods (label, started_at, status) VALUES (?, NOW(), 'OPEN')");
        $st->execute(['Açık Hesap']);
    }

    try {
        $legacyPartnerCount = (int)$pdo->query("SELECT COUNT(*) FROM business_account_partners")->fetchColumn();
    } catch (Throwable $e) {
        $legacyPartnerCount = 0;
    }
    $ledgerPartnerCount = (int)$pdo->query("SELECT COUNT(*) FROM ledger_partners")->fetchColumn();
    if ($legacyPartnerCount > 0 && $ledgerPartnerCount === 0) {
        $pdo->exec("INSERT INTO ledger_partners (id, name, is_settlement_partner, sort_order, created_at)
                    SELECT id, name, is_settlement_partner, sort_order, created_at FROM business_account_partners");
    }

    try {
        $legacyPeriodCount = (int)$pdo->query("SELECT COUNT(*) FROM business_account_periods")->fetchColumn();
    } catch (Throwable $e) {
        $legacyPeriodCount = 0;
    }
    $ledgerPeriodCount = (int)$pdo->query("SELECT COUNT(*) FROM ledger_periods")->fetchColumn();
    if ($legacyPeriodCount > 0 && $ledgerPeriodCount <= 1) {
        $openRows = $pdo->query("SELECT id, status FROM ledger_periods ORDER BY id")->fetchAll();
        if (count($openRows) === 1 && ($openRows[0]['status'] ?? '') === 'OPEN') {
            $pdo->exec("DELETE FROM ledger_periods");
        }
        $pdo->exec("INSERT INTO ledger_periods (id, label, started_at, settled_at, status, created_at)
                    SELECT id, label, started_at, settled_at, status, created_at FROM business_account_periods");
    }

    try {
        $legacyEntryCount = (int)$pdo->query("SELECT COUNT(*) FROM business_account_entries")->fetchColumn();
    } catch (Throwable $e) {
        $legacyEntryCount = 0;
    }
    $ledgerEntryCount = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
    if ($legacyEntryCount > 0 && $ledgerEntryCount === 0) {
        $pdo->exec("INSERT INTO ledger_entries (id, period_id, partner_id, business_expense_id, type, car_label, amount, note, entry_date, created_at)
                    SELECT id, period_id, partner_id, business_expense_id, type, car_label, amount, note, entry_date, created_at FROM business_account_entries");
    }

    $initialized = true;
}

function ensureCustomerCompanySchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_companies (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        company_name VARCHAR(180) NOT NULL,
        contact_name VARCHAR(150) NULL,
        phone VARCHAR(30) NULL,
        email VARCHAR(150) NULL,
        tax_office VARCHAR(120) NULL,
        tax_number VARCHAR(30) NULL,
        address TEXT NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $customerCompanyIndexCheck = $pdo->query("SHOW INDEX FROM customer_companies WHERE Key_name = 'idx_customer_companies_company_id'")->fetch();
    if (!$customerCompanyIndexCheck) {
        $pdo->exec("ALTER TABLE customer_companies ADD INDEX idx_customer_companies_company_id (company_id)");
    }

    $rentalCustomerCompanyCheck = $pdo->query("SHOW COLUMNS FROM rentals LIKE 'customer_company_id'")->fetch();
    if (!$rentalCustomerCompanyCheck) {
        $pdo->exec("ALTER TABLE rentals ADD COLUMN customer_company_id BIGINT NULL AFTER company_id");
    }

    $rentalCustomerCompanyIndexCheck = $pdo->query("SHOW INDEX FROM rentals WHERE Key_name = 'idx_rentals_customer_company_id'")->fetch();
    if (!$rentalCustomerCompanyIndexCheck) {
        $pdo->exec("ALTER TABLE rentals ADD INDEX idx_rentals_customer_company_id (customer_company_id)");
    }

    $initialized = true;
}

function getCustomerCompanies(PDO $pdo, int $companyId, bool $onlyActive = false): array {
    ensureCustomerCompanySchema($pdo);

    $sql = 'SELECT * FROM customer_companies WHERE company_id = ?';
    $params = [$companyId];
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, company_name ASC, id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function getCustomerCompanyMap(PDO $pdo, int $companyId, bool $onlyActive = false): array {
    $rows = getCustomerCompanies($pdo, $companyId, $onlyActive);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) ($row['id'] ?? 0)] = $row;
    }

    return $map;
}

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
    if ($periodId <= 0) {
        return;
    }

    $legacySt = $pdo->prepare('SELECT id FROM business_account_periods WHERE id = ?');
    $legacySt->execute([$periodId]);
    if ($legacySt->fetch()) {
        return;
    }

    $ledgerSt = $pdo->prepare('SELECT id, label, started_at, settled_at, status, created_at FROM ledger_periods WHERE id = ? AND company_id = ?');
    $ledgerSt->execute([$periodId, $companyId]);
    $ledgerPeriod = $ledgerSt->fetch(PDO::FETCH_ASSOC);
    if (!$ledgerPeriod) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO business_account_periods (id, label, started_at, settled_at, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([
        (int) $ledgerPeriod['id'],
        $ledgerPeriod['label'],
        $ledgerPeriod['started_at'],
        $ledgerPeriod['settled_at'],
        $ledgerPeriod['status'],
        $ledgerPeriod['created_at'],
    ]);
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

function buildBusinessAccountSummary(array $partners, array $entries): array {
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
    $sharePerPartner = $settlementPartnerCount > 0 ? ($netPool / $settlementPartnerCount) : 0.0;
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

function ensureRentalExtensionSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    $checks = [
        ['initial_end_date', "ALTER TABLE rentals ADD COLUMN initial_end_date DATETIME NULL AFTER end_date"],
        ['customer_phone', "ALTER TABLE rentals ADD COLUMN customer_phone VARCHAR(30) NULL AFTER customer_name"],
        ['customer_identity_no', "ALTER TABLE rentals ADD COLUMN customer_identity_no VARCHAR(20) NULL AFTER customer_phone"],
        ['departure_km', "ALTER TABLE rentals ADD COLUMN departure_km INT NULL AFTER initial_end_date"],
        ['return_km', "ALTER TABLE rentals ADD COLUMN return_km INT NULL AFTER departure_km"],
    ];
    foreach ($checks as [$column, $sql]) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rentals LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'rental_extensions'")->fetch();
    if (!$tableCheck) {
        $pdo->exec("CREATE TABLE rental_extensions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT NULL,
            rental_id BIGINT NOT NULL,
            previous_end_date DATETIME NULL,
            new_end_date DATETIME NOT NULL,
            income DOUBLE NOT NULL DEFAULT 0,
            expense DOUBLE NOT NULL DEFAULT 0,
            net_profit DOUBLE NOT NULL DEFAULT 0,
            payment_status VARCHAR(20) NOT NULL DEFAULT 'collected',
            payment_due_date DATETIME NULL,
            collected_at DATETIME NULL,
            collected_by_user_id BIGINT NULL,
            extension_status VARCHAR(20) NOT NULL DEFAULT 'active',
            cancelled_at DATETIME NULL,
            cancelled_by_user_id BIGINT NULL,
            cancel_reason VARCHAR(255) NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_rental_extensions_rental FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
        )");
    }

    $columnMap = [
        'company_id' => "ALTER TABLE rental_extensions ADD COLUMN company_id BIGINT NULL AFTER id",
        'payment_status' => "ALTER TABLE rental_extensions ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'collected' AFTER net_profit",
        'payment_due_date' => "ALTER TABLE rental_extensions ADD COLUMN payment_due_date DATETIME NULL AFTER payment_status",
        'collected_at' => "ALTER TABLE rental_extensions ADD COLUMN collected_at DATETIME NULL AFTER payment_due_date",
        'collected_by_user_id' => "ALTER TABLE rental_extensions ADD COLUMN collected_by_user_id BIGINT NULL AFTER collected_at",
        'extension_status' => "ALTER TABLE rental_extensions ADD COLUMN extension_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER collected_by_user_id",
        'cancelled_at' => "ALTER TABLE rental_extensions ADD COLUMN cancelled_at DATETIME NULL AFTER extension_status",
        'cancelled_by_user_id' => "ALTER TABLE rental_extensions ADD COLUMN cancelled_by_user_id BIGINT NULL AFTER cancelled_at",
        'cancel_reason' => "ALTER TABLE rental_extensions ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by_user_id",
    ];
    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rental_extensions LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $companyBackfillCheck = $pdo->query("SHOW COLUMNS FROM rental_extensions LIKE 'company_id'")->fetch();
    if ($companyBackfillCheck) {
        $pdo->exec("UPDATE rental_extensions re INNER JOIN rentals r ON r.id = re.rental_id SET re.company_id = r.company_id WHERE re.company_id IS NULL");
    }

    $companyIndexCheck = $pdo->query("SHOW INDEX FROM rental_extensions WHERE Key_name = 'idx_rental_extensions_company_rental'")->fetch();
    if (!$companyIndexCheck) {
        $pdo->exec("ALTER TABLE rental_extensions ADD INDEX idx_rental_extensions_company_rental (company_id, rental_id)");
    }

    $collectionTableCheck = $pdo->query("SHOW TABLES LIKE 'rental_extension_collections'")->fetch();
    if (!$collectionTableCheck) {
        $pdo->exec("CREATE TABLE rental_extension_collections (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT NOT NULL,
            rental_extension_id BIGINT NOT NULL,
            amount DOUBLE NOT NULL DEFAULT 0,
            payment_method VARCHAR(30) NULL,
            collection_status VARCHAR(20) NOT NULL DEFAULT 'active',
            cancelled_at DATETIME NULL,
            cancelled_by_user_id BIGINT NULL,
            cancel_reason VARCHAR(255) NULL,
            collected_at DATETIME NOT NULL,
            collected_by_user_id BIGINT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rental_extension_collections_company_extension (company_id, rental_extension_id),
            CONSTRAINT fk_rental_extension_collections_extension FOREIGN KEY (rental_extension_id) REFERENCES rental_extensions(id) ON DELETE CASCADE
        )");
    }

    $collectionColumnCheck = $pdo->query("SHOW COLUMNS FROM rental_extension_collections LIKE 'payment_method'")->fetch();
    if (!$collectionColumnCheck) {
        $pdo->exec("ALTER TABLE rental_extension_collections ADD COLUMN payment_method VARCHAR(30) NULL AFTER amount");
    }

    $collectionColumnMap = [
        'collection_status' => "ALTER TABLE rental_extension_collections ADD COLUMN collection_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER payment_method",
        'cancelled_at' => "ALTER TABLE rental_extension_collections ADD COLUMN cancelled_at DATETIME NULL AFTER collection_status",
        'cancelled_by_user_id' => "ALTER TABLE rental_extension_collections ADD COLUMN cancelled_by_user_id BIGINT NULL AFTER cancelled_at",
        'cancel_reason' => "ALTER TABLE rental_extension_collections ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by_user_id",
    ];
    foreach ($collectionColumnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rental_extension_collections LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $revisionTableCheck = $pdo->query("SHOW TABLES LIKE 'rental_extension_revisions'")->fetch();
    if (!$revisionTableCheck) {
        $pdo->exec("CREATE TABLE rental_extension_revisions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT NOT NULL,
            rental_extension_id BIGINT NOT NULL,
            rental_id BIGINT NOT NULL,
            action_type VARCHAR(30) NOT NULL,
            payload_before LONGTEXT NULL,
            payload_after LONGTEXT NULL,
            created_by_user_id BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rental_extension_revisions_company_extension (company_id, rental_extension_id),
            KEY idx_rental_extension_revisions_company_rental (company_id, rental_id),
            CONSTRAINT fk_rental_extension_revisions_extension FOREIGN KEY (rental_extension_id) REFERENCES rental_extensions(id) ON DELETE CASCADE
        )");
    }

    $initialized = true;
}

function ensureRentalDocumentSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS document_sequences (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        document_type VARCHAR(50) NOT NULL,
        prefix VARCHAR(20) NOT NULL,
        next_number INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_document_sequences_company_type (company_id, document_type)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rental_documents (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        rental_id BIGINT NOT NULL,
        document_type VARCHAR(50) NOT NULL,
        document_number VARCHAR(50) NOT NULL,
        sequence_number INT NOT NULL,
        created_by_user_id BIGINT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_rental_documents_company_rental_type (company_id, rental_id, document_type),
        UNIQUE KEY uniq_rental_documents_company_type_seq (company_id, document_type, sequence_number),
        KEY idx_rental_documents_company_rental (company_id, rental_id),
        CONSTRAINT fk_rental_documents_rental FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
    )");

    $initialized = true;
}

function rental_document_type_catalog(): array {
    return [
        'rental_summary' => ['label' => 'Kiralama Ozeti', 'prefix' => 'KRL'],
        'collection_receipt' => ['label' => 'Tahsilat Makbuzu', 'prefix' => 'MKB'],
    ];
}

function rental_document_title(string $documentType): string {
    $catalog = rental_document_type_catalog();
    return $catalog[$documentType]['label'] ?? $documentType;
}

function rental_document_prefix(string $documentType): string {
    $catalog = rental_document_type_catalog();
    return $catalog[$documentType]['prefix'] ?? 'DOC';
}

function ensureDocumentSequenceRow(PDO $pdo, int $companyId, string $documentType): void {
    $prefix = rental_document_prefix($documentType);
    $insert = $pdo->prepare('INSERT IGNORE INTO document_sequences (company_id, document_type, prefix, next_number) VALUES (?, ?, ?, 1)');
    $insert->execute([$companyId, $documentType, $prefix]);
}

function rental_ensure_document(PDO $pdo, int $companyId, int $rentalId, string $documentType, int $createdByUserId = 0): array {
    ensureRentalDocumentSchema($pdo);

    $existing = $pdo->prepare('SELECT * FROM rental_documents WHERE company_id = ? AND rental_id = ? AND document_type = ? LIMIT 1');
    $existing->execute([$companyId, $rentalId, $documentType]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    ensureDocumentSequenceRow($pdo, $companyId, $documentType);

    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT * FROM rental_documents WHERE company_id = ? AND rental_id = ? AND document_type = ? LIMIT 1');
        $existing->execute([$companyId, $rentalId, $documentType]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->commit();
            return $row;
        }

        $sequenceSt = $pdo->prepare('SELECT id, prefix, next_number FROM document_sequences WHERE company_id = ? AND document_type = ? LIMIT 1 FOR UPDATE');
        $sequenceSt->execute([$companyId, $documentType]);
        $sequence = $sequenceSt->fetch(PDO::FETCH_ASSOC);
        if (!$sequence) {
            throw new RuntimeException('Belge sirasi bulunamadi.');
        }

        $sequenceNumber = max(1, (int) ($sequence['next_number'] ?? 1));
        $documentNumber = sprintf('%s-%s-%06d', $sequence['prefix'], date('Y'), $sequenceNumber);

        $insert = $pdo->prepare('INSERT INTO rental_documents (company_id, rental_id, document_type, document_number, sequence_number, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([
            $companyId,
            $rentalId,
            $documentType,
            $documentNumber,
            $sequenceNumber,
            $createdByUserId > 0 ? $createdByUserId : null,
        ]);

        $updateSequence = $pdo->prepare('UPDATE document_sequences SET next_number = ? WHERE id = ?');
        $updateSequence->execute([$sequenceNumber + 1, (int) $sequence['id']]);

        $documentId = (int) $pdo->lastInsertId();
        $pdo->commit();

        $fetchInserted = $pdo->prepare('SELECT * FROM rental_documents WHERE id = ? LIMIT 1');
        $fetchInserted->execute([$documentId]);
        return $fetchInserted->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function rental_extension_payment_status(array $extension): string {
    $status = strtolower(trim((string) ($extension['payment_status'] ?? 'collected')));
    return in_array($status, ['pending', 'partial', 'collected'], true) ? $status : 'collected';
}

function rental_extension_lifecycle_status(array $extension): string {
    $status = strtolower(trim((string) ($extension['extension_status'] ?? 'active')));
    return in_array($status, ['active', 'cancelled'], true) ? $status : 'active';
}

function rental_extension_is_active(array $extension): bool {
    return rental_extension_lifecycle_status($extension) === 'active';
}

function rental_extension_is_collected(array $extension): bool {
    return rental_extension_payment_status($extension) === 'collected';
}

function rental_extension_status_label(array $extension): string {
    if (!rental_extension_is_active($extension)) {
        return 'Iptal Edildi';
    }
    if (rental_extension_payment_status($extension) === 'partial') {
        return 'Parcali Tahsil Edildi';
    }
    return rental_extension_is_collected($extension) ? 'Tahsil Edildi' : 'Tahsilat Bekliyor';
}

function rental_extension_status_badge_class(array $extension): string {
    if (!rental_extension_is_active($extension)) {
        return 'bg-secondary';
    }
    if (rental_extension_payment_status($extension) === 'partial') {
        return 'bg-info text-dark';
    }
    return rental_extension_is_collected($extension) ? 'bg-success' : 'bg-warning text-dark';
}

function rental_extension_collection_lifecycle_status(array $collection): string {
    $status = strtolower(trim((string) ($collection['collection_status'] ?? 'active')));
    return in_array($status, ['active', 'cancelled'], true) ? $status : 'active';
}

function rental_extension_collection_is_active(array $collection): bool {
    return rental_extension_collection_lifecycle_status($collection) === 'active';
}

function rental_extension_active_collections(array $collections): array {
    return array_values(array_filter($collections, static function (array $collection): bool {
        return rental_extension_collection_is_active($collection);
    }));
}

function rental_extension_latest_active_collection(array $collections): ?array {
    $activeCollections = rental_extension_active_collections($collections);
    if (empty($activeCollections)) {
        return null;
    }

    usort($activeCollections, static function (array $left, array $right): int {
        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    return end($activeCollections) ?: null;
}

function rental_active_extensions(array $extensions): array {
    return array_values(array_filter($extensions, static function (array $extension): bool {
        return rental_extension_is_active($extension);
    }));
}

function rental_latest_active_extension(array $extensions): ?array {
    $activeExtensions = rental_active_extensions($extensions);
    if (empty($activeExtensions)) {
        return null;
    }

    usort($activeExtensions, static function (array $left, array $right): int {
        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    return end($activeExtensions) ?: null;
}

function rental_effective_end_date(array $rental, array $extensionsByRentalId): ?string {
    $rentalId = (int) ($rental['id'] ?? 0);
    $latestActiveExtension = rental_latest_active_extension($extensionsByRentalId[$rentalId] ?? []);
    if ($latestActiveExtension && !empty($latestActiveExtension['new_end_date'])) {
        return (string) $latestActiveExtension['new_end_date'];
    }

    return $rental['initial_end_date'] ?? $rental['end_date'] ?? null;
}

function rental_refresh_end_date(PDO $pdo, array $rental, array $extensionsByRentalId): void {
    $rentalId = (int) ($rental['id'] ?? 0);
    $companyId = (int) ($rental['company_id'] ?? 0);
    if ($rentalId <= 0 || $companyId <= 0) {
        return;
    }

    $effectiveEndDate = rental_effective_end_date($rental, $extensionsByRentalId);
    $update = $pdo->prepare('UPDATE rentals SET end_date = ? WHERE id = ? AND company_id = ?');
    $update->execute([$effectiveEndDate, $rentalId, $companyId]);
}

function getRentalExtensionCollectionsByExtensionId(PDO $pdo, ?int $companyId = null): array {
    ensureRentalExtensionSchema($pdo);
    $companyId = $companyId ?? (function_exists('auth_current_company_id') ? (int) auth_current_company_id() : 0);

    if ($companyId > 0) {
        $st = $pdo->prepare('SELECT * FROM rental_extension_collections WHERE company_id = ? ORDER BY rental_extension_id ASC, collected_at ASC, id ASC');
        $st->execute([$companyId]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM rental_extension_collections ORDER BY rental_extension_id ASC, collected_at ASC, id ASC')->fetchAll();
    }

    $grouped = [];
    foreach ($rows as $row) {
        $extensionId = (int) ($row['rental_extension_id'] ?? 0);
        if (!isset($grouped[$extensionId])) {
            $grouped[$extensionId] = [];
        }
        $grouped[$extensionId][] = $row;
    }

    return $grouped;
}

function getRentalExtensionRevisionsByExtensionId(PDO $pdo, ?int $companyId = null): array {
    ensureRentalExtensionSchema($pdo);
    $companyId = $companyId ?? (function_exists('auth_current_company_id') ? (int) auth_current_company_id() : 0);

    if ($companyId > 0) {
        $st = $pdo->prepare('SELECT * FROM rental_extension_revisions WHERE company_id = ? ORDER BY rental_extension_id ASC, created_at ASC, id ASC');
        $st->execute([$companyId]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM rental_extension_revisions ORDER BY rental_extension_id ASC, created_at ASC, id ASC')->fetchAll();
    }

    $grouped = [];
    foreach ($rows as $row) {
        $extensionId = (int) ($row['rental_extension_id'] ?? 0);
        if (!isset($grouped[$extensionId])) {
            $grouped[$extensionId] = [];
        }
        $grouped[$extensionId][] = $row;
    }

    return $grouped;
}

function rental_extension_collected_amount(array $extension, array $collectionsByExtensionId = []): float {
    $extensionId = (int) ($extension['id'] ?? 0);
    $income = max(0.0, (float) ($extension['income'] ?? 0));

    if (!rental_extension_is_active($extension)) {
        return 0.0;
    }

    if (isset($collectionsByExtensionId[$extensionId])) {
        $collectedAmount = 0.0;
        foreach ($collectionsByExtensionId[$extensionId] as $collection) {
            if (!rental_extension_collection_is_active($collection)) {
                continue;
            }
            $collectedAmount += max(0.0, (float) ($collection['amount'] ?? 0));
        }
        return min($income, $collectedAmount);
    }

    return rental_extension_is_collected($extension) ? $income : 0.0;
}

function rental_extension_pending_amount(array $extension, array $collectionsByExtensionId = []): float {
    $income = max(0.0, (float) ($extension['income'] ?? 0));
    return max(0.0, $income - rental_extension_collected_amount($extension, $collectionsByExtensionId));
}

function rental_extension_effective_payment_status(array $extension, array $collectionsByExtensionId = []): string {
    if (!rental_extension_is_active($extension)) {
        return 'cancelled';
    }

    $income = max(0.0, (float) ($extension['income'] ?? 0));
    $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
    if ($income <= 0.0) {
        return 'collected';
    }
    if ($collectedAmount <= 0.0) {
        return 'pending';
    }
    if ($collectedAmount + 0.0001 >= $income) {
        return 'collected';
    }
    return 'partial';
}

function rental_extension_record_revision(PDO $pdo, int $companyId, int $rentalId, int $extensionId, string $actionType, ?array $payloadBefore, ?array $payloadAfter, int $createdByUserId = 0): void {
    ensureRentalExtensionSchema($pdo);

    $insert = $pdo->prepare('INSERT INTO rental_extension_revisions (company_id, rental_extension_id, rental_id, action_type, payload_before, payload_after, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        $companyId,
        $extensionId,
        $rentalId,
        $actionType,
        $payloadBefore !== null ? json_encode($payloadBefore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $payloadAfter !== null ? json_encode($payloadAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $createdByUserId > 0 ? $createdByUserId : null,
    ]);
}

function getRentalExtensionsByRentalId(PDO $pdo, ?int $companyId = null): array {
    ensureRentalExtensionSchema($pdo);
    $companyId = $companyId ?? (function_exists('auth_current_company_id') ? (int) auth_current_company_id() : 0);

    if ($companyId > 0) {
        $st = $pdo->prepare('SELECT * FROM rental_extensions WHERE company_id = ? ORDER BY rental_id ASC, id ASC');
        $st->execute([$companyId]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM rental_extensions ORDER BY rental_id ASC, id ASC')->fetchAll();
    }

    $grouped = [];
    foreach ($rows as $row) {
        $rentalId = (int)$row['rental_id'];
        if (!isset($grouped[$rentalId])) {
            $grouped[$rentalId] = [];
        }
        $grouped[$rentalId][] = $row;
    }
    return $grouped;
}

function getRentalTotals(array $rental, array $extensionsByRentalId, array $collectionsByExtensionId = []): array {
    $rentalId = (int)($rental['id'] ?? 0);
    $income = (float)($rental['income'] ?? 0);
    $expense = (float)($rental['expense'] ?? 0);
    $pendingIncome = 0.0;
    $pendingExpense = 0.0;

    foreach ($extensionsByRentalId[$rentalId] ?? [] as $extension) {
        if (!rental_extension_is_active($extension)) {
            continue;
        }
        $extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
        $extensionExpense = max(0.0, (float) ($extension['expense'] ?? 0));
        $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
        $remainingAmount = max(0.0, $extensionIncome - $collectedAmount);
        $collectionRatio = $extensionIncome > 0.0 ? min(1.0, $collectedAmount / $extensionIncome) : 1.0;
        $collectedExpense = $extensionExpense * $collectionRatio;
        $remainingExpense = max(0.0, $extensionExpense - $collectedExpense);

        $income += $collectedAmount;
        $expense += $collectedExpense;
        $pendingIncome += $remainingAmount;
        $pendingExpense += $remainingExpense;
    }

    return [
        'income' => $income,
        'expense' => $expense,
        'net_profit' => $income - $expense,
        'pending_income' => $pendingIncome,
        'pending_expense' => $pendingExpense,
        'pending_net_profit' => $pendingIncome - $pendingExpense,
        'contract_income' => $income + $pendingIncome,
        'contract_expense' => $expense + $pendingExpense,
        'contract_net_profit' => ($income + $pendingIncome) - ($expense + $pendingExpense),
    ];
}

function buildRentalExtensionReceivableWarnings(array $rentals, array $extensionsByRentalId, array $collectionsByExtensionId = [], int $reminderDays = 1): array
{
    $today = new DateTimeImmutable(date('Y-m-d'));
    $items = [];
    $byRentalId = [];

    foreach ($rentals as $rental) {
        if ((int) ($rental['completed'] ?? 0) === 1) {
            continue;
        }

        $rentalId = (int) ($rental['id'] ?? 0);
        foreach ($extensionsByRentalId[$rentalId] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }

            $pendingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);
            $paymentDueDate = trim((string) ($extension['payment_due_date'] ?? ''));
            if ($pendingAmount <= 0.0 || $paymentDueDate === '') {
                continue;
            }

            try {
                $dueDate = new DateTimeImmutable(date('Y-m-d', strtotime($paymentDueDate)));
            } catch (Throwable $exception) {
                continue;
            }

            $daysLeft = (int) $today->diff($dueDate)->format('%r%a');
            if ($daysLeft > $reminderDays) {
                continue;
            }

            $customerName = trim((string) ($rental['customer_name'] ?? 'Musteri'));
            $level = $daysLeft < 0 ? 'danger' : 'warning';

            if ($daysLeft < 0) {
                $message = $customerName . ' uzatmasinda bekleyen ' . money($pendingAmount) . ' tahsilat ' . abs($daysLeft) . ' gun gecikti.';
                $shortLabel = 'Tahsilat gecikti';
            } elseif ($daysLeft === 0) {
                $message = $customerName . ' uzatmasinda bekleyen ' . money($pendingAmount) . ' tahsilat bugun alinmali.';
                $shortLabel = 'Bugun tahsilat';
            } else {
                $message = $customerName . ' uzatmasinda bekleyen ' . money($pendingAmount) . ' tahsilat yarin alinmali.';
                $shortLabel = 'Yarin tahsilat';
            }

            $item = [
                'rental_id' => $rentalId,
                'extension_id' => (int) ($extension['id'] ?? 0),
                'pending_amount' => $pendingAmount,
                'due_date' => $paymentDueDate,
                'days_left' => $daysLeft,
                'level' => $level,
                'short_label' => $shortLabel,
                'message' => $message,
            ];

            $items[] = $item;
            $byRentalId[$rentalId][] = $item;
        }
    }

    usort($items, static function (array $left, array $right): int {
        $leftPriority = $left['days_left'] < 0 ? 0 : ($left['days_left'] === 0 ? 1 : 2);
        $rightPriority = $right['days_left'] < 0 ? 0 : ($right['days_left'] === 0 ? 1 : 2);
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string) ($left['due_date'] ?? ''), (string) ($right['due_date'] ?? ''));
    });

    foreach ($byRentalId as &$rentalItems) {
        usort($rentalItems, static function (array $left, array $right): int {
            return ($left['days_left'] ?? 0) <=> ($right['days_left'] ?? 0);
        });
    }
    unset($rentalItems);

    return [
        'items' => $items,
        'by_rental_id' => $byRentalId,
    ];
}

function buildRentalCollectionCenterData(array $rentals, array $extensionsByRentalId, array $collectionsByExtensionId = []): array
{
    $today = new DateTimeImmutable(date('Y-m-d'));
    $pendingItems = [];
    $recentCollections = [];
    $summary = [
        'overdue_amount' => 0.0,
        'due_today_amount' => 0.0,
        'upcoming_amount' => 0.0,
        'pending_total' => 0.0,
        'active_pending_count' => 0,
        'collected_this_month' => 0.0,
        'collected_this_month_count' => 0,
    ];

    foreach ($rentals as $rental) {
        $rentalId = (int) ($rental['id'] ?? 0);
        $carLabel = trim((string) (($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? '')));
        foreach ($extensionsByRentalId[$rentalId] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }

            $extensionId = (int) ($extension['id'] ?? 0);
            $pendingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);
            $paymentDueDate = trim((string) ($extension['payment_due_date'] ?? ''));

            if ($pendingAmount > 0.0) {
                $daysLeft = null;
                $urgency = 'muted';
                if ($paymentDueDate !== '') {
                    try {
                        $dueDate = new DateTimeImmutable(date('Y-m-d', strtotime($paymentDueDate)));
                        $daysLeft = (int) $today->diff($dueDate)->format('%r%a');
                        if ($daysLeft < 0) {
                            $urgency = 'danger';
                            $summary['overdue_amount'] += $pendingAmount;
                        } elseif ($daysLeft === 0) {
                            $urgency = 'warning';
                            $summary['due_today_amount'] += $pendingAmount;
                        } else {
                            $urgency = 'info';
                            $summary['upcoming_amount'] += $pendingAmount;
                        }
                    } catch (Throwable $exception) {
                        $daysLeft = null;
                    }
                }

                $pendingItems[] = [
                    'rental_id' => $rentalId,
                    'extension_id' => $extensionId,
                    'customer_name' => (string) ($rental['customer_name'] ?? 'Musteri'),
                    'car_label' => $carLabel !== '' ? $carLabel : 'Arac bilgisi yok',
                    'pending_amount' => $pendingAmount,
                    'due_date' => $paymentDueDate !== '' ? $paymentDueDate : null,
                    'days_left' => $daysLeft,
                    'urgency' => $urgency,
                    'collected_amount' => rental_extension_collected_amount($extension, $collectionsByExtensionId),
                    'contract_amount' => max(0.0, (float) ($extension['income'] ?? 0)),
                ];
                $summary['pending_total'] += $pendingAmount;
                $summary['active_pending_count']++;
            }

            foreach ($collectionsByExtensionId[$extensionId] ?? [] as $collection) {
                if (!rental_extension_collection_is_active($collection)) {
                    continue;
                }

                $collectedAt = $collection['collected_at'] ?? null;
                if (!$collectedAt) {
                    continue;
                }

                $collectionAmount = max(0.0, (float) ($collection['amount'] ?? 0));
                if (date('Y-m', strtotime($collectedAt)) === date('Y-m')) {
                    $summary['collected_this_month'] += $collectionAmount;
                    $summary['collected_this_month_count']++;
                }

                $recentCollections[] = [
                    'rental_id' => $rentalId,
                    'extension_id' => $extensionId,
                    'collection_id' => (int) ($collection['id'] ?? 0),
                    'customer_name' => (string) ($rental['customer_name'] ?? 'Musteri'),
                    'car_label' => $carLabel !== '' ? $carLabel : 'Arac bilgisi yok',
                    'amount' => $collectionAmount,
                    'collected_at' => $collectedAt,
                    'payment_method' => (string) ($collection['payment_method'] ?? ''),
                    'note' => (string) ($collection['note'] ?? ''),
                ];
            }
        }
    }

    usort($pendingItems, static function (array $left, array $right): int {
        $leftPriority = $left['days_left'] === null ? 3 : ($left['days_left'] < 0 ? 0 : ($left['days_left'] === 0 ? 1 : 2));
        $rightPriority = $right['days_left'] === null ? 3 : ($right['days_left'] < 0 ? 0 : ($right['days_left'] === 0 ? 1 : 2));
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }
        return strcmp((string) ($left['due_date'] ?? ''), (string) ($right['due_date'] ?? ''));
    });

    usort($recentCollections, static function (array $left, array $right): int {
        return strcmp((string) ($right['collected_at'] ?? ''), (string) ($left['collected_at'] ?? ''));
    });

    return [
        'summary' => $summary,
        'pending_items' => $pendingItems,
        'recent_collections' => array_slice($recentCollections, 0, 20),
    ];
}

function buildRentalMonthlyData(array $rentals, array $extensionsByRentalId, array $collectionsByExtensionId = []): array {
    $monthly = [];

    foreach ($rentals as $rental) {
        $startDate = $rental['start_date'] ?? null;
        $initialEndDate = $rental['initial_end_date'] ?? ($rental['end_date'] ?? null);

        addMonthlyAllocation($monthly, allocateAmountByMonth($startDate, $initialEndDate, (float)($rental['income'] ?? 0)), 0);
        addMonthlyAllocation($monthly, allocateAmountByMonth($startDate, $initialEndDate, (float)($rental['expense'] ?? 0)), 1);

        foreach ($extensionsByRentalId[(int)($rental['id'] ?? 0)] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }
            $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
            if ($collectedAmount <= 0.0) {
                continue;
            }
            $segmentStart = $extension['previous_end_date'] ?? $startDate;
            $segmentEnd = $extension['new_end_date'] ?? null;
            $extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
            $extensionExpense = max(0.0, (float) ($extension['expense'] ?? 0));
            $collectionRatio = $extensionIncome > 0.0 ? min(1.0, $collectedAmount / $extensionIncome) : 1.0;
            addMonthlyAllocation($monthly, allocateAmountByMonth($segmentStart, $segmentEnd, $collectedAmount), 0);
            addMonthlyAllocation($monthly, allocateAmountByMonth($segmentStart, $segmentEnd, $extensionExpense * $collectionRatio), 1);
        }
    }

    ksort($monthly);
    foreach ($monthly as &$values) {
        if (!isset($values[2])) $values[2] = 0.0;
        $values[3] = ($values[1] ?? 0) + ($values[2] ?? 0);
        $values[4] = ($values[0] ?? 0) - ($values[3] ?? 0);
    }
    unset($values);

    return $monthly;
}

function calculateOverlapSeconds(DateTimeImmutable $segStart, DateTimeImmutable $segEnd, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): int {
    $startTs = max($segStart->getTimestamp(), $rangeStart->getTimestamp());
    $endTs = min($segEnd->getTimestamp(), $rangeEnd->getTimestamp());
    return max(0, $endTs - $startTs);
}

function buildCarPeriodProfitSummary(array $rentals, array $extensionsByRentalId, array $selectedCarIds, array $periodRanges, array $collectionsByExtensionId = []): array {
    $selectedMap = array_fill_keys(array_map('intval', $selectedCarIds), true);
    $summary = [];
    foreach ($selectedCarIds as $carId) {
        $summary[(int)$carId] = [];
        foreach ($periodRanges as $key => $_range) {
            $summary[(int)$carId][$key] = ['income' => 0.0, 'expense' => 0.0, 'net_profit' => 0.0];
        }
    }

    foreach ($rentals as $rental) {
        $carId = (int)($rental['car_id'] ?? 0);
        if (!isset($selectedMap[$carId])) {
            continue;
        }

        $segments = [];
        $start = $rental['start_date'] ?? null;
        $initialEnd = $rental['initial_end_date'] ?? ($rental['end_date'] ?? null);
        if ($start) {
            $segments[] = [
                'start' => $start,
                'end' => $initialEnd,
                'income' => (float)($rental['income'] ?? 0),
                'expense' => (float)($rental['expense'] ?? 0),
            ];
        }

        foreach ($extensionsByRentalId[(int)($rental['id'] ?? 0)] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }
            $extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
            $extensionExpense = max(0.0, (float) ($extension['expense'] ?? 0));
            $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
            if ($collectedAmount <= 0.0) {
                continue;
            }
            $collectionRatio = $extensionIncome > 0.0 ? min(1.0, $collectedAmount / $extensionIncome) : 1.0;
            $segments[] = [
                'start' => $extension['previous_end_date'] ?? $start,
                'end' => $extension['new_end_date'] ?? null,
                'income' => $collectedAmount,
                'expense' => $extensionExpense * $collectionRatio,
            ];
        }

        foreach ($segments as $segment) {
            if (empty($segment['start'])) {
                continue;
            }
            $segStart = new DateTimeImmutable($segment['start']);
            $segEnd = !empty($segment['end']) ? new DateTimeImmutable($segment['end']) : $segStart;
            if ($segEnd <= $segStart) {
                foreach ($periodRanges as $periodKey => [$rangeStart, $rangeEnd]) {
                    if ($segStart >= $rangeStart && $segStart < $rangeEnd) {
                        $summary[$carId][$periodKey]['income'] += $segment['income'];
                        $summary[$carId][$periodKey]['expense'] += $segment['expense'];
                        $summary[$carId][$periodKey]['net_profit'] += $segment['income'] - $segment['expense'];
                    }
                }
                continue;
            }

            $segmentSeconds = $segEnd->getTimestamp() - $segStart->getTimestamp();
            foreach ($periodRanges as $periodKey => [$rangeStart, $rangeEnd]) {
                $overlap = calculateOverlapSeconds($segStart, $segEnd, $rangeStart, $rangeEnd);
                if ($overlap <= 0 || $segmentSeconds <= 0) {
                    continue;
                }
                $ratio = $overlap / $segmentSeconds;
                $income = $segment['income'] * $ratio;
                $expense = $segment['expense'] * $ratio;
                $summary[$carId][$periodKey]['income'] += $income;
                $summary[$carId][$periodKey]['expense'] += $expense;
                $summary[$carId][$periodKey]['net_profit'] += $income - $expense;
            }
        }
    }

    return $summary;
}

function ensureNotificationSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

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

    $initialized = true;
}

function notifications_open_count(PDO $pdo, int $companyId): int
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE company_id = ? AND status = 'open'");
    $st->execute([$companyId]);
    return (int) $st->fetchColumn();
}

function notifications_summary(PDO $pdo, int $companyId): array
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS read_count,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN status = 'open' AND severity = 'danger' THEN 1 ELSE 0 END) AS critical_open_count
        FROM notifications
        WHERE company_id = ?
    ");
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'open_count' => (int) ($row['open_count'] ?? 0),
        'read_count' => (int) ($row['read_count'] ?? 0),
        'resolved_count' => (int) ($row['resolved_count'] ?? 0),
        'critical_open_count' => (int) ($row['critical_open_count'] ?? 0),
    ];
}

function notifications_fetch(PDO $pdo, int $companyId, string $status = 'open', int $limit = 100): array
{
    ensureNotificationSchema($pdo);
    $limit = max(1, min(250, $limit));
    $allowedStatuses = ['open', 'read', 'resolved', 'active'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'open';
    }

    if ($status === 'active') {
        $st = $pdo->prepare("
            SELECT *
            FROM notifications
            WHERE company_id = ? AND status <> 'resolved'
            ORDER BY
                CASE status
                    WHEN 'open' THEN 0
                    WHEN 'read' THEN 1
                    ELSE 2
                END,
                CASE severity
                    WHEN 'danger' THEN 0
                    WHEN 'warning' THEN 1
                    ELSE 2
                END,
                COALESCE(due_at, created_at) ASC,
                id DESC
            LIMIT {$limit}
        ");
        $st->execute([$companyId]);
    } else {
        $st = $pdo->prepare("
            SELECT *
            FROM notifications
            WHERE company_id = ? AND status = ?
            ORDER BY
                CASE severity
                    WHEN 'danger' THEN 0
                    WHEN 'warning' THEN 1
                    ELSE 2
                END,
                COALESCE(due_at, created_at) ASC,
                id DESC
            LIMIT {$limit}
        ");
        $st->execute([$companyId, $status]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['metadata'] = [];
        if (!empty($row['metadata_json'])) {
            try {
                $decoded = json_decode((string) $row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $row['metadata'] = $decoded;
                }
            } catch (Throwable $exception) {
                $row['metadata'] = [];
            }
        }
    }
    unset($row);

    return $rows;
}

function notification_target_url(array $notification): ?string
{
    $entityType = (string) ($notification['entity_type'] ?? '');
    $entityId = (int) ($notification['entity_id'] ?? 0);
    $metadata = $notification['metadata'] ?? [];

    if ($entityType === 'rental' && $entityId > 0) {
        return 'rental_detail.php?id=' . $entityId;
    }

    if ($entityType === 'rental_extension' && !empty($metadata['rental_id'])) {
        return 'rental_detail.php?id=' . (int) $metadata['rental_id'] . '#extension-' . $entityId;
    }

    if ($entityType === 'car' && $entityId > 0) {
        return 'car_detail.php?id=' . $entityId;
    }

    return null;
}

function notifications_mark_read(PDO $pdo, int $companyId, int $notificationId): bool
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = CASE WHEN status = 'open' THEN 'read' ELSE status END,
            read_at = CASE WHEN status = 'open' THEN NOW() ELSE read_at END,
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND status = 'open'
    ");
    $st->execute([$notificationId, $companyId]);
    return $st->rowCount() > 0;
}

function notifications_mark_all_read(PDO $pdo, int $companyId): int
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = 'read',
            read_at = COALESCE(read_at, NOW()),
            updated_at = NOW()
        WHERE company_id = ? AND status = 'open'
    ");
    $st->execute([$companyId]);
    return $st->rowCount();
}

function notifications_resolve(PDO $pdo, int $companyId, int $notificationId): bool
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = 'resolved',
            read_at = COALESCE(read_at, NOW()),
            resolved_at = NOW(),
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND status <> 'resolved'
    ");
    $st->execute([$notificationId, $companyId]);
    return $st->rowCount() > 0;
}

function notifications_upsert_system(PDO $pdo, int $companyId, array $payload): void
{
    ensureNotificationSchema($pdo);

    $notificationKey = trim((string) ($payload['notification_key'] ?? ''));
    if ($notificationKey === '') {
        return;
    }

    $metadataJson = null;
    if (isset($payload['metadata']) && is_array($payload['metadata'])) {
        try {
            $metadataJson = json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $metadataJson = null;
        }
    }

    $st = $pdo->prepare("
        INSERT INTO notifications (
            company_id, notification_key, source_type, event_type, entity_type, entity_id,
            severity, status, title, message, due_at, first_seen_at, last_seen_at,
            metadata_json, created_at, updated_at
        ) VALUES (
            ?, ?, 'system', ?, ?, ?, ?, 'open', ?, ?, ?, NOW(), NOW(), ?, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            event_type = VALUES(event_type),
            entity_type = VALUES(entity_type),
            entity_id = VALUES(entity_id),
            severity = VALUES(severity),
            title = VALUES(title),
            message = VALUES(message),
            due_at = VALUES(due_at),
            last_seen_at = NOW(),
            metadata_json = VALUES(metadata_json),
            updated_at = NOW(),
            status = CASE WHEN notifications.status = 'resolved' THEN 'open' ELSE notifications.status END,
            resolved_at = CASE WHEN notifications.status = 'resolved' THEN NULL ELSE notifications.resolved_at END
    ");

    $st->execute([
        $companyId,
        $notificationKey,
        (string) ($payload['event_type'] ?? 'system.notice'),
        $payload['entity_type'] ?? null,
        isset($payload['entity_id']) ? (int) $payload['entity_id'] : null,
        (string) ($payload['severity'] ?? 'info'),
        (string) ($payload['title'] ?? 'Bildirim'),
        (string) ($payload['message'] ?? ''),
        $payload['due_at'] ?? null,
        $metadataJson,
    ]);
}

function notifications_resolve_missing_system(PDO $pdo, int $companyId, array $activeKeys): void
{
    ensureNotificationSchema($pdo);

    if (empty($activeKeys)) {
        $st = $pdo->prepare("
            UPDATE notifications
            SET status = 'resolved',
                resolved_at = COALESCE(resolved_at, NOW()),
                updated_at = NOW()
            WHERE company_id = ? AND source_type = 'system' AND status <> 'resolved'
        ");
        $st->execute([$companyId]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($activeKeys), '?'));
    $params = array_merge([$companyId], $activeKeys);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = 'resolved',
            resolved_at = COALESCE(resolved_at, NOW()),
            updated_at = NOW()
        WHERE company_id = ?
          AND source_type = 'system'
          AND status <> 'resolved'
          AND notification_key NOT IN ($placeholders)
    ");
    $st->execute($params);
}

function notifications_sync_operational(PDO $pdo, int $companyId): array
{
    ensureNotificationSchema($pdo);
    ensureRentalExtensionSchema($pdo);
    ensureRentalArchiveSchema($pdo);
    ensureCarArchiveSchema($pdo);

    $activeKeys = [];
    $today = new DateTimeImmutable(date('Y-m-d'));
    $now = new DateTimeImmutable(date('Y-m-d H:i:s'));

    $carsSt = $pdo->prepare("
        SELECT id, brand, model, plate, inspection_date, insurance_date, maintenance_date
        FROM cars
        WHERE company_id = ? AND archived_at IS NULL
    ");
    $carsSt->execute([$companyId]);
    $cars = $carsSt->fetchAll(PDO::FETCH_ASSOC);

    $carDateFields = [
        'inspection_date' => ['event' => 'car.inspection_due', 'title' => 'Muayene takibi', 'label' => 'muayenesi'],
        'insurance_date' => ['event' => 'car.insurance_due', 'title' => 'Sigorta takibi', 'label' => 'sigortasi'],
        'maintenance_date' => ['event' => 'car.maintenance_due', 'title' => 'Bakim takibi', 'label' => 'bakimi'],
    ];

    foreach ($cars as $car) {
        $carId = (int) ($car['id'] ?? 0);
        $carLabel = trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '') . ' - ' . ($car['plate'] ?? ''));

        foreach ($carDateFields as $field => $meta) {
            $rawDate = trim((string) ($car[$field] ?? ''));
            if ($rawDate === '') {
                continue;
            }

            try {
                $targetDate = new DateTimeImmutable(date('Y-m-d', strtotime($rawDate)));
            } catch (Throwable $exception) {
                continue;
            }

            $daysLeft = (int) $today->diff($targetDate)->format('%r%a');
            if ($daysLeft > 30) {
                continue;
            }

            $severity = $daysLeft < 0 ? 'danger' : 'warning';
            if ($daysLeft < 0) {
                $message = $carLabel . ' aracinin ' . $meta['label'] . ' ' . abs($daysLeft) . ' gun gecmis durumda.';
            } elseif ($daysLeft === 0) {
                $message = $carLabel . ' aracinin ' . $meta['label'] . ' bugun doluyor.';
            } else {
                $message = $carLabel . ' aracinin ' . $meta['label'] . ' ' . $daysLeft . ' gun icinde dolacak.';
            }

            $key = 'car:' . $field . ':' . $carId;
            $activeKeys[] = $key;
            notifications_upsert_system($pdo, $companyId, [
                'notification_key' => $key,
                'event_type' => $meta['event'],
                'entity_type' => 'car',
                'entity_id' => $carId,
                'severity' => $severity,
                'title' => $meta['title'],
                'message' => $message,
                'due_at' => $targetDate->format('Y-m-d 00:00:00'),
                'metadata' => ['car_id' => $carId, 'days_left' => $daysLeft],
            ]);
        }
    }

    $rentalsSt = $pdo->prepare("
      SELECT
        r.id,
        r.customer_name,
        r.completed,
        r.end_date,
        c.brand,
        c.model,
        c.plate,
        COALESCE(MAX(re.new_end_date), r.end_date) AS effective_end_date
      FROM rentals r
      LEFT JOIN cars c ON c.id = r.car_id AND c.company_id = r.company_id
      LEFT JOIN rental_extensions re ON re.rental_id = r.id AND re.extension_status = 'active'
      WHERE r.company_id = ? AND r.archived_at IS NULL AND r.completed = 0
      GROUP BY r.id, r.customer_name, r.completed, r.end_date, c.brand, c.model, c.plate
      HAVING effective_end_date IS NOT NULL
    ");
    $rentalsSt->execute([$companyId]);
    $rentals = $rentalsSt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rentals as $rental) {
        $effectiveEndDate = $rental['effective_end_date'] ?? null;
        if (!$effectiveEndDate) {
            continue;
        }

        try {
            $endDateTime = new DateTimeImmutable(date('Y-m-d H:i:s', strtotime($effectiveEndDate)));
            $endDate = new DateTimeImmutable($endDateTime->format('Y-m-d'));
        } catch (Throwable $exception) {
            continue;
        }

        $daysLeft = (int) $today->diff($endDate)->format('%r%a');
        if ($daysLeft > 1) {
            continue;
        }

        $rentalId = (int) ($rental['id'] ?? 0);
        $carLabel = trim(($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? ''));
        $isOverdue = $endDateTime < $now;
        $severity = $isOverdue ? 'danger' : 'warning';
        if ($isOverdue) {
            if ($daysLeft < 0) {
                $message = $carLabel . ' icin kiralama suresi gecti. ' . abs($daysLeft) . ' gun gecmis durumda.';
            } else {
                $message = $carLabel . ' icin kiralama suresi doldu, arac henuz teslim alinmadi.';
            }
        } elseif ($daysLeft === 0) {
            $message = $carLabel . ' icin kiralama suresi bugun doluyor.';
        } else {
            $message = $carLabel . ' icin kiralama suresi yarin doluyor.';
        }

        $key = 'rental:deadline:' . $rentalId;
        $activeKeys[] = $key;
        notifications_upsert_system($pdo, $companyId, [
            'notification_key' => $key,
            'event_type' => 'rental.deadline',
            'entity_type' => 'rental',
            'entity_id' => $rentalId,
            'severity' => $severity,
            'title' => 'Kiralama suresi takibi',
            'message' => $message,
            'due_at' => $endDateTime->format('Y-m-d H:i:s'),
            'metadata' => ['rental_id' => $rentalId, 'days_left' => $daysLeft],
        ]);
    }

    $baseRentalsSt = $pdo->prepare("
        SELECT id, customer_name, completed
        FROM rentals
        WHERE company_id = ? AND archived_at IS NULL AND completed = 0
        ORDER BY start_date
    ");
    $baseRentalsSt->execute([$companyId]);
    $baseRentals = $baseRentalsSt->fetchAll(PDO::FETCH_ASSOC);
    $extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
    $collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
    $receivableWarnings = buildRentalExtensionReceivableWarnings($baseRentals, $extensionsByRentalId, $collectionsByExtensionId, 1);

    foreach ($receivableWarnings['items'] as $warning) {
        $extensionId = (int) ($warning['extension_id'] ?? 0);
        $rentalId = (int) ($warning['rental_id'] ?? 0);
        $key = 'extension:receivable:' . $extensionId;
        $activeKeys[] = $key;
        notifications_upsert_system($pdo, $companyId, [
            'notification_key' => $key,
            'event_type' => 'rental_extension.receivable',
            'entity_type' => 'rental_extension',
            'entity_id' => $extensionId,
            'severity' => ($warning['level'] ?? '') === 'danger' ? 'danger' : 'warning',
            'title' => 'Bekleyen tahsilat',
            'message' => (string) ($warning['message'] ?? ''),
            'due_at' => !empty($warning['due_date']) ? date('Y-m-d 00:00:00', strtotime((string) $warning['due_date'])) : null,
            'metadata' => [
                'rental_id' => $rentalId,
                'extension_id' => $extensionId,
                'pending_amount' => (float) ($warning['pending_amount'] ?? 0),
                'days_left' => (int) ($warning['days_left'] ?? 0),
            ],
        ]);
    }

    notifications_resolve_missing_system($pdo, $companyId, array_values(array_unique($activeKeys)));
    return notifications_summary($pdo, $companyId);
}

function pagination_resolve_state(string $pageParam = 'page', string $perPageParam = 'per_page', int $defaultPerPage = 10, array $allowedPerPage = [10, 20, 50, 100]): array
{
    $normalizedAllowed = array_values(array_unique(array_map('intval', $allowedPerPage)));
    sort($normalizedAllowed);

    if (empty($normalizedAllowed)) {
        $normalizedAllowed = [$defaultPerPage];
    }

    $defaultPerPage = in_array($defaultPerPage, $normalizedAllowed, true) ? $defaultPerPage : $normalizedAllowed[0];
    $page = max(1, (int) ($_GET[$pageParam] ?? 1));
    $requestedPerPage = (int) ($_GET[$perPageParam] ?? $defaultPerPage);
    $perPage = in_array($requestedPerPage, $normalizedAllowed, true) ? $requestedPerPage : $defaultPerPage;

    return [
        'page_param' => $pageParam,
        'per_page_param' => $perPageParam,
        'page' => $page,
        'per_page' => $perPage,
        'default_per_page' => $defaultPerPage,
        'allowed_per_page' => $normalizedAllowed,
    ];
}

function paginate_array(array $items, array $state): array
{
    $totalItems = count($items);
    $perPage = max(1, (int) ($state['per_page'] ?? 10));
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min(max(1, (int) ($state['page'] ?? 1)), $totalPages);
    $offset = ($page - 1) * $perPage;
    $pagedItems = array_slice($items, $offset, $perPage);

    return [
        'items' => $pagedItems,
        'page' => $page,
        'per_page' => $perPage,
        'total_items' => $totalItems,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'from' => $totalItems > 0 ? $offset + 1 : 0,
        'to' => $totalItems > 0 ? min($totalItems, $offset + count($pagedItems)) : 0,
        'page_param' => $state['page_param'] ?? 'page',
        'per_page_param' => $state['per_page_param'] ?? 'per_page',
        'allowed_per_page' => $state['allowed_per_page'] ?? [10, 20, 50, 100],
    ];
}

function paginate_collection(array $items, string $pageParam = 'page', string $perPageParam = 'per_page', int $defaultPerPage = 10, array $allowedPerPage = [10, 20, 50, 100]): array
{
    return paginate_array($items, pagination_resolve_state($pageParam, $perPageParam, $defaultPerPage, $allowedPerPage));
}

function pagination_build_url(array $pagination, array $overrides = [], ?string $anchor = null): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }

        $query[$key] = $value;
    }

    $queryString = http_build_query($query);
    $path = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));

    return $path . ($queryString !== '' ? '?' . $queryString : '') . ($anchor ? '#' . ltrim($anchor, '#') : '');
}

function pagination_render_hidden_query_fields(array $query, string $pageParam, string $perPageParam, string $prefix = ''): string
{
    ob_start();
    foreach ($query as $key => $value) {
        $inputName = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
        if ($inputName === $pageParam || $inputName === $perPageParam) {
            continue;
        }

        if (is_array($value)) {
            echo pagination_render_hidden_query_fields($value, $pageParam, $perPageParam, $inputName);
            continue;
        }
        ?>
        <input type="hidden" name="<?= h($inputName) ?>" value="<?= h((string) $value) ?>">
        <?php
    }

    return (string) ob_get_clean();
}

function pagination_render(array $pagination, array $options = []): string
{
    $totalItems = (int) ($pagination['total_items'] ?? 0);
    $totalPages = (int) ($pagination['total_pages'] ?? 1);
    $page = (int) ($pagination['page'] ?? 1);
    $perPage = (int) ($pagination['per_page'] ?? 10);
    $pageParam = (string) ($pagination['page_param'] ?? 'page');
    $perPageParam = (string) ($pagination['per_page_param'] ?? 'per_page');
    $allowedPerPage = $pagination['allowed_per_page'] ?? [10, 20, 50, 100];
    $from = (int) ($pagination['from'] ?? 0);
    $to = (int) ($pagination['to'] ?? 0);
    $anchor = $options['anchor'] ?? null;
    $itemLabel = (string) ($options['item_label'] ?? 'kayit');

    if ($totalItems <= 0) {
        return '';
    }

    $window = max(3, (int) ($options['window'] ?? 5));
    $halfWindow = (int) floor($window / 2);
    $startPage = max(1, $page - $halfWindow);
    $endPage = min($totalPages, $startPage + $window - 1);
    $startPage = max(1, $endPage - $window + 1);

    ob_start();
    ?>
    <div class="app-pagination-shell d-flex flex-column flex-lg-row justify-content-between align-items-stretch align-items-lg-center gap-3 mt-3">
      <div class="text-muted small">
        <?= h((string) $from) ?> - <?= h((string) $to) ?> / <?= h((string) $totalItems) ?> <?= h($itemLabel) ?>
      </div>
      <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2">
        <form method="get" class="d-flex align-items-center gap-2">
          <?= pagination_render_hidden_query_fields($_GET, $pageParam, $perPageParam) ?>
          <input type="hidden" name="<?= h($pageParam) ?>" value="1">
          <label class="small text-muted" for="<?= h($perPageParam) ?>">Sayfa basi</label>
          <select id="<?= h($perPageParam) ?>" name="<?= h($perPageParam) ?>" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($allowedPerPage as $option): ?>
              <option value="<?= h((string) $option) ?>" <?= $perPage === (int) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Sayfalama">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $page <= 1 ? '#' : h(pagination_build_url($pagination, [$pageParam => $page - 1], $anchor)) ?>">Onceki</a>
            </li>
            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h(pagination_build_url($pagination, [$pageParam => $pageNumber], $anchor)) ?>"><?= h((string) $pageNumber) ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $page >= $totalPages ? '#' : h(pagination_build_url($pagination, [$pageParam => $page + 1], $anchor)) ?>">Sonraki</a>
            </li>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
