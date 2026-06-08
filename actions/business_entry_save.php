<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureBusinessAccountsSchema($pdo);
ensureBusinessExpenseOwnerSchema($pdo);
$companyId = auth_current_company_id();

$id = (int) ($_POST['id'] ?? 0);
$periodId = (int) ($_POST['period_id'] ?? 0);
$type = ($_POST['type'] ?? 'income') === 'expense' ? 'expense' : 'income';
$partnerId = (int) ($_POST['partner_id'] ?? 0);
$carLabel = trim((string) ($_POST['car_label'] ?? ''));
$amount = (isset($_POST['amount']) && $_POST['amount'] !== '') ? max(0, (float) $_POST['amount']) : 0.0;
$entryDate = $_POST['entry_date'] ?? '';
$note = trim((string) ($_POST['note'] ?? ''));
$partnerSt = $pdo->prepare('SELECT * FROM ledger_partners WHERE id = ? AND company_id = ?');
$partnerSt->execute([$partnerId, $companyId]);
$partner = $partnerSt->fetch(PDO::FETCH_ASSOC);

if (!$partner || $amount <= 0) {
    redirect('../business_accounts.php?entry_status=invalid');
}

$entryDateFormatted = date('Y-m-d H:i:s', strtotime($entryDate ?: date('Y-m-d H:i:s')));
$expenseTitleBase = $note !== '' ? $note : ($carLabel !== '' ? $carLabel : 'Gelir Gider Kaydi');
$expenseTitle = trim($partner['name'] . ' - ' . $expenseTitleBase);
$mirroredExpenseOwner = trim((string) ($partner['name'] ?? ''));
$linkedExpenseId = null;
$legacySyncFailed = false;

if (!function_exists('safeLegacyBusinessAccountPeriodSync')) {
    function safeLegacyBusinessAccountPeriodSync(PDO $pdo, int $companyId, int $periodId): bool
    {
        try {
            ensureLegacyBusinessAccountPeriod($pdo, $companyId, $periodId);
            return true;
        } catch (Throwable $e) {
            error_log('Legacy business account period sync failed: ' . $e->getMessage());
            return false;
        }
    }
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $existingSt = $pdo->prepare('SELECT * FROM ledger_entries WHERE id = ? AND company_id = ?');
        $existingSt->execute([$id, $companyId]);
        $existingEntry = $existingSt->fetch(PDO::FETCH_ASSOC);
        if (!$existingEntry) {
            throw new RuntimeException('Kayit bulunamadi.');
        }

        $periodId = (int) $existingEntry['period_id'];
        $legacySyncFailed = !safeLegacyBusinessAccountPeriodSync($pdo, $companyId, $periodId) || $legacySyncFailed;
        $linkedExpenseId = isset($existingEntry['business_expense_id']) ? (int) $existingEntry['business_expense_id'] : null;

        if ($type === 'expense') {
            if ($linkedExpenseId) {
                $expenseUpdate = $pdo->prepare('UPDATE business_expenses SET title = ?, owner_name = ?, amount = ?, expense_date = ? WHERE id = ? AND company_id = ?');
                $expenseUpdate->execute([$expenseTitle, $mirroredExpenseOwner, $amount, date('Y-m-d', strtotime($entryDateFormatted)), $linkedExpenseId, $companyId]);
            } else {
                $expenseInsert = $pdo->prepare('INSERT INTO business_expenses (company_id, title, owner_name, amount, expense_date) VALUES (?, ?, ?, ?, ?)');
                $expenseInsert->execute([$companyId, $expenseTitle, $mirroredExpenseOwner, $amount, date('Y-m-d', strtotime($entryDateFormatted))]);
                $linkedExpenseId = (int) $pdo->lastInsertId();
            }
        }

        $ledgerUpdate = $pdo->prepare('UPDATE ledger_entries SET partner_id = ?, business_expense_id = ?, type = ?, car_label = ?, amount = ?, note = ?, entry_date = ? WHERE id = ? AND company_id = ?');
        $ledgerUpdate->execute([$partnerId, $linkedExpenseId ?: null, $type, $carLabel !== '' ? $carLabel : null, $amount, $note !== '' ? $note : null, $entryDateFormatted, $id, $companyId]);

        try {
            $legacyUpdate = $pdo->prepare('UPDATE business_account_entries SET period_id = ?, partner_id = ?, business_expense_id = ?, type = ?, car_label = ?, amount = ?, note = ?, entry_date = ? WHERE id = ?');
            $legacyUpdate->execute([$periodId, $partnerId, $linkedExpenseId ?: null, $type, $carLabel !== '' ? $carLabel : null, $amount, $note !== '' ? $note : null, $entryDateFormatted, $id]);
        } catch (Throwable $e) {
            $legacySyncFailed = true;
            error_log('Legacy business account entry update failed: ' . $e->getMessage());
        }

    } else {
        $openPeriod = getOpenBusinessAccountPeriod($pdo, $companyId);
        $periodId = (int) $openPeriod['id'];
        $legacySyncFailed = !safeLegacyBusinessAccountPeriodSync($pdo, $companyId, $periodId) || $legacySyncFailed;

        if ($type === 'expense') {
            $expenseInsert = $pdo->prepare('INSERT INTO business_expenses (company_id, title, owner_name, amount, expense_date) VALUES (?, ?, ?, ?, ?)');
            $expenseInsert->execute([$companyId, $expenseTitle, $mirroredExpenseOwner, $amount, date('Y-m-d', strtotime($entryDateFormatted))]);
            $linkedExpenseId = (int) $pdo->lastInsertId();
        }

        $ledgerInsert = $pdo->prepare('INSERT INTO ledger_entries (company_id, period_id, partner_id, business_expense_id, type, car_label, amount, note, entry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ledgerInsert->execute([$companyId, $periodId, $partnerId, $linkedExpenseId ?: null, $type, $carLabel !== '' ? $carLabel : null, $amount, $note !== '' ? $note : null, $entryDateFormatted]);
        $id = (int) $pdo->lastInsertId();

        try {
            $legacyInsert = $pdo->prepare('INSERT INTO business_account_entries (id, period_id, partner_id, business_expense_id, type, car_label, amount, note, entry_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $legacyInsert->execute([$id, $periodId, $partnerId, $linkedExpenseId ?: null, $type, $carLabel !== '' ? $carLabel : null, $amount, $note !== '' ? $note : null, $entryDateFormatted]);
        } catch (Throwable $e) {
            $legacySyncFailed = true;
            error_log('Legacy business account entry insert failed: ' . $e->getMessage());
        }

    }

    $pdo->commit();
    redirect('../business_accounts.php?entry_status=saved&entry_id=' . $id . ($legacySyncFailed ? '&legacy_sync=warning' : ''));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Business entry save failed: ' . $e->getMessage());
    redirect('../business_accounts.php?entry_status=error');
}
