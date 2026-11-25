<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'storefront.php';
start_secure_session();

$pdo = require __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

$bannerQuery = $pdo->query(
    'SELECT title, description, image, button_text, button_url
     FROM banners
     WHERE is_active = 1
       AND start_date <= CURRENT_DATE()
       AND end_date >= CURRENT_DATE()
     ORDER BY sort_order ASC, id ASC'
);
$banners = $bannerQuery->fetchAll();

$categoryQuery = $pdo->query(
    'SELECT id, name, slug
     FROM categories
     WHERE is_active = 1
     ORDER BY sort_order ASC, name ASC'
);
$categories = $categoryQuery->fetchAll();

$featuredQuery = $pdo->query(
    'SELECT p.id, p.category_id, p.name, p.slug, p.image, p.description, p.price,
            p.stock, p.is_available, c.name AS category_name
     FROM products p
     INNER JOIN categories c ON c.id = p.category_id
     WHERE p.is_featured = 1
       AND c.is_active = 1
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT 8'
);
$featuredProducts = $featuredQuery->fetchAll();

$tableAvailabilityQuery = $pdo->query(
    "SELECT
        SUM(CASE WHEN status = 'Tersedia' THEN 1 ELSE 0 END) AS available_count,
        COUNT(*) AS total_count
     FROM `tables`
     WHERE status <> 'Tidak Aktif'"
);
$tableAvailability = $tableAvailabilityQuery->fetch();
$availableTables = is_array($tableAvailability) ? (int) ($tableAvailability['available_count'] ?? 0) : 0;
$totalTables = is_array($tableAvailability) ? (int) ($tableAvailability['total_count'] ?? 0) : 0;

$pageTitle = __('storefront.home_title');
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="hero-section">
    <div class="container">
        <div class="hero-content">
            <p class="eyebrow"><?= e(__('storefront.hero_eyebrow')) ?></p>
            <h1><?= e(__('storefront.hero_title')) ?></h1>
            <p class="hero-copy"><?= e(__('storefront.hero_intro')) ?></p>
            <div class="d-flex flex-wrap gap-3">
                <a class="btn btn-cozy btn-lg" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>">
                    <?= e(__('storefront.view_menu')) ?>
                </a>
                <?php if (cozycafe_storefront_route_available('customer/reservations/index.php')): ?>
                    <a class="btn btn-outline-cozy-light btn-lg" href="<?= e(cozycafe_app_path('customer/reservations/index.php')) ?>">
                        <?= e(__('button.book_table')) ?>
                    </a>
                <?php else: ?>
                    <span class="btn btn-outline-cozy-light btn-lg disabled" aria-disabled="true" title="<?= e(__('storefront.reservations_coming_soon')) ?>">
                        <?= e(__('button.book_table')) ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="hero-hours mt-4 mb-0"><?= e(__('storefront.operating_hours')) ?></p>
        </div>
        <div class="hero-decoration" aria-hidden="true">
            <div class="hero-cup">
                <span class="hero-steam hero-steam--one"></span>
                <span class="hero-steam hero-steam--two"></span>
                <span class="hero-steam hero-steam--three"></span>
                <span class="hero-cup__body"></span>
                <span class="hero-cup__handle"></span>
            </div>
        </div>
    </div>
</section>

