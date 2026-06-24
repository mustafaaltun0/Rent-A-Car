<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!auth_can('ledger.manage') && !auth_can('ledger.entry.delete')) {
    auth_abort('Bu islem icin yetkiniz yok.', 403);
}

auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'finance_core');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0 || $companyId <= 0 || $currentUserId <= 0) {
    redirect('../business_accounts.php?entry_status=invalid');
}

try {
    $pdo->beginTransaction();

    $entrySt = $pdo->prepare('SELECT * FROM ledger_entries WHERE id = ? AND company_id = ? LIMIT 1');
    $entrySt->execute([$id, $companyId]);
    $entry = $entrySt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        throw new RuntimeException('Kayit bulunamadi.');
    }

    $st = $pdo->prepare('DELETE FROM ledger_entries WHERE id = ? AND company_id = ?');
    $st->execute([$id, $companyId]);

    auth_audit_log($pdo, 'ledger.entry_deleted', 'Gelir gider hareketi silindi.', [
        'entity_type' => 'ledger_entry',
        'entity_id' => $id,
        'company_id' => $companyId,
        'metadata' => [
            'type' => $entry['type'] ?? '',
            'partner_id' => (int) ($entry['partner_id'] ?? 0),
            'amount' => (float) ($entry['amount'] ?? 0),
            'car_label' => (string) ($entry['car_label'] ?? ''),
            'note' => (string) ($entry['note'] ?? ''),
            'entry_date' => (string) ($entry['entry_date'] ?? ''),
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('business_entry_delete_failed: ' . $exception->getMessage());
    redirect('../business_accounts.php?entry_status=delete_error');
}

redirect('../business_accounts.php?entry_status=deleted');
