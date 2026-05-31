<aside class="panel settings-sidebar" style="padding:1rem;">
    <a class="button secondary" href="/"><?= e(__('ui.profile.dashboard')) ?></a>
    <nav class="settings-nav" aria-label="<?= e(__('ui.profile.subtitle')) ?>">
        <?php
        $profileLinks = [
            ['key' => 'basic', 'href' => '/profile', 'label' => __('ui.profile.sections.basic')],
            ['key' => 'security', 'href' => '/profile/security', 'label' => __('ui.profile.sections.security')],
            ['key' => 'interface', 'href' => '/profile/interface', 'label' => __('ui.profile.sections.interface')],
        ];
        ?>
        <?php foreach ($profileLinks as $link): ?>
            <?php $isActive = ($activeProfileSection ?? '') === $link['key']; ?>
            <a
                class="button secondary<?= $isActive ? ' is-active' : '' ?>"
                href="<?= e($link['href']) ?>"
                data-profile-settings-link="true"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            ><?= e($link['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>

<script>
(() => {
    if (window.passwayProfileSettingsNavInitialized) {
        return;
    }
    window.passwayProfileSettingsNavInitialized = true;

    const cache = new Map();
    const selector = '[data-profile-settings-link="true"]';
    const formSelector = '[data-profile-settings-form="true"]';
    const containerSelector = '.js-profile-settings-page';

    const isPlainLeftClick = (event) => event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    const closestLink = (target) => target instanceof Element ? target.closest(selector) : null;

    const fetchPage = (url) => {
        if (!cache.has(url)) {
            cache.set(url, fetch(url, {
                headers: {
                    'Accept': 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            }).then((response) => {
                if (!response.ok) {
                    throw new Error('Failed to load profile settings section');
                }
                return response.text();
            }).catch((error) => {
                cache.delete(url);
                throw error;
            }));
        }

        return cache.get(url);
    };

    const executeScripts = (container) => {
        container.querySelectorAll('script').forEach((script) => {
            const nextScript = document.createElement('script');
            for (const attribute of script.attributes) {
                nextScript.setAttribute(attribute.name, attribute.value);
            }
            nextScript.textContent = script.textContent;
            script.replaceWith(nextScript);
        });
    };

    const showToasts = (container) => {
        if (!window.passwayToast || typeof window.passwayToast.show !== 'function') {
            return;
        }

        container.querySelectorAll('[data-toast="true"]').forEach((element) => {
            const type = element.classList.contains('error') ? 'error' : 'success';
            window.passwayToast.show(element.textContent || '', type);
            element.remove();
        });
    };

    const applyPageMetadata = (container) => {
        const theme = container.getAttribute('data-current-theme') || 'system';
        const locale = container.getAttribute('data-current-locale') || '';
        document.documentElement.dataset.theme = theme;
        if (locale !== '') {
            document.documentElement.lang = locale;
        }

        const trigger = document.querySelector('.profile-menu-trigger');
        if (!trigger) {
            return;
        }

        const displayName = container.getAttribute('data-profile-display-name') || '';
        const avatarSrc = container.getAttribute('data-profile-avatar-src') || '';
        const avatarInitial = container.getAttribute('data-profile-avatar-initial') || '?';
        const avatarColor = container.getAttribute('data-profile-avatar-color') || '#475569';
        const nameNode = trigger.querySelector('span:not(.avatar-square)');
        if (nameNode && displayName !== '') {
            nameNode.textContent = displayName;
        }

        const currentAvatar = trigger.querySelector('.avatar-square');
        if (!currentAvatar) {
            return;
        }

        if (avatarSrc !== '') {
            const image = document.createElement('img');
            image.className = 'avatar-square avatar-image';
            image.src = avatarSrc;
            image.alt = displayName;
            image.width = 32;
            image.height = 32;
            image.decoding = 'async';
            image.loading = 'eager';
            currentAvatar.replaceWith(image);
        } else {
            const fallback = document.createElement('span');
            fallback.className = 'avatar-square';
            fallback.style.background = avatarColor;
            fallback.textContent = avatarInitial;
            currentAvatar.replaceWith(fallback);
        }
    };

    const renderPage = (html, url, pushState) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const incoming = doc.querySelector(containerSelector);
        const current = document.querySelector(containerSelector);
        if (!incoming || !current) {
            window.location.href = url;
            return;
        }

        current.replaceWith(incoming);
        document.title = incoming.getAttribute('data-page-title') || doc.title || document.title;
        applyPageMetadata(incoming);
        executeScripts(incoming);
        showToasts(incoming);
        if (window.passwayFormatLocalDatetimes && typeof window.passwayFormatLocalDatetimes === 'function') {
            window.passwayFormatLocalDatetimes(incoming);
        }

        if (pushState) {
            history.pushState({ profileSettings: true }, '', url);
        }
    };

    const loadPage = async (url, pushState = true) => {
        const html = await fetchPage(url);
        renderPage(html, url, pushState);
    };

    const submitForm = async (form, submitter) => {
        if (form.getAttribute('data-profile-settings-submitting') === 'true') {
            return;
        }

        const action = submitter && submitter.hasAttribute('formaction')
            ? submitter.formAction
            : (form.action || window.location.href);
        const method = (submitter && submitter.hasAttribute('formmethod')
            ? submitter.getAttribute('formmethod')
            : (form.getAttribute('method') || 'GET')).toUpperCase();
        const body = new FormData(form);
        if (submitter && submitter.name) {
            body.append(submitter.name, submitter.value || '');
        }

        form.setAttribute('data-profile-settings-submitting', 'true');
        const fields = Array.from(form.querySelectorAll('button, input, select, textarea'));
        fields.forEach((field) => {
            field.disabled = true;
        });

        cache.clear();

        const response = await fetch(action, {
            method,
            body,
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Failed to submit profile settings form');
        }

        const html = await response.text();
        renderPage(html, window.location.href, false);
    };

    window.passwayProfileSettingsSubmit = (event, form) => {
        if (!form || !form.matches(formSelector)) {
            return true;
        }

        if (event) {
            event.preventDefault();
        }

        const submitter = event && 'submitter' in event ? event.submitter : null;
        submitForm(form, submitter).catch((error) => {
            form.removeAttribute('data-profile-settings-submitting');
            form.querySelectorAll('button, input, select, textarea').forEach((field) => {
                field.disabled = false;
            });

            if (window.passwayToast && typeof window.passwayToast.show === 'function') {
                window.passwayToast.show(error instanceof Error ? error.message : 'Failed to submit profile settings form', 'error');
            }
        });

        return false;
    };

    document.addEventListener('click', (event) => {
        if (!document.querySelector(containerSelector)) {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        if (target === null) {
            return;
        }

        const modalActions = [
            ['.js-open-email-modal', '#profile-email-modal', 'open'],
            ['.js-close-email-modal', '#profile-email-modal', 'close'],
            ['.js-open-password-modal', '#profile-password-modal', 'open'],
            ['.js-close-password-modal', '#profile-password-modal', 'close'],
        ];

        for (const [buttonSelector, dialogSelector, action] of modalActions) {
            if (!target.closest(buttonSelector)) {
                continue;
            }

            const dialog = document.querySelector(dialogSelector);
            if (!(dialog instanceof HTMLDialogElement)) {
                return;
            }

            event.preventDefault();
            if (action === 'open' && !dialog.open) {
                dialog.showModal();
            } else if (action === 'close' && dialog.open) {
                dialog.close();
            }
            return;
        }
    });

    document.addEventListener('click', (event) => {
        if (!document.querySelector(containerSelector)) {
            return;
        }

        const link = closestLink(event.target);
        if (!link || !isPlainLeftClick(event)) {
            return;
        }

        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.href === window.location.href) {
            return;
        }

        event.preventDefault();
        loadPage(url.href).catch(() => {
            window.location.href = url.href;
        });
    });

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented || !document.querySelector(containerSelector)) {
            return;
        }

        const form = event.target instanceof Element ? event.target.closest(formSelector) : null;
        if (!form) {
            return;
        }

        window.passwayProfileSettingsSubmit(event, form);
    });

    document.addEventListener('mouseover', (event) => {
        if (!document.querySelector(containerSelector)) {
            return;
        }

        const link = closestLink(event.target);
        if (link) {
            fetchPage(link.href).catch(() => {});
        }
    });

    document.addEventListener('focusin', (event) => {
        if (!document.querySelector(containerSelector)) {
            return;
        }

        const link = closestLink(event.target);
        if (link) {
            fetchPage(link.href).catch(() => {});
        }
    });

    window.addEventListener('popstate', () => {
        if (!document.querySelector(containerSelector)) {
            return;
        }

        loadPage(window.location.href, false).catch(() => {
            window.location.reload();
        });
    });
})();
</script>
