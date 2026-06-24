<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_core', 'car_sales', 'customer_companies');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$customerCompaniesEnabled = app_feature_customer_companies_enabled();
$fail = static function (string $error) : void {
    redirect('../rentals.php?error=' . urlencode($error));
};

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
$submittedCollectedAmount = isset($_POST['collected_amount']) ? trim((string) $_POST['collected_amount']) : '';
$collectedAmount = $submittedCollectedAmount === '' ? max(0.0, $income) : min(max(0.0, (float) $submittedCollectedAmount), max(0.0, $income));
$paymentDueDate = normalizeDateTimeValue($_POST['payment_due_date'] ?? null);
$paymentStatus = 'collected';
if ($income > 0.0) {
    if ($collectedAmount <= 0.0001) {
        $paymentStatus = 'pending';
    } elseif ($collectedAmount + 0.0001 < $income) {
        $paymentStatus = 'partial';
    }
}
if ($paymentStatus === 'collected') {
    $paymentDueDate = null;
}
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

if ($companyId <= 0 || $currentUserId <= 0) {
    $fail('unauthorized');
}

if ($carId <= 0) {
    $fail('invalid_car');
}

if ($customerName === '') {
    $fail('invalid_customer_name');
}

if ($startDate === null || $endDate === null) {
    $fail('invalid_dates');
}

try {
    $startDateObject = new DateTimeImmutable($startDate);
    $endDateObject = new DateTimeImmutable($endDate);
} catch (Throwable $exception) {
    $fail('invalid_dates');
}

if ($endDateObject <= $startDateObject) {
    $fail('invalid_dates');
}

if ($income < 0 || $expense < 0) {
    $fail('invalid_amount');
}

if ($paymentStatus !== 'collected' && $paymentDueDate === null) {
    $fail('invalid_payment_due_date');
}

