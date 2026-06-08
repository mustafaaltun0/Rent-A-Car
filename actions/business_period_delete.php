<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureBusinessAccountsSchema($pdo);
$companyId = auth_current_company_id();

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $st = $pdo->prepare("DELETE FROM ledger_periods WHERE id = ? AND company_id = ? AND status = 'CLOSED'");
    $st->execute([$id, $companyId]);
    if ($st->rowCount() > 0) {
        auth_audit_log($pdo, 'ledger.period_deleted', 'Kapali hesap donemi silindi.', [
            'entity_type' => 'ledger_period',
            'entity_id' => $id,
        ]);
    }
}

redirect('../business_accounts.php');
