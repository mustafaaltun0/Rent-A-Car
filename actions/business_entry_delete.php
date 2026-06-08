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
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('DELETE FROM ledger_entries WHERE id = ? AND company_id = ?');
        $st->execute([$id, $companyId]);

        try {
            $legacy = $pdo->prepare('DELETE FROM business_account_entries WHERE id = ?');
            $legacy->execute([$id]);
        } catch (Throwable $e) {
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

redirect('../business_accounts.php');
