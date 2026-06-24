<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.extension.update');
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

$extensionId = (int) ($_POST['extension_id'] ?? 0);
$rentalId = (int) ($_POST['rental_id'] ?? 0);
$newEndDate = normalizeDateTimeValue($_POST['new_end_date'] ?? null);
$additionalIncome = ($_POST['additional_income'] !== '' ? (float) $_POST['additional_income'] : 0);
$additionalExpense = ($_POST['additional_expense'] !== '' ? (float) $_POST['additional_expense'] : 0);
$pricingMode = trim((string) ($_POST['pricing_mode'] ?? 'auto_prorata'));
$paymentStatus = ($_POST['payment_status'] ?? 'pending') === 'collected' ? 'collected' : 'pending';
$paymentDueDate = normalizeDateTimeValue($_POST['payment_due_date'] ?? null);
$note = trim((string) ($_POST['note'] ?? ''));

if ($companyId <= 0 || $currentUserId <= 0 || $extensionId <= 0 || $rentalId <= 0) {
    redirect('../rentals.php');
}

$rentalSt = $pdo->prepare('SELECT * FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$rentalSt->execute([$rentalId, $companyId]);
$rental = $rentalSt->fetch(PDO::FETCH_ASSOC);
if (!$rental) {
    redirect('../rentals.php');
}

$extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
$extensions = $extensionsByRentalId[$rentalId] ?? [];
$latestActiveExtension = rental_latest_active_extension($extensions);
if (!$latestActiveExtension || (int) ($latestActiveExtension['id'] ?? 0) !== $extensionId) {
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_not_editable');
}

$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$collectedAmount = rental_extension_collected_amount($latestActiveExtension, $collectionsByExtensionId);
$revisionsByExtensionId = getRentalExtensionRevisionsByExtensionId($pdo, $companyId);
$extensionRevisions = $revisionsByExtensionId[$extensionId] ?? [];
$originalExtensionTerms = rental_extension_original_terms($latestActiveExtension, $extensionRevisions);

$previousEndDate = normalizeDateTimeValue($latestActiveExtension['previous_end_date'] ?? null);
if (!$newEndDate || ($previousEndDate && (new DateTimeImmutable($newEndDate)) <= new DateTimeImmutable($previousEndDate))) {
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_invalid');
}

if ($pricingMode !== 'manual') {
    $additionalIncome = rental_extension_prorated_income($originalExtensionTerms, $newEndDate);
}

if ($additionalIncome + 0.0001 < $collectedAmount) {
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_collection_conflict');
}

$netProfit = $additionalIncome - $additionalExpense;
$newPaymentStatus = $paymentStatus;
if ($collectedAmount > 0.0 && $collectedAmount + 0.0001 < $additionalIncome) {
    $newPaymentStatus = 'partial';
} elseif ($collectedAmount > 0.0 && $collectedAmount + 0.0001 >= $additionalIncome) {
    $newPaymentStatus = 'collected';
}

$autoCollectedAmount = 0.0;
if ($paymentStatus === 'collected' && $additionalIncome - $collectedAmount > 0.0001) {
    $autoCollectedAmount = $additionalIncome - $collectedAmount;
    $newPaymentStatus = 'collected';
}

$collectedAt = $newPaymentStatus === 'collected'
    ? (($latestActiveExtension['collected_at'] ?? null) ?: date('Y-m-d H:i:s'))
    : (($latestActiveExtension['collected_at'] ?? null) ?: null);
$collectedByUserId = $newPaymentStatus === 'collected'
    ? (($latestActiveExtension['collected_by_user_id'] ?? null) ?: ($currentUserId > 0 ? $currentUserId : null))
    : (($latestActiveExtension['collected_by_user_id'] ?? null) ?: null);

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('
        UPDATE rental_extensions
        SET new_end_date = ?, income = ?, expense = ?, net_profit = ?, payment_status = ?, payment_due_date = ?, collected_at = ?, collected_by_user_id = ?, note = ?
        WHERE id = ? AND rental_id = ? AND company_id = ?
    ');
    $update->execute([
        $newEndDate,
        $additionalIncome,
        $additionalExpense,
        $netProfit,
        $newPaymentStatus,
        $paymentDueDate,
        $collectedAt,
        $collectedByUserId,
        $note !== '' ? $note : null,
        $extensionId,
        $rentalId,
        $companyId,
    ]);

    if ($autoCollectedAmount > 0.0) {
        $collectionInsert = $pdo->prepare('
            INSERT INTO rental_extension_collections (company_id, rental_extension_id, amount, collection_status, collected_at, collected_by_user_id, note)
            VALUES (?, ?, ?, ?, NOW(), ?, ?)
        ');
        $collectionInsert->execute([
            $companyId,
            $extensionId,
            $autoCollectedAmount,
            'active',
            $currentUserId,
            'Uzatma guncellenirken kalan tutar tahsil edildi.',
        ]);
    }

    $extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
    rental_refresh_end_date($pdo, $rental, $extensionsByRentalId);

    rental_extension_record_revision($pdo, $companyId, $rentalId, $extensionId, 'updated', $latestActiveExtension, [
        'new_end_date' => $newEndDate,
        'income' => $additionalIncome,
        'expense' => $additionalExpense,
        'payment_status' => $newPaymentStatus,
        'payment_due_date' => $paymentDueDate,
        'note' => $note !== '' ? $note : null,
        'auto_collected_amount' => $autoCollectedAmount,
    ], $currentUserId);

    auth_audit_log($pdo, 'rental.extension_updated', 'Son uzatma kaydi guncellendi.', [
        'entity_type' => 'rental_extension',
        'entity_id' => $extensionId,
        'company_id' => $companyId,
        'metadata' => [
            'rental_id' => $rentalId,
            'payment_status' => $newPaymentStatus,
            'new_end_date' => $newEndDate,
            'income' => $additionalIncome,
            'pricing_mode' => $pricingMode,
            'auto_collected_amount' => $autoCollectedAmount,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('rental_extension_update_failed: ' . $exception->getMessage());
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_update_failed');
}

redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_updated');
