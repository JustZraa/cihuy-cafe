<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'reservation_service.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'order_service.php';

final class CozyCafePaymentException extends RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replacements = []
    ) {
        parent::__construct($translationKey);
    }
}

function cozycafe_payment_access(): ?array
{
    $access = $_SESSION['_payment_access'] ?? null;
    if (
        !is_array($access)
        || !isset($access['payment_id'], $access['payable_type'], $access['payable_id'])
        || !is_int($access['payment_id'])
        || !is_int($access['payable_id'])
        || !in_array($access['payable_type'], ['order', 'reservation_dp'], true)
    ) {
        return null;
    }

    return $access;
}

function cozycafe_payment_find_order_access(PDO $pdo, string $orderCode, string $phone): array
{
    $orderCode = strtoupper(trim($orderCode));
    if (preg_match('/\A[A-Z0-9-]{6,40}\z/', $orderCode) !== 1) {
        throw new CozyCafePaymentException('validation.track_order_code');
    }
    try {
        $phone = normalize_indonesian_phone($phone);
    } catch (InvalidArgumentException) {
        throw new CozyCafePaymentException('error.checkout_phone_invalid');
    }

    $statement = $pdo->prepare(
        "SELECT p.id AS payment_id, p.payment_code, p.payable_type, p.payable_id,
                p.payment_method, p.amount, p.status AS payment_status, p.expires_at,
                p.rejection_reason, o.order_code, o.guest_phone, o.guest_name,
                o.order_type, o.status AS order_status, o.user_id
         FROM orders o
         INNER JOIN payments p
           ON p.payable_type = 'order'
          AND p.payable_id = o.id
         WHERE o.order_code = :order_code
           AND o.guest_phone = :phone
         ORDER BY p.id DESC
         LIMIT 1"
    );
    $statement->execute(['order_code' => $orderCode, 'phone' => $phone]);
    $payment = $statement->fetch();
    if (
        !is_array($payment)
        || $payment['order_type'] !== 'takeaway'
        || $payment['payment_method'] !== 'qris'
    ) {
        throw new CozyCafePaymentException('error.payment_not_found');
    }

    cozycafe_expire_payment_if_unproven($pdo, (int) $payment['payment_id']);
    $payment = cozycafe_payment_find_by_id($pdo, (int) $payment['payment_id']);
    if (!is_array($payment)) {
        throw new RuntimeException('The payment record disappeared during lookup.');
    }

    $_SESSION['_payment_access'] = [
        'payment_id' => (int) $payment['id'],
        'payable_type' => 'order',
        'payable_id' => (int) $payment['payable_id'],
        'guest_phone' => $phone,
    ];

    return $payment;
}

function cozycafe_payment_find_by_id(PDO $pdo, int $paymentId): ?array
{
    if ($paymentId < 1) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT id, payment_code, payable_type, payable_id, payment_method, amount,
                status, rejection_reason, expires_at, verified_at
         FROM payments
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $paymentId]);
    $payment = $statement->fetch();

    return is_array($payment) ? $payment : null;
}

