<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_post_request();
auth_validate_csrf_request();
auth_require_permission('notifications.manage');

$companyId = auth_current_company_id();
$notificationId = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($notificationId > 0) {
    notifications_resolve($pdo, $companyId, $notificationId);
}

auth_redirect('notifications.php?status=resolved&flash=resolved');
