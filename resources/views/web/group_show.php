<?php
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid . '/groups', 'label' => __('ui.groups.back_to_groups')],
    ['href' => '/organizations/' . $organization->uuid . '/manage', 'label' => __('ui.app.back_to_management')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem; display:grid; gap:.4rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e($group->name) ?></h1>
    <div class="muted"><?= e($group->description ?? __('ui.groups.no_description')) ?></div>
</section>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.members')) ?></h2>
        <div id="group-members-list" class="grid" style="gap:.75rem;">
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
            <div id="group-members-empty" class="muted<?= $members === [] ? '' : ' hidden' ?>"><?= e(__('ui.groups.no_members')) ?></div>
        </div>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.add_member')) ?></h2>
        <div class="grid" style="gap:.75rem; margin-bottom:1rem;">
            <div>
                <label for="group-member-search"><?= e(__('ui.groups.search_members')) ?></label>
                <input id="group-member-search" type="search" placeholder="<?= e(__('ui.groups.search_members_placeholder')) ?>">
            </div>
        </div>

        <div id="group-candidates-list" class="grid" style="gap:.75rem;">
            <?php foreach ($candidates as $candidate): ?>
                <div
                    class="panel panel-muted group-candidate-card"
                    data-member-search="<?= e(mb_strtolower($candidate['name'] . ' ' . $candidate['email'] . ' ' . $candidate['role_label'], 'UTF-8')) ?>"
                    data-user-uuid="<?= e($candidate['uuid']) ?>"
                    data-user-name="<?= e($candidate['name']) ?>"
                    data-user-email="<?= e($candidate['email']) ?>"
                    data-user-role-label="<?= e($candidate['role_label']) ?>"
                    style="padding:1rem; display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:.75rem; align-items:start;"
                >
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
            <div id="group-candidates-empty" class="muted<?= $candidates === [] ? '' : ' hidden' ?>"><?= e(__('ui.groups.no_available_members')) ?></div>
            <div id="group-candidates-filter-empty" class="muted hidden"><?= e(__('ui.groups.no_search_matches')) ?></div>
        </div>
    </section>
</div>

<script>
(() => {
    const searchInput = document.getElementById('group-member-search');
    const candidatesList = document.getElementById('group-candidates-list');
    const membersList = document.getElementById('group-members-list');
    const candidatesEmpty = document.getElementById('group-candidates-empty');
    const candidatesFilterEmpty = document.getElementById('group-candidates-filter-empty');
    const membersEmpty = document.getElementById('group-members-empty');
    const apiUrl = <?= json_encode('/api/v1/organizations/' . $organization->uuid . '/groups/' . $group->uuid . '/members') ?>;
    const apiRemoveBaseUrl = <?= json_encode('/api/v1/organizations/' . $organization->uuid . '/groups/' . $group->uuid . '/members/') ?>;
    const removeBaseUrl = <?= json_encode('/organizations/' . $organization->uuid . '/groups/' . $group->uuid . '/members/') ?>;
    const canManageGroups = <?= json_encode((bool) $canManageGroups) ?>;
    const labels = {
        addedAt: <?= json_encode((string) __('ui.groups.member_added_at_now')) ?>,
        rolePrefix: <?= json_encode((string) __('ui.groups.member_role_prefix')) ?>,
        remove: <?= json_encode((string) __('ui.groups.remove_member')) ?>,
        addFailed: <?= json_encode((string) __('ui.groups.member_add_failed')) ?>,
        removeFailed: <?= json_encode((string) __('ui.groups.member_remove_failed')) ?>,
        removed: <?= json_encode((string) __('ui.groups.member_removed')) ?>,
        addAria: <?= json_encode((string) __('ui.groups.add_member_aria', ['user' => '__USER__'])) ?>,
    };

    if (!searchInput || !candidatesList || !membersList || !candidatesEmpty || !candidatesFilterEmpty || !membersEmpty) {
        return;
    }

    const setFeedback = (message, type) => {
        if (!message || !window.passwayToast || typeof window.passwayToast.show !== 'function') {
            return;
        }

        window.passwayToast.show(message, type);
    };

    const syncCandidateEmptyStates = () => {
        const cards = Array.from(candidatesList.querySelectorAll('.group-candidate-card'));
        const visibleCards = cards.filter((card) => !card.classList.contains('hidden'));
        const hasCards = cards.length > 0;
        candidatesEmpty.classList.toggle('hidden', hasCards);
        candidatesFilterEmpty.classList.toggle('hidden', !hasCards || visibleCards.length > 0);
    };

    const syncMembersEmptyState = () => {
        const memberCards = membersList.querySelectorAll('.group-member-card');
        membersEmpty.classList.toggle('hidden', memberCards.length > 0);
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
            form.action = `/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>/members`;
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

    const bindAddMemberForm = (form) => {
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
                    ? <?= json_encode((string) __('ui.groups.member_added_at', ['date' => '__DATE__'])) ?>.replace('__DATE__', payload.data.added_at)
                    : labels.addedAt;
                addMemberCard(card, addedAt);
                card.remove();
                filterCandidates();
                setFeedback(<?= json_encode((string) __('ui.groups.member_added')) ?>, 'success');
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : labels.addFailed, 'error');
                submitButton.disabled = false;
            }
        });
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
                setFeedback(labels.removed, 'success');
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : labels.removeFailed, 'error');
                submitButton.disabled = false;
            }
        });
    };

    searchInput.addEventListener('input', filterCandidates);

    candidatesList.querySelectorAll('.js-group-add-member-form').forEach(bindAddMemberForm);
    membersList.querySelectorAll('.js-group-remove-member-form').forEach(bindRemoveMemberForm);

    syncMembersEmptyState();
    syncCandidateEmptyStates();
})();
</script>
