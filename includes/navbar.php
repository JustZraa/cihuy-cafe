<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'storefront.php';

$sessionCustomerId = $_SESSION['customer_id'] ?? null;
$customerId = (is_int($sessionCustomerId) || (is_string($sessionCustomerId) && ctype_digit($sessionCustomerId)))
    && (int) $sessionCustomerId > 0
    ? (int) $sessionCustomerId
    : null;
$cartCount = 0;
$notificationCount = 0;

if (isset($pdo) && $pdo instanceof PDO && $customerId !== null) {
    $cartQuery = $pdo->prepare(
        'SELECT COALESCE(SUM(ci.quantity), 0)
         FROM carts c
         INNER JOIN cart_items ci ON ci.cart_id = c.id
         WHERE c.user_id = :user_id'
    );
    $cartQuery->execute(['user_id' => $customerId]);
    $cartCount = (int) $cartQuery->fetchColumn();

    $notificationQuery = $pdo->prepare(
        "SELECT COUNT(*)
         FROM notifications
         WHERE recipient_type = 'customer'
           AND recipient_id = :recipient_id
           AND is_read = 0"
    );
    $notificationQuery->execute(['recipient_id' => $customerId]);
    $notificationCount = (int) $notificationQuery->fetchColumn();
} elseif (isset($_SESSION['guest_cart']) && is_array($_SESSION['guest_cart'])) {
    foreach ($_SESSION['guest_cart'] as $cartKey => $cartItem) {
        if ($cartKey === '_order_notes') {
            continue;
        }
        if (is_array($cartItem) && isset($cartItem['quantity']) && is_numeric($cartItem['quantity'])) {
            $cartCount += max(0, (int) $cartItem['quantity']);
        } elseif (is_numeric($cartItem)) {
            $cartCount += max(0, (int) $cartItem);
        }
    }
}

