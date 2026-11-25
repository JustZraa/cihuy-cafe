<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'reservation_service.php';

start_secure_session();
require_customer_login();

$pdo = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
if ($customerId === null) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$customerQuery = $pdo->prepare('SELECT id, is_active FROM users WHERE id = :id LIMIT 1');
$customerQuery->execute(['id' => $customerId]);
$customer = $customerQuery->fetch();
if (!is_array($customer) || (int) $customer['is_active'] !== 1) {
    destroy_secure_session();
    header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
    exit;
}

$timezone = new DateTimeZone('Asia/Jakarta');
$now = new DateTimeImmutable('now', $timezone);
$currentMinutes = ((int) $now->format('H') * 60) + (int) $now->format('i');
$defaultMinutes = max(9 * 60, (intdiv($currentMinutes, 30) + 1) * 30);
$defaultDate = $now->format('Y-m-d');
if ($defaultMinutes > 21 * 60) {
    $defaultDate = $now->modify('+1 day')->format('Y-m-d');
    $defaultMinutes = 9 * 60;
}
$defaultStart = sprintf('%02d:%02d', intdiv($defaultMinutes, 60), $defaultMinutes % 60);

$readGetString = static function (string $key, string $fallback): string {
    $value = $_GET[$key] ?? null;
    return is_string($value) ? trim($value) : $fallback;
};
$reservationDate = $readGetString('date', $defaultDate);
$startTime = $readGetString('start_time', $defaultStart);
$durationInput = $readGetString('duration_minutes', '60');
$guestCountInput = $readGetString('guest_count', '2');
$errors = [];
$slot = null;
$durationMinutes = 60;
$guestCount = 2;

if (preg_match('/\A[1-9][0-9]{0,2}\z/', $durationInput) === 1) {
    $durationMinutes = (int) $durationInput;
} else {
    $errors[] = __('error.reservation_invalid_duration');
}
if (preg_match('/\A[1-9][0-9]{0,2}\z/', $guestCountInput) === 1) {
    $guestCount = (int) $guestCountInput;
    if ($guestCount > 100) {
        $errors[] = __('error.reservation_invalid_request');
    }
} else {
    $errors[] = __('error.reservation_invalid_request');
}

try {
    $slot = cozycafe_reservation_validate_slot($reservationDate, $startTime, $durationMinutes);
} catch (CozyCafeReservationException $exception) {
    $errors[] = __($exception->translationKey, $exception->replacements);
}

$tableQuery = $pdo->query(
    'SELECT id, table_number, capacity, location_area, status, occupied_until
     FROM `tables`
     ORDER BY table_number ASC'
);
$tables = $tableQuery->fetchAll();
$availableCount = 0;
$tableReasons = [];
if (is_array($slot) && $errors === []) {
    foreach ($tables as $table) {
        $reason = cozycafe_reservation_table_conflict_reason(
            $pdo,
            $table,
            $slot['date'],
            $slot['start_time'],
            $slot['end_time'],
            $guestCount
        );
        $tableReasons[(int) $table['id']] = $reason;
        if ($reason === null) {
            $availableCount++;
        }
    }
}

$bookingsQuery = $pdo->prepare(
    'SELECT r.id, r.booking_code, r.reservation_date, r.start_time, r.end_time,
            r.guest_count, r.dp_amount, r.status, t.table_number
     FROM reservations r
     INNER JOIN `tables` t ON t.id = r.table_id
     WHERE r.user_id = :user_id
     ORDER BY r.created_at DESC
     LIMIT 8'
);
$bookingsQuery->execute(['user_id' => $customerId]);
$bookings = $bookingsQuery->fetchAll();

$createdReservation = null;
if (
    ($_GET['created'] ?? '') === '1'
    && isset($_SESSION['_reservation_created'])
    && is_array($_SESSION['_reservation_created'])
) {
    $createdReservation = $_SESSION['_reservation_created'];
    unset($_SESSION['_reservation_created']);
}

$durationOptions = range(30, 780, 30);
$reservationStatusKeys = [
    'Waiting for DP' => 'status.reservation.waiting_for_dp',
    'Confirmed' => 'status.reservation.confirmed',
    'Cancelled' => 'status.reservation.cancelled',
    'Completed' => 'status.reservation.completed',
    'No Show' => 'status.reservation.no_show',
    'Expired' => 'status.reservation.expired',
];
$reasonKeys = [
    'capacity' => 'reservation.reason.capacity',
    'inactive' => 'reservation.reason.inactive',
    'occupied' => 'reservation.reason.occupied',
    'reservation' => 'reservation.reason.reserved',
    'walkin' => 'reservation.reason.walkin',
];

