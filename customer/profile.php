<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_layout.php';

require_customer_login();
$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = (int) $_SESSION['customer_id'];

$userQuery = $pdo->prepare(
    'SELECT id, full_name, email, phone, password_hash, is_active
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$userQuery->execute(['id' => $customerId]);
$user = $userQuery->fetch();

if (!is_array($user) || (int) $user['is_active'] !== 1) {
    destroy_secure_session();
    cozycafe_auth_redirect('auth/login.php', 303);
}

$errors = [];
$notice = null;
$profileName = (string) $user['full_name'];
$profileEmail = (string) $user['email'];
$profilePhone = (string) $user['phone'];

if (($_GET['saved'] ?? '') === '1') {
    $notice = __('message.profile_saved');
} elseif (($_GET['password'] ?? '') === 'changed') {
    $notice = __('message.password_changed');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $profileName = trim(cozycafe_post_string('full_name'));
            $profileEmail = strtolower(trim(cozycafe_post_string('email')));
            $phoneInput = trim(cozycafe_post_string('phone'));
            $profilePhone = $phoneInput;
            $nameLength = preg_match_all('/./us', $profileName);

            if ($nameLength === false || $nameLength < 2 || $nameLength > 150 || preg_match('/[\x00-\x1F\x7F]/u', $profileName) === 1) {
                $errors[] = __('validation.name_invalid');
            }
            if (filter_var($profileEmail, FILTER_VALIDATE_EMAIL) === false || strlen($profileEmail) > 190) {
                $errors[] = __('validation.invalid_email');
            }

            try {
                $profilePhone = normalize_indonesian_phone($phoneInput);
            } catch (InvalidArgumentException) {
                $errors[] = __('validation.invalid_phone');
            }

            if ($errors === []) {
                $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $emailCheck->execute(['email' => $profileEmail, 'id' => $customerId]);
                if ($emailCheck->fetchColumn() !== false) {
                    $errors[] = __('validation.duplicate_email');
                }

                $phoneCheck = $pdo->prepare('SELECT id FROM users WHERE phone = :phone AND id <> :id LIMIT 1');
                $phoneCheck->execute(['phone' => $profilePhone, 'id' => $customerId]);
                if ($phoneCheck->fetchColumn() !== false) {
                    $errors[] = __('validation.duplicate_phone');
                }
            }

            if ($errors === []) {
                $updateProfile = $pdo->prepare(
                    'UPDATE users
                     SET full_name = :full_name, email = :email, phone = :phone
                     WHERE id = :id AND is_active = 1'
                );
                $pdo->beginTransaction();
                try {
                    $updateProfile->execute([
                        'full_name' => $profileName,
                        'email' => $profileEmail,
                        'phone' => $profilePhone,
                        'id' => $customerId,
                    ]);
                    if ($profileEmail !== (string) $user['email']) {
                        $invalidateOldEmailTokens = $pdo->prepare(
                            'UPDATE password_resets SET used_at = NOW() WHERE email = :email AND used_at IS NULL'
                        );
                        $invalidateOldEmailTokens->execute(['email' => $user['email']]);
                    }
                    $pdo->commit();
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                        throw $exception;
                    }
                    http_response_code(409);
                    $errors[] = __('validation.account_conflict');
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }

                if ($errors === []) {
                    cozycafe_auth_redirect('customer/profile.php?saved=1', 303);
                }
            }
        } elseif ($action === 'change_password') {
            $currentPassword = cozycafe_post_string('current_password');
            $newPassword = cozycafe_post_string('new_password');
            $passwordConfirmation = cozycafe_post_string('password_confirmation');

            if ($currentPassword === '') {
                $errors[] = __('error.current_password_required');
            } elseif (!password_verify($currentPassword, (string) $user['password_hash'])) {
                $errors[] = __('validation.wrong_current_password');
            }
            if (strlen($newPassword) < 8) {
                $errors[] = __('validation.password_too_short');
            } elseif (strlen($newPassword) > 72) {
                $errors[] = __('validation.password_too_long');
            }
            if ($newPassword !== $passwordConfirmation) {
                $errors[] = __('validation.password_mismatch');
            }

            if ($errors === []) {
                $pdo->beginTransaction();
                try {
                    $updatePassword = $pdo->prepare(
                        'UPDATE users SET password_hash = :password_hash WHERE id = :id AND is_active = 1'
                    );
                    $updatePassword->execute([
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'id' => $customerId,
                    ]);

                    $invalidateTokens = $pdo->prepare(
                        'UPDATE password_resets SET used_at = NOW() WHERE email = :email AND used_at IS NULL'
                    );
                    $invalidateTokens->execute(['email' => $user['email']]);
                    $pdo->commit();
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException('Unable to renew the session after password change.');
                    }
                    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
                    cozycafe_auth_redirect('customer/profile.php?password=changed', 303);
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }
            }
        } else {
            http_response_code(400);
            $errors[] = __('error.invalid_request');
        }
    }
}

