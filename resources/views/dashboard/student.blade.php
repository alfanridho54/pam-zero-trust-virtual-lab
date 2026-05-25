@php
    $studentVm = $vms->first();
    $studentRealVm = $realVms->firstWhere('ownership_status', 'owned') ?? $realVms->first();
    $vmName = $studentRealVm['name'] ?? $studentVm?->name ?? 'Practical Linux VM';
    $vmStatus = $studentRealVm['status'] ?? $studentVm?->status ?? 'pending';
    $isRunning = $vmStatus === 'running';
    $cpu = $studentRealVm['cpu'] ?? ($studentVm ? $studentVm->cpu_cores . ' core' : '-');
    $memory = $studentRealVm['memory_usage'] ?? ($studentVm ? $studentVm->memory_mb . ' MB' : '-');
    $disk = $studentRealVm['disk'] ?? ($studentVm ? $studentVm->disk_gb . ' GB' : '-');
    $activeSession = $currentUser
        ? \App\Models\TerminalSession::with('vm')->forUser($currentUser)->active()->recent()->first()
        : null;
    $activeSessionTarget = $activeSession
        ? ($activeSession->ssh_username ?: 'student') . '@' . ($activeSession->ssh_host ?: 'virtual-lab')
        : 'student@virtual-lab';
@endphp

<x-layouts.student
    title="Welcome back, {{ $currentUser?->name ?? 'Student' }}"
    subtitle="Your practical lab environment is ready."
    :user="$currentUser"
