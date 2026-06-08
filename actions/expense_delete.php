<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

ensureExpenseArchiveSchema($pdo);

auth_require_permission('expenses.manage');
auth_require_post_request();
auth_validate_csrf_request();

$id = (int) ($_POST['id'] ?? 0);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
if ($id > 0) {
    $expenseSt = $pdo->prepare('SELECT * FROM business_expenses WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $expenseSt->execute([$id, $companyId]);
    $expense = $expenseSt->fetch(PDO::FETCH_ASSOC);

    if ($expense) {
        $archiveReason = 'Kullanici tarafindan arsive alindi.';
        $pdo->prepare('UPDATE business_expenses SET archived_at = NOW(), archived_by_user_id = ?, archive_reason = ? WHERE id = ? AND company_id = ?')->execute([
            $currentUserId > 0 ? $currentUserId : null,
            $archiveReason,
            $id,
            $companyId,
        ]);

        auth_audit_log($pdo, 'expense.archived', 'Isletme gideri arsive alindi.', [
            'entity_type' => 'business_expense',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'title' => $expense['title'] ?? null,
                'amount' => isset($expense['amount']) ? (float) $expense['amount'] : null,
                'archived_by_user_id' => $currentUserId > 0 ? $currentUserId : null,
            ],
        ]);

        redirect('../expenses.php?status=archived');
    }
}

redirect('../expenses.php');
