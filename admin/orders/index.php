<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$typeFilter = cozycafe_post_string('type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $typeFilter = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
}
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$orderTypes = ['dine_in', 'takeaway'];
$orderStatuses = ['Pending', 'Waiting for Payment', 'Paid', 'Processing', 'Ready', 'Completed', 'Cancelled'];
if (!in_array($typeFilter, $orderTypes, true)) {
    $typeFilter = '';
}
if (!in_array($statusFilter, $orderStatuses, true)) {
    $statusFilter = '';
}

$conditions = [];
$parameters = [];
if ($typeFilter !== '') {
    $conditions[] = 'o.order_type = :order_type';
    $parameters['order_type'] = $typeFilter;
}
if ($statusFilter !== '') {
    $conditions[] = 'o.status = :status';
    $parameters['status'] = $statusFilter;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$statement = $pdo->prepare(
    "SELECT o.id, o.order_code, o.order_type, o.status, o.payment_method,
            o.total_amount, o.created_at, COALESCE(u.full_name, o.guest_name) AS customer_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     {$where}
     ORDER BY o.created_at DESC, o.id DESC
     LIMIT 200"
);
$statement->execute($parameters);
$orders = $statement->fetchAll();

admin_layout_start($pdo, __('admin.page.orders'), 'orders');
?>
<section class="admin-panel card-cozy">
    <form method="get" action="<?= e(admin_url('orders/index.php')) ?>" class="row g-3 align-items-end mb-4">
        <div class="col-12 col-md-4"><label class="form-label" for="order-type"><?= e(__('admin.filter.type')) ?></label>
            <select class="form-select" id="order-type" name="type">
                <option value=""><?= e(__('admin.filter.all')) ?></option>
                <option value="dine_in" <?= $typeFilter === 'dine_in' ? 'selected' : '' ?>><?= e(__('order.type.dine_in')) ?></option>
                <option value="takeaway" <?= $typeFilter === 'takeaway' ? 'selected' : '' ?>><?= e(__('order.type.takeaway')) ?></option>
            </select>
        </div>
        <div class="col-12 col-md-4"><label class="form-label" for="order-status"><?= e(__('admin.filter.status')) ?></label>
            <select class="form-select" id="order-status" name="status"><option value=""><?= e(__('admin.filter.all')) ?></option>
                <?php foreach ($orderStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(admin_status_label($status, 'order')) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4"><button class="btn btn-cozy" type="submit"><?= e(__('button.submit')) ?></button></div>
    </form>
    <?php if ($orders === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('admin.empty.orders')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr>
                <th><?= e(__('label.order_code')) ?></th><th><?= e(__('admin.table.customer')) ?></th>
                <th><?= e(__('label.order_type')) ?></th><th><?= e(__('label.status')) ?></th>
                <th><?= e(__('admin.table.payment_method')) ?></th><th><?= e(__('admin.table.total')) ?></th>
                <th><?= e(__('admin.table.created')) ?></th>
            </tr></thead>
            <tbody><?php foreach ($orders as $order): ?>
                <tr>
                    <td><a href="<?= e(admin_url('orders/detail.php?id=' . (int) $order['id'])) ?>"><strong><?= e($order['order_code']) ?></strong></a></td>
                    <td><?= e($order['customer_name'] ?? __('admin.guest')) ?></td>
                    <td><?= e($order['order_type'] === 'dine_in' ? __('order.type.dine_in') : __('order.type.takeaway')) ?></td>
                    <td><?= admin_status_badge((string) $order['status']) ?></td>
                    <td><?= e($order['payment_method'] === 'cash' ? __('payment.method.cash') : __('payment.method.qris')) ?></td>
                    <td><?= e(format_rupiah((string) $order['total_amount'])) ?></td>
                    <td><?= e(format_wib((string) $order['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
