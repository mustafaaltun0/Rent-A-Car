<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('expenses.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureExpenseArchiveSchema($pdo);

$id = (int) ($_POST['id'] ?? 0);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

if ($id > 0) {
    $expenseSt = $pdo->prepare('SELECT * FROM business_expenses WHERE id = ? AND company_id = ? AND archived_at IS NOT NULL LIMIT 1');
    $expenseSt->execute([$id, $companyId]);
    $expense = $expenseSt->fetch(PDO::FETCH_ASSOC);

    if ($expense) {
        $pdo->prepare('UPDATE business_expenses SET archived_at = NULL, archived_by_user_id = NULL, archive_reason = NULL WHERE id = ? AND company_id = ?')->execute([
            $id,
            $companyId,
        ]);

        auth_audit_log($pdo, 'expense.restored', 'Isletme gideri arsivden geri yuklendi.', [
            'entity_type' => 'business_expense',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'title' => $expense['title'] ?? null,
                'restored_by_user_id' => $currentUserId > 0 ? $currentUserId : null,
            ],
        ]);

        redirect('../expenses.php?status=restored');
    }
}

redirect('../expenses.php?show_archived=1');