$cartRouteAvailable = cozycafe_storefront_route_available('customer/cart.php');
$reservationRouteAvailable = cozycafe_storefront_route_available('customer/reservations/index.php');
$walkinRouteAvailable = cozycafe_storefront_route_available('customer/walkin.php');
$trackingRouteAvailable = cozycafe_storefront_route_available('customer/track-order.php');
$dashboardRouteAvailable = cozycafe_storefront_route_available('customer/dashboard.php');
$requestUri = $_SERVER['REQUEST_URI'] ?? cozycafe_app_path();
if (!is_string($requestUri) || $requestUri === '' || !str_starts_with($requestUri, '/')) {
    $requestUri = cozycafe_app_path();
}
?>
<header class="site-header">
    <nav class="navbar navbar-expand-lg navbar-dark cozy-navbar" aria-label="<?= e(__('nav.home')) ?>">
        <div class="container">
            <a class="navbar-brand cozy-brand" href="<?= e(cozycafe_app_path()) ?>" aria-label="CozyCafe - <?= e(__('nav.home')) ?>">
                <span class="cozy-brand__mark" aria-hidden="true">C</span>
                <span>CozyCafe</span>
            </a>
            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#cozycafeNavigation"
                aria-controls="cozycafeNavigation"
                aria-expanded="false"
                aria-label="<?= e(__('storefront.toggle_navigation')) ?>"
            >
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="cozycafeNavigation">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(cozycafe_app_path()) ?>"><?= e(__('nav.home')) ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('nav.menu')) ?></a>
                    </li>
                    <li class="nav-item">
                        <?php if ($reservationRouteAvailable): ?>
                            <a class="nav-link" href="<?= e(cozycafe_app_path('customer/reservations/index.php')) ?>"><?= e(__('nav.reservations')) ?></a>
                        <?php else: ?>
                            <span class="nav-link disabled" aria-disabled="true"><?= e(__('nav.reservations')) ?></span>
                        <?php endif; ?>
                    </li>
                    <li class="nav-item">
                        <?php if ($walkinRouteAvailable): ?>
                            <a class="nav-link" href="<?= e(cozycafe_app_path('customer/walkin.php')) ?>"><?= e(__('nav.walkin')) ?></a>
                        <?php else: ?>
                            <span class="nav-link disabled" aria-disabled="true"><?= e(__('nav.walkin')) ?></span>
                        <?php endif; ?>
                    </li>
                    <li class="nav-item">
                        <?php if ($trackingRouteAvailable): ?>
                            <a class="nav-link" href="<?= e(cozycafe_app_path('customer/track-order.php')) ?>"><?= e(__('nav.track_order')) ?></a>
                        <?php else: ?>
                            <span class="nav-link disabled" aria-disabled="true"><?= e(__('nav.track_order')) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php if ($customerId !== null && $dashboardRouteAvailable): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= e(cozycafe_app_path('customer/dashboard.php')) ?>"><?= e(__('nav.dashboard')) ?></a>
                        </li>
                    <?php endif; ?>
                </ul>
                <div class="navbar-tools">
                    <?php if ($customerId !== null): ?>
                        <details
                            class="notification-control"
                            data-notification-root
                            data-notification-api="<?= e(cozycafe_app_path('api/notifications.php')) ?>"
                            data-notification-empty="<?= e(__('notification.empty')) ?>"
                            data-notification-error="<?= e(__('notification.error')) ?>"
                            data-notification-mark-read="<?= e(__('button.mark_read')) ?>"
                        >
                            <summary class="nav-icon-link" aria-label="<?= e(__('nav.notifications')) ?>" title="<?= e(__('nav.notifications')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path>
                            </svg>
                            <span class="nav-count" data-notification-count aria-live="polite"<?= $notificationCount > 0 ? '' : ' hidden' ?>><?= e($notificationCount > 99 ? '99+' : $notificationCount) ?></span>
                            </summary>
                            <div class="notification-dropdown">
                                <div class="notification-dropdown__heading"><?= e(__('nav.notifications')) ?></div>
                                <ul class="notification-list" data-notification-list>
                                    <li class="notification-empty"><?= e(__('common.loading')) ?></li>
                                </ul>
                                <a class="notification-dropdown__all" href="<?= e(cozycafe_app_path('customer/profile.php')) ?>"><?= e(__('notification.view_account')) ?></a>
                            </div>
                        </details>
                    <?php endif; ?>
                    <?php if ($cartRouteAvailable): ?>
                        <a
                            class="nav-icon-link"
                            href="<?= e(cozycafe_app_path('customer/cart.php')) ?>"
                            aria-label="<?= e(__('nav.cart')) ?>: <?= e($cartCount) ?>"
                            data-cart-link
                        >
                    <?php else: ?>
                        <span class="nav-icon-link is-disabled" aria-disabled="true" title="<?= e(__('storefront.cart_coming_soon')) ?>">
                    <?php endif; ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 1.9-1.4L22 8H6"></path>
                            <circle cx="10" cy="21" r="1"></circle>
                            <circle cx="18" cy="21" r="1"></circle>
                        </svg>
                        <span class="nav-count" data-cart-count aria-hidden="true"><?= e($cartCount > 99 ? '99+' : $cartCount) ?></span>
                    <?php if ($cartRouteAvailable): ?>
                        </a>
                    <?php else: ?>
                        </span>
                    <?php endif; ?>
                    <a class="nav-account-link" href="<?= e(cozycafe_app_path(
                        $customerId !== null ? 'customer/profile.php' : 'auth/login.php'
                    )) ?>">
                        <?= e($customerId !== null ? __('nav.account') : __('nav.login')) ?>
                    </a>
                    <form class="language-switcher" method="post" action="<?= e(cozycafe_app_path('change-lang.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= e($requestUri) ?>">
                        <label class="visually-hidden" for="storefront-language"><?= e(__('common.language')) ?></label>
                        <select
                            class="form-select form-select-sm"
                            id="storefront-language"
                            name="lang"
                            aria-label="<?= e(__('common.language')) ?>"
                            onchange="this.form.submit()"
                        >
                            <option value="id"<?= $activeLanguage === 'id' ? ' selected' : '' ?>>ID</option>
                            <option value="en"<?= $activeLanguage === 'en' ? ' selected' : '' ?>>EN</option>
                        </select>
                        <noscript><button class="btn btn-sm btn-light mt-1" type="submit"><?= e(__('button.submit')) ?></button></noscript>
                    </form>
                </div>
            </div>
        </div>
    </nav>
</header>
