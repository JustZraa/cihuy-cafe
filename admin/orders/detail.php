<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'operations.php';

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($orderId) || $orderId < 1) {
    http_response_code(404);
    exit(e(__('admin.error.not_found')));
}
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $action = cozycafe_post_string('action');
    try {
        if ($action === 'confirm_cash') {
            admin_confirm_dine_in_payment($pdo, $orderId, $adminId);
            admin_redirect('orders/detail.php?id=' . $orderId, 'admin.message.dine_in_payment_verified');
        }
        if ($action === 'status') {
            admin_change_order_status($pdo, $orderId, $adminId, cozycafe_post_string('status'), cozycafe_post_string('reason'));
            admin_redirect('orders/detail.php?id=' . $orderId, 'admin.message.order_updated');
        }
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    } catch (InvalidArgumentException | DomainException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$orderQuery = $pdo->prepare(
    'SELECT o.*, COALESCE(u.full_name, o.guest_name) AS customer_name,
            COALESCE(u.phone, o.guest_phone) AS customer_phone,
            r.booking_code, t.table_number
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     LEFT JOIN reservations r ON r.id = o.reservation_id
     LEFT JOIN `tables` t ON t.id = o.table_id
     WHERE o.id = :id LIMIT 1'
);
$orderQuery->execute(['id' => $orderId]);
$order = $orderQuery->fetch();
if (!is_array($order)) {
    http_response_code(404);
    exit(e(__('admin.error.not_found')));
}
$itemQuery = $pdo->prepare('SELECT product_name_snapshot, price_snapshot, quantity, subtotal, item_notes FROM order_items WHERE order_id = :id ORDER BY id');
$itemQuery->execute(['id' => $orderId]);
$items = $itemQuery->fetchAll();
$paymentQuery = $pdo->prepare(
    "SELECT id, payment_code, payment_method, amount, status, verified_at
     FROM payments WHERE payable_type = 'order' AND payable_id = :id ORDER BY id DESC"
);
$paymentQuery->execute(['id' => $orderId]);
$payments = $paymentQuery->fetchAll();
$historyQuery = $pdo->prepare(
    'SELECT old_status, new_status, changed_by_type, notes, created_at
     FROM order_status_logs WHERE order_id = :id ORDER BY created_at DESC, id DESC'
);
$historyQuery->execute(['id' => $orderId]);
$history = $historyQuery->fetchAll();
$hasVerifiedPayment = false;
foreach ($payments as $payment) {
    if ($payment['status'] === 'Verified') {
        $hasVerifiedPayment = true;
        break;
    }
}
$canCancel = in_array($order['status'], ['Pending', 'Waiting for Payment', 'Paid'], true);
$canStart = in_array($order['status'], ['Pending', 'Paid'], true);
$canReady = $order['status'] === 'Processing';
$canComplete = $order['status'] === 'Ready';
$canConfirmCash = $order['order_type'] === 'dine_in'
    && in_array($order['payment_method'], ['cash', 'qris'], true)
    && $order['status'] === 'Completed'
    && !$hasVerifiedPayment;

admin_layout_start($pdo, __('admin.page.order_detail'), 'orders');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <div class="admin-section-heading">
        <div><p class="eyebrow mb-1"><?= e($order['order_code']) ?></p><h2 class="h4 mb-0"><?= e($order['customer_name'] ?? __('admin.guest')) ?></h2></div>
        <?= admin_status_badge((string) $order['status']) ?>
    </div>
    <dl class="row mb-0">
        <dt class="col-sm-3"><?= e(__('label.order_type')) ?></dt><dd class="col-sm-9"><?= e($order['order_type'] === 'dine_in' ? __('order.type.dine_in') : __('order.type.takeaway')) ?></dd>
        <dt class="col-sm-3"><?= e(__('admin.table.payment_method')) ?></dt><dd class="col-sm-9"><?= e($order['payment_method'] === 'cash' ? __('payment.method.cash') : __('payment.method.qris')) ?></dd>
        <dt class="col-sm-3"><?= e(__('label.phone')) ?></dt><dd class="col-sm-9"><?= e($order['customer_phone'] ?? '-') ?></dd>
        <dt class="col-sm-3"><?= e(__('label.table')) ?></dt><dd class="col-sm-9"><?= e($order['table_number'] ?? '-') ?><?= is_string($order['booking_code']) ? ' · ' . e($order['booking_code']) : '' ?></dd>
        <dt class="col-sm-3"><?= e(__('admin.table.created')) ?></dt><dd class="col-sm-9"><?= e(format_wib((string) $order['created_at'])) ?></dd>
        <?php if (is_string($order['scheduled_prep_at'])): ?><dt class="col-sm-3"><?= e(__('admin.prep_at')) ?></dt><dd class="col-sm-9"><?= e(format_wib($order['scheduled_prep_at'])) ?></dd><?php endif; ?>
        <?php if (is_string($order['order_notes']) && trim($order['order_notes']) !== ''): ?><dt class="col-sm-3"><?= e(__('admin.field.notes')) ?></dt><dd class="col-sm-9"><?= nl2br(e($order['order_notes'])) ?></dd><?php endif; ?>
    </dl>
</section>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4 mb-3"><?= e(__('admin.order_items')) ?></h2>
    <div class="table-responsive"><table class="table table-cozy align-middle">
        <thead><tr><th><?= e(__('admin.table.product')) ?></th><th><?= e(__('admin.table.quantity')) ?></th><th><?= e(__('admin.field.price')) ?></th><th><?= e(__('admin.table.total')) ?></th></tr></thead>
        <tbody><?php foreach ($items as $item): ?><tr>
            <td><?= e($item['product_name_snapshot']) ?><?php if (is_string($item['item_notes']) && $item['item_notes'] !== ''): ?><small class="d-block text-secondary"><?= e($item['item_notes']) ?></small><?php endif; ?></td>
            <td><?= e((int) $item['quantity']) ?></td><td><?= e(format_rupiah((string) $item['price_snapshot'])) ?></td><td><?= e(format_rupiah((string) $item['subtotal'])) ?></td>
        </tr><?php endforeach; ?></tbody>
        <tfoot><tr><th colspan="3"><?= e(__('label.subtotal')) ?></th><td><?= e(format_rupiah((string) $order['subtotal'])) ?></td></tr>
            <tr><th colspan="3"><?= e(__('label.tax')) ?></th><td><?= e(format_rupiah((string) $order['tax_amount'])) ?></td></tr>
            <tr><th colspan="3"><?= e(__('label.total')) ?></th><td><strong><?= e(format_rupiah((string) $order['total_amount'])) ?></strong></td></tr></tfoot>
    </table></div>
</section>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4 mb-3"><?= e(__('admin.order_actions')) ?></h2>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ([
            ['enabled' => $canStart, 'status' => 'Processing', 'label' => 'admin.action.start_processing'],
            ['enabled' => $canReady, 'status' => 'Ready', 'label' => 'admin.action.mark_ready'],
            ['enabled' => $canComplete, 'status' => 'Completed', 'label' => 'admin.action.complete'],
        ] as $statusAction): ?>
            <?php if ($statusAction['enabled']): ?><form method="post" action="<?= e(admin_url('orders/detail.php?id=' . $orderId)) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= e($statusAction['status']) ?>">
                <button class="btn btn-cozy" type="submit"><?= e(__($statusAction['label'])) ?></button>
            </form><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($canCancel): ?><form method="post" action="<?= e(admin_url('orders/detail.php?id=' . $orderId)) ?>" class="admin-cancel-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="Cancelled">
            <label class="visually-hidden" for="cancel-reason"><?= e(__('admin.field.reason')) ?></label>
            <input class="form-control" id="cancel-reason" name="reason" maxlength="2000" placeholder="<?= e(__('admin.field.reason')) ?>" required>
            <button class="btn btn-outline-danger" type="submit"><?= e(__('admin.action.cancel_order')) ?></button>
        </form><?php endif; ?>
        <?php if ($canConfirmCash): ?><form method="post" action="<?= e(admin_url('orders/detail.php?id=' . $orderId)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="confirm_cash">
            <button class="btn btn-outline-cozy" type="submit"><?= e($order['payment_method'] === 'cash' ? __('admin.action.confirm_cash') : __('admin.action.confirm_qris')) ?></button>
        </form><?php endif; ?>
    </div>
