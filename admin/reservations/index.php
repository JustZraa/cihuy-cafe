<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'operations.php';

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $action = cozycafe_post_string('action');
    $reservationId = filter_var(cozycafe_post_string('reservation_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $walkinId = filter_var(cozycafe_post_string('walkin_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (in_array($action, ['complete', 'no_show', 'cancel', 'mark_refunded'], true) && is_int($reservationId) && $reservationId > 0) {
            admin_change_reservation($pdo, $reservationId, $adminId, $action, cozycafe_post_string('reason'));
            admin_redirect(
                'reservations/index.php',
                $action === 'mark_refunded' ? 'admin.message.refund_updated' : 'admin.message.reservation_updated'
            );
        }
        if (in_array($action, ['approve_walkin', 'reject_walkin', 'complete_walkin'], true) && is_int($walkinId) && $walkinId > 0) {
            $walkinAction = match ($action) {
                'approve_walkin' => 'approve',
                'reject_walkin' => 'reject',
                default => 'complete',
            };
            admin_process_walkin($pdo, $walkinId, $adminId, $walkinAction);
            admin_redirect(
                'reservations/index.php',
                $walkinAction === 'approve'
                    ? 'admin.message.walkin_approved'
                    : ($walkinAction === 'reject' ? 'admin.message.walkin_rejected' : 'admin.message.walkin_completed')
            );
        }
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    } catch (InvalidArgumentException | DomainException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$reservations = $pdo->query(
    "SELECT r.id, r.booking_code, r.user_id, r.reservation_date, r.start_time, r.end_time,
            r.guest_count, r.dp_amount, r.status, r.dp_refund_status,
            u.full_name, u.phone, t.table_number,
            (SELECT p.status FROM payments p
             WHERE p.payable_type = 'reservation_dp' AND p.payable_id = r.id
             ORDER BY p.id DESC LIMIT 1) AS payment_status
     FROM reservations r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN `tables` t ON t.id = r.table_id
     ORDER BY r.reservation_date DESC, r.start_time DESC, r.id DESC
     LIMIT 250"
)->fetchAll();
$walkins = $pdo->query(
    "SELECT w.id, w.table_id, w.user_id, w.guest_name, w.guest_phone, w.guest_count,
            w.status, w.expires_at, w.created_at, t.table_number, u.full_name
     FROM walkin_requests w
     INNER JOIN `tables` t ON t.id = w.table_id
     LEFT JOIN users u ON u.id = w.user_id
     ORDER BY (w.status = 'Pending') DESC, w.created_at DESC
     LIMIT 100"
)->fetchAll();
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));

