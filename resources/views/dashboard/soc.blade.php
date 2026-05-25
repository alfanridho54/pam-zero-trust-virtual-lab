@php
    $shortSession = fn (?string $uuid) => $uuid ? substr($uuid, 0, 8) : '-';
@endphp

<x-layouts.app title="SOC Monitoring" :user="$currentUser">
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">Privileged Access Monitoring</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">SOC PAM Monitoring</h2>
                <p class="mt-2 max-w-3xl text-sm text-slate-500">
                    Monitor active terminal sessions, command executions, blocked commands, and PAM lifecycle events.
                </p>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Active</p>
                    <p class="mt-1 text-2xl font-bold text-slate-950">{{ $activeTerminalSessions->count() }}</p>
                </div>
                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wide text-red-600">Blocked</p>
                    <p class="mt-1 text-2xl font-bold text-red-700">{{ $blockedCommandLogs->count() }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Commands</p>
                    <p class="mt-1 text-2xl font-bold text-slate-950">{{ $recentCommandLogs->count() }}</p>
                </div>
            </div>
        </div>

        <x-card title="Active Terminal Sessions" subtitle="Only active sessions can be revoked from this console.">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">User</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">VM / Session</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">SSH Target</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Lifecycle</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($activeTerminalSessions as $session)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-5 py-4">
                                    <div class="text-sm font-semibold text-slate-900">{{ $session['user_name'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $session['user_email'] }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="text-sm font-medium text-slate-900">{{ $session['vm_name'] }}</div>
                                    <div class="font-mono text-xs text-slate-500">session {{ $shortSession($session['session_uuid']) }}</div>
                                </td>
                                <td class="px-5 py-4 font-mono text-xs text-slate-600">
                                    {{ ($session['ssh_username'] ?? 'student01').'@'.($session['ssh_host'] ?? '-').':'.($session['ssh_port'] ?? 22) }}
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500">
                                    <div>Started <span class="font-mono">{{ $session['started_at']?->format('Y-m-d H:i:s') ?? '-' }}</span></div>
                                    <div>Last <span class="font-mono">{{ $session['last_activity_at']?->format('Y-m-d H:i:s') ?? '-' }}</span></div>
                                    <div>Expires <span class="font-mono">{{ $session['expires_at']?->format('Y-m-d H:i:s') ?? '-' }}</span></div>
                                </td>
                                <td class="px-5 py-4">
                                    <x-badge :type="$session['status_class']">{{ $session['status'] }}</x-badge>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($session['can_revoke'])
                                        <form method="POST" action="{{ route('terminal-sessions.revoke', $session['id']) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-9 items-center justify-center rounded-lg bg-red-600 px-3 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                                                Revoke
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">No action</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state message="No active terminal sessions." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-card title="Recent Command Logs" subtitle="Latest command executions across PAM sessions.">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">User</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">VM / Session</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Command</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Timestamp</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($recentCommandLogs as $commandLog)
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-4 py-3.5 text-sm">
                                        <div class="font-semibold text-slate-900">{{ $commandLog['user_name'] }}</div>
                                        <div class="text-xs text-slate-500">{{ $commandLog['user_email'] }}</div>
                                    </td>
                                    <td class="px-4 py-3.5 text-sm">
                                        {{ $commandLog['vm_name'] }}
                                        <div class="font-mono text-xs text-slate-500">session {{ $shortSession($commandLog['session_uuid']) }}</div>
                                    </td>
                                    <td class="px-4 py-3.5"><code class="rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700">{{ $commandLog['command'] }}</code></td>
                                    <td class="px-4 py-3.5"><x-badge :type="$commandLog['status_class']">{{ $commandLog['status'] }}</x-badge></td>
                                    <td class="px-4 py-3.5 font-mono text-xs text-slate-500">{{ $commandLog['executed_at']?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-empty-state message="No command logs yet." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>

            <x-card title="Blocked Command Monitoring" subtitle="Restricted commands blocked by terminal policy." :danger="true">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-red-100">
                        <thead class="bg-red-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-red-700">User</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-red-700">VM / Session</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-red-700">Command</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-red-700">Reason</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-red-700">Timestamp</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-red-100 bg-white">
                            @forelse ($blockedCommandLogs as $commandLog)
                                <tr class="hover:bg-red-50/50">
                                    <td class="px-4 py-3.5 text-sm">
                                        <div class="font-semibold text-slate-900">{{ $commandLog['user_name'] }}</div>
                                        <div class="text-xs text-slate-500">{{ $commandLog['user_email'] }}</div>
                                    </td>
                                    <td class="px-4 py-3.5 text-sm">
                                        {{ $commandLog['vm_name'] }}
                                        <div class="font-mono text-xs text-slate-500">session {{ $shortSession($commandLog['session_uuid']) }}</div>
                                    </td>
                                    <td class="px-4 py-3.5"><code class="rounded bg-red-50 px-2 py-1 font-mono text-xs text-red-700">{{ $commandLog['command'] }}</code></td>
                                    <td class="px-4 py-3.5 text-xs font-medium text-red-700">{{ $commandLog['blocked_reason'] ?? 'Command diblokir oleh policy terminal.' }}</td>
                                    <td class="px-4 py-3.5 font-mono text-xs text-slate-500">{{ $commandLog['executed_at']?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-empty-state message="No blocked commands." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <x-card title="PAM Activity Timeline" subtitle="Recent session lifecycle and command events.">
            <div class="divide-y divide-slate-100">
                @forelse ($terminalActivityTimeline as $item)
                    <div class="grid gap-3 px-6 py-4 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <div class="font-mono text-xs text-slate-500">{{ $item['occurred_at']?->format('Y-m-d H:i:s') }}</div>
                        <div>
                            <x-badge :type="$item['style']">{{ $item['label'] }}</x-badge>
                            <div class="mt-2 text-sm text-slate-900">
                                <span class="font-semibold">{{ $item['user_name'] }}</span>
                                <span class="text-slate-400">- {{ $item['vm_name'] }}</span>
                            </div>
                            <div class="mt-1 font-mono text-xs text-slate-600">{{ $item['description'] }}</div>
                            <div class="mt-1 font-mono text-xs text-slate-400">session {{ $shortSession($item['session_uuid']) }}</div>
                        </div>
                    </div>
                @empty
                    <x-empty-state message="No terminal activity yet." />
                @endforelse
            </div>
        </x-card>
    </div>
</x-layouts.app>
