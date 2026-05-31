@php
    $status = $terminalSession->status->value;
    $targetUsername = $terminalSession->ssh_username ?: 'student';
    $targetHost = $terminalSession->ssh_host ?: 'virtual-lab';
    $targetPort = $terminalSession->ssh_port ?: 22;
    $targetAddress = $targetUsername . '@' . $targetHost;
    $targetEndpoint = $targetAddress . ':' . $targetPort;
    $prompt = $targetAddress;
    $subtitle = ($terminalSession->vm?->name ?? 'VM') . ' - interactive monitored terminal';
    $logs = $commandLogs ?? collect();
    $accessError = $terminalAccessError ?? null;
    $terminalMode = $terminalMode ?? 'command';
    $statusClass = $terminalSession->isActive()
        ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
        : ($terminalSession->isEnded()
            ? 'bg-red-50 text-red-700 ring-red-600/20'
            : 'bg-amber-50 text-amber-700 ring-amber-600/20');
    $expiresSoon = $terminalSession->expires_at
        && ! $terminalSession->isEnded()
        && $terminalSession->expires_at->isFuture()
        && $terminalSession->expires_at->lte(now()->addMinutes(5));
    $interactiveEnabled = $terminalWebSocketUrl && $terminalWebSocketTicket && ! $terminalSession->isEnded() && ! $terminalSession->isExpired();
@endphp

<x-layouts.student
    title="Terminal Session"
    :subtitle="$subtitle"
    :user="$terminalSession->user"