admin_layout_start($pdo, __('admin.page.reservations'), 'reservations');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4 mb-3"><?= e(__('admin.reservation_list')) ?></h2>
    <?php if ($reservations === []): ?><p class="mb-0 text-secondary"><?= e(__('admin.empty.reservations')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr>
                <th><?= e(__('label.booking_code')) ?></th><th><?= e(__('admin.table.customer')) ?></th>
                <th><?= e(__('label.table')) ?></th><th><?= e(__('admin.table.booking_time')) ?></th>
                <th><?= e(__('label.dp')) ?></th><th><?= e(__('label.status')) ?></th>
                <th><?= e(__('admin.table.refund_status')) ?></th><th><?= e(__('admin.table.actions')) ?></th>
            </tr></thead>
            <tbody><?php foreach ($reservations as $reservation):
                $reservationStart = new DateTimeImmutable((string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'], new DateTimeZone('Asia/Jakarta'));
                $noShowReady = $now >= $reservationStart->modify('+15 minutes');
                ?>
                <tr>
                    <td><strong><?= e($reservation['booking_code']) ?></strong><small class="d-block text-secondary"><?= e($reservation['guest_count']) ?> <?= e(__('label.guest_count')) ?></small></td>
                    <td><?= e($reservation['full_name']) ?><small class="d-block text-secondary"><?= e($reservation['phone']) ?></small></td>
                    <td><?= e($reservation['table_number']) ?></td>
                    <td><?= e(format_wib((string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'])) ?><small class="d-block"><?= e(substr((string) $reservation['end_time'], 0, 5)) ?></small></td>
                    <td><?= e(format_rupiah((string) $reservation['dp_amount'])) ?><small class="d-block"><?= e($reservation['payment_status'] ?? __('admin.label.none')) ?></small></td>
                    <td><?= admin_status_badge((string) $reservation['status'], 'reservation') ?></td>
                    <td><?= e(__('admin.label.' . $reservation['dp_refund_status'])) ?></td>
                    <td><div class="d-grid gap-2">
                        <?php if ($reservation['status'] === 'Confirmed'): ?>
                            <form method="post" action="<?= e(admin_url('reservations/index.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="reservation_id" value="<?= e((int) $reservation['id']) ?>"><button class="btn btn-outline-cozy btn-sm" type="submit"><?= e(__('admin.action.complete')) ?></button></form>
                            <?php if ($noShowReady): ?><form method="post" action="<?= e(admin_url('reservations/index.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="no_show"><input type="hidden" name="reservation_id" value="<?= e((int) $reservation['id']) ?>"><button class="btn btn-outline-secondary btn-sm" type="submit"><?= e(__('admin.action.no_show')) ?></button></form><?php endif; ?>
                        <?php endif; ?>
                        <?php if (in_array($reservation['status'], ['Waiting for DP','Confirmed'], true)): ?>
                            <form method="post" action="<?= e(admin_url('reservations/index.php')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="reservation_id" value="<?= e((int) $reservation['id']) ?>">
                                <label class="visually-hidden" for="reservation-reason-<?= e((int) $reservation['id']) ?>"><?= e(__('admin.field.reason')) ?></label><input class="form-control form-control-sm" id="reservation-reason-<?= e((int) $reservation['id']) ?>" name="reason" maxlength="2000" placeholder="<?= e(__('admin.field.reason')) ?>" required>
                                <button class="btn btn-outline-danger btn-sm w-100 mt-2" type="submit"><?= e(__('admin.action.cafe_cancel')) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($reservation['status'] === 'Cancelled' && $reservation['dp_refund_status'] === 'pending_refund'): ?>
                            <form method="post" action="<?= e(admin_url('reservations/index.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="mark_refunded"><input type="hidden" name="reservation_id" value="<?= e((int) $reservation['id']) ?>"><button class="btn btn-outline-cozy btn-sm" type="submit"><?= e(__('admin.action.mark_refunded')) ?></button></form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.walkin_requests')) ?></h2>
    <?php if ($walkins === []): ?><p class="mb-0 text-secondary"><?= e(__('admin.empty.walkins')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr><th><?= e(__('label.table')) ?></th><th><?= e(__('admin.table.customer')) ?></th><th><?= e(__('label.guest_count')) ?></th><th><?= e(__('label.status')) ?></th><th><?= e(__('admin.table.created')) ?></th><th><?= e(__('admin.table.actions')) ?></th></tr></thead>
            <tbody><?php foreach ($walkins as $walkin): ?>
                <tr><td><?= e($walkin['table_number']) ?></td><td><?= e($walkin['full_name'] ?? $walkin['guest_name'] ?? __('admin.guest')) ?><small class="d-block text-secondary"><?= e($walkin['guest_phone'] ?? '') ?></small></td>
                    <td><?= e((int) $walkin['guest_count']) ?></td><td><?= admin_status_badge((string) $walkin['status'], 'walkin') ?></td><td><?= e(format_wib((string) $walkin['created_at'])) ?><?php if (is_string($walkin['expires_at'])): ?><small class="d-block"><?= e(__('admin.expires_at')) ?> <?= e(format_wib($walkin['expires_at'])) ?></small><?php endif; ?></td>
                    <td><div class="d-flex flex-wrap gap-2">
                        <?php if ($walkin['status'] === 'Pending'): ?>
                            <form method="post" action="<?= e(admin_url('reservations/index.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="approve_walkin"><input type="hidden" name="walkin_id" value="<?= e((int) $walkin['id']) ?>"><button class="btn btn-cozy btn-sm" type="submit"><?= e(__('admin.action.place_walkin')) ?></button></form>
                            <form method="post" action="<?= e(admin_url('reservations/index.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reject_walkin"><input type="hidden" name="walkin_id" value="<?= e((int) $walkin['id']) ?>"><button class="btn btn-outline-danger btn-sm" type="submit"><?= e(__('admin.action.reject_walkin')) ?></button></form>
                        <?php elseif ($walkin['status'] === 'Approved'): ?>
                            <form method="post" action="<?= e(admin_url('reservations/index.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="complete_walkin"><input type="hidden" name="walkin_id" value="<?= e((int) $walkin['id']) ?>"><button class="btn btn-outline-cozy btn-sm" type="submit"><?= e(__('admin.action.complete')) ?></button></form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
