<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'storefront.php';
start_secure_session();

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

$categoryQuery = $pdo->query(
    'SELECT id, name, slug
     FROM categories
     WHERE is_active = 1
     ORDER BY sort_order ASC, name ASC'
);
$categories = $categoryQuery->fetchAll();

$requestedCategory = $_GET['category'] ?? null;
$selectedCategory = is_string($requestedCategory) ? trim($requestedCategory) : '';
$categorySlugs = array_column($categories, 'slug');
if ($selectedCategory !== '' && !in_array($selectedCategory, $categorySlugs, true)) {
    $selectedCategory = '';
}

$sql = 'SELECT p.id, p.category_id, p.name, p.slug, p.image, p.description, p.price,
               p.stock, p.is_available, c.name AS category_name, c.slug AS category_slug
        FROM products p
        INNER JOIN categories c ON c.id = p.category_id
        WHERE c.is_active = 1';
$parameters = [];
if ($selectedCategory !== '') {
    $sql .= ' AND c.slug = :category_slug';
    $parameters['category_slug'] = $selectedCategory;
}
$sql .= ' ORDER BY c.sort_order ASC, p.created_at DESC, p.id DESC';

$productQuery = $pdo->prepare($sql);
$productQuery->execute($parameters);
$products = $productQuery->fetchAll();
$productsByCategory = [];
foreach ($products as $product) {
    $slug = (string) $product['category_slug'];
    if (!isset($productsByCategory[$slug])) {
        $productsByCategory[$slug] = [
            'name' => (string) $product['category_name'],
            'products' => [],
        ];
    }
    $productsByCategory[$slug]['products'][] = $product;
}

$pageTitle = __('storefront.menu_title');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header.php';
?>
<section class="page-hero page-hero--compact">
    <div class="container">
        <p class="eyebrow"><?= e(__('storefront.menu_eyebrow')) ?></p>
        <h1><?= e(__('storefront.menu_title')) ?></h1>
        <p class="page-hero__copy"><?= e(__('storefront.menu_intro')) ?></p>
    </div>
</section>

<section class="section-space container">
    <nav class="category-tabs" aria-label="<?= e(__('storefront.menu_categories')) ?>">
        <a
            class="category-tab<?= $selectedCategory === '' ? ' active' : '' ?>"
            href="<?= e(cozycafe_app_path('customer/menu.php')) ?>"
            <?= $selectedCategory === '' ? 'aria-current="page"' : '' ?>
        ><?= e(__('storefront.all_categories')) ?></a>
        <?php foreach ($categories as $category): ?>
            <a
                class="category-tab<?= $selectedCategory === $category['slug'] ? ' active' : '' ?>"
                href="<?= e(cozycafe_app_path('customer/menu.php?category=' . rawurlencode((string) $category['slug']))) ?>"
                <?= $selectedCategory === $category['slug'] ? 'aria-current="page"' : '' ?>
            ><?= e($category['name']) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($products === []): ?>
        <div class="empty-state empty-state--large">
            <h2 class="h4"><?= e(__('storefront.no_products_title')) ?></h2>
            <p class="mb-0"><?= e(__('storefront.no_products_intro')) ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($productsByCategory as $categorySlug => $group): ?>
            <section class="menu-category-section" aria-labelledby="category-<?= e($categorySlug) ?>">
                <div class="section-heading">
                    <h2 id="category-<?= e($categorySlug) ?>"><?= e($group['name']) ?></h2>
                </div>
                <div class="row g-4">
                    <?php foreach ($group['products'] as $product): ?>
                        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                            <?php render_storefront_product_card($product); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
