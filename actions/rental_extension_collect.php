<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.extension.collect');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_core');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$extensionId = (int) ($_POST['extension_id'] ?? 0);
$rentalId = (int) ($_POST['rental_id'] ?? 0);
$amount = ($_POST['amount'] !== '' ? (float) $_POST['amount'] : 0);
$paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$returnTo = trim((string) ($_POST['return_to'] ?? ''));

$buildRedirectTarget = static function (int $safeRentalId, string $status, string $requestedPath = ''): string {
    $fallback = 'rental_detail.php?id=' . $safeRentalId;
    $requestedPath = trim(str_replace('\\', '/', $requestedPath));
    if ($requestedPath === '') {
        return $fallback . '&status=' . urlencode($status);
    }

    $path = (string) parse_url($requestedPath, PHP_URL_PATH);
    $query = (string) parse_url($requestedPath, PHP_URL_QUERY);
    $path = ltrim($path, '/');
    if ($path === '') {
        $path = $fallback;
    }

    $allowedTargets = ['collection_center.php', 'rental_detail.php'];
    if (!in_array(basename($path), $allowedTargets, true)) {
        $path = $fallback;
        $query = '';
    }

    if (basename($path) === 'rental_detail.php' && !str_contains($query, 'id=')) {
        $query = ($query !== '' ? $query . '&' : '') . 'id=' . $safeRentalId;
    }

    $queryParams = [];
    if ($query !== '') {
        parse_str($query, $queryParams);
    }
    $queryParams['status'] = $status;

    return $path . '?' . http_build_query($queryParams);
};

$fail = static function (int $safeRentalId, string $status, string $returnToPath = '') use ($buildRedirectTarget): void {
    redirect('../' . $buildRedirectTarget($safeRentalId, $status, $returnToPath));
};

if ($extensionId <= 0 || $rentalId <= 0) {
    redirect('../rentals.php');
}

if ($companyId <= 0 || $currentUserId <= 0) {
    $fail($rentalId, 'unauthorized', $returnTo);
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
    $fail($rentalId, 'extension_not_collectible', $returnTo);
}

$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$alreadyCollected = rental_extension_collected_amount($extension, $collectionsByExtensionId);
$remainingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);

if ($remainingAmount <= 0.0) {
    $fail($rentalId, 'extension_collected', $returnTo);
}

if ($amount <= 0.0 || $amount - $remainingAmount > 0.0001) {
    $fail($rentalId, 'extension_collection_invalid', $returnTo);
}
try {
    $pdo->beginTransaction();

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
        $currentUserId,
        $note !== '' ? $note : null,
    ]);

    $newCollectedAmount = min((float) ($extension['income'] ?? 0), $alreadyCollected + $amount);
    $newPaymentStatus = $newCollectedAmount + 0.0001 >= (float) ($extension['income'] ?? 0) ? 'collected' : 'partial';

    $update = $pdo->prepare('UPDATE rental_extensions SET payment_status = ?, collected_at = NOW(), collected_by_user_id = ? WHERE id = ? AND rental_id = ? AND company_id = ?');
    $update->execute([
        $newPaymentStatus,
        $currentUserId,
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

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Rental extension collect failed: ' . $exception->getMessage());
    $fail($rentalId, 'extension_collection_save_failed', $returnTo);
}

redirect('../' . $buildRedirectTarget($rentalId, 'extension_collected', $returnTo));
