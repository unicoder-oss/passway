<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Manage <?= e($organization->name) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/?org=<?= e($organization->uuid) ?>">Back to Dashboard</a>
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/audit">Audit Log</a>
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/api-keys">API Keys</a>
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/integrations">Integrations</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>

<div class="grid grid-2-compact" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Members</h2>
        <div class="grid" style="gap:.8rem;">
            <?php foreach ($members as $member): $memberUser = \Passway\Models\User::findById($member->userId); ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($memberUser?->email ?? 'Unknown user') ?></div>
                        <div class="muted" style="font-size:.92rem;">Joined <?= e($member->joinedAt) ?></div>
                    </div>
                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/role" class="grid field-actions-3" style="gap:.75rem;">
                        <div>
                            <label>Role</label>
                            <select name="role">
                                <?php foreach (\Passway\Models\OrganizationMember::ROLES as $role): ?>
                                    <option value="<?= e($role) ?>" <?= $member->role === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit">Update</button>
                        <?php if (($memberUser?->uuid ?? '') !== $user->uuid): ?>
                            <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/remove">Remove</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="grid" style="gap:1rem;">
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;">Create Invite</h3>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites" class="grid" style="gap:.75rem;">
                <div>
                    <label for="invite-role">Role</label>
                    <select id="invite-role" name="role">
                        <option value="user">user</option>
                        <option value="observer">observer</option>
                        <option value="moderator">moderator</option>
                        <option value="admin">admin</option>
                    </select>
                </div>
                <div>
                    <label for="invite-ttl">TTL seconds</label>
                    <input id="invite-ttl" type="number" name="ttl" value="3600" min="60" max="604800">
                </div>
                <button type="submit">Create Invite Link</button>
            </form>
        </div>

        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;">Active Invites</h3>
            <div class="grid" style="gap:.75rem;">
                <?php foreach ($invites as $invite): ?>
                    <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55);">
                        <div style="font-weight:700;">Role: <?= e($invite->role) ?></div>
                        <div class="muted" style="font-size:.92rem;">Expires <?= e($invite->expiresAt) ?></div>
                        <div class="muted" style="font-size:.92rem; margin:.35rem 0;">Link: <code>/invite/<?= e($invite->token) ?></code></div>
                        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites/<?= e($invite->uuid) ?>/revoke">
                            <button type="submit" class="danger">Revoke Invite</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if ($invites === []): ?><div class="muted">No active invites.</div><?php endif; ?>
            </div>
        </div>
    </section>
</div>
