<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.sale.collect');
auth_require_post_request();
auth_validate_csrf_request();

ensureCarSaleSchema($pdo);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$carId = (int) ($_POST['car_id'] ?? 0);
$saleId = (int) ($_POST['sale_id'] ?? 0);
$amount = ($_POST['amount'] ?? '') !== '' ? (float) $_POST['amount'] : 0.0;
$paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$returnTo = trim((string) ($_POST['return_to'] ?? ''));

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

$fail = static function (int $safeCarId, string $status, string $returnToPath = '') use ($buildRedirectTarget): void {
    redirect('../' . $buildRedirectTarget($safeCarId, $status, $returnToPath));
};

if ($carId <= 0 || $saleId <= 0) {
    redirect('../cars.php');
}

if ($companyId <= 0 || $currentUserId <= 0) {
    $fail($carId, 'unauthorized', $returnTo);
}

$saleSt = $pdo->prepare("SELECT * FROM car_sales WHERE id = ? AND company_id = ? AND car_id = ? AND sale_status = 'active' LIMIT 1");
$saleSt->execute([$saleId, $companyId, $carId]);
$sale = $saleSt->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
    $fail($carId, 'car_sale_invalid', $returnTo);
}

$collectionsBySaleId = getCarSaleCollectionsBySaleId($pdo, $companyId, [$saleId]);
$remainingAmount = car_sale_pending_amount($sale, $collectionsBySaleId);
$alreadyCollected = car_sale_collected_amount($sale, $collectionsBySaleId);

if ($remainingAmount <= 0.0) {
    $fail($carId, 'car_sale_collected', $returnTo);
}

if ($amount <= 0.0 || $amount - $remainingAmount > 0.0001) {
    $fail($carId, 'car_sale_collection_invalid', $returnTo);
}

try {
    $pdo->beginTransaction();

    $collectionInsert = $pdo->prepare("
        INSERT INTO car_sale_collections (
            company_id, car_sale_id, amount, payment_method, note, collection_status, collected_at, collected_by_user_id
        ) VALUES (?, ?, ?, ?, ?, 'active', NOW(), ?)
    ");
    $collectionInsert->execute([
        $companyId,
        $saleId,
        $amount,
        $paymentMethod !== '' ? $paymentMethod : null,
        $note !== '' ? $note : null,
        $currentUserId,
    ]);

    $newCollectedAmount = min((float) ($sale['total_amount'] ?? 0), $alreadyCollected + $amount);
    $newPaymentStatus = $newCollectedAmount + 0.0001 >= (float) ($sale['total_amount'] ?? 0) ? 'collected' : 'partial';

    $update = $pdo->prepare('UPDATE car_sales SET payment_status = ?, collected_at = NOW(), collected_by_user_id = ? WHERE id = ? AND company_id = ?');
    $update->execute([
        $newPaymentStatus,
        $currentUserId,
        $saleId,
        $companyId,
    ]);

    auth_audit_log($pdo, 'car.sale_collected', 'Arac satis tahsilati kaydedildi.', [
        'entity_type' => 'car_sale',
        'entity_id' => $saleId,
        'company_id' => $companyId,
        'metadata' => [
            'car_id' => $carId,
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
    error_log('Car sale collect failed: ' . $exception->getMessage());
    $fail($carId, 'car_sale_collection_save_failed', $returnTo);
}

redirect('../' . $buildRedirectTarget($carId, 'car_sale_collected', $returnTo));
