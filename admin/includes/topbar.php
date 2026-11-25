<?php
declare(strict_types=1);
?>
<header class="admin-topbar">
    <div>
        <span class="admin-topbar__greeting"><?= e(__('admin.welcome')) ?></span>
        <strong><?= e($adminName) ?></strong>
    </div>
    <div class="admin-topbar__tools">
        <details
            class="notification-control"
            data-notification-root
            data-notification-api="<?= e(cozycafe_app_path('api/notifications.php')) ?>"
            data-notification-empty="<?= e(__('notification.empty')) ?>"
            data-notification-error="<?= e(__('notification.error')) ?>"
            data-notification-mark-read="<?= e(__('button.mark_read')) ?>"
        >
            <summary class="nav-icon-link admin-notification-button" aria-label="<?= e(__('nav.notifications')) ?>" title="<?= e(__('nav.notifications')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path>
                </svg>
                <span class="nav-count" data-notification-count hidden></span>
            </summary>
            <div class="notification-dropdown notification-dropdown--admin">
                <div class="notification-dropdown__heading"><?= e(__('nav.notifications')) ?></div>
                <ul class="notification-list" data-notification-list>
                    <li class="notification-empty"><?= e(__('common.loading')) ?></li>
                </ul>
            </div>
        </details>
        <form method="post" action="<?= e(cozycafe_app_path('admin/logout.php')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-outline-cozy btn-sm" type="submit"><?= e(__('nav.logout')) ?></button>
        </form>
    </div>
</header>
