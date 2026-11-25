<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $action = cozycafe_post_string('action');
    $tableId = filter_var(cozycafe_post_string('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (in_array($action, ['create', 'update'], true)) {
            $tableNumber = strtoupper(trim(cozycafe_post_string('table_number')));
            $capacity = filter_var(cozycafe_post_string('capacity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            $area = trim(cozycafe_post_string('location_area'));
            $status = cozycafe_post_string('status');
            $occupiedMinutes = filter_var(cozycafe_post_string('occupied_minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 120]]);
            if (
                preg_match('/\A[A-Z0-9-]{1,20}\z/', $tableNumber) !== 1
                || !is_int($capacity)
                || $area === ''
                || strlen($area) > 100
                || !in_array($status, ['Tersedia', 'Digunakan', 'Tidak Aktif'], true)
                || ($action === 'update' && (!is_int($tableId) || $tableId < 1))
                || ($action === 'create' && $status === 'Digunakan')
                || ($status === 'Digunakan' && !is_int($occupiedMinutes))
            ) {
                throw new InvalidArgumentException(__('error.invalid_request'));
            }

            admin_transaction($pdo, static function () use (
                $pdo,
                $adminId,
                $action,
                $tableId,
                $tableNumber,
                $capacity,
                $area,
                $status,
                $occupiedMinutes
            ): void {
                $oldTable = null;
                if ($action === 'update') {
                    $lock = $pdo->prepare('SELECT id, table_number, status, occupied_until FROM `tables` WHERE id = :id FOR UPDATE');
                    $lock->execute(['id' => $tableId]);
                    $oldTable = $lock->fetch();
                    if (!is_array($oldTable)) {
                        throw new InvalidArgumentException(__('admin.error.not_found'));
                    }
                    $activeReservations = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM reservations r
                         WHERE r.table_id = :table_id
                           AND TIMESTAMP(r.reservation_date, r.end_time) > NOW()
                           AND (
                               r.status = 'Confirmed'
                               OR (
                                   r.status = 'Waiting for DP'
                                   AND EXISTS (
                                       SELECT 1 FROM payments p
                                       WHERE p.payable_type = 'reservation_dp'
                                         AND p.payable_id = r.id
                                         AND (
                                             p.status = 'Waiting Verification'
                                             OR (p.status IN ('Pending','Rejected') AND p.expires_at > NOW())
                                         )
                                   )
                               )
                           )"
                    );
                    $activeReservations->execute(['table_id' => $tableId]);
                    $activeWalkins = $pdo->prepare(
                        "SELECT COUNT(*) FROM walkin_requests
                         WHERE table_id = :table_id
                           AND status IN ('Pending','Approved')
                           AND (expires_at IS NULL OR expires_at > NOW())"
                    );
                    $activeWalkins->execute(['table_id' => $tableId]);
                    $hasReservations = (int) $activeReservations->fetchColumn() > 0;
                    $hasWalkins = (int) $activeWalkins->fetchColumn() > 0;
                    $overlappingReservations = false;
                    if ($status === 'Digunakan') {
                        $nowForSlot = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
                        $untilForSlot = $nowForSlot->modify('+' . $occupiedMinutes . ' minutes');
                        $overlapQuery = $pdo->prepare(
                            "SELECT 1
                             FROM reservations r
                             WHERE r.table_id = :table_id
                               AND r.reservation_date = CURRENT_DATE()
                               AND r.start_time < :requested_end
                               AND r.end_time > :requested_start
                               AND (
                                   r.status = 'Confirmed'
                                   OR (
                                       r.status = 'Waiting for DP'
                                       AND EXISTS (
                                           SELECT 1 FROM payments p
                                           WHERE p.payable_type = 'reservation_dp'
                                             AND p.payable_id = r.id
                                             AND (
                                                 p.status = 'Waiting Verification'
                                                 OR (p.status IN ('Pending','Rejected') AND p.expires_at > NOW())
                                             )
                                       )
                                   )
                               )
                             LIMIT 1"
                        );
                        $overlapQuery->execute([
                            'table_id' => $tableId,
                            'requested_end' => $untilForSlot->format('H:i:s'),
                            'requested_start' => $nowForSlot->format('H:i:s'),
                        ]);
                        $overlappingReservations = $overlapQuery->fetchColumn() !== false;
                    }
                    if ($status === 'Tidak Aktif' && ($hasReservations || $hasWalkins)) {
                        throw new DomainException(__('admin.error.table_reserved'));
                    }
                    if ($status === 'Tersedia' && $oldTable['status'] === 'Digunakan' && $hasWalkins) {
                        throw new DomainException(__('admin.error.table_walkin_active'));
                    }
                    if ($status === 'Digunakan') {
                        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
                        $until = $now->modify('+' . $occupiedMinutes . ' minutes');
                        $opening = new DateTimeImmutable($now->format('Y-m-d') . ' 09:00:00', $now->getTimezone());
                        $closing = new DateTimeImmutable($now->format('Y-m-d') . ' 22:00:00', $now->getTimezone());
                        if ($now < $opening || $until > $closing || $overlappingReservations || $hasWalkins) {
                            throw new DomainException(__('admin.error.table_reserved'));
                        }
                    }
                    $occupiedUntil = $status === 'Digunakan'
                        ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))
                            ->modify('+' . $occupiedMinutes . ' minutes')->format('Y-m-d H:i:s')
                        : null;
                    $update = $pdo->prepare(
                        'UPDATE `tables`
                         SET table_number = :table_number, capacity = :capacity, location_area = :area,
                             status = :status, occupied_until = :occupied_until,
                             current_reservation_id = CASE WHEN :keep_reservation = 1 THEN current_reservation_id ELSE NULL END
                         WHERE id = :id'
                    );
                    $update->execute([
                        'table_number' => $tableNumber,
                        'capacity' => $capacity,
                        'area' => $area,
                        'status' => $status,
                        'occupied_until' => $occupiedUntil,
                        'keep_reservation' => $status === 'Digunakan' ? 1 : 0,
                        'id' => $tableId,
                    ]);
                    $id = $tableId;
                    $description = 'Table ' . $oldTable['table_number'] . ' updated to ' . $tableNumber . ' (' . $status . ').';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO `tables` (table_number, capacity, location_area, status)
                         VALUES (:table_number, :capacity, :area, :status)'
                    );
                    $insert->execute([
                        'table_number' => $tableNumber,
                        'capacity' => $capacity,
                        'area' => $area,
                        'status' => $status,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                    $description = 'Table ' . $tableNumber . ' created.';
                }
                log_admin_action($pdo, $adminId, $action, 'table', $id, $description);
            });
            admin_redirect('tables/index.php', 'admin.message.saved');
        }

        if ($action === 'delete' && is_int($tableId) && $tableId > 0) {
            admin_transaction($pdo, static function () use ($pdo, $adminId, $tableId): void {
                $lock = $pdo->prepare('SELECT table_number FROM `tables` WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $tableId]);
                $table = $lock->fetch();
                if (!is_array($table)) {
                    throw new InvalidArgumentException(__('admin.error.not_found'));
                }
                $refs = $pdo->prepare(
                    'SELECT (SELECT COUNT(*) FROM reservations WHERE table_id = :reservation_table)
                          + (SELECT COUNT(*) FROM walkin_requests WHERE table_id = :walkin_table)'
                );
                $refs->execute(['reservation_table' => $tableId, 'walkin_table' => $tableId]);
                if ((int) $refs->fetchColumn() > 0) {
                    throw new DomainException(__('admin.error.table_history'));
                }
                $pdo->prepare('DELETE FROM `tables` WHERE id = :id')->execute(['id' => $tableId]);
                log_admin_action($pdo, $adminId, 'delete', 'table', $tableId, 'Table ' . $table['table_number'] . ' deleted.');
            });
            admin_redirect('tables/index.php', 'admin.message.deleted');
        }
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    } catch (InvalidArgumentException | DomainException $exception) {
        $errors[] = $exception->getMessage();
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            $errors[] = __('admin.error.duplicate_table');
        } else {
            throw $exception;
        }
    }
}

$tables = $pdo->query(
    'SELECT t.id, t.table_number, t.capacity, t.location_area, t.status, t.occupied_until,
            t.current_reservation_id, r.booking_code
     FROM `tables` t
     LEFT JOIN reservations r ON r.id = t.current_reservation_id
     ORDER BY t.table_number'
)->fetchAll();
admin_layout_start($pdo, __('admin.page.tables'), 'tables');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4"><?= e(__('admin.action.create')) ?></h2>
    <form method="post" action="<?= e(admin_url('tables/index.php')) ?>" class="row g-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="col-6 col-md-3"><label class="form-label" for="new-table-number"><?= e(__('label.table')) ?></label><input class="form-control" id="new-table-number" name="table_number" maxlength="20" required></div>
        <div class="col-6 col-md-2"><label class="form-label" for="new-table-capacity"><?= e(__('admin.field.capacity')) ?></label><input class="form-control" id="new-table-capacity" name="capacity" type="number" min="1" max="100" required></div>
        <div class="col-12 col-md-3"><label class="form-label" for="new-table-area"><?= e(__('admin.field.area')) ?></label><input class="form-control" id="new-table-area" name="location_area" maxlength="100" required></div>
        <div class="col-12 col-md-2"><label class="form-label" for="new-table-status"><?= e(__('label.status')) ?></label><select class="form-select" id="new-table-status" name="status"><option value="Tersedia"><?= e(__('status.table.available')) ?></option><option value="Tidak Aktif"><?= e(__('status.table.inactive')) ?></option></select></div>
        <div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-cozy" type="submit"><?= e(__('admin.action.create')) ?></button></div>
    </form>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.nav.tables')) ?></h2>
    <?php if ($tables === []): ?><p class="mb-0 text-secondary"><?= e(__('admin.empty.tables')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr><th><?= e(__('label.table')) ?></th><th><?= e(__('admin.field.capacity')) ?></th><th><?= e(__('admin.field.area')) ?></th><th><?= e(__('label.status')) ?></th><th><?= e(__('admin.table.actions')) ?></th></tr></thead>
            <tbody><?php foreach ($tables as $table): ?>
                <tr><td colspan="5"><form method="post" action="<?= e(admin_url('tables/index.php')) ?>" class="row g-2 align-items-center">
                    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= e((int) $table['id']) ?>">
                    <div class="col-6 col-lg-2"><label class="visually-hidden" for="table-number-<?= e((int) $table['id']) ?>"><?= e(__('label.table')) ?></label><input class="form-control" id="table-number-<?= e((int) $table['id']) ?>" name="table_number" value="<?= e($table['table_number']) ?>" maxlength="20" required></div>
                    <div class="col-6 col-lg-1"><label class="visually-hidden" for="table-capacity-<?= e((int) $table['id']) ?>"><?= e(__('admin.field.capacity')) ?></label><input class="form-control" id="table-capacity-<?= e((int) $table['id']) ?>" name="capacity" type="number" min="1" max="100" value="<?= e((int) $table['capacity']) ?>" required></div>
                    <div class="col-6 col-lg-2"><label class="visually-hidden" for="table-area-<?= e((int) $table['id']) ?>"><?= e(__('admin.field.area')) ?></label><input class="form-control" id="table-area-<?= e((int) $table['id']) ?>" name="location_area" value="<?= e($table['location_area']) ?>" maxlength="100" required></div>
                    <div class="col-6 col-lg-2"><label class="visually-hidden" for="table-status-<?= e((int) $table['id']) ?>"><?= e(__('label.status')) ?></label><select class="form-select" id="table-status-<?= e((int) $table['id']) ?>" name="status">
                        <?php foreach (['Tersedia', 'Digunakan', 'Tidak Aktif'] as $status): ?><option value="<?= e($status) ?>" <?= $table['status'] === $status ? 'selected' : '' ?>><?= e(admin_status_label($status, 'table')) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-6 col-lg-2"><label class="visually-hidden" for="occupied-<?= e((int) $table['id']) ?>"><?= e(__('admin.field.occupied_minutes')) ?></label><input class="form-control" id="occupied-<?= e((int) $table['id']) ?>" name="occupied_minutes" type="number" min="30" max="120" step="30" value="120" aria-label="<?= e(__('admin.field.occupied_minutes')) ?>"></div>
                    <div class="col-6 col-lg-1"><button class="btn btn-outline-cozy btn-sm w-100" type="submit"><?= e(__('button.save')) ?></button></div>
                    <div class="col-12 col-lg-2 d-flex justify-content-between align-items-center">
                        <small class="text-secondary"><?= is_string($table['occupied_until']) ? e(format_wib($table['occupied_until'])) : '' ?><?= is_string($table['booking_code']) ? e($table['booking_code']) : '' ?></small>
                        <button class="btn btn-outline-danger btn-sm" type="submit" form="delete-table-<?= e((int) $table['id']) ?>"><?= e(__('button.delete')) ?></button>
                    </div>
                </form><form id="delete-table-<?= e((int) $table['id']) ?>" method="post" action="<?= e(admin_url('tables/index.php')) ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((int) $table['id']) ?>"></form></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
