<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'finance_core');
$companyId = auth_current_company_id();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('../business_accounts.php?period_status=invalid');
}

try {
    $st = $pdo->prepare("DELETE FROM ledger_periods WHERE id = ? AND company_id = ? AND status = 'CLOSED'");
    $st->execute([$id, $companyId]);
    if ($st->rowCount() > 0) {
        auth_audit_log($pdo, 'ledger.period_deleted', 'Kapali hesap donemi silindi.', [
            'entity_type' => 'ledger_period',
            'entity_id' => $id,
            'company_id' => $companyId,
        ]);
    }
} catch (Throwable $exception) {
    error_log('business_period_delete_failed: ' . $exception->getMessage());
    redirect('../business_accounts.php?period_status=error');
}

redirect('../business_accounts.php?period_status=deleted');
