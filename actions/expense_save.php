<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('expenses.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureBusinessExpenseOwnerSchema($pdo);
ensureExpenseArchiveSchema($pdo);
$companyId = auth_current_company_id();
$companyName = (string) (auth_current_user()['company_name'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
$ownerName = $companyName !== '' ? $companyName : null;
$data = [
    $_POST['title'] ?? '',
    $ownerName,
    ($_POST['amount'] !== '' ? (float) $_POST['amount'] : 0),
    $_POST['expense_date'] ?: null,
];

if ($id > 0) {
    $st = $pdo->prepare('UPDATE business_expenses SET title = ?, owner_name = ?, amount = ?, expense_date = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $data[] = $id;
    $data[] = $companyId;
    $st->execute($data);
} else {
    $st = $pdo->prepare('INSERT INTO business_expenses (company_id, title, owner_name, amount, expense_date) VALUES (?, ?, ?, ?, ?)');
    array_unshift($data, $companyId);
    $st->execute($data);
}

redirect('../expenses.php');
