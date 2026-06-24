<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.extension.collection.cancel');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_core');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$collectionId = (int) ($_POST['collection_id'] ?? 0);
$extensionId = (int) ($_POST['extension_id'] ?? 0);
$rentalId = (int) ($_POST['rental_id'] ?? 0);
$cancelReasonOption = trim((string) ($_POST['cancel_reason_option'] ?? ''));
$cancelReasonDetail = trim((string) ($_POST['cancel_reason_detail'] ?? ''));
$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$cancelReason = $cancelReasonOption;
if ($cancelReasonOption === 'Diger' && $cancelReasonDetail !== '') {
    $cancelReason = $cancelReasonDetail;
} elseif ($cancelReasonOption !== '' && $cancelReasonDetail !== '') {
    $cancelReason = $cancelReasonOption . ' - ' . $cancelReasonDetail;
}

$buildRedirectTarget = static function (int $safeRentalId, int $safeExtensionId, string $status, string $requestedPath = ''): string {
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

    $allowedTargets = ['collection_center.php', 'collection_collect.php', 'rental_detail.php'];
    if (!in_array(basename($path), $allowedTargets, true)) {
        $path = $fallback;
        $query = '';
    }

    $queryParams = [];
    if ($query !== '') {
        parse_str($query, $queryParams);
    }

    if (basename($path) === 'rental_detail.php' && !isset($queryParams['id'])) {
        $queryParams['id'] = $safeRentalId;
    }

    if (basename($path) === 'collection_collect.php') {
        $queryParams['rental_id'] = $safeRentalId;
        $queryParams['extension_id'] = $safeExtensionId;
    }

    $queryParams['status'] = $status;

    return $path . '?' . http_build_query($queryParams);
};

$fail = static function (int $safeRentalId, int $safeExtensionId, string $status, string $returnToPath = '') use ($buildRedirectTarget): void {
    redirect('../' . $buildRedirectTarget($safeRentalId, $safeExtensionId, $status, $returnToPath));
};

if ($collectionId <= 0 || $extensionId <= 0 || $rentalId <= 0) {
    redirect('../rentals.php');
}

if ($companyId <= 0 || $currentUserId <= 0) {
    $fail($rentalId, $extensionId, 'unauthorized', $returnTo);
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
    $fail($rentalId, $extensionId, 'extension_not_collectible', $returnTo);
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
    $fail($rentalId, $extensionId, 'extension_collection_not_reversible', $returnTo);
}

$collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
$latestActiveCollection = rental_extension_latest_active_collection($collectionsByExtensionId[$extensionId] ?? []);
if (!$latestActiveCollection || (int) ($latestActiveCollection['id'] ?? 0) !== $collectionId) {
    $fail($rentalId, $extensionId, 'extension_collection_not_reversible', $returnTo);
}
try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('
        UPDATE rental_extension_collections
        SET collection_status = ?, cancelled_at = NOW(), cancelled_by_user_id = ?, cancel_reason = ?
        WHERE id = ? AND rental_extension_id = ? AND company_id = ?
    ');
    $update->execute([
        'cancelled',
        $currentUserId,
        $cancelReason !== '' ? $cancelReason : null,
        $collectionId,
        $extensionId,
        $companyId,
    ]);

    $collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
    $extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
    $newCollectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
    $oldCollectedAmount = min($extensionIncome, $newCollectedAmount + max(0.0, (float) ($collection['amount'] ?? 0)));
    $newPaymentStatus = 'pending';
    if ($extensionIncome <= 0.0) {
        $newPaymentStatus = 'collected';
    } elseif ($newCollectedAmount > 0.0 && $newCollectedAmount + 0.0001 < $extensionIncome) {
        $newPaymentStatus = 'partial';
    } elseif ($newCollectedAmount + 0.0001 >= $extensionIncome) {
        $newPaymentStatus = 'collected';
    }

    $latestActiveCollectionAfterCancel = rental_extension_latest_active_collection($collectionsByExtensionId[$extensionId] ?? []);
    $latestCollectedAt = $latestActiveCollectionAfterCancel['collected_at'] ?? null;
    $latestCollectedBy = $latestActiveCollectionAfterCancel['collected_by_user_id'] ?? null;

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

    rental_extension_record_revision($pdo, $companyId, $rentalId, $extensionId, 'collection_cancelled', [
        'collection_id' => $collectionId,
        'collection_amount' => (float) ($collection['amount'] ?? 0),
        'payment_method' => $collection['payment_method'] ?? null,
        'payment_status' => $extension['payment_status'] ?? 'pending',
        'collected_amount_before' => $oldCollectedAmount,
    ], [
        'collection_id' => $collectionId,
        'payment_status' => $newPaymentStatus,
        'collected_amount_after' => $newCollectedAmount,
        'cancel_reason' => $cancelReason !== '' ? $cancelReason : null,
    ], $currentUserId);

    auth_audit_log($pdo, 'rental.extension_collection_cancelled', 'Uzatma tahsilati geri alindi.', [
        'entity_type' => 'rental_extension_collection',
        'entity_id' => $collectionId,
        'company_id' => $companyId,
        'metadata' => [
            'rental_id' => $rentalId,
            'extension_id' => $extensionId,
            'amount' => (float) ($collection['amount'] ?? 0),
            'payment_status' => $newPaymentStatus,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Rental extension collection cancel failed: ' . $exception->getMessage());
    $fail($rentalId, $extensionId, 'extension_collection_cancel_failed', $returnTo);
}

redirect('../' . $buildRedirectTarget($rentalId, $extensionId, 'extension_collection_cancelled', $returnTo));
