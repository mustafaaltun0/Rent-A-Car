<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('expenses.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'finance_core');
$companyId = auth_current_company_id();
$companyName = (string) (auth_current_user()['company_name'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
$ownerName = $companyName !== '' ? $companyName : null;
$title = auth_trimmed_string($_POST['title'] ?? '', 255);
$amount = ($_POST['amount'] !== '' ? (float) $_POST['amount'] : 0);
$expenseDate = auth_nullable_trimmed_string($_POST['expense_date'] ?? '', 30);

if ($title === '' || $amount <= 0) {
    redirect('../expenses.php?status=invalid');
}

$expenseDateValue = null;
if ($expenseDate !== null) {
    $expenseDateTimestamp = strtotime($expenseDate);
    if ($expenseDateTimestamp === false) {
        redirect('../expenses.php?status=invalid');
    }

    $expenseDateValue = date('Y-m-d', $expenseDateTimestamp);
}

$data = [
    $title,
    $ownerName,
    $amount,
    $expenseDateValue,
];

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $st = $pdo->prepare('UPDATE business_expenses SET title = ?, owner_name = ?, amount = ?, expense_date = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
        $data[] = $id;
        $data[] = $companyId;
        $st->execute($data);

        if ($st->rowCount() < 1) {
            $existsSt = $pdo->prepare('SELECT id FROM business_expenses WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
            $existsSt->execute([$id, $companyId]);
            if (!$existsSt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Gider kaydi bulunamadi.');
            }
        }

        auth_audit_log($pdo, 'expense.updated', 'Gider kaydi guncellendi.', [
            'entity_type' => 'business_expense',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'title' => $title,
                'amount' => $amount,
                'expense_date' => $expenseDateValue,
            ],
        ]);
    } else {
        $st = $pdo->prepare('INSERT INTO business_expenses (company_id, title, owner_name, amount, expense_date) VALUES (?, ?, ?, ?, ?)');
        array_unshift($data, $companyId);
        $st->execute($data);
        $expenseId = (int) $pdo->lastInsertId();

        auth_audit_log($pdo, 'expense.created', 'Yeni gider kaydi olusturuldu.', [
            'entity_type' => 'business_expense',
            'entity_id' => $expenseId,
            'company_id' => $companyId,
            'metadata' => [
                'title' => $title,
                'amount' => $amount,
                'expense_date' => $expenseDateValue,
            ],
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('expense_save_failed: ' . $exception->getMessage());
    redirect('../expenses.php?status=error');
}

redirect('../expenses.php?status=saved');
