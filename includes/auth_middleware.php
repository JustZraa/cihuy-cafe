<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';

function require_customer_login(): void
{
    start_secure_session();

    if (cozycafe_customer_session_id_if_active() !== null) {
        return;
    }

    cozycafe_auth_redirect('auth/login.php');
}

function cozycafe_customer_session_id_if_active(): ?int
{
    if (!cozycafe_session_has_id('customer_id')) {
        return null;
    }

    $customerId = (int) $_SESSION['customer_id'];
    static $verifiedCustomerIds = [];
    if (isset($verifiedCustomerIds[$customerId])) {
        return $customerId;
    }

    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $statement = $pdo->prepare('SELECT is_active FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $customerId]);
    $isActive = $statement->fetchColumn();
    if ($isActive !== false && (int) $isActive === 1) {
        $verifiedCustomerIds[$customerId] = true;
        return $customerId;
    }

    unset($_SESSION['customer_id']);
    $_SESSION['_customer_disabled_notice'] = true;
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent() && !session_regenerate_id(true)) {
        throw new RuntimeException('Unable to renew the session after customer account deactivation.');
    }
    return null;
}

function require_admin_login(): void
{
    start_secure_session();

    if (cozycafe_session_has_id('admin_id')) {
        return;
    }

    cozycafe_auth_redirect('admin/login.php');
}

function redirect_if_authenticated(?string $area = null): void
{
    start_secure_session();

    if ($area !== null && !in_array($area, ['customer', 'admin'], true)) {
        throw new InvalidArgumentException('The authenticated area must be customer or admin.');
    }

    if (
        ($area === null || $area === 'customer')
        && cozycafe_session_has_id('customer_id')
    ) {
        cozycafe_auth_redirect('customer/dashboard.php');
    }

    if (
        ($area === null || $area === 'admin')
        && cozycafe_session_has_id('admin_id')
    ) {
        cozycafe_auth_redirect('admin/dashboard.php');
    }
}

function cozycafe_session_has_id(string $key): bool
{
    $value = $_SESSION[$key] ?? null;
    return (is_int($value) || (is_string($value) && ctype_digit($value)))
        && (int) $value > 0;
}

function cozycafe_auth_redirect(string $relativePath, int $statusCode = 302): void
{
    if (headers_sent($file, $line)) {
        throw new RuntimeException('Authentication redirect cannot be sent after output at ' . $file . ':' . $line);
    }
    if (!in_array($statusCode, [302, 303, 307, 308], true)) {
        throw new InvalidArgumentException('Unsupported redirect status code.');
    }
    if (
        $relativePath === ''
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, '\\')
        || preg_match('/[\x00-\x20\x7F]/', $relativePath) === 1
    ) {
        throw new InvalidArgumentException('Authentication redirects must use a safe relative application path.');
    }

    header('Location: ' . cozycafe_app_path($relativePath), true, $statusCode);
    exit;
}
