<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureBusinessAccountsSchema($pdo);

$companyId = auth_current_company_id();
$periodId = (int) ($_POST['period_id'] ?? 0);
$manualSharedIncome = isset($_POST['manual_shared_income']) && $_POST['manual_shared_income'] !== ''
    ? max(0, (float) $_POST['manual_shared_income'])
    : 0.0;

if ($periodId <= 0) {
    redirect('../business_accounts.php?share_status=invalid');
}

$periodSt = $pdo->prepare('SELECT * FROM ledger_periods WHERE id = ? AND company_id = ?');
$periodSt->execute([$periodId, $companyId]);
$period = $periodSt->fetch(PDO::FETCH_ASSOC);
if (!$period) {
    redirect('../business_accounts.php?share_status=invalid');
}

try {
    $pdo->beginTransaction();

    $updateSt = $pdo->prepare('UPDATE ledger_periods SET manual_shared_income = ? WHERE id = ? AND company_id = ?');
    $updateSt->execute([$manualSharedIncome, $periodId, $companyId]);

    $pdo->commit();

    auth_audit_log($pdo, 'ledger.period_share_updated', 'Donem kisi basi pay ek tutari guncellendi.', [
        'entity_type' => 'ledger_period',
        'entity_id' => $periodId,
        'metadata' => [
            'manual_shared_income' => $manualSharedIncome,
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Business period share update failed: ' . $e->getMessage());
    redirect('../business_accounts.php?share_status=error' . ($periodId > 0 ? '&period_id=' . $periodId : ''));
}

$query = 'share_status=saved';
if ($periodId > 0) {
    $query .= '&period_id=' . $periodId;
}
redirect('../business_accounts.php?' . $query);
