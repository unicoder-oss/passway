<?php
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid, 'label' => __('ui.app.back_to_organization')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');

$actionMetaJson = json_encode($filterOptions['actionMeta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$hasAdvancedFilters = $filters['ip_address'] !== '';
$autocompleteStringsJson = json_encode([
    'noMatches' => __('ui.audit.autocomplete_no_matches'),
    'loading' => __('ui.audit.autocomplete_loading'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$memberAutocompleteOptions = array_map(
    static fn(array $member): array => [
        'value' => (string) $member['email'],
        'label' => (string) ($member['display_label'] ?? $member['email']),
        'avatar_path' => (string) ($member['avatar_path'] ?? ''),
        'avatar_initial' => (string) ($member['avatar_initial'] ?? '?'),
        'avatar_color' => (string) ($member['avatar_color'] ?? avatar_fallback_color()),
    ],
    $filterOptions['members']
);
$groupAutocompleteOptions = array_map(
    static fn(array $group): array => [
        'value' => (string) $group['uuid'],
        'label' => (string) $group['name'],
    ],
    $filterOptions['groups']
);
$apiKeyAutocompleteOptions = array_map(
    static fn(array $apiKey): array => [
        'value' => (string) $apiKey['uuid'],
        'label' => (string) $apiKey['name'],
    ],
    $filterOptions['apiKeys']
);
$integrationAutocompleteOptions = array_map(
    static fn(array $integration): array => [
        'value' => (string) $integration['uuid'],
        'label' => (string) $integration['name'],
    ],
    $filterOptions['integrations']
);
$rotationServiceAutocompleteOptions = array_map(
    static fn(array $service): array => [
        'value' => (string) $service['uuid'],
        'label' => (string) $service['name'],
    ],
    $filterOptions['rotationServices']
);

$findSelectedLabel = static function (array $options, string $selectedValue): string {
    foreach ($options as $option) {
        if ((string) ($option['value'] ?? '') === $selectedValue) {
            return (string) ($option['label'] ?? '');
        }
    }

    return '';
};

$renderAuditAvatar = static function (?array $avatar, string $className, string $alt = ''): void {
    if ($avatar === null) {
        return;
    }

    $path = (string) ($avatar['path'] ?? '');
    $initial = (string) ($avatar['initial'] ?? '?');
    $color = (string) ($avatar['color'] ?? avatar_fallback_color());

    if ($path !== '') {
        ?><img class="avatar-square avatar-image <?= e($className) ?>" src="<?= e($path) ?>" alt="<?= e($alt) ?>" decoding="async" loading="lazy"><?php
        return;
    }

    ?><span class="avatar-square <?= e($className) ?>" style="background: <?= e($color) ?>;"><?= e($initial) ?></span><?php
};

$renderAutocomplete = static function (
    string $fieldKey,
    string $label,
    string $name,
    string $selectedValue,
    string $selectedLabel,
    string $placeholder,
    ?string $optionsJson = null,
    ?string $asyncUrl = null,
    bool $submitRaw = false,
) {
    ?>
    <div data-audit-filter="<?= e($fieldKey) ?>">
        <label for="<?= e($fieldKey) ?>_display"><?= e($label) ?></label>
        <div class="audit-autocomplete" data-audit-autocomplete="true"<?= $optionsJson !== null ? ' data-options="' . e($optionsJson) . '"' : '' ?><?= $asyncUrl !== null ? ' data-async-url="' . e($asyncUrl) . '"' : '' ?><?= $submitRaw ? ' data-submit-raw="true"' : '' ?>>
            <?php if ($submitRaw): ?>
                <input type="text" id="<?= e($fieldKey) ?>_display" name="<?= e($name) ?>" value="<?= e($selectedValue) ?>" placeholder="<?= e($placeholder) ?>" autocomplete="off" data-autocomplete-input>
            <?php else: ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($selectedValue) ?>" data-autocomplete-value>
                <input type="text" id="<?= e($fieldKey) ?>_display" value="<?= e($selectedLabel) ?>" placeholder="<?= e($placeholder) ?>" autocomplete="off" data-autocomplete-input>
            <?php endif; ?>
            <div class="audit-autocomplete-list hidden" data-autocomplete-list></div>
        </div>
    </div>
    <?php
};
?>

<style>
    .audit-autocomplete {
        position: relative;
    }

    .audit-autocomplete-list {
        position: absolute;
        top: calc(100% + .35rem);
        left: 0;
        right: 0;
        z-index: 30;
        max-height: 240px;
        overflow: auto;
        border: 1px solid var(--border);
        background: var(--panel);
        box-shadow: var(--shadow);
        display: grid;
        gap: 0;
    }

    .audit-autocomplete-option {
        padding: .7rem .8rem;
        cursor: pointer;
        border: 0;
        background: transparent;
        color: var(--fg);
        font: inherit;
        text-align: left;
        width: 100%;
    }

    .audit-autocomplete-option-content {
        display: flex;
        align-items: center;
        gap: .6rem;
        min-width: 0;
    }

    .audit-autocomplete-option:hover,
    .audit-autocomplete-option:focus,
    .audit-autocomplete-option.is-active {
        background: var(--panel-subtle);
        outline: none;
    }

    .audit-autocomplete-empty {
        padding: .7rem .8rem;
        color: var(--muted);
    }

    .audit-avatar-sm {
        width: 28px;
        height: 28px;
        flex-basis: 28px;
        font-size: .82rem;
    }

    .audit-avatar-inline {
        width: 1.15em;
        height: 1.15em;
        flex-basis: 1.15em;
        font-size: .6em;
    }

    .audit-avatar-md {
        width: 40px;
        height: 40px;
        flex-basis: 40px;
        font-size: 1rem;
    }

    .audit-entry-with-avatar {
        display: flex;
        align-items: flex-start;
        gap: .85rem;
    }

    .audit-entry-body {
        min-width: 0;
        flex: 1 1 auto;
    }

    .audit-title-entity {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        vertical-align: middle;
    }
</style>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.audit.for_org', ['organization' => $organization->name])) ?></h1>
</section>

<section class="panel" style="padding:1.5rem; margin-bottom:1rem;">
    <form method="GET" class="grid grid-4" style="gap:1rem;">
        <div>
            <label for="action"><?= e(__('ui.audit.event')) ?></label>
            <select id="action" name="action">
                <option value=""><?= e(__('ui.audit.all_events')) ?></option>
                <?php foreach ($filterOptions['actionGroups'] as $group): ?>
                    <optgroup label="<?= e((string) $group['label']) ?>">
                        <?php foreach ($group['actions'] as $action): ?>
                            <option value="<?= e((string) $action['value']) ?>" <?= $filters['action'] === $action['value'] ? 'selected' : '' ?>><?= e((string) $action['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="actor_kind"><?= e(__('ui.audit.actor')) ?></label>
            <select id="actor_kind" name="actor_kind">
                <?php foreach ($filterOptions['actorKinds'] as $actorKind): ?>
                    <option value="<?= e((string) $actorKind['value']) ?>" <?= $filters['actor_kind'] === $actorKind['value'] ? 'selected' : '' ?>><?= e((string) $actorKind['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php $renderAutocomplete(
            'actor_user_email',
            __('ui.audit.actor_user'),
            'actor_user_email',
            $filters['actor_user_email'],
            $filters['actor_user_email'],
            __('ui.audit.actor_user_placeholder'),
            json_encode($memberAutocompleteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null,
            true,
        ); ?>

        <?php $renderAutocomplete(
            'actor_api_key_uuid',
            __('ui.audit.actor_api_key'),
            'actor_api_key_uuid',
            $filters['actor_api_key_uuid'],
            $findSelectedLabel($apiKeyAutocompleteOptions, $filters['actor_api_key_uuid']),
            __('ui.audit.autocomplete_placeholder'),
            json_encode($apiKeyAutocompleteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ); ?>

        <?php $renderAutocomplete(
            'target_user_email',
            __('ui.audit.target_user'),
            'target_user_email',
            $filters['target_user_email'],
            $filters['target_user_email'],
            __('ui.audit.target_user_placeholder'),
            json_encode($memberAutocompleteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null,
            true,
        ); ?>

        <?php $renderAutocomplete(
            'group_uuid',
            __('ui.audit.group'),
            'group_uuid',
            $filters['group_uuid'],
            $findSelectedLabel($groupAutocompleteOptions, $filters['group_uuid']),
            __('ui.audit.autocomplete_placeholder'),
            json_encode($groupAutocompleteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ); ?>

        <?php $renderAutocomplete(
            'secret_uuid',
            __('ui.audit.secret'),
            'secret_uuid',
            $filters['secret_uuid'],
            (string) ($filterOptions['selectedSecret']['name'] ?? ''),
            __('ui.audit.secret_placeholder'),
            null,
            (string) $filterOptions['secretSearchUrl'],
        ); ?>

        <?php $renderAutocomplete(
            'api_key_uuid',
            __('ui.audit.api_key'),
            'api_key_uuid',
            $filters['api_key_uuid'],
            $findSelectedLabel($apiKeyAutocompleteOptions, $filters['api_key_uuid']),
            __('ui.audit.autocomplete_placeholder'),
            json_encode($apiKeyAutocompleteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ); ?>

        <?php $renderAutocomplete(
            'integration_uuid',
            __('ui.audit.integration'),
            'integration_uuid',
            $filters['integration_uuid'],
            $findSelectedLabel($integrationAutocompleteOptions, $filters['integration_uuid']),
            __('ui.audit.autocomplete_placeholder'),
            json_encode($integrationAutocompleteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ); ?>

        <?php $renderAutocomplete(
            'rotation_service_uuid',
            __('ui.audit.rotation_service'),
            'rotation_service_uuid',
            $filters['rotation_service_uuid'],
            $findSelectedLabel($rotationServiceAutocompleteOptions, $filters['rotation_service_uuid']),
            __('ui.audit.autocomplete_placeholder'),
            json_encode($rotationServiceAutocompleteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ); ?>

        <div data-audit-filter="role">
            <label for="role"><?= e(__('ui.audit.role')) ?></label>
            <select id="role" name="role">
                <option value=""><?= e(__('ui.audit.any')) ?></option>
                <?php foreach ($filterOptions['roles'] as $role): ?>
                    <option value="<?= e((string) $role['value']) ?>" <?= $filters['role'] === $role['value'] ? 'selected' : '' ?>><?= e((string) $role['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div data-audit-filter="invite_type">
            <label for="invite_type"><?= e(__('ui.audit.invite_type')) ?></label>
            <select id="invite_type" name="invite_type">
                <option value=""><?= e(__('ui.audit.any')) ?></option>
                <?php foreach ($filterOptions['inviteTypes'] as $type): ?>
                    <option value="<?= e((string) $type['value']) ?>" <?= $filters['invite_type'] === $type['value'] ? 'selected' : '' ?>><?= e((string) $type['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="success"><?= e(__('ui.audit.success')) ?></label>
            <select id="success" name="success">
                <option value="" <?= $filters['success'] === '' ? 'selected' : '' ?>><?= e(__('ui.audit.any')) ?></option>
                <option value="1" <?= $filters['success'] === '1' ? 'selected' : '' ?>><?= e(__('ui.app.success')) ?></option>
                <option value="0" <?= $filters['success'] === '0' ? 'selected' : '' ?>><?= e(__('ui.app.failed')) ?></option>
            </select>
        </div>

        <div>
            <label for="from_date"><?= e(__('ui.audit.from_date')) ?></label>
            <input id="from_date" name="from_date" type="date" value="<?= e($filters['from_date']) ?>">
        </div>

        <div>
            <label for="to_date"><?= e(__('ui.audit.to_date')) ?></label>
            <input id="to_date" name="to_date" type="date" value="<?= e($filters['to_date']) ?>">
        </div>

        <details style="grid-column:1 / -1;" <?= $hasAdvancedFilters ? 'open' : '' ?>>
            <summary style="cursor:pointer;"><?= e(__('ui.audit.advanced_filters')) ?></summary>
            <div class="grid grid-4" style="gap:1rem; margin-top:1rem;">
                <div data-audit-filter="ip_address">
                    <label for="ip_address"><?= e(__('ui.audit.ip_address')) ?></label>
                    <input id="ip_address" name="ip_address" value="<?= e($filters['ip_address']) ?>" placeholder="<?= e(__('ui.audit.ip_address_placeholder')) ?>">
                </div>
            </div>
        </details>

        <div class="actions" style="grid-column:1 / -1;">
            <button type="submit"><?= e(__('ui.audit.apply_filters')) ?></button>
            <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/audit"><?= e(__('ui.audit.reset')) ?></a>
        </div>
    </form>
</section>

<?php
$previousQuery = $filters;
$previousQuery['limit'] = (string) $meta['limit'];
$previousQuery['offset'] = (string) max(0, $meta['offset'] - $meta['limit']);
$nextQuery = $filters;
$nextQuery['limit'] = (string) $meta['limit'];
$nextQuery['offset'] = (string) ($meta['offset'] + $meta['limit']);
?>

<section class="panel" style="padding:1.5rem; display:grid; gap:.75rem;">
    <div class="muted"><?= e(__('ui.audit.summary', ['total' => (string) $meta['total'], 'offset' => (string) $meta['offset'], 'limit' => (string) $meta['limit']])) ?></div>
    <?php foreach ($entries as $entry): ?>
        <div class="panel panel-muted<?= !empty($entry['actor_avatar']) ? ' audit-entry-with-avatar' : '' ?>" style="padding:1rem;">
            <?php if (!empty($entry['actor_avatar']) && is_array($entry['actor_avatar'])): ?>
                <?php $renderAuditAvatar($entry['actor_avatar'], 'audit-avatar-md', (string) $entry['actor_label']); ?>
            <?php endif; ?>
            <div class="audit-entry-body">
                <div style="font-weight:700; line-height:1.45;">
                    <?php foreach ($entry['title_parts'] as $part): ?>
                        <?php
                            $partAvatar = isset($part['avatar']) && is_array($part['avatar']) ? $part['avatar'] : null;
                            $partAvatarKind = (string) ($partAvatar['kind'] ?? '');
                            $showPartAvatar = $partAvatar !== null && ($partAvatarKind === 'user' || ($partAvatarKind === 'organization' && $part['href'] !== null));
                        ?>
                        <?php if ($part['href'] !== null): ?><a href="<?= e((string) $part['href']) ?>" class="audit-title-link<?= $showPartAvatar ? ' audit-title-entity' : '' ?>"><?php if ($showPartAvatar) { $renderAuditAvatar($partAvatar, 'audit-avatar-inline', (string) $part['text']); } ?><?= e((string) $part['text']) ?></a><?php else: ?><?php if (!empty($part['accent'])): ?><span class="audit-title-accent<?= $showPartAvatar ? ' audit-title-entity' : '' ?>"><?php if ($showPartAvatar) { $renderAuditAvatar($partAvatar, 'audit-avatar-inline', (string) $part['text']); } ?><?= e((string) $part['text']) ?></span><?php else: ?><?= e((string) $part['text']) ?><?php endif; ?><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="muted" style="font-size:.92rem;"><?= $entry['timestamp_html'] ?> · <?= e((string) $entry['status']) ?> · <?php if ($entry['actor_href'] !== null): ?><a href="<?= e((string) $entry['actor_href']) ?>"><?= e((string) $entry['actor_label']) ?></a><?php else: ?><?= e((string) $entry['actor_label']) ?><?php endif; ?></div>
                <?php foreach (($entry['details'] ?? []) as $detail): ?>
                    <div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e((string) $detail) ?></div>
                <?php endforeach; ?>
                <?php if (($entry['ip_address'] ?? null) !== null): ?><div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e(__('ui.audit.ip', ['ip' => (string) $entry['ip_address']])) ?></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><div class="muted"><?= e(__('ui.audit.no_entries')) ?></div><?php endif; ?>
    <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <?php if ($meta['offset'] > 0): ?><a class="button secondary" href="?<?= e(http_build_query($previousQuery)) ?>"><?= e(__('ui.audit.previous')) ?></a><?php endif; ?>
        <?php if ($meta['has_more']): ?><a class="button secondary" href="?<?= e(http_build_query($nextQuery)) ?>"><?= e(__('ui.audit.next')) ?></a><?php endif; ?>
    </div>
</section>

<script>
(() => {
    const actionSelect = document.getElementById('action');
    const actorKindSelect = document.getElementById('actor_kind');
    const actionMeta = <?= $actionMetaJson ?: '{}' ?>;
    const autocompleteStrings = <?= $autocompleteStringsJson ?: '{}' ?>;

    if (!actionSelect || !actorKindSelect) {
        return;
    }

    const wrappers = new Map();
    document.querySelectorAll('[data-audit-filter]').forEach((element) => {
        wrappers.set(element.getAttribute('data-audit-filter'), element);
    });

    const commonFields = new Set(['actor_user_email', 'success', 'from_date', 'to_date']);

    const normalize = (value) => (value || '').toString().trim().toLowerCase();

    const setWrapperEnabled = (wrapper, enabled) => {
        wrapper.querySelectorAll('input, select, textarea, button').forEach((input) => {
            input.disabled = !enabled;
        });
    };

    const syncVisibility = () => {
        const selectedAction = actionSelect.value || '';
        const selectedActorKind = actorKindSelect.value || '';
        const fields = new Set((actionMeta[selectedAction] && actionMeta[selectedAction].fields) || []);

        wrappers.forEach((element, key) => {
            let visible = commonFields.has(key) || fields.has(key);

            if (key === 'actor_user_email') {
                visible = selectedActorKind !== 'system' && selectedActorKind !== 'api_key';
            }

            if (key === 'actor_api_key_uuid') {
                visible = selectedActorKind === 'api_key' || fields.has('actor_api_key_uuid');
            }

            if (key === 'ip_address') {
                visible = fields.has('ip_address');
            }

            element.classList.toggle('hidden', !visible);
            setWrapperEnabled(element, visible);
        });
    };

    const initAutocomplete = (root) => {
        const input = root.querySelector('[data-autocomplete-input]');
        const hiddenInput = root.querySelector('[data-autocomplete-value]');
        const list = root.querySelector('[data-autocomplete-list]');
        const submitRaw = root.dataset.submitRaw === 'true';
        const asyncUrl = root.dataset.asyncUrl || '';
        const localOptions = root.dataset.options ? JSON.parse(root.dataset.options) : [];
        let activeIndex = -1;
        let currentOptions = localOptions;
        let requestToken = 0;
        let debounceTimer = null;

        if (!input || !list) {
            return;
        }

        const closeList = () => {
            list.classList.add('hidden');
            list.innerHTML = '';
            activeIndex = -1;
        };

        const setSelected = (option) => {
            if (submitRaw) {
                input.value = option.value || '';
            } else {
                if (hiddenInput) {
                    hiddenInput.value = option.value || '';
                }
                input.value = option.label || option.value || '';
            }
            input.dataset.selectedLabel = input.value;
            closeList();
        };

        const renderOptions = (options, loading = false) => {
            currentOptions = options;
            list.innerHTML = '';

            if (loading) {
                const loadingNode = document.createElement('div');
                loadingNode.className = 'audit-autocomplete-empty';
                loadingNode.textContent = autocompleteStrings.loading || 'Loading...';
                list.appendChild(loadingNode);
                list.classList.remove('hidden');
                return;
            }

            if (!options.length) {
                const emptyNode = document.createElement('div');
                emptyNode.className = 'audit-autocomplete-empty';
                emptyNode.textContent = autocompleteStrings.noMatches || 'No matches';
                list.appendChild(emptyNode);
                list.classList.remove('hidden');
                return;
            }

            options.forEach((option, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'audit-autocomplete-option';
                const content = document.createElement('span');
                content.className = 'audit-autocomplete-option-content';
                if (option.avatar_path !== undefined || option.avatar_initial !== undefined || option.avatar_color !== undefined) {
                    if (option.avatar_path) {
                        const image = document.createElement('img');
                        image.className = 'avatar-square avatar-image audit-avatar-sm';
                        image.src = option.avatar_path;
                        image.alt = option.label || option.value || '';
                        image.decoding = 'async';
                        image.loading = 'lazy';
                        content.appendChild(image);
                    } else {
                        const fallback = document.createElement('span');
                        fallback.className = 'avatar-square audit-avatar-sm';
                        fallback.style.background = option.avatar_color || '#475569';
                        fallback.textContent = option.avatar_initial || '?';
                        content.appendChild(fallback);
                    }
                }
                const label = document.createElement('span');
                label.textContent = option.label || option.value || '';
                content.appendChild(label);
                button.appendChild(content);
                button.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    setSelected(option);
                });
                if (index === activeIndex) {
                    button.classList.add('is-active');
                }
                list.appendChild(button);
            });

            list.classList.remove('hidden');
        };

        const loadLocalOptions = () => {
            const query = normalize(input.value);
            const options = !query
                ? localOptions.slice(0, 12)
                : localOptions.filter((option) => normalize(option.label).includes(query) || normalize(option.value).includes(query)).slice(0, 12);

            if (!submitRaw && hiddenInput && query !== normalize(input.dataset.selectedLabel || '')) {
                hiddenInput.value = '';
            }

            renderOptions(options);
        };

        const loadAsyncOptions = () => {
            const query = input.value.trim();
            if (query.length < 2) {
                closeList();
                if (hiddenInput && normalize(query) !== normalize(input.dataset.selectedLabel || '')) {
                    hiddenInput.value = '';
                }
                return;
            }

            requestToken += 1;
            const token = requestToken;
            renderOptions([], true);

            fetch(`${asyncUrl}?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.ok ? response.json() : Promise.reject(new Error('Request failed')))
                .then((payload) => {
                    if (token !== requestToken) {
                        return;
                    }
                    const items = Array.isArray(payload?.data?.items) ? payload.data.items : [];
                    renderOptions(items.map((item) => ({ value: item.uuid || '', label: item.name || '' })));
                })
                .catch(() => {
                    if (token !== requestToken) {
                        return;
                    }
                    renderOptions([]);
                });
        };

        const loadOptions = () => {
            if (asyncUrl) {
                if (debounceTimer !== null) {
                    clearTimeout(debounceTimer);
                }
                debounceTimer = window.setTimeout(loadAsyncOptions, 180);
                return;
            }

            loadLocalOptions();
        };

        input.dataset.selectedLabel = input.value;
        input.addEventListener('focus', loadOptions);
        input.addEventListener('input', loadOptions);
        input.addEventListener('keydown', (event) => {
            const optionButtons = Array.from(list.querySelectorAll('.audit-autocomplete-option'));
            if (!optionButtons.length) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = Math.min(optionButtons.length - 1, activeIndex + 1);
                renderOptions(currentOptions);
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = Math.max(0, activeIndex - 1);
                renderOptions(currentOptions);
                return;
            }

            if (event.key === 'Enter' && activeIndex >= 0 && currentOptions[activeIndex]) {
                event.preventDefault();
                setSelected(currentOptions[activeIndex]);
                return;
            }

            if (event.key === 'Escape') {
                closeList();
            }
        });

        input.addEventListener('blur', () => {
            window.setTimeout(() => {
                closeList();
                if (!submitRaw && hiddenInput && input.value.trim() === '') {
                    hiddenInput.value = '';
                }
                if (!submitRaw && hiddenInput && hiddenInput.value.trim() === '') {
                    input.value = '';
                    input.dataset.selectedLabel = '';
                }
            }, 120);
        });
    };

    document.querySelectorAll('[data-audit-autocomplete="true"]').forEach(initAutocomplete);
    actionSelect.addEventListener('change', syncVisibility);
    actorKindSelect.addEventListener('change', syncVisibility);
    syncVisibility();
})();
</script>
