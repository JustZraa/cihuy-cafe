<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'payment_service.php';

start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit(__('error.method_not_allowed'));
}

if (!verify_csrf_token()) {
    http_response_code(403);
    exit(__('validation.invalid_csrf'));
}

$paymentIdInput = $_POST['payment_id'] ?? null;
if (!is_string($paymentIdInput) || preg_match('/\A[1-9][0-9]{0,9}\z/', $paymentIdInput) !== 1) {
    http_response_code(422);
    exit(__('error.payment_not_found'));
}

$file = $_FILES['proof'] ?? null;
if (!is_array($file)) {
    http_response_code(422);
    exit(__('error.payment_proof_required'));
}

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
try {
    cozycafe_payment_upload_proof($pdo, (int) $paymentIdInput, $file);
    $access = cozycafe_payment_access();
    if (
        is_array($access)
        && ($access['payable_type'] ?? null) === 'reservation_dp'
        && (int) ($access['payable_id'] ?? 0) > 0
    ) {
        header(
            'Location: ' . cozycafe_app_path('customer/reservations/detail.php?id=' . (int) $access['payable_id']),
            true,
            303
        );
        exit;
    }

    $payment = cozycafe_payment_find_by_id($pdo, (int) $paymentIdInput);
    if (!is_array($payment)) {
        throw new RuntimeException('The uploaded payment could not be reloaded.');
    }
    header(
        'Location: ' . cozycafe_app_path('customer/payment.php?code=' . rawurlencode((string) $payment['payment_code'])),
        true,
        303
    );
    exit;
} catch (CozyCafePaymentException $exception) {
    http_response_code(422);
    $pageTitle = __('payment.title');
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <section class="section-space container">
        <div class="alert alert-danger" role="alert"><?= e(__($exception->translationKey, $exception->replacements)) ?></div>
        <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('customer/payment.php')) ?>"><?= e(__('payment.back_to_payment')) ?></a>
    </section>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php';
}
