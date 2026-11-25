<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cart_service.php';

start_secure_session();
require_customer_login();

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
if ($customerId === null) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$customerQuery = $pdo->prepare('SELECT full_name, is_active FROM users WHERE id = :id LIMIT 1');
$customerQuery->execute(['id' => $customerId]);
$customer = $customerQuery->fetch();
if (!is_array($customer) || (int) $customer['is_active'] !== 1) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$ordersQuery = $pdo->prepare(
    "SELECT id, order_code, order_type, status, total_amount, created_at, scheduled_prep_at
     FROM orders
     WHERE user_id = :user_id
       AND status IN ('Pending', 'Waiting for Payment', 'Paid', 'Processing', 'Ready')
     ORDER BY created_at DESC
     LIMIT 6"
);
$ordersQuery->execute(['user_id' => $customerId]);
$activeOrders = $ordersQuery->fetchAll();

$reservationsQuery = $pdo->prepare(
    "SELECT r.id, r.booking_code, r.reservation_date, r.start_time, r.end_time,
            r.guest_count, r.status, t.table_number, t.location_area,
            (SELECT p.status
             FROM payments p
             WHERE p.payable_type = 'reservation_dp'
               AND p.payable_id = r.id
             ORDER BY p.id DESC
             LIMIT 1) AS dp_status,
            (r.reservation_date = CURRENT_DATE()
             AND r.start_time <= TIME(DATE_ADD(NOW(), INTERVAL 15 MINUTE))
             AND r.end_time > CURRENT_TIME()) AS visit_active
     FROM reservations r
     INNER JOIN `tables` t ON t.id = r.table_id
     WHERE r.user_id = :user_id
       AND r.status IN ('Waiting for DP', 'Confirmed')
       AND r.reservation_date >= CURRENT_DATE()
     ORDER BY r.reservation_date ASC, r.start_time ASC
     LIMIT 6"
);
$reservationsQuery->execute(['user_id' => $customerId]);
$upcomingReservations = $reservationsQuery->fetchAll();

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
$paymentStatusKeys = [
    'Pending' => 'status.payment.pending',
    'Waiting Verification' => 'status.payment.waiting_verification',
    'Verified' => 'status.payment.verified',
    'Rejected' => 'status.payment.rejected',
    'Expired' => 'status.payment.expired',
    'Refunded' => 'status.payment.refunded',
];
$orderSteps = [
    'Pending',
    'Waiting for Payment',
    'Paid',
    'Processing',
    'Ready',
    'Completed',
];

