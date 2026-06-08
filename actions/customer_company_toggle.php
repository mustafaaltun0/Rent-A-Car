<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('customers.manage');
auth_require_post_request();
auth_validate_csrf_request();

if (!app_feature_customer_companies_enabled()) {
    auth_redirect('index.php');
}

ensureCustomerCompanySchema($pdo);
ensureRentalArchiveSchema($pdo);
$companyId = auth_current_company_id();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;

if ($id <= 0) {
    auth_redirect('customer_companies.php?status=invalid');
}

$customerCompanySt = $pdo->prepare('SELECT id, company_name, is_active FROM customer_companies WHERE id = ? AND company_id = ? LIMIT 1');
$customerCompanySt->execute([$id, $companyId]);
$customerCompany = $customerCompanySt->fetch(PDO::FETCH_ASSOC);
if (!$customerCompany) {
    auth_redirect('customer_companies.php?status=invalid');
}

if ($isActive !== 1) {
    $activeRentalSt = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE company_id = ? AND customer_company_id = ? AND completed = 0 AND archived_at IS NULL');
    $activeRentalSt->execute([$companyId, $id]);
    if ((int) $activeRentalSt->fetchColumn() > 0) {
        auth_redirect('customer_companies.php?status=inactive_blocked');
    }
}

$update = $pdo->prepare('UPDATE customer_companies SET is_active = ?, updated_at = NOW() WHERE id = ? AND company_id = ?');
$update->execute([$isActive === 1 ? 1 : 0, $id, $companyId]);

auth_audit_log($pdo, 'customer.company_status_changed', $isActive === 1 ? 'Kurumsal musteri tekrar aktif edildi.' : 'Kurumsal musteri pasife alindi.', [
    'entity_type' => 'customer_company',
    'entity_id' => $id,
    'company_id' => $companyId,
    'metadata' => [
        'company_name' => (string) ($customerCompany['company_name'] ?? ''),
        'is_active' => $isActive === 1 ? 1 : 0,
    ],
]);

auth_redirect('customer_companies.php?status=status_changed');
