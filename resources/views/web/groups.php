<?php if (empty($organizationSettingsPartial)) { require base_path('resources/views/partials/auth_topbar.php'); } ?>

<div class="js-organization-settings-page" data-page-title="<?= e((string) ($title ?? 'Passway')) ?>">
<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.groups')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($organization->name) ?></div>
</section>

<style>
    dialog.group-members-modal {
        width: min(1120px, calc(100vw - 2rem));
        max-height: calc(100dvh - 2rem);
        overflow: hidden;
    }

    .group-members-modal .modal-body {
        max-height: calc(100dvh - 2rem);
        grid-template-rows: auto minmax(0, 1fr) auto;
        overflow: hidden;
    }

    .group-members-modal-content {
        min-height: 0;
    }

    .group-members-panel {
        min-height: 0;
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
    }

    .group-members-panel-with-search {
        grid-template-rows: auto auto minmax(0, 1fr);
    }

    .group-members-scroll-list {
        min-height: 0;
        max-height: min(58vh, 560px);
        overflow: auto;
        padding-right: .15rem;
    }

    .group-member-card .muted,
    .group-candidate-card .muted {
        overflow-wrap: anywhere;
    }

    @media (max-width: 900px) {
        dialog.group-members-modal {
            width: min(680px, calc(100vw - 2rem));
        }

        .group-members-scroll-list {
            max-height: 40vh;
        }
    }
