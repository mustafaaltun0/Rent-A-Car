<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.sale.update');
auth_require_post_request();
auth_validate_csrf_request();

ensureCarSaleSchema($pdo);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$saleId = (int) ($_POST['sale_id'] ?? 0);
$carId = (int) ($_POST['car_id'] ?? 0);
$buyerName = trim((string) ($_POST['buyer_name'] ?? ''));
$buyerPhone = trim((string) ($_POST['buyer_phone'] ?? ''));
$saleDateInput = trim((string) ($_POST['sale_date'] ?? ''));
$saleDateTimestamp = $saleDateInput !== '' ? strtotime($saleDateInput) : false;
$totalAmount = isset($_POST['total_amount']) && $_POST['total_amount'] !== '' ? (float) $_POST['total_amount'] : 0.0;
$desiredCollectedAmount = isset($_POST['collected_amount']) && $_POST['collected_amount'] !== '' ? (float) $_POST['collected_amount'] : 0.0;
$paymentDueDateInput = trim((string) ($_POST['payment_due_date'] ?? ''));
$paymentDueDateTimestamp = $paymentDueDateInput !== '' ? strtotime($paymentDueDateInput) : false;
$note = trim((string) ($_POST['note'] ?? ''));
$returnTo = trim((string) ($_POST['return_to'] ?? ('car_sale_collect.php?car_id=' . $carId)));

$buildRedirectTarget = static function (int $safeCarId, string $status, string $requestedPath = ''): string {
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

if ($saleId <= 0 || $carId <= 0 || $buyerName === '' || $totalAmount <= 0.0 || $saleDateTimestamp === false || $desiredCollectedAmount < 0.0) {
    redirect('../' . $buildRedirectTarget($carId, 'car_sale_update_invalid', $returnTo));
}

if ($companyId <= 0 || $currentUserId <= 0) {
    redirect('../' . $buildRedirectTarget($carId, 'unauthorized', $returnTo));
}

$saleSt = $pdo->prepare('SELECT * FROM car_sales WHERE id = ? AND car_id = ? AND company_id = ? AND sale_status = ? LIMIT 1');
$saleSt->execute([$saleId, $carId, $companyId, 'active']);
$sale = $saleSt->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
    redirect('../' . $buildRedirectTarget($carId, 'car_sale_invalid', $returnTo));
}

$collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [$saleId]);
$saleCollections = $collectionsBySaleId[$saleId] ?? [];
$latestActiveCollection = car_sale_latest_active_collection($saleCollections);
$oldCollectedAmount = car_sale_collected_amount($sale, $collectionsBySaleId);
$otherCollectedAmount = 0.0;

foreach ($saleCollections as $collection) {
    if (!car_sale_collection_is_active($collection)) {
        continue;
    }

    if ($latestActiveCollection && (int) ($collection['id'] ?? 0) === (int) ($latestActiveCollection['id'] ?? 0)) {
        continue;
    }

    $otherCollectedAmount += max(0.0, (float) ($collection['amount'] ?? 0));
}

if ($desiredCollectedAmount + 0.0001 < $otherCollectedAmount || $desiredCollectedAmount - $totalAmount > 0.0001) {
    redirect('../' . $buildRedirectTarget($carId, 'car_sale_update_conflict', $returnTo));
}

$saleDate = date('Y-m-d H:i:s', $saleDateTimestamp);
$paymentDueDate = $paymentDueDateTimestamp !== false ? date('Y-m-d H:i:s', $paymentDueDateTimestamp) : null;

try {
    $pdo->beginTransaction();

    if ($latestActiveCollection) {
        $latestCollectionNewAmount = max(0.0, $desiredCollectedAmount - $otherCollectedAmount);
        if ($latestCollectionNewAmount <= 0.0) {
            throw new RuntimeException('Latest collection amount must stay positive.');
        }

        $updateCollection = $pdo->prepare('UPDATE car_sale_collections SET amount = ? WHERE id = ? AND company_id = ?');
        $updateCollection->execute([
            $latestCollectionNewAmount,
            (int) ($latestActiveCollection['id'] ?? 0),
            $companyId,
        ]);
    } elseif ($desiredCollectedAmount > 0.0) {
        $insertCollection = $pdo->prepare("
            INSERT INTO car_sale_collections (
                company_id, car_sale_id, amount, payment_method, note, collection_status, collected_at, collected_by_user_id
            ) VALUES (?, ?, ?, ?, ?, 'active', ?, ?)
        ");
        $insertCollection->execute([
            $companyId,
            $saleId,
            $desiredCollectedAmount,
            'Duzenleme',
            $note !== '' ? $note : null,
            $saleDate,
            null,
        ]);
    }

    $collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [$saleId]);
    $newCollectedAmount = car_sale_collected_amount($sale, $collectionsBySaleId);
    if ($newCollectedAmount - $totalAmount > 0.0001) {
        throw new RuntimeException('Collected amount exceeds total amount.');
    }

    $paymentStatus = 'pending';
    if ($newCollectedAmount <= 0.0001) {
        $paymentStatus = 'pending';
    } elseif ($newCollectedAmount + 0.0001 < $totalAmount) {
        $paymentStatus = 'partial';
    } else {
        $paymentStatus = 'collected';
        $paymentDueDate = null;
    }

    $latestActiveCollectionAfterUpdate = car_sale_latest_active_collection($collectionsBySaleId[$saleId] ?? []);
    $latestCollectedAt = $latestActiveCollectionAfterUpdate['collected_at'] ?? null;
    $latestCollectedBy = $latestActiveCollectionAfterUpdate['collected_by_user_id'] ?? null;

    $update = $pdo->prepare("
        UPDATE car_sales
        SET buyer_name = ?, buyer_phone = ?, sale_date = ?, total_amount = ?, payment_due_date = ?, payment_status = ?, collected_at = ?, collected_by_user_id = ?, note = ?
        WHERE id = ? AND car_id = ? AND company_id = ?
    ");
    $update->execute([
        $buyerName,
        $buyerPhone !== '' ? $buyerPhone : null,
        $saleDate,
        $totalAmount,
        $paymentDueDate,
        $paymentStatus,
        $latestCollectedAt,
        $latestCollectedBy,
        $note !== '' ? $note : null,
        $saleId,
        $carId,
        $companyId,
    ]);

    $carUpdate = $pdo->prepare('UPDATE cars SET sold_at = ?, sale_note = ? WHERE id = ? AND company_id = ?');
    $carUpdate->execute([
        $saleDate,
        $note !== '' ? $note : null,
        $carId,
        $companyId,
    ]);

    auth_audit_log($pdo, 'car.sale_updated', 'Arac satis kaydi guncellendi.', [
        'entity_type' => 'car_sale',
        'entity_id' => $saleId,
        'company_id' => $companyId,
        'metadata' => [
            'car_id' => $carId,
            'old_total_amount' => (float) ($sale['total_amount'] ?? 0),
            'new_total_amount' => $totalAmount,
            'old_collected_amount' => $oldCollectedAmount,
            'new_collected_amount' => $newCollectedAmount,
            'payment_status' => $paymentStatus,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('car_sale_update_failed: ' . $exception->getMessage());
    redirect('../' . $buildRedirectTarget($carId, 'car_sale_update_conflict', $returnTo));
}

redirect('../' . $buildRedirectTarget($carId, 'car_sale_updated', $returnTo));
