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
$companyId = auth_current_company_id();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$companyName = auth_trimmed_string($_POST['company_name'] ?? '', 180);
$contactName = auth_nullable_trimmed_string($_POST['contact_name'] ?? '', 150);
$phone = auth_nullable_trimmed_string($_POST['phone'] ?? '', 30);
$email = auth_nullable_trimmed_string($_POST['email'] ?? '', 150);
$taxOffice = auth_nullable_trimmed_string($_POST['tax_office'] ?? '', 120);
$taxNumber = auth_nullable_trimmed_string($_POST['tax_number'] ?? '', 30);
$address = auth_nullable_trimmed_string($_POST['address'] ?? '', 4000);
$notes = auth_nullable_trimmed_string($_POST['notes'] ?? '', 4000);

if ($companyName === '') {
    auth_redirect('customer_companies.php?status=invalid');
}

if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_redirect('customer_companies.php?status=email_invalid');
}

$duplicateSt = $pdo->prepare('SELECT COUNT(*) FROM customer_companies WHERE company_id = ? AND LOWER(company_name) = ? AND id <> ?');
$duplicateSt->execute([$companyId, mb_strtolower($companyName, 'UTF-8'), $id]);
if ((int) $duplicateSt->fetchColumn() > 0) {
    auth_redirect('customer_companies.php?status=duplicate');
}

if ($id > 0) {
    $existingSt = $pdo->prepare('SELECT id FROM customer_companies WHERE id = ? AND company_id = ? LIMIT 1');
    $existingSt->execute([$id, $companyId]);
    if (!$existingSt->fetch(PDO::FETCH_ASSOC)) {
        auth_redirect('customer_companies.php?status=invalid');
    }

    $update = $pdo->prepare('
        UPDATE customer_companies
        SET company_name = ?, contact_name = ?, phone = ?, email = ?, tax_office = ?, tax_number = ?, address = ?, notes = ?, updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ');
    $update->execute([
        $companyName,
        $contactName,
        $phone,
        $email,
        $taxOffice,
        $taxNumber,
        $address,
        $notes,
        $id,
        $companyId,
    ]);

    auth_audit_log($pdo, 'customer.company_updated', 'Kurumsal musteri guncellendi.', [
        'entity_type' => 'customer_company',
        'entity_id' => $id,
        'company_id' => $companyId,
        'metadata' => [
            'company_name' => $companyName,
        ],
    ]);
} else {
    $insert = $pdo->prepare('
        INSERT INTO customer_companies (company_id, company_name, contact_name, phone, email, tax_office, tax_number, address, notes, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ');
    $insert->execute([
        $companyId,
        $companyName,
        $contactName,
        $phone,
        $email,
        $taxOffice,
        $taxNumber,
        $address,
        $notes,
    ]);

    $customerCompanyId = (int) $pdo->lastInsertId();
    auth_audit_log($pdo, 'customer.company_created', 'Kurumsal musteri olusturuldu.', [
        'entity_type' => 'customer_company',
        'entity_id' => $customerCompanyId,
        'company_id' => $companyId,
        'metadata' => [
            'company_name' => $companyName,
        ],
    ]);
}

auth_redirect('customer_companies.php?status=saved');
