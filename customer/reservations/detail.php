<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'payment_service.php';

start_secure_session();
require_customer_login();
$pdo = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
if ($customerId === null) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$reservationIdInput = $_GET['id'] ?? null;
if (!is_string($reservationIdInput) || preg_match('/\A[1-9][0-9]{0,9}\z/', $reservationIdInput) !== 1) {
    http_response_code(404);
    exit(__('error.reservation_not_found'));
}
$reservationId = (int) $reservationIdInput;
$errors = [];
$notice = null;

$customerQuery = $pdo->prepare('SELECT id, is_active FROM users WHERE id = :id LIMIT 1');
$customerQuery->execute(['id' => $customerId]);
$customer = $customerQuery->fetch();
if (!is_array($customer) || (int) $customer['is_active'] !== 1) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } elseif (cozycafe_post_string('action') !== 'cancel') {
        http_response_code(422);
        $errors[] = __('error.reservation_invalid_request');
    } else {
        try {
            cozycafe_cancel_customer_reservation($pdo, $reservationId, $customerId);
            header(
                'Location: ' . cozycafe_app_path('customer/reservations/detail.php?id=' . $reservationId . '&cancelled=1'),
                true,
                303
            );
            exit;
        } catch (CozyCafePaymentException $exception) {
            http_response_code(422);
            $errors[] = __($exception->translationKey, $exception->replacements);
        }
    }
}

if (($_GET['cancelled'] ?? '') === '1') {
    $notice = __('reservation.cancelled_success');
}

$reservationQuery = $pdo->prepare(
    'SELECT r.id, r.booking_code, r.reservation_date, r.start_time, r.end_time,
            r.duration_minutes, r.guest_count, r.dp_amount, r.status, r.cancelled_by,
            r.cancel_reason, r.dp_refund_status, r.created_at, t.table_number, t.location_area
     FROM reservations r
     INNER JOIN `tables` t ON t.id = r.table_id
     WHERE r.id = :id AND r.user_id = :user_id
     LIMIT 1'
);
$reservationQuery->execute(['id' => $reservationId, 'user_id' => $customerId]);
$reservation = $reservationQuery->fetch();
if (!is_array($reservation)) {
    http_response_code(404);
    exit(__('error.reservation_not_found'));
}

$paymentQuery = $pdo->prepare(
    "SELECT id, payment_code, amount, payment_method, status, rejection_reason,
            expires_at, verified_at
     FROM payments
     WHERE payable_type = 'reservation_dp'
       AND payable_id = :reservation_id
     ORDER BY id DESC
     LIMIT 1"
);
$paymentQuery->execute(['reservation_id' => $reservationId]);
$paymentResult = $paymentQuery->fetch();
$payment = is_array($paymentResult) ? $paymentResult : null;
if (is_array($payment)) {
    cozycafe_expire_payment_if_unproven($pdo, (int) $payment['id']);
    $payment = cozycafe_payment_find_by_id($pdo, (int) $payment['id']);
    $_SESSION['_payment_access'] = [
        'payment_id' => (int) $payment['id'],
        'payable_type' => 'reservation_dp',
        'payable_id' => $reservationId,
    ];
    $proofs = cozycafe_payment_proofs($pdo, (int) $payment['id']);
} else {
    $proofs = [];
}

$reservationQuery->execute(['id' => $reservationId, 'user_id' => $customerId]);
$reservation = $reservationQuery->fetch();
$statusKeys = [
    'Waiting for DP' => 'status.reservation.waiting_for_dp',
    'Confirmed' => 'status.reservation.confirmed',
    'Cancelled' => 'status.reservation.cancelled',
    'Completed' => 'status.reservation.completed',
    'No Show' => 'status.reservation.no_show',
    'Expired' => 'status.reservation.expired',
];
$statusKey = $statusKeys[(string) $reservation['status']] ?? 'label.status';
$paymentStatusKeys = [
    'Pending' => 'status.payment.pending',
    'Waiting Verification' => 'status.payment.waiting_verification',
    'Verified' => 'status.payment.verified',
    'Rejected' => 'status.payment.rejected',
    'Expired' => 'status.payment.expired',
    'Refunded' => 'status.payment.refunded',
];
$paymentStatusKey = is_array($payment)
    ? ($paymentStatusKeys[(string) $payment['status']] ?? 'label.payment_status')
    : 'label.payment_status';
