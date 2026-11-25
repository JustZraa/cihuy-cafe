<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'payment_service.php';

start_secure_session();
$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$errors = [];
$payment = null;
$proofs = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        try {
            $payment = cozycafe_payment_find_order_access(
                $pdo,
                cozycafe_post_string('order_code'),
                cozycafe_post_string('phone')
            );
            header(
                'Location: ' . cozycafe_app_path('customer/payment.php?code=' . rawurlencode((string) $payment['payment_code'])),
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

$paymentCode = $_GET['code'] ?? null;
if (
    is_string($paymentCode)
    && preg_match('/\APAY-[0-9]{8}-[A-F0-9]{10}\z/', $paymentCode) === 1
) {
    $access = cozycafe_payment_access();
    if (
        is_array($access)
        && ($access['payable_type'] ?? null) === 'order'
        && (int) ($access['payment_id'] ?? 0) > 0
    ) {
        cozycafe_expire_payment_if_unproven($pdo, (int) $access['payment_id']);
        $paymentQuery = $pdo->prepare(
            "SELECT p.id, p.payment_code, p.payable_type, p.payable_id, p.amount,
                    p.payment_method, p.status, p.rejection_reason, p.expires_at,
                    o.order_code, o.guest_name, o.order_type, o.status AS order_status,
                    o.total_amount
             FROM payments p
             INNER JOIN orders o ON o.id = p.payable_id
             WHERE p.id = :payment_id
               AND p.payment_code = :payment_code
               AND p.payable_type = 'order'
               AND o.order_type = 'takeaway'
             LIMIT 1"
        );
        $paymentQuery->execute([
            'payment_id' => (int) $access['payment_id'],
            'payment_code' => $paymentCode,
        ]);
        $paymentResult = $paymentQuery->fetch();
        if (is_array($paymentResult) && cozycafe_payment_authorized($pdo, $paymentResult)) {
            $payment = $paymentResult;
            $proofs = cozycafe_payment_proofs($pdo, (int) $payment['id']);
        } else {
            http_response_code(404);
        }
    } else {
        http_response_code(404);
    }
}

$canUpload = is_array($payment)
    && in_array($payment['status'], ['Pending', 'Rejected'], true)
    && $payment['order_status'] === 'Waiting for Payment';
$hasProof = $proofs !== [];
$deadlineTimestamp = null;
if (
    is_array($payment)
    && is_string($payment['expires_at'])
    && !($payment['status'] === 'Rejected' && $hasProof)
) {
    $deadlineTimestamp = (new DateTimeImmutable($payment['expires_at'], new DateTimeZone('Asia/Jakarta')))
        ->getTimestamp() * 1000;
}
$paymentStatusKeys = [
    'Pending' => 'status.payment.pending',
    'Waiting Verification' => 'status.payment.waiting_verification',
    'Verified' => 'status.payment.verified',
    'Rejected' => 'status.payment.rejected',
    'Expired' => 'status.payment.expired',
    'Refunded' => 'status.payment.refunded',
];
$statusKey = is_array($payment)
    ? ($paymentStatusKeys[(string) $payment['status']] ?? 'label.payment_status')
    : 'label.payment_status';

$pageTitle = __('payment.title');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('payment.eyebrow')) ?></p>
        <h1><?= e(__('payment.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('payment.intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
                <p class="mb-1"><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!is_array($payment)): ?>
        <section class="track-form-card payment-lookup-card">
            <h2 class="h4"><?= e(__('payment.lookup_title')) ?></h2>
            <p class="text-secondary"><?= e(__('payment.lookup_intro')) ?></p>
            <form method="post" action="<?= e(cozycafe_app_path('customer/payment.php')) ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="payment-order-code"><?= e(__('label.order_code')) ?></label>
                    <input class="form-control text-uppercase" id="payment-order-code" name="order_code" type="text" maxlength="40" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="payment-phone"><?= e(__('label.phone')) ?></label>
                    <input class="form-control" id="payment-phone" name="phone" type="tel" maxlength="20" required>
                </div>
                <button class="btn btn-cozy" type="submit"><?= e(__('payment.find_order')) ?></button>
            </form>
        </section>
    <?php else: ?>
        <div class="row g-4 align-items-start">
            <div class="col-12 col-lg-5">
                <section class="payment-summary-card">
                    <p class="eyebrow mb-1"><?= e(__('payment.order_reference')) ?></p>
                    <h2 class="h3"><?= e($payment['order_code']) ?></h2>
                    <div class="payment-meta">
                        <div><span><?= e(__('label.full_name')) ?></span><strong><?= e($payment['guest_name']) ?></strong></div>
                        <div><span><?= e(__('label.payment_status')) ?></span><strong><?= e(__($statusKey)) ?></strong></div>
                        <div><span><?= e(__('label.total')) ?></span><strong><?= e(format_rupiah((string) $payment['amount'])) ?></strong></div>
                    </div>
                    <?php if ($payment['status'] === 'Waiting Verification'): ?>
                        <div class="alert alert-info mt-4 mb-0" role="status"><?= e(__('payment.waiting_verification')) ?></div>
                    <?php elseif ($payment['status'] === 'Rejected'): ?>
                        <div class="alert alert-danger mt-4 mb-0" role="alert">
                            <strong><?= e(__('payment.rejected_title')) ?></strong>
                            <p class="mb-0 mt-1"><?= e(is_string($payment['rejection_reason']) && $payment['rejection_reason'] !== '' ? $payment['rejection_reason'] : __('payment.rejected_no_reason')) ?></p>
                        </div>
                    <?php elseif ($payment['status'] === 'Expired' || $payment['order_status'] === 'Cancelled'): ?>
                        <div class="alert alert-warning mt-4 mb-0" role="status"><?= e(__('payment.expired_notice')) ?></div>
                    <?php elseif ($payment['status'] === 'Verified' || $payment['order_status'] !== 'Waiting for Payment'): ?>
                        <div class="alert alert-success mt-4 mb-0" role="status"><?= e(__('payment.verified_notice')) ?></div>
                    <?php endif; ?>
                </section>

                <?php if ($proofs !== []): ?>
                    <section class="payment-proof-card mt-4">
                        <h2 class="h5"><?= e(__('payment.proof_history')) ?></h2>
                        <div class="payment-proof-list">
                            <?php foreach ($proofs as $proof): ?>
                                <a
                                    class="payment-proof-preview"
                                    href="<?= e(cozycafe_app_path('customer/view-proof.php?id=' . (int) $proof['id'])) ?>"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <img
                                        src="<?= e(cozycafe_app_path('customer/view-proof.php?id=' . (int) $proof['id'])) ?>"
                                        alt="<?= e(__('payment.proof_preview')) ?>"
                                        loading="lazy"
                                    >
                                    <span>
                                        <strong><?= e($proof['original_name']) ?></strong>
                                        <small><?= e(format_wib((string) $proof['uploaded_at'])) ?></small>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>

            <div class="col-12 col-lg-7">
                <?php if ($canUpload): ?>
                    <section class="payment-instructions-card" data-payment-panel data-deadline="<?= e($deadlineTimestamp ?? '') ?>" data-status-url="<?= e(cozycafe_app_path('customer/payment_status.php')) ?>">
                        <h2 class="h4"><?= e(__('payment.qris_title')) ?></h2>
                        <p><?= e(__('payment.qris_instructions')) ?></p>
                        <div class="payment-countdown" data-payment-countdown>
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
                        <form
                            method="post"
                            action="<?= e(cozycafe_app_path('customer/upload_proof.php')) ?>"
                            enctype="multipart/form-data"
                            class="payment-upload-form"
                            data-payment-upload
                            data-expired-message="<?= e(__('payment.expired_notice')) ?>"
                        >
                            <?= csrf_field() ?>
                            <input type="hidden" name="payment_id" value="<?= e((int) $payment['id']) ?>">
                            <div class="mb-3">
                                <label class="form-label" for="payment-proof"><?= e(__('payment.upload_label')) ?></label>
                                <input
                                    class="form-control"
                                    id="payment-proof"
                                    name="proof"
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    required
                                >
                                <div class="form-text"><?= e(__('payment.upload_help')) ?></div>
                            </div>
                            <button class="btn btn-cozy" type="submit" data-upload-submit><?= e(__('payment.upload_submit')) ?></button>
                        </form>
                        <div class="alert alert-warning d-none mt-3 mb-0" role="status" data-payment-expired>
                            <?= e(__('payment.expired_notice')) ?>
                        </div>
                    </section>
                <?php elseif ($payment['status'] === 'Waiting Verification'): ?>
                    <section class="payment-instructions-card">
                        <h2 class="h4"><?= e(__('payment.waiting_verification_title')) ?></h2>
                        <p class="mb-0"><?= e(__('payment.waiting_verification')) ?></p>
                    </section>
                <?php elseif ($payment['status'] === 'Rejected' && $hasProof): ?>
                    <section class="payment-instructions-card">
                        <h2 class="h4"><?= e(__('payment.reupload_title')) ?></h2>
                        <p class="mb-0"><?= e(__('payment.reupload_next_stage')) ?></p>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<script src="<?= e(cozycafe_app_path('assets/js/payment.js')) ?>" defer></script>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
