<?php
$topbarLinks = $topbarLinks ?? [];
$profileDisplayName = display_name_for_user($user);
$profileAvatarPath = isset($user->avatarPath) ? trim((string) $user->avatarPath) : '';
$profileAvatarColor = avatar_color_for_user($user);
$profileAvatarInitial = avatar_initial($profileDisplayName);
?>
<style>
    .approval-counter-square {
        display: none;
        min-width: 32px;
        min-height: 32px;
        padding: .35rem;
        align-items: center;
        justify-content: center;
        border: 1px solid #efb4b4;
        background: #f8dede;
        color: #7a1f1f;
        font-weight: 700;
    }
    .approval-counter-square.is-visible { display: inline-flex; }
    .approval-menu-dot {
        display: none;
        width: .55rem;
        height: .55rem;
        border-radius: 999px;
        background: #e8a2a2;
    }
    .approval-menu-dot.is-visible { display: inline-block; }
    .approval-row {
        padding: .85rem 1rem;
        border: 1px solid var(--border);
        background: var(--panel-subtle);
        display: grid;
        gap: .65rem;
    }
</style>
<div class="topbar">
    <div>
        <a class="brand" href="/">passway</a>
    </div>
    <div class="topnav">
        <?php foreach ($topbarLinks as $link): ?>
            <?php if (((string) ($link['href'] ?? '')) === '/auth/logout') { continue; } ?>
            <?php if (((string) ($link['href'] ?? '')) === '/api'): ?>
                <div class="profile-menu js-delayed-details-menu">
                    <button type="button" class="button secondary profile-menu-trigger" aria-haspopup="true" aria-expanded="false"><?= e(__('ui.help.title')) ?></button>
                    <div class="profile-menu-panel panel">
                        <a class="button secondary" href="/docs"><?= e(__('ui.help.guide')) ?></a>
                        <a class="button secondary" href="/api"><?= e(__('ui.help.api')) ?></a>
                    </div>
                </div>
            <?php else: ?>
                <a class="button secondary" href="<?= e((string) ($link['href'] ?? '/')) ?>"><?= e((string) ($link['label'] ?? '')) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <span id="global-approval-counter" class="approval-counter-square" aria-live="polite">0</span>
        <div class="profile-menu js-delayed-details-menu">
            <button type="button" class="profile-link profile-menu-trigger" aria-haspopup="true" aria-expanded="false">
                <span><?= e($profileDisplayName) ?></span>
                <?php if ($profileAvatarPath !== ''): ?>
                    <img class="avatar-square avatar-image" src="<?= e($profileAvatarPath) ?>" alt="<?= e($profileDisplayName) ?>" width="32" height="32" decoding="async" loading="eager" fetchpriority="high">
                <?php else: ?>
                    <span class="avatar-square" style="background: <?= e($profileAvatarColor) ?>;"><?= e($profileAvatarInitial) ?></span>
                <?php endif; ?>
            </button>
            <div class="profile-menu-panel panel">
                <button type="button" class="button secondary hidden" id="open-global-approvals-modal"><span id="global-approval-dot" class="approval-menu-dot"></span><?= e(__('ui.secret.approvals_menu_button')) ?></button>
                <a class="button secondary" href="/profile"><?= e(__('ui.home.profile')) ?></a>
                <a class="button secondary" href="/auth/logout"><?= e(__('ui.app.logout')) ?></a>
            </div>
        </div>
    </div>
</div>

<dialog id="global-approvals-modal" class="modal">
    <div class="modal-body">
        <div style="display:flex; justify-content:space-between; gap:1rem; align-items:start;">
            <div>
                <h3 style="margin:0 0 .35rem;"><?= e(__('ui.secret.approvals_menu_button')) ?></h3>
                <div class="wizard-meta" id="global-approvals-status"><?= e(__('ui.secret.approvals_loading')) ?></div>
            </div>
            <button type="button" class="secondary" data-close-modal="global-approvals-modal">x</button>
        </div>
        <div id="global-approvals-list" class="grid" style="gap:.75rem;"></div>
    </div>
</dialog>

