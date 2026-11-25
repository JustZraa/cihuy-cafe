<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_layout.php';

require_admin_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        auth_layout_start(__('nav.logout'));
        auth_layout_alerts([__('validation.invalid_csrf')]);
        auth_layout_end();
        exit;
    }

    destroy_secure_session();
    cozycafe_auth_redirect('admin/login.php?logout=1', 303);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
}

auth_layout_start(__('nav.logout'));
?>
<p><?= e(__('auth.logout_confirm')) ?></p>
<form method="post" action="<?= e(cozycafe_app_path('admin/logout.php')) ?>">
    <?= csrf_field() ?>
    <button class="btn btn-cozy w-100" type="submit"><?= e(__('nav.logout')) ?></button>
</form>
<?php auth_layout_end(); ?>
