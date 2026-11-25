<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cart_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'checkout_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'storefront.php';
start_secure_session();

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
$customer = null;
$reservationTargets = [];
$orderTargets = [];

if ($customerId !== null) {
    $customerQuery = $pdo->prepare(
        'SELECT id, full_name, phone, is_active FROM users WHERE id = :id LIMIT 1'
    );
    $customerQuery->execute(['id' => $customerId]);
    $customerResult = $customerQuery->fetch();
    if (!is_array($customerResult) || (int) $customerResult['is_active'] !== 1) {
        destroy_secure_session();
        header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
        exit;
    }
    $customer = $customerResult;

    $reservationsQuery = $pdo->prepare(
        "SELECT r.id, r.booking_code, r.start_time, r.end_time, t.table_number
         FROM reservations r
         INNER JOIN `tables` t ON t.id = r.table_id
         WHERE r.user_id = :user_id
           AND r.status = 'Confirmed'
           AND r.reservation_date = CURRENT_DATE()
           AND r.start_time <= TIME(DATE_ADD(NOW(), INTERVAL 15 MINUTE))
           AND r.end_time > CURRENT_TIME()
         ORDER BY r.start_time ASC"
    );
    $reservationsQuery->execute(['user_id' => $customerId]);
    $reservationTargets = $reservationsQuery->fetchAll();

    $ordersQuery = $pdo->prepare(
        "SELECT o.id, o.order_code, o.status, o.created_at
         FROM orders o
         WHERE o.user_id = :user_id
           AND o.order_type = 'dine_in'
           AND o.reservation_id IS NULL
           AND o.status IN ('Pending', 'Processing')
           AND o.created_at >= CURRENT_DATE()
           AND NOT EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.payable_type = 'order'
                 AND p.payable_id = o.id
                 AND p.status IN ('Waiting Verification', 'Verified')
           )
         ORDER BY o.created_at DESC"
    );
    $ordersQuery->execute(['user_id' => $customerId]);
    $orderTargets = $ordersQuery->fetchAll();
}

$cart = cozycafe_cart_get($pdo, $customerId);
$errors = [];
$fullName = $customer !== null ? (string) $customer['full_name'] : '';
$phone = $customer !== null ? (string) $customer['phone'] : '';
$orderType = 'takeaway';
$paymentMethod = 'qris';
$dineInTarget = 'new';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $requestedTarget = $_GET['target'] ?? null;
    if (is_string($requestedTarget) && preg_match('/\A(reservation|order):([1-9][0-9]{0,9})\z/', $requestedTarget, $matches) === 1) {
        $targetId = (int) $matches[2];
        $targetExists = false;
        $targetCollection = $matches[1] === 'reservation' ? $reservationTargets : $orderTargets;
        foreach ($targetCollection as $target) {
            if ((int) $target['id'] === $targetId) {
                $targetExists = true;
                break;
            }
        }
        if ($targetExists) {
            $dineInTarget = $requestedTarget;
            $orderType = 'dine_in';
            $paymentMethod = 'cash';
        }
    }
}
$totals = cozycafe_cart_totals($cart['items']);
$hasUnavailableItems = (int) $cart['invalid_item_count'] > 0;
foreach ($cart['items'] as $item) {
    if ((int) $item['is_available'] !== 1 || (int) $item['stock'] < (int) $item['quantity']) {
        $hasUnavailableItems = true;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $fullName = trim(cozycafe_post_string('full_name'));
    $phoneInput = trim(cozycafe_post_string('phone'));
    $orderType = cozycafe_post_string('order_type');
    $paymentMethod = cozycafe_post_string('payment_method');
    $dineInTarget = cozycafe_post_string('dine_in_target');

    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $nameLength = preg_match_all('/./us', $fullName);
        if (
            $nameLength === false
            || $nameLength < 2
            || $nameLength > 150
            || preg_match('/[\x00-\x1F\x7F]/u', $fullName) === 1
        ) {
            $errors[] = __('error.checkout_name_invalid');
        }
        try {
            $phone = normalize_indonesian_phone($phoneInput);
        } catch (InvalidArgumentException) {
            $phone = $phoneInput;
            $errors[] = __('error.checkout_phone_invalid');
        }

        if (!in_array($orderType, ['dine_in', 'takeaway'], true)) {
            $errors[] = __('error.checkout_invalid_order_type');
        }
        if (!in_array($paymentMethod, ['qris', 'cash'], true)) {
            $errors[] = __('error.checkout_invalid_payment_method');
        }

        if ($errors === []) {
            try {
                $order = cozycafe_place_order(
                    $pdo,
                    $customerId,
                    $fullName,
                    $phone,
                    $orderType,
                    $paymentMethod,
                    $dineInTarget
                );
                $_SESSION['_last_order_lookup'] = [
                    'order_code' => $order['order_code'],
                    'phone' => $phone,
                ];
                $_SESSION['_last_order_created'] = true;
                if ($order['merged']) {
                    $_SESSION['_last_order_merged'] = true;
                }

                header(
                    'Location: ' . cozycafe_app_path('customer/track-order.php?created=1'),
                    true,
                    303
                );
                exit;
            } catch (CozyCafeCheckoutException $exception) {
                http_response_code(422);
                $errors[] = __($exception->translationKey, $exception->replacements);
            } catch (CozyCafeCartException $exception) {
                http_response_code(422);
                $errors[] = __($exception->translationKey, $exception->replacements);
            } catch (CozyCafeOrderException $exception) {
                http_response_code(422);
                $errors[] = __($exception->translationKey);
            }
        }
    }
}