>
    <div class="space-y-8">
        <section class="rounded-2xl bg-gradient-to-br from-indigo-600 via-indigo-600 to-violet-600 p-6 text-white shadow-lg shadow-indigo-500/20 sm:p-8">
            <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl space-y-4">
                    <div class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[11px] font-bold uppercase tracking-wider">
                        <span class="h-1.5 w-1.5 rounded-full {{ $isRunning ? 'bg-emerald-300' : 'bg-amber-300' }}"></span>
                        Your assigned VM is {{ $vmStatus }}
                    </div>
                    <div>
                        <h2 class="text-3xl font-bold leading-tight">{{ $vmName }}</h2>
                        <p class="mt-3 max-w-xl text-sm leading-6 text-indigo-100">
                            Open a monitored terminal session, complete your Linux practice task, and keep activity inside the PAM workspace.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3 pt-2">
                        @if ($studentVm)
                            @php
                                $terminalBlocked = $studentVm->trashed()
                                    || filter_var($studentVm->metadata['critical'] ?? false, FILTER_VALIDATE_BOOLEAN)
                                    || filter_var($studentVm->metadata['system_vm'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            @endphp
                            <form method="POST" action="{{ route('terminal-sessions.store', $studentVm) }}">
                                @csrf
                                <button class="inline-flex min-h-10 items-center justify-center rounded-lg bg-white px-4 text-sm font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($terminalBlocked)>
                                    Open Terminal
                                </button>
                            </form>
                        @else
                            <button class="inline-flex min-h-10 cursor-not-allowed items-center justify-center rounded-lg bg-white/70 px-4 text-sm font-bold text-indigo-700 opacity-70" type="button" disabled>
                                Open Terminal
                            </button>
                        @endif
                        <a href="{{ route('dashboard.templates') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-white/30 px-4 text-sm font-bold text-white transition hover:bg-white/10">
                            View Lab Guide
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4 lg:min-w-96">
                    <x-student.mini-metric label="CPU" :value="$cpu" />
                    <x-student.mini-metric label="RAM" :value="$memory" />
                    <x-student.mini-metric label="Disk" :value="$disk" />
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-student.quick-stat label="Assigned VM" value="{{ $studentVm ? '1 assigned' : 'No VM yet' }}" tint="indigo">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                </x-slot:icon>
            </x-student.quick-stat>
            <x-student.quick-stat label="Terminal session" value="{{ $activeSession ? $activeSession->status->value : 'Not connected' }}" tint="amber">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 5h14v14H5z"/></svg>
                </x-slot:icon>
            </x-student.quick-stat>
            <x-student.quick-stat label="Audit trail" value="{{ $auditLogs->count() }} recent logs" tint="emerald">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7 4h10l2 4v10a2 2 0 01-2 2H7a2 2 0 01-2-2V8l2-4z"/></svg>
                </x-slot:icon>
            </x-student.quick-stat>
        </section>

        <section class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                <div class="mb-5 flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold">Active terminal session</h3>
                        <p class="mt-0.5 text-xs text-slate-500">Connected through Cloudflare Zero Trust and PAM monitoring.</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $activeSession ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $activeSession ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                        {{ $activeSession ? 'Live' : 'Idle' }}
                    </span>
                </div>

                <div class="overflow-hidden rounded-xl bg-slate-950 font-mono text-[13px] text-slate-100">
                    <div class="flex items-center gap-2 border-b border-white/10 bg-white/[0.03] px-4 py-2.5">
                        <span class="h-2.5 w-2.5 rounded-full bg-red-400/80"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-400/80"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/80"></span>
                        <span class="ml-3 text-[11px] text-slate-400">{{ $activeSessionTarget }} - ssh</span>
                    </div>
                    <div class="space-y-1.5 p-4 leading-relaxed">
                        @if ($activeSession)
                            <p><span class="text-emerald-400">{{ $activeSessionTarget }}</span>:<span class="text-indigo-400">~</span>$ session-status</p>
                            <p class="text-slate-400">session {{ $activeSession->session_uuid }} is {{ $activeSession->status->value }}</p>
                            <p class="text-slate-400">expires_at {{ $activeSession->expires_at?->format('Y-m-d H:i') ?? '-' }}</p>
                        @else
                            <p><span class="text-emerald-400">student@virtual-lab</span>:<span class="text-indigo-400">~</span>$ open-terminal</p>
                            <p class="text-slate-400">No active terminal session yet. Start from your assigned VM.</p>
                        @endif
                        <p><span class="text-emerald-400">student@virtual-lab</span>:<span class="text-indigo-400">~</span>$ <span class="inline-block h-4 w-2 animate-pulse bg-slate-100 align-middle"></span></p>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <div class="text-xs text-slate-500">
                        @if ($activeSession)
                            Started <span class="font-mono">{{ $activeSession->started_at?->format('H:i:s') }}</span> · Last activity <span class="font-mono">{{ $activeSession->last_activity_at?->diffForHumans() }}</span>
                        @else
                            Terminal transport will attach after session creation.
                        @endif
                    </div>
                    @if ($activeSession)
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('terminal-sessions.destroy', $activeSession) }}">
                                @csrf
                                @method('DELETE')
                                <button class="inline-flex min-h-9 items-center rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50" type="submit">End session</button>
                            </form>
                            <a href="{{ route('terminal-sessions.show', $activeSession) }}" class="inline-flex min-h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700">Reattach</a>
                        </div>
                    @endif
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-base font-semibold">Today's lab steps</h3>
                    <ol class="mt-4 space-y-3 text-sm">
                        @foreach (['Connect to your VM', 'Inspect file permissions', 'Apply chmod and chown', 'Submit lab report'] as $index => $step)
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 grid h-5 w-5 place-items-center rounded-full text-[10px] font-bold {{ $index < 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $index < 1 ? '✓' : $index + 1 }}
                                </span>
                                <span class="{{ $index < 1 ? 'text-slate-500 line-through' : 'text-slate-800' }}">{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                    <a href="{{ route('dashboard.templates') }}" class="mt-5 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">Open full lab guide</a>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-base font-semibold">Command policy</h3>
                    <p class="mt-1 text-xs text-slate-500">Some commands are restricted for safety.</p>
                    <ul class="mt-4 space-y-2 text-xs">
                        @foreach ([['ok' => true, 'cmd' => 'ls, cat, grep, chmod'], ['ok' => true, 'cmd' => 'sudo systemctl restart'], ['ok' => false, 'cmd' => 'rm -rf /'], ['ok' => false, 'cmd' => 'shutdown, reboot, poweroff']] as $policy)
                            <li class="flex items-center gap-2">
                                <span class="grid h-4 w-4 place-items-center rounded-full text-[9px] font-bold {{ $policy['ok'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                    {{ $policy['ok'] ? '✓' : 'x' }}
                                </span>
                                <code class="font-mono text-[11px] text-slate-700">{{ $policy['cmd'] }}</code>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>
    </div>
</x-layouts.student>