$hasProof = $proofs !== [];
$canUpload = is_array($payment)
    && $reservation['status'] === 'Waiting for DP'
    && in_array($payment['status'], ['Pending', 'Rejected'], true)
    && ($payment['status'] === 'Rejected' || !$hasProof);
$canCancel = in_array($reservation['status'], ['Waiting for DP', 'Confirmed'], true)
    && (!is_array($payment) || $payment['status'] !== 'Waiting Verification');
$deadlineTimestamp = is_array($payment)
    && is_string($payment['expires_at'])
    && !($payment['status'] === 'Rejected' && $hasProof)
    ? (new DateTimeImmutable($payment['expires_at'], new DateTimeZone('Asia/Jakarta')))->getTimestamp() * 1000
    : null;

$pageTitle = __('reservation.detail_title');
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('reservation.eyebrow')) ?></p>
        <h1><?= e(__('reservation.detail_title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('reservation.detail_intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
                <p class="mb-1"><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4 align-items-start">
        <div class="col-12 col-lg-5">
            <section class="reservation-detail-card">
                <p class="eyebrow mb-1"><?= e(__('label.booking_code')) ?></p>
                <h2 class="h3"><?= e($reservation['booking_code']) ?></h2>
                <span class="badge rounded-pill text-bg-primary"><?= e(__($statusKey)) ?></span>
                <dl class="reservation-detail-list mt-4">
                    <div><dt><?= e(__('label.table')) ?></dt><dd><?= e($reservation['table_number']) ?> · <?= e($reservation['location_area']) ?></dd></div>
                    <div><dt><?= e(__('label.date')) ?></dt><dd><?= e(format_wib((string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'])) ?></dd></div>
                    <div><dt><?= e(__('label.time')) ?></dt><dd><?= e(substr((string) $reservation['start_time'], 0, 5)) ?>–<?= e(substr((string) $reservation['end_time'], 0, 5)) ?> WIB</dd></div>
                    <div><dt><?= e(__('label.guest_count')) ?></dt><dd><?= e((int) $reservation['guest_count']) ?></dd></div>
                    <div><dt><?= e(__('label.dp')) ?></dt><dd><?= e(format_rupiah((string) $reservation['dp_amount'])) ?></dd></div>
                    <div><dt><?= e(__('label.payment_status')) ?></dt><dd><?= e(is_array($payment) ? __($paymentStatusKey) : __('common.no_data')) ?></dd></div>
                </dl>
                <?php if (is_array($payment) && $payment['status'] === 'Rejected'): ?>
                    <div class="alert alert-danger" role="alert">
                        <strong><?= e(__('payment.rejected_title')) ?></strong>
                        <p class="mb-0 mt-1"><?= e(is_string($payment['rejection_reason']) && $payment['rejection_reason'] !== '' ? $payment['rejection_reason'] : __('payment.rejected_no_reason')) ?></p>
                    </div>
                <?php elseif (is_array($payment) && $payment['status'] === 'Waiting Verification'): ?>
                    <div class="alert alert-info" role="status"><?= e(__('payment.waiting_verification')) ?></div>
                <?php elseif (is_array($payment) && $payment['status'] === 'Expired'): ?>
                    <div class="alert alert-warning" role="status"><?= e(__('payment.expired_notice')) ?></div>
                <?php elseif (is_array($payment) && $payment['status'] === 'Verified'): ?>
                    <div class="alert alert-success" role="status"><?= e(__('payment.dp_verified_notice')) ?></div>
                <?php endif; ?>
                <div class="reservation-policy-note">
                    <strong><?= e(__('reservation.cancellation_policy_title')) ?></strong>
                    <p class="mb-0 mt-1"><?= e(__('reservation.cancellation_policy')) ?></p>
                </div>
                <?php if ($canCancel): ?>
                    <form method="post" action="<?= e(cozycafe_app_path('customer/reservations/detail.php?id=' . $reservationId)) ?>" class="mt-3" data-confirm="<?= e(__('reservation.cancel_confirm')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button class="btn btn-outline-danger" type="submit"><?= e(__('reservation.cancel_booking')) ?></button>
                    </form>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-12 col-lg-7">
            <?php if ($hasProof): ?>
                <section class="payment-proof-card mb-4">
                    <h2 class="h5"><?= e(__('payment.proof_history')) ?></h2>
                    <div class="payment-proof-list">
                        <?php foreach ($proofs as $proof): ?>
                            <a class="payment-proof-preview" href="<?= e(cozycafe_app_path('customer/view-proof.php?id=' . (int) $proof['id'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(cozycafe_app_path('customer/view-proof.php?id=' . (int) $proof['id'])) ?>" alt="<?= e(__('payment.proof_preview')) ?>" loading="lazy">
                                <span>
                                    <strong><?= e($proof['original_name']) ?></strong>
                                    <small><?= e(format_wib((string) $proof['uploaded_at'])) ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($canUpload): ?>
                <section class="payment-instructions-card" data-payment-panel data-deadline="<?= e($deadlineTimestamp ?? '') ?>" data-status-url="<?= e(cozycafe_app_path('customer/payment_status.php')) ?>">
                    <h2 class="h4"><?= e(__('payment.qris_title')) ?></h2>
                    <p><?= e(__('payment.qris_instructions')) ?></p>
                    <div class="payment-countdown">
                        <span><?= e(__('payment.time_remaining')) ?></span>
                        <strong data-countdown-value>--:--</strong>
                    </div>
                    <div class="payment-qr-frame">
                        <img src="<?= e(cozycafe_app_path('assets/qris/qris-cozycafe.svg')) ?>" alt="<?= e(__('payment.qris_alt')) ?>">
                    </div>
                    <div class="alert alert-warning mt-3" role="alert">
                        <strong><?= e(__('payment.qris_placeholder_title')) ?></strong>
                        <p class="mb-0 mt-1"><?= e(__('payment.qris_placeholder_notice')) ?></p>
                    </div>
                    <form method="post" action="<?= e(cozycafe_app_path('customer/upload_proof.php')) ?>" enctype="multipart/form-data" class="payment-upload-form" data-payment-upload>
                        <?= csrf_field() ?>
                        <input type="hidden" name="payment_id" value="<?= e((int) $payment['id']) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="reservation-payment-proof"><?= e(__('payment.upload_label')) ?></label>
                            <input class="form-control" id="reservation-payment-proof" name="proof" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                            <div class="form-text"><?= e(__('payment.upload_help')) ?></div>
                        </div>
                        <button class="btn btn-cozy" type="submit" data-upload-submit><?= e(__('payment.upload_submit')) ?></button>
                    </form>
                    <div class="alert alert-warning d-none mt-3 mb-0" role="status" data-payment-expired><?= e(__('payment.expired_notice')) ?></div>
                </section>
            <?php elseif (is_array($payment) && $payment['status'] === 'Waiting Verification'): ?>
                <section class="payment-instructions-card">
                    <h2 class="h4"><?= e(__('payment.waiting_verification_title')) ?></h2>
                    <p class="mb-0"><?= e(__('payment.waiting_verification')) ?></p>
                </section>
            <?php elseif (is_array($payment) && $payment['status'] === 'Rejected' && $hasProof): ?>
                <section class="payment-instructions-card">
                    <h2 class="h4"><?= e(__('payment.reupload_title')) ?></h2>
                    <p class="mb-0"><?= e(__('payment.reupload_next_stage')) ?></p>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>
<script src="<?= e(cozycafe_app_path('assets/js/payment.js')) ?>" defer></script>
<script src="<?= e(cozycafe_app_path('assets/js/reservation-detail.js')) ?>" defer></script>
<?php require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