function cozycafe_payment_expire_unproven_locked(PDO $pdo, int $paymentId): bool
{
    $statement = $pdo->prepare(
        'SELECT id, payable_type, payable_id, status, expires_at
         FROM payments
         WHERE id = :id
         FOR UPDATE'
    );
    $statement->execute(['id' => $paymentId]);
    $payment = $statement->fetch();
    if (
        !is_array($payment)
        || !in_array($payment['status'], ['Pending', 'Rejected'], true)
        || !is_string($payment['expires_at'])
    ) {
        return false;
    }

    $proofQuery = $pdo->prepare('SELECT 1 FROM payment_proofs WHERE payment_id = :payment_id LIMIT 1');
    $proofQuery->execute(['payment_id' => $paymentId]);
    if ($proofQuery->fetchColumn() !== false) {
        return false;
    }

    $expire = $pdo->prepare(
        "UPDATE payments
         SET status = 'Expired'
         WHERE id = :id
           AND status IN ('Pending', 'Rejected')
           AND expires_at <= NOW()"
    );
    $expire->execute(['id' => $paymentId]);
    if ($expire->rowCount() !== 1) {
        return false;
    }

    if ($payment['payable_type'] === 'order') {
        $orderQuery = $pdo->prepare(
            'SELECT id, status
             FROM orders
             WHERE id = :id
             FOR UPDATE'
        );
        $orderQuery->execute(['id' => (int) $payment['payable_id']]);
        $order = $orderQuery->fetch();
        if (is_array($order) && $order['status'] === 'Waiting for Payment') {
            $updateOrder = $pdo->prepare(
                "UPDATE orders
                 SET status = 'Cancelled', cancel_reason = :reason
                 WHERE id = :id AND status = 'Waiting for Payment'"
            );
            $updateOrder->execute([
                'reason' => __('payment.expired_reason'),
                'id' => (int) $order['id'],
            ]);
            if ($updateOrder->rowCount() === 1) {
                log_order_status(
                    $pdo,
                    (int) $order['id'],
                    'Waiting for Payment',
                    'Cancelled',
                    'system',
                    null,
                    __('payment.expired_reason')
                );
                restore_order_stock($pdo, (int) $order['id']);
            }
        }
    } elseif ($payment['payable_type'] === 'reservation_dp') {
        $reservationQuery = $pdo->prepare(
            'SELECT id, user_id, status
             FROM reservations
             WHERE id = :id
             FOR UPDATE'
        );
        $reservationQuery->execute(['id' => (int) $payment['payable_id']]);
        $reservation = $reservationQuery->fetch();
        if (is_array($reservation) && $reservation['status'] === 'Waiting for DP') {
            $updateReservation = $pdo->prepare(
                "UPDATE reservations
                 SET status = 'Expired',
                     cancelled_by = 'system',
                     dp_refund_status = 'none'
                 WHERE id = :id AND status = 'Waiting for DP'"
            );
            $updateReservation->execute(['id' => (int) $reservation['id']]);
            if ($updateReservation->rowCount() === 1) {
                log_reservation_status(
                    $pdo,
                    (int) $reservation['id'],
                    'Waiting for DP',
                    'Expired',
                    'system',
                    null,
                    __('payment.expired_reason')
                );
                create_notification(
                    $pdo,
                    'customer',
                    (int) $reservation['user_id'],
                    __('notification.customer.payment_updated', [
                        'status' => __('status.payment.expired'),
                    ]),
                    __('payment.reservation_expired_notice'),
                    cozycafe_app_path('customer/reservations/index.php')
                );
            }
        }
    }

    return true;
}

function cozycafe_expire_payment_if_unproven(PDO $pdo, int $paymentId): bool
{
    return cozycafe_with_transaction(
        $pdo,
        static fn (): bool => cozycafe_payment_expire_unproven_locked($pdo, $paymentId)
    );
}

function cozycafe_payment_proofs(PDO $pdo, int $paymentId): array
{
    $statement = $pdo->prepare(
        'SELECT id, file_path, original_name, mime_type, uploaded_at
         FROM payment_proofs
         WHERE payment_id = :payment_id
         ORDER BY uploaded_at DESC, id DESC'
    );
    $statement->execute(['payment_id' => $paymentId]);

    return $statement->fetchAll();
}

function cozycafe_payment_authorized(PDO $pdo, array $payment): bool
{
    if (cozycafe_session_has_id('admin_id')) {
        return true;
    }
    $customerId = cozycafe_current_customer_id();
    if ($payment['payable_type'] === 'order') {
        $ownerQuery = $pdo->prepare('SELECT user_id, guest_phone FROM orders WHERE id = :id LIMIT 1');
        $ownerQuery->execute(['id' => (int) $payment['payable_id']]);
        $owner = $ownerQuery->fetch();
        if (
            is_array($owner)
            && $customerId !== null
            && $owner['user_id'] !== null
            && (int) $owner['user_id'] === $customerId
        ) {
            return true;
        }
        $access = cozycafe_payment_access();
        return is_array($owner)
            && is_array($access)
            && ($access['payable_type'] ?? null) === 'order'
            && (int) ($access['payment_id'] ?? 0) === (int) $payment['id']
            && (int) ($access['payable_id'] ?? 0) === (int) $payment['payable_id']
            && isset($access['guest_phone'])
            && is_string($access['guest_phone'])
            && hash_equals((string) $owner['guest_phone'], $access['guest_phone']);
    }

    if ($payment['payable_type'] === 'reservation_dp' && $customerId !== null) {
        $ownerQuery = $pdo->prepare('SELECT user_id FROM reservations WHERE id = :id LIMIT 1');
        $ownerQuery->execute(['id' => (int) $payment['payable_id']]);
        $ownerId = $ownerQuery->fetchColumn();
        return $ownerId !== false && (int) $ownerId === $customerId;
    }

    return false;
}

