<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';

function cozycafe_storefront_image(?string $image, string $type): string
{
    if (!in_array($type, ['product', 'banner'], true)) {
        throw new InvalidArgumentException('Unsupported storefront image type.');
    }

    $directory = $type === 'product' ? 'products' : 'banners';
    $fallback = $type === 'product'
        ? 'assets/images/menu-placeholder.svg'
        : 'assets/images/banner-placeholder.svg';

    if (
        is_string($image)
        && preg_match(
            '/\Auploads\/' . preg_quote($directory, '/') . '\/[A-Za-z0-9][A-Za-z0-9._-]{0,248}\.(?:jpe?g|png|webp)\z/i',
            $image
        ) === 1
    ) {
        $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $image);
        if (is_file($filePath)) {
            return cozycafe_app_path($image);
        }
    }

    return cozycafe_app_path($fallback);
}

function cozycafe_storefront_url(?string $url): ?string
{
    if (
        !is_string($url)
        || $url === ''
        || str_starts_with($url, '/')
        || str_contains($url, '\\')
        || preg_match('/[\x00-\x20\x7F]/', $url) === 1
    ) {
        return null;
    }

    $parts = parse_url($url);
    if (
        !is_array($parts)
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || !isset($parts['path'])
        || !is_string($parts['path'])
        || preg_match('/\A(?:index\.php|customer\/[A-Za-z0-9_.\/-]+\.php)\z/', $parts['path']) !== 1
    ) {
        return null;
    }

    foreach (explode('/', $parts['path']) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return null;
        }
    }

    if (isset($parts['query']) && preg_match('/\A[A-Za-z0-9_=&.%+-]*\z/', $parts['query']) !== 1) {
        return null;
    }

    return cozycafe_app_path($url);
}

function cozycafe_storefront_route_available(string $relativePath): bool
{
    if (
        $relativePath === ''
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, '\\')
        || str_contains($relativePath, '..')
    ) {
        throw new InvalidArgumentException('Storefront routes must be safe relative paths.');
    }

    return is_file(
        dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
    );
}

function render_storefront_product_card(array $product): void
{
    $slug = (string) ($product['slug'] ?? '');
    $detailUrl = cozycafe_app_path('customer/product-detail.php?slug=' . rawurlencode($slug));
    $inStock = (int) ($product['stock'] ?? 0) > 0 && (int) ($product['is_available'] ?? 0) === 1;
    ?>
    <article class="card card-cozy product-card h-100">
        <a class="product-card__image-link" href="<?= e($detailUrl) ?>">
            <img
                class="product-card__image"
                src="<?= e(cozycafe_storefront_image(
                    isset($product['image']) && is_string($product['image']) ? $product['image'] : null,
                    'product'
                )) ?>"
                alt="<?= e($product['name'] ?? '') ?>"
                loading="lazy"
            >
        </a>
        <div class="card-body d-flex flex-column">
            <?php if (isset($product['category_name'])): ?>
                <p class="product-card__category mb-2"><?= e($product['category_name']) ?></p>
            <?php endif; ?>
            <h3 class="h5 card-title">
                <a class="product-card__title" href="<?= e($detailUrl) ?>"><?= e($product['name'] ?? '') ?></a>
            </h3>
            <p class="product-card__price mb-3"><?= e(format_rupiah((string) ($product['price'] ?? '0'))) ?></p>
            <?php if ($inStock): ?>
                <span class="badge text-bg-success align-self-start mb-3"><?= e(__('storefront.available')) ?></span>
            <?php else: ?>
                <span class="badge text-bg-secondary align-self-start mb-3"><?= e(__('storefront.sold_out')) ?></span>
            <?php endif; ?>
            <div class="mt-auto d-grid gap-2">
                <a class="btn btn-outline-cozy" href="<?= e($detailUrl) ?>"><?= e(__('button.view_details')) ?></a>
                <form method="post" action="<?= e(cozycafe_app_path('customer/cart_action.php')) ?>" data-cart-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= e($product['id'] ?? 0) ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button class="btn btn-cozy w-100" type="submit"<?= !$inStock ? ' disabled' : '' ?>>
                        <?= e(__('button.add_to_cart')) ?>
                    </button>
                </form>
            </div>
        </div>
    </article>
    <?php
}
