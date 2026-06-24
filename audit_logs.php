<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

auth_require_permission('users.manage');

$companyId = auth_current_company_id();
$eventType = trim((string) ($_GET['event_type'] ?? ''));
$paginationState = pagination_resolve_state('page', 'limit', 100, [50, 100, 150, 200]);
$limit = $paginationState['per_page'];
$page = $paginationState['page'];

$baseWhereSql = " WHERE l.company_id = ? ";
$params = [$companyId];

if ($eventType !== '') {
    $baseWhereSql .= ' AND l.event_type = ?';
    $params[] = $eventType;
}

$countSt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs l' . $baseWhereSql);
$countSt->execute($params);
$totalLogs = (int) $countSt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLogs / max(1, $limit)));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$sql = "
    SELECT l.*, u.full_name, u.username
    FROM audit_logs l
    LEFT JOIN users u ON u.id = l.user_id
" . $baseWhereSql . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

$st = $pdo->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll();

$logsPagination = [
    'page' => $page,
    'per_page' => $limit,
    'total_items' => $totalLogs,
    'total_pages' => $totalPages,
    'offset' => $offset,
    'from' => $totalLogs > 0 ? $offset + 1 : 0,
    'to' => $totalLogs > 0 ? min($totalLogs, $offset + count($logs)) : 0,
    'page_param' => 'page',
    'per_page_param' => 'limit',
    'allowed_per_page' => [50, 100, 150, 200],
];

$eventTypeSt = $pdo->prepare('SELECT DISTINCT event_type FROM audit_logs WHERE company_id = ? ORDER BY event_type ASC');
$eventTypeSt->execute([$companyId]);
$eventTypes = $eventTypeSt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Logları';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<div class="users-page">
  <div class="users-hero mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="users-hero-label"><?= h(auth_current_user()['company_name'] ?? 'Firma') ?></div>
      <h2 class="mb-0">Audit Logları</h2>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header">Filtreler</div>
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label">Olay Tipi</label>
          <select name="event_type" class="form-select">
            <option value="">Tüm Olaylar</option>
            <?php foreach ($eventTypes as $type): ?>
              <option value="<?= h($type) ?>" <?= $eventType === $type ? 'selected' : '' ?>><?= h($type) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Kayıt Limiti</label>
          <select name="limit" class="form-select">
            <?php foreach ([50, 100, 150, 200] as $option): ?>
              <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 d-grid">
          <button class="btn btn-dark" type="submit">Filtreyi Uygula</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">Kayıtlar</div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped align-middle">
        <tr><th>Tarih</th><th>Olay</th><th>Kullanıcı</th><th>Açıklama</th><th>IP</th><th>Detay</th></tr>
        <?php if (empty($logs)): ?>
        <tr><td colspan="6" class="text-center text-muted">Gösterilecek log kaydı yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <?php
          $metadata = [];
          if (!empty($log['metadata_json'])) {
              $decoded = json_decode((string) $log['metadata_json'], true);
              if (is_array($decoded)) {
                  $metadata = $decoded;
              }
          }
          $actor = trim((string) (($log['full_name'] ?? '') !== '' ? $log['full_name'] : ($log['username'] ?? '')));
          ?>
        <tr>
          <td><?= dt($log['created_at']) ?></td>
          <td><span class="badge bg-dark"><?= h($log['event_type']) ?></span></td>
          <td><?= h($actor !== '' ? $actor : 'Sistem') ?></td>
          <td><?= h($log['description']) ?></td>
          <td><?= h($log['ip_address']) ?></td>
          <td><?php if (!empty($metadata)): ?><code><?= h(json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?= pagination_render($logsPagination, ['item_label' => 'log kaydı']) ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
