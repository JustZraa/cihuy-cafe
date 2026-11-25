<?php
declare(strict_types=1);

$adminNavigation = [
    ['key' => 'dashboard', 'label' => 'admin.nav.dashboard', 'path' => 'dashboard.php'],
    ['key' => 'products', 'label' => 'admin.nav.products', 'path' => 'products/index.php'],
    ['key' => 'categories', 'label' => 'admin.nav.categories', 'path' => 'categories/index.php'],
    ['key' => 'inventory', 'label' => 'admin.nav.inventory', 'path' => 'inventory/index.php'],
    ['key' => 'orders', 'label' => 'admin.nav.orders', 'path' => 'orders/index.php'],
    ['key' => 'payments', 'label' => 'admin.nav.payments', 'path' => 'payments/index.php', 'badge' => 'payments'],
    ['key' => 'reservations', 'label' => 'admin.nav.reservations', 'path' => 'reservations/index.php', 'badge' => 'walkins'],
    ['key' => 'tables', 'label' => 'admin.nav.tables', 'path' => 'tables/index.php'],
    ['key' => 'customers', 'label' => 'admin.nav.customers', 'path' => 'customers/index.php'],
    ['key' => 'banners', 'label' => 'admin.nav.banners', 'path' => 'banners/index.php'],
    ['key' => 'reports', 'label' => 'admin.nav.reports', 'path' => 'reports/index.php'],
    ['key' => 'logs', 'label' => 'admin.nav.logs', 'path' => 'logs/index.php'],
    ['key' => 'backup', 'label' => 'admin.nav.backup', 'path' => 'backup/index.php'],
];
?>
<aside class="admin-sidebar">
    <a class="admin-brand" href="<?= e(admin_url('dashboard.php')) ?>">
        <span class="cozy-brand__mark" aria-hidden="true">C</span>
        <span>CozyCafe <small><?= e(__('admin.panel')) ?></small></span>
    </a>
    <details class="admin-nav" open>
        <summary><?= e(__('admin.navigation')) ?></summary>
        <nav aria-label="<?= e(__('admin.navigation')) ?>">
            <?php foreach ($adminNavigation as $item): ?>
                <?php if (!empty($item['future'])): ?>
                    <span class="admin-nav-link is-disabled" aria-disabled="true">
                        <span><?= e(__($item['label'])) ?></span>
                        <small><?= e(__('admin.stage10')) ?></small>
                    </span>
                <?php else: ?>
                    <a
                        class="admin-nav-link<?= $adminActive === $item['key'] ? ' is-active' : '' ?>"
                        href="<?= e(admin_url($item['path'])) ?>"
                        <?= $adminActive === $item['key'] ? 'aria-current="page"' : '' ?>
                    >
                        <span><?= e(__($item['label'])) ?></span>
                        <?php if (isset($item['badge']) && (int) ($adminBadges[$item['badge']] ?? 0) > 0): ?>
                            <span class="badge rounded-pill text-bg-warning"><?= e((int) $adminBadges[$item['badge']]) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </details>
</aside>
