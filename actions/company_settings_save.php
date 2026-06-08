<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('company.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = auth_current_company_id();
if ($companyId <= 0) {
    auth_redirect('company_settings.php?status=invalid');
}

$company = auth_current_company($pdo);
if (!$company) {
    auth_redirect('company_settings.php?status=invalid');
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
$removeLogo = (int) ($_POST['remove_logo'] ?? 0) === 1;
$logoFile = $_FILES['logo_file'] ?? null;

if ($companyName === '') {
    auth_redirect('company_settings.php?status=invalid');
}

if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_redirect('company_settings.php?status=email_invalid');
}

if ($website !== null && !filter_var($website, FILTER_VALIDATE_URL)) {
    auth_redirect('company_settings.php?status=website_invalid');
}

$newLogoRelativePath = $company['logo_path'] ?? null;
$logoChanged = false;
$newUploadedAbsolutePath = null;

if (is_array($logoFile) && (int) ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $uploadResult = auth_store_company_logo_upload($logoFile, $companyId);
    if (!$uploadResult['ok']) {
        auth_redirect('company_settings.php?status=' . urlencode((string) ($uploadResult['status'] ?? 'logo_upload_failed')));
    }

    $newLogoRelativePath = (string) $uploadResult['relative_path'];
    $newUploadedAbsolutePath = (string) $uploadResult['absolute_path'];
    $logoChanged = true;
}

if ($removeLogo && (!is_array($logoFile) || (int) ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
    $newLogoRelativePath = null;
    $logoChanged = true;
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('
        UPDATE companies
        SET name = ?, legal_name = ?, phone = ?, email = ?, tax_office = ?, tax_number = ?, mersis_number = ?, address = ?, district = ?, city = ?, country = ?, website = ?, logo_path = ?, updated_at = NOW()
        WHERE id = ? AND is_active = 1
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
        $newLogoRelativePath,
        $companyId,
    ]);

    auth_audit_log($pdo, 'company.updated', 'Firma ayarlari guncellendi.', [
        'entity_type' => 'company',
        'entity_id' => $companyId,
        'company_id' => $companyId,
        'metadata' => [
            'name' => $companyName,
            'legal_name' => $legalName,
            'logo_changed' => $logoChanged,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newUploadedAbsolutePath !== null && is_file($newUploadedAbsolutePath)) {
        @unlink($newUploadedAbsolutePath);
    }
    auth_redirect('company_settings.php?status=logo_upload_failed');
}

if ($logoChanged) {
    $oldLogoPath = auth_company_logo_filesystem_path($company);
    $currentLogoPath = auth_company_logo_filesystem_path(['logo_path' => $newLogoRelativePath]);
    if ($oldLogoPath !== null && $oldLogoPath !== $currentLogoPath && is_file($oldLogoPath)) {
        @unlink($oldLogoPath);
    }
}
auth_reload_user($pdo);
auth_redirect('company_settings.php?status=saved');