</section>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4 mb-3"><?= e(__('admin.payment_history')) ?></h2>
    <?php if ($payments === []): ?><p class="mb-0 text-secondary"><?= e(__('common.no_data')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy"><thead><tr><th><?= e(__('admin.table.reference')) ?></th><th><?= e(__('admin.table.payment_method')) ?></th><th><?= e(__('admin.table.total')) ?></th><th><?= e(__('label.status')) ?></th></tr></thead><tbody>
        <?php foreach ($payments as $payment): ?><tr><td><?= e($payment['payment_code']) ?></td><td><?= e($payment['payment_method']) ?></td><td><?= e(format_rupiah((string) $payment['amount'])) ?></td><td><?= admin_status_badge((string) $payment['status'], 'payment') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.status_history')) ?></h2>
    <?php if ($history === []): ?><p class="mb-0 text-secondary"><?= e(__('common.no_data')) ?></p><?php else: ?>
        <ul class="admin-timeline"><?php foreach ($history as $entry): ?><li>
            <strong><?= e($entry['old_status'] ?? '—') ?> → <?= e($entry['new_status']) ?></strong>
            <span><?= e(format_wib((string) $entry['created_at'])) ?> · <?= e($entry['changed_by_type']) ?></span>
            <?php if (is_string($entry['notes']) && $entry['notes'] !== ''): ?><small><?= e($entry['notes']) ?></small><?php endif; ?>
        </li><?php endforeach; ?></ul>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
