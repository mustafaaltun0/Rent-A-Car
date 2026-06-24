<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.sale.collection.update');
auth_require_post_request();
auth_validate_csrf_request();

ensureCarSaleSchema($pdo);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$collectionId = (int) ($_POST['collection_id'] ?? 0);
$saleId = (int) ($_POST['sale_id'] ?? 0);
$carId = (int) ($_POST['car_id'] ?? 0);
$amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float) $_POST['amount'] : 0.0;
$paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$collectedAtInput = trim((string) ($_POST['collected_at'] ?? ''));
$returnTo = trim((string) ($_POST['return_to'] ?? ''));

$buildRedirectTarget = static function (int $safeCarId, int $safeCollectionId, string $status, string $requestedPath = ''): string {
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
        $queryParams['edit_collection_id'] = $safeCollectionId;
    }

    $queryParams['status'] = $status;

    return $path . '?' . http_build_query($queryParams);
};

$fail = static function (int $safeCarId, int $safeCollectionId, string $status, string $returnToPath = '') use ($buildRedirectTarget): void {
    redirect('../' . $buildRedirectTarget($safeCarId, $safeCollectionId, $status, $returnToPath));
};

if ($collectionId <= 0 || $saleId <= 0 || $carId <= 0) {
    redirect('../cars.php');
}

if ($companyId <= 0 || $currentUserId <= 0) {
    $fail($carId, $collectionId, 'unauthorized', $returnTo);
}

$saleSt = $pdo->prepare("SELECT * FROM car_sales WHERE id = ? AND company_id = ? AND car_id = ? AND sale_status = 'active' LIMIT 1");
$saleSt->execute([$saleId, $companyId, $carId]);
$sale = $saleSt->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
    $fail($carId, $collectionId, 'car_sale_invalid', $returnTo);
}

$collectionSt = $pdo->prepare('SELECT * FROM car_sale_collections WHERE id = ? AND car_sale_id = ? AND company_id = ? LIMIT 1');
$collectionSt->execute([$collectionId, $saleId, $companyId]);
$collection = $collectionSt->fetch(PDO::FETCH_ASSOC);
if (!$collection || !car_sale_collection_is_active($collection)) {
    $fail($carId, $collectionId, 'car_sale_collection_not_reversible', $returnTo);
}

$collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [$saleId]);
$latestActiveCollection = car_sale_latest_active_collection($collectionsBySaleId[$saleId] ?? []);
if (!$latestActiveCollection || (int) ($latestActiveCollection['id'] ?? 0) !== $collectionId) {
    $fail($carId, $collectionId, 'car_sale_collection_not_reversible', $returnTo);
}

$collectedAtTimestamp = $collectedAtInput !== '' ? strtotime($collectedAtInput) : false;
if ($amount <= 0.0 || $collectedAtTimestamp === false) {
    $fail($carId, $collectionId, 'car_sale_collection_update_invalid', $returnTo);
}

$saleTotalAmount = max(0.0, (float) ($sale['total_amount'] ?? 0));
$otherCollectedAmount = 0.0;
foreach ($collectionsBySaleId[$saleId] ?? [] as $existingCollection) {
    if (!car_sale_collection_is_active($existingCollection)) {
        continue;
    }

    if ((int) ($existingCollection['id'] ?? 0) === $collectionId) {
        continue;
    }

    $otherCollectedAmount += max(0.0, (float) ($existingCollection['amount'] ?? 0));
}

$maxAllowedAmount = max(0.0, $saleTotalAmount - $otherCollectedAmount);
if ($amount - $maxAllowedAmount > 0.0001) {
    $fail($carId, $collectionId, 'car_sale_collection_update_conflict', $returnTo);
}

$newCollectedAt = date('Y-m-d H:i:s', $collectedAtTimestamp);

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE car_sale_collections
        SET amount = ?, payment_method = ?, note = ?, collected_at = ?
        WHERE id = ? AND car_sale_id = ? AND company_id = ?
    ");
    $update->execute([
        $amount,
        $paymentMethod !== '' ? $paymentMethod : null,
        $note !== '' ? $note : null,
        $newCollectedAt,
        $collectionId,
        $saleId,
        $companyId,
    ]);

    $collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [$saleId]);
    $newCollectedAmount = car_sale_collected_amount($sale, $collectionsBySaleId);
    $newPaymentStatus = 'pending';
    if ($saleTotalAmount <= 0.0 || $newCollectedAmount + 0.0001 >= $saleTotalAmount) {
        $newPaymentStatus = 'collected';
    } elseif ($newCollectedAmount > 0.0) {
        $newPaymentStatus = 'partial';
    }

    $latestActiveCollectionAfterUpdate = car_sale_latest_active_collection($collectionsBySaleId[$saleId] ?? []);
    $latestCollectedAt = $latestActiveCollectionAfterUpdate['collected_at'] ?? null;
    $latestCollectedBy = $latestActiveCollectionAfterUpdate['collected_by_user_id'] ?? null;

    $saleUpdate = $pdo->prepare('UPDATE car_sales SET payment_status = ?, collected_at = ?, collected_by_user_id = ? WHERE id = ? AND company_id = ?');
    $saleUpdate->execute([
        $newPaymentStatus,
        $latestCollectedAt,
        $latestCollectedBy,
        $saleId,
        $companyId,
    ]);

    auth_audit_log($pdo, 'car.sale_collection_updated', 'Arac satis tahsilati guncellendi.', [
        'entity_type' => 'car_sale_collection',
        'entity_id' => $collectionId,
        'company_id' => $companyId,
        'metadata' => [
            'car_id' => $carId,
            'sale_id' => $saleId,
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
    error_log('Car sale collection update failed: ' . $exception->getMessage());
    $fail($carId, $collectionId, 'car_sale_collection_update_failed', $returnTo);
}

redirect('../' . $buildRedirectTarget($carId, $collectionId, 'car_sale_collection_updated', $returnTo));
