<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_core');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$extensionId = (int) ($_POST['extension_id'] ?? 0);
$rentalId = (int) ($_POST['rental_id'] ?? 0);

if ($extensionId <= 0 || $rentalId <= 0) {
    redirect('../rentals.php');
}

$extensionSt = $pdo->prepare('
    SELECT re.*, r.customer_name, r.company_id AS rental_company_id
    FROM rental_extensions re
    INNER JOIN rentals r ON r.id = re.rental_id
    WHERE re.id = ? AND re.rental_id = ? AND r.company_id = ?
    LIMIT 1
');
$extensionSt->execute([$extensionId, $rentalId, $companyId]);
$extension = $extensionSt->fetch(PDO::FETCH_ASSOC);

if (!$extension) {
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_not_editable');
}

if (rental_extension_is_active($extension)) {
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_delete_requires_cancel');
}

$pdo->beginTransaction();

try {
    $collectionSummarySt = $pdo->prepare('SELECT COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount FROM rental_extension_collections WHERE rental_extension_id = ? AND company_id = ?');
    $collectionSummarySt->execute([$extensionId, $companyId]);
    $collectionSummary = $collectionSummarySt->fetch(PDO::FETCH_ASSOC) ?: ['total_count' => 0, 'total_amount' => 0];

    $revisionCountSt = $pdo->prepare('SELECT COUNT(*) FROM rental_extension_revisions WHERE rental_extension_id = ? AND company_id = ?');
    $revisionCountSt->execute([$extensionId, $companyId]);
    $revisionCount = (int) $revisionCountSt->fetchColumn();

    $pdo->prepare('DELETE FROM rental_extension_collections WHERE rental_extension_id = ? AND company_id = ?')->execute([$extensionId, $companyId]);
    $pdo->prepare('DELETE FROM rental_extension_revisions WHERE rental_extension_id = ? AND company_id = ?')->execute([$extensionId, $companyId]);
    $pdo->prepare('DELETE FROM rental_extensions WHERE id = ? AND rental_id = ? AND company_id = ?')->execute([$extensionId, $rentalId, $companyId]);

    $rentalSt = $pdo->prepare('SELECT * FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $rentalSt->execute([$rentalId, $companyId]);
    $rental = $rentalSt->fetch(PDO::FETCH_ASSOC);
    if ($rental) {
        $extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
        rental_refresh_end_date($pdo, $rental, $extensionsByRentalId);
    }

    auth_audit_log($pdo, 'rental.extension_deleted', 'Iptal edilmis uzatma kaydi tamamen silindi.', [
        'entity_type' => 'rental_extension',
        'entity_id' => $extensionId,
        'company_id' => $companyId,
        'metadata' => [
            'rental_id' => $rentalId,
            'customer_name' => $extension['customer_name'] ?? null,
            'deleted_by_user_id' => $currentUserId > 0 ? $currentUserId : null,
            'deleted_collection_count' => (int) ($collectionSummary['total_count'] ?? 0),
            'deleted_collection_total' => (float) ($collectionSummary['total_amount'] ?? 0),
            'deleted_revision_count' => $revisionCount,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('rental_extension_delete_failed: ' . $exception->getMessage());
    redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_delete_error');
}

redirect('../rental_detail.php?id=' . $rentalId . '&status=extension_deleted');
