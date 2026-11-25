<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'reservation_service.php';

start_secure_session();
$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
$customer = null;
if ($customerId !== null) {
    $customerQuery = $pdo->prepare('SELECT id, full_name, phone, is_active FROM users WHERE id = :id LIMIT 1');
    $customerQuery->execute(['id' => $customerId]);
    $customerResult = $customerQuery->fetch();
    if (!is_array($customerResult) || (int) $customerResult['is_active'] !== 1) {
        destroy_secure_session();
        header('Location: ' . cozycafe_app_path('auth/login.php'), true, 303);
        exit;
    }
    $customer = $customerResult;
}

$fullName = $customer !== null ? (string) $customer['full_name'] : '';
$phone = $customer !== null ? (string) $customer['phone'] : '';
$guestCountInput = '2';
$errors = [];
$requestCreated = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $fullName = trim(cozycafe_post_string('guest_name'));
    $phone = trim(cozycafe_post_string('guest_phone'));
    $guestCountInput = trim(cozycafe_post_string('guest_count'));
    if (!verify_csrf_token()) {
        http_response_code(403);
        $errors[] = __('validation.invalid_csrf');
    } else {
        $tableIdInput = $_POST['table_id'] ?? null;
        if (
            !is_string($tableIdInput)
            || preg_match('/\A[1-9][0-9]{0,9}\z/', $tableIdInput) !== 1
            || preg_match('/\A[1-9][0-9]{0,2}\z/', $guestCountInput) !== 1
        ) {
            http_response_code(422);
            $errors[] = __('error.walkin_invalid_request');
        } else {
            try {
                $requestCreated = cozycafe_create_walkin_request(
                    $pdo,
                    $customerId,
                    (int) $tableIdInput,
                    $fullName,
                    $phone,
                    (int) $guestCountInput
                );
                $_SESSION['_walkin_created'] = $requestCreated;
                header('Location: ' . cozycafe_app_path('customer/walkin.php?requested=1'), true, 303);
                exit;
            } catch (CozyCafeReservationException $exception) {
                http_response_code(422);
                $errors[] = __($exception->translationKey, $exception->replacements);
            }
        }
    }
}

if (
    ($_GET['requested'] ?? '') === '1'
    && isset($_SESSION['_walkin_created'])
    && is_array($_SESSION['_walkin_created'])
) {
    $requestCreated = $_SESSION['_walkin_created'];
    unset($_SESSION['_walkin_created']);
}

$timezone = new DateTimeZone('Asia/Jakarta');
$now = new DateTimeImmutable('now', $timezone);
$walkinEnd = $now->modify('+120 minutes');
$walkinOpen = $now->format('H:i:s') >= '09:00:00'
    && $now->format('H:i:s') <= '20:00:00'
    && $walkinEnd->format('Y-m-d') === $now->format('Y-m-d')
    && $walkinEnd->format('H:i:s') <= '22:00:00';

$tablesQuery = $pdo->query(
    'SELECT id, table_number, capacity, location_area, status, occupied_until
     FROM `tables`
     ORDER BY table_number ASC'
);
$tables = $tablesQuery->fetchAll();
$tableReasons = [];
if ($walkinOpen) {
    foreach ($tables as $table) {
        $tableReasons[(int) $table['id']] = cozycafe_reservation_table_conflict_reason(
            $pdo,
            $table,
            $now->format('Y-m-d'),
            $now->format('H:i:s'),
            $walkinEnd->format('H:i:s'),
            1
        );
    }
}

$myRequests = [];
if ($customerId !== null) {
    $myRequestsQuery = $pdo->prepare(
        'SELECT w.id, w.guest_count, w.status, w.created_at, t.table_number
         FROM walkin_requests w
         INNER JOIN `tables` t ON t.id = w.table_id
         WHERE w.user_id = :user_id
         ORDER BY w.created_at DESC
         LIMIT 5'
    );
    $myRequestsQuery->execute(['user_id' => $customerId]);
    $myRequests = $myRequestsQuery->fetchAll();
}

$walkinStatusKeys = [
    'Pending' => 'status.walkin.pending',
    'Approved' => 'status.walkin.approved',
    'Rejected' => 'status.walkin.rejected',
    'Completed' => 'status.walkin.completed',
];
$reasonKeys = [
    'capacity' => 'reservation.reason.capacity',
    'inactive' => 'reservation.reason.inactive',
    'occupied' => 'reservation.reason.occupied',
    'reservation' => 'reservation.reason.reserved',
    'walkin' => 'reservation.reason.walkin',
];

