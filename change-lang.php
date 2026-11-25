<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';

start_secure_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo e(__('error.method_not_allowed'));
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(403);
    echo e(__('validation.invalid_csrf'));
    exit;
}

$language = $_POST['lang'] ?? null;
if (!is_string($language) || !in_array($language, ['id', 'en'], true)) {
    http_response_code(400);
    echo e(__('validation.invalid_language'));
    exit;
}

$_SESSION['lang'] = $language;

$appUrl = cozycafe_env('APP_URL', 'http://localhost/cozycafe');
$basePath = parse_url((string) $appUrl, PHP_URL_PATH);
$basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
if (
    ($basePath !== '' && (!str_starts_with($basePath, '/') || str_starts_with($basePath, '//')))
    || preg_match('/[\x00-\x1F\x7F]/', $basePath) === 1
) {
    throw new RuntimeException('APP_URL must contain a safe application path.');
}
$fallbackLocation = ($basePath === '' ? '' : $basePath) . '/';
$returnTo = $_POST['return_to'] ?? null;

if (
    is_string($returnTo)
    && $returnTo !== ''
    && str_starts_with($returnTo, '/')
    && !str_starts_with($returnTo, '//')
    && !str_contains($returnTo, '\\')
    && !str_contains($returnTo, '%5c')
    && !str_contains($returnTo, '%5C')
    && !str_contains($returnTo, "\r")
    && !str_contains($returnTo, "\n")
) {
    $returnPath = parse_url($returnTo, PHP_URL_PATH);
    $withinApplication = $basePath === ''
        || $returnPath === $basePath
        || (is_string($returnPath) && str_starts_with($returnPath, $basePath . '/'));

    if (is_string($returnPath) && $withinApplication) {
        $fallbackLocation = $returnTo;
    }
}

header('Location: ' . $fallbackLocation, true, 303);
exit;
