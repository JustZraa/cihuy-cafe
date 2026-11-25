<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'order_service.php';

start_secure_session();
require_customer_login();

$pdo = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
if ($customerId === null) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}
$customerQuery = $pdo->prepare('SELECT is_active FROM users WHERE id = :id LIMIT 1');
$customerQuery->execute(['id' => $customerId]);
$customer = $customerQuery->fetch();
if (!is_array($customer) || (int) $customer['is_active'] !== 1) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$orderIdInput = $_GET['id'] ?? null;
if (!is_string($orderIdInput) || preg_match('/\A[1-9][0-9]{0,9}\z/', $orderIdInput) !== 1) {
    http_response_code(404);
    exit(__('error.order_not_found'));
}
$orderId = (int) $orderIdInput;
$errors = [];
$notice = ($_GET['cancelled'] ?? '') === '1' ? __('order.cancel_success') : null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } elseif (cozycafe_post_string('action') !== 'cancel') {
        http_response_code(422);
        $errors[] = __('error.invalid_request');
    } else {
        try {
            cozycafe_cancel_customer_order($pdo, $orderId, $customerId);
            header(
                'Location: ' . cozycafe_app_path('customer/orders/detail.php?id=' . $orderId . '&cancelled=1'),
                true,
                303
            );
            exit;
        } catch (CozyCafeOrderException $exception) {
            http_response_code(422);
            $errors[] = __($exception->translationKey);
        }
    }
}

$orderQuery = $pdo->prepare(
    'SELECT id, order_code, guest_name, guest_phone, order_type, reservation_id, table_id,
            subtotal, tax_amount, total_amount, payment_method, status, order_notes,
            scheduled_prep_at, payment_deadline, cancel_reason, created_at, updated_at
     FROM orders
     WHERE id = :id AND user_id = :user_id
     LIMIT 1'
);
$orderQuery->execute(['id' => $orderId, 'user_id' => $customerId]);
$order = $orderQuery->fetch();
if (!is_array($order)) {
    http_response_code(404);
    exit(__('error.order_not_found'));
}

$itemsQuery = $pdo->prepare(
    'SELECT product_name_snapshot, price_snapshot, quantity, subtotal, item_notes
     FROM order_items
     WHERE order_id = :order_id
     ORDER BY id ASC'
);
$itemsQuery->execute(['order_id' => $orderId]);
$items = $itemsQuery->fetchAll();

$paymentQuery = $pdo->prepare(
    'SELECT payment_code, status, amount, expires_at, rejection_reason, verified_at
     FROM payments
     WHERE payable_type = \'order\' AND payable_id = :order_id
     ORDER BY id DESC
     LIMIT 1'
);
$paymentQuery->execute(['order_id' => $orderId]);
$paymentResult = $paymentQuery->fetch();
$payment = is_array($paymentResult) ? $paymentResult : null;

$historyQuery = $pdo->prepare(
    'SELECT old_status, new_status, changed_by_type, notes, created_at
     FROM order_status_logs
     WHERE order_id = :order_id
     ORDER BY created_at ASC, id ASC'
);
$historyQuery->execute(['order_id' => $orderId]);
$history = $historyQuery->fetchAll();

$statusKeys = [
    'Pending' => 'status.order.pending',
    'Waiting for Payment' => 'status.order.waiting_for_payment',
    'Paid' => 'status.order.paid',
    'Processing' => 'status.order.processing',
    'Ready' => 'status.order.ready',
    'Completed' => 'status.order.completed',
    'Cancelled' => 'status.order.cancelled',
];
$paymentStatusKeys = [
    'Pending' => 'status.payment.pending',
    'Waiting Verification' => 'status.payment.waiting_verification',
    'Verified' => 'status.payment.verified',
    'Rejected' => 'status.payment.rejected',
    'Expired' => 'status.payment.expired',
    'Refunded' => 'status.payment.refunded',
];
$currentStatus = (string) $order['status'];
$statusKey = $statusKeys[$currentStatus] ?? 'label.status';
$paymentStatusKey = is_array($payment)
    ? ($paymentStatusKeys[(string) $payment['status']] ?? 'label.payment_status')
    : null;
