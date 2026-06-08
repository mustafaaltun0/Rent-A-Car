<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('ledger.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureBusinessAccountsSchema($pdo);
$companyId = auth_current_company_id();

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$isSettlementPartner = (int) (($_POST['is_settlement_partner'] ?? '1') === '1');
$sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));

if ($name === '') {
    redirect('../business_accounts.php');
}

if ($id > 0) {
    $st = $pdo->prepare('UPDATE ledger_partners SET name = ?, is_settlement_partner = ?, sort_order = ? WHERE id = ? AND company_id = ?');
    $st->execute([$name, $isSettlementPartner, $sortOrder, $id, $companyId]);
    auth_audit_log($pdo, 'ledger.partner_updated', 'Kisi kaydi guncellendi.', [
        'entity_type' => 'ledger_partner',
        'entity_id' => $id,
        'metadata' => [
            'name' => $name,
            'is_settlement_partner' => $isSettlementPartner,
        ],
    ]);
} else {
    $st = $pdo->prepare('INSERT INTO ledger_partners (company_id, name, is_settlement_partner, sort_order) VALUES (?, ?, ?, ?)');
    $st->execute([$companyId, $name, $isSettlementPartner, $sortOrder]);
    $newPartnerId = (int) $pdo->lastInsertId();
    auth_audit_log($pdo, 'ledger.partner_created', 'Yeni kisi kaydi olusturuldu.', [
        'entity_type' => 'ledger_partner',
        'entity_id' => $newPartnerId,
        'metadata' => [
            'name' => $name,
            'is_settlement_partner' => $isSettlementPartner,
        ],
    ]);
}

redirect('../business_accounts.php');
