<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_layout.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cart_service.php';

start_secure_session();
redirect_if_authenticated('customer');

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$errors = [];
$identifier = '';
$notice = null;

if (($_SESSION['_customer_disabled_notice'] ?? false) === true) {
    unset($_SESSION['_customer_disabled_notice']);
    $notice = __('validation.login_disabled');
} elseif (($_GET['registered'] ?? '') === '1') {
    $notice = __('message.registration_success');
} elseif (($_GET['reset'] ?? '') === '1') {
    $notice = __('message.password_reset_success');
} elseif (($_GET['logout'] ?? '') === '1') {
    $notice = __('message.logout_success');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $identifier = trim(cozycafe_post_string('identifier'));
        $password = cozycafe_post_string('password');
        $rateIdentity = strtolower($identifier);

        if ($identifier !== '' && filter_var($identifier, FILTER_VALIDATE_EMAIL) === false) {
            try {
                $rateIdentity = normalize_indonesian_phone($identifier);
            } catch (InvalidArgumentException) {
                $rateIdentity = preg_replace('/\D+/', '', $identifier) ?: strtolower($identifier);
            }
        }

        $rateKey = 'customer-login:' . $rateIdentity . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!check_rate_limit($rateKey)) {
            http_response_code(429);
            $errors[] = __('error.rate_limited', ['minutes' => 15]);
        } elseif ($identifier === '' || $password === '') {
            $errors[] = __('validation.identity_required');
        } else {
            $user = false;
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
                $statement = $pdo->prepare(
                    'SELECT id, password_hash, is_active FROM users WHERE email = :email LIMIT 1'
                );
                $statement->execute(['email' => strtolower($identifier)]);
                $user = $statement->fetch();
            } else {
                try {
                    $phone = normalize_indonesian_phone($identifier);
                    $statement = $pdo->prepare(
                        'SELECT id, password_hash, is_active FROM users WHERE phone = :phone LIMIT 1'
                    );
                    $statement->execute(['phone' => $phone]);
                    $user = $statement->fetch();
                } catch (InvalidArgumentException) {
                    $user = false;
                }
            }

            if (
                is_array($user)
                && (int) $user['is_active'] === 1
                && password_verify($password, (string) $user['password_hash'])
            ) {
                clear_rate_limit($rateKey);
                $language = $_SESSION['lang'] ?? 'id';
                $guestCart = $_SESSION['guest_cart'] ?? null;
                $cartMergeAdjusted = false;
                if (is_array($guestCart)) {
                    $cartMergeAdjusted = cozycafe_cart_merge_guest($pdo, (int) $user['id'], $guestCart);
                    unset($_SESSION['guest_cart']);
                }
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('Unable to renew the session after customer login.');
                }

                $_SESSION = [
                    'customer_id' => (int) $user['id'],
                    '_csrf_token' => bin2hex(random_bytes(32)),
                ];
                if (is_string($language) && in_array($language, ['id', 'en'], true)) {
                    $_SESSION['lang'] = $language;
                }
                if ($cartMergeAdjusted) {
                    $_SESSION['_cart_merge_adjusted'] = true;
                }

                cozycafe_auth_redirect('customer/dashboard.php', 303);
            }

            $errors[] = __('error.login_failed');
        }
    }
}

auth_layout_start(__('auth.login_title'), __('auth.login_intro'));
auth_layout_alerts($errors, $notice);
?>
<form method="post" action="<?= e(cozycafe_app_path('auth/login.php')) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="identifier"><?= e(__('label.identifier')) ?></label>
        <input class="form-control" id="identifier" name="identifier" type="text" autocomplete="username" required value="<?= e($identifier) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password"><?= e(__('label.password')) ?></label>
        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <p class="text-end">
        <a href="<?= e(cozycafe_app_path('auth/forgot-password.php')) ?>"><?= e(__('auth.forgot_link')) ?></a>
    </p>
    <button class="btn btn-cozy w-100" type="submit"><?= e(__('nav.login')) ?></button>
</form>
<p class="mt-4 mb-0 text-center">
    <?= e(__('auth.no_account')) ?>
    <a href="<?= e(cozycafe_app_path('auth/register.php')) ?>"><?= e(__('nav.register')) ?></a>
</p>
<?php auth_layout_end(); ?>
