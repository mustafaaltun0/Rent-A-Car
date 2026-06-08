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

if ($companyId === auth_current_company_id()) {
    auth_redirect('companies.php?status=self_company_blocked');
}

$companySt = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$companySt->execute([$companyId]);
$company = $companySt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    auth_redirect('companies.php?status=invalid');
}

if ((int) ($company['is_active'] ?? 0) === 1) {
    auth_redirect('companies.php?status=company_delete_blocked');
}

$relations = [
    'users' => 'SELECT COUNT(*) FROM users WHERE company_id = ?',
    'cars' => 'SELECT COUNT(*) FROM cars WHERE company_id = ?',
    'rentals' => 'SELECT COUNT(*) FROM rentals WHERE company_id = ?',
    'business_expenses' => 'SELECT COUNT(*) FROM business_expenses WHERE company_id = ?',
    'ledger_partners' => 'SELECT COUNT(*) FROM ledger_partners WHERE company_id = ?',
    'ledger_periods' => 'SELECT COUNT(*) FROM ledger_periods WHERE company_id = ?',
    'ledger_entries' => 'SELECT COUNT(*) FROM ledger_entries WHERE company_id = ?',
];

foreach ($relations as $sql) {
    $countSt = $pdo->prepare($sql);
    $countSt->execute([$companyId]);
    if ((int) $countSt->fetchColumn() > 0) {
        auth_redirect('companies.php?status=company_delete_blocked');
    }
}

try {
    $pdo->beginTransaction();

    $deleteAudit = $pdo->prepare('DELETE FROM audit_logs WHERE company_id = ?');
    $deleteAudit->execute([$companyId]);

    $deleteCompany = $pdo->prepare('DELETE FROM companies WHERE id = ? LIMIT 1');
    $deleteCompany->execute([$companyId]);

    auth_audit_log($pdo, 'platform.company_deleted', 'Pasif ve bos firma kalici olarak silindi.', [
        'entity_type' => 'company',
        'entity_id' => $companyId,
        'metadata' => [
            'company_name' => (string) ($company['name'] ?? ''),
        ],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    auth_redirect('companies.php?status=invalid');
}

$companyLogoPath = auth_company_logo_filesystem_path($company);
if ($companyLogoPath && is_file($companyLogoPath)) {
    @unlink($companyLogoPath);
}

$companyLogoDir = auth_company_logo_storage_dir() . DIRECTORY_SEPARATOR . $companyId;
if (is_dir($companyLogoDir)) {
    $items = @scandir($companyLogoDir);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $companyLogoDir . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    @rmdir($companyLogoDir);
}

auth_redirect('companies.php?status=company_deleted');
