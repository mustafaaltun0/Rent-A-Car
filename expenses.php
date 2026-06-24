<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('expenses.view');

ensureBusinessExpenseOwnerSchema($pdo);
ensureExpenseArchiveSchema($pdo);
$companyId = auth_current_company_id();
$canManageExpenses = auth_can('expenses.manage');
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
$status = $_GET['status'] ?? '';

$expenseSql = 'SELECT * FROM business_expenses WHERE company_id = ?';
$expenseSql .= $showArchived ? ' AND archived_at IS NOT NULL' : ' AND archived_at IS NULL';
$expenseSql .= ' ORDER BY id DESC';
$expenseSt = $pdo->prepare($expenseSql);
$expenseSt->execute([$companyId]);
$expenses = $expenseSt->fetchAll();
$expensesPagination = paginate_collection($expenses, 'expenses_page', 'expenses_per_page', 10, [10, 20, 50, 100]);
$expenses = $expensesPagination['items'];

$pageTitle = 'İşletme Giderleri';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="expenses-page">
  <div class="expenses-hero mb-4">
    <div>
      <div class="expenses-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-2">İşletme Giderleri</h2>
    </div>
    <div class="expenses-hero-actions">
      <?php if ($canManageExpenses && !$showArchived): ?>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#expenseModal" data-mode="create">Yeni Gider Ekle</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($status === 'archived'): ?>
  <div class="alert alert-success">İşletme gideri arşive alındı.</div>
  <?php elseif ($status === 'restored'): ?>
  <div class="alert alert-success">İşletme gideri arşivden geri yüklendi.</div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header"><?= $showArchived ? 'Arşivlenmiş Giderler' : 'Gider Listesi' ?></div>
    <div class="card-body border-bottom bg-light-subtle rentals-switchbar">
      <?php if ($showArchived): ?>
      <a href="expenses.php" class="btn btn-outline-dark btn-sm">Normal Listeye Dön</a>
      <?php else: ?>
      <a href="expenses.php?show_archived=1" class="btn btn-outline-secondary btn-sm">Arşivdekileri Gör</a>
      <?php endif; ?>
    </div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped align-middle">
        <tr><th>Açıklama</th><th>Tutar</th><th>Tarih</th><th>İşlem</th></tr>
        <?php foreach ($expenses as $expense): ?>
        <tr>
          <td><?= h($expense['title']) ?></td>
          <td><?= money($expense['amount']) ?></td>
          <td><?= d($expense['expense_date']) ?></td>
          <td class="table-actions-cell">
            <div class="action-group">
              <?php if (!$showArchived): ?>
              <button class="action-btn action-warning" type="button" title="Düzenle" aria-label="Düzenle" data-bs-toggle="modal" data-bs-target="#expenseModal" data-mode="edit" data-id="<?= h($expense['id']) ?>" data-title="<?= h($expense['title']) ?>" data-amount="<?= h($expense['amount']) ?>" data-expense_date="<?= h($expense['expense_date']) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 17.2 10.9-10.9 3.8 3.8L6.8 21H3v-3.8Zm12.3-12.3 1.4-1.4a2 2 0 0 1 2.8 0l1.5 1.5a2 2 0 0 1 0 2.8L19.6 9.2l-4.3-4.3Z"/></svg>
              </button>
              <form action="actions/expense_delete.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($expense['id']) ?>">
                <button class="action-btn action-danger" type="submit" title="Arşivle" aria-label="Arşivle" data-confirm="Bu gider kaydını arşive almak istediğinize emin misiniz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v4H4V5Zm1 6h14v8H5v-8Zm3 2v2h8v-2H8Z"/></svg>
                </button>
              </form>
              <?php else: ?>
              <form action="actions/expense_restore.php" method="post" class="d-inline">
                <?= auth_csrf_input() ?>
                <input type="hidden" name="id" value="<?= h($expense['id']) ?>">
                <button class="action-btn action-secondary" type="submit" title="Geri Yükle" aria-label="Geri Yükle" data-confirm="Bu gider kaydını arşivden geri yüklemek istiyor musunuz?">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.3 10H3l3.5-3.5L10 15H7.8A5 5 0 1 0 12 7h-1V5h1Z"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?= pagination_render($expensesPagination, ['item_label' => 'gider kaydı']) ?>
    </div>
  </div>
</div>

<?php if ($canManageExpenses && !$showArchived): ?>
<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="expenseModalLabel">Yeni Gider Ekle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form action="actions/expense_save.php" method="post" data-modal-form="expense">
        <div class="modal-body">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="id" value="">
          <div class="mb-3"><label class="form-label">Gider Açıklaması</label><input name="title" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Tutar</label><input name="amount" type="number" step="0.01" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Tarih</label><input name="expense_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button><button class="btn btn-success" type="submit" data-submit-label>Kaydet</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