</style>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/organization_settings_sidebar.php'); ?>
    <div class="grid grid-2" style="align-items:start; gap:1rem;">
        <?php if (!empty($canManageGroups)): ?>
            <section class="panel" style="padding:1.5rem;">
                <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.create')) ?></h2>
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/groups" class="grid" style="gap:.75rem;" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
                    <div>
                        <label for="group-name"><?= e(__('ui.groups.name')) ?></label>
                        <input id="group-name" name="name" placeholder="<?= e(__('ui.groups.name_placeholder')) ?>" required>
                    </div>
                    <div>
                        <label for="group-description"><?= e(__('ui.groups.description')) ?></label>
                        <textarea id="group-description" name="description" rows="4" placeholder="<?= e(__('ui.groups.description_placeholder')) ?>"></textarea>
                    </div>
                    <button type="submit"><?= e(__('ui.groups.create_submit')) ?></button>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel" style="padding:1.5rem;">
            <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.existing')) ?></h2>
            <div class="grid" style="gap:.75rem;">
                <?php foreach ($groups as $groupRow): ?>
                    <?php $group = $groupRow['group']; ?>
                    <?php $members = $groupRow['members'] ?? []; ?>
                    <?php $candidates = $groupRow['candidates'] ?? []; ?>
                    <?php $membersModalId = 'group-members-modal-' . $group->uuid; ?>
                    <?php $deleteModalId = 'group-delete-modal-' . $group->uuid; ?>
                    <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                        <div>
                            <div style="font-weight:700;"><?= e($group->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e($group->description ?? __('ui.groups.no_description')) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e(__('ui.groups.member_count', ['count' => (string) $groupRow['member_count']])) ?></div>
                        </div>
                        <div class="actions">
                            <a class="button secondary js-open-group-members-modal" href="/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>" data-group-modal-id="<?= e($membersModalId) ?>"><?= e(__('ui.groups.manage_members')) ?></a>
                            <?php if (!empty($canManageGroups)): ?>
                                <button type="button" class="danger js-open-group-delete-modal" data-group-modal-id="<?= e($deleteModalId) ?>"><?= e(__('ui.groups.delete_group')) ?></button>
                                <noscript>
                                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>/delete">
                                        <button type="submit" class="danger"><?= e(__('ui.groups.delete_group')) ?></button>
                                    </form>
                                </noscript>
                            <?php endif; ?>
                        </div>
                    </div>

                    <dialog id="<?= e($membersModalId) ?>" class="modal group-members-modal js-group-members-modal" data-group-uuid="<?= e($group->uuid) ?>" data-api-url="<?= e('/api/v1/organizations/' . $organization->uuid . '/groups/' . $group->uuid . '/members') ?>" data-api-remove-base-url="<?= e('/api/v1/organizations/' . $organization->uuid . '/groups/' . $group->uuid . '/members/') ?>" data-remove-base-url="<?= e('/organizations/' . $organization->uuid . '/groups/' . $group->uuid . '/members/') ?>">
                        <div class="modal-body">
                            <div>
                                <h3 style="margin:0 0 .35rem;"><?= e(__('ui.groups.manage_members_title', ['group' => $group->name])) ?></h3>
                                <div class="wizard-meta"><?= e($group->description ?? __('ui.groups.no_description')) ?></div>
                            </div>
                            <div class="grid grid-2 group-members-modal-content" style="align-items:stretch; gap:1rem;">
                                <section class="panel group-members-panel" style="padding:1.5rem;">
                                    <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.members')) ?></h2>
                                    <div class="js-group-members-list group-members-scroll-list grid" style="gap:.75rem;">
                                        <?php foreach ($members as $member): ?>
                                            <div class="panel panel-muted group-member-card" style="padding:1rem; display:grid; gap:.75rem;">
                                                <div>
                                                    <div style="font-weight:700;"><?= e($member['name']) ?></div>
                                                    <div class="muted" style="font-size:.92rem;"><?= e($member['email']) ?></div>
                                                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.groups.member_role', ['role' => $member['role_label']])) ?></div>
                                                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.groups.member_added_at', ['date' => $member['added_at']])) ?></div>
                                                </div>
                                                <?php if (!empty($canManageGroups)): ?>
                                                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>/members/<?= e($member['user_uuid']) ?>/remove" class="js-group-remove-member-form" data-user-uuid="<?= e($member['user_uuid']) ?>" data-user-name="<?= e($member['name']) ?>" data-user-email="<?= e($member['email']) ?>" data-user-role-label="<?= e($member['role_label']) ?>">
                                                        <button type="submit" class="danger"><?= e(__('ui.groups.remove_member')) ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="js-group-members-empty muted<?= $members === [] ? '' : ' hidden' ?>"><?= e(__('ui.groups.no_members')) ?></div>
                                    </div>
                                </section>

                                <section class="panel group-members-panel group-members-panel-with-search" style="padding:1.5rem;">
                                    <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.add_member')) ?></h2>
                                    <div class="grid" style="gap:.75rem; margin-bottom:1rem;">
                                        <div>
                                            <label><?= e(__('ui.groups.search_members')) ?></label>
                                            <input class="js-group-member-search" type="search" placeholder="<?= e(__('ui.groups.search_members_placeholder')) ?>">
                                        </div>
                                    </div>

                                    <div class="js-group-candidates-list group-members-scroll-list grid" style="gap:.75rem;">
                                        <?php foreach ($candidates as $candidate): ?>
                                            <div class="panel panel-muted group-candidate-card" data-member-search="<?= e(mb_strtolower($candidate['name'] . ' ' . $candidate['email'] . ' ' . $candidate['role_label'], 'UTF-8')) ?>" data-user-uuid="<?= e($candidate['uuid']) ?>" data-user-name="<?= e($candidate['name']) ?>" data-user-email="<?= e($candidate['email']) ?>" data-user-role-label="<?= e($candidate['role_label']) ?>" style="padding:1rem; display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:.75rem; align-items:start;">
                                                <div style="min-width:0; display:grid; gap:.35rem;">
                                                    <div style="font-weight:700;"><?= e($candidate['name']) ?></div>
                                                    <div class="muted" style="font-size:.92rem;"><?= e($candidate['email']) ?></div>
                                                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.groups.member_role', ['role' => $candidate['role_label']])) ?></div>
                                                </div>
                                                <?php if (!empty($canManageGroups)): ?>
                                                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>/members" class="js-group-add-member-form">
                                                        <input type="hidden" name="user_uuid" value="<?= e($candidate['uuid']) ?>">
                                                        <button type="submit" class="secondary" aria-label="<?= e(__('ui.groups.add_member_aria', ['user' => $candidate['email']])) ?>" title="<?= e(__('ui.groups.add_member_aria', ['user' => $candidate['email']])) ?>">+</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="js-group-candidates-empty muted<?= $candidates === [] ? '' : ' hidden' ?>"><?= e(__('ui.groups.no_available_members')) ?></div>
                                        <div class="js-group-candidates-filter-empty muted hidden"><?= e(__('ui.groups.no_search_matches')) ?></div>
                                    </div>
                                </section>
                            </div>
                            <div class="actions-end">
                                <button type="button" class="secondary js-close-group-modal"><?= e(__('ui.groups.close_members')) ?></button>
                            </div>
                        </div>
                    </dialog>

                    <?php if (!empty($canManageGroups)): ?>
                        <dialog id="<?= e($deleteModalId) ?>" class="modal">
                            <div class="modal-body">
                                <div>
                                    <h3 style="margin:0 0 .35rem;"><?= e(__('ui.groups.delete_title')) ?></h3>
                                    <div class="wizard-meta"><?= e(__('ui.groups.delete_hint', ['group' => $group->name])) ?></div>
                                </div>
                                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>/delete" class="actions-end" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
                                    <button type="button" class="secondary js-close-group-modal"><?= e(__('ui.organization.cancel')) ?></button>
                                    <button type="submit" class="danger"><?= e(__('ui.groups.delete_group')) ?></button>
                                </form>
                            </div>
                        </dialog>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($groups === []): ?><div class="muted"><?= e(__('ui.groups.no_groups')) ?></div><?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
(() => {
    const canManageGroups = <?= json_encode((bool) $canManageGroups) ?>;
    const labels = {
        addedAt: <?= json_encode((string) __('ui.groups.member_added_at_now')) ?>,
        addedTemplate: <?= json_encode((string) __('ui.groups.member_added_at', ['date' => '__DATE__'])) ?>,
        rolePrefix: <?= json_encode((string) __('ui.groups.member_role_prefix')) ?>,
        remove: <?= json_encode((string) __('ui.groups.remove_member')) ?>,
        addFailed: <?= json_encode((string) __('ui.groups.member_add_failed')) ?>,
        removeFailed: <?= json_encode((string) __('ui.groups.member_remove_failed')) ?>,
        added: <?= json_encode((string) __('ui.groups.member_added')) ?>,
        removed: <?= json_encode((string) __('ui.groups.member_removed')) ?>,
        addAria: <?= json_encode((string) __('ui.groups.add_member_aria', ['user' => '__USER__'])) ?>,
    };

    const showFeedback = (message, type) => {
        if (message && window.passwayToast && typeof window.passwayToast.show === 'function') {
            window.passwayToast.show(message, type);
        }
    };

    const openDialog = (id) => {
        const dialog = id ? document.getElementById(id) : null;
        if (dialog && typeof dialog.showModal === 'function') {
            dialog.showModal();
            return true;
        }

        return false;
    };

    document.querySelectorAll('.js-open-group-members-modal').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (openDialog(link.getAttribute('data-group-modal-id'))) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.js-open-group-delete-modal').forEach((button) => {
        button.addEventListener('click', () => {
            openDialog(button.getAttribute('data-group-modal-id'));
        });
    });

    document.querySelectorAll('.js-close-group-modal').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.closest('dialog');
            if (dialog) {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('.js-group-members-modal').forEach((modal) => {
        const searchInput = modal.querySelector('.js-group-member-search');
        const candidatesList = modal.querySelector('.js-group-candidates-list');
        const membersList = modal.querySelector('.js-group-members-list');
        const candidatesEmpty = modal.querySelector('.js-group-candidates-empty');
        const candidatesFilterEmpty = modal.querySelector('.js-group-candidates-filter-empty');
        const membersEmpty = modal.querySelector('.js-group-members-empty');
        const apiUrl = modal.getAttribute('data-api-url') || '';
        const apiRemoveBaseUrl = modal.getAttribute('data-api-remove-base-url') || '';
        const removeBaseUrl = modal.getAttribute('data-remove-base-url') || '';

        if (!searchInput || !candidatesList || !membersList || !candidatesEmpty || !candidatesFilterEmpty || !membersEmpty) {
            return;
        }

        const syncCandidateEmptyStates = () => {
            const cards = Array.from(candidatesList.querySelectorAll('.group-candidate-card'));
            const visibleCards = cards.filter((card) => !card.classList.contains('hidden'));
            const hasCards = cards.length > 0;
            candidatesEmpty.classList.toggle('hidden', hasCards);
            candidatesFilterEmpty.classList.toggle('hidden', !hasCards || visibleCards.length > 0);
        };

        const syncMembersEmptyState = () => {
            membersEmpty.classList.toggle('hidden', membersList.querySelectorAll('.group-member-card').length > 0);
        };

        const filterCandidates = () => {
            const query = (searchInput.value || '').trim().toLowerCase();
            candidatesList.querySelectorAll('.group-candidate-card').forEach((card) => {
                const haystack = card.getAttribute('data-member-search') || '';
                card.classList.toggle('hidden', query !== '' && !haystack.includes(query));
            });
            syncCandidateEmptyStates();
        };

        const createRemoveForm = (userUuid) => {
            if (!canManageGroups) {
                return null;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `${removeBaseUrl}${encodeURIComponent(userUuid)}/remove`;
            form.className = 'js-group-remove-member-form';
            form.dataset.userUuid = userUuid;

            const button = document.createElement('button');
            button.type = 'submit';
            button.className = 'danger';
            button.textContent = labels.remove;

            form.appendChild(button);
            return form;
        };

        const bindRemoveMemberForm = (form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitButton = form.querySelector('button[type="submit"]');
                const card = form.closest('.group-member-card');
                if (!(submitButton instanceof HTMLButtonElement) || !(card instanceof HTMLElement)) {
                    form.submit();
                    return;
                }

                submitButton.disabled = true;

                try {
                    const response = await fetch(`${apiRemoveBaseUrl}${encodeURIComponent(form.dataset.userUuid || '')}`, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.error || labels.removeFailed);
                    }

                    restoreCandidateCard(form);
                    card.remove();
                    syncMembersEmptyState();
                    showFeedback(labels.removed, 'success');
                } catch (error) {
                    showFeedback(error instanceof Error ? error.message : labels.removeFailed, 'error');
                    submitButton.disabled = false;
                }
            });
        };

        const addMemberCard = (card, addedAt) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'panel panel-muted group-member-card';
            wrapper.style.padding = '1rem';
            wrapper.style.display = 'grid';
            wrapper.style.gap = '.75rem';

            const info = document.createElement('div');
            const name = document.createElement('div');
            name.style.fontWeight = '700';
            name.textContent = card.getAttribute('data-user-name') || '';
            const email = document.createElement('div');
            email.className = 'muted';
            email.style.fontSize = '.92rem';
            email.textContent = card.getAttribute('data-user-email') || '';
            const role = document.createElement('div');
            role.className = 'muted';
            role.style.fontSize = '.92rem';
            role.textContent = `${labels.rolePrefix}${card.getAttribute('data-user-role-label') || ''}`;
            const added = document.createElement('div');
            added.className = 'muted';
            added.style.fontSize = '.92rem';
            added.textContent = addedAt || labels.addedAt;
            info.append(name, email, role, added);
            wrapper.appendChild(info);

            const removeForm = createRemoveForm(card.getAttribute('data-user-uuid') || '');
            if (removeForm) {
                removeForm.dataset.userName = card.getAttribute('data-user-name') || '';
                removeForm.dataset.userEmail = card.getAttribute('data-user-email') || '';
                removeForm.dataset.userRoleLabel = card.getAttribute('data-user-role-label') || '';
                wrapper.appendChild(removeForm);
                bindRemoveMemberForm(removeForm);
            }

            membersList.insertBefore(wrapper, membersEmpty);
            syncMembersEmptyState();
        };

        const createCandidateCard = (member) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'panel panel-muted group-candidate-card';
            wrapper.setAttribute('data-member-search', `${member.name} ${member.email} ${member.roleLabel}`.toLowerCase());
            wrapper.setAttribute('data-user-uuid', member.userUuid);
            wrapper.setAttribute('data-user-name', member.name);
            wrapper.setAttribute('data-user-email', member.email);
            wrapper.setAttribute('data-user-role-label', member.roleLabel);
            wrapper.style.padding = '1rem';
            wrapper.style.display = 'grid';
            wrapper.style.gridTemplateColumns = 'minmax(0, 1fr) auto';
            wrapper.style.gap = '.75rem';
            wrapper.style.alignItems = 'start';

            const info = document.createElement('div');
            info.style.minWidth = '0';
            info.style.display = 'grid';
            info.style.gap = '.35rem';
            const name = document.createElement('div');
            name.style.fontWeight = '700';
            name.textContent = member.name;
            const email = document.createElement('div');
            email.className = 'muted';
            email.style.fontSize = '.92rem';
            email.textContent = member.email;
            const role = document.createElement('div');
            role.className = 'muted';
            role.style.fontSize = '.92rem';
            role.textContent = `${labels.rolePrefix}${member.roleLabel}`;
            info.append(name, email, role);
            wrapper.appendChild(info);

            if (canManageGroups) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = apiUrl.replace('/api/v1', '');
                form.className = 'js-group-add-member-form';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'user_uuid';
                input.value = member.userUuid;
                const button = document.createElement('button');
                button.type = 'submit';
                button.className = 'secondary';
                button.setAttribute('aria-label', labels.addAria.replace('__USER__', member.email));
                button.title = labels.addAria.replace('__USER__', member.email);
                button.textContent = '+';
                form.append(input, button);
                wrapper.appendChild(form);
                bindAddMemberForm(form);
            }

            return wrapper;
        };

        const restoreCandidateCard = (form) => {
            const userUuid = form.dataset.userUuid || '';
            if (userUuid === '') {
                return;
            }

            const candidateCard = createCandidateCard({
                userUuid,
                name: form.dataset.userName || '',
                email: form.dataset.userEmail || '',
                roleLabel: form.dataset.userRoleLabel || '',
            });
            candidatesList.insertBefore(candidateCard, candidatesEmpty);
            filterCandidates();
        };

        function bindAddMemberForm(form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitButton = form.querySelector('button[type="submit"]');
                const card = form.closest('.group-candidate-card');
                if (!(submitButton instanceof HTMLButtonElement) || !(card instanceof HTMLElement)) {
                    form.submit();
                    return;
                }

                submitButton.disabled = true;

                try {
                    const response = await fetch(apiUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: new URLSearchParams(new FormData(form)),
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.error || labels.addFailed);
                    }

                    const addedAt = payload.data && payload.data.added_at
                        ? labels.addedTemplate.replace('__DATE__', payload.data.added_at)
                        : labels.addedAt;
                    addMemberCard(card, addedAt);
                    card.remove();
                    filterCandidates();
                    showFeedback(labels.added, 'success');
                } catch (error) {
                    showFeedback(error instanceof Error ? error.message : labels.addFailed, 'error');
                    submitButton.disabled = false;
                }
            });
        }

        searchInput.addEventListener('input', filterCandidates);
        candidatesList.querySelectorAll('.js-group-add-member-form').forEach(bindAddMemberForm);
        membersList.querySelectorAll('.js-group-remove-member-form').forEach(bindRemoveMemberForm);
        syncMembersEmptyState();
        syncCandidateEmptyStates();
    });
})();
</script>
</div>
