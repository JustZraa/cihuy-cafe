<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'storefront.php';
start_secure_session();

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'CozyCafe';
$activeLanguage = $_SESSION['lang'] ?? 'id';
if (!is_string($activeLanguage) || !in_array($activeLanguage, ['id', 'en'], true)) {
    $activeLanguage = 'id';
}
?>
<!doctype html>
<html lang="<?= e($activeLanguage) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#5C3A21">
    <meta name="csrf-token" content="<?= e(generate_csrf_token()) ?>">
    <title><?= e($pageTitle) ?> | CozyCafe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(cozycafe_app_path('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'navbar.php'; ?>
<main id="main-content">
    <div
        id="cart-feedback"
        class="alert d-none cart-feedback container mt-3"
        role="status"
        aria-live="polite"
        data-error-message="<?= e(__('error.cart_request_failed')) ?>"
    ></div>
