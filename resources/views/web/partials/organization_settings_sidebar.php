<aside class="panel settings-sidebar" style="padding:1rem;">
    <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>"><?= e(__('ui.app.back_to_organization')) ?></a>
    <nav class="settings-nav" aria-label="<?= e(__('ui.titles.manage_organization')) ?>">
        <?php
        $settingsLinks = [
            ['key' => 'settings', 'href' => '/organizations/' . $organization->uuid . '/manage/settings', 'label' => __('ui.organization_manage.sections.settings')],
            ['key' => 'api-keys', 'href' => '/organizations/' . $organization->uuid . '/api-keys', 'label' => __('ui.organization_manage.sections.api_keys')],
            ['key' => 'integrations', 'href' => '/organizations/' . $organization->uuid . '/integrations', 'label' => __('ui.organization_manage.sections.integrations')],
        ];

        if (!is_solo_mode()) {
            array_splice($settingsLinks, 1, 0, [
                ['key' => 'members', 'href' => '/organizations/' . $organization->uuid . '/manage/members', 'label' => __('ui.organization_manage.sections.members')],
                ['key' => 'invites', 'href' => '/organizations/' . $organization->uuid . '/manage/invites', 'label' => __('ui.organization_manage.sections.invites')],
                ['key' => 'groups', 'href' => '/organizations/' . $organization->uuid . '/groups', 'label' => __('ui.organization_manage.sections.groups')],
            ]);
        }
        ?>
        <?php foreach ($settingsLinks as $link): ?>
            <?php $isActive = ($activeSettingsSection ?? '') === $link['key']; ?>
            <a
                class="button secondary<?= $isActive ? ' is-active' : '' ?>"
                href="<?= e($link['href']) ?>"
                data-organization-settings-link="true"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            ><?= e($link['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>

<script>
(() => {
    if (window.passwayOrganizationSettingsNavInitialized) {
        return;
    }
    window.passwayOrganizationSettingsNavInitialized = true;

    const cache = new Map();
    const selector = '[data-organization-settings-link="true"]';
    const formSelector = '[data-organization-settings-form="true"]';
    const containerSelector = '.js-organization-settings-page';

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
                    throw new Error('Failed to load settings section');
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
        executeScripts(incoming);
        showToasts(incoming);

        if (pushState) {
            history.pushState({ organizationSettings: true }, '', url);
        }
    };

    const loadPage = async (url, pushState = true) => {
        const html = await fetchPage(url);
        renderPage(html, url, pushState);
    };

    const submitForm = async (form, submitter) => {
        if (form.getAttribute('data-organization-settings-submitting') === 'true') {
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

        form.setAttribute('data-organization-settings-submitting', 'true');
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
            throw new Error('Failed to submit settings form');
        }

        const html = await response.text();
        renderPage(html, window.location.href, false);
    };

    window.passwayOrganizationSettingsSubmit = (event, form) => {
        if (!form || !form.matches(formSelector)) {
            return true;
        }

        if (event) {
            event.preventDefault();
        }

        const submitter = event && 'submitter' in event ? event.submitter : null;
        submitForm(form, submitter).catch((error) => {
            form.removeAttribute('data-organization-settings-submitting');
            form.querySelectorAll('button, input, select, textarea').forEach((field) => {
                field.disabled = false;
            });

            if (window.passwayToast && typeof window.passwayToast.show === 'function') {
                window.passwayToast.show(error instanceof Error ? error.message : 'Failed to submit settings form', 'error');
            }
        });

        return false;
    };

    document.addEventListener('click', (event) => {
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
        if (event.defaultPrevented) {
            return;
        }

        const form = event.target instanceof Element ? event.target.closest(formSelector) : null;
        if (!form) {
            return;
        }

        window.passwayOrganizationSettingsSubmit(event, form);
    });

    document.addEventListener('mouseover', (event) => {
        const link = closestLink(event.target);
        if (link) {
            fetchPage(link.href).catch(() => {});
        }
    });

    document.addEventListener('focusin', (event) => {
        const link = closestLink(event.target);
        if (link) {
            fetchPage(link.href).catch(() => {});
        }
    });

    window.addEventListener('popstate', () => {
        loadPage(window.location.href, false).catch(() => {
            window.location.reload();
        });
    });
})();
</script>
