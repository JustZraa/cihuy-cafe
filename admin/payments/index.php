<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'operations.php';

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $action = cozycafe_post_string('action');
    $paymentId = filter_var(cozycafe_post_string('payment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $orderId = filter_var(cozycafe_post_string('order_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (in_array($action, ['approve', 'reject'], true) && is_int($paymentId) && $paymentId > 0) {
            admin_review_payment($pdo, $paymentId, $adminId, $action === 'approve', cozycafe_post_string('reason'));
            admin_redirect(
                'payments/index.php',
                $action === 'approve' ? 'admin.message.payment_approved' : 'admin.message.payment_rejected'
            );
        }
        if (in_array($action, ['confirm_cash', 'confirm_qris'], true) && is_int($orderId) && $orderId > 0) {
            admin_confirm_dine_in_payment($pdo, $orderId, $adminId);
            admin_redirect('payments/index.php', 'admin.message.dine_in_payment_verified');
        }
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    } catch (InvalidArgumentException | DomainException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$payments = $pdo->query(
    "SELECT p.id, p.payment_code, p.payable_type, p.payable_id, p.amount, p.status,
            p.payment_method, p.created_at,
            COALESCE(o.order_code, r.booking_code) AS reference_code,
            COALESCE(o.user_id, r.user_id) AS customer_id,
            COALESCE(u.full_name, o.guest_name) AS customer_name,
            (SELECT pp.id FROM payment_proofs pp
             WHERE pp.payment_id = p.id ORDER BY pp.uploaded_at DESC, pp.id DESC LIMIT 1) AS proof_id
     FROM payments p
     LEFT JOIN orders o ON p.payable_type = 'order' AND o.id = p.payable_id
     LEFT JOIN reservations r ON p.payable_type = 'reservation_dp' AND r.id = p.payable_id
     LEFT JOIN users u ON u.id = COALESCE(o.user_id, r.user_id)
     WHERE p.status = 'Waiting Verification'
     ORDER BY p.created_at ASC, p.id ASC"
)->fetchAll();
$cashOrders = $pdo->query(
    "SELECT o.id, o.order_code, o.payment_method, o.total_amount, o.created_at, u.full_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     WHERE o.order_type = 'dine_in'
       AND o.payment_method IN ('cash','qris')
       AND o.status = 'Completed'
       AND NOT EXISTS (
           SELECT 1 FROM payments p
           WHERE p.payable_type = 'order' AND p.payable_id = o.id
             AND p.status IN ('Verified','Waiting Verification')
       )
     ORDER BY o.updated_at ASC, o.id ASC"
)->fetchAll();

admin_layout_start($pdo, __('admin.page.payments'), 'payments');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4 mb-3"><?= e(__('admin.pending_payments')) ?></h2>
    <?php if ($payments === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('admin.empty.payments')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr>
                <th><?= e(__('admin.table.reference')) ?></th><th><?= e(__('admin.table.customer')) ?></th>
                <th><?= e(__('admin.table.payment_method')) ?></th><th><?= e(__('label.total')) ?></th>
                <th><?= e(__('admin.table.created')) ?></th><th><?= e(__('admin.table.actions')) ?></th>
            </tr></thead>
            <tbody><?php foreach ($payments as $payment): ?>
                <tr>
                    <td><strong><?= e($payment['reference_code'] ?? $payment['payment_code']) ?></strong><small class="d-block text-secondary"><?= e($payment['payment_code']) ?></small></td>
                    <td><?= e($payment['customer_name'] ?? __('admin.guest')) ?></td>
                    <td><?= e(strtoupper((string) $payment['payment_method'])) ?><?= $payment['payable_type'] === 'reservation_dp' ? ' · ' . e(__('label.dp')) : '' ?></td>
                    <td><?= e(format_rupiah((string) $payment['amount'])) ?></td>
                    <td><?= e(format_wib((string) $payment['created_at'])) ?></td>
                    <td>
                        <?php if ($payment['proof_id'] !== null): ?>
                            <button
                                class="btn btn-outline-cozy btn-sm mb-2"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#proof-modal"
                                data-proof-src="<?= e(cozycafe_app_path('customer/view-proof.php?id=' . (int) $payment['proof_id'])) ?>"
                                data-proof-title="<?= e($payment['reference_code'] ?? $payment['payment_code']) ?>"
                            ><?= e(__('admin.action.preview_proof')) ?></button>
                        <?php endif; ?>
                        <form method="post" action="<?= e(admin_url('payments/index.php')) ?>" class="d-grid gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="payment_id" value="<?= e((int) $payment['id']) ?>">
                            <button class="btn btn-cozy btn-sm" type="submit" name="action" value="approve"><?= e(__('admin.action.approve')) ?></button>
                            <label class="visually-hidden" for="reject-reason-<?= e((int) $payment['id']) ?>"><?= e(__('admin.field.reason')) ?></label>
                            <input class="form-control form-control-sm" id="reject-reason-<?= e((int) $payment['id']) ?>" name="reason" maxlength="2000" placeholder="<?= e(__('admin.field.reason')) ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit" name="action" value="reject"><?= e(__('admin.action.reject')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<div class="modal fade" id="proof-modal" tabindex="-1" aria-labelledby="proof-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="proof-modal-title"><?= e(__('admin.action.preview_proof')) ?></h2>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="<?= e(__('button.close')) ?>"></button>
            </div>
            <div class="modal-body text-center">
                <img id="proof-modal-image" class="img-fluid rounded" alt="<?= e(__('admin.action.preview_proof')) ?>">
            </div>
        </div>
    </div>
</div>
<script>
    document.getElementById('proof-modal')?.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!(trigger instanceof HTMLElement)) return;
        const image = document.getElementById('proof-modal-image');
        const title = document.getElementById('proof-modal-title');
        if (image instanceof HTMLImageElement && title instanceof HTMLElement) {
            image.src = trigger.dataset.proofSrc || '';
            title.textContent = trigger.dataset.proofTitle || <?= json_encode(__('admin.action.preview_proof'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        }
    });
</script>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.dine_in_settlements')) ?></h2>
    <?php if ($cashOrders === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('admin.empty.cash_settlements')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr><th><?= e(__('label.order_code')) ?></th><th><?= e(__('admin.table.customer')) ?></th><th><?= e(__('admin.table.total')) ?></th><th><?= e(__('admin.table.created')) ?></th><th></th></tr></thead>
            <tbody><?php foreach ($cashOrders as $order): ?>
                <tr>
                    <td><?= e($order['order_code']) ?><small class="d-block text-secondary"><?= e($order['payment_method'] === 'cash' ? __('payment.method.cash') : __('payment.method.qris')) ?></small></td><td><?= e($order['full_name'] ?? __('admin.guest')) ?></td>
                    <td><?= e(format_rupiah((string) $order['total_amount'])) ?></td><td><?= e(format_wib((string) $order['created_at'])) ?></td>
                    <td><form method="post" action="<?= e(admin_url('payments/index.php')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($order['payment_method'] === 'cash' ? 'confirm_cash' : 'confirm_qris') ?>"><input type="hidden" name="order_id" value="<?= e((int) $order['id']) ?>">
                        <button class="btn btn-cozy btn-sm" type="submit"><?= e($order['payment_method'] === 'cash' ? __('admin.action.confirm_cash') : __('admin.action.confirm_qris')) ?></button>
                    </form></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
