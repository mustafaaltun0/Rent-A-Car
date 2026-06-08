<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureBusinessAccountsSchema($pdo);
$companyId = auth_current_company_id();
$openPeriod = getOpenBusinessAccountPeriod($pdo, $companyId);
ensureLegacyBusinessAccountPeriod($pdo, $companyId, (int) $openPeriod['id']);

$pdo->beginTransaction();
try {
    $closeSt = $pdo->prepare("UPDATE ledger_periods SET status = 'CLOSED', settled_at = NOW() WHERE id = ? AND company_id = ?");
    $closeSt->execute([(int) $openPeriod['id'], $companyId]);

    $legacyCloseSt = $pdo->prepare("UPDATE business_account_periods SET status = 'CLOSED', settled_at = NOW() WHERE id = ?");
    $legacyCloseSt->execute([(int) $openPeriod['id']]);

    $createSt = $pdo->prepare("INSERT INTO ledger_periods (company_id, label, started_at, status) VALUES (?, ?, NOW(), 'OPEN')");
    $createSt->execute([$companyId, 'Acik Hesap']);
    $newPeriodId = (int) $pdo->lastInsertId();
    ensureLegacyBusinessAccountPeriod($pdo, $companyId, $newPeriodId);

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
}

redirect('../business_accounts.php');
