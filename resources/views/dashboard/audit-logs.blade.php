<x-layouts.app title="Audit Logs" :user="$currentUser">
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">PAM Activity</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Audit Logs</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-500">
                    Security-relevant actions from VM ownership, terminal sessions, command logging, and dashboard operations.
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Logs</p>
                <p class="mt-1 text-2xl font-bold text-slate-950">{{ $stats['auditLogs'] }}</p>
            </div>
        </div>

        <x-card title="Recent Audit Events" subtitle="Latest 50 events, newest first.">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">User</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">VM</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($auditLogs as $log)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-semibold text-slate-900">{{ $log->user?->name ?? '-' }}</div>
                                    <div class="text-xs text-slate-500">{{ $log->user?->email ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <x-badge type="allowed">{{ $log->action }}</x-badge>
                                </td>
                                <td class="max-w-xl px-6 py-4 text-sm text-slate-600">{{ $log->description }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $log->vm?->name ?? '-' }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
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
