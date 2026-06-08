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
$cancelReasonOption = trim((string) ($_POST['cancel_reason_option'] ?? ''));
$cancelReasonDetail = trim((string) ($_POST['cancel_reason_detail'] ?? ''));
$cancelReason = $cancelReasonOption;
if ($cancelReasonOption === 'Diger' && $cancelReasonDetail !== '') {
    $cancelReason = $cancelReasonDetail;
} elseif ($cancelReasonOption !== '' && $cancelReasonDetail !== '') {
    $cancelReason = $cancelReasonOption . ' - ' . $cancelReasonDetail;
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
$activeCollections = rental_extension_active_collections($collectionsByExtensionId[$extensionId] ?? []);
$cancelledCollectionCount = 0;
$cancelledCollectionTotal = 0.0;

$pdo->beginTransaction();

try {
    if (!empty($activeCollections)) {
        $cancelCollectionUpdate = $pdo->prepare('
            UPDATE rental_extension_collections
            SET collection_status = ?, cancelled_at = NOW(), cancelled_by_user_id = ?, cancel_reason = ?
            WHERE id = ? AND rental_extension_id = ? AND company_id = ?
        ');

        foreach ($activeCollections as $collection) {
            $cancelCollectionUpdate->execute([
                'cancelled',
                $currentUserId > 0 ? $currentUserId : null,
                $cancelReason !== '' ? $cancelReason : 'Uzatma iptal edildi.',
                (int) ($collection['id'] ?? 0),
                $extensionId,
                $companyId,
            ]);

            $cancelledCollectionCount++;
            $cancelledCollectionTotal += max(0.0, (float) ($collection['amount'] ?? 0));
        }
    }

    $update = $pdo->prepare('
        UPDATE rental_extensions
        SET extension_status = ?, payment_status = ?, collected_at = NULL, collected_by_user_id = NULL, cancelled_at = NOW(), cancelled_by_user_id = ?, cancel_reason = ?
        WHERE id = ? AND rental_id = ? AND company_id = ?
    ');
    $update->execute([
        'cancelled',
        'pending',
        $currentUserId > 0 ? $currentUserId : null,
        $cancelReason !== '' ? $cancelReason : null,
        $extensionId,
        $rentalId,
        $companyId,
    ]);

    $extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
    rental_refresh_end_date($pdo, $rental, $extensionsByRentalId);

    rental_extension_record_revision($pdo, $companyId, $rentalId, $extensionId, 'cancelled', $latestActiveExtension, [
        'extension_status' => 'cancelled',
        'payment_status' => 'pending',
        'cancel_reason' => $cancelReason !== '' ? $cancelReason : null,
        'cancelled_collection_count' => $cancelledCollectionCount,
        'cancelled_collection_total' => $cancelledCollectionTotal,
    ], $currentUserId);

    auth_audit_log($pdo, 'rental.extension_cancelled', 'Son uzatma kaydi iptal edildi.', [
        'entity_type' => 'rental_extension',
        'entity_id' => $extensionId,
        'company_id' => $companyId,
        'metadata' => [
            'rental_id' => $rentalId,
            'cancel_reason' => $cancelReason,
            'cancelled_collection_count' => $cancelledCollectionCount,
            'cancelled_collection_total' => $cancelledCollectionTotal,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_cancelled');
