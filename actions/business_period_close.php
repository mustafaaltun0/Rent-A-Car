<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'finance_core');
$companyId = auth_current_company_id();
$openPeriod = getOpenBusinessAccountPeriod($pdo, $companyId);

$pdo->beginTransaction();
try {
    $closeSt = $pdo->prepare("UPDATE ledger_periods SET status = 'CLOSED', settled_at = NOW() WHERE id = ? AND company_id = ?");
    $closeSt->execute([(int) $openPeriod['id'], $companyId]);

    $createSt = $pdo->prepare("INSERT INTO ledger_periods (company_id, label, started_at, status) VALUES (?, ?, NOW(), 'OPEN')");
    $createSt->execute([$companyId, 'Acik Hesap']);
    $newPeriodId = (int) $pdo->lastInsertId();

    $pdo->commit();
    auth_audit_log($pdo, 'ledger.period_closed', 'Acik hesap donemi kapatildi ve yeni donem acildi.', [
        'entity_type' => 'ledger_period',
        'entity_id' => (int) $openPeriod['id'],
        'metadata' => [
            'new_period_id' => $newPeriodId,
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('business_period_close_failed: ' . $e->getMessage());
    redirect('../business_accounts.php?period_status=error');
}

redirect('../business_accounts.php?period_status=closed');
