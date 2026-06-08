<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$extensionId = (int) ($_POST['extension_id'] ?? 0);
$rentalId = (int) ($_POST['rental_id'] ?? 0);
$amount = ($_POST['amount'] !== '' ? (float) $_POST['amount'] : 0);
$paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));

if ($extensionId <= 0 || $rentalId <= 0) {
    redirect('../rentals.php');
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
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_not_collectible');
}

$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$alreadyCollected = rental_extension_collected_amount($extension, $collectionsByExtensionId);
$remainingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);

if ($remainingAmount <= 0.0) {
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_collected');
}

if ($amount <= 0.0 || $amount - $remainingAmount > 0.0001) {
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_collection_invalid');
}

$collectionInsert = $pdo->prepare('
    INSERT INTO rental_extension_collections (company_id, rental_extension_id, amount, payment_method, collection_status, collected_at, collected_by_user_id, note)
    VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
');
$collectionInsert->execute([
    $companyId,
    $extensionId,
    $amount,
    $paymentMethod !== '' ? $paymentMethod : null,
    'active',
    $currentUserId > 0 ? $currentUserId : null,
    $note !== '' ? $note : null,
]);

$newCollectedAmount = min((float) ($extension['income'] ?? 0), $alreadyCollected + $amount);
$newPaymentStatus = $newCollectedAmount + 0.0001 >= (float) ($extension['income'] ?? 0) ? 'collected' : 'partial';

$update = $pdo->prepare('UPDATE rental_extensions SET payment_status = ?, collected_at = NOW(), collected_by_user_id = ? WHERE id = ? AND rental_id = ? AND company_id = ?');
$update->execute([
    $newPaymentStatus,
    $currentUserId > 0 ? $currentUserId : null,
    $extensionId,
    $rentalId,
    $companyId,
]);

rental_extension_record_revision($pdo, $companyId, $rentalId, $extensionId, 'collection_added', [
    'payment_status' => $extension['payment_status'] ?? 'pending',
    'collected_amount_before' => $alreadyCollected,
], [
    'payment_status' => $newPaymentStatus,
    'collection_amount' => $amount,
    'collected_amount_after' => $newCollectedAmount,
    'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
    'note' => $note !== '' ? $note : null,
], $currentUserId);

auth_audit_log($pdo, 'rental.extension_collected', 'Uzatma tahsilati kaydedildi.', [
    'entity_type' => 'rental_extension',
    'entity_id' => $extensionId,
    'company_id' => $companyId,
    'metadata' => [
        'rental_id' => $rentalId,
        'amount' => $amount,
        'payment_status' => $newPaymentStatus,
        'payment_method' => $paymentMethod,
    ],
]);

redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_collected');
