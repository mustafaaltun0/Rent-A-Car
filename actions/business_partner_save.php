<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'finance_core');
$companyId = auth_current_company_id();

$id = (int) ($_POST['id'] ?? 0);
$name = auth_trimmed_string($_POST['name'] ?? '', 100);
$isSettlementPartner = (int) (($_POST['is_settlement_partner'] ?? '1') === '1');
$sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));

if ($name === '') {
    redirect('../business_accounts.php?partner_status=invalid');
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $existingSt = $pdo->prepare('SELECT id FROM ledger_partners WHERE id = ? AND company_id = ? LIMIT 1');
        $existingSt->execute([$id, $companyId]);
        if (!$existingSt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Partner bulunamadi.');
        }

        $st = $pdo->prepare('UPDATE ledger_partners SET name = ?, is_settlement_partner = ?, sort_order = ? WHERE id = ? AND company_id = ?');
        $st->execute([$name, $isSettlementPartner, $sortOrder, $id, $companyId]);

        auth_audit_log($pdo, 'ledger.partner_updated', 'Kisi kaydi guncellendi.', [
            'entity_type' => 'ledger_partner',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'name' => $name,
                'is_settlement_partner' => $isSettlementPartner,
                'sort_order' => $sortOrder,
            ],
        ]);
    } else {
        $st = $pdo->prepare('INSERT INTO ledger_partners (company_id, name, is_settlement_partner, sort_order) VALUES (?, ?, ?, ?)');
        $st->execute([$companyId, $name, $isSettlementPartner, $sortOrder]);
        $newPartnerId = (int) $pdo->lastInsertId();

        auth_audit_log($pdo, 'ledger.partner_created', 'Yeni kisi kaydi olusturuldu.', [
            'entity_type' => 'ledger_partner',
            'entity_id' => $newPartnerId,
            'company_id' => $companyId,
            'metadata' => [
                'name' => $name,
                'is_settlement_partner' => $isSettlementPartner,
                'sort_order' => $sortOrder,
            ],
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('business_partner_save_failed: ' . $exception->getMessage());
    redirect('../business_accounts.php?partner_status=error');
}

redirect('../business_accounts.php?partner_status=saved');
