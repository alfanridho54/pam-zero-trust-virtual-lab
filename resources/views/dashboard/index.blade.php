@php
    $title = match ($section) {
        'templates' => 'Akses VM Praktikum',
        'vms' => 'Kelola Lab Pribadi',
        'audit-logs' => 'Audit Log',
        default => 'Dashboard PAM Proxmox',
    };

    $statusClass = fn ($vm) => $vm->trashed() ? 'deleted' : ($vm->status === 'running' ? 'running' : ($vm->status === 'stopped' ? 'stopped' : 'default'));
    $statusLabel = fn ($vm) => $vm->trashed() ? 'deleted' : $vm->status;
    $realStatusClass = fn ($status) => $status === 'running' ? 'running' : ($status === 'stopped' ? 'stopped' : 'default');
    $shortSession = fn (?string $uuid) => $uuid ? substr($uuid, 0, 8) : '-';
@endphp

<x-layouts.app :title="$title" :user="$currentUser ?? null">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-8">
        <x-stat-card label="Total Template" :value="$stats['templates']" accent="indigo" />
        <x-stat-card label="Total VM" :value="$stats['vms']" accent="blue" />
        <x-stat-card label="Total Audit Log" :value="$stats['auditLogs']" accent="amber" />
        <x-stat-card label="Total User" :value="$stats['users']" accent="emerald" />
    </div>

    @if ($section === 'overview')
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <x-card>
                <div class="p-6">
                    <h3 class="text-base font-semibold text-slate-900">Akses VM Praktikum</h3>
                    <p class="mt-1 text-sm text-slate-500">Daftar template lab yang disediakan guru untuk simulasi akses praktikum siswa.</p>
                    <a href="{{ route('dashboard.templates') }}" class="mt-4 inline-flex items-center justify-center rounded-lg bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200 hover:bg-indigo-100">Buka Template</a>
                </div>
            </x-card>
            <x-card>
                <div class="p-6">
                    <h3 class="text-base font-semibold text-slate-900">Kelola Lab Pribadi</h3>
                    <p class="mt-1 text-sm text-slate-500">Kelola VM milik user, simulasi perubahan resource, dan soft delete untuk kebutuhan demo.</p>
                    <a href="{{ route('dashboard.vms') }}" class="mt-4 inline-flex items-center justify-center rounded-lg bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200 hover:bg-indigo-100">Buka VM</a>
                </div>
            </x-card>
        </div>
    @endif

    @if (in_array($section, ['overview', 'templates'], true))
        <x-card title="Template Lab" subtitle="Template praktikum yang tersedia untuk siswa." class="mb-8">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Nama</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Proxmox Template</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">CPU</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">RAM</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Disk</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($templates as $template)
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4 text-sm">
                                    <span class="font-medium text-slate-900">{{ $template->name }}</span>
                                    @if ($template->description)
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $template->description }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->proxmox_template_id }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->cpu_cores }} core</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->memory_mb }} MB</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->disk_gb }} GB</td>
                                <td class="px-6 py-4">
                                    <x-badge :type="$template->is_active ? 'running' : 'inactive'">{{ $template->is_active ? 'active' : 'inactive' }}</x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($templates->isEmpty())
                <x-empty-state message="Belum ada template lab tersedia." />
            @endif
        </x-card>
    @endif

    @if (in_array($section, ['overview', 'vms'], true))
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Virtual Machine Proxmox</h2>
                <p class="mt-0.5 text-sm text-slate-500">Daftar VM real dari Proxmox beserta status resource dan action power.</p>
            </div>
            <form method="POST" action="{{ route('dashboard.simulate.docker-lab') }}">
                @csrf
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Create Docker Lab
                </button>
            </form>
        </div>

        @if (! ($realVmResponse['success'] ?? false))
            <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                Proxmox API gagal dibaca: {{ $realVmResponse['message'] ?? 'Unknown error' }}
            </div>
        @endif

        <x-card class="mb-8">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">VMID</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Name</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Node</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Owner</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Ownership</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">CPU</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Memory</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Disk</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Uptime</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($realVms as $vm)
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $vm['vmid'] }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm['name'] }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm['node'] }}</td>
                                <td class="px-6 py-4 text-sm">
                                    {{ $vm['owner_name'] ?? '-' }}
                                    @if ($vm['owner_user_id'] && ! $vm['is_system_vm'])
                                        <p class="text-xs text-slate-400">user_id={{ $vm['owner_user_id'] }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if ($vm['ownership_status'] === 'system')
                                        <x-badge type="system">Critical/System</x-badge>
                                    @elseif ($vm['ownership_status'] === 'owned')
                                        <x-badge type="owned">owned</x-badge>
                                    @else
                                        <x-badge type="unassigned">Belum diassign</x-badge>
                                    @endif
                                    @if ($vm['is_critical'] && ! $vm['is_system_vm'])
                                        <p class="text-xs text-red-500 mt-1">critical</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <x-badge :type="$realStatusClass($vm['status'])">{{ $vm['status'] }}</x-badge>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm['cpu'] }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">
                                    <span class="block">{{ $vm['memory_usage'] }}</span>
                                    <span class="text-xs text-slate-400">{{ $vm['max_memory'] }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm['disk'] }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm['uptime'] }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('dashboard.proxmox.vms.action', [$vm['node'], $vm['vmid'], 'start']) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 disabled:opacity-50 disabled:cursor-not-allowed" @disabled(! $vm['can_control'] || $vm['status'] === 'running')>Start</button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.proxmox.vms.action', [$vm['node'], $vm['vmid'], 'stop']) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-100 disabled:opacity-50 disabled:cursor-not-allowed" @disabled(! $vm['can_control'] || $vm['status'] !== 'running')>Stop</button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.proxmox.vms.action', [$vm['node'], $vm['vmid'], 'shutdown']) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 hover:bg-amber-100 disabled:opacity-50 disabled:cursor-not-allowed" @disabled(! $vm['can_control'] || $vm['status'] !== 'running')>Shutdown</button>
                                        </form>
                                        @if ($vm['local_vm_id'])
                                            <form method="POST" action="{{ route('terminal-sessions.store', $vm['local_vm_id']) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed" @disabled(! $vm['can_control'])>Terminal</button>
                                            </form>
                                        @else
                                            <button type="button" disabled class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-400 cursor-not-allowed">Terminal</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-6 py-8 text-center text-sm text-slate-500">Belum ada VM real yang bisa ditampilkan dari Proxmox.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="VM Lokal Demo" subtitle="Data lokal untuk simulasi ownership, quota, RBAC, dan audit lama." class="mb-8">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">VM</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Owner</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Template</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">CPU</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">RAM</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Disk</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($vms as $vm)
                            @php
                                $isCriticalVm = filter_var($vm->metadata['critical'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                $isSystemVm = filter_var($vm->metadata['system_vm'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                $isProtectedVm = $isCriticalVm || $isSystemVm;
                                $terminalBlocked = $vm->trashed() || $isProtectedVm;
                            @endphp
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4 text-sm">
                                    <span class="font-medium text-slate-900">{{ $vm->name }}</span>
                                    <p class="text-xs text-slate-400">{{ $vm->proxmox_id }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    {{ $isSystemVm ? 'System VM' : ($vm->user?->name ?? '-') }}
                                    @if (! $isSystemVm)
                                        <p class="text-xs text-slate-400">owner_id={{ $vm->user_id }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm->labTemplate?->name ?? 'Lab Pribadi' }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm->cpu_cores }} core</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm->memory_mb }} MB</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $vm->disk_gb }} GB</td>
                                <td class="px-6 py-4">
                                    <x-badge :type="$statusClass($vm)">{{ $statusLabel($vm) }}</x-badge>
                                    @if ($isSystemVm)
                                        <div class="mt-1"><x-badge type="system">System VM</x-badge></div>
                                    @elseif ($isCriticalVm)
                                        <div class="mt-1"><x-badge type="critical">Critical</x-badge></div>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('dashboard.simulate.vm.resources', $vm) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200 hover:bg-indigo-100 disabled:opacity-50 disabled:cursor-not-allowed" @disabled($vm->trashed() || $isProtectedVm)>Edit Resource</button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.simulate.vm.delete', $vm) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-100 disabled:opacity-50 disabled:cursor-not-allowed" @disabled($vm->trashed() || $isProtectedVm)>Soft Delete</button>
                                        </form>
                                        <form method="POST" action="{{ route('terminal-sessions.store', $vm) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed" @disabled($terminalBlocked)>Terminal</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-sm text-slate-500">Belum ada VM lokal. Gunakan tombol Create Docker Lab.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    @if (in_array($section, ['overview', 'audit-logs'], true))
        @if ($canViewPamMonitoring)
            <x-card title="SOC PAM Monitoring" subtitle="Terminal sessions and command activity for practical VM access." class="mb-6">
                <div class="p-0"></div>
            </x-card>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <x-card title="Recent Command Logs" subtitle="Latest command executions across terminal sessions.">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead>
                                <tr class="bg-slate-50/50">
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">User</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">VM / Session</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Command</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Executed</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Output</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($recentCommandLogs as $commandLog)
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-4 py-3.5 text-sm">
                                            <span class="font-medium text-slate-900">{{ $commandLog['user_name'] }}</span>
                                            <p class="text-xs text-slate-400">{{ $commandLog['user_email'] }}</p>
                                        </td>
                                        <td class="px-4 py-3.5 text-sm">
                                            {{ $commandLog['vm_name'] }}
                                            <p class="text-xs text-slate-400">session {{ $shortSession($commandLog['session_uuid']) }}</p>
                                        </td>
                                        <td class="px-4 py-3.5"><code class="text-xs font-mono bg-slate-100 px-2 py-1 rounded text-slate-700">{{ $commandLog['command'] }}</code></td>
                                        <td class="px-4 py-3.5"><x-badge :type="$commandLog['status_class']">{{ $commandLog['status'] }}</x-badge></td>
                                        <td class="px-4 py-3.5 text-xs text-slate-500">{{ $commandLog['executed_at']?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                        <td class="px-4 py-3.5 text-xs font-mono text-slate-600 max-w-[200px] truncate">
                                            @if ($commandLog['output_excerpt'])
                                                {{ $commandLog['output_excerpt'] }}
                                            @else
                                                <span class="text-slate-400">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">Belum ada command log.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>

                <x-card title="Active Terminal Sessions" subtitle="Currently active PAM terminal sessions.">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead>
                                <tr class="bg-slate-50/50">
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">User</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">VM / Session</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">SSH</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Started</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($activeTerminalSessions as $session)
                                    @php
                                        $sessionTarget = ($session['ssh_username'] ?: 'student') . '@' . ($session['ssh_host'] ?: '-') . ':' . ($session['ssh_port'] ?: 22);
                                    @endphp
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-4 py-3.5 text-sm">
                                            <span class="font-medium text-slate-900">{{ $session['user_name'] }}</span>
                                            <p class="text-xs text-slate-400">{{ $session['user_email'] }}</p>
                                        </td>
                                        <td class="px-4 py-3.5 text-sm">
                                            {{ $session['vm_name'] }}
                                            <p class="text-xs text-slate-400">session {{ $shortSession($session['session_uuid']) }}</p>
                                        </td>
                                        <td class="px-4 py-3.5 text-xs font-mono text-slate-600">{{ $sessionTarget }}</td>
                                        <td class="px-4 py-3.5"><x-badge :type="$session['status_class']">{{ $session['status'] }}</x-badge></td>
                                        <td class="px-4 py-3.5 text-xs text-slate-500">{{ $session['started_at']?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Tidak ada terminal session aktif.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>

                <x-card title="Blocked Command Monitoring" subtitle="Restricted commands blocked by terminal policy." :danger="true">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-red-100">
                            <thead>
                                <tr class="bg-red-50/50">
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-red-700">User</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-red-700">VM / Session</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-red-700">Command</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-red-700">Reason</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-red-700">Timestamp</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-red-100">
                                @forelse ($blockedCommandLogs as $commandLog)
                                    <tr class="hover:bg-red-50/30 transition-colors">
                                        <td class="px-4 py-3.5 text-sm">
                                            <span class="font-medium text-slate-900">{{ $commandLog['user_name'] }}</span>
                                            <p class="text-xs text-slate-400">{{ $commandLog['user_email'] }}</p>
                                        </td>
                                        <td class="px-4 py-3.5 text-sm">
                                            {{ $commandLog['vm_name'] }}
                                            <p class="text-xs text-slate-400">session {{ $shortSession($commandLog['session_uuid']) }}</p>
                                        </td>
                                        <td class="px-4 py-3.5"><code class="text-xs font-mono bg-red-50 text-red-700 px-2 py-1 rounded">{{ $commandLog['command'] }}</code></td>
                                        <td class="px-4 py-3.5 text-xs text-red-600">{{ $commandLog['blocked_reason'] ?? 'Command diblokir oleh policy terminal.' }}</td>
                                        <td class="px-4 py-3.5 text-xs text-slate-500">{{ $commandLog['executed_at']?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Tidak ada command yang diblokir.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>

                <x-card title="Terminal Activity Timeline" subtitle="Recent session and command activity.">
                    <div class="divide-y divide-slate-100">
                        @forelse ($terminalActivityTimeline as $item)
                            <div class="px-6 py-4 grid grid-cols-[auto,1fr] gap-4 items-start">
                                <div class="text-xs text-slate-400 whitespace-nowrap pt-0.5">{{ $item['occurred_at']?->format('Y-m-d H:i:s') }}</div>
                                <div>
                                    <x-badge :type="str_replace('badge-', '', $item['style'])">{{ $item['label'] }}</x-badge>
                                    <p class="mt-1.5 text-sm text-slate-900">
                                        <span class="font-medium">{{ $item['user_name'] }}</span>
                                        <span class="text-slate-400">· {{ $item['vm_name'] }}</span>
                                    </p>
                                    <p class="mt-0.5 text-xs font-mono text-slate-600">{{ $item['description'] }}</p>
                                    <p class="mt-0.5 text-xs text-slate-400">session {{ $shortSession($item['session_uuid']) }}</p>
                                </div>
                            </div>
                        @empty
                            <x-empty-state message="Belum ada aktivitas terminal." class="py-8" />
                        @endforelse
                    </div>
                </x-card>
            </div>
        @endif

        <x-card class="{{ $section === 'audit-logs' ? 'mb-0' : '' }}">
            <div class="border-b border-slate-100 px-6 py-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-950">Audit Log</h2>
                        <p class="mt-1 text-sm text-slate-500">Aktivitas penting dari API dan dashboard mock.</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        <span class="font-semibold text-slate-900">{{ $auditLogs->count() }}</span> latest entries
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">User</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Action</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">VM</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Created At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($auditLogs as $log)
                            <tr class="bg-white transition hover:bg-slate-50">
                                <td class="whitespace-nowrap px-6 py-4 text-sm">
                                    <span class="font-semibold text-slate-900">{{ $log->user?->name ?? '-' }}</span>
                                    @if ($log->user?->email)
                                        <p class="mt-0.5 text-xs text-slate-500">{{ $log->user->email }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <x-badge type="allowed">{{ $log->action }}</x-badge>
                                </td>
                                <td class="max-w-xl px-6 py-4 text-sm leading-6 text-slate-700">
                                    {{ $log->description }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">
                                    {{ $log->vm?->name ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-500">
                                    {{ $log->created_at?->format('Y-m-d H:i') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-sm text-slate-500">Belum ada audit log.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
