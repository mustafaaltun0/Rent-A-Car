<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.extend');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_core');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

function normalizeDateTimeValue(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

$rentalId = (int) ($_POST['rental_id'] ?? 0);
$additionalIncome = ($_POST['additional_income'] !== '' ? (float) $_POST['additional_income'] : 0);
$additionalExpense = ($_POST['additional_expense'] !== '' ? (float) $_POST['additional_expense'] : 0);
$hasCustomCollectionPlan = (int) ($_POST['custom_collection_plan'] ?? 0) === 1;
$requestedPaymentStatus = ($_POST['payment_status'] ?? 'pending') === 'collected' ? 'collected' : 'pending';
$initialCollectedAmount = ($_POST['initial_collected_amount'] !== '' ? (float) $_POST['initial_collected_amount'] : 0);
$paymentDueDate = normalizeDateTimeValue($_POST['payment_due_date'] ?? null);
$note = trim((string) ($_POST['note'] ?? ''));

$rentalQuery = $pdo->prepare('SELECT * FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$rentalQuery->execute([$rentalId, $companyId]);
$rental = $rentalQuery->fetch(PDO::FETCH_ASSOC);

if ($companyId <= 0 || $currentUserId <= 0 || !$rental || (int) ($rental['completed'] ?? 0) === 1) {
    redirect('../rentals.php');
}

$previousEndDate = normalizeDateTimeValue($rental['end_date'] ?? null);
$extensionDays = (int) ($_POST['extension_days'] ?? 0);
$submittedNewEndDate = normalizeDateTimeValue($_POST['new_end_date'] ?? null);

if ($previousEndDate && $extensionDays > 0) {
    $newEndDate = (new DateTimeImmutable($previousEndDate))->modify('+' . $extensionDays . ' days')->format('Y-m-d H:i:s');
} else {
    $newEndDate = $submittedNewEndDate;
}

if ($rentalId <= 0 || !$newEndDate) {
    redirect('../rentals.php');
}

$newEnd = new DateTimeImmutable($newEndDate);
$previousEnd = $previousEndDate ? new DateTimeImmutable($previousEndDate) : null;
if ($previousEnd && $newEnd <= $previousEnd) {
    redirect('../rentals.php');
}

$additionalIncome = max(0, $additionalIncome);
$additionalExpense = max(0, $additionalExpense);
$initialCollectedAmount = max(0, $initialCollectedAmount);

if (!$hasCustomCollectionPlan && $additionalIncome > 0.0) {
    $requestedPaymentStatus = 'collected';
    $initialCollectedAmount = $additionalIncome;
    $paymentDueDate = null;
}

if ($initialCollectedAmount - $additionalIncome > 0.0001) {
    $initialCollectedAmount = $additionalIncome;
}

$paymentStatus = 'pending';
if ($additionalIncome <= 0.0) {
    $paymentStatus = 'collected';
    $initialCollectedAmount = 0.0;
} elseif ($requestedPaymentStatus === 'collected') {
    $paymentStatus = 'collected';
    $initialCollectedAmount = $additionalIncome;
} elseif ($initialCollectedAmount > 0.0 && $initialCollectedAmount + 0.0001 < $additionalIncome) {
    $paymentStatus = 'partial';
} elseif ($initialCollectedAmount > 0.0 && $initialCollectedAmount + 0.0001 >= $additionalIncome) {
    $paymentStatus = 'collected';
    $initialCollectedAmount = $additionalIncome;
}

$netProfit = $additionalIncome - $additionalExpense;
$collectedAt = in_array($paymentStatus, ['partial', 'collected'], true) ? date('Y-m-d H:i:s') : null;
$collectedByUserId = in_array($paymentStatus, ['partial', 'collected'], true) ? $currentUserId : null;

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare('
        INSERT INTO rental_extensions (
            company_id, rental_id, previous_end_date, new_end_date, income, expense, net_profit,
            payment_status, payment_due_date, collected_at, collected_by_user_id, extension_status, note
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $insert->execute([
        $companyId,
        $rentalId,
        $previousEndDate,
        $newEndDate,
        $additionalIncome,
        $additionalExpense,
        $netProfit,
        $paymentStatus,
        $paymentDueDate,
        $collectedAt,
        $collectedByUserId,
        'active',
        $note !== '' ? $note : null,
    ]);
    $extensionId = (int) $pdo->lastInsertId();

    if ($initialCollectedAmount > 0) {
        $collectionInsert = $pdo->prepare('
            INSERT INTO rental_extension_collections (company_id, rental_extension_id, amount, collection_status, collected_at, collected_by_user_id, note)
            VALUES (?, ?, ?, ?, NOW(), ?, ?)
        ');
        $collectionInsert->execute([
            $companyId,
            $extensionId,
            $initialCollectedAmount,
            'active',
            $currentUserId,
            'Uzatma kaydi olusturulurken ilk tahsilat alindi.',
        ]);
    }

    $pdo->prepare('UPDATE rentals SET end_date = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([$newEndDate, $rentalId, $companyId]);

    rental_extension_record_revision($pdo, $companyId, $rentalId, $extensionId, 'created', null, [
        'new_end_date' => $newEndDate,
        'income' => $additionalIncome,
        'expense' => $additionalExpense,
        'payment_status' => $paymentStatus,
        'initial_collected_amount' => $initialCollectedAmount,
        'payment_due_date' => $paymentDueDate,
        'note' => $note !== '' ? $note : null,
    ], $currentUserId);

    auth_audit_log($pdo, 'rental.extension_created', 'Kiralama uzatma kaydi olusturuldu.', [
        'entity_type' => 'rental_extension',
        'entity_id' => $extensionId,
        'company_id' => $companyId,
        'metadata' => [
            'rental_id' => $rentalId,
            'payment_status' => $paymentStatus,
            'new_end_date' => $newEndDate,
            'income' => $additionalIncome,
            'initial_collected_amount' => $initialCollectedAmount,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('rental_extend_failed: ' . $exception->getMessage());
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_save_error');
}

redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_saved');
