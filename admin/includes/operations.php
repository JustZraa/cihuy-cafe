<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'order_service.php';

function admin_payment_code(): string
{
    return 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function admin_review_payment(PDO $pdo, int $paymentId, int $adminId, bool $approve, string $reason = ''): void
{
    if ($paymentId < 1 || $adminId < 1) {
        throw new InvalidArgumentException(__('admin.error.not_found'));
    }
    $reason = trim($reason);
    if (!$approve && ($reason === '' || strlen($reason) > 2000)) {
        throw new InvalidArgumentException(__('admin.error.reason_required'));
    }

    admin_transaction($pdo, static function () use ($pdo, $paymentId, $adminId, $approve, $reason): void {
        $paymentQuery = $pdo->prepare(
            'SELECT id, payment_code, payable_type, payable_id, amount, status
             FROM payments WHERE id = :id FOR UPDATE'
        );
        $paymentQuery->execute(['id' => $paymentId]);
        $payment = $paymentQuery->fetch();
        if (!is_array($payment) || $payment['status'] !== 'Waiting Verification') {
            throw new DomainException(__('admin.error.payment_review'));
        }
        $proofQuery = $pdo->prepare('SELECT 1 FROM payment_proofs WHERE payment_id = :id LIMIT 1');
        $proofQuery->execute(['id' => $paymentId]);
        if ($proofQuery->fetchColumn() === false) {
            throw new DomainException(__('admin.error.payment_review'));
        }

        if ($payment['payable_type'] === 'order') {
            $entityQuery = $pdo->prepare(
                'SELECT id, user_id, order_code, order_type, status
                 FROM orders WHERE id = :id FOR UPDATE'
            );
            $entityQuery->execute(['id' => (int) $payment['payable_id']]);
            $order = $entityQuery->fetch();
            if (!is_array($order) || $order['status'] !== 'Waiting for Payment') {
                throw new DomainException(__('admin.error.payment_review'));
            }
            if ($approve) {
                $pdo->prepare("UPDATE payments SET status = 'Verified', verified_by = :admin_id, verified_at = NOW(), rejection_reason = NULL WHERE id = :id")
                    ->execute(['admin_id' => $adminId, 'id' => $paymentId]);
                $pdo->prepare("UPDATE orders SET status = 'Paid' WHERE id = :id AND status = 'Waiting for Payment'")
                    ->execute(['id' => (int) $order['id']]);
                log_order_status($pdo, (int) $order['id'], 'Waiting for Payment', 'Paid', 'admin', $adminId, 'Payment proof verified.');
                log_admin_action($pdo, $adminId, 'payment_verified', 'payment', $paymentId, 'Verified payment for order ' . $order['order_code'] . '.');
                if ($order['user_id'] !== null) {
                    admin_notify_customer(
                        $pdo,
                        (int) $order['user_id'],
                        __('status.order.paid'),
                        __('notification.customer.order_status_changed', ['status' => __('status.order.paid')]),
                        'customer/orders/detail.php?id=' . (int) $order['id']
                    );
                }
            } else {
                $pdo->prepare("UPDATE payments SET status = 'Rejected', verified_by = :admin_id, verified_at = NOW(), rejection_reason = :reason WHERE id = :id")
                    ->execute(['admin_id' => $adminId, 'reason' => $reason, 'id' => $paymentId]);
                log_admin_action($pdo, $adminId, 'payment_rejected', 'payment', $paymentId, 'Rejected payment for order ' . $order['order_code'] . '.');
                if ($order['user_id'] !== null) {
                    admin_notify_customer(
                        $pdo,
                        (int) $order['user_id'],
                        __('status.payment.rejected'),
                        $reason,
                        'customer/payment.php?id=' . $paymentId
                    );
                }
            }
        } elseif ($payment['payable_type'] === 'reservation_dp') {
            $entityQuery = $pdo->prepare(
                'SELECT id, user_id, booking_code, status FROM reservations WHERE id = :id FOR UPDATE'
            );
            $entityQuery->execute(['id' => (int) $payment['payable_id']]);
            $reservation = $entityQuery->fetch();
            if (!is_array($reservation) || $reservation['status'] !== 'Waiting for DP') {
                throw new DomainException(__('admin.error.payment_review'));
            }
            if ($approve) {
                $pdo->prepare("UPDATE payments SET status = 'Verified', verified_by = :admin_id, verified_at = NOW(), rejection_reason = NULL WHERE id = :id")
                    ->execute(['admin_id' => $adminId, 'id' => $paymentId]);
                $pdo->prepare("UPDATE reservations SET status = 'Confirmed' WHERE id = :id AND status = 'Waiting for DP'")
                    ->execute(['id' => (int) $reservation['id']]);
                log_reservation_status($pdo, (int) $reservation['id'], 'Waiting for DP', 'Confirmed', 'admin', $adminId, 'Reservation DP verified.');
                log_admin_action($pdo, $adminId, 'payment_verified', 'payment', $paymentId, 'Verified DP for reservation ' . $reservation['booking_code'] . '.');
                admin_notify_customer(
                    $pdo,
                    (int) $reservation['user_id'],
                    __('status.reservation.confirmed'),
                    __('notification.customer.reservation_status_changed', ['status' => __('status.reservation.confirmed')]),
                    'customer/reservations/detail.php?id=' . (int) $reservation['id']
                );
            } else {
                $pdo->prepare("UPDATE payments SET status = 'Rejected', verified_by = :admin_id, verified_at = NOW(), rejection_reason = :reason WHERE id = :id")
                    ->execute(['admin_id' => $adminId, 'reason' => $reason, 'id' => $paymentId]);
                log_admin_action($pdo, $adminId, 'payment_rejected', 'payment', $paymentId, 'Rejected DP for reservation ' . $reservation['booking_code'] . '.');
                admin_notify_customer(
                    $pdo,
                    (int) $reservation['user_id'],
                    __('status.payment.rejected'),
                    $reason,
                    'customer/reservations/detail.php?id=' . (int) $reservation['id']
                );
            }
        } else {
            throw new DomainException(__('admin.error.payment_review'));
        }
    });
}

function admin_change_order_status(PDO $pdo, int $orderId, int $adminId, string $nextStatus, string $reason = ''): void
{
    if (!in_array($nextStatus, ['Processing', 'Ready', 'Completed', 'Cancelled'], true)) {
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    }
    $reason = trim($reason);
    if ($nextStatus === 'Cancelled' && ($reason === '' || strlen($reason) > 2000)) {
        throw new InvalidArgumentException(__('admin.error.reason_required'));
    }

    admin_transaction($pdo, static function () use ($pdo, $orderId, $adminId, $nextStatus, $reason): void {
        $typeQuery = $pdo->prepare('SELECT order_type FROM orders WHERE id = :id');
        $typeQuery->execute(['id' => $orderId]);
        $orderType = $typeQuery->fetchColumn();
        if (!is_string($orderType)) {
            throw new InvalidArgumentException(__('admin.error.not_found'));
        }

        $lockPayment = static function () use ($pdo, $orderId): array|false {
            $paymentQuery = $pdo->prepare(
                "SELECT id, status
                 FROM payments
                 WHERE payable_type = 'order' AND payable_id = :id
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $paymentQuery->execute(['id' => $orderId]);
            $payment = $paymentQuery->fetch();
            return is_array($payment) ? $payment : false;
        };
        $lockOrder = static function () use ($pdo, $orderId): array|false {
            $query = $pdo->prepare(
                'SELECT id, user_id, order_code, order_type, status, payment_method, total_amount
                 FROM orders WHERE id = :id FOR UPDATE'
            );
            $query->execute(['id' => $orderId]);
            $order = $query->fetch();
            return is_array($order) ? $order : false;
        };
        if ($orderType === 'takeaway') {
            $payment = $lockPayment();
            $order = $lockOrder();
        } else {
            $order = $lockOrder();
            $payment = $lockPayment();
        }
        if (!is_array($order)) {
            throw new InvalidArgumentException(__('admin.error.not_found'));
        }
        $oldStatus = (string) $order['status'];
        $allowed = match ($nextStatus) {
            'Processing' => in_array($oldStatus, ['Pending', 'Paid'], true),
            'Ready' => $oldStatus === 'Processing',
            'Completed' => $oldStatus === 'Ready',
            'Cancelled' => in_array($oldStatus, ['Pending', 'Waiting for Payment', 'Paid'], true),
            default => false,
        };
        if (!$allowed) {
            throw new DomainException(__('admin.error.transition'));
        }

        if ($nextStatus === 'Cancelled') {
            if (is_array($payment) && $payment['status'] === 'Waiting Verification') {
                throw new DomainException(__('admin.error.order_payment_review'));
            }
            if (is_array($payment) && $payment['status'] === 'Verified') {
                $pdo->prepare("UPDATE payments SET status = 'Refunded' WHERE id = :id")
                    ->execute(['id' => (int) $payment['id']]);
            } elseif (is_array($payment) && in_array($payment['status'], ['Pending', 'Rejected'], true)) {
                $pdo->prepare("UPDATE payments SET status = 'Expired' WHERE id = :id")
                    ->execute(['id' => (int) $payment['id']]);
            }
        }

        $pdo->prepare(
            'UPDATE orders SET status = :status, cancel_reason = :reason WHERE id = :id'
        )->execute([
            'status' => $nextStatus,
            'reason' => $nextStatus === 'Cancelled' ? $reason : null,
            'id' => $orderId,
        ]);
        log_order_status(
            $pdo,
            $orderId,
            $oldStatus,
            $nextStatus,
            'admin',
            $adminId,
            $nextStatus === 'Cancelled' ? $reason : null
        );
        if ($nextStatus === 'Cancelled') {
            restore_order_stock($pdo, $orderId, $adminId);
        }
        log_admin_action(
            $pdo,
            $adminId,
            'order_status',
            'order',
            $orderId,
            'Order ' . $order['order_code'] . ' changed from ' . $oldStatus . ' to ' . $nextStatus . '.'
        );
        if ($order['user_id'] !== null) {
            $statusKey = 'status.order.' . strtolower(str_replace(' ', '_', $nextStatus));
            admin_notify_customer(
                $pdo,
                (int) $order['user_id'],
                __($statusKey),
                __('notification.customer.order_status_changed', ['status' => __($statusKey)]),
                'customer/orders/detail.php?id=' . $orderId
            );
        }
    });
}

function admin_confirm_dine_in_payment(PDO $pdo, int $orderId, int $adminId): void
{
    admin_transaction($pdo, static function () use ($pdo, $orderId, $adminId): void {
        $query = $pdo->prepare(
            'SELECT id, user_id, order_code, order_type, payment_method, status, total_amount
             FROM orders WHERE id = :id FOR UPDATE'
        );
        $query->execute(['id' => $orderId]);
        $order = $query->fetch();
        if (
            !is_array($order)
            || $order['order_type'] !== 'dine_in'
            || !in_array($order['payment_method'], ['cash', 'qris'], true)
            || $order['status'] !== 'Completed'
        ) {
            throw new DomainException(__('admin.error.dine_in_payment_state'));
        }
        $existing = $pdo->prepare(
            "SELECT id FROM payments
             WHERE payable_type = 'order' AND payable_id = :id AND status IN ('Verified','Waiting Verification')
             LIMIT 1 FOR UPDATE"
        );
        $existing->execute(['id' => $orderId]);
        if ($existing->fetchColumn() !== false) {
            throw new DomainException(__('admin.error.dine_in_payment_confirmed'));
        }
        $insert = $pdo->prepare(
            "INSERT INTO payments
                (payment_code, payable_type, payable_id, payment_method, amount, status, verified_by, verified_at)
             VALUES (:payment_code, 'order', :payable_id, :payment_method, :amount, 'Verified', :admin_id, NOW())"
        );
        $insert->execute([
            'payment_code' => admin_payment_code(),
            'payable_id' => $orderId,
            'payment_method' => $order['payment_method'],
            'amount' => $order['total_amount'],
            'admin_id' => $adminId,
        ]);
        $paymentId = (int) $pdo->lastInsertId();
        log_admin_action(
            $pdo,
            $adminId,
            'dine_in_payment_verified',
            'payment',
            $paymentId,
            'Confirmed ' . $order['payment_method'] . ' settlement for order ' . $order['order_code'] . '.'
        );
        if ($order['user_id'] !== null) {
            admin_notify_customer(
                $pdo,
                (int) $order['user_id'],
                __('admin.message.dine_in_payment_verified'),
                __('admin.notification.dine_in_payment_verified', [
                    'order_code' => $order['order_code'],
                    'method' => $order['payment_method'] === 'cash' ? __('payment.method.cash') : __('payment.method.qris'),
                ]),
                'customer/orders/detail.php?id=' . $orderId
            );
        }
    });
}

function admin_confirm_cash_payment(PDO $pdo, int $orderId, int $adminId): void
{
    $methodQuery = $pdo->prepare('SELECT payment_method FROM orders WHERE id = :id');
    $methodQuery->execute(['id' => $orderId]);
    if ($methodQuery->fetchColumn() !== 'cash') {
        throw new DomainException(__('admin.error.cash_payment_state'));
    }

    admin_confirm_dine_in_payment($pdo, $orderId, $adminId);
}

function admin_change_reservation(PDO $pdo, int $reservationId, int $adminId, string $action, string $reason = ''): void
{
    if (!in_array($action, ['complete', 'no_show', 'cancel', 'mark_refunded'], true)) {
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    }
    $reason = trim($reason);
    if ($action === 'cancel' && ($reason === '' || strlen($reason) > 2000)) {
        throw new InvalidArgumentException(__('admin.error.reason_required'));
    }

    admin_transaction($pdo, static function () use ($pdo, $reservationId, $adminId, $action, $reason): void {
        $paymentQuery = $pdo->prepare(
            "SELECT id, status FROM payments
             WHERE payable_type = 'reservation_dp' AND payable_id = :id
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $paymentQuery->execute(['id' => $reservationId]);
        $payment = $paymentQuery->fetch();
        $query = $pdo->prepare(
            'SELECT id, user_id, table_id, booking_code, reservation_date, start_time, status, dp_refund_status
             FROM reservations WHERE id = :id FOR UPDATE'
        );
        $query->execute(['id' => $reservationId]);
        $reservation = $query->fetch();
        if (!is_array($reservation)) {
            throw new InvalidArgumentException(__('admin.error.not_found'));
        }
        $oldStatus = (string) $reservation['status'];
        $newStatus = $oldStatus;
        $refundStatus = (string) $reservation['dp_refund_status'];

        if ($action === 'complete') {
            if ($oldStatus !== 'Confirmed') {
                throw new DomainException(__('admin.error.reservation_state'));
            }
            $newStatus = 'Completed';
        } elseif ($action === 'no_show') {
            if ($oldStatus !== 'Confirmed') {
                throw new DomainException(__('admin.error.reservation_state'));
            }
            $graceCheck = $pdo->prepare(
                "SELECT TIMESTAMP(:reservation_date, :start_time) <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $graceCheck->execute([
                'reservation_date' => $reservation['reservation_date'],
                'start_time' => $reservation['start_time'],
            ]);
            if ((int) $graceCheck->fetchColumn() !== 1) {
                throw new DomainException(__('admin.error.reservation_grace'));
            }
            $newStatus = 'No Show';
            $refundStatus = is_array($payment) && $payment['status'] === 'Verified' ? 'forfeited' : 'none';
        } elseif ($action === 'cancel') {
            if (!in_array($oldStatus, ['Waiting for DP', 'Confirmed'], true)) {
                throw new DomainException(__('admin.error.reservation_state'));
            }
            $newStatus = 'Cancelled';
            if (is_array($payment)) {
                if (in_array($payment['status'], ['Verified', 'Waiting Verification', 'Refunded'], true)) {
                    $refundStatus = 'pending_refund';
                    $pdo->prepare("UPDATE payments SET status = 'Refunded', verified_by = :admin_id, verified_at = NOW() WHERE id = :id")
                        ->execute(['admin_id' => $adminId, 'id' => (int) $payment['id']]);
                } elseif (in_array($payment['status'], ['Pending', 'Rejected'], true)) {
                    $pdo->prepare("UPDATE payments SET status = 'Expired' WHERE id = :id")
                        ->execute(['id' => (int) $payment['id']]);
                    $refundStatus = 'none';
                }
            }
        } else {
            if ($oldStatus !== 'Cancelled' || $refundStatus !== 'pending_refund') {
                throw new DomainException(__('admin.error.reservation_state'));
            }
            $refundStatus = 'refunded';
            if (is_array($payment)) {
                $pdo->prepare("UPDATE payments SET status = 'Refunded', verified_by = :admin_id, verified_at = NOW() WHERE id = :id")
                    ->execute(['admin_id' => $adminId, 'id' => (int) $payment['id']]);
            }
        }

        if ($action === 'mark_refunded') {
            $pdo->prepare('UPDATE reservations SET dp_refund_status = :refund_status WHERE id = :id')
                ->execute(['refund_status' => $refundStatus, 'id' => $reservationId]);
            log_admin_action($pdo, $adminId, 'reservation_refunded', 'reservation', $reservationId, 'DP refund completed for ' . $reservation['booking_code'] . '.');
        } else {
            $pdo->prepare(
                'UPDATE reservations
                 SET status = :status, cancelled_by = :cancelled_by, cancel_reason = :reason,
                     dp_refund_status = :refund_status
                 WHERE id = :id'
            )->execute([
                'status' => $newStatus,
                'cancelled_by' => $action === 'cancel' ? 'admin' : null,
                'reason' => $action === 'cancel' ? $reason : null,
                'refund_status' => $refundStatus,
                'id' => $reservationId,
            ]);
            log_reservation_status(
                $pdo,
                $reservationId,
                $oldStatus,
                $newStatus,
                'admin',
                $adminId,
                $action === 'cancel' ? $reason : null
            );
            log_admin_action($pdo, $adminId, 'reservation_status', 'reservation', $reservationId, 'Reservation ' . $reservation['booking_code'] . ' changed from ' . $oldStatus . ' to ' . $newStatus . '.');
        }

        $statusText = $action === 'mark_refunded'
            ? __('admin.label.refunded')
            : __('status.reservation.' . strtolower(str_replace(' ', '_', $newStatus)));
        admin_notify_customer(
            $pdo,
            (int) $reservation['user_id'],
            $statusText,
            __('notification.customer.reservation_status_changed', ['status' => $statusText]),
            'customer/reservations/detail.php?id=' . $reservationId
        );
    });
}

function admin_process_walkin(PDO $pdo, int $requestId, int $adminId, string $action): void
{
    if (!in_array($action, ['approve', 'reject', 'complete'], true)) {
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    }

    admin_transaction($pdo, static function () use ($pdo, $requestId, $adminId, $action): void {
        $requestQuery = $pdo->prepare(
            'SELECT id, table_id, user_id, guest_name, status, expires_at, guest_phone, guest_count
             FROM walkin_requests WHERE id = :id FOR UPDATE'
        );
        $requestQuery->execute(['id' => $requestId]);
        $request = $requestQuery->fetch();
        if (!is_array($request)) {
            throw new InvalidArgumentException(__('admin.error.not_found'));
        }

        $tableQuery = $pdo->prepare(
            'SELECT id, table_number, capacity, status, occupied_until
             FROM `tables` WHERE id = :id FOR UPDATE'
        );
        $tableQuery->execute(['id' => (int) $request['table_id']]);
        $table = $tableQuery->fetch();
        if (!is_array($table)) {
            throw new InvalidArgumentException(__('admin.error.not_found'));
        }

        if ($action === 'approve') {
            if ($request['status'] !== 'Pending' || $table['status'] !== 'Tersedia') {
                throw new DomainException(__('admin.error.walkin_conflict'));
            }
            if ((int) $request['guest_count'] > (int) $table['capacity']) {
                throw new DomainException(__('admin.error.walkin_conflict'));
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
            $opening = new DateTimeImmutable($now->format('Y-m-d') . ' 09:00:00', $now->getTimezone());
            $latest = new DateTimeImmutable($now->format('Y-m-d') . ' 20:00:00', $now->getTimezone());
            $until = $now->modify('+2 hours');
            if ($now < $opening || $now > $latest || $until->format('Y-m-d') !== $now->format('Y-m-d')) {
                throw new DomainException(__('error.walkin_outside_hours'));
            }

            $overlap = $pdo->prepare(
                "SELECT 1
                 FROM reservations r
                 WHERE r.table_id = :table_id
                   AND r.reservation_date = CURRENT_DATE()
                   AND r.start_time < :end_time
                   AND r.end_time > :start_time
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
            $overlap->execute([
                'table_id' => (int) $table['id'],
                'end_time' => $until->format('H:i:s'),
                'start_time' => $now->format('H:i:s'),
            ]);
            $otherWalkin = $pdo->prepare(
                "SELECT 1 FROM walkin_requests
                 WHERE table_id = :table_id AND id <> :id
                   AND status IN ('Pending','Approved')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1"
            );
            $otherWalkin->execute(['table_id' => (int) $table['id'], 'id' => $requestId]);
            if ($overlap->fetchColumn() !== false || $otherWalkin->fetchColumn() !== false) {
                throw new DomainException(__('admin.error.walkin_conflict'));
            }

            $expiresAt = $until->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE walkin_requests SET status = 'Approved', approved_by = :admin_id, expires_at = :expires_at WHERE id = :id")
                ->execute(['admin_id' => $adminId, 'expires_at' => $expiresAt, 'id' => $requestId]);
            $pdo->prepare("UPDATE `tables` SET status = 'Digunakan', occupied_until = :expires_at, current_reservation_id = NULL WHERE id = :id")
                ->execute(['expires_at' => $expiresAt, 'id' => (int) $table['id']]);
            log_admin_action($pdo, $adminId, 'walkin_approved', 'walkin_request', $requestId, 'Walk-in seated at table ' . $table['table_number'] . ' until ' . $expiresAt . '.');
            if ($request['user_id'] !== null) {
                admin_notify_customer(
                    $pdo,
                    (int) $request['user_id'],
                    __('admin.message.walkin_approved'),
                    __('admin.notification.walkin_approved', ['table_number' => $table['table_number']]),
                    'customer/walkin.php'
                );
            }
        } elseif ($action === 'reject') {
            if ($request['status'] !== 'Pending') {
                throw new DomainException(__('admin.error.transition'));
            }
            $pdo->prepare("UPDATE walkin_requests SET status = 'Rejected', approved_by = :admin_id WHERE id = :id")
                ->execute(['admin_id' => $adminId, 'id' => $requestId]);
            log_admin_action($pdo, $adminId, 'walkin_rejected', 'walkin_request', $requestId, 'Rejected walk-in request for table ' . $table['table_number'] . '.');
            if ($request['user_id'] !== null) {
                admin_notify_customer(
                    $pdo,
                    (int) $request['user_id'],
                    __('admin.message.walkin_rejected'),
                    __('admin.notification.walkin_rejected', ['table_number' => $table['table_number']]),
                    'customer/walkin.php'
                );
            }
        } else {
            if ($request['status'] !== 'Approved') {
                throw new DomainException(__('admin.error.transition'));
            }
            $pdo->prepare("UPDATE walkin_requests SET status = 'Completed' WHERE id = :id")
                ->execute(['id' => $requestId]);
            if (
                $table['status'] === 'Digunakan'
                && is_string($request['expires_at'])
                && is_string($table['occupied_until'])
                && $request['expires_at'] === $table['occupied_until']
            ) {
                $pdo->prepare("UPDATE `tables` SET status = 'Tersedia', occupied_until = NULL WHERE id = :id")
                    ->execute(['id' => (int) $table['id']]);
            }
            log_admin_action($pdo, $adminId, 'walkin_completed', 'walkin_request', $requestId, 'Walk-in completed at table ' . $table['table_number'] . '.');
        }
    });
}
