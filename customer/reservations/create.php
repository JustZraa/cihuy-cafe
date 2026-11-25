<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'reservation_service.php';

start_secure_session();
require_customer_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit(__('error.method_not_allowed'));
}

$pdo = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
if ($customerId === null) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$customerQuery = $pdo->prepare('SELECT id, is_active FROM users WHERE id = :id LIMIT 1');
$customerQuery->execute(['id' => $customerId]);
$customer = $customerQuery->fetch();
if (!is_array($customer) || (int) $customer['is_active'] !== 1) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$error = null;
if (!verify_csrf_token()) {
    http_response_code(403);
    $error = __('validation.invalid_csrf');
} else {
    $tableIdInput = $_POST['table_id'] ?? null;
    $durationInput = $_POST['duration_minutes'] ?? null;
    $guestCountInput = $_POST['guest_count'] ?? null;
    if (
        !is_string($tableIdInput)
        || preg_match('/\A[1-9][0-9]{0,9}\z/', $tableIdInput) !== 1
        || !is_string($durationInput)
        || preg_match('/\A[1-9][0-9]{0,2}\z/', $durationInput) !== 1
        || !is_string($guestCountInput)
        || preg_match('/\A[1-9][0-9]{0,2}\z/', $guestCountInput) !== 1
    ) {
        http_response_code(422);
        $error = __('error.reservation_invalid_request');
    } else {
        try {
            $reservation = cozycafe_create_reservation(
                $pdo,
                $customerId,
                (int) $tableIdInput,
                trim(cozycafe_post_string('reservation_date')),
                trim(cozycafe_post_string('start_time')),
                (int) $durationInput,
                (int) $guestCountInput
            );
            $_SESSION['_reservation_created'] = $reservation;
            header('Location: ' . cozycafe_app_path('customer/reservations/index.php?created=1'), true, 303);
            exit;
        } catch (CozyCafeReservationException $exception) {
            http_response_code(422);
            $error = __($exception->translationKey, $exception->replacements);
        }
    }
}

$pageTitle = __('reservation.title');
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="section-space container">
    <div class="alert alert-danger" role="alert"><?= e($error ?? __('error.generic')) ?></div>
    <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('customer/reservations/index.php')) ?>"><?= e(__('reservation.back_to_availability')) ?></a>
</section>
<?php require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
