<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e((string) ($title ?? 'Passway')) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #0b1020; color: #e5e7eb; line-height: 1.5; }
        a { color: inherit; text-decoration: none; }
        .shell { min-height: 100vh; background: radial-gradient(circle at top left, rgba(79,70,229,.35), transparent 34%), radial-gradient(circle at bottom right, rgba(14,165,233,.22), transparent 28%), #0b1020; }
        .container { width: min(1160px, calc(100vw - 2rem)); margin: 0 auto; padding-bottom: 2rem; }
        .topbar { display:flex; align-items:center; justify-content:space-between; padding:1rem 0; gap:1rem; }
        .brand { font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#c4b5fd; }
        .topnav { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; }
        .panel { background: rgba(15,23,42,.86); border:1px solid rgba(148,163,184,.14); border-radius:18px; box-shadow:0 22px 60px rgba(0,0,0,.25); }
        .button, button { display:inline-flex; align-items:center; justify-content:center; border:none; border-radius:12px; padding:.8rem 1rem; background:#4f46e5; color:#fff; font-weight:600; cursor:pointer; transition: background-color .18s ease, transform .18s ease, border-color .18s ease; }
        .button.secondary { background: rgba(148,163,184,.12); color:#e5e7eb; }
        .button.danger, button.danger { background:#b91c1c; }
        .button:hover, button:hover { transform: translateY(-1px); }
        input, select, textarea { width:100%; border-radius:12px; border:1px solid rgba(148,163,184,.18); background:#0f172a; color:#e5e7eb; padding:.8rem .9rem; }
        label { display:block; font-size:.9rem; color:#cbd5e1; margin-bottom:.4rem; }
        .grid { display:grid; gap:1rem; }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .grid-2-compact { grid-template-columns: 1.2fr .8fr; }
        .grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .sidebar-layout { grid-template-columns: minmax(240px, 280px) minmax(0, 1fr); }
        .field-actions-2 { grid-template-columns: minmax(0, 1fr) auto; align-items: end; }
        .field-actions-3 { grid-template-columns: minmax(0, 1fr) auto auto; align-items: end; }
        .stack-sm { display:grid; gap:.75rem; }
        .actions { display:flex; gap:.75rem; flex-wrap:wrap; }
        .panel-muted { background:rgba(15,23,42,.55); }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .pill { display:inline-flex; align-items:center; gap:.35rem; padding:.3rem .55rem; border-radius:999px; border:1px solid rgba(148,163,184,.16); background:rgba(148,163,184,.1); color:#cbd5e1; font-size:.82rem; font-weight:600; }
        .muted { color:#94a3b8; }
        .error { border:1px solid rgba(248,113,113,.28); background:rgba(127,29,29,.3); color:#fecaca; padding:.9rem 1rem; border-radius:14px; }
        .success { border:1px solid rgba(74,222,128,.24); background:rgba(20,83,45,.35); color:#bbf7d0; padding:.9rem 1rem; border-radius:14px; }
        pre { margin:0; white-space:pre-wrap; word-break:break-word; }
        textarea { resize: vertical; }
        input:focus, select:focus, textarea:focus, button:focus, .button:focus { outline:2px solid rgba(129,140,248,.65); outline-offset:2px; }
        @media (max-width: 900px) {
            .topbar { flex-direction:column; align-items:flex-start; }
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
