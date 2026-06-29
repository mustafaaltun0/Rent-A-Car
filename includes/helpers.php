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

require_once __DIR__ . '/schema_helpers.php';
require_once __DIR__ . '/modules/car_helpers.php';

function rental_is_archived(array $rental): bool
{
    return !empty($rental['archived_at']);
}

function expense_is_archived(array $expense): bool
{
    return !empty($expense['archived_at']);
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

require_once __DIR__ . '/modules/ledger_helpers.php';
require_once __DIR__ . '/modules/rental_helpers.php';
require_once __DIR__ . '/modules/notification_helpers.php';
require_once __DIR__ . '/modules/pagination_helpers.php';
require_once __DIR__ . '/modules/tenant_audit_helpers.php';
