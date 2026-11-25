<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cart_service.php';

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

$statement = $pdo->prepare(
    'SELECT id, order_code, order_type, status, total_amount, created_at
     FROM orders
     WHERE user_id = :user_id
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$statement->execute(['user_id' => $customerId]);
$orders = $statement->fetchAll();
$statusKeys = [
    'Pending' => 'status.order.pending',
    'Waiting for Payment' => 'status.order.waiting_for_payment',
    'Paid' => 'status.order.paid',
    'Processing' => 'status.order.processing',
    'Ready' => 'status.order.ready',
    'Completed' => 'status.order.completed',
    'Cancelled' => 'status.order.cancelled',
];

$pageTitle = __('orders.title');
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('dashboard.eyebrow')) ?></p>
        <h1><?= e(__('orders.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('orders.intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <?php if ($orders === []): ?>
        <div class="empty-state empty-state--large">
            <h2 class="h3"><?= e(__('orders.empty_title')) ?></h2>
            <p><?= e(__('orders.empty_intro')) ?></p>
            <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('dashboard.view_menu')) ?></a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-cozy align-middle">
                <thead>
                    <tr>
                        <th scope="col"><?= e(__('label.order_code')) ?></th>
                        <th scope="col"><?= e(__('label.date')) ?></th>
                        <th scope="col"><?= e(__('label.order_type')) ?></th>
                        <th scope="col"><?= e(__('label.total')) ?></th>
                        <th scope="col"><?= e(__('label.status')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(__('orders.view_detail')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php $statusKey = $statusKeys[(string) $order['status']] ?? 'label.status'; ?>
                        <tr>
                            <td><?= e($order['order_code']) ?></td>
                            <td><?= e(format_wib((string) $order['created_at'])) ?></td>
                            <td><?= e($order['order_type'] === 'dine_in' ? __('checkout.dine_in') : __('checkout.takeaway')) ?></td>
                            <td><?= e(format_rupiah((string) $order['total_amount'])) ?></td>
                            <td><span class="badge rounded-pill text-bg-primary"><?= e(__($statusKey)) ?></span></td>
                            <td>
                                <a class="btn btn-sm btn-outline-cozy" href="<?= e(cozycafe_app_path('customer/orders/detail.php?id=' . (int) $order['id'])) ?>">
                                    <?= e(__('orders.view_detail')) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($orders) === 100): ?>
            <p class="small text-secondary"><?= e(__('orders.limit_notice')) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