function cozycafe_payment_upload_proof(PDO $pdo, int $paymentId, array $file): void
{
    if ($paymentId < 1) {
        throw new CozyCafePaymentException('error.payment_not_found');
    }
    if (cozycafe_session_has_id('admin_id')) {
        throw new CozyCafePaymentException('error.payment_not_found');
    }

    cozycafe_expire_payment_if_unproven($pdo, $paymentId);
    $uploadedPath = null;
    try {
        cozycafe_with_transaction($pdo, static function () use ($pdo, $paymentId, $file, &$uploadedPath): void {
        $paymentQuery = $pdo->prepare(
            'SELECT id, payment_code, payable_type, payable_id, payment_method, status, expires_at
             FROM payments
             WHERE id = :id
             FOR UPDATE'
        );
        $paymentQuery->execute(['id' => $paymentId]);
        $payment = $paymentQuery->fetch();
        if (!is_array($payment) || $payment['payment_method'] !== 'qris') {
            throw new CozyCafePaymentException('error.payment_not_found');
        }

        if (!cozycafe_payment_authorized($pdo, $payment)) {
            throw new CozyCafePaymentException('error.payment_not_found');
        }

        if ($payment['payable_type'] === 'order') {
            $targetQuery = $pdo->prepare('SELECT order_type, status FROM orders WHERE id = :id FOR UPDATE');
            $targetQuery->execute(['id' => (int) $payment['payable_id']]);
            $target = $targetQuery->fetch();
            if (
                !is_array($target)
                || $target['order_type'] !== 'takeaway'
                || $target['status'] !== 'Waiting for Payment'
            ) {
                throw new CozyCafePaymentException('error.payment_not_payable');
            }
        } elseif ($payment['payable_type'] === 'reservation_dp') {
            $targetQuery = $pdo->prepare('SELECT status FROM reservations WHERE id = :id FOR UPDATE');
            $targetQuery->execute(['id' => (int) $payment['payable_id']]);
            $target = $targetQuery->fetch();
            if (!is_array($target) || $target['status'] !== 'Waiting for DP') {
                throw new CozyCafePaymentException('error.payment_not_payable');
            }
        } else {
            throw new CozyCafePaymentException('error.payment_not_found');
        }

        if (!in_array($payment['status'], ['Pending', 'Rejected'], true)) {
            throw new CozyCafePaymentException(
                $payment['status'] === 'Waiting Verification'
                    ? 'error.payment_proof_already_waiting'
                    : 'error.payment_not_payable'
            );
        }

        $proofQuery = $pdo->prepare('SELECT COUNT(*) FROM payment_proofs WHERE payment_id = :payment_id');
        $proofQuery->execute(['payment_id' => $paymentId]);
        $hasPriorProof = (int) $proofQuery->fetchColumn() > 0;
        if (!$hasPriorProof) {
            if (!is_string($payment['expires_at'])) {
                throw new CozyCafePaymentException('error.payment_deadline_missing');
            }
            $deadline = new DateTimeImmutable($payment['expires_at'], new DateTimeZone('Asia/Jakarta'));
            if ($deadline <= new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'))) {
                throw new CozyCafePaymentException('error.payment_expired');
            }
        }

        if (!isset($file['error']) || !is_int($file['error'])) {
            throw new CozyCafePaymentException('error.payment_proof_required');
        }

        $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        if ($originalName === '') {
            $originalName = 'payment-proof';
        }
        if (strlen($originalName) > 255) {
            $originalName = substr($originalName, -255);
        }

        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment-proofs';
        try {
            $fileName = secure_upload(
                $file,
                $uploadDirectory,
                ['image/jpeg', 'image/png', 'image/webp'],
                5
            );
        } catch (RuntimeException $exception) {
            throw new CozyCafePaymentException('error.payment_proof_upload_failed', [
                'reason' => $exception->getMessage(),
            ]);
        }

        $proofPath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
        $uploadedPath = $proofPath;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('The saved proof could not be inspected.');
        }
        try {
            $mimeType = finfo_file($finfo, $proofPath);
        } finally {
            finfo_close($finfo);
        }
        if (!is_string($mimeType) || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('The saved proof has an unexpected MIME type.');
        }

        $insertProof = $pdo->prepare(
            'INSERT INTO payment_proofs (payment_id, file_path, original_name, mime_type)
             VALUES (:payment_id, :file_path, :original_name, :mime_type)'
        );
        $insertProof->execute([
            'payment_id' => $paymentId,
            'file_path' => 'payment-proofs/' . $fileName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
        ]);
        $updatePayment = $pdo->prepare(
            "UPDATE payments
             SET status = 'Waiting Verification', rejection_reason = NULL
             WHERE id = :id"
        );
        $updatePayment->execute(['id' => $paymentId]);

        $customerId = null;
        if ($payment['payable_type'] === 'reservation_dp') {
            $customerQuery = $pdo->prepare('SELECT user_id FROM reservations WHERE id = :id');
        } else {
            $customerQuery = $pdo->prepare('SELECT user_id FROM orders WHERE id = :id');
        }
        $customerQuery->execute(['id' => (int) $payment['payable_id']]);
        $ownerId = $customerQuery->fetchColumn();
        if ($ownerId !== false && $ownerId !== null) {
            $customerId = (int) $ownerId;
        }

        create_notification(
            $pdo,
            'admin',
            null,
            __('notification.admin.payment_proof'),
            __('payment.proof_received_notice', ['payment_code' => (string) $payment['payment_code']])
        );
        if ($customerId !== null) {
            create_notification(
                $pdo,
                'customer',
                $customerId,
                __('status.payment.waiting_verification'),
                __('payment.proof_waiting_notice'),
                $payment['payable_type'] === 'reservation_dp'
                    ? cozycafe_app_path('customer/reservations/detail.php?id=' . (int) $payment['payable_id'])
                    : cozycafe_app_path('customer/track-order.php')
            );
        }

        if (!cozycafe_payment_authorized($pdo, $payment)) {
            throw new CozyCafePaymentException('error.payment_not_found');
        }
        });
    } catch (Throwable $exception) {
        if (is_string($uploadedPath) && is_file($uploadedPath) && !unlink($uploadedPath)) {
            throw new RuntimeException('The uncommitted payment proof could not be removed.', 0, $exception);
        }
        throw $exception;
    }
}