>
    <div class="space-y-6">
        @if ($expiresSoon)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                Session expires in less than 5 minutes.
            </div>
        @endif

        @if ($accessError)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                {{ $accessError }}
            </div>
        @endif

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-950">Terminal Session</h2>
                <p class="mt-1 text-sm text-slate-500">Interactive monitored terminal session for {{ $terminalSession->vm?->name ?? 'VM' }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('student.vms.index') }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    Back to VMs
                </a>
                <form method="POST" action="{{ route('terminal-sessions.destroy', $terminalSession) }}">
                    @csrf
                    @method('DELETE')
                    <button class="inline-flex min-h-10 items-center rounded-lg border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($terminalSession->isEnded())>
                        Close Session
                    </button>
                </form>
            </div>
        </div>

        <section class="grid grid-cols-1 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:grid-cols-4">
            <div class="border-b border-slate-100 p-5 md:border-b-0 md:border-r">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Status</p>
                <p class="mt-2">
                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold ring-1 ring-inset {{ $statusClass }}">{{ $status }}</span>
                </p>
            </div>
            <div class="border-b border-slate-100 p-5 md:border-b-0 md:border-r">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">VM</p>
                <p class="mt-2 truncate text-sm font-bold text-slate-950">{{ $terminalSession->vm?->name ?? '-' }}</p>
            </div>
            <div class="border-b border-slate-100 p-5 md:border-b-0 md:border-r">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Target</p>
                <p class="mt-2 break-all font-mono text-sm font-bold text-slate-950">{{ $targetEndpoint }}</p>
            </div>
            <div class="p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Expires</p>
                <p class="mt-2 text-sm font-bold text-slate-950">{{ $terminalSession->expires_at?->format('Y-m-d H:i') ?? '-' }}</p>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 shadow-sm xl:col-span-2">
                <div class="flex flex-col gap-3 border-b border-white/10 bg-white/[0.03] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-red-400/80"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-400/80"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/80"></span>
                            <h3 class="ml-2 truncate text-sm font-semibold text-slate-100">Interactive terminal</h3>
                        </div>
                        <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ $terminalWebSocketUrl ?: 'WebSocket URL is not configured.' }}</p>
                    </div>
                    <span id="ws-status" class="inline-flex rounded-full bg-slate-800 px-2.5 py-1 text-xs font-bold text-slate-300">
                        {{ $interactiveEnabled ? ($terminalMode === 'pty' ? 'connecting pty' : 'connecting') : 'unavailable' }}
                    </span>
                </div>

                <div id="ws-terminal" class="min-h-[28rem] bg-slate-950 p-5 font-mono text-sm leading-relaxed text-slate-100">
                    <div class="text-slate-400">Session {{ $terminalSession->session_uuid }}</div>
                    <div class="text-slate-500">{{ $targetEndpoint }}</div>
                    @if ($accessError)
                        <div class="mt-3 text-amber-300">{{ $accessError }}</div>
                    @elseif (! $interactiveEnabled)
                        <div class="mt-3 text-amber-300">Interactive terminal is unavailable for ended, expired, revoked, or closed sessions.</div>
                    @else
                        <div class="mt-3 text-slate-500">{{ $terminalMode === 'pty' ? 'Connecting to interactive PTY shell...' : 'Connecting to monitored WebSocket transport...' }}</div>
                    @endif
                </div>

                <form id="ws-command-form" class="flex flex-col gap-3 border-t border-slate-800 bg-slate-950 px-5 py-4 sm:flex-row" autocomplete="off">
                    <label class="sr-only" for="ws-command">{{ $terminalMode === 'pty' ? 'PTY input' : 'Interactive command' }}</label>
                    <input
                        id="ws-command"
                        class="min-h-11 flex-1 rounded-lg border border-slate-700 bg-slate-900 px-3 font-mono text-sm text-slate-100 outline-none ring-indigo-500/30 placeholder:text-slate-500 focus:border-indigo-500 focus:ring-2 disabled:cursor-not-allowed disabled:opacity-50"
                        placeholder="{{ $terminalMode === 'pty' ? 'Type shell input and press Enter' : 'Type a command and press Enter' }}"
                        @disabled(! $interactiveEnabled)
                    >
                    <button
                        class="inline-flex min-h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        type="submit"
                        @disabled(! $interactiveEnabled)
                    >
                        Send
                    </button>
                </form>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm xl:col-span-1">
                <h3 class="text-base font-semibold text-slate-950">Recent command logs</h3>
                <div class="mt-4 space-y-3">
                    @forelse ($logs as $log)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $log->isBlocked() ? 'bg-red-50 text-red-700' : ($log->isSucceeded() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700') }}">
                                    {{ $log->status->value }}
                                </span>
                                <span class="font-mono text-[11px] text-slate-500">{{ $log->executed_at?->format('H:i:s') }}</span>
                            </div>
                            <code class="mt-2 block break-all font-mono text-xs text-slate-700">{{ $log->command }}</code>
                            @if ($log->blocked_reason)
                                <p class="mt-2 text-xs text-red-600">{{ $log->blocked_reason }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No command logs for this session yet.</p>
                    @endforelse
                </div>
            </div>
        </section>

        <details id="fallback-command-panel" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @if (! $interactiveEnabled) open @endif>
            <summary class="cursor-pointer select-none text-sm font-bold text-slate-950">
                Fallback monitored command form
            </summary>
            <div class="mt-4 border-t border-slate-100 pt-4">
                <p class="text-sm text-slate-500">Use this if the WebSocket terminal is disconnected. Commands still use the same policy checks and command log.</p>
                <form method="POST" action="{{ route('terminal-sessions.commands.store', $terminalSession) }}" class="mt-4 space-y-3">
                    @csrf
                    <textarea name="command" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20" @disabled($terminalSession->isEnded() || $accessError)>{{ old('command', $defaultCommand ?? '') }}</textarea>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-slate-500">Blocked commands are recorded with status <span class="font-mono">blocked</span>.</p>
                        <button class="inline-flex min-h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($terminalSession->isEnded() || $accessError)>
                            Run fallback command
                        </button>
                    </div>
                </form>
            </div>
        </details>
    </div>

    @if ($interactiveEnabled)
        <script>
            (() => {
                const wsUrl = @json($terminalWebSocketUrl);
                const ticket = @json($terminalWebSocketTicket);
                const terminalMode = @json($terminalMode);
                const terminal = document.getElementById('ws-terminal');
                const status = document.getElementById('ws-status');
                const form = document.getElementById('ws-command-form');
                const input = document.getElementById('ws-command');
                const fallbackPanel = document.getElementById('fallback-command-panel');
                let socket = null;
                let prompt = @json($prompt);

                const setStatus = (label, classes) => {
                    status.textContent = label;
                    status.className = `inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${classes}`;
                };

                const writeLine = (text, className = 'text-slate-300') => {
                    const line = document.createElement('div');
                    line.className = `whitespace-pre-wrap break-words ${className}`;
                    line.textContent = text || ' ';
                    terminal.appendChild(line);
                    terminal.scrollTop = terminal.scrollHeight;
                };

                const connect = () => {
                    try {
                        socket = new WebSocket(wsUrl);
                    } catch (error) {
                        setStatus('error', 'bg-red-100 text-red-700');
                        fallbackPanel.open = true;
                        writeLine('WebSocket terminal URL is invalid. Use the monitored command form below.', 'text-amber-300');
                        return;
                    }

                    socket.addEventListener('open', () => {
                        setStatus('authenticating', 'bg-amber-100 text-amber-700');
                        socket.send(JSON.stringify({ type: 'auth', ticket }));
                    });

                    socket.addEventListener('message', (event) => {
                        let message = {};

                        try {
                            message = JSON.parse(event.data);
                        } catch (error) {
                            writeLine('Received invalid WebSocket terminal payload.', 'text-red-300');
                            return;
                        }

                        if (message.type === 'ready') {
                            prompt = message.prompt || prompt;
                            setStatus('connected', 'bg-emerald-100 text-emerald-700');
                            input.disabled = false;
                            writeLine(terminalMode === 'pty' ? `interactive PTY connected to ${prompt}` : `connected to ${prompt}`, 'text-emerald-300');
                            return;
                        }

                        if (message.type === 'running') {
                            writeLine(`${prompt}:~$ ${message.command}`, 'text-slate-100');
                            return;
                        }

                        if (message.type === 'pty_output') {
                            String(message.output || '').split(/\r?\n/).forEach((line) => writeLine(line, 'text-slate-300'));
                            return;
                        }

                        if (['output', 'failed', 'blocked'].includes(message.type)) {
                            const outputClass = message.type === 'output' ? 'text-slate-300' : 'text-red-300';
                            String(message.output || '').split(/\r?\n/).forEach((line) => writeLine(line, outputClass));
                            return;
                        }

                        if (message.type === 'error') {
                            writeLine(message.message || 'WebSocket terminal error.', 'text-red-300');
                        }
                    });

                    socket.addEventListener('close', () => {
                        setStatus('disconnected', 'bg-red-100 text-red-700');
                        input.disabled = true;
                        fallbackPanel.open = true;
                    });

                    socket.addEventListener('error', () => {
                        setStatus('error', 'bg-red-100 text-red-700');
                        fallbackPanel.open = true;
                        writeLine(`WebSocket terminal is unavailable at ${wsUrl}. Use the monitored command form below.`, 'text-amber-300');
                    });
                };

                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    const command = input.value.trim();

                    if (! command || ! socket || socket.readyState !== WebSocket.OPEN) {
                        return;
                    }

                    socket.send(JSON.stringify({ type: 'command', command }));
                    input.value = '';
                });

                input.disabled = true;
                connect();
            })();
        </script>
    @endif
</x-layouts.student>
