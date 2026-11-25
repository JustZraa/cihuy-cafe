<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_layout.php';

start_secure_session();
redirect_if_authenticated('customer');

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$errors = [];
$fullName = '';
$email = '';
$phone = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $fullName = trim(cozycafe_post_string('full_name'));
        $email = strtolower(trim(cozycafe_post_string('email')));
        $phoneInput = trim(cozycafe_post_string('phone'));
        $password = cozycafe_post_string('password');
        $passwordConfirmation = cozycafe_post_string('password_confirmation');
        $nameLength = preg_match_all('/./us', $fullName);

        if ($nameLength === false || $nameLength < 2 || $nameLength > 150 || preg_match('/[\x00-\x1F\x7F]/u', $fullName) === 1) {
            $errors[] = __('validation.name_invalid');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            $errors[] = __('validation.invalid_email');
        }

        try {
            $phone = normalize_indonesian_phone($phoneInput);
        } catch (InvalidArgumentException) {
            $errors[] = __('validation.invalid_phone');
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
            $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $emailCheck->execute(['email' => $email]);
            if ($emailCheck->fetchColumn() !== false) {
                $errors[] = __('validation.duplicate_email');
            }

            $phoneCheck = $pdo->prepare('SELECT id FROM users WHERE phone = :phone LIMIT 1');
            $phoneCheck->execute(['phone' => $phone]);
            if ($phoneCheck->fetchColumn() !== false) {
                $errors[] = __('validation.duplicate_phone');
            }
        }

        if ($errors === []) {
            $statement = $pdo->prepare(
                'INSERT INTO users (full_name, email, phone, password_hash)
                 VALUES (:full_name, :email, :phone, :password_hash)'
            );
            try {
                $statement->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                    throw $exception;
                }
                http_response_code(409);
                $errors[] = __('validation.account_conflict');
            }

            if ($errors === []) {
                cozycafe_auth_redirect('auth/login.php?registered=1', 303);
            }
        }
    }
}

auth_layout_start(__('auth.register_title'), __('auth.register_intro'));
auth_layout_alerts($errors);
?>
<form method="post" action="<?= e(cozycafe_app_path('auth/register.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="full_name"><?= e(__('label.full_name')) ?></label>
        <input class="form-control" id="full_name" name="full_name" type="text" maxlength="150" autocomplete="name" required value="<?= e($fullName) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="email"><?= e(__('label.email')) ?></label>
        <input class="form-control" id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?= e($email) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="phone"><?= e(__('label.phone')) ?></label>
        <input class="form-control" id="phone" name="phone" type="tel" maxlength="24" autocomplete="tel" required value="<?= e($phone !== '' ? $phone : ($phoneInput ?? '')) ?>">
        <div class="form-text">08xxxxxxxxxx</div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password"><?= e(__('label.password')) ?></label>
        <input class="form-control" id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
    </div>
    <div class="mb-4">
        <label class="form-label" for="password_confirmation"><?= e(__('label.confirm_password')) ?></label>
        <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
    </div>
    <button class="btn btn-cozy w-100" type="submit"><?= e(__('nav.register')) ?></button>
</form>
<p class="mt-4 mb-0 text-center">
    <?= e(__('auth.already_have_account')) ?>
    <a href="<?= e(cozycafe_app_path('auth/login.php')) ?>"><?= e(__('nav.login')) ?></a>
</p>
<?php auth_layout_end(); ?>