$ordersQuery = $pdo->prepare(
    'SELECT order_code, status, total_amount, created_at
     FROM orders
     WHERE user_id = :user_id
     ORDER BY created_at DESC
     LIMIT 10'
);
$ordersQuery->execute(['user_id' => $customerId]);
$orders = $ordersQuery->fetchAll();

$reservationsQuery = $pdo->prepare(
    'SELECT booking_code, reservation_date, start_time, status, dp_amount
     FROM reservations
     WHERE user_id = :user_id
     ORDER BY reservation_date DESC, start_time DESC
     LIMIT 10'
);
$reservationsQuery->execute(['user_id' => $customerId]);
$reservations = $reservationsQuery->fetchAll();

$orderStatusKeys = [
    'Pending' => 'status.order.pending',
    'Waiting for Payment' => 'status.order.waiting_for_payment',
    'Paid' => 'status.order.paid',
    'Processing' => 'status.order.processing',
    'Ready' => 'status.order.ready',
    'Completed' => 'status.order.completed',
    'Cancelled' => 'status.order.cancelled',
];
$reservationStatusKeys = [
    'Waiting for DP' => 'status.reservation.waiting_for_dp',
    'Confirmed' => 'status.reservation.confirmed',
    'Cancelled' => 'status.reservation.cancelled',
    'Completed' => 'status.reservation.completed',
    'No Show' => 'status.reservation.no_show',
    'Expired' => 'status.reservation.expired',
];

auth_layout_start(__('profile.title'), '', true);
auth_layout_alerts($errors, $notice);
?>
<section class="profile-section mb-4">
    <h2 class="h5 mb-3"><?= e(__('profile.account_details')) ?></h2>
    <form method="post" action="<?= e(cozycafe_app_path('customer/profile.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="full_name"><?= e(__('label.full_name')) ?></label>
                <input class="form-control" id="full_name" name="full_name" type="text" maxlength="150" autocomplete="name" required value="<?= e($profileName) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="email"><?= e(__('label.email')) ?></label>
                <input class="form-control" id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?= e($profileEmail) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="phone"><?= e(__('label.phone')) ?></label>
                <input class="form-control" id="phone" name="phone" type="tel" maxlength="24" autocomplete="tel" required value="<?= e($profilePhone) ?>">
            </div>
        </div>
        <button class="btn btn-cozy mt-3" type="submit"><?= e(__('button.save')) ?></button>
    </form>
</section>

<section class="profile-section mb-4">
    <h2 class="h5 mb-3"><?= e(__('profile.change_password')) ?></h2>
    <form method="post" action="<?= e(cozycafe_app_path('customer/profile.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="current_password"><?= e(__('label.current_password')) ?></label>
                <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="new_password"><?= e(__('label.new_password')) ?></label>
                <input class="form-control" id="new_password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="password_confirmation"><?= e(__('label.confirm_password')) ?></label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
            </div>
        </div>
        <button class="btn btn-cozy mt-3" type="submit"><?= e(__('button.save')) ?></button>
    </form>
</section>

<section class="profile-section mb-4">
    <h2 class="h5 mb-3"><?= e(__('profile.recent_orders')) ?></h2>
    <?php if ($orders === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('profile.no_orders')) ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-cozy align-middle mb-0">
                <thead><tr><th><?= e(__('label.order_code')) ?></th><th><?= e(__('label.date')) ?></th><th><?= e(__('label.total')) ?></th><th><?= e(__('label.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php $statusKey = $orderStatusKeys[$order['status']] ?? null; ?>
                    <tr>
                        <td><?= e($order['order_code']) ?></td>
                        <td><?= e(format_wib((string) $order['created_at'])) ?></td>
                        <td><?= e(format_rupiah((string) $order['total_amount'])) ?></td>
                        <td><?= e($statusKey === null ? (string) $order['status'] : __($statusKey)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="profile-section">
    <h2 class="h5 mb-3"><?= e(__('profile.recent_reservations')) ?></h2>
    <?php if ($reservations === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('profile.no_reservations')) ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-cozy align-middle mb-0">
                <thead><tr><th><?= e(__('label.booking_code')) ?></th><th><?= e(__('label.date')) ?></th><th><?= e(__('label.time')) ?></th><th><?= e(__('label.dp')) ?></th><th><?= e(__('label.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($reservations as $reservation): ?>
                    <?php $statusKey = $reservationStatusKeys[$reservation['status']] ?? null; ?>
                    <tr>
                        <td><?= e($reservation['booking_code']) ?></td>
                        <td><?= e(format_wib((string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'])) ?></td>
                        <td><?= e((string) $reservation['start_time']) ?></td>
                        <td><?= e(format_rupiah((string) $reservation['dp_amount'])) ?></td>
                        <td><?= e($statusKey === null ? (string) $reservation['status'] : __($statusKey)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<div class="d-flex justify-content-end mt-3">
    <a class="btn btn-outline-secondary" href="<?= e(cozycafe_app_path('auth/logout.php')) ?>"><?= e(__('nav.logout')) ?></a>
</div>
<?php auth_layout_end(); ?>
