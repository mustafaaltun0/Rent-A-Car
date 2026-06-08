<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('expenses.manage');

ensureBusinessExpenseOwnerSchema($pdo);
ensureExpenseArchiveSchema($pdo);
$companyId = auth_current_company_id();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$expense = ['id' => '', 'title' => '', 'owner_name' => '', 'amount' => '', 'expense_date' => date('Y-m-d')];
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM business_expenses WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $st->execute([$id, $companyId]);
    $row = $st->fetch();
    if ($row) {
        $expense = $row;
    }
}
$pageTitle = $id ? 'Gider Duzenle' : 'Yeni Gider';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<h2 class="mb-4"><?= $id ? 'Gider Duzenle' : 'Yeni Gider Ekle' ?></h2>
<form action="actions/expense_save.php" method="post" class="card shadow-sm">
  <div class="card-body">
    <?= auth_csrf_input() ?>
    <input type="hidden" name="id" value="<?= h($expense['id']) ?>">
    <div class="mb-3"><label class="form-label">Gider Aciklamasi</label><input name="title" class="form-control" value="<?= h($expense['title']) ?>" required></div>
    <div class="mb-3"><label class="form-label">Tutar</label><input name="amount" type="number" step="0.01" class="form-control" value="<?= h($expense['amount']) ?>" required></div>
    <div class="mb-3"><label class="form-label">Tarih</label><input name="expense_date" type="date" class="form-control" value="<?= h($expense['expense_date']) ?>"></div>
  </div>
  <div class="card-footer"><button class="btn btn-success">Kaydet</button> <a href="expenses.php" class="btn btn-secondary">Iptal</a></div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
