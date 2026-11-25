<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'storefront.php';
start_secure_session();

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$requestedSlug = $_GET['slug'] ?? null;
$slug = is_string($requestedSlug) ? trim($requestedSlug) : '';
$product = null;

if ($slug !== '' && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9-]{0,169}\z/', $slug) === 1) {
    $productQuery = $pdo->prepare(
        'SELECT p.id, p.category_id, p.name, p.slug, p.image, p.description, p.price,
                p.stock, p.is_available, c.name AS category_name
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
         WHERE p.slug = :slug
           AND c.is_active = 1
         LIMIT 1'
    );
    $productQuery->execute(['slug' => $slug]);
    $result = $productQuery->fetch();
    $product = is_array($result) ? $result : null;
}

if ($product === null) {
    http_response_code(404);
}

$inStock = $product !== null
    && (int) $product['stock'] > 0
    && (int) $product['is_available'] === 1;
$pageTitle = $product !== null ? (string) $product['name'] : __('storefront.product_not_found');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="section-space container">
    <?php if ($product === null): ?>
        <div class="empty-state empty-state--large text-center">
            <p class="eyebrow"><?= e(__('storefront.menu_eyebrow')) ?></p>
            <h1 class="h2"><?= e(__('storefront.product_not_found')) ?></h1>
            <p><?= e(__('storefront.product_not_found_intro')) ?></p>
            <a class="btn btn-cozy" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('storefront.back_to_menu')) ?></a>
        </div>
    <?php else: ?>
        <nav class="mb-4" aria-label="<?= e(__('storefront.breadcrumb')) ?>">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= e(cozycafe_app_path()) ?>"><?= e(__('nav.home')) ?></a></li>
                <li class="breadcrumb-item"><a href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('nav.menu')) ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e($product['name']) ?></li>
            </ol>
        </nav>
        <div class="row g-4 g-lg-5 align-items-start product-detail">
            <div class="col-12 col-lg-6">
                <img
                    class="product-detail__image"
                    src="<?= e(cozycafe_storefront_image(
                        isset($product['image']) && is_string($product['image']) ? $product['image'] : null,
                        'product'
                    )) ?>"
                    alt="<?= e($product['name']) ?>"
                >
            </div>
            <div class="col-12 col-lg-6">
                <p class="eyebrow"><?= e($product['category_name']) ?></p>
                <h1><?= e($product['name']) ?></h1>
                <p class="product-detail__price"><?= e(format_rupiah((string) $product['price'])) ?></p>
                <div class="mb-4">
                    <?php if ($inStock): ?>
                        <span class="badge text-bg-success"><?= e(__('storefront.available')) ?></span>
                        <span class="text-secondary ms-2">
                            <?= e(__('storefront.stock_remaining', ['count' => (int) $product['stock']])) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><?= e(__('storefront.sold_out')) ?></span>
                    <?php endif; ?>
                </div>
                <h2 class="h5"><?= e(__('storefront.product_description')) ?></h2>
                <p class="product-detail__description">
                    <?= nl2br(e(
                        isset($product['description']) && is_string($product['description']) && $product['description'] !== ''
                            ? $product['description']
                            : __('storefront.no_description')
                    )) ?>
                </p>
                <form
                    class="product-order-controls"
                    method="post"
                    action="<?= e(cozycafe_app_path('customer/cart_action.php')) ?>"
                    data-cart-form
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= e($product['id']) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="quantity"><?= e(__('label.quantity')) ?></label>
                        <input
                            class="form-control"
                            id="quantity"
                            name="quantity"
                            type="number"
                            min="1"
                            max="<?= e(max(1, (int) $product['stock'])) ?>"
                            value="1"
                            <?= !$inStock ? 'disabled' : '' ?>
                        >
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="item-notes">
                            <?= e(__('storefront.item_notes')) ?>
                            <span class="text-secondary">(<?= e(__('common.optional')) ?>)</span>
                        </label>
                        <textarea
                            class="form-control"
                            id="item-notes"
                            name="item_notes"
                            rows="3"
                            maxlength="255"
                            placeholder="<?= e(__('storefront.item_notes_placeholder')) ?>"
                            <?= !$inStock ? 'disabled' : '' ?>
                        ></textarea>
                    </div>
                    <button class="btn btn-cozy btn-lg w-100" type="submit"<?= !$inStock ? ' disabled' : '' ?>>
                        <?= e(__('button.add_to_cart')) ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
