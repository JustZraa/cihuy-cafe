<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'payment_service.php';
start_secure_session();

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$errors = [];
$order = null;
$items = [];
$payment = null;
$orderCode = '';
$phone = '';
$createdNotice = false;
$mergedNotice = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $orderCode = strtoupper(trim(cozycafe_post_string('order_code')));
    $phoneInput = trim(cozycafe_post_string('phone'));
    $phone = $phoneInput;
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } elseif (preg_match('/\A[A-Z0-9-]{6,40}\z/', $orderCode) !== 1) {
        $errors[] = __('validation.track_order_code');
    } else {
        try {
            $phone = normalize_indonesian_phone($phoneInput);
        } catch (InvalidArgumentException) {
            $errors[] = __('error.checkout_phone_invalid');
        }
    }
} elseif (
    ($_GET['created'] ?? '') === '1'
    && isset($_SESSION['_last_order_lookup'])
    && is_array($_SESSION['_last_order_lookup'])
) {
    $lookup = $_SESSION['_last_order_lookup'];
    $orderCode = isset($lookup['order_code']) && is_string($lookup['order_code'])
        ? $lookup['order_code']
        : '';
    $phone = isset($lookup['phone']) && is_string($lookup['phone'])
        ? $lookup['phone']
        : '';
    $createdNotice = ($_SESSION['_last_order_created'] ?? false) === true;
    $mergedNotice = ($_SESSION['_last_order_merged'] ?? false) === true;
    unset($_SESSION['_last_order_lookup'], $_SESSION['_last_order_created'], $_SESSION['_last_order_merged']);
}

