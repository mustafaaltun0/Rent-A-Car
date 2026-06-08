<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_post_request();
auth_validate_csrf_request();
auth_require_permission('notifications.manage');

$companyId = auth_current_company_id();
notifications_mark_all_read($pdo, $companyId);

$redirectTo = auth_referer_path('index.php?flash=all_read');
auth_redirect($redirectTo);