$existingRental = null;
if ($id > 0) {
    $existingRentalSt = $pdo->prepare('SELECT id, car_id, initial_end_date, customer_company_id, income, collected_amount, payment_status, payment_due_date, collected_at, collected_by_user_id, expense FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $existingRentalSt->execute([$id, $companyId]);
    $existingRental = $existingRentalSt->fetch(PDO::FETCH_ASSOC);
    if (!$existingRental) {
        $fail('invalid');
    }
}

if (!$customerCompaniesEnabled) {
    $customerCompanyId = $existingRental ? ((isset($existingRental['customer_company_id']) && $existingRental['customer_company_id'] !== null) ? (int) $existingRental['customer_company_id'] : null) : null;
} elseif ($customerCompanyId !== null && $customerCompanyId > 0) {
    $customerCompanySt = $pdo->prepare('SELECT id, is_active FROM customer_companies WHERE id = ? AND company_id = ? LIMIT 1');
    $customerCompanySt->execute([$customerCompanyId, $companyId]);
    $customerCompany = $customerCompanySt->fetch(PDO::FETCH_ASSOC);
    if (!$customerCompany) {
        $fail('invalid_customer_company');
    }

    $existingCustomerCompanyId = $existingRental ? (int) ($existingRental['customer_company_id'] ?? 0) : 0;
    if ((int) ($customerCompany['is_active'] ?? 0) !== 1 && $customerCompanyId !== $existingCustomerCompanyId) {
        $fail('invalid_customer_company');
    }
} else {
    $customerCompanyId = null;
}

if ($carId > 0) {
    $carCheck = $pdo->prepare('SELECT id FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL AND sold_at IS NULL');
    $carCheck->execute([$carId, $companyId]);
    if (!$carCheck->fetchColumn()) {
        $fail('car_unavailable');
    }

    if ($id > 0) {
        $activeRentalCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND id <> ? AND company_id = ? AND archived_at IS NULL');
        $activeRentalCheck->execute([$carId, $id, $companyId]);
    } else {
        $activeRentalCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND company_id = ? AND archived_at IS NULL');
        $activeRentalCheck->execute([$carId, $companyId]);
    }

    if ((int) $activeRentalCheck->fetchColumn() > 0) {
        $fail('car_unavailable');
    }
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $oldCarId = (int) ($existingRental['car_id'] ?? 0);
        $oldCollectedAmount = rental_collected_amount($existingRental);
        $oldPaymentStatus = rental_effective_payment_status($existingRental);
        $collectedAt = $collectedAmount > 0 ? (($collectedAmount !== $oldCollectedAmount) ? date('Y-m-d H:i:s') : ($existingRental['collected_at'] ?? date('Y-m-d H:i:s'))) : null;
        $collectedByUserId = $collectedAmount > 0 ? (($existingRental['collected_by_user_id'] ?? null) ?: (function_exists('auth_current_user_id') ? auth_current_user_id() : null)) : null;

        $st = $pdo->prepare('
            UPDATE rentals
            SET customer_company_id = ?, customer_name = ?, customer_phone = ?, customer_identity_no = ?, start_date = ?, end_date = ?, departure_km = ?, income = ?, collected_amount = ?, payment_status = ?, payment_due_date = ?, collected_at = ?, collected_by_user_id = ?, expense = ?, net_profit = ?, car_id = ?
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
            $collectedAmount,
            $paymentStatus,
            $paymentDueDate,
            $collectedAt,
            $collectedByUserId,
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

        $extensionCountSt = $pdo->prepare('SELECT COUNT(*) FROM rental_extensions WHERE rental_id = ? AND company_id = ?');
        $extensionCountSt->execute([$id, $companyId]);
        $extensionCount = (int) $extensionCountSt->fetchColumn();
        if ($extensionCount === 0) {
            $pdo->prepare('UPDATE rentals SET initial_end_date = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([
                $endDate,
                $id,
                $companyId,
            ]);
        } else {
            $extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
            $latestActiveExtension = rental_latest_active_extension($extensionsByRentalId[$id] ?? []);
            if ($latestActiveExtension && !empty($endDate) && (string) ($latestActiveExtension['new_end_date'] ?? '') !== (string) $endDate) {
                $previousEndDate = !empty($latestActiveExtension['previous_end_date']) ? new DateTimeImmutable((string) $latestActiveExtension['previous_end_date']) : null;
                $newEndDateObject = new DateTimeImmutable((string) $endDate);
                if (!$previousEndDate || $newEndDateObject > $previousEndDate) {
                    $updateExtensionEndDate = $pdo->prepare('UPDATE rental_extensions SET new_end_date = ? WHERE id = ? AND rental_id = ? AND company_id = ?');
                    $updateExtensionEndDate->execute([
                        $endDate,
                        (int) $latestActiveExtension['id'],
                        $id,
                        $companyId,
                    ]);

                    rental_extension_record_revision($pdo, $companyId, $id, (int) $latestActiveExtension['id'], 'updated', $latestActiveExtension, [
                        'new_end_date' => $endDate,
                        'income' => (float) ($latestActiveExtension['income'] ?? 0),
                        'expense' => (float) ($latestActiveExtension['expense'] ?? 0),
                        'payment_status' => $latestActiveExtension['payment_status'] ?? 'pending',
                        'payment_due_date' => $latestActiveExtension['payment_due_date'] ?? null,
                        'note' => $latestActiveExtension['note'] ?? null,
                        'synced_from_rental_edit' => true,
                    ], $currentUserId);
                }
            }
        }

        if ($oldCarId && $oldCarId !== $carId) {
            $pdo->prepare('UPDATE cars SET available = 1 WHERE id = ? AND company_id = ?')->execute([$oldCarId, $companyId]);
        }

        if (abs($oldCollectedAmount - $collectedAmount) > 0.0001 || $oldPaymentStatus !== $paymentStatus || (string) ($existingRental['payment_due_date'] ?? '') !== (string) ($paymentDueDate ?? '')) {
            auth_audit_log($pdo, 'rental.payment_updated', 'Kiralama tahsilat durumu guncellendi.', [
                'entity_type' => 'rental',
                'entity_id' => $id,
                'metadata' => [
                    'old_collected_amount' => $oldCollectedAmount,
                    'new_collected_amount' => $collectedAmount,
                    'old_payment_status' => $oldPaymentStatus,
                    'new_payment_status' => $paymentStatus,
                    'payment_due_date' => $paymentDueDate,
                    'contract_income' => $income,
                ],
            ]);
        }
    } else {
        $currentUserId = function_exists('auth_current_user_id') ? auth_current_user_id() : null;
        $collectedAt = $collectedAmount > 0 ? date('Y-m-d H:i:s') : null;
        $collectedByUserId = $collectedAmount > 0 ? $currentUserId : null;
        $st = $pdo->prepare('
            INSERT INTO rentals (company_id, customer_company_id, customer_name, customer_phone, customer_identity_no, start_date, end_date, initial_end_date, departure_km, income, collected_amount, payment_status, payment_due_date, collected_at, collected_by_user_id, expense, net_profit, car_id, completed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
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
            $collectedAmount,
            $paymentStatus,
            $paymentDueDate,
            $collectedAt,
            $collectedByUserId,
            $expense,
            $net,
            $carId,
        ]);
    }

    $pdo->prepare('UPDATE cars SET available = 0 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Rental save failed: ' . $exception->getMessage());
    $fail('save_failed');
}

redirect('../rentals.php');