$pageTitle = __('reservation.title');
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('reservation.eyebrow')) ?></p>
        <h1><?= e(__('reservation.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('reservation.intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <?php if (is_array($createdReservation)): ?>
        <div class="alert alert-success" role="status">
            <h2 class="h5"><?= e(__('reservation.created_title')) ?></h2>
            <p class="mb-1"><?= e(__('reservation.created_message', [
                'booking_code' => (string) ($createdReservation['booking_code'] ?? ''),
                'table_number' => (string) ($createdReservation['table_number'] ?? ''),
            ])) ?></p>
            <p class="mb-1"><?= e(__('reservation.dp_amount')) ?>: <strong><?= e(format_rupiah('50000.00')) ?></strong></p>
            <?php if (isset($createdReservation['payment_deadline']) && is_string($createdReservation['payment_deadline'])): ?>
                <p class="mb-1"><?= e(__('reservation.dp_deadline')) ?>: <strong><?= e(format_wib($createdReservation['payment_deadline'])) ?></strong></p>
            <?php endif; ?>
            <p class="mb-0"><?= e(__('reservation.dp_next_step')) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
                <p class="mb-1"><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="reservation-filter-card">
        <div class="section-heading">
            <div>
                <h2><?= e(__('reservation.filter_title')) ?></h2>
                <p class="section-intro"><?= e(__('reservation.operating_hours')) ?></p>
            </div>
            <a class="btn btn-outline-cozy" href="<?= e(cozycafe_app_path('customer/walkin.php')) ?>"><?= e(__('walkin.link')) ?></a>
        </div>
        <form method="get" action="<?= e(cozycafe_app_path('customer/reservations/index.php')) ?>" data-reservation-filter>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="reservation-date"><?= e(__('reservation.date')) ?></label>
                    <input class="form-control" type="date" id="reservation-date" name="date" min="<?= e($now->format('Y-m-d')) ?>" value="<?= e($reservationDate) ?>" required>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="reservation-start"><?= e(__('reservation.start_time')) ?></label>
                    <select class="form-select" id="reservation-start" name="start_time" data-reservation-start required>
                        <?php for ($minutes = 9 * 60; $minutes <= 21 * 60; $minutes += 30): ?>
                            <?php $timeOption = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60); ?>
                            <option value="<?= e($timeOption) ?>"<?= $startTime === $timeOption ? ' selected' : '' ?>>
                                <?= e($timeOption) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="reservation-duration"><?= e(__('reservation.duration')) ?></label>
                    <select class="form-select" id="reservation-duration" name="duration_minutes" data-reservation-duration required>
                        <?php foreach ($durationOptions as $minutes): ?>
                            <?php $hours = rtrim(rtrim(number_format($minutes / 60, 1, '.', ''), '0'), '.'); ?>
                            <option value="<?= e($minutes) ?>"<?= $durationMinutes === $minutes ? ' selected' : '' ?>>
                                <?= e(__('reservation.duration_option', ['hours' => $hours])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label" for="reservation-guests"><?= e(__('label.guest_count')) ?></label>
                    <input class="form-control" type="number" id="reservation-guests" name="guest_count" min="1" max="100" value="<?= e($guestCountInput) ?>" required>
                </div>
                <div class="col-12 col-lg-1 d-grid">
                    <button class="btn btn-cozy" type="submit"><?= e(__('reservation.check_availability')) ?></button>
                </div>
            </div>
        </form>
        <?php if (is_array($slot)): ?>
            <p class="reservation-slot-summary mb-0 mt-3">
                <?= e(__('reservation.slot_date_time', [
                    'date' => $slot['start_at']->format('d-m-Y'),
                    'start' => $slot['start_at']->format('H:i'),
                ])) ?>
                <span class="ms-2">
                    <?= e(__('reservation.ends_at')) ?>:
                    <strong data-reservation-end><?= e($slot['end_at']->format('H:i')) ?></strong>
                </span>
                <span class="ms-2"><?= e(__('reservation.available_count', ['count' => $availableCount])) ?></span>
            </p>
        <?php endif; ?>
    </section>

    <section class="mt-5">
        <div class="section-heading">
            <div>
                <h2><?= e(__('reservation.tables_title')) ?></h2>
                <p class="section-intro"><?= e(__('reservation.table_selection_intro')) ?></p>
            </div>
        </div>
        <?php if (!is_array($slot) || $errors !== []): ?>
            <div class="empty-state"><p class="mb-0"><?= e(__('reservation.correct_filter')) ?></p></div>
        <?php elseif ($tables === []): ?>
            <div class="empty-state"><p class="mb-0"><?= e(__('common.no_data')) ?></p></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($tables as $table): ?>
                    <?php
                    $reason = $tableReasons[(int) $table['id']] ?? null;
                    $isAvailable = $reason === null;
                    ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <article class="reservation-table-card<?= $isAvailable ? '' : ' is-unavailable' ?>">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <p class="eyebrow mb-1"><?= e(__('label.table')) ?></p>
                                    <h3 class="h4 mb-1"><?= e($table['table_number']) ?></h3>
                                    <p class="text-secondary mb-0"><?= e($table['location_area']) ?></p>
                                </div>
                                <span class="badge rounded-pill <?= $isAvailable ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= e($isAvailable ? __('reservation.available') : __('reservation.unavailable')) ?>
                                </span>
                            </div>
                            <p class="reservation-table-card__capacity mt-3 mb-3">
                                <?= e(__('reservation.table_capacity', ['count' => (int) $table['capacity']])) ?>
                            </p>
                            <?php if ($isAvailable): ?>
                                <form method="post" action="<?= e(cozycafe_app_path('customer/reservations/create.php')) ?>" class="d-grid">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="table_id" value="<?= e((int) $table['id']) ?>">
                                    <input type="hidden" name="reservation_date" value="<?= e($slot['date']) ?>">
                                    <input type="hidden" name="start_time" value="<?= e(substr($slot['start_time'], 0, 5)) ?>">
                                    <input type="hidden" name="duration_minutes" value="<?= e($slot['duration_minutes']) ?>">
                                    <input type="hidden" name="guest_count" value="<?= e($guestCount) ?>">
                                    <button class="btn btn-outline-cozy" type="submit"><?= e(__('reservation.reserve')) ?></button>
                                </form>
                            <?php else: ?>
                                <p class="small text-danger mb-0">
                                    <?= e(__($reasonKeys[$reason] ?? 'reservation.unavailable_reason')) ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="mt-5">
        <div class="section-heading">
            <div>
                <h2><?= e(__('reservation.my_bookings')) ?></h2>
                <p class="section-intro"><?= e(__('reservation.my_bookings_intro')) ?></p>
            </div>
        </div>
        <?php if ($bookings === []): ?>
            <div class="empty-state"><p class="mb-0"><?= e(__('reservation.bookings_empty')) ?></p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-cozy align-middle">
                    <thead>
                        <tr>
                            <th scope="col"><?= e(__('label.booking_code')) ?></th>
                            <th scope="col"><?= e(__('label.table')) ?></th>
                            <th scope="col"><?= e(__('label.date')) ?></th>
                            <th scope="col"><?= e(__('label.time')) ?></th>
                            <th scope="col"><?= e(__('label.guest_count')) ?></th>
                            <th scope="col"><?= e(__('label.dp')) ?></th>
                            <th scope="col"><?= e(__('label.status')) ?></th>
                            <th scope="col"><span class="visually-hidden"><?= e(__('button.view_details')) ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <?php $statusKey = $reservationStatusKeys[(string) $booking['status']] ?? 'label.status'; ?>
                            <tr>
                                <th scope="row"><?= e($booking['booking_code']) ?></th>
                                <td><?= e($booking['table_number']) ?></td>
                                <td><?= e(format_wib((string) $booking['reservation_date'] . ' ' . (string) $booking['start_time'])) ?></td>
                                <td><?= e(substr((string) $booking['start_time'], 0, 5)) ?>–<?= e(substr((string) $booking['end_time'], 0, 5)) ?></td>
                                <td><?= e((int) $booking['guest_count']) ?></td>
                                <td><?= e(format_rupiah((string) $booking['dp_amount'])) ?></td>
                                <td><?= e(__($statusKey)) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-cozy" href="<?= e(cozycafe_app_path(
                                        'customer/reservations/detail.php?id=' . (int) $booking['id']
                                    )) ?>"><?= e(__('button.view_details')) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
<script src="<?= e(cozycafe_app_path('assets/js/reservations.js')) ?>" defer></script>
<?php require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
