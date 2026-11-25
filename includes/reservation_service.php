<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cart_service.php';

final class CozyCafeReservationException extends RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replacements = []
    ) {
        parent::__construct($translationKey);
    }
}

function cozycafe_reservation_validate_slot(
    string $reservationDate,
    string $startTime,
    int $durationMinutes
): array {
    $timezone = new DateTimeZone('Asia/Jakarta');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reservationDate, $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $reservationDate
        || $date->format('Y-m-d') < (new DateTimeImmutable('today', $timezone))->format('Y-m-d')
    ) {
        throw new CozyCafeReservationException('error.reservation_invalid_date');
    }

    if (
        preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $startTime) !== 1
        || (int) substr($startTime, 0, 2) < 9
        || (int) substr($startTime, 0, 2) > 21
        || (int) substr($startTime, 3, 2) % 30 !== 0
    ) {
        throw new CozyCafeReservationException('error.reservation_invalid_time');
    }

    if ($durationMinutes < 30 || $durationMinutes > 780 || $durationMinutes % 30 !== 0) {
        throw new CozyCafeReservationException('error.reservation_invalid_duration');
    }

    $start = new DateTimeImmutable($reservationDate . ' ' . $startTime, $timezone);
    $end = $start->modify('+' . $durationMinutes . ' minutes');
    $opening = new DateTimeImmutable($reservationDate . ' 09:00:00', $timezone);
    $closing = new DateTimeImmutable($reservationDate . ' 22:00:00', $timezone);
    if ($start < $opening || $end > $closing) {
        throw new CozyCafeReservationException('error.reservation_outside_hours');
    }
    if ($start <= new DateTimeImmutable('now', $timezone)) {
        throw new CozyCafeReservationException('error.reservation_start_passed');
    }

    return [
        'date' => $reservationDate,
        'start_time' => $start->format('H:i:s'),
        'end_time' => $end->format('H:i:s'),
        'start_at' => $start,
        'end_at' => $end,
        'duration_minutes' => $durationMinutes,
    ];
}

function cozycafe_reservation_table_conflict_reason(
    PDO $pdo,
    array $table,
    string $reservationDate,
    string $startTime,
    string $endTime,
    int $guestCount
): ?string {
    if ((int) ($table['capacity'] ?? 0) < $guestCount) {
        return 'capacity';
    }
    if (($table['status'] ?? '') === 'Tidak Aktif') {
        return 'inactive';
    }

    $timezone = new DateTimeZone('Asia/Jakarta');
    $startAt = new DateTimeImmutable($reservationDate . ' ' . $startTime, $timezone);
    $endAt = new DateTimeImmutable($reservationDate . ' ' . $endTime, $timezone);
    $now = new DateTimeImmutable('now', $timezone);
    $occupiedUntil = $table['occupied_until'] ?? null;
    if (($table['status'] ?? '') === 'Digunakan') {
        if (!is_string($occupiedUntil) || $occupiedUntil === '') {
            return 'occupied';
        }
        $occupiedAt = new DateTimeImmutable($occupiedUntil, $timezone);
        if ($startAt < $occupiedAt && $endAt > $now) {
            return 'occupied';
        }
    } elseif (is_string($occupiedUntil) && $occupiedUntil !== '') {
        $occupiedAt = new DateTimeImmutable($occupiedUntil, $timezone);
        if ($startAt < $occupiedAt && $endAt > $now) {
            return 'occupied';
        }
    }

    $reservationQuery = $pdo->prepare(
        "SELECT 1
         FROM reservations r
         WHERE r.table_id = :table_id
           AND r.reservation_date = :reservation_date
           AND r.start_time < :requested_end
           AND r.end_time > :requested_start
           AND (
               r.status = 'Confirmed'
               OR (
                   r.status = 'Waiting for DP'
                   AND EXISTS (
                       SELECT 1
                       FROM payments p
                       WHERE p.payable_type = 'reservation_dp'
                         AND p.payable_id = r.id
                         AND (
                             p.status = 'Waiting Verification'
                             OR (p.status IN ('Pending', 'Rejected') AND p.expires_at > NOW())
                         )
                   )
               )
           )
         LIMIT 1"
    );
    $reservationQuery->execute([
        'table_id' => (int) $table['id'],
        'reservation_date' => $reservationDate,
        'requested_end' => $endAt->format('H:i:s'),
        'requested_start' => $startAt->format('H:i:s'),
    ]);
    if ($reservationQuery->fetchColumn() !== false) {
        return 'reservation';
    }

    $walkinQuery = $pdo->prepare(
        "SELECT 1
         FROM walkin_requests w
         WHERE w.table_id = :table_id
           AND w.status IN ('Pending', 'Approved')
           AND DATE(w.created_at) = :request_date
           AND w.created_at < :requested_end
           AND DATE_ADD(w.created_at, INTERVAL 2 HOUR) > :requested_start
           AND (w.expires_at IS NULL OR w.expires_at > NOW())
         LIMIT 1"
    );
    $walkinQuery->execute([
        'table_id' => (int) $table['id'],
        'request_date' => $reservationDate,
        'requested_end' => $endAt->format('Y-m-d H:i:s'),
        'requested_start' => $startAt->format('Y-m-d H:i:s'),
    ]);
    if ($walkinQuery->fetchColumn() !== false) {
        return 'walkin';
    }

    return null;
}

