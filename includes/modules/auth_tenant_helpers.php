<?php

function auth_tenant_assert_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid tenant identifier.');
    }

    return $identifier;
}

function auth_tenant_normalize_columns($columns): string
{
    if (is_string($columns)) {
        return trim($columns) !== '' ? $columns : '*';
    }

    if (!is_array($columns) || $columns === []) {
        return '*';
    }

    $normalized = [];
    foreach ($columns as $column) {
        $normalized[] = auth_tenant_assert_identifier((string) $column);
    }

    return implode(', ', $normalized);
}

function auth_tenant_find_row(PDO $pdo, string $table, int $companyId, int $id, array $options = []): ?array
{
    if ($companyId <= 0 || $id <= 0) {
        return null;
    }

    $tableName = auth_tenant_assert_identifier($table);
    $idColumn = auth_tenant_assert_identifier((string) ($options['id_column'] ?? 'id'));
    $companyColumn = auth_tenant_assert_identifier((string) ($options['company_column'] ?? 'company_id'));
    $columns = auth_tenant_normalize_columns($options['columns'] ?? '*');
    $extraWhere = $options['extra_where'] ?? [];
    $whereEquals = $options['where_equals'] ?? [];

    if (!is_array($extraWhere)) {
        $extraWhere = [$extraWhere];
    }
    if (!is_array($whereEquals)) {
        $whereEquals = [];
    }

    $whereParts = ["{$idColumn} = ?", "{$companyColumn} = ?"];
    $params = [$id, $companyId];

    foreach ($whereEquals as $column => $value) {
        $safeColumn = auth_tenant_assert_identifier((string) $column);
        if ($value === null) {
            $whereParts[] = "{$safeColumn} IS NULL";
            continue;
        }

        $whereParts[] = "{$safeColumn} = ?";
        $params[] = $value;
    }

    foreach ($extraWhere as $condition) {
        $condition = trim((string) $condition);
        if ($condition === '') {
            continue;
        }
        $whereParts[] = $condition;
    }

    $sql = "SELECT {$columns} FROM {$tableName} WHERE " . implode(' AND ', $whereParts) . ' LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function auth_tenant_row_exists(PDO $pdo, string $table, int $companyId, int $id, array $options = []): bool
{
    return auth_tenant_find_row($pdo, $table, $companyId, $id, $options) !== null;
}

function auth_tenant_assert_relation(PDO $pdo, string $table, int $companyId, $id, array $options = []): ?array
{
    if ($id === null || (int) $id <= 0) {
        return null;
    }

    $row = auth_tenant_find_row($pdo, $table, $companyId, (int) $id, $options);
    if ($row === null) {
        $message = (string) ($options['error_message'] ?? ('Invalid tenant relation: ' . $table));
        throw new RuntimeException($message);
    }

    return $row;
}

function auth_tenant_assert_custom_role_selection(PDO $pdo, int $companyId, array $roleSelection, bool $activeOnly = true): array
{
    $role = (string) ($roleSelection['role'] ?? 'viewer');
    $customRoleId = isset($roleSelection['custom_role_id']) ? (int) $roleSelection['custom_role_id'] : 0;

    if ($role !== 'custom') {
        $roleSelection['custom_role_id'] = null;
        return $roleSelection;
    }

    if ($companyId <= 0 || $customRoleId <= 0) {
        throw new RuntimeException('Invalid custom role selection.');
    }

    $extraWhere = ['archived_at IS NULL'];
    if ($activeOnly) {
        $extraWhere[] = 'is_active = 1';
    }

    auth_tenant_assert_relation($pdo, 'company_roles', $companyId, $customRoleId, [
        'extra_where' => $extraWhere,
        'error_message' => 'Invalid custom role selection.',
    ]);

    $roleSelection['custom_role_id'] = $customRoleId;
    return $roleSelection;
}
