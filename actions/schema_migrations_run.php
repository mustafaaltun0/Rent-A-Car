<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/schema_migrations.php';

auth_require_post_request();
auth_validate_csrf_request();

if (!app_schema_migration_available_for_user()) {
    auth_require_permission('platform.manage');
}

try {
    $result = app_run_schema_migrations($pdo);
    redirect('../migrations.php?status=success&applied=' . count($result['applied_now']));
} catch (Throwable $exception) {
    error_log('Schema migrations failed: ' . $exception->getMessage());
    redirect('../migrations.php?status=error');
}
