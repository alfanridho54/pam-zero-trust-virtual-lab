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
            grid-template-columns: repeat(3, minmax(0, 1fr));
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
        .stack { display: grid; gap: 18px; }
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
        .btn-primary { background: var(--blue); color: #fff; border-color: var(--blue); }
        .btn-danger { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
        .btn:disabled { cursor: not-allowed; opacity: .55; }
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
        .badge-expired { background: #ffedd5; color: #9a3412; }
        .badge-revoked { background: #f3e8ff; color: #6b21a8; }
        .badge-closed { background: #e2e8f0; color: #334155; }
        .badge-ended { background: #fee2e2; color: #991b1b; }
        .panel { padding: 18px; margin-bottom: 18px; }
        .panel-title { margin: 0 0 14px; font-size: 16px; font-weight: 850; }
        .command-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: end;
        }
        .field label {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .field input {
            width: 100%;
            min-height: 38px;
            padding: 0 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--text);
            font: 13px ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 750;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .log-list { display: grid; gap: 12px; }
        .log-item {
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
        }
        .log-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            font-size: 12px;
            color: var(--muted);
        }
        .log-command,
        .log-output {
            margin: 0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font: 13px/1.55 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
        .log-command { color: var(--text); }
        .log-output {
            margin-top: 10px;
            padding: 12px;
            border-radius: 8px;
            background: #0f172a;
            color: #cbd5e1;
        }

        @media (max-width: 900px) {
            .page { padding: 18px; }
            .topbar { display: block; }
            .actions { margin-top: 14px; }
            .meta { grid-template-columns: 1fr; }
            .command-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
@php
    $status = $terminalSession->status->value;
    $statusClass = match ($status) {
        'active' => 'badge-active',
        'expired' => 'badge-expired',
        'revoked' => 'badge-revoked',
        'closed' => 'badge-closed',
        default => 'badge-pending',
    };
    $canRunCommand = ! $terminalSession->isEnded() && ! $terminalSession->isExpired();
    $remainingSeconds = $terminalSession->expires_at ? now()->diffInSeconds($terminalSession->expires_at, false) : null;
    $showExpirationWarning = $canRunCommand && $remainingSeconds !== null && $remainingSeconds > 0 && $remainingSeconds <= 300;
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
            <div class="meta-label">Connected VM</div>
            <div class="meta-value">{{ $terminalSession->vm?->name ?? '-' }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-label">SSH Host</div>
            <div class="meta-value">{{ $terminalSession->ssh_host }}:{{ $terminalSession->ssh_port }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-label">SSH User</div>
            <div class="meta-value">{{ $terminalSession->ssh_username }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Started</div>
            <div class="meta-value">{{ $terminalSession->started_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Expires</div>
            <div class="meta-value">{{ $terminalSession->expires_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Last Activity</div>
            <div class="meta-value">{{ $terminalSession->last_activity_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    @if ($showExpirationWarning)
        <div class="alert alert-warning">Session expires in less than 5 minutes.</div>
    @endif

    <section class="card panel">
        <h2 class="panel-title">POC SSH Command</h2>
        <form class="command-form" method="POST" action="{{ route('terminal-sessions.commands.store', $terminalSession) }}">
            @csrf
            <div class="field">
                <label for="command">Command</label>
                <input id="command" name="command" value="{{ old('command', $defaultCommand) }}" autocomplete="off" @disabled(! $canRunCommand)>
            </div>
            <button class="btn btn-primary" type="submit" @disabled(! $canRunCommand)>Run Test Command</button>
        </form>
    </section>

    <div class="stack">
        <section class="terminal">
            <div><span class="prompt">{{ $terminalSession->ssh_username }}@virtual-lab</span>:~$ <span class="muted">SSH command POC ready.</span></div>
            <div class="muted">No websocket or interactive shell is enabled yet.</div>
            <div class="muted">Session ID: {{ $terminalSession->session_uuid }}</div>
        </section>

        <section class="card panel">
            <h2 class="panel-title">Recent Command Output</h2>
            <div class="log-list">
                @forelse ($commandLogs as $commandLog)
                    <article class="log-item">
                        <div class="log-head">
                            <span>{{ $commandLog->executed_at?->format('Y-m-d H:i:s') }}</span>
                            <span>{{ $commandLog->status->value }} @if($commandLog->duration_ms !== null) · {{ $commandLog->duration_ms }} ms @endif</span>
                        </div>
                        <pre class="log-command">{{ $commandLog->command }}</pre>
                        @if ($commandLog->blocked_reason)
                            <pre class="log-output">{{ $commandLog->blocked_reason }}</pre>
                        @elseif ($commandLog->output_excerpt)
                            <pre class="log-output">{{ $commandLog->output_excerpt }}</pre>
                        @endif
                    </article>
                @empty
                    <div class="muted">No commands have been run for this session.</div>
                @endforelse
            </div>
        </section>
    </div>
</main>
</body>
</html>