$pageTitle = __('checkout.title');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('checkout.eyebrow')) ?></p>
        <h1><?= e(__('checkout.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('checkout.intro')) ?></p>
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
    <?php if ($cart['items'] === []): ?>
        <div class="empty-state empty-state--large">
            <h2 class="h3"><?= e(__('cart.empty_title')) ?></h2>
            <p><?= e(__('cart.empty_intro')) ?></p>
            <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('storefront.view_menu')) ?></a>
        </div>
    <?php else: ?>
        <form
            method="post"
            action="<?= e(cozycafe_app_path('customer/checkout.php')) ?>"
            class="checkout-form"
            data-checkout-form
            data-takeaway-hint="<?= e(__('checkout.qris_upfront')) ?>"
            data-dine-in-hint="<?= e(__('checkout.dine_in_payment')) ?>"
            data-loading-text="<?= e(__('common.loading')) ?>"
        >
            <?= csrf_field() ?>
            <div class="row g-4 align-items-start">
                <div class="col-12 col-lg-7">
                    <section class="checkout-section">
                        <h2 class="h4"><?= e(__('checkout.contact_section')) ?></h2>
                        <p class="text-secondary"><?= e($customer !== null ? __('checkout.customer_notice') : __('checkout.guest_notice')) ?></p>
                        <div class="mb-3">
                            <label class="form-label" for="full-name"><?= e(__('label.full_name')) ?></label>
                            <input
                                class="form-control"
                                id="full-name"
                                name="full_name"
                                type="text"
                                minlength="2"
                                maxlength="150"
                                autocomplete="name"
                                required
                                value="<?= e($fullName) ?>"
                            >
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="phone"><?= e(__('label.phone')) ?></label>
                            <input
                                class="form-control"
                                id="phone"
                                name="phone"
                                type="tel"
                                maxlength="20"
                                autocomplete="tel"
                                required
                                value="<?= e($phone) ?>"
                            >
                        </div>
                    </section>

                    <section class="checkout-section mt-4">
                        <h2 class="h4"><?= e(__('checkout.order_section')) ?></h2>
                        <fieldset class="mb-4">
                            <legend class="form-label"><?= e(__('checkout.order_type')) ?></legend>
                            <div class="row g-3">
                                <div class="col-12 col-sm-6">
                                    <label class="checkout-choice">
                                        <input type="radio" name="order_type" value="takeaway"<?= $orderType === 'takeaway' ? ' checked' : '' ?>>
                                        <span>
                                            <strong><?= e(__('checkout.takeaway')) ?></strong>
                                            <small><?= e(__('checkout.qris_upfront')) ?></small>
                                        </span>
                                    </label>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="checkout-choice">
                                        <input type="radio" name="order_type" value="dine_in"<?= $orderType === 'dine_in' ? ' checked' : '' ?>>
                                        <span>
                                            <strong><?= e(__('checkout.dine_in')) ?></strong>
                                            <small><?= e(__('checkout.dine_in_payment')) ?></small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="mb-4">
                            <legend class="form-label"><?= e(__('checkout.payment_method')) ?></legend>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check payment-choice" data-payment-choice="qris">
                                    <input class="form-check-input" type="radio" name="payment_method" value="qris"<?= $paymentMethod === 'qris' ? ' checked' : '' ?>>
                                    <span class="form-check-label"><?= e(__('payment.method.qris')) ?></span>
                                </label>
                                <label class="form-check payment-choice" data-payment-choice="cash">
                                    <input class="form-check-input" type="radio" name="payment_method" value="cash"<?= $paymentMethod === 'cash' ? ' checked' : '' ?>>
                                    <span class="form-check-label"><?= e(__('payment.method.cash')) ?></span>
                                </label>
                            </div>
                            <p class="small text-secondary mt-2 mb-0" data-payment-hint></p>
                        </fieldset>

                        <div class="mb-4" data-dine-in-target>
                            <label class="form-label" for="dine-in-target"><?= e(__('checkout.dine_in_target')) ?></label>
                            <select class="form-select" id="dine-in-target" name="dine_in_target">
                                <option value="new"<?= $dineInTarget === 'new' ? ' selected' : '' ?>><?= e(__('checkout.new_visit')) ?></option>
                                <?php foreach ($reservationTargets as $reservation): ?>
                                    <?php $targetValue = 'reservation:' . (int) $reservation['id']; ?>
                                    <option value="<?= e($targetValue) ?>"<?= $dineInTarget === $targetValue ? ' selected' : '' ?>>
                                        <?= e(__('checkout.reservation_option')) ?> ·
                                        <?= e($reservation['booking_code']) ?> ·
                                        <?= e($reservation['table_number']) ?> ·
                                        <?= e(substr((string) $reservation['start_time'], 0, 5)) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php foreach ($orderTargets as $openOrder): ?>
                                    <?php $targetValue = 'order:' . (int) $openOrder['id']; ?>
                                    <option value="<?= e($targetValue) ?>"<?= $dineInTarget === $targetValue ? ' selected' : '' ?>>
                                        <?= e(__('checkout.order_option')) ?> · <?= e($openOrder['order_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($reservationTargets === [] && $orderTargets === []): ?>
                                <p class="small text-secondary mt-2 mb-0"><?= e(__('checkout.no_active_visit')) ?></p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <aside class="col-12 col-lg-5">
                    <section class="checkout-summary-card">
                        <h2 class="h4"><?= e(__('checkout.summary')) ?></h2>
                        <ul class="checkout-item-list">
                            <?php foreach ($cart['items'] as $item): ?>
                                <?php $itemAvailable = (int) $item['is_available'] === 1 && (int) $item['quantity'] <= (int) $item['stock']; ?>
                                <li>
                                    <div class="d-flex justify-content-between gap-3">
                                        <span><?= e($item['name']) ?> × <?= e($item['quantity']) ?></span>
                                        <strong><?= e(format_rupiah((float) $item['price'] * (int) $item['quantity'])) ?></strong>
                                    </div>
                                    <?php if (!$itemAvailable): ?>
                                        <small class="text-danger"><?= e(__('cart.resolve_stock_before_checkout')) ?></small>
                                    <?php endif; ?>
                                    <?php if (isset($item['item_notes']) && is_string($item['item_notes']) && $item['item_notes'] !== ''): ?>
                                        <small class="d-block text-secondary"><?= e($item['item_notes']) ?></small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($cart['order_notes'] !== ''): ?>
                            <p class="checkout-order-note"><strong><?= e(__('cart.order_notes')) ?>:</strong> <?= e($cart['order_notes']) ?></p>
                        <?php endif; ?>
                        <dl class="cart-summary-list">
                            <div><dt><?= e(__('label.subtotal')) ?></dt><dd><?= e(format_rupiah($totals['subtotal'])) ?></dd></div>
                            <div><dt><?= e(__('label.tax')) ?> (10%)</dt><dd><?= e(format_rupiah($totals['tax_amount'])) ?></dd></div>
                            <div class="cart-summary-list__total"><dt><?= e(__('label.total')) ?></dt><dd><?= e(format_rupiah($totals['total_amount'])) ?></dd></div>
                        </dl>
                        <?php if ($hasUnavailableItems): ?>
                            <button class="btn btn-cozy btn-lg w-100" type="button" disabled><?= e(__('checkout.submit')) ?></button>
                            <p class="small text-danger mt-2 mb-0"><?= e(__('cart.resolve_stock_before_checkout')) ?></p>
                        <?php else: ?>
                            <button class="btn btn-cozy btn-lg w-100" type="submit" data-checkout-submit>
                                <?= e(__('checkout.submit')) ?>
                            </button>
                        <?php endif; ?>
                        <a class="btn btn-link w-100 mt-2" href="<?= e(cozycafe_app_path('customer/cart.php')) ?>"><?= e(__('checkout.back_to_cart')) ?></a>
                    </section>
                </aside>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
