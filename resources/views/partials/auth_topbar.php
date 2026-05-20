<?php
$topbarLinks = $topbarLinks ?? [];
$profileDisplayName = display_name_for_user($user);
$profileAvatarPath = isset($user->avatarPath) ? trim((string) $user->avatarPath) : '';
$profileAvatarColor = avatar_color_for_user($user);
$profileAvatarInitial = avatar_initial($profileDisplayName);
?>
<div class="topbar">
    <div>
        <a class="brand" href="/">passway</a>
    </div>
    <div class="topnav">
        <?php foreach ($topbarLinks as $link): ?>
            <?php if (((string) ($link['href'] ?? '')) === '/auth/logout') { continue; } ?>
            <a class="button secondary" href="<?= e((string) ($link['href'] ?? '/')) ?>"><?= e((string) ($link['label'] ?? '')) ?></a>
        <?php endforeach; ?>
        <details class="profile-menu js-delayed-details-menu">
            <summary class="profile-link">
                <span><?= e($profileDisplayName) ?></span>
                <?php if ($profileAvatarPath !== ''): ?>
                    <img class="avatar-square avatar-image" src="<?= e($profileAvatarPath) ?>" alt="<?= e($profileDisplayName) ?>" width="32" height="32">
                <?php else: ?>
                    <span class="avatar-square" style="background: <?= e($profileAvatarColor) ?>;"><?= e($profileAvatarInitial) ?></span>
                <?php endif; ?>
            </summary>
            <div class="profile-menu-panel panel">
                <a class="button secondary" href="/profile"><?= e(__('ui.home.profile')) ?></a>
                <a class="button secondary" href="/auth/logout"><?= e(__('ui.app.logout')) ?></a>
            </div>
        </details>
    </div>
</div>

<script>
(() => {
    const menus = document.querySelectorAll('.js-delayed-details-menu');

    for (const menu of menus) {
        let closeTimer = null;

        const closeMenu = () => {
            menu.open = false;
            menu.classList.remove('is-open');
        };

        const openMenu = () => {
            if (closeTimer !== null) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }
            for (const other of menus) {
                if (other !== menu) {
                    other.open = false;
                    other.classList.remove('is-open');
                }
            }
            menu.open = true;
            menu.classList.add('is-open');
        };

        const scheduleClose = () => {
            if (closeTimer !== null) {
                window.clearTimeout(closeTimer);
            }
            closeTimer = window.setTimeout(() => {
                closeMenu();
                closeTimer = null;
            }, 180);
        };

        menu.addEventListener('mouseenter', () => {
            openMenu();
        });

        menu.addEventListener('mouseleave', () => {
            scheduleClose();
        });

        menu.addEventListener('focusin', () => {
            openMenu();
        });

        menu.addEventListener('focusout', () => {
            window.setTimeout(() => {
                if (!menu.contains(document.activeElement)) {
                    scheduleClose();
                }
            }, 0);
        });

        menu.addEventListener('toggle', () => {
            if (!menu.open) {
                closeMenu();
                return;
            }

            openMenu();
        });
    }
})();
</script>
