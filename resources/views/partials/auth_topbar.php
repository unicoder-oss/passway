<?php
$topbarLinks = $topbarLinks ?? [];
$topbarTitle = isset($topbarTitle) ? trim((string) $topbarTitle) : '';
$profileDisplayName = display_name_for_user($user);
$profileAvatarPath = isset($user->avatarPath) ? trim((string) $user->avatarPath) : '';
$profileAvatarColor = avatar_color_for_user($user);
$profileAvatarInitial = avatar_initial($profileDisplayName);
?>
<div class="topbar">
    <div>
        <a class="brand" href="/">passway</a>
        <?php if ($topbarTitle !== ''): ?>
            <div class="topbar-title"><?= e($topbarTitle) ?></div>
        <?php endif; ?>
    </div>
    <div class="topnav">
        <?php foreach ($topbarLinks as $link): ?>
            <a class="button secondary" href="<?= e((string) ($link['href'] ?? '/')) ?>"><?= e((string) ($link['label'] ?? '')) ?></a>
        <?php endforeach; ?>
        <a class="profile-link" href="/profile">
            <span><?= e($profileDisplayName) ?></span>
            <?php if ($profileAvatarPath !== ''): ?>
                <img class="avatar-square avatar-image" src="<?= e($profileAvatarPath) ?>" alt="<?= e($profileDisplayName) ?>" width="32" height="32">
            <?php else: ?>
                <span class="avatar-square" style="background: <?= e($profileAvatarColor) ?>;"><?= e($profileAvatarInitial) ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>
