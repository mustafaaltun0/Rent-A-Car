<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!auth_can('ledger.manage') && !auth_can('ledger.entry.create') && !auth_can('ledger.entry.update')) {
    auth_abort('Bu islem icin yetkiniz yok.', 403);
}

auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'finance_core');
$companyId = auth_current_company_id();

$id = (int) ($_POST['id'] ?? 0);
$periodId = (int) ($_POST['period_id'] ?? 0);
$type = ($_POST['type'] ?? 'income') === 'expense' ? 'expense' : 'income';
$partnerId = (int) ($_POST['partner_id'] ?? 0);
$carId = (int) ($_POST['car_id'] ?? 0);
$carLabel = trim((string) ($_POST['car_label'] ?? ''));
$amount = (isset($_POST['amount']) && $_POST['amount'] !== '') ? max(0, (float) $_POST['amount']) : 0.0;
$entryDate = $_POST['entry_date'] ?? '';
$note = trim((string) ($_POST['note'] ?? ''));

$partnerSt = $pdo->prepare('SELECT * FROM ledger_partners WHERE id = ? AND company_id = ?');
$partnerSt->execute([$partnerId, $companyId]);
$partner = $partnerSt->fetch(PDO::FETCH_ASSOC);
$car = null;

if ($carId > 0) {
    $carSt = $pdo->prepare('SELECT id, plate, brand, model FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $carSt->execute([$carId, $companyId]);
    $car = $carSt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$car) {
        redirect('../business_accounts.php?entry_status=invalid');
    }

    $carLabel = trim(((string) ($car['brand'] ?? '')) . ' ' . ((string) ($car['model'] ?? '')));
    if (!empty($car['plate'])) {
        $carLabel .= ($carLabel !== '' ? ' / ' : '') . (string) $car['plate'];
    }
}

if (!$partner || $amount <= 0) {
    redirect('../business_accounts.php?entry_status=invalid');
}

$entryTimestamp = strtotime($entryDate ?: date('Y-m-d H:i:s'));
if ($entryTimestamp === false) {
    redirect('../business_accounts.php?entry_status=invalid');
}

$entryDateFormatted = date('Y-m-d H:i:s', $entryTimestamp);
$expenseTitleBase = $note !== '' ? $note : ($carLabel !== '' ? $carLabel : 'Gelir Gider Kaydi');
$expenseTitle = trim($partner['name'] . ' - ' . $expenseTitleBase);
$mirroredExpenseOwner = trim((string) ($partner['name'] ?? ''));
$linkedExpenseId = null;

if ($id > 0) {
    if (!auth_can('ledger.manage') && !auth_can('ledger.entry.update')) {
        auth_abort('Bu islem icin yetkiniz yok.', 403);
    }
} else {
    if (!auth_can('ledger.manage') && !auth_can('ledger.entry.create')) {
        auth_abort('Bu islem icin yetkiniz yok.', 403);
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

        $ledgerUpdate = $pdo->prepare('UPDATE ledger_entries SET partner_id = ?, car_id = ?, business_expense_id = ?, type = ?, car_label = ?, amount = ?, note = ?, entry_date = ? WHERE id = ? AND company_id = ?');
        $ledgerUpdate->execute([$partnerId, $carId > 0 ? $carId : null, $linkedExpenseId ?: null, $type, $carLabel !== '' ? $carLabel : null, $amount, $note !== '' ? $note : null, $entryDateFormatted, $id, $companyId]);

        auth_audit_log($pdo, 'ledger.entry_updated', 'Gelir gider hareketi guncellendi.', [
            'entity_type' => 'ledger_entry',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'type' => $type,
                'partner_id' => $partnerId,
                'partner_name' => $partner['name'] ?? '',
                'car_id' => $carId,
                'amount' => $amount,
                'car_label' => $carLabel,
                'note' => $note,
                'entry_date' => $entryDateFormatted,
            ],
        ]);
    } else {
        $openPeriod = getOpenBusinessAccountPeriod($pdo, $companyId);
        $periodId = (int) $openPeriod['id'];

        if ($type === 'expense') {
            $expenseInsert = $pdo->prepare('INSERT INTO business_expenses (company_id, title, owner_name, amount, expense_date) VALUES (?, ?, ?, ?, ?)');
            $expenseInsert->execute([$companyId, $expenseTitle, $mirroredExpenseOwner, $amount, date('Y-m-d', strtotime($entryDateFormatted))]);
            $linkedExpenseId = (int) $pdo->lastInsertId();
        }

        $ledgerInsert = $pdo->prepare('INSERT INTO ledger_entries (company_id, period_id, partner_id, car_id, business_expense_id, type, car_label, amount, note, entry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ledgerInsert->execute([$companyId, $periodId, $partnerId, $carId > 0 ? $carId : null, $linkedExpenseId ?: null, $type, $carLabel !== '' ? $carLabel : null, $amount, $note !== '' ? $note : null, $entryDateFormatted]);
        $id = (int) $pdo->lastInsertId();

        auth_audit_log($pdo, 'ledger.entry_created', 'Yeni gelir gider hareketi olusturuldu.', [
            'entity_type' => 'ledger_entry',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'type' => $type,
                'partner_id' => $partnerId,
                'partner_name' => $partner['name'] ?? '',
                'car_id' => $carId,
                'amount' => $amount,
                'car_label' => $carLabel,
                'note' => $note,
                'entry_date' => $entryDateFormatted,
            ],
        ]);
    }

    $pdo->commit();
    redirect('../business_accounts.php?entry_status=saved&entry_id=' . $id);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('business_entry_save_failed: ' . $exception->getMessage());
    redirect('../business_accounts.php?entry_status=error');
}
