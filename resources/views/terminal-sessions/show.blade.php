<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terminal Session</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --line: #e5e7eb;
            --muted: #64748b;
            --text: #0f172a;
            --blue: #2563eb;
            --green: #16a34a;
            --amber: #d97706;
            --red: #dc2626;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        a { color: inherit; text-decoration: none; }
        button { font: inherit; }
        .page { min-height: 100vh; padding: 32px; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .title { margin: 0; font-size: 24px; font-weight: 850; }
        .subtitle { margin-top: 5px; color: var(--muted); font-size: 14px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
        }
        .meta {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1px;
            overflow: hidden;
            margin-bottom: 18px;
        }
        .meta-item { padding: 16px; background: #fff; }
        .meta-label { color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .meta-value { margin-top: 6px; font-weight: 800; overflow-wrap: anywhere; }
        .terminal {
            min-height: 360px;
            padding: 18px;
            background: #0f172a;
            color: #cbd5e1;
            border-radius: 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            line-height: 1.6;
        }
        .prompt { color: #86efac; }
        .muted { color: #94a3b8; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
        }
        .btn-soft { background: #eef2ff; color: #3730a3; border-color: #c7d2fe; }
        .btn-danger { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-ended { background: #fee2e2; color: #991b1b; }

        @media (max-width: 900px) {
            .page { padding: 18px; }
            .topbar { display: block; }
            .actions { margin-top: 14px; }
            .meta { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
@php
    $status = $terminalSession->status->value;
    $statusClass = $terminalSession->isActive() ? 'badge-active' : ($terminalSession->isEnded() ? 'badge-ended' : 'badge-pending');
@endphp
<main class="page">
    <div class="topbar">
        <div>
            <h1 class="title">Terminal Session</h1>
            <div class="subtitle">{{ $terminalSession->vm?->name ?? 'VM' }} · SSH/websocket belum diaktifkan</div>
        </div>
        <div class="actions">
            <a class="btn btn-soft" href="{{ route('dashboard.vms') }}">Kembali</a>
            <form method="POST" action="{{ route('terminal-sessions.destroy', $terminalSession) }}">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger" type="submit" @disabled($terminalSession->isEnded())>Close Session</button>
            </form>
        </div>
    </div>

    <section class="card meta">
        <div class="meta-item">
            <div class="meta-label">Status</div>
            <div class="meta-value"><span class="badge {{ $statusClass }}">{{ $status }}</span></div>
        </div>
        <div class="meta-item">
            <div class="meta-label">VM</div>
            <div class="meta-value">{{ $terminalSession->vm?->name ?? '-' }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Target</div>
            <div class="meta-value">{{ $terminalSession->ssh_username }}@{{ $terminalSession->ssh_host }}:{{ $terminalSession->ssh_port }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Expires</div>
            <div class="meta-value">{{ $terminalSession->expires_at?->format('Y-m-d H:i') ?? '-' }}</div>
        </div>
    </section>

    <section class="terminal">
        <div><span class="prompt">student@virtual-lab</span>:~$ <span class="muted">Terminal placeholder ready.</span></div>
        <div class="muted">Real SSH/websocket transport will be attached in the next implementation step.</div>
        <div class="muted">Session ID: {{ $terminalSession->session_uuid }}</div>
    </section>
</main>
</body>
</html>
