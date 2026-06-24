<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.sale.collection.cancel');
auth_require_post_request();
auth_validate_csrf_request();

ensureCarSaleSchema($pdo);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$collectionId = (int) ($_POST['collection_id'] ?? 0);
$saleId = (int) ($_POST['sale_id'] ?? 0);
$carId = (int) ($_POST['car_id'] ?? 0);
$cancelReasonOption = trim((string) ($_POST['cancel_reason_option'] ?? ''));
$cancelReasonDetail = trim((string) ($_POST['cancel_reason_detail'] ?? ''));
$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$cancelReason = $cancelReasonOption;
if ($cancelReasonOption === 'Diger' && $cancelReasonDetail !== '') {
    $cancelReason = $cancelReasonDetail;
} elseif ($cancelReasonOption !== '' && $cancelReasonDetail !== '') {
    $cancelReason = $cancelReasonOption . ' - ' . $cancelReasonDetail;
}

$buildRedirectTarget = static function (int $safeCarId, int $safeSaleId, string $status, string $requestedPath = ''): string {
    $fallback = 'car_detail.php?id=' . $safeCarId;
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

    $allowedTargets = ['collection_center.php', 'car_detail.php', 'car_sale_collect.php'];
    if (!in_array(basename($path), $allowedTargets, true)) {
        $path = $fallback;
        $query = '';
    }

    $queryParams = [];
    if ($query !== '') {
        parse_str($query, $queryParams);
    }
    if (basename($path) === 'car_detail.php' && !isset($queryParams['id'])) {
        $queryParams['id'] = $safeCarId;
    }
    if (basename($path) === 'car_sale_collect.php') {
        $queryParams['car_id'] = $safeCarId;
    }
    $queryParams['status'] = $status;

    return $path . '?' . http_build_query($queryParams);
};

$fail = static function (int $safeCarId, int $safeSaleId, string $status, string $returnToPath = '') use ($buildRedirectTarget): void {
    redirect('../' . $buildRedirectTarget($safeCarId, $safeSaleId, $status, $returnToPath));
};

if ($collectionId <= 0 || $saleId <= 0 || $carId <= 0) {
    redirect('../cars.php');
}

if ($companyId <= 0 || $currentUserId <= 0) {
    $fail($carId, $saleId, 'unauthorized', $returnTo);
}

$saleSt = $pdo->prepare("SELECT * FROM car_sales WHERE id = ? AND company_id = ? AND car_id = ? AND sale_status = 'active' LIMIT 1");
$saleSt->execute([$saleId, $companyId, $carId]);
$sale = $saleSt->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
    $fail($carId, $saleId, 'car_sale_invalid', $returnTo);
}

$collectionSt = $pdo->prepare('SELECT * FROM car_sale_collections WHERE id = ? AND car_sale_id = ? AND company_id = ? LIMIT 1');
$collectionSt->execute([$collectionId, $saleId, $companyId]);
$collection = $collectionSt->fetch(PDO::FETCH_ASSOC);
if (!$collection || !car_sale_collection_is_active($collection)) {
    $fail($carId, $saleId, 'car_sale_collection_not_reversible', $returnTo);
}

$collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [$saleId]);
$latestActiveCollection = car_sale_latest_active_collection($collectionsBySaleId[$saleId] ?? []);
if (!$latestActiveCollection || (int) ($latestActiveCollection['id'] ?? 0) !== $collectionId) {
    $fail($carId, $saleId, 'car_sale_collection_not_reversible', $returnTo);
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE car_sale_collections
        SET collection_status = 'cancelled', cancelled_at = NOW(), cancelled_by_user_id = ?, cancel_reason = ?
        WHERE id = ? AND car_sale_id = ? AND company_id = ?
    ");
    $update->execute([
        $currentUserId,
        $cancelReason !== '' ? $cancelReason : null,
        $collectionId,
        $saleId,
        $companyId,
    ]);

    $collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [$saleId]);
    $newCollectedAmount = car_sale_collected_amount($sale, $collectionsBySaleId);
    $totalAmount = max(0.0, (float) ($sale['total_amount'] ?? 0));
    $newPaymentStatus = 'pending';
    if ($totalAmount <= 0.0 || $newCollectedAmount + 0.0001 >= $totalAmount) {
        $newPaymentStatus = 'collected';
    } elseif ($newCollectedAmount > 0.0) {
        $newPaymentStatus = 'partial';
    }

    $latestActiveCollectionAfterCancel = car_sale_latest_active_collection($collectionsBySaleId[$saleId] ?? []);
    $latestCollectedAt = $latestActiveCollectionAfterCancel['collected_at'] ?? null;
    $latestCollectedBy = $latestActiveCollectionAfterCancel['collected_by_user_id'] ?? null;

    $saleUpdate = $pdo->prepare('UPDATE car_sales SET payment_status = ?, collected_at = ?, collected_by_user_id = ? WHERE id = ? AND company_id = ?');
    $saleUpdate->execute([
        $newPaymentStatus,
        $latestCollectedAt,
        $latestCollectedBy,
        $saleId,
        $companyId,
    ]);

    auth_audit_log($pdo, 'car.sale_collection_cancelled', 'Arac satis tahsilati geri alindi.', [
        'entity_type' => 'car_sale_collection',
        'entity_id' => $collectionId,
        'company_id' => $companyId,
        'metadata' => [
            'car_id' => $carId,
            'sale_id' => $saleId,
            'amount' => (float) ($collection['amount'] ?? 0),
            'payment_status' => $newPaymentStatus,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Car sale collection cancel failed: ' . $exception->getMessage());
    $fail($carId, $saleId, 'car_sale_collection_cancel_failed', $returnTo);
}

redirect('../' . $buildRedirectTarget($carId, $saleId, 'car_sale_collection_cancelled', $returnTo));
