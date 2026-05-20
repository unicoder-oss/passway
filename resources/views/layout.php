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
        .topbar-title { color: var(--muted); margin-top: .35rem; font-size: .92rem; }
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
        }
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
        .field-actions-2 { grid-template-columns: minmax(0, 1fr) auto; align-items: end; }
        .field-actions-3 { grid-template-columns: minmax(0, 1fr) auto auto; align-items: end; }
        .stack-sm { display: grid; gap: .75rem; }
        .actions { display: flex; gap: .75rem; flex-wrap: wrap; }
        .actions-end { display: flex; gap: .75rem; flex-wrap: wrap; justify-content: flex-end; }
        .panel-muted { background: var(--panel-subtle); }
        .mono { font-family: inherit; }
        .hidden { display: none !important; }
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
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="container">
        <?= $content ?>
    </div>
</div>
</body>
</html>