function cozycafe_cancel_customer_reservation(PDO $pdo, int $reservationId, int $customerId): void
{
    if ($reservationId < 1 || $customerId < 1) {
        throw new CozyCafePaymentException('error.reservation_not_found');
    }

    cozycafe_with_transaction($pdo, static function () use ($pdo, $reservationId, $customerId): void {
        $paymentIdQuery = $pdo->prepare(
            "SELECT id
             FROM payments
             WHERE payable_type = 'reservation_dp'
               AND payable_id = :reservation_id
             ORDER BY id DESC
             LIMIT 1"
        );
        $paymentIdQuery->execute(['reservation_id' => $reservationId]);
        $paymentId = $paymentIdQuery->fetchColumn();
        $payment = false;
        if ($paymentId !== false) {
            $paymentQuery = $pdo->prepare(
                'SELECT id, status
                 FROM payments
                 WHERE id = :id
                 FOR UPDATE'
            );
            $paymentQuery->execute(['id' => (int) $paymentId]);
            $payment = $paymentQuery->fetch();
        }

        $reservationQuery = $pdo->prepare(
            'SELECT id, user_id, booking_code, status
             FROM reservations
             WHERE id = :id
             FOR UPDATE'
        );
        $reservationQuery->execute(['id' => $reservationId]);
        $reservation = $reservationQuery->fetch();
        if (
            !is_array($reservation)
            || (int) $reservation['user_id'] !== $customerId
            || !in_array($reservation['status'], ['Waiting for DP', 'Confirmed'], true)
        ) {
            throw new CozyCafePaymentException('error.reservation_not_cancellable');
        }

        if (is_array($payment) && $payment['status'] === 'Waiting Verification') {
            throw new CozyCafePaymentException('error.reservation_payment_under_review');
        }

        $refundStatus = is_array($payment) && $payment['status'] === 'Verified'
            ? 'forfeited'
            : 'none';
        $updateReservation = $pdo->prepare(
            "UPDATE reservations
             SET status = 'Cancelled',
                 cancelled_by = 'customer',
                 cancel_reason = :cancel_reason,
                 dp_refund_status = :dp_refund_status
             WHERE id = :id"
        );
        $updateReservation->execute([
            'cancel_reason' => __('reservation.customer_cancel_reason'),
            'dp_refund_status' => $refundStatus,
            'id' => $reservationId,
        ]);
        if ($updateReservation->rowCount() !== 1) {
            throw new RuntimeException('The reservation cancellation was not persisted.');
        }

        if (is_array($payment) && in_array($payment['status'], ['Pending', 'Rejected'], true)) {
            $expirePayment = $pdo->prepare(
                "UPDATE payments
                 SET status = 'Expired'
                 WHERE id = :id AND status IN ('Pending', 'Rejected')"
            );
            $expirePayment->execute(['id' => (int) $payment['id']]);
        }

        log_reservation_status(
            $pdo,
            $reservationId,
            (string) $reservation['status'],
            'Cancelled',
            'customer',
            $customerId,
            __('reservation.customer_cancel_reason')
        );
        create_notification(
            $pdo,
            'customer',
            $customerId,
            __('status.reservation.cancelled'),
            __('notification.customer.reservation_status_changed', [
                'status' => __('status.reservation.cancelled'),
            ]),
            cozycafe_app_path('customer/reservations/index.php')
        );
        create_notification(
            $pdo,
            'admin',
            null,
            __('status.reservation.cancelled'),
            __('notification.reservation_cancelled_message', [
                'booking_code' => (string) $reservation['booking_code'],
            ])
        );
    });
}

