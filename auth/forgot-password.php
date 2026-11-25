<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_layout.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'mailer.php';

start_secure_session();
redirect_if_authenticated('customer');

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$errors = [];
$success = null;
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $email = strtolower(trim(cozycafe_post_string('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            $errors[] = __('validation.invalid_email');
        } else {
            $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $rateKey = 'password-reset:' . $email . '|' . $clientIp;
            if (
                !check_rate_limit('password-reset-ip:' . $clientIp)
                || !check_rate_limit($rateKey)
            ) {
                http_response_code(429);
                $errors[] = __('error.rate_limited', ['minutes' => 15]);
            } else {
                $statement = $pdo->prepare(
                    'SELECT id, email FROM users WHERE email = :email AND is_active = 1 LIMIT 1'
                );
                $statement->execute(['email' => $email]);
                $user = $statement->fetch();

                if (is_array($user)) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $pdo->beginTransaction();
                    try {
                        $invalidateOldTokens = $pdo->prepare(
                            'UPDATE password_resets
                             SET used_at = NOW()
                             WHERE email = :email AND used_at IS NULL'
                        );
                        $invalidateOldTokens->execute(['email' => $user['email']]);

                        $createToken = $pdo->prepare(
                            'INSERT INTO password_resets (email, token_hash, expires_at)
                             VALUES (:email, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
                        );
                        $createToken->execute([
                            'email' => $user['email'],
                            'token_hash' => $tokenHash,
                        ]);
                        $pdo->commit();
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $exception;
                    }

                    $appUrl = rtrim((string) cozycafe_env('APP_URL', 'http://localhost/cozycafe'), '/');
                    $appUrlParts = parse_url($appUrl);
                    if (
                        filter_var($appUrl, FILTER_VALIDATE_URL) === false
                        || !is_array($appUrlParts)
                        || !in_array($appUrlParts['scheme'] ?? '', ['http', 'https'], true)
                        || empty($appUrlParts['host'])
                        || isset($appUrlParts['query'])
                        || isset($appUrlParts['fragment'])
                        || isset($appUrlParts['user'])
                        || isset($appUrlParts['pass'])
                    ) {
                        throw new RuntimeException('APP_URL must be an absolute HTTP or HTTPS URL without credentials or a query string.');
                    }

                    $resetUrl = $appUrl . '/auth/reset-password.php?token=' . rawurlencode($token);
                    try {
                        send_email(
                            (string) $user['email'],
                            __('email.reset_subject'),
                            __('email.reset_body', ['url' => $resetUrl])
                        );
                    } catch (RuntimeException $exception) {
                        $invalidateFailedDelivery = $pdo->prepare(
                            'UPDATE password_resets SET used_at = NOW() WHERE token_hash = :token_hash'
                        );
                        $invalidateFailedDelivery->execute(['token_hash' => $tokenHash]);
                        error_log('CozyCafe password reset email delivery failed: ' . $exception->getMessage());
                    }
                }

                // Keep the response identical to avoid revealing whether an email belongs to an account.
                $success = __('message.password_reset_requested');
            }
        }
    }
}

auth_layout_start(__('auth.forgot_title'), __('auth.forgot_intro'));
auth_layout_alerts($errors, $success);
?>
<form method="post" action="<?= e(cozycafe_app_path('auth/forgot-password.php')) ?>">
    <?= csrf_field() ?>
    <div class="mb-4">
        <label class="form-label" for="email"><?= e(__('label.email')) ?></label>
        <input class="form-control" id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?= e($email) ?>">
    </div>
    <button class="btn btn-cozy w-100" type="submit"><?= e(__('auth.send_reset_link')) ?></button>
</form>
<p class="mt-4 mb-0 text-center">
    <a href="<?= e(cozycafe_app_path('auth/login.php')) ?>"><?= e(__('auth.back_to_login')) ?></a>
</p>
<?php auth_layout_end(); ?>
