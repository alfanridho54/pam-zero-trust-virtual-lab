@php
    $auditSeverity = function (string $action): array {
        if (str_contains($action, 'blocked') || str_contains($action, 'denied') || str_contains($action, 'critical')) {
            return ['label' => 'BLOCKED', 'type' => 'blocked'];
        }

        if (str_contains($action, 'terminal') || str_contains($action, 'ssh') || str_contains($action, 'revoked')) {
            return ['label' => 'SECURITY', 'type' => 'revoked'];
        }

        if (str_contains($action, 'failed') || str_contains($action, 'mismatch')) {
            return ['label' => 'WARNING', 'type' => 'stopped'];
        }

        return ['label' => 'INFO', 'type' => 'allowed'];
    };
@endphp

<x-layouts.app title="Audit Logs" :user="$currentUser">
    <div class="space-y-6">
        <div class="relative isolate overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 via-indigo-600 to-violet-600 p-6 text-white shadow-lg shadow-indigo-500/20 sm:p-8">
            <div class="absolute inset-0 z-0 bg-indigo-950/10"></div>
            <div class="relative z-10">
                <p class="text-xs font-bold uppercase tracking-wider text-indigo-100">PAM Activity</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-white drop-shadow-sm">Audit Logs</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-indigo-100">
                    Security-relevant actions from VM ownership, terminal sessions, command logging, and dashboard operations.
                </p>
            </div>
            <div class="relative z-10 mt-6 grid grid-cols-2 gap-3 sm:max-w-xl sm:grid-cols-4">
                <div class="rounded-xl bg-white/15 p-3 ring-1 ring-white/20">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-100">Total Logs</p>
                    <p class="mt-1 text-2xl font-bold">{{ $stats['auditLogs'] }}</p>
                </div>
                <div class="rounded-xl bg-white/15 p-3 ring-1 ring-white/20">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-100">Shown</p>
                    <p class="mt-1 text-2xl font-bold">{{ $auditLogs->count() }}</p>
                </div>
                <div class="rounded-xl bg-white/15 p-3 ring-1 ring-white/20">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-100">Security</p>
                    <p class="mt-1 text-2xl font-bold">{{ $auditLogs->filter(fn ($log) => $auditSeverity($log->action)['label'] === 'SECURITY')->count() }}</p>
                </div>
                <div class="rounded-xl bg-white/15 p-3 ring-1 ring-white/20">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-100">Blocked</p>
                    <p class="mt-1 text-2xl font-bold">{{ $auditLogs->filter(fn ($log) => $auditSeverity($log->action)['label'] === 'BLOCKED')->count() }}</p>
                </div>
            </div>
        </div>

        <x-card title="Recent Audit Events" subtitle="Latest 50 events, newest first.">
            <div class="max-h-[42rem] overflow-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="sticky top-0 z-10 bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Severity</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">User</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Description</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">VM</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($auditLogs as $log)
                            @php($severity = $auditSeverity($log->action))
                            <tr class="hover:bg-slate-50/70">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <x-badge :type="$severity['type']">{{ $severity['label'] }}</x-badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <div class="text-sm font-semibold text-slate-900">{{ $log->user?->name ?? '-' }}</div>
                                    <div class="text-xs text-slate-500">{{ $log->user?->email ?? '' }}</div>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200">{{ $log->action }}</span>
                                </td>
                                <td class="max-w-xl px-5 py-3 text-sm leading-6 text-slate-600">{{ $log->description }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm font-medium text-slate-900">{{ $log->vm?->name ?? '-' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-slate-500">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state message="Belum ada audit log." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-layouts.app>