<?php if ($banners !== []): ?>
    <section class="section-space container" aria-labelledby="promotions-title">
        <div class="section-heading">
            <div>
                <p class="eyebrow"><?= e(__('storefront.promotion_eyebrow')) ?></p>
                <h2 id="promotions-title"><?= e(__('storefront.promotions_title')) ?></h2>
            </div>
        </div>
        <div id="cozycafePromotions" class="carousel slide promotion-carousel" data-bs-ride="carousel">
            <?php if (count($banners) > 1): ?>
                <div class="carousel-indicators">
                    <?php foreach ($banners as $index => $banner): ?>
                        <button
                            type="button"
                            data-bs-target="#cozycafePromotions"
                            data-bs-slide-to="<?= e($index) ?>"
                            class="<?= $index === 0 ? 'active' : '' ?>"
                            aria-current="<?= $index === 0 ? 'true' : 'false' ?>"
                            aria-label="<?= e(__('storefront.promotion_slide')) ?> <?= e($index + 1) ?>"
                        ></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="carousel-inner">
                <?php foreach ($banners as $index => $banner): ?>
                    <?php
                    $bannerUrl = cozycafe_storefront_url(
                        isset($banner['button_url']) && is_string($banner['button_url']) ? $banner['button_url'] : null
                    );
                    ?>
                    <article class="carousel-item<?= $index === 0 ? ' active' : '' ?>">
                        <img
                            class="promotion-image"
                            src="<?= e(cozycafe_storefront_image(
                                isset($banner['image']) && is_string($banner['image']) ? $banner['image'] : null,
                                'banner'
                            )) ?>"
                            alt=""
                        >
                        <div class="promotion-caption">
                            <p class="eyebrow"><?= e(__('storefront.promotion_eyebrow')) ?></p>
                            <h3><?= e($banner['title'] ?? '') ?></h3>
                            <?php if (isset($banner['description']) && is_string($banner['description']) && $banner['description'] !== ''): ?>
                                <p><?= e($banner['description']) ?></p>
                            <?php endif; ?>
                            <?php if ($bannerUrl !== null): ?>
                                <a class="btn btn-cozy" href="<?= e($bannerUrl) ?>">
                                    <?= e(
                                        isset($banner['button_text']) && is_string($banner['button_text']) && $banner['button_text'] !== ''
                                            ? $banner['button_text']
                                            : __('storefront.view_menu')
                                    ) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (count($banners) > 1): ?>
                <button class="carousel-control-prev" type="button" data-bs-target="#cozycafePromotions" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    <span class="visually-hidden"><?= e(__('storefront.previous_promotion')) ?></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#cozycafePromotions" data-bs-slide="next">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    <span class="visually-hidden"><?= e(__('storefront.next_promotion')) ?></span>
                </button>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="section-space section-tint" aria-labelledby="categories-title">
    <div class="container">
        <div class="section-heading">
            <div>
                <p class="eyebrow"><?= e(__('storefront.categories_eyebrow')) ?></p>
                <h2 id="categories-title"><?= e(__('storefront.categories_title')) ?></h2>
            </div>
            <a class="text-link" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('storefront.view_all_menu')) ?></a>
        </div>
        <?php if ($categories === []): ?>
            <p class="empty-state"><?= e(__('common.no_data')) ?></p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($categories as $category): ?>
                    <div class="col-6 col-lg-3">
                        <a
                            class="category-tile"
                            href="<?= e(cozycafe_app_path('customer/menu.php?category=' . rawurlencode((string) $category['slug']))) ?>"
                        >
                            <span class="category-tile__icon" aria-hidden="true"><?= e(strtoupper(substr((string) $category['name'], 0, 1))) ?></span>
                            <span class="category-tile__name"><?= e($category['name']) ?></span>
                            <span class="category-tile__arrow" aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section-space container" aria-labelledby="featured-title">
    <div class="section-heading">
        <div>
            <p class="eyebrow"><?= e(__('storefront.featured_eyebrow')) ?></p>
            <h2 id="featured-title"><?= e(__('storefront.featured_title')) ?></h2>
            <p class="section-intro"><?= e(__('storefront.featured_intro')) ?></p>
        </div>
        <a class="text-link" href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"><?= e(__('storefront.view_all_menu')) ?></a>
    </div>
    <?php if ($featuredProducts === []): ?>
        <p class="empty-state"><?= e(__('storefront.no_featured_products')) ?></p>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($featuredProducts as $product): ?>
                <div class="col-12 col-sm-6 col-xl-3">
                    <?php render_storefront_product_card($product); ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="availability-section section-space">
    <div class="container availability-card">
        <div>
            <p class="eyebrow"><?= e(__('storefront.availability_eyebrow')) ?></p>
            <h2><?= e(__('storefront.availability_title')) ?></h2>
            <p class="mb-0"><?= e(__('storefront.availability_intro')) ?></p>
        </div>
        <div class="availability-count" aria-label="<?= e(__('storefront.available_tables')) ?>">
            <strong><?= e($availableTables) ?></strong>
            <span><?= e(__('storefront.available_of')) ?> <?= e($totalTables) ?></span>
        </div>
    </div>
</section>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
