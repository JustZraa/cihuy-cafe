<?php
declare(strict_types=1);

function admin_url(string $path = ''): string
{
    if (
        str_starts_with($path, '/')
        || str_contains($path, '\\')
        || str_contains($path, '..')
        || preg_match('/[\x00-\x20\x7F]/', $path) === 1
    ) {
        throw new InvalidArgumentException('Admin routes must be safe relative paths.');
    }

    return cozycafe_app_path('admin/' . $path);
}

function admin_redirect(string $path, ?string $flashKey = null, string $flashType = 'success'): never
{
    if (!in_array($flashType, ['success', 'danger', 'warning'], true)) {
        throw new InvalidArgumentException('Unsupported admin flash type.');
    }

    if ($flashKey !== null) {
        $_SESSION['_admin_flash'] = ['key' => $flashKey, 'type' => $flashType];
    }

    header('Location: ' . admin_url($path), true, 303);
    exit;
}

function admin_take_flash(): ?array
{
    $flash = $_SESSION['_admin_flash'] ?? null;
    unset($_SESSION['_admin_flash']);

    if (
        !is_array($flash)
        || !isset($flash['key'], $flash['type'])
        || !is_string($flash['key'])
        || !is_string($flash['type'])
        || !in_array($flash['type'], ['success', 'danger', 'warning'], true)
    ) {
        return null;
    }

    return ['key' => $flash['key'], 'type' => $flash['type']];
}

function admin_render_flash(?array $flash): void
{
    if ($flash === null) {
        return;
    }
    ?>
    <div class="alert alert-<?= e($flash['type']) ?> mb-4" role="status"><?= e(__($flash['key'])) ?></div>
    <?php
}

function admin_transaction(PDO $pdo, callable $operation): mixed
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $result = $operation();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function admin_layout_start(PDO $pdo, string $title, string $active): void
{
    start_secure_session();
    $adminName = isset($GLOBALS['adminName']) && is_string($GLOBALS['adminName'])
        ? $GLOBALS['adminName']
        : '';
    $activeAdminLanguage = $_SESSION['lang'] ?? 'id';
    if (!is_string($activeAdminLanguage) || !in_array($activeAdminLanguage, ['id', 'en'], true)) {
        $activeAdminLanguage = 'id';
    }
    $pendingPaymentCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM payments WHERE status = 'Waiting Verification'"
    )->fetchColumn();
    $pendingWalkinCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM walkin_requests WHERE status = 'Pending'"
    )->fetchColumn();
    $pageTitle = $title;
    $adminActive = $active;
    $adminBadges = [
        'payments' => $pendingPaymentCount,
        'walkins' => $pendingWalkinCount,
    ];
    ?>
    <!doctype html>
    <html lang="<?= e($activeAdminLanguage) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#5C3A21">
        <meta name="csrf-token" content="<?= e(generate_csrf_token()) ?>">
        <title><?= e($pageTitle) ?> | CozyCafe Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="<?= e(cozycafe_app_path('assets/css/style.css')) ?>" rel="stylesheet">
        <link href="<?= e(cozycafe_app_path('assets/css/admin.css')) ?>" rel="stylesheet">
    </head>
    <body class="admin-body">
    <div class="admin-shell">
        <?php require __DIR__ . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        <div class="admin-content">
            <?php require __DIR__ . DIRECTORY_SEPARATOR . 'topbar.php'; ?>
            <main class="admin-main container-fluid">
                <div class="admin-page-heading">
                    <div>
                        <p class="eyebrow mb-1"><?= e(__('admin.panel')) ?></p>
                        <h1 class="h2 mb-0"><?= e($pageTitle) ?></h1>
                    </div>
                </div>
                <?php admin_render_flash(admin_take_flash()); ?>
    <?php
}

function admin_layout_end(): void
{
    ?>
            </main>
            <footer class="admin-footer container-fluid">
                <span>CozyCafe</span>
                <span><?= e(__('admin.footer_note')) ?></span>
            </footer>
        </div>
    </div>
    <div class="notification-toast" data-notification-toast role="status" aria-live="polite" hidden>
        <strong data-notification-toast-title></strong>
        <span data-notification-toast-message></span>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= e(cozycafe_app_path('assets/js/notifications.js')) ?>" defer></script>
    </body>
    </html>
    <?php
}

