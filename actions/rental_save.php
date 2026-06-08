<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
ensureCarArchiveSchema($pdo);
ensureCustomerCompanySchema($pdo);
$companyId = auth_current_company_id();
$customerCompaniesEnabled = app_feature_customer_companies_enabled();

function normalizeCustomerName(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/\s+/', mb_strtolower($value, 'UTF-8'));
    $parts = array_map(static function ($part) {
        return mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
    }, $parts);

    return implode(' ', $parts);
}

function normalizePhone(?string $value): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '') {
        return null;
    }

    if (str_starts_with($digits, '90') && strlen($digits) > 10) {
        $digits = '0' . substr($digits, 2);
    }

    if (!str_starts_with($digits, '0')) {
        $digits = '0' . $digits;
    }

    $digits = substr($digits, 0, 11);
    $part1 = substr($digits, 0, 4);
    $part2 = substr($digits, 4, 3);
    $part3 = substr($digits, 7, 2);
    $part4 = substr($digits, 9, 2);

    return trim(implode(' ', array_filter([$part1, $part2, $part3, $part4])));
}

function normalizeDateTimeValue(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

function resolveEndDate(?string $startDate, $rentalDays, ?string $submittedEndDate): ?string
{
    $normalizedStartDate = normalizeDateTimeValue($startDate);
    $days = (int) $rentalDays;

    if ($normalizedStartDate && $days > 0) {
        $start = new DateTimeImmutable($normalizedStartDate);
        return $start->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }

    return normalizeDateTimeValue($submittedEndDate);
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$carId = (int) ($_POST['car_id'] ?? 0);
$income = ($_POST['income'] !== '' ? (float) $_POST['income'] : 0);
$expense = ($_POST['expense'] !== '' ? (float) $_POST['expense'] : 0);
$net = $income - $expense;
$customerName = normalizeCustomerName($_POST['customer_name'] ?? '');
$customerCompanyId = isset($_POST['customer_company_id']) && $_POST['customer_company_id'] !== '' ? (int) $_POST['customer_company_id'] : null;
$customerPhone = normalizePhone($_POST['customer_phone'] ?? null);
$customerIdentityNo = trim((string) ($_POST['customer_identity_no'] ?? ''));
$customerIdentityNo = preg_replace('/\D+/', '', $customerIdentityNo);
$customerIdentityNo = substr($customerIdentityNo, 0, 11);
$customerIdentityNo = $customerIdentityNo !== '' ? $customerIdentityNo : null;
$departureKmRaw = preg_replace('/\D+/', '', (string) ($_POST['departure_km'] ?? ''));
$departureKm = $departureKmRaw !== '' ? max(0, (int) $departureKmRaw) : null;
$startDate = normalizeDateTimeValue($_POST['start_date'] ?? null);
$endDate = resolveEndDate($_POST['start_date'] ?? null, $_POST['rental_days'] ?? null, $_POST['end_date'] ?? null);

$existingRental = null;
if ($id > 0) {
    $existingRentalSt = $pdo->prepare('SELECT id, car_id, initial_end_date, customer_company_id FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $existingRentalSt->execute([$id, $companyId]);
    $existingRental = $existingRentalSt->fetch(PDO::FETCH_ASSOC);
    if (!$existingRental) {
        redirect('../rentals.php');
    }
}

if (!$customerCompaniesEnabled) {
    $customerCompanyId = $existingRental ? ((isset($existingRental['customer_company_id']) && $existingRental['customer_company_id'] !== null) ? (int) $existingRental['customer_company_id'] : null) : null;
} elseif ($customerCompanyId !== null && $customerCompanyId > 0) {
    $customerCompanySt = $pdo->prepare('SELECT id, is_active FROM customer_companies WHERE id = ? AND company_id = ? LIMIT 1');
    $customerCompanySt->execute([$customerCompanyId, $companyId]);
    $customerCompany = $customerCompanySt->fetch(PDO::FETCH_ASSOC);
    if (!$customerCompany) {
        redirect('../rentals.php?error=invalid_customer_company');
    }

    $existingCustomerCompanyId = $existingRental ? (int) ($existingRental['customer_company_id'] ?? 0) : 0;
    if ((int) ($customerCompany['is_active'] ?? 0) !== 1 && $customerCompanyId !== $existingCustomerCompanyId) {
        redirect('../rentals.php?error=invalid_customer_company');
    }
} else {
    $customerCompanyId = null;
}

if ($carId > 0) {
    $carCheck = $pdo->prepare('SELECT id FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $carCheck->execute([$carId, $companyId]);
    if (!$carCheck->fetchColumn()) {
        redirect('../rentals.php?error=car_unavailable');
    }

    if ($id > 0) {
        $activeRentalCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND id <> ? AND company_id = ? AND archived_at IS NULL');
        $activeRentalCheck->execute([$carId, $id, $companyId]);
    } else {
        $activeRentalCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND company_id = ? AND archived_at IS NULL');
        $activeRentalCheck->execute([$carId, $companyId]);
    }

    if ((int) $activeRentalCheck->fetchColumn() > 0) {
        redirect('../rentals.php?error=car_unavailable');
    }
}

if ($id > 0) {
    $oldCarId = (int) ($existingRental['car_id'] ?? 0);

    $st = $pdo->prepare('
        UPDATE rentals
        SET customer_company_id = ?, customer_name = ?, customer_phone = ?, customer_identity_no = ?, start_date = ?, end_date = ?, departure_km = ?, income = ?, expense = ?, net_profit = ?, car_id = ?
        WHERE id = ? AND company_id = ? AND archived_at IS NULL
    ');
    $st->execute([
        $customerCompanyId,
        $customerName,
        $customerPhone,
        $customerIdentityNo,
        $startDate,
        $endDate,
        $departureKm,
        $income,
        $expense,
        $net,
        $carId,
        $id,
        $companyId,
    ]);

    if (($existingRental['initial_end_date'] ?? null) === null) {
        $pdo->prepare('UPDATE rentals SET initial_end_date = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([
            $endDate,
            $id,
            $companyId,
        ]);
    }

    if ($oldCarId && $oldCarId !== $carId) {
        $pdo->prepare('UPDATE cars SET available = 1 WHERE id = ? AND company_id = ?')->execute([$oldCarId, $companyId]);
    }
} else {
    $st = $pdo->prepare('
        INSERT INTO rentals (company_id, customer_company_id, customer_name, customer_phone, customer_identity_no, start_date, end_date, initial_end_date, departure_km, income, expense, net_profit, car_id, completed)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
    ');
    $st->execute([
        $companyId,
        $customerCompanyId,
        $customerName,
        $customerPhone,
        $customerIdentityNo,
        $startDate,
        $endDate,
        $endDate,
        $departureKm,
        $income,
        $expense,
        $net,
        $carId,
    ]);
}

if ($carId > 0) {
    $pdo->prepare('UPDATE cars SET available = 0 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
}

redirect('../rentals.php');
