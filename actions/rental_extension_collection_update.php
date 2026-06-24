<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.extension.collection.update');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_core');

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$collectionId = (int) ($_POST['collection_id'] ?? 0);
$extensionId = (int) ($_POST['extension_id'] ?? 0);
$rentalId = (int) ($_POST['rental_id'] ?? 0);
$amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float) $_POST['amount'] : 0.0;
$paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$collectedAtInput = trim((string) ($_POST['collected_at'] ?? ''));

$fail = static function (int $safeRentalId, string $status): void {
    redirect('../rental_detail.php?id=' . $safeRentalId . '&status=' . urlencode($status));
};

if ($collectionId <= 0 || $extensionId <= 0 || $rentalId <= 0) {
    redirect('../rentals.php');
}

if ($companyId <= 0 || $currentUserId <= 0) {
    $fail($rentalId, 'unauthorized');
}

$extensionSt = $pdo->prepare('
    SELECT re.*, r.company_id AS rental_company_id
    FROM rental_extensions re
    INNER JOIN rentals r ON r.id = re.rental_id
    WHERE re.id = ? AND re.rental_id = ? AND r.company_id = ? AND r.archived_at IS NULL
    LIMIT 1
');
$extensionSt->execute([$extensionId, $rentalId, $companyId]);
$extension = $extensionSt->fetch(PDO::FETCH_ASSOC);

if (!$extension || !rental_extension_is_active($extension)) {
    $fail($rentalId, 'extension_not_collectible');
}

$collectionSt = $pdo->prepare('
    SELECT *
    FROM rental_extension_collections
    WHERE id = ? AND rental_extension_id = ? AND company_id = ?
    LIMIT 1
');
$collectionSt->execute([$collectionId, $extensionId, $companyId]);
$collection = $collectionSt->fetch(PDO::FETCH_ASSOC);

if (!$collection || !rental_extension_collection_is_active($collection)) {
    $fail($rentalId, 'extension_collection_not_reversible');
}

$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$latestActiveCollection = rental_extension_latest_active_collection($collectionsByExtensionId[$extensionId] ?? []);
if (!$latestActiveCollection || (int) ($latestActiveCollection['id'] ?? 0) !== $collectionId) {
    $fail($rentalId, 'extension_collection_not_reversible');
}

$collectedAtTimestamp = $collectedAtInput !== '' ? strtotime($collectedAtInput) : false;
if ($amount <= 0.0 || $collectedAtTimestamp === false) {
    $fail($rentalId, 'extension_collection_update_invalid');
}

$extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
$otherCollectedAmount = 0.0;
foreach ($collectionsByExtensionId[$extensionId] ?? [] as $existingCollection) {
    if (!rental_extension_collection_is_active($existingCollection)) {
        continue;
    }

    if ((int) ($existingCollection['id'] ?? 0) === $collectionId) {
        continue;
    }

    $otherCollectedAmount += max(0.0, (float) ($existingCollection['amount'] ?? 0));
}

$maxAllowedAmount = max(0.0, $extensionIncome - $otherCollectedAmount);
if ($amount - $maxAllowedAmount > 0.0001) {
    $fail($rentalId, 'extension_collection_update_conflict');
}

$newCollectedAt = date('Y-m-d H:i:s', $collectedAtTimestamp);
$oldTotalCollected = rental_extension_collected_amount($extension, $collectionsByExtensionId);

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('
        UPDATE rental_extension_collections
        SET amount = ?, payment_method = ?, note = ?, collected_at = ?
        WHERE id = ? AND rental_extension_id = ? AND company_id = ?
    ');
    $update->execute([
        $amount,
        $paymentMethod !== '' ? $paymentMethod : null,
        $note !== '' ? $note : null,
        $newCollectedAt,
        $collectionId,
        $extensionId,
        $companyId,
    ]);

    $collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
    $newCollectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
    $newPaymentStatus = 'pending';
    if ($extensionIncome <= 0.0) {
        $newPaymentStatus = 'collected';
    } elseif ($newCollectedAmount > 0.0 && $newCollectedAmount + 0.0001 < $extensionIncome) {
        $newPaymentStatus = 'partial';
    } elseif ($newCollectedAmount + 0.0001 >= $extensionIncome) {
        $newPaymentStatus = 'collected';
    }

    $latestActiveCollectionAfterUpdate = rental_extension_latest_active_collection($collectionsByExtensionId[$extensionId] ?? []);
    $latestCollectedAt = $latestActiveCollectionAfterUpdate['collected_at'] ?? null;
    $latestCollectedBy = $latestActiveCollectionAfterUpdate['collected_by_user_id'] ?? null;

    $extensionUpdate = $pdo->prepare('
        UPDATE rental_extensions
        SET payment_status = ?, collected_at = ?, collected_by_user_id = ?
        WHERE id = ? AND rental_id = ? AND company_id = ?
    ');
    $extensionUpdate->execute([
        $newPaymentStatus,
        $latestCollectedAt,
        $latestCollectedBy,
        $extensionId,
        $rentalId,
        $companyId,
    ]);

    rental_extension_record_revision($pdo, $companyId, $rentalId, $extensionId, 'collection_updated', [
        'collection_id' => $collectionId,
        'collection_amount' => (float) ($collection['amount'] ?? 0),
        'payment_method' => $collection['payment_method'] ?? null,
        'note' => $collection['note'] ?? null,
        'collected_at' => $collection['collected_at'] ?? null,
        'payment_status' => $extension['payment_status'] ?? 'pending',
        'collected_amount_before' => $oldTotalCollected,
    ], [
        'collection_id' => $collectionId,
        'collection_amount' => $amount,
        'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
        'note' => $note !== '' ? $note : null,
        'collected_at' => $newCollectedAt,
        'payment_status' => $newPaymentStatus,
        'collected_amount_after' => $newCollectedAmount,
    ], $currentUserId);

    auth_audit_log($pdo, 'rental.extension_collection_updated', 'Uzatma tahsilati guncellendi.', [
        'entity_type' => 'rental_extension_collection',
        'entity_id' => $collectionId,
        'company_id' => $companyId,
        'metadata' => [
            'rental_id' => $rentalId,
            'extension_id' => $extensionId,
            'old_amount' => (float) ($collection['amount'] ?? 0),
            'new_amount' => $amount,
            'payment_status' => $newPaymentStatus,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Rental extension collection update failed: ' . $exception->getMessage());
    $fail($rentalId, 'extension_collection_update_failed');
}

redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_collection_updated');