function admin_status_label(string $status, string $type = 'order'): string
{
    $key = match ($type) {
        'order' => [
            'Pending' => 'status.order.pending',
            'Waiting for Payment' => 'status.order.waiting_for_payment',
            'Paid' => 'status.order.paid',
            'Processing' => 'status.order.processing',
            'Ready' => 'status.order.ready',
            'Completed' => 'status.order.completed',
            'Cancelled' => 'status.order.cancelled',
        ][$status] ?? null,
        'reservation' => [
            'Waiting for DP' => 'status.reservation.waiting_for_dp',
            'Confirmed' => 'status.reservation.confirmed',
            'Cancelled' => 'status.reservation.cancelled',
            'Completed' => 'status.reservation.completed',
            'No Show' => 'status.reservation.no_show',
            'Expired' => 'status.reservation.expired',
        ][$status] ?? null,
        'payment' => [
            'Pending' => 'status.payment.pending',
            'Waiting Verification' => 'status.payment.waiting_verification',
            'Verified' => 'status.payment.verified',
            'Rejected' => 'status.payment.rejected',
            'Expired' => 'status.payment.expired',
            'Refunded' => 'status.payment.refunded',
        ][$status] ?? null,
        'table' => [
            'Tersedia' => 'status.table.available',
            'Digunakan' => 'status.table.occupied',
            'Tidak Aktif' => 'status.table.inactive',
        ][$status] ?? null,
        'walkin' => [
            'Pending' => 'status.walkin.pending',
            'Approved' => 'status.walkin.approved',
            'Rejected' => 'status.walkin.rejected',
            'Completed' => 'status.walkin.completed',
        ][$status] ?? null,
        default => null,
    };
    return $key === null ? $status : __($key);
}

function admin_status_badge(string $status, string $type = 'order'): string
{
    $class = match ($status) {
        'Paid', 'Verified', 'Confirmed', 'Ready', 'Completed', 'Approved', 'Tersedia' => 'success',
        'Waiting for Payment', 'Waiting for DP', 'Waiting Verification', 'Pending', 'Processing', 'Digunakan' => 'warning',
        'Cancelled', 'Rejected', 'Expired', 'No Show', 'Tidak Aktif', 'Refunded' => 'secondary',
        default => 'light',
    };

    return '<span class="badge text-bg-' . e($class) . '">' . e(admin_status_label($status, $type)) . '</span>';
}

function admin_notify_customer(
    PDO $pdo,
    int $customerId,
    string $title,
    string $message,
    string $relativePath
): void {
    if ($customerId < 1) {
        return;
    }
    create_notification(
        $pdo,
        'customer',
        $customerId,
        $title,
        $message,
        cozycafe_app_path($relativePath)
    );
}

function admin_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '') {
        throw new InvalidArgumentException(__('admin.error.slug_invalid'));
    }
    return substr($value, 0, 160);
}

function admin_image_path(string $type, string $fileName): string
{
    if (!in_array($type, ['products', 'banners'], true) || basename($fileName) !== $fileName) {
        throw new InvalidArgumentException('Invalid admin image path.');
    }
    return 'uploads/' . $type . '/' . $fileName;
}

function admin_delete_uploaded_image(?string $relativePath): void
{
    if (
        !is_string($relativePath)
        || preg_match('/\Auploads\/(products|banners)\/([a-f0-9]{32}\.(?:jpg|png|webp))\z/', $relativePath, $matches) !== 1
    ) {
        return;
    }
    $base = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $matches[1]);
    $file = $base === false
        ? false
        : realpath($base . DIRECTORY_SEPARATOR . $matches[2]);
    if ($base !== false && $file !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
        if (!unlink($file)) {
            throw new RuntimeException('An unused admin image could not be removed.');
        }
    }
}
