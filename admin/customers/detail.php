<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$customerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($customerId) || $customerId < 1) {
    http_response_code(404);
    exit(e(__('admin.error.not_found')));
}
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    if (cozycafe_post_string('action') !== 'toggle_active') {
        $errors[] = __('admin.error.invalid_action');
    } else {
        try {
            admin_transaction($pdo, static function () use ($pdo, $adminId, $customerId): void {
                $query = $pdo->prepare('SELECT id, full_name, is_active FROM users WHERE id = :id FOR UPDATE');
                $query->execute(['id' => $customerId]);
                $customer = $query->fetch();
                if (!is_array($customer)) {
                    throw new InvalidArgumentException(__('admin.error.not_found'));
                }
                $newStatus = (int) $customer['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id')
                    ->execute(['active' => $newStatus, 'id' => $customerId]);
                log_admin_action(
                    $pdo,
                    $adminId,
                    $newStatus === 1 ? 'customer_activated' : 'customer_deactivated',
                    'customer',
                    $customerId,
                    'Customer ' . $customer['full_name'] . ' was ' . ($newStatus === 1 ? 'activated.' : 'deactivated.')
                );
                create_notification(
                    $pdo,
                    'customer',
                    $customerId,
                    __('admin.message.customer_updated'),
                    $newStatus === 1 ? __('admin.notification.customer_activated') : __('admin.notification.customer_deactivated'),
                    cozycafe_app_path('customer/dashboard.php')
                );
            });
            admin_redirect('customers/detail.php?id=' . $customerId, 'admin.message.customer_updated');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$customerQuery = $pdo->prepare('SELECT id, full_name, email, phone, is_active, created_at, updated_at FROM users WHERE id = :id LIMIT 1');
$customerQuery->execute(['id' => $customerId]);
$customer = $customerQuery->fetch();
if (!is_array($customer)) {
    http_response_code(404);
    exit(e(__('admin.error.not_found')));
}
$ordersQuery = $pdo->prepare(
    'SELECT id, order_code, order_type, status, total_amount, created_at
     FROM orders WHERE user_id = :id ORDER BY created_at DESC, id DESC LIMIT 20'
);
$ordersQuery->execute(['id' => $customerId]);
$orders = $ordersQuery->fetchAll();
$reservationsQuery = $pdo->prepare(
    'SELECT r.id, r.booking_code, r.reservation_date, r.start_time, r.status, r.dp_refund_status, t.table_number
     FROM reservations r INNER JOIN `tables` t ON t.id = r.table_id
     WHERE r.user_id = :id ORDER BY r.reservation_date DESC, r.start_time DESC LIMIT 20'
);
$reservationsQuery->execute(['id' => $customerId]);
$reservations = $reservationsQuery->fetchAll();

admin_layout_start($pdo, __('admin.page.customer_detail'), 'customers');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <div class="admin-section-heading">
        <div><p class="eyebrow mb-1">#<?= e((int) $customer['id']) ?></p><h2 class="h4 mb-0"><?= e($customer['full_name']) ?></h2></div>
        <form method="post" action="<?= e(admin_url('customers/detail.php?id=' . $customerId)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active">
            <button class="btn <?= (int) $customer['is_active'] === 1 ? 'btn-outline-danger' : 'btn-cozy' ?>" type="submit">
                <?= e((int) $customer['is_active'] === 1 ? __('admin.action.customer_disabled') : __('admin.action.customer_enabled')) ?>
            </button>
        </form>
    </div>
    <dl class="row mb-0">
        <dt class="col-sm-3"><?= e(__('label.email')) ?></dt><dd class="col-sm-9"><?= e($customer['email']) ?></dd>
        <dt class="col-sm-3"><?= e(__('label.phone')) ?></dt><dd class="col-sm-9"><?= e($customer['phone']) ?></dd>
        <dt class="col-sm-3"><?= e(__('label.status')) ?></dt><dd class="col-sm-9"><?= e((int) $customer['is_active'] === 1 ? __('admin.label.status_active') : __('admin.label.status_inactive')) ?></dd>
        <dt class="col-sm-3"><?= e(__('admin.table.created')) ?></dt><dd class="col-sm-9"><?= e(format_wib((string) $customer['created_at'])) ?></dd>
    </dl>
</section>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4 mb-3"><?= e(__('admin.customer_orders')) ?></h2>
    <?php if ($orders === []): ?><p class="mb-0 text-secondary"><?= e(__('profile.no_orders')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy"><thead><tr><th><?= e(__('label.order_code')) ?></th><th><?= e(__('label.date')) ?></th><th><?= e(__('label.status')) ?></th><th><?= e(__('label.total')) ?></th></tr></thead><tbody>
        <?php foreach ($orders as $order): ?><tr><td><a href="<?= e(admin_url('orders/detail.php?id=' . (int) $order['id'])) ?>"><?= e($order['order_code']) ?></a></td><td><?= e(format_wib((string) $order['created_at'])) ?></td><td><?= admin_status_badge((string) $order['status']) ?></td><td><?= e(format_rupiah((string) $order['total_amount'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.customer_reservations')) ?></h2>
    <?php if ($reservations === []): ?><p class="mb-0 text-secondary"><?= e(__('profile.no_reservations')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy"><thead><tr><th><?= e(__('label.booking_code')) ?></th><th><?= e(__('label.table')) ?></th><th><?= e(__('admin.table.booking_time')) ?></th><th><?= e(__('label.status')) ?></th><th><?= e(__('admin.table.refund_status')) ?></th></tr></thead><tbody>
        <?php foreach ($reservations as $reservation): ?><tr><td><?= e($reservation['booking_code']) ?></td><td><?= e($reservation['table_number']) ?></td><td><?= e(format_wib((string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'])) ?></td><td><?= admin_status_badge((string) $reservation['status'], 'reservation') ?></td><td><?= e(__('admin.label.' . $reservation['dp_refund_status'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