$pageTitle = __('walkin.title');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('walkin.eyebrow')) ?></p>
        <h1><?= e(__('walkin.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('walkin.intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <?php if (is_array($requestCreated)): ?>
        <div class="alert alert-success" role="status">
            <?= e(__('walkin.requested_message', [
                'table_number' => (string) ($requestCreated['table_number'] ?? ''),
                'id' => (int) ($requestCreated['id'] ?? 0),
            ])) ?>
        </div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
                <p class="mb-1"><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info" role="status">
        <?= e(__('walkin.duration_notice', ['end' => $walkinEnd->format('H:i')])) ?>
    </div>
    <?php if (!$walkinOpen): ?>
        <div class="alert alert-warning" role="status"><?= e(__('error.walkin_outside_hours')) ?></div>
    <?php endif; ?>

    <section>
        <div class="section-heading">
            <div>
                <h2><?= e(__('walkin.available_tables')) ?></h2>
                <p class="section-intro"><?= e(__('walkin.table_selection_intro')) ?></p>
            </div>
            <a class="btn btn-outline-cozy" href="<?= e(cozycafe_app_path('customer/reservations/index.php')) ?>"><?= e(__('walkin.reservation_link')) ?></a>
        </div>
        <?php if ($tables === []): ?>
            <div class="empty-state"><p class="mb-0"><?= e(__('common.no_data')) ?></p></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($tables as $table): ?>
                    <?php
                    $reason = $tableReasons[(int) $table['id']] ?? null;
                    $isAvailable = $walkinOpen && $table['status'] === 'Tersedia' && $reason === null;
                    ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <article class="reservation-table-card<?= $isAvailable ? '' : ' is-unavailable' ?>">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <p class="eyebrow mb-1"><?= e(__('label.table')) ?></p>
                                    <h2 class="h4 mb-1"><?= e($table['table_number']) ?></h2>
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
                                <form method="post" action="<?= e(cozycafe_app_path('customer/walkin.php')) ?>" class="walkin-request-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="table_id" value="<?= e((int) $table['id']) ?>">
                                    <div class="mb-2">
                                        <label class="form-label small" for="walkin-name-<?= e((int) $table['id']) ?>"><?= e(__('label.full_name')) ?></label>
                                        <input
                                            class="form-control form-control-sm"
                                            id="walkin-name-<?= e((int) $table['id']) ?>"
                                            name="guest_name"
                                            type="text"
                                            minlength="2"
                                            maxlength="150"
                                            required
                                            value="<?= e($fullName) ?>"
                                        >
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small" for="walkin-phone-<?= e((int) $table['id']) ?>"><?= e(__('label.phone')) ?></label>
                                        <input
                                            class="form-control form-control-sm"
                                            id="walkin-phone-<?= e((int) $table['id']) ?>"
                                            name="guest_phone"
                                            type="tel"
                                            maxlength="20"
                                            required
                                            value="<?= e($phone) ?>"
                                        >
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small" for="walkin-guests-<?= e((int) $table['id']) ?>"><?= e(__('label.guest_count')) ?></label>
                                        <input
                                            class="form-control form-control-sm"
                                            id="walkin-guests-<?= e((int) $table['id']) ?>"
                                            name="guest_count"
                                            type="number"
                                            min="1"
                                            max="<?= e((int) $table['capacity']) ?>"
                                            value="<?= e(min((int) $table['capacity'], max(1, (int) $guestCountInput))) ?>"
                                            required
                                        >
                                    </div>
                                    <button class="btn btn-outline-cozy w-100" type="submit"><?= e(__('walkin.request_table')) ?></button>
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

    <?php if ($myRequests !== []): ?>
        <section class="mt-5">
            <div class="section-heading">
                <div>
                    <h2><?= e(__('walkin.my_requests')) ?></h2>
                    <p class="section-intro"><?= e(__('walkin.my_requests_intro')) ?></p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-cozy align-middle">
                    <thead>
                        <tr>
                            <th scope="col"><?= e(__('walkin.request_number')) ?></th>
                            <th scope="col"><?= e(__('label.table')) ?></th>
                            <th scope="col"><?= e(__('label.guest_count')) ?></th>
                            <th scope="col"><?= e(__('label.date')) ?></th>
                            <th scope="col"><?= e(__('label.status')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myRequests as $request): ?>
                            <?php $statusKey = $walkinStatusKeys[(string) $request['status']] ?? 'label.status'; ?>
                            <tr>
                                <th scope="row">#<?= e((int) $request['id']) ?></th>
                                <td><?= e($request['table_number']) ?></td>
                                <td><?= e((int) $request['guest_count']) ?></td>
                                <td><?= e(format_wib((string) $request['created_at'])) ?></td>
                                <td><?= e(__($statusKey)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
