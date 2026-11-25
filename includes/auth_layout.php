<?php
declare(strict_types=1);

function auth_layout_start(string $title, string $intro = '', bool $wide = false, bool $admin = false): void
{
    $language = $_SESSION['lang'] ?? 'id';
    ?>
<!doctype html>
<html lang="<?= e(is_string($language) && in_array($language, ['id', 'en'], true) ? $language : 'id') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | CozyCafe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(cozycafe_app_path('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
    <header class="container py-3">
        <a class="auth-brand text-decoration-none" href="<?= e(cozycafe_app_path()) ?>">CozyCafe</a>
    </header>
    <main class="auth-page">
        <section class="auth-card<?= $wide ? ' auth-card--wide' : '' ?><?= $admin ? ' admin-auth-card' : '' ?>">
            <p class="auth-brand mb-2">CozyCafe</p>
            <h1 class="h3 mb-2"><?= e($title) ?></h1>
            <?php if ($intro !== ''): ?>
                <p class="text-secondary mb-4"><?= e($intro) ?></p>
            <?php endif; ?>
<?php
}

function auth_layout_alerts(array $errors = [], ?string $success = null): void
{
    if ($success !== null && $success !== ''):
        ?>
        <div class="alert alert-success" role="status"><?= e($success) ?></div>
        <?php
    endif;

    foreach ($errors as $error):
        ?>
        <div class="alert alert-danger" role="alert"><?= e((string) $error) ?></div>
        <?php
    endforeach;
}

function auth_layout_end(): void
{
    ?>
        </section>
    </main>
</body>
</html>
<?php
}
