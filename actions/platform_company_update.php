<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('platform.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
if ($companyId <= 0) {
    auth_redirect('companies.php?status=invalid');
}

$companySt = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$companySt->execute([$companyId]);
$company = $companySt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    auth_redirect('companies.php?status=invalid');
}

$companyName = auth_trimmed_string($_POST['company_name'] ?? '', 150);
$legalName = auth_nullable_trimmed_string($_POST['legal_name'] ?? '', 180);
$phone = auth_nullable_trimmed_string($_POST['phone'] ?? '', 30);
$email = auth_nullable_trimmed_string($_POST['email'] ?? '', 150);
$website = auth_normalize_website((string) ($_POST['website'] ?? ''));
$taxOffice = auth_nullable_trimmed_string($_POST['tax_office'] ?? '', 120);
$taxNumber = auth_nullable_trimmed_string($_POST['tax_number'] ?? '', 30);
$mersisNumber = auth_nullable_trimmed_string($_POST['mersis_number'] ?? '', 30);
$address = auth_nullable_trimmed_string($_POST['address'] ?? '', 4000);
$district = auth_nullable_trimmed_string($_POST['district'] ?? '', 120);
$city = auth_nullable_trimmed_string($_POST['city'] ?? '', 120);
$country = auth_nullable_trimmed_string($_POST['country'] ?? '', 120) ?? 'Turkiye';

if ($companyName === '') {
    auth_redirect('company_detail.php?id=' . $companyId . '&status=invalid');
}

if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_redirect('company_detail.php?id=' . $companyId . '&status=email_invalid');
}

if ($website !== null && !filter_var($website, FILTER_VALIDATE_URL)) {
    auth_redirect('company_detail.php?id=' . $companyId . '&status=website_invalid');
}

$duplicateSt = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE LOWER(name) = ? AND id <> ?');
$duplicateSt->execute([mb_strtolower($companyName, 'UTF-8'), $companyId]);
if ((int) $duplicateSt->fetchColumn() > 0) {
    auth_redirect('company_detail.php?id=' . $companyId . '&status=company_exists');
}

$update = $pdo->prepare('
    UPDATE companies
    SET name = ?, legal_name = ?, phone = ?, email = ?, tax_office = ?, tax_number = ?, mersis_number = ?, address = ?, district = ?, city = ?, country = ?, website = ?, updated_at = NOW()
    WHERE id = ?
');
$update->execute([
    $companyName,
    $legalName,
    $phone,
    $email,
    $taxOffice,
    $taxNumber,
    $mersisNumber,
    $address,
    $district,
    $city,
    $country,
    $website,
    $companyId,
]);

auth_audit_log($pdo, 'platform.company_updated', 'Platform yoneticisi firma profilini guncelledi.', [
    'entity_type' => 'company',
    'entity_id' => $companyId,
    'company_id' => $companyId,
    'metadata' => [
        'name' => $companyName,
        'legal_name' => $legalName,
    ],
]);

auth_redirect('company_detail.php?id=' . $companyId . '&status=company_updated');
