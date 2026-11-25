<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';
$footerCustomerId = $_SESSION['customer_id'] ?? null;
$footerAccountPath = (
    (is_int($footerCustomerId) || (is_string($footerCustomerId) && ctype_digit($footerCustomerId)))
    && (int) $footerCustomerId > 0
)
    ? 'customer/profile.php'
    : 'auth/login.php';
?>
</main>
<footer class="site-footer">
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-md-5">
                <a class="footer-brand" href="<?= e(cozycafe_app_path()) ?>">CozyCafe</a>
                <p class="mb-1"><?= e(__('storefront.footer_address_pending')) ?></p>
                <p class="mb-0"><?= e(__('storefront.operating_hours')) ?></p>
            </div>
            <div class="col-6 col-md-3">
                <h2 class="h6 footer-heading"><?= e(__('storefront.quick_links')) ?></h2>
                <ul class="list-unstyled footer-links">
                    <li><a href="<?= e(cozycafe_app_path()) ?>"><?= e(__('nav.home')) ?></a></li>
                    <li><a href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('nav.menu')) ?></a></li>
                    <li><a href="<?= e(cozycafe_app_path($footerAccountPath)) ?>"><?= e(__('nav.account')) ?></a></li>
                </ul>
            </div>
            <div class="col-6 col-md-4">
                <h2 class="h6 footer-heading"><?= e(__('storefront.contact')) ?></h2>
                <p class="mb-0"><?= e(__('storefront.contact_pending')) ?></p>
            </div>
        </div>
        <div class="footer-bottom mt-4 pt-3">
            <small>&copy; <?= e(date('Y')) ?> CozyCafe. <?= e(__('storefront.rights_reserved')) ?></small>
        </div>
    </div>
</footer>
<div class="notification-toast" data-notification-toast role="status" aria-live="polite" hidden>
    <strong data-notification-toast-title></strong>
    <span data-notification-toast-message></span>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(cozycafe_app_path('assets/js/cart.js')) ?>" defer></script>
<script src="<?= e(cozycafe_app_path('assets/js/checkout.js')) ?>" defer></script>
<script src="<?= e(cozycafe_app_path('assets/js/notifications.js')) ?>" defer></script>
</body>
</html>