function cozycafe_reservation_booking_code(): string
{
    return 'CCB-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function cozycafe_reservation_payment_code(): string
{
    return 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function cozycafe_create_reservation(
    PDO $pdo,
    int $customerId,
    int $tableId,
    string $reservationDate,
    string $startTime,
    int $durationMinutes,
    int $guestCount
): array {
    if ($customerId < 1 || $tableId < 1 || $guestCount < 1) {
        throw new CozyCafeReservationException('error.reservation_invalid_request');
    }
    $slot = cozycafe_reservation_validate_slot($reservationDate, $startTime, $durationMinutes);

    return cozycafe_with_transaction($pdo, static function () use (
        $pdo,
        $customerId,
        $tableId,
        $guestCount,
        $slot
    ): array {
        $tableQuery = $pdo->prepare(
            'SELECT id, table_number, capacity, status, occupied_until
             FROM `tables`
             WHERE id = :table_id
             FOR UPDATE'
        );
        $tableQuery->execute(['table_id' => $tableId]);
        $table = $tableQuery->fetch();
        if (!is_array($table)) {
            throw new CozyCafeReservationException('error.reservation_table_missing');
        }

        $conflict = cozycafe_reservation_table_conflict_reason(
            $pdo,
            $table,
            $slot['date'],
            $slot['start_time'],
            $slot['end_time'],
            $guestCount
        );
        if ($conflict !== null) {
            throw new CozyCafeReservationException(
                $conflict === 'capacity'
                    ? 'error.reservation_guest_count_invalid'
                    : 'error.reservation_slot_unavailable'
            );
        }

        $bookingCode = cozycafe_reservation_booking_code();
        $insertReservation = $pdo->prepare(
            "INSERT INTO reservations
                (booking_code, user_id, table_id, reservation_date, start_time, end_time,
                 duration_minutes, guest_count, dp_amount, status)
             VALUES
                (:booking_code, :user_id, :table_id, :reservation_date, :start_time, :end_time,
                 :duration_minutes, :guest_count, 50000.00, 'Waiting for DP')"
        );
        $insertReservation->execute([
            'booking_code' => $bookingCode,
            'user_id' => $customerId,
            'table_id' => $tableId,
            'reservation_date' => $slot['date'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'duration_minutes' => $slot['duration_minutes'],
            'guest_count' => $guestCount,
        ]);
        $reservationId = (int) $pdo->lastInsertId();

        $insertPayment = $pdo->prepare(
            "INSERT INTO payments
                (payment_code, payable_type, payable_id, payment_method, amount, status, expires_at)
             VALUES
                (:payment_code, 'reservation_dp', :payable_id, 'qris', 50000.00, 'Pending',
                 DATE_ADD(NOW(), INTERVAL 15 MINUTE))"
        );
        $insertPayment->execute([
            'payment_code' => cozycafe_reservation_payment_code(),
            'payable_id' => $reservationId,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        log_reservation_status(
            $pdo,
            $reservationId,
            null,
            'Waiting for DP',
            'customer',
            $customerId,
            'Reservation created and awaiting DP verification.'
        );
        create_notification(
            $pdo,
            'admin',
            null,
            __('notification.admin.new_reservation'),
            __('notification.reservation_admin_message', [
                'booking_code' => $bookingCode,
                'table_number' => (string) $table['table_number'],
            ])
        );
        create_notification(
            $pdo,
            'customer',
            $customerId,
            __('notification.customer.reservation_created'),
            __('notification.reservation_customer_message', ['booking_code' => $bookingCode]),
            cozycafe_app_path('customer/reservations/index.php')
        );

        $deadlineQuery = $pdo->prepare('SELECT expires_at FROM payments WHERE id = :payment_id');
        $deadlineQuery->execute(['payment_id' => $paymentId]);
        $paymentDeadline = $deadlineQuery->fetchColumn();
        if (!is_string($paymentDeadline)) {
            throw new RuntimeException('Unable to load the reservation payment deadline.');
        }

        return [
            'id' => $reservationId,
            'booking_code' => $bookingCode,
            'table_number' => (string) $table['table_number'],
            'reservation_date' => $slot['date'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'dp_amount' => '50000.00',
            'payment_deadline' => $paymentDeadline,
        ];
    });
}

function cozycafe_create_walkin_request(
    PDO $pdo,
    ?int $customerId,
    int $tableId,
    string $guestName,
    string $guestPhone,
    int $guestCount
): array {
    if ($tableId < 1 || $guestCount < 1 || ($customerId !== null && $customerId < 1)) {
        throw new CozyCafeReservationException('error.walkin_invalid_request');
    }

    $nameLength = preg_match_all('/./us', trim($guestName));
    if (
        $nameLength === false
        || $nameLength < 2
        || $nameLength > 150
        || preg_match('/[\x00-\x1F\x7F]/u', $guestName) === 1
    ) {
        throw new CozyCafeReservationException('error.checkout_name_invalid');
    }
    try {
        $guestPhone = normalize_indonesian_phone($guestPhone);
    } catch (InvalidArgumentException) {
        throw new CozyCafeReservationException('error.checkout_phone_invalid');
    }

    $timezone = new DateTimeZone('Asia/Jakarta');
    $now = new DateTimeImmutable('now', $timezone);
    $opening = new DateTimeImmutable($now->format('Y-m-d') . ' 09:00:00', $timezone);
    $latestStart = new DateTimeImmutable($now->format('Y-m-d') . ' 20:00:00', $timezone);
    $end = $now->modify('+120 minutes');
    if ($now < $opening || $now > $latestStart || $end->format('Y-m-d') !== $now->format('Y-m-d')) {
        throw new CozyCafeReservationException('error.walkin_outside_hours');
    }

    return cozycafe_with_transaction($pdo, static function () use (
        $pdo,
        $customerId,
        $tableId,
        $guestName,
        $guestPhone,
        $guestCount,
        $now,
        $end
    ): array {
        $tableQuery = $pdo->prepare(
            'SELECT id, table_number, capacity, status, occupied_until
             FROM `tables`
             WHERE id = :table_id
             FOR UPDATE'
        );
        $tableQuery->execute(['table_id' => $tableId]);
        $table = $tableQuery->fetch();
        if (!is_array($table)) {
            throw new CozyCafeReservationException('error.reservation_table_missing');
        }
        if ($table['status'] !== 'Tersedia') {
            throw new CozyCafeReservationException('error.walkin_table_unavailable');
        }
        if ($guestCount > (int) $table['capacity']) {
            throw new CozyCafeReservationException('error.reservation_guest_count_invalid');
        }

        $startTime = $now->format('H:i:s');
        $endTime = $end->format('H:i:s');
        $conflict = cozycafe_reservation_table_conflict_reason(
            $pdo,
            $table,
            $now->format('Y-m-d'),
            $startTime,
            $endTime,
            $guestCount
        );
        if ($conflict !== null) {
            throw new CozyCafeReservationException('error.walkin_table_unavailable');
        }

        $pendingQuery = $pdo->prepare(
            "SELECT 1
             FROM walkin_requests
             WHERE table_id = :table_id
               AND status IN ('Pending', 'Approved')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1"
        );
        $pendingQuery->execute(['table_id' => $tableId]);
        if ($pendingQuery->fetchColumn() !== false) {
            throw new CozyCafeReservationException('error.walkin_request_pending');
        }

        $insertRequest = $pdo->prepare(
            "INSERT INTO walkin_requests
                (table_id, user_id, guest_name, guest_phone, guest_count, status)
             VALUES
                (:table_id, :user_id, :guest_name, :guest_phone, :guest_count, 'Pending')"
        );
        $insertRequest->execute([
            'table_id' => $tableId,
            'user_id' => $customerId,
            'guest_name' => trim($guestName),
            'guest_phone' => $guestPhone,
            'guest_count' => $guestCount,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        create_notification(
            $pdo,
            'admin',
            null,
            __('notification.admin.walkin_request'),
            __('notification.walkin_admin_message', [
                'table_number' => (string) $table['table_number'],
                'guest_name' => trim($guestName),
            ])
        );
        if ($customerId !== null) {
            create_notification(
                $pdo,
                'customer',
                $customerId,
                __('notification.walkin_customer_title'),
                __('notification.walkin_customer_message', ['table_number' => (string) $table['table_number']]),
                cozycafe_app_path('customer/walkin.php')
            );
        }

        return [
            'id' => $requestId,
            'table_number' => (string) $table['table_number'],
            'guest_name' => trim($guestName),
            'guest_phone' => $guestPhone,
            'guest_count' => $guestCount,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
    });
}