$pageTitle = __('dashboard.title');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('dashboard.eyebrow')) ?></p>
        <h1><?= e(__('dashboard.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('dashboard.intro')) ?> · <?= e($customer['full_name']) ?></p>
    </div>
</section>

<section class="section-space container">
    <div class="dashboard-shortcuts mb-4">
        <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('dashboard.view_menu')) ?></a>
        <a class="btn btn-outline-cozy" href="<?= e(cozycafe_app_path('customer/reservations/index.php')) ?>"><?= e(__('dashboard.book_table')) ?></a>
        <a class="btn btn-outline-cozy" href="<?= e(cozycafe_app_path('customer/orders/index.php')) ?>"><?= e(__('dashboard.view_orders')) ?></a>
    </div>

    <div class="row g-4 align-items-start">
        <div class="col-12 col-lg-7">
            <section class="dashboard-panel">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h4 mb-0"><?= e(__('dashboard.active_orders')) ?></h2>
                    <a class="text-link" href="<?= e(cozycafe_app_path('customer/orders/index.php')) ?>"><?= e(__('dashboard.view_orders')) ?></a>
                </div>
                <?php if ($activeOrders === []): ?>
                    <p class="mb-0 text-secondary"><?= e(__('dashboard.no_active_orders')) ?></p>
                <?php else: ?>
                    <div class="dashboard-card-list">
                        <?php foreach ($activeOrders as $order): ?>
                            <?php
                            $statusKey = $orderStatusKeys[(string) $order['status']] ?? 'label.status';
                            $currentStep = array_search((string) $order['status'], $orderSteps, true);
                            $isOpenDineIn = $order['order_type'] === 'dine_in'
                                && in_array($order['status'], ['Pending', 'Processing'], true);
                            ?>
                            <article class="dashboard-list-card">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <a class="dashboard-card-title" href="<?= e(cozycafe_app_path('customer/orders/detail.php?id=' . (int) $order['id'])) ?>">
                                            <?= e($order['order_code']) ?>
                                        </a>
                                        <p class="small text-secondary mb-0"><?= e(format_wib((string) $order['created_at'])) ?></p>
                                    </div>
                                    <span class="badge rounded-pill text-bg-primary align-self-start"><?= e(__($statusKey)) ?></span>
                                </div>
                                <ol class="order-status-stepper order-status-stepper--compact mt-3" aria-label="<?= e(__('order.status_progress')) ?>">
                                    <?php foreach ($orderSteps as $index => $step): ?>
                                        <?php
                                        $stepState = is_int($currentStep) && $index < $currentStep
                                            ? 'is-complete'
                                            : (is_int($currentStep) && $index === $currentStep ? 'is-current' : '');
                                        ?>
                                        <li class="<?= e($stepState) ?>"<?= $stepState === 'is-current' ? ' aria-current="step"' : '' ?>>
                                            <span><?= e((string) ($index + 1)) ?></span>
                                            <small><?= e(__($orderStatusKeys[$step])) ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                                    <strong><?= e(format_rupiah((string) $order['total_amount'])) ?></strong>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if ($isOpenDineIn): ?>
                                            <a class="btn btn-sm btn-outline-cozy" href="<?= e(cozycafe_app_path('customer/checkout.php?target=order:' . (int) $order['id'])) ?>">
                                                <?= e(__('dashboard.add_to_visit')) ?>
                                            </a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-cozy" href="<?= e(cozycafe_app_path('customer/orders/detail.php?id=' . (int) $order['id'])) ?>">
                                            <?= e(__('orders.view_detail')) ?>
                                        </a>
                                    </div>
                                </div>
                                <?php if (is_string($order['scheduled_prep_at'])): ?>
                                    <p class="small text-secondary mt-2 mb-0"><?= e(__('order.preparation_due')) ?>: <?= e(format_wib($order['scheduled_prep_at'])) ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-12 col-lg-5">
            <section class="dashboard-panel">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h4 mb-0"><?= e(__('dashboard.upcoming_reservations')) ?></h2>
                    <a class="text-link" href="<?= e(cozycafe_app_path('customer/reservations/index.php')) ?>"><?= e(__('dashboard.book_table')) ?></a>
                </div>
                <?php if ($upcomingReservations === []): ?>
                    <p class="mb-0 text-secondary"><?= e(__('dashboard.no_upcoming_reservations')) ?></p>
                <?php else: ?>
                    <div class="dashboard-card-list">
                        <?php foreach ($upcomingReservations as $reservation): ?>
                            <?php $statusKey = $reservationStatusKeys[(string) $reservation['status']] ?? 'label.status'; ?>
                            <?php $dpStatusKey = isset($reservation['dp_status']) ? ($paymentStatusKeys[(string) $reservation['dp_status']] ?? 'label.payment_status') : null; ?>
                            <article class="dashboard-list-card">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <a class="dashboard-card-title" href="<?= e(cozycafe_app_path('customer/reservations/detail.php?id=' . (int) $reservation['id'])) ?>">
                                            <?= e($reservation['booking_code']) ?>
                                        </a>
                                        <p class="small text-secondary mb-0">
                                            <?= e(format_wib((string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'])) ?>
                                            · <?= e($reservation['table_number']) ?>
                                        </p>
                                    </div>
                                    <span class="badge rounded-pill text-bg-primary align-self-start"><?= e(__($statusKey)) ?></span>
                                </div>
                                <?php if ($dpStatusKey !== null): ?>
                                    <p class="small text-secondary mt-2 mb-0"><?= e(__('label.dp')) ?> · <?= e(__($dpStatusKey)) ?></p>
                                <?php endif; ?>
                                <?php if ($reservation['status'] === 'Confirmed' && (int) $reservation['visit_active'] === 1): ?>
                                    <a class="btn btn-sm btn-outline-cozy mt-3" href="<?= e(cozycafe_app_path('customer/checkout.php?target=reservation:' . (int) $reservation['id'])) ?>">
                                        <?= e(__('dashboard.add_to_visit')) ?>
                                    </a>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