<script>
(() => {
    const menus = document.querySelectorAll('.js-delayed-details-menu');
    const globalApprovalCounter = document.getElementById('global-approval-counter');
    const openGlobalApprovalsModal = document.getElementById('open-global-approvals-modal');
    const globalApprovalDot = document.getElementById('global-approval-dot');
    const globalApprovalsModal = document.getElementById('global-approvals-modal');
    const globalApprovalsStatus = document.getElementById('global-approvals-status');
    const globalApprovalsList = document.getElementById('global-approvals-list');
    let globalApprovalTimer = null;

    for (const menu of menus) {
        let closeTimer = null;
        const trigger = menu.querySelector('.profile-menu-trigger');

        const closeMenu = () => {
            menu.classList.remove('is-open');
            if (trigger !== null) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        };

        const openMenu = () => {
            if (closeTimer !== null) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }
            for (const other of menus) {
                if (other !== menu) {
                    other.classList.remove('is-open');
                    const otherTrigger = other.querySelector('.profile-menu-trigger');
                    if (otherTrigger !== null) {
                        otherTrigger.setAttribute('aria-expanded', 'false');
                    }
                }
            }
            menu.classList.add('is-open');
            if (trigger !== null) {
                trigger.setAttribute('aria-expanded', 'true');
            }
        };

        const toggleMenu = () => {
            if (menu.classList.contains('is-open')) {
                closeMenu();
                return;
            }

            openMenu();
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

        if (trigger !== null) {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                toggleMenu();
            });
        }

        document.addEventListener('click', (event) => {
            if (!menu.contains(event.target)) {
                closeMenu();
            }
        });
    }

    const renderApprovalSummary = (count) => {
        if (globalApprovalCounter) {
            globalApprovalCounter.textContent = String(count);
            globalApprovalCounter.classList.toggle('is-visible', count > 0);
        }
        if (openGlobalApprovalsModal) {
            openGlobalApprovalsModal.classList.toggle('hidden', count <= 0);
        }
        if (globalApprovalDot) {
            globalApprovalDot.classList.toggle('is-visible', count > 0);
        }
    };

    const loadApprovalSummary = async () => {
        try {
            const response = await fetch('/api/v1/approvals/pending-summary', {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data) {
                throw new Error('Failed to load approvals');
            }
            renderApprovalSummary(Number(payload.data.count || 0));
        } catch (_error) {
            renderApprovalSummary(0);
        } finally {
            globalApprovalTimer = window.setTimeout(() => {
                void loadApprovalSummary();
            }, 20000);
        }
    };

    const reviewApproval = async (item, action) => {
        const organizationUuid = item.organization && item.organization.uuid ? item.organization.uuid : '';
        const requestUuid = item.uuid || '';
        if (!organizationUuid || !requestUuid) {
            return;
        }

        const response = await fetch(`/api/v1/organizations/${encodeURIComponent(organizationUuid)}/approvals/${encodeURIComponent(requestUuid)}/${action}`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || <?= json_encode((string) __('ui.secret.approvals_review_failed')) ?>);
        }

        if (window.passwayToast) {
            window.passwayToast.show(action === 'approve' ? <?= json_encode((string) __('ui.secret.approval_approved_toast')) ?> : <?= json_encode((string) __('ui.secret.approval_rejected_toast')) ?>, 'success');
        }
    };

    const renderApprovalRows = (items) => {
        if (!globalApprovalsList) {
            return;
        }

        globalApprovalsList.innerHTML = '';
        if (!Array.isArray(items) || items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'muted';
            empty.textContent = <?= json_encode((string) __('ui.secret.approvals_empty')) ?>;
            globalApprovalsList.appendChild(empty);
            return;
        }

        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'approval-row';

            const title = document.createElement('strong');
            title.textContent = item.organization && item.organization.name ? item.organization.name : '';
            row.appendChild(title);

            const secretLink = document.createElement(item.secret && item.secret.link ? 'a' : 'div');
            if (item.secret && item.secret.link) {
                secretLink.href = item.secret.link;
            }
            secretLink.textContent = item.directory && item.directory.path
                ? `${item.directory.path} / ${item.secret && item.secret.name ? item.secret.name : ''}`
                : (item.secret && item.secret.name ? item.secret.name : '');
            row.appendChild(secretLink);

            const requester = document.createElement('div');
            requester.className = 'muted';
            requester.textContent = item.requester && item.requester.display_name ? item.requester.display_name : '';
            row.appendChild(requester);

            const actions = document.createElement('div');
            actions.className = 'actions';

            const approveButton = document.createElement('button');
            approveButton.type = 'button';
            approveButton.textContent = <?= json_encode((string) __('ui.secret.approval_action_approve')) ?>;
            approveButton.addEventListener('click', async () => {
                try {
                    await reviewApproval(item, 'approve');
                    await loadApprovalList();
                } catch (error) {
                    if (window.passwayToast) {
                        window.passwayToast.show(error instanceof Error ? error.message : <?= json_encode((string) __('ui.secret.approvals_review_failed')) ?>, 'error');
                    }
                }
            });

            const rejectButton = document.createElement('button');
            rejectButton.type = 'button';
            rejectButton.className = 'secondary danger';
            rejectButton.textContent = <?= json_encode((string) __('ui.secret.approval_action_reject')) ?>;
            rejectButton.addEventListener('click', async () => {
                try {
                    await reviewApproval(item, 'reject');
                    await loadApprovalList();
                } catch (error) {
                    if (window.passwayToast) {
                        window.passwayToast.show(error instanceof Error ? error.message : <?= json_encode((string) __('ui.secret.approvals_review_failed')) ?>, 'error');
                    }
                }
            });

            actions.appendChild(approveButton);
            actions.appendChild(rejectButton);
            row.appendChild(actions);
            globalApprovalsList.appendChild(row);
        });
    };

    const loadApprovalList = async () => {
        if (!globalApprovalsStatus) {
            return;
        }

        globalApprovalsStatus.textContent = <?= json_encode((string) __('ui.secret.approvals_loading')) ?>;
        try {
            const response = await fetch('/api/v1/approvals/pending', {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data) {
                throw new Error(payload.error || <?= json_encode((string) __('ui.secret.approvals_load_failed')) ?>);
            }

            renderApprovalRows(payload.data);
            globalApprovalsStatus.textContent = '';
            renderApprovalSummary(Array.isArray(payload.data) ? payload.data.length : 0);
        } catch (error) {
            globalApprovalsStatus.textContent = error instanceof Error ? error.message : <?= json_encode((string) __('ui.secret.approvals_load_failed')) ?>;
        }
    };

    document.querySelectorAll('[data-close-modal="global-approvals-modal"]').forEach((button) => {
        button.addEventListener('click', () => {
            if (globalApprovalsModal) {
                globalApprovalsModal.close();
            }
        });
    });

    if (openGlobalApprovalsModal && globalApprovalsModal) {
        openGlobalApprovalsModal.addEventListener('click', async () => {
            globalApprovalsModal.showModal();
            await loadApprovalList();
        });
    }

    void loadApprovalSummary();
})();
</script>
