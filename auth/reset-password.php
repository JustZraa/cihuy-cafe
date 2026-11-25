<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_layout.php';

start_secure_session();
redirect_if_authenticated('customer');

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$errors = [];
$token = $_GET['token'] ?? '';
$validToken = false;

if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
    $tokenCheck = $pdo->prepare(
        'SELECT pr.id
         FROM password_resets AS pr
         INNER JOIN users AS u ON u.email = pr.email
         WHERE pr.token_hash = :token_hash
           AND pr.used_at IS NULL
           AND pr.expires_at > NOW()
           AND u.is_active = 1
         LIMIT 1'
    );
    $tokenCheck->execute(['token_hash' => hash('sha256', $token)]);
    $validToken = $tokenCheck->fetchColumn() !== false;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$validToken) {
    http_response_code(400);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $token = $_POST['token'] ?? '';
        $password = cozycafe_post_string('password');
        $passwordConfirmation = cozycafe_post_string('password_confirmation');
        $validToken = false;

        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
            $tokenCheck = $pdo->prepare(
                'SELECT pr.id
                 FROM password_resets AS pr
                 INNER JOIN users AS u ON u.email = pr.email
                 WHERE pr.token_hash = :token_hash
                   AND pr.used_at IS NULL
                   AND pr.expires_at > NOW()
                   AND u.is_active = 1
                 LIMIT 1'
            );
            $tokenCheck->execute(['token_hash' => hash('sha256', $token)]);
            $validToken = $tokenCheck->fetchColumn() !== false;
        }

        if (!$validToken) {
            http_response_code(400);
            $errors[] = __('validation.reset_token_invalid');
        }
        if (strlen($password) < 8) {
            $errors[] = __('validation.password_too_short');
        } elseif (strlen($password) > 72) {
            $errors[] = __('validation.password_too_long');
        }
        if ($password !== $passwordConfirmation) {
            $errors[] = __('validation.password_mismatch');
        }

        if ($errors === []) {
            $tokenHash = hash('sha256', $token);
            $pdo->beginTransaction();
            try {
                $lockToken = $pdo->prepare(
                    'SELECT pr.id, pr.email, u.id AS user_id
                     FROM password_resets AS pr
                     INNER JOIN users AS u ON u.email = pr.email
                     WHERE pr.token_hash = :token_hash
                       AND pr.used_at IS NULL
                       AND pr.expires_at > NOW()
                       AND u.is_active = 1
                     LIMIT 1
                     FOR UPDATE'
                );
                $lockToken->execute(['token_hash' => $tokenHash]);
                $reset = $lockToken->fetch();
                if (!is_array($reset)) {
                    $pdo->rollBack();
                    http_response_code(400);
                    $errors[] = __('validation.reset_token_invalid');
                } else {
                    $updatePassword = $pdo->prepare(
                        'UPDATE users SET password_hash = :password_hash WHERE id = :user_id AND is_active = 1'
                    );
                    $updatePassword->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'user_id' => $reset['user_id'],
                    ]);

                    $markTokensUsed = $pdo->prepare(
                        'UPDATE password_resets SET used_at = NOW() WHERE email = :email AND used_at IS NULL'
                    );
                    $markTokensUsed->execute(['email' => $reset['email']]);
                    $pdo->commit();

                    cozycafe_auth_redirect('auth/login.php?reset=1', 303);
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }
}

auth_layout_start(__('auth.reset_title'), __('auth.reset_intro'));
auth_layout_alerts($errors);
if ($validToken):
    ?>
    <form method="post" action="<?= e(cozycafe_app_path('auth/reset-password.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e(is_string($token) ? $token : '') ?>">
        <div class="mb-3">
            <label class="form-label" for="password"><?= e(__('label.new_password')) ?></label>
            <input class="form-control" id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirmation"><?= e(__('label.confirm_password')) ?></label>
            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
        </div>
        <button class="btn btn-cozy w-100" type="submit"><?= e(__('auth.reset_password')) ?></button>
    </form>
<?php else: ?>
    <p class="alert alert-warning" role="status"><?= e(__('validation.reset_token_invalid')) ?></p>
    <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('auth/forgot-password.php')) ?>"><?= e(__('button.retry')) ?></a>
<?php endif; ?>
<?php auth_layout_end(); ?>