function cozycafe_payment_stream_proof(PDO $pdo, int $proofId): never
{
    $statement = $pdo->prepare(
        'SELECT pp.file_path, pp.mime_type, pp.original_name, p.id,
                p.payable_type, p.payable_id
         FROM payment_proofs pp
         INNER JOIN payments p ON p.id = pp.payment_id
         WHERE pp.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $proofId]);
    $proof = $statement->fetch();
    if (!is_array($proof) || !cozycafe_payment_authorized($pdo, $proof)) {
        http_response_code(404);
        exit(__('error.payment_proof_not_found'));
    }

    $relativePath = $proof['file_path'];
    if (
        !is_string($relativePath)
        || preg_match('/\Apayment-proofs\/([a-f0-9]{32}\.(?:jpg|png|webp))\z/', $relativePath, $matches) !== 1
        || !in_array($proof['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)
    ) {
        throw new RuntimeException('The stored payment proof path or MIME type is invalid.');
    }
    $directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment-proofs');
    if ($directory === false) {
        throw new RuntimeException('The payment proof directory is unavailable.');
    }
    $filePath = realpath($directory . DIRECTORY_SEPARATOR . $matches[1]);
    if (
        $filePath === false
        || !is_file($filePath)
        || !str_starts_with($filePath, $directory . DIRECTORY_SEPARATOR)
    ) {
        http_response_code(404);
        exit(__('error.payment_proof_not_found'));
    }

    header('Content-Type: ' . $proof['mime_type']);
    header('Content-Length: ' . (string) filesize($filePath));
    header('Content-Disposition: inline; filename="payment-proof-' . (int) $proofId . '.' . pathinfo($filePath, PATHINFO_EXTENSION) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($filePath);
    exit;
}
