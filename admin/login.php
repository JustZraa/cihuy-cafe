<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_layout.php';

start_secure_session();
$showAuthenticatedState = ($_GET['authenticated'] ?? '') === '1' && cozycafe_session_has_id('admin_id');
if (!$showAuthenticatedState) {
    redirect_if_authenticated('admin');
}

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$errors = [];
$identifier = '';
$notice = ($_GET['logout'] ?? '') === '1' ? __('message.logout_success') : null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $identifier = trim(cozycafe_post_string('identifier'));
        $password = cozycafe_post_string('password');
        $rateKey = 'admin-login:' . strtolower($identifier) . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if (!check_rate_limit($rateKey)) {
            http_response_code(429);
            $errors[] = __('error.rate_limited', ['minutes' => 15]);
        } elseif ($identifier === '' || $password === '') {
            $errors[] = __('validation.identity_required');
        } else {
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
                $statement = $pdo->prepare(
                    'SELECT id, name, username, email, password_hash
                     FROM admins
                     WHERE email = :identifier
                     LIMIT 1'
                );
            } else {
                $statement = $pdo->prepare(
                    'SELECT id, name, username, email, password_hash
                     FROM admins
                     WHERE username = :identifier
                     LIMIT 1'
                );
            }
            $statement->execute(['identifier' => $identifier]);
            $admin = $statement->fetch();

            if (is_array($admin) && password_verify($password, (string) $admin['password_hash'])) {
                $adminId = (int) $admin['id'];
                $pdo->beginTransaction();
                try {
                    $updateLoginTime = $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = :id');
                    $updateLoginTime->execute(['id' => $adminId]);
                    log_admin_action(
                        $pdo,
                        $adminId,
                        'login',
                        'admin',
                        $adminId,
                        'Administrator login successful.'
                    );
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }

                clear_rate_limit($rateKey);
                $language = $_SESSION['lang'] ?? 'id';
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('Unable to renew the session after administrator login.');
                }
                $_SESSION = [
                    'admin_id' => $adminId,
                    '_csrf_token' => bin2hex(random_bytes(32)),
                ];
                if (is_string($language) && in_array($language, ['id', 'en'], true)) {
                    $_SESSION['lang'] = $language;
                }

                cozycafe_auth_redirect('admin/login.php?authenticated=1', 303);
            }

            $errors[] = __('error.login_failed');
        }
    }
}

auth_layout_start(
    $showAuthenticatedState ? __('message.admin_login_success') : __('auth.admin_login_title'),
    $showAuthenticatedState ? __('auth.admin_welcome') : __('auth.admin_login_intro'),
    false,
    true
);
auth_layout_alerts($errors, $notice);
if ($showAuthenticatedState):
    ?>
    <form method="post" action="<?= e(cozycafe_app_path('admin/logout.php')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-cozy w-100" type="submit"><?= e(__('nav.logout')) ?></button>
    </form>
<?php else: ?>
    <form method="post" action="<?= e(cozycafe_app_path('admin/login.php')) ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="identifier"><?= e(__('label.admin_identifier')) ?></label>
            <input class="form-control" id="identifier" name="identifier" type="text" autocomplete="username" required value="<?= e($identifier) ?>">
        </div>
        <div class="mb-4">
            <label class="form-label" for="password"><?= e(__('label.password')) ?></label>
            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-cozy w-100" type="submit"><?= e(__('nav.login')) ?></button>
    </form>
<?php endif; ?>
<?php auth_layout_end(); ?>