$canCancel = in_array($currentStatus, ['Pending', 'Waiting for Payment'], true)
    && (!is_array($payment) || !in_array($payment['status'], ['Waiting Verification', 'Verified'], true));
$statusSteps = [
    'Pending',
    'Waiting for Payment',
    'Paid',
    'Processing',
    'Ready',
    'Completed',
];
$currentStep = array_search($currentStatus, $statusSteps, true);
$showPaymentAction = $order['order_type'] === 'takeaway'
    && $currentStatus === 'Waiting for Payment'
    && is_array($payment)
    && in_array($payment['status'], ['Pending', 'Rejected'], true);
$reservationLink = $order['reservation_id'] === null
    ? null
    : cozycafe_app_path('customer/reservations/detail.php?id=' . (int) $order['reservation_id']);

$pageTitle = __('order.detail_title');
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('orders.title')) ?></p>
        <h1><?= e(__('order.detail_title')) ?></h1>
        <p class="page-hero__copy"><?= e($order['order_code']) ?> · <?= e(format_wib((string) $order['created_at'])) ?></p>
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
        <div class="col-12 col-lg-7">
            <section class="order-detail-card">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <p class="eyebrow mb-1"><?= e(__('label.order_code')) ?></p>
                        <h2 class="h3"><?= e($order['order_code']) ?></h2>
                    </div>
                    <span class="badge rounded-pill text-bg-primary"><?= e(__($statusKey)) ?></span>
                </div>
                <?php if ($currentStatus === 'Cancelled'): ?>
                    <div class="alert alert-warning mt-3" role="status">
                        <?= e(is_string($order['cancel_reason']) && $order['cancel_reason'] !== '' ? $order['cancel_reason'] : __('order.cancelled_notice')) ?>
                    </div>
                <?php else: ?>
                    <h3 class="h6 mt-4"><?= e(__('order.status_progress')) ?></h3>
                    <ol class="order-status-stepper" aria-label="<?= e(__('order.status_progress')) ?>">
                        <?php foreach ($statusSteps as $index => $step): ?>
                            <?php
                            $stepKey = $statusKeys[$step];
                            $stepState = is_int($currentStep) && $index < $currentStep
                                ? 'is-complete'
                                : (is_int($currentStep) && $index === $currentStep ? 'is-current' : '');
                            ?>
                            <li class="<?= e($stepState) ?>"<?= $stepState === 'is-current' ? ' aria-current="step"' : '' ?>>
                                <span><?= e((string) ($index + 1)) ?></span>
                                <small><?= e(__($stepKey)) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <dl class="order-detail-meta mt-4">
                    <div><dt><?= e(__('label.order_type')) ?></dt><dd><?= e($order['order_type'] === 'dine_in' ? __('checkout.dine_in') : __('checkout.takeaway')) ?></dd></div>
                    <div><dt><?= e(__('checkout.payment_method')) ?></dt><dd><?= e($order['payment_method'] === 'qris' ? __('payment.method.qris') : __('payment.method.cash')) ?></dd></div>
                    <?php if ($paymentStatusKey !== null): ?>
                        <div><dt><?= e(__('label.payment_status')) ?></dt><dd><?= e(__($paymentStatusKey)) ?></dd></div>
                    <?php endif; ?>
                    <?php if (is_string($order['scheduled_prep_at'])): ?>
                        <div><dt><?= e(__('order.preparation_due')) ?></dt><dd><?= e(format_wib($order['scheduled_prep_at'])) ?></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if ($reservationLink !== null): ?>
                    <a class="text-link" href="<?= e($reservationLink) ?>"><?= e(__('order.reservation_link')) ?></a>
                <?php endif; ?>
                <?php if ($showPaymentAction): ?>
                    <form method="post" action="<?= e(cozycafe_app_path('customer/payment.php')) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_code" value="<?= e($order['order_code']) ?>">
                        <input type="hidden" name="phone" value="<?= e($order['guest_phone']) ?>">
                        <button class="btn btn-cozy" type="submit">
                            <?= e($payment['status'] === 'Rejected' ? __('payment.view_status') : __('payment.pay_and_upload')) ?>
                        </button>
                    </form>
                <?php elseif (is_array($payment) && $payment['status'] === 'Waiting Verification'): ?>
                    <div class="alert alert-info mt-3 mb-0" role="status"><?= e(__('payment.waiting_verification')) ?></div>
                <?php endif; ?>
                <?php if ($canCancel): ?>
                    <form
                        method="post"
                        action="<?= e(cozycafe_app_path('customer/orders/detail.php?id=' . $orderId)) ?>"
                        class="mt-3"
                        data-confirm="<?= e(__('order.cancel_confirm')) ?>"
                    >
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button class="btn btn-outline-danger" type="submit"><?= e(__('order.cancel_action')) ?></button>
                    </form>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-12 col-lg-5">
            <section class="order-detail-card">
                <h2 class="h4"><?= e(__('order.items')) ?></h2>
                <ul class="track-item-list">
                    <?php foreach ($items as $item): ?>
                        <li>
                            <span>
                                <?= e($item['product_name_snapshot']) ?> × <?= e($item['quantity']) ?>
                                <small class="d-block text-secondary"><?= e(format_rupiah((string) $item['price_snapshot'])) ?></small>
                                <?php if (is_string($item['item_notes']) && $item['item_notes'] !== ''): ?>
                                    <small class="d-block text-secondary"><?= e($item['item_notes']) ?></small>
                                <?php endif; ?>
                            </span>
                            <strong><?= e(format_rupiah((string) $item['subtotal'])) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (is_string($order['order_notes']) && $order['order_notes'] !== ''): ?>
                    <p class="checkout-order-note"><strong><?= e(__('cart.order_notes')) ?>:</strong> <?= e($order['order_notes']) ?></p>
                <?php endif; ?>
                <dl class="cart-summary-list mt-3">
                    <div><dt><?= e(__('label.subtotal')) ?></dt><dd><?= e(format_rupiah((string) $order['subtotal'])) ?></dd></div>
                    <div><dt><?= e(__('label.tax')) ?> (10%)</dt><dd><?= e(format_rupiah((string) $order['tax_amount'])) ?></dd></div>
                    <div class="cart-summary-list__total"><dt><?= e(__('label.total')) ?></dt><dd><?= e(format_rupiah((string) $order['total_amount'])) ?></dd></div>
                </dl>
            </section>
        </div>
    </div>

    <section class="order-detail-card mt-4">
        <h2 class="h4"><?= e(__('order.status_history')) ?></h2>
        <?php if ($history === []): ?>
            <p class="mb-0 text-secondary"><?= e(__('order.no_status_history')) ?></p>
        <?php else: ?>
            <ol class="order-history-list">
                <?php foreach ($history as $entry): ?>
                    <?php $newStatusKey = $statusKeys[(string) $entry['new_status']] ?? 'label.status'; ?>
                    <li>
                        <strong><?= e(__($newStatusKey)) ?></strong>
                        <time datetime="<?= e((string) $entry['created_at']) ?>"><?= e(format_wib((string) $entry['created_at'])) ?></time>
                        <?php if (is_string($entry['notes']) && $entry['notes'] !== ''): ?>
                            <p><?= e($entry['notes']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <a class="text-link d-inline-block mt-3" href="<?= e(cozycafe_app_path('customer/orders/index.php')) ?>"><?= e(__('orders.title')) ?></a>
</section>
<script src="<?= e(cozycafe_app_path('assets/js/reservation-detail.js')) ?>" defer></script>
<?php require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
