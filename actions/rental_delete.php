<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.archive');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_documents');

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $st->execute([$id, $companyId]);
    $rental = $st->fetch(PDO::FETCH_ASSOC);
    $carId = (int) ($rental['car_id'] ?? 0);

    if ($rental) {
        $pdo->beginTransaction();

        try {
            $archiveReason = 'Kullanici tarafindan arsive alindi.';
            $pdo->prepare('UPDATE rentals SET archived_at = NOW(), archived_by_user_id = ?, archive_reason = ? WHERE id = ? AND company_id = ?')->execute([
                $currentUserId > 0 ? $currentUserId : null,
                $archiveReason,
                $id,
                $companyId,
            ]);

            if ($carId > 0) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND company_id = ? AND archived_at IS NULL');
                $check->execute([$carId, $companyId]);
                if ((int) $check->fetchColumn() === 0) {
                    $pdo->prepare('UPDATE cars SET available = 1 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
                }
            }

            auth_audit_log($pdo, 'rental.archived', 'Kiralama kaydi arsive alindi.', [
                'entity_type' => 'rental',
                'entity_id' => $id,
                'company_id' => $companyId,
                'metadata' => [
                    'customer_name' => $rental['customer_name'] ?? null,
                    'car_id' => $carId,
                    'archived_by_user_id' => $currentUserId > 0 ? $currentUserId : null,
                    'archive_reason' => $archiveReason,
                ],
            ]);

            $pdo->commit();
            redirect('../rentals.php?status=archived');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('rental_delete_failed: ' . $exception->getMessage());
            redirect('../rentals.php?status=delete_error');
        }
    }
}

redirect('../rentals.php');
