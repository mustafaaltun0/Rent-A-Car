<?php
require_once __DIR__ . '/../config/database.php';

auth_require_post_request();
auth_validate_csrf_request();

auth_audit_log($pdo, 'auth.logout', 'Kullanici oturumu kapatildi.', [
    'entity_type' => 'auth',
]);

auth_logout();
auth_redirect('login.php');
