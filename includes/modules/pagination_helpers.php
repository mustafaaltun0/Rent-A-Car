<?php

function pagination_resolve_state(string $pageParam = 'page', string $perPageParam = 'per_page', int $defaultPerPage = 10, array $allowedPerPage = [10, 20, 50, 100]): array
{
    $normalizedAllowed = array_values(array_unique(array_map('intval', $allowedPerPage)));
    sort($normalizedAllowed);

    if (empty($normalizedAllowed)) {
        $normalizedAllowed = [$defaultPerPage];
    }

    $defaultPerPage = in_array($defaultPerPage, $normalizedAllowed, true) ? $defaultPerPage : $normalizedAllowed[0];
    $page = max(1, (int) ($_GET[$pageParam] ?? 1));
    $requestedPerPage = (int) ($_GET[$perPageParam] ?? $defaultPerPage);
    $perPage = in_array($requestedPerPage, $normalizedAllowed, true) ? $requestedPerPage : $defaultPerPage;

    return [
        'page_param' => $pageParam,
        'per_page_param' => $perPageParam,
        'page' => $page,
        'per_page' => $perPage,
        'default_per_page' => $defaultPerPage,
        'allowed_per_page' => $normalizedAllowed,
    ];
}

function paginate_array(array $items, array $state): array
{
    $totalItems = count($items);
    $perPage = max(1, (int) ($state['per_page'] ?? 10));
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min(max(1, (int) ($state['page'] ?? 1)), $totalPages);
    $offset = ($page - 1) * $perPage;
    $pagedItems = array_slice($items, $offset, $perPage);

    return [
        'items' => $pagedItems,
        'page' => $page,
        'per_page' => $perPage,
        'total_items' => $totalItems,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'from' => $totalItems > 0 ? $offset + 1 : 0,
        'to' => $totalItems > 0 ? min($totalItems, $offset + count($pagedItems)) : 0,
        'page_param' => $state['page_param'] ?? 'page',
        'per_page_param' => $state['per_page_param'] ?? 'per_page',
        'allowed_per_page' => $state['allowed_per_page'] ?? [10, 20, 50, 100],
    ];
}

function paginate_collection(array $items, string $pageParam = 'page', string $perPageParam = 'per_page', int $defaultPerPage = 10, array $allowedPerPage = [10, 20, 50, 100]): array
{
    return paginate_array($items, pagination_resolve_state($pageParam, $perPageParam, $defaultPerPage, $allowedPerPage));
}

function pagination_build_url(array $pagination, array $overrides = [], ?string $anchor = null): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }

        $query[$key] = $value;
    }

    $queryString = http_build_query($query);
    $path = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));

    return $path . ($queryString !== '' ? '?' . $queryString : '') . ($anchor ? '#' . ltrim($anchor, '#') : '');
}

function pagination_render_hidden_query_fields(array $query, string $pageParam, string $perPageParam, string $prefix = ''): string
{
    ob_start();
    foreach ($query as $key => $value) {
        $inputName = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
        if ($inputName === $pageParam || $inputName === $perPageParam) {
            continue;
        }

        if (is_array($value)) {
            echo pagination_render_hidden_query_fields($value, $pageParam, $perPageParam, $inputName);
            continue;
        }
        ?>
        <input type="hidden" name="<?= h($inputName) ?>" value="<?= h((string) $value) ?>">
        <?php
    }

    return (string) ob_get_clean();
}

function pagination_render(array $pagination, array $options = []): string
{
    $totalItems = (int) ($pagination['total_items'] ?? 0);
    $totalPages = (int) ($pagination['total_pages'] ?? 1);
    $page = (int) ($pagination['page'] ?? 1);
    $perPage = (int) ($pagination['per_page'] ?? 10);
    $pageParam = (string) ($pagination['page_param'] ?? 'page');
    $perPageParam = (string) ($pagination['per_page_param'] ?? 'per_page');
    $allowedPerPage = $pagination['allowed_per_page'] ?? [10, 20, 50, 100];
    $from = (int) ($pagination['from'] ?? 0);
    $to = (int) ($pagination['to'] ?? 0);
    $anchor = $options['anchor'] ?? null;
    $itemLabel = (string) ($options['item_label'] ?? 'kayit');

    if ($totalItems <= 0) {
        return '';
    }

    $window = max(3, (int) ($options['window'] ?? 5));
    $halfWindow = (int) floor($window / 2);
    $startPage = max(1, $page - $halfWindow);
    $endPage = min($totalPages, $startPage + $window - 1);
    $startPage = max(1, $endPage - $window + 1);

    ob_start();
    ?>
    <div class="app-pagination-shell d-flex flex-column flex-lg-row justify-content-between align-items-stretch align-items-lg-center gap-3 mt-3">
      <div class="text-muted small">
        <?= h((string) $from) ?> - <?= h((string) $to) ?> / <?= h((string) $totalItems) ?> <?= h($itemLabel) ?>
      </div>
      <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2">
        <form method="get" class="d-flex align-items-center gap-2">
          <?= pagination_render_hidden_query_fields($_GET, $pageParam, $perPageParam) ?>
          <input type="hidden" name="<?= h($pageParam) ?>" value="1">
          <label class="small text-muted" for="<?= h($perPageParam) ?>">Sayfa basi</label>
          <select id="<?= h($perPageParam) ?>" name="<?= h($perPageParam) ?>" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($allowedPerPage as $option): ?>
              <option value="<?= h((string) $option) ?>" <?= $perPage === (int) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Sayfalama">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $page <= 1 ? '#' : h(pagination_build_url($pagination, [$pageParam => $page - 1], $anchor)) ?>">Onceki</a>
            </li>
            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h(pagination_build_url($pagination, [$pageParam => $pageNumber], $anchor)) ?>"><?= h((string) $pageNumber) ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $page >= $totalPages ? '#' : h(pagination_build_url($pagination, [$pageParam => $page + 1], $anchor)) ?>">Sonraki</a>
            </li>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
