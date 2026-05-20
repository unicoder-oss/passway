<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e((string) ($title ?? 'Passway')) ?></title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f5f5f5;
            --fg: #161616;
            --muted: #606060;
            --panel: #ffffff;
            --panel-subtle: #ededed;
            --border: #d0d0d0;
            --button: #4b4b4b;
            --button-fg: #ffffff;
            --button-secondary: #e5e5e5;
            --button-secondary-fg: #161616;
            --accent-soft: #7a2e8a;
            --accent-link: #0f5cc0;
            --danger: #8f1d1d;
            --success-bg: #e7f2e9;
            --success-border: #9cb69f;
            --success-fg: #204028;
            --error-bg: #f5e6e6;
            --error-border: #c79494;
            --error-fg: #5f1e1e;
            --shadow: 0 12px 32px rgba(0, 0, 0, .05);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111111;
                --fg: #f3f3f3;
                --muted: #a4a4a4;
                --panel: #1a1a1a;
                --panel-subtle: #242424;
                --border: #393939;
                --button: #d6d6d6;
                --button-fg: #111111;
                --button-secondary: #2a2a2a;
                --button-secondary-fg: #f3f3f3;
                --accent-soft: #d78cff;
                --accent-link: #7ec8ff;
                --danger: #dc6b6b;
                --success-bg: #16301d;
                --success-border: #2e5b38;
                --success-fg: #d8efdd;
                --error-bg: #351b1b;
                --error-border: #6a2d2d;
                --error-fg: #f1cdcd;
                --shadow: none;
            }
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            background: var(--bg);
            color: var(--fg);
            line-height: 1.5;
        }
        a { color: inherit; text-decoration: none; }
        .shell { min-height: 100vh; background: var(--bg); }
        .container { width: min(1080px, calc(100vw - 2rem)); margin: 0 auto; padding: 0 0 2rem; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 0 1.5rem; }
        .brand { display: inline-block; font-weight: 700; letter-spacing: .02em; text-transform: lowercase; }
        .topnav { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .profile-link {
            display: inline-flex;
            align-items: center;
            gap: .7rem;
            min-height: 44px;
            padding: .55rem .75rem;
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--fg);
            cursor: pointer;
        }
        .profile-menu { position: relative; }
        .profile-menu summary { list-style: none; cursor: pointer; }
        .profile-menu summary::-webkit-details-marker { display: none; }
        .profile-menu:not([open]) .profile-menu-panel { display: none; }
        .profile-menu-panel {
            position: absolute;
            right: 0;
            top: calc(100% + .5rem);
            min-width: 180px;
            padding: .75rem;
            display: grid;
            gap: .5rem;
            z-index: 20;
        }
        .profile-menu.is-open .profile-menu-panel,
        .profile-menu:focus-within .profile-menu-panel { display: grid; }
        .avatar-square {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            color: #fff;
            font-weight: 700;
            overflow: hidden;
        }
        .avatar-image { object-fit: cover; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        .button, button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--button);
            padding: .8rem 1rem;
            background: var(--button);
            color: var(--button-fg);
            font: inherit;
            cursor: pointer;
            transition: opacity .15s ease, background-color .15s ease, border-color .15s ease;
        }
        .button.secondary {
            background: var(--button-secondary);
            color: var(--button-secondary-fg);
            border-color: var(--border);
        }
        .button.secondary.is-active,
        .button.secondary[aria-current="page"] {
            background: var(--button);
            color: var(--button-fg);
            border-color: var(--button);
        }
        .button.danger, button.danger {
            background: var(--danger);
            border-color: var(--danger);
            color: #fff;
        }
        .button:hover, button:hover, .profile-link:hover { opacity: .88; }
        input, select, textarea {
            width: 100%;
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--fg);
            padding: .8rem .9rem;
            font: inherit;
        }
        label { display: block; font-size: .9rem; color: var(--muted); margin-bottom: .4rem; }
        .grid { display: grid; gap: 1rem; }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .grid-2-compact { grid-template-columns: 1.2fr .8fr; }
        .grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .sidebar-layout { grid-template-columns: minmax(240px, 300px) minmax(0, 1fr); }
        .settings-sidebar {
            position: sticky;
            top: 1rem;
            display: grid;
            gap: 1rem;
            align-content: start;
        }
        .settings-nav {
            display: grid;
            gap: .65rem;
        }
        .settings-nav .button {
            width: 100%;
            justify-content: flex-start;
            text-align: left;
        }
        .field-actions-2 { grid-template-columns: minmax(0, 1fr) auto; align-items: end; }
        .field-actions-3 { grid-template-columns: minmax(0, 1fr) auto auto; align-items: end; }
        .stack-sm { display: grid; gap: .75rem; }
        .actions { display: flex; gap: .75rem; flex-wrap: wrap; }
        .actions-end { display: flex; gap: .75rem; flex-wrap: wrap; justify-content: flex-end; }
        .panel-muted { background: var(--panel-subtle); }
        .mono { font-family: inherit; }
        .hidden { display: none !important; }
        .inline-check {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: .5rem;
            width: fit-content;
            margin: 0;
            text-align: left;
        }
        .inline-check input {
            width: auto;
            margin: 0;
            flex: 0 0 auto;
        }
        .inline-check span {
            text-align: left;
        }
        .preview-wrap { max-width: 100%; border: 1px solid var(--border); background: var(--panel-subtle); }
        canvas { display: block; width: 100%; height: auto; }
        .range { width: 100%; margin: 0; }
        .template-range-inputs input[type="range"] {
            -webkit-appearance: none;
            appearance: none;
            background: transparent;
            padding: 0;
            border: 0;
            height: 20px;
        }
        .template-range-inputs input[type="range"]::-webkit-slider-runnable-track {
            height: 4px;
            background: var(--border);
            border-radius: 999px;
        }
        .template-range-inputs input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #93c5fd;
            border: 0;
            margin-top: -6px;
            cursor: pointer;
        }
        .template-range-inputs input[type="range"]::-moz-range-track {
            height: 4px;
            background: var(--border);
            border-radius: 999px;
        }
        .template-range-inputs input[type="range"]::-moz-range-thumb {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #93c5fd;
            border: 0;
            cursor: pointer;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .3rem .55rem;
            border: 1px solid var(--border);
            background: var(--panel-subtle);
            color: var(--muted);
            font-size: .82rem;
            font-weight: 600;
        }
        .muted { color: var(--muted); }
        .audit-title-accent {
            color: var(--accent-soft);
        }
        .audit-title-link {
            color: var(--accent-link);
            text-decoration: underline;
            text-decoration-thickness: .08em;
            text-underline-offset: .14em;
        }
        .audit-title-link:hover,
        .audit-title-link:focus {
            opacity: .88;
        }
        .error {
            border: 1px solid var(--error-border);
            background: var(--error-bg);
            color: var(--error-fg);
            padding: .9rem 1rem;
            margin-bottom: 1rem;
        }
        .success {
            border: 1px solid var(--success-border);
            background: var(--success-bg);
            color: var(--success-fg);
            padding: .9rem 1rem;
            margin-bottom: 1rem;
        }
        .toast-region {
            position: fixed;
            top: 1rem;
            right: 1rem;
            width: min(360px, calc(100vw - 2rem));
            display: grid;
            gap: .75rem;
            z-index: 1000;
            pointer-events: none;
        }
        .toast {
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--fg);
            box-shadow: var(--shadow);
            padding: .9rem 1rem;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: .75rem;
            align-items: start;
            pointer-events: auto;
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity .18s ease, transform .18s ease;
        }
        .toast.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .toast.is-closing {
            opacity: 0;
            transform: translateY(-8px);
        }
        .toast.toast-success {
            border-color: var(--success-border);
            background: var(--success-bg);
            color: var(--success-fg);
        }
        .toast.toast-error {
            border-color: var(--error-border);
            background: var(--error-bg);
            color: var(--error-fg);
        }
        .toast-copy {
            min-width: 0;
            overflow-wrap: anywhere;
        }
        .toast-close {
            border: 0;
            background: transparent;
            color: inherit;
            padding: 0;
            width: 1.5rem;
            height: 1.5rem;
            min-width: 1.5rem;
            min-height: 1.5rem;
            font-size: 1.1rem;
            line-height: 1;
            opacity: .75;
        }
        .toast-close:hover,
        .toast-close:focus {
            opacity: 1;
        }
        dialog.modal {
            width: min(680px, calc(100vw - 2rem));
            margin: auto;
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--fg);
            padding: 0;
            box-shadow: var(--shadow);
        }
        dialog.modal::backdrop { background: rgba(0, 0, 0, .5); }
        .modal-body { padding: 1.25rem; display: grid; gap: 1rem; }
        .wizard-step { display: grid; gap: 1rem; }
        .wizard-meta { color: var(--muted); font-size: .92rem; }
        pre { margin: 0; white-space: pre-wrap; word-break: break-word; }
        textarea { resize: vertical; }
        input:focus, select:focus, textarea:focus, button:focus, .button:focus, .profile-link:focus {
            outline: 2px solid var(--fg);
            outline-offset: 2px;
        }
        @media (max-width: 900px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .topnav { justify-content: flex-start; }
            .grid-2, .grid-2-compact, .grid-4, .sidebar-layout, .field-actions-2, .field-actions-3 { grid-template-columns: 1fr; }
            .toast-region {
                top: .75rem;
                right: 1rem;
                left: 1rem;
                width: auto;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="container">
        <?= $content ?>
    </div>
</div>
<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false"></div>
<script>
(() => {
    const toastRegion = document.getElementById('toast-region');
    const closeToastLabel = <?= json_encode((string) __('ui.app.close_notification')) ?>;

    const createToast = (message, type = 'success', options = {}) => {
        if (!toastRegion || typeof message !== 'string' || message.trim() === '') {
            return null;
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type === 'error' ? 'error' : 'success'}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        const copy = document.createElement('div');
        copy.className = 'toast-copy';
        copy.textContent = message;

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', closeToastLabel);
        close.textContent = 'x';

        const closeToast = () => {
            if (!toast.isConnected) {
                return;
            }
            toast.classList.add('is-closing');
            window.setTimeout(() => {
                toast.remove();
            }, 180);
        };

        close.addEventListener('click', closeToast);
        toast.append(copy, close);
        toastRegion.appendChild(toast);

        window.requestAnimationFrame(() => {
            toast.classList.add('is-visible');
        });

        const duration = typeof options.duration === 'number' ? options.duration : 5000;
        if (duration > 0) {
            window.setTimeout(closeToast, duration);
        }

        return toast;
    };

    window.passwayToast = {
        show(message, type = 'success', options = {}) {
            return createToast(message, type, options);
        },
    };

    document.querySelectorAll('[data-toast="true"]').forEach((element) => {
        const type = element.classList.contains('error') ? 'error' : 'success';
        const message = element.textContent || '';
        createToast(message, type);
        element.remove();
    });

    const parseUtcDate = (value) => {
        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }

        const normalized = value.includes('T') ? value : value.replace(' ', 'T');
        const withTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized) ? normalized : `${normalized}Z`;
        const date = new Date(withTimezone);

        return Number.isNaN(date.getTime()) ? null : date;
    };

    const formatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });

    document.querySelectorAll('[data-local-datetime]').forEach((element) => {
        const rawValue = element.getAttribute('data-local-datetime');
        const parsed = parseUtcDate(rawValue);

        if (parsed === null) {
            return;
        }

        element.textContent = formatter.format(parsed);
        if (rawValue) {
            element.title = `${rawValue} UTC`;
        }
    });
})();
</script>
</body>
</html>
