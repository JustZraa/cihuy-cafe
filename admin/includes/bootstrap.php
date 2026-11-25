<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';

start_secure_session();
require_admin_login();

$pdo = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$adminId = (int) $_SESSION['admin_id'];
$adminQuery = $pdo->prepare('SELECT id, name FROM admins WHERE id = :id LIMIT 1');
$adminQuery->execute(['id' => $adminId]);
$admin = $adminQuery->fetch();
if (!is_array($admin)) {
    destroy_secure_session();
    cozycafe_auth_redirect('admin/login.php', 303);
}
$adminId = (int) $admin['id'];
$adminName = (string) $admin['name'];

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';

function admin_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit(e(__('error.method_not_allowed')));
    }

    if (!verify_csrf_token()) {
        http_response_code(403);
        exit(e(__('validation.invalid_csrf')));
    }
}