if ($errors === [] && $orderCode !== '' && $phone !== '') {
    $orderQuery = $pdo->prepare(
        'SELECT o.id, o.order_code, o.guest_name, o.guest_phone, o.order_type,
                o.subtotal, o.tax_amount, o.total_amount, o.payment_method,
                o.status, o.created_at, o.payment_deadline
         FROM orders o
         WHERE o.order_code = :order_code
           AND o.guest_phone = :phone
         LIMIT 1'
    );
    $orderQuery->execute(['order_code' => $orderCode, 'phone' => $phone]);
    $result = $orderQuery->fetch();
    if (is_array($result)) {
        $order = $result;
        $itemsQuery = $pdo->prepare(
            'SELECT product_name_snapshot, price_snapshot, quantity, subtotal, item_notes
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $itemsQuery->execute(['order_id' => (int) $order['id']]);
        $items = $itemsQuery->fetchAll();

        $paymentQuery = $pdo->prepare(
            "SELECT payment_code, status, amount, expires_at
             FROM payments
             WHERE payable_type = 'order'
               AND payable_id = :order_id
             ORDER BY id DESC
             LIMIT 1"
        );
        $paymentQuery->execute(['order_id' => (int) $order['id']]);
        $paymentResult = $paymentQuery->fetch();
        $payment = is_array($paymentResult) ? $paymentResult : null;
    } else {
        $errors[] = __('track.not_found');
    }
}

$orderStatusKeys = [
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
$pageTitle = __('track.title');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('storefront.menu_eyebrow')) ?></p>
        <h1><?= e(__('track.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('track.intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <?php if ($createdNotice): ?>
        <div class="alert alert-success" role="status"><?= e(__('track.new_order')) ?></div>
    <?php endif; ?>
    <?php if ($mergedNotice): ?>
        <div class="alert alert-info" role="status"><?= e(__('checkout.merged_notice')) ?></div>
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
            <section class="track-form-card">
                <h2 class="h4"><?= e(__('track.lookup')) ?></h2>
                <form method="post" action="<?= e(cozycafe_app_path('customer/track-order.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="order-code"><?= e(__('label.order_code')) ?></label>
                        <input
                            class="form-control text-uppercase"
                            id="order-code"
                            name="order_code"
                            type="text"
                            maxlength="40"
                            autocomplete="off"
                            required
                            value="<?= e($orderCode) ?>"
                        >
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="track-phone"><?= e(__('label.phone')) ?></label>
                        <input
                            class="form-control"
                            id="track-phone"
                            name="phone"
                            type="tel"
                            maxlength="20"
                            autocomplete="tel"
                            required
                            value="<?= e($phone) ?>"
                        >
                    </div>
                    <button class="btn btn-cozy w-100" type="submit"><?= e(__('track.lookup')) ?></button>
                </form>
            </section>
        </div>

        <?php if (is_array($order)): ?>
            <?php
            $orderStatus = (string) $order['status'];
            $orderStatusKey = $orderStatusKeys[$orderStatus] ?? 'label.status';
            $paymentStatus = is_array($payment) ? (string) $payment['status'] : '';
            $paymentStatusKey = $paymentStatusKeys[$paymentStatus] ?? 'label.payment_status';
            ?>
            <div class="col-12 col-lg-7">
                <section class="track-order-card">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <p class="eyebrow mb-1"><?= e(__('track.order_details')) ?></p>
                            <h2 class="h3 mb-1"><?= e($order['order_code']) ?></h2>
                            <p class="text-secondary mb-0"><?= e(__('track.created_at')) ?>: <?= e(format_wib((string) $order['created_at'])) ?></p>
                        </div>
                        <span class="badge rounded-pill text-bg-primary"><?= e(__($orderStatusKey)) ?></span>
                    </div>
                    <div class="track-order-meta mt-4">
                        <div><span><?= e(__('label.full_name')) ?></span><strong><?= e($order['guest_name']) ?></strong></div>
                        <div><span><?= e(__('label.order_type')) ?></span><strong><?= e($order['order_type'] === 'dine_in' ? __('checkout.dine_in') : __('checkout.takeaway')) ?></strong></div>
                        <div><span><?= e(__('checkout.payment_method')) ?></span><strong><?= e($order['payment_method'] === 'qris' ? __('payment.method.qris') : __('payment.method.cash')) ?></strong></div>
                        <?php if (is_array($payment)): ?>
                            <div><span><?= e(__('label.payment_status')) ?></span><strong><?= e(__($paymentStatusKey)) ?></strong></div>
                        <?php endif; ?>
                    </div>
                    <h3 class="h5 mt-4"><?= e(__('track.items')) ?></h3>
                    <ul class="track-item-list">
                        <?php foreach ($items as $item): ?>
                            <li>
                                <span>
                                    <?= e($item['product_name_snapshot']) ?> × <?= e($item['quantity']) ?>
                                    <?php if (isset($item['item_notes']) && is_string($item['item_notes']) && $item['item_notes'] !== ''): ?>
                                        <small class="d-block text-secondary"><?= e($item['item_notes']) ?></small>
                                    <?php endif; ?>
                                </span>
                                <strong><?= e(format_rupiah((string) $item['subtotal'])) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <dl class="cart-summary-list mt-3">
                        <div><dt><?= e(__('label.subtotal')) ?></dt><dd><?= e(format_rupiah((string) $order['subtotal'])) ?></dd></div>
                        <div><dt><?= e(__('label.tax')) ?> (10%)</dt><dd><?= e(format_rupiah((string) $order['tax_amount'])) ?></dd></div>
                        <div class="cart-summary-list__total"><dt><?= e(__('label.total')) ?></dt><dd><?= e(format_rupiah((string) $order['total_amount'])) ?></dd></div>
                    </dl>
                    <?php if ($order['order_type'] === 'takeaway' && $orderStatus === 'Waiting for Payment'): ?>
                        <div class="alert alert-warning mb-0" role="status"><?= e(__('track.takeaway_payment_notice')) ?></div>
                        <?php if (is_array($payment) && in_array($paymentStatus, ['Pending', 'Waiting Verification', 'Rejected'], true)): ?>
                            <form method="post" action="<?= e(cozycafe_app_path('customer/payment.php')) ?>" class="mt-3">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_code" value="<?= e($order['order_code']) ?>">
                                <input type="hidden" name="phone" value="<?= e($phone) ?>">
                                <button class="btn btn-cozy" type="submit">
                                    <?= e($paymentStatus === 'Pending' ? __('payment.pay_and_upload') : __('payment.view_status')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($order['order_type'] === 'dine_in'): ?>
                        <div class="alert alert-info mb-0" role="status"><?= e(__('track.dine_in_payment_notice')) ?></div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
    <div class="mt-4"><a class="text-link" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('storefront.view_menu')) ?></a></div>
</section>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
