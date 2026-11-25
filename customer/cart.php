<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cart_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'storefront.php';
start_secure_session();

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
$cart = cozycafe_cart_get($pdo, $customerId);
$errors = [];
$mergeAdjusted = ($_SESSION['_cart_merge_adjusted'] ?? false) === true;
unset($_SESSION['_cart_merge_adjusted']);

try {
    $totals = cozycafe_cart_totals($cart['items']);
} catch (CozyCafeCartException $exception) {
    $errors[] = __($exception->translationKey, $exception->replacements);
    $totals = cozycafe_cart_totals([]);
}

$hasUnavailableItems = false;
foreach ($cart['items'] as $item) {
    if ((int) $item['is_available'] !== 1 || (int) $item['stock'] < (int) $item['quantity']) {
        $hasUnavailableItems = true;
    }
}
$pageTitle = __('cart.title');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('cart.eyebrow')) ?></p>
        <h1><?= e(__('cart.title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('cart.intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
                <p class="mb-1"><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($mergeAdjusted): ?>
        <div class="alert alert-warning" role="status"><?= e(__('cart.merge_adjusted')) ?></div>
    <?php endif; ?>
    <?php if ((int) $cart['invalid_item_count'] > 0): ?>
        <div class="alert alert-warning" role="status"><?= e(__('cart.invalid_items')) ?></div>
    <?php endif; ?>
    <?php if ($cart['items'] === []): ?>
        <div class="empty-state empty-state--large">
            <h2 class="h3"><?= e(__('cart.empty_title')) ?></h2>
            <p><?= e(__('cart.empty_intro')) ?></p>
            <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('storefront.view_menu')) ?></a>
        </div>
    <?php else: ?>
        <div class="row g-4 align-items-start">
            <div class="col-12 col-lg-8">
                <div class="d-grid gap-3">
                    <?php foreach ($cart['items'] as $item): ?>
                        <?php
                        $productId = (int) $item['product_id'];
                        $quantity = (int) $item['quantity'];
                        $stock = (int) $item['stock'];
                        $available = (int) $item['is_available'] === 1 && $stock > 0;
                        ?>
                        <article class="cart-item-card">
                            <img
                                class="cart-item-card__image"
                                src="<?= e(cozycafe_storefront_image(
                                    isset($item['image']) && is_string($item['image']) ? $item['image'] : null,
                                    'product'
                                )) ?>"
                                alt=""
                                loading="lazy"
                            >
                            <div class="cart-item-card__details">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <h2 class="h5 mb-1"><?= e($item['name']) ?></h2>
                                        <p class="text-secondary mb-1"><?= e(format_rupiah((string) $item['price'])) ?> <?= e(__('cart.each')) ?></p>
                                    </div>
                                    <strong class="cart-item-card__total">
                                        <?= e(format_rupiah((float) $item['price'] * $quantity)) ?>
                                    </strong>
                                </div>
                                <?php if (!$available): ?>
                                    <p class="small text-danger mb-2"><?= e(__('cart.product_unavailable')) ?></p>
                                <?php elseif ($quantity > $stock): ?>
                                    <p class="small text-danger mb-2">
                                        <?= e(__('cart.stock_changed', ['count' => $stock])) ?>
                                    </p>
                                <?php endif; ?>
                                <form
                                    class="cart-item-form"
                                    method="post"
                                    action="<?= e(cozycafe_app_path('customer/cart_action.php')) ?>"
                                    data-cart-update-form
                                >
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?= e($productId) ?>">
                                    <label class="form-label small mb-1" for="quantity-<?= e($productId) ?>"><?= e(__('label.quantity')) ?></label>
                                    <div class="cart-quantity">
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-quantity-step="-1" aria-label="<?= e(__('cart.decrease_quantity')) ?>">−</button>
                                        <input
                                            class="form-control form-control-sm"
                                            id="quantity-<?= e($productId) ?>"
                                            name="quantity"
                                            type="number"
                                            min="1"
                                            max="<?= e(max(1, $stock)) ?>"
                                            value="<?= e($quantity) ?>"
                                            required
                                        >
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-quantity-step="1" aria-label="<?= e(__('cart.increase_quantity')) ?>">+</button>
                                    </div>
                                    <label class="form-label small mt-2 mb-1" for="notes-<?= e($productId) ?>"><?= e(__('storefront.item_notes')) ?></label>
                                    <input
                                        class="form-control form-control-sm"
                                        id="notes-<?= e($productId) ?>"
                                        name="item_notes"
                                        type="text"
                                        maxlength="255"
                                        value="<?= e($item['item_notes'] ?? '') ?>"
                                    >
                                    <div class="cart-item-actions mt-2">
                                        <button class="btn btn-sm btn-outline-cozy" type="submit"><?= e(__('cart.update')) ?></button>
                                        <button class="btn btn-sm btn-link text-danger" type="button" data-cart-remove><?= e(__('cart.remove')) ?></button>
                                    </div>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <form
                    class="cart-notes-card mt-4"
                    method="post"
                    action="<?= e(cozycafe_app_path('customer/cart_action.php')) ?>"
                    data-cart-notes-form
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_order_notes">
                    <label class="form-label fw-semibold" for="order-notes"><?= e(__('cart.order_notes')) ?></label>
                    <textarea
                        class="form-control"
                        id="order-notes"
                        name="order_notes"
                        rows="3"
                        maxlength="5000"
                        placeholder="<?= e(__('cart.order_notes_placeholder')) ?>"
                    ><?= e($cart['order_notes']) ?></textarea>
                    <button class="btn btn-outline-cozy btn-sm mt-2" type="submit"><?= e(__('button.save')) ?></button>
                </form>
            </div>
            <aside class="col-12 col-lg-4">
                <section class="cart-summary-card">
                    <h2 class="h4"><?= e(__('cart.summary')) ?></h2>
                    <dl class="cart-summary-list">
                        <div>
                            <dt><?= e(__('label.subtotal')) ?></dt>
                            <dd data-cart-subtotal><?= e(format_rupiah($totals['subtotal'])) ?></dd>
                        </div>
                        <div>
                            <dt><?= e(__('label.tax')) ?> (10%)</dt>
                            <dd data-cart-tax><?= e(format_rupiah($totals['tax_amount'])) ?></dd>
                        </div>
                        <div class="cart-summary-list__total">
                            <dt><?= e(__('label.total')) ?></dt>
                            <dd data-cart-total><?= e(format_rupiah($totals['total_amount'])) ?></dd>
                        </div>
                    </dl>
                    <?php if ($cart['invalid_item_count'] > 0 || $hasUnavailableItems || $errors !== []): ?>
                        <button class="btn btn-cozy w-100" type="button" disabled><?= e(__('cart.checkout')) ?></button>
                        <p class="small text-danger mt-2 mb-0"><?= e(__('cart.resolve_stock_before_checkout')) ?></p>
                    <?php else: ?>
                        <a class="btn btn-cozy w-100" href="<?= e(cozycafe_app_path('customer/checkout.php')) ?>"><?= e(__('cart.checkout')) ?></a>
                    <?php endif; ?>
                    <a class="btn btn-link w-100 mt-2" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('cart.continue_shopping')) ?></a>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
