@php
    $title = match ($section) {
        'templates' => 'Template Library',
        'vms' => 'Kelola VM Siswa',
        'audit-logs' => 'Audit Log',
        default => 'Dashboard PAM Proxmox',
    };

    $statusClass = fn ($vm) => $vm->trashed() ? 'deleted' : ($vm->status === 'running' ? 'running' : ($vm->status === 'stopped' ? 'stopped' : 'default'));
    $statusLabel = fn ($vm) => $vm->trashed() ? 'deleted' : $vm->status;
    $realStatusClass = fn ($status) => $status === 'running' ? 'running' : ($status === 'stopped' ? 'stopped' : 'default');
    $shortSession = fn (?string $uuid) => $uuid ? substr($uuid, 0, 8) : '-';
    $runningVmCount = $realVms->where('status', 'running')->count();
    $activeLabCount = $vms->filter(fn ($vm) => ! $vm->trashed() && ($vm->isSharedPractical() || $vm->isManagedAssignment()))->count();
    $personalSandboxVms = $vms->filter(fn ($vm) => ! $vm->isProtectedVm() && ! $vm->isSharedPractical() && ! $vm->isManagedAssignment())->values();
    $practicalLabVms = $vms->filter(fn ($vm) => ! $vm->isProtectedVm() && ($vm->isSharedPractical() || $vm->isManagedAssignment()))->values();
    $infrastructureVms = $vms->filter(fn ($vm) => $vm->isProtectedVm())->values();
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

<x-layouts.app :title="$title" :user="$currentUser ?? null">
    <div class="relative isolate mb-8 overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 via-indigo-600 to-violet-600 p-6 text-white shadow-lg shadow-indigo-500/20 sm:p-8">
        <div class="absolute inset-0 z-0 bg-indigo-950/10"></div>
        <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-indigo-100">Zero Trust PAM Control Center</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-white drop-shadow-sm">Privileged lab access, monitored from one place</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-indigo-100">Provision practical environments, supervise student access, and review terminal activity without exposing low-level SSH controls.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                <span class="inline-flex items-center rounded-full border border-white/25 bg-white/10 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white/95 shadow-sm backdrop-blur-sm">PAM Protected</span>
                <span class="inline-flex items-center rounded-full border border-white/25 bg-white/10 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white/95 shadow-sm backdrop-blur-sm">Audited</span>
                <span class="inline-flex items-center rounded-full border border-white/25 bg-white/10 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white/95 shadow-sm backdrop-blur-sm">Secure Gateway</span>
            </div>
        </div>
    </div>

    <div class="mb-8 grid grid-cols-2 gap-4 xl:grid-cols-5">
        <x-stat-card label="Active Labs" :value="$activeLabCount" accent="purple" />
        <x-stat-card label="Active Sessions" :value="$activeTerminalSessions->count()" accent="indigo" />
        <x-stat-card label="Running VMs" :value="$runningVmCount" accent="emerald" />
        <x-stat-card label="Audited Commands" :value="$recentCommandLogs->count()" accent="amber" />
        <x-stat-card label="Active Students" :value="$students->count()" accent="blue" />
    </div>

    @if ($section === 'overview')
        <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
            <a href="{{ route('dashboard.templates') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50/20">
                <div class="grid h-11 w-11 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                </div>
                <h3 class="mt-4 text-base font-semibold text-slate-950">Template Library</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">Curated Proxmox sources for student self-service provisioning.</p>
            </a>
            <a href="{{ route('dashboard.vms') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50/20">
                <div class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                </div>
                <h3 class="mt-4 text-base font-semibold text-slate-950">VM Access Control</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">Assign labs, grant access, and open monitored terminal sessions.</p>
            </a>
            <a href="{{ route('dashboard.audit-logs') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50/20">
                <div class="grid h-11 w-11 place-items-center rounded-xl bg-violet-50 text-violet-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7 4h10l2 4v10a2 2 0 01-2 2H7a2 2 0 01-2-2V8l2-4z"/></svg>
                </div>
                <h3 class="mt-4 text-base font-semibold text-slate-950">Audit Intelligence</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">Review security-relevant VM, terminal, and dashboard actions.</p>
            </a>
        </div>
    @endif

    @if (in_array($section, ['overview', 'templates'], true))
        @if ($currentUser?->role === 'admin')
            <x-card title="Create VM Template" subtitle="Proxmox template source for student self-service provisioning." class="mb-6">
                <form method="POST" action="{{ route('dashboard.vm-templates.store') }}" class="grid gap-4 p-6 lg:grid-cols-4">
                    @csrf
                    <input name="name" value="{{ old('name') }}" placeholder="Template name" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm">
                    <input name="proxmox_template_id" value="{{ old('proxmox_template_id') }}" placeholder="Proxmox VMID" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm">
                    <input name="proxmox_node" value="{{ old('proxmox_node', config('services.proxmox.node', 'pve')) }}" placeholder="Proxmox node" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm">
                    <input name="ssh_username" value="{{ old('ssh_username', 'student') }}" placeholder="SSH username" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm">
                    <input name="cpu" value="{{ old('cpu', 1) }}" placeholder="CPU" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm">
                    <input name="ram" value="{{ old('ram', 1024) }}" placeholder="RAM MB" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm">
                    <input name="disk" value="{{ old('disk', 10) }}" placeholder="Disk GB" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm">
                    <textarea name="description" placeholder="Description" class="rounded-lg border border-slate-300 px-3 py-2 text-sm lg:col-span-3">{{ old('description') }}</textarea>
                    <label class="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" name="enabled" value="1" checked class="rounded border-slate-300">
                        Enabled
                    </label>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-500 lg:col-span-4">Create template</button>
                </form>
            </x-card>
        @endif

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
                            @if ($currentUser?->role === 'admin')
                                <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Manage</th>
                            @endif
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
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->proxmox_node }} / {{ $template->proxmox_template_id }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->cpu }} core</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->ram }} MB</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $template->disk }} GB</td>
                                <td class="px-6 py-4">
                                    <x-badge :type="$template->enabled ? 'running' : 'inactive'">{{ $template->enabled ? 'enabled' : 'disabled' }}</x-badge>
                                </td>
                                @if ($currentUser?->role === 'admin')
                                    <td class="px-6 py-4">
                                        <details>
                                            <summary class="cursor-pointer text-xs font-semibold text-slate-600 hover:text-slate-900">Edit</summary>
                                            <form method="POST" action="{{ route('dashboard.vm-templates.update', $template) }}" class="mt-3 grid min-w-[28rem] gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                                                @csrf
                                                @method('PUT')
                                                <input name="name" value="{{ $template->name }}" class="min-h-9 rounded-md border border-slate-300 px-2 text-xs">
                                                <input name="proxmox_template_id" value="{{ $template->proxmox_template_id }}" class="min-h-9 rounded-md border border-slate-300 px-2 text-xs">
                                                <input name="proxmox_node" value="{{ $template->proxmox_node }}" class="min-h-9 rounded-md border border-slate-300 px-2 text-xs">
                                                <input name="ssh_username" value="{{ $template->ssh_username }}" class="min-h-9 rounded-md border border-slate-300 px-2 text-xs">
                                                <input name="cpu" value="{{ $template->cpu }}" class="min-h-9 rounded-md border border-slate-300 px-2 text-xs">
                                                <input name="ram" value="{{ $template->ram }}" class="min-h-9 rounded-md border border-slate-300 px-2 text-xs">
                                                <input name="disk" value="{{ $template->disk }}" class="min-h-9 rounded-md border border-slate-300 px-2 text-xs">
                                                <textarea name="description" rows="2" class="rounded-md border border-slate-300 px-2 py-2 text-xs sm:col-span-2">{{ $template->description }}</textarea>
                                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                                    <input type="checkbox" name="enabled" value="1" @checked($template->enabled) class="rounded border-slate-300">
                                                    Enabled
                                                </label>
                                                <button type="submit" class="inline-flex min-h-9 items-center justify-center rounded-lg bg-slate-900 px-3 text-xs font-semibold text-white hover:bg-slate-800">Save</button>
                                            </form>
                                            <form method="POST" action="{{ route('dashboard.vm-templates.destroy', $template) }}" class="mt-2">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-700">Delete</button>
                                            </form>
                                        </details>
                                    </td>
                                @endif
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
                                                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed" @disabled(! $vm['can_control'])>Open Terminal</button>
                                            </form>
                                        @else
                                            <button type="button" disabled class="inline-flex cursor-not-allowed items-center justify-center rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-400">Open Terminal</button>
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

        <!-- @if ($currentUser?->role === 'admin')
            <x-card title="Bulk Managed Practical VM" subtitle="Clone one practical VM per selected siswa from a safe source template VM." class="mb-8">
                <form method="POST" action="{{ route('dashboard.vms.bulk-managed-generation.store') }}" class="grid gap-4 p-6 lg:grid-cols-[minmax(14rem,1fr)_minmax(14rem,1fr)_auto]">
                    @csrf
                    <div>
                        <label for="source_vm_id" class="text-sm font-semibold text-slate-800">Source template VM</label>
                        <select id="source_vm_id" name="source_vm_id" class="mt-2 min-h-10 w-full rounded-lg border border-slate-300 px-3 text-sm" required>
                            <option value="">Pilih source VM</option>
                            @foreach ($sourceTemplateVms as $sourceVm)
                                <option value="{{ $sourceVm->id }}" @selected((string) old('source_vm_id') === (string) $sourceVm->id)>
                                    {{ $sourceVm->name }} - VMID {{ $sourceVm->proxmoxVmid() ?? '-' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="target_mode" class="text-sm font-semibold text-slate-800">Target siswa</label>
                        <select id="target_mode" name="target_mode" class="mt-2 min-h-10 w-full rounded-lg border border-slate-300 px-3 text-sm">
                            <option value="all" @selected(old('target_mode', 'all') === 'all')>Semua siswa</option>
                            <option value="selected" @selected(old('target_mode') === 'selected')>Siswa terpilih</option>
                        </select>
                    </div>
                    <label class="mt-7 inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" name="confirm_duplicates" value="1" class="rounded border-slate-300" @checked(old('confirm_duplicates'))>
                        Confirm duplicates
                    </label>
                    <div class="lg:col-span-2">
                        <label for="student_ids" class="text-sm font-semibold text-slate-800">Selected siswa</label>
                        <select id="student_ids" name="student_ids[]" multiple size="4" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}" @selected(in_array((string) $student->id, old('student_ids', []), true))>
                                    {{ $student->name }} ({{ $student->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center self-end rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50" @disabled($sourceTemplateVms->isEmpty() || $students->isEmpty())>
                        Generate VMs
                    </button>
                </form>
            </x-card>
        @endif -->

        <section class="mb-8 space-y-6">
            <div class="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">Secure Connection</h3>
                        <p class="mt-1 text-sm text-slate-600">SSH connection is managed automatically through PAM.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-indigo-700 ring-1 ring-indigo-200">PAM Protected</span>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">Audited</span>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-violet-700 ring-1 ring-violet-200">Secure Gateway</span>
                    </div>
                </div>
            </div>

            <div>
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">Practical Labs</h3>
                        <p class="mt-1 text-sm text-slate-500">Shared multi-user VM environments for supervised practice.</p>
                    </div>
                    <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">{{ $practicalLabVms->count() }} labs</span>
                </div>
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    @forelse ($practicalLabVms as $vm)
                        @include('dashboard.partials.admin-vm-card', ['vm' => $vm, 'students' => $students, 'statusClass' => $statusClass, 'statusLabel' => $statusLabel])
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 xl:col-span-2">No practical labs have been configured yet.</div>
                    @endforelse
                </div>
            </div>

            <div>
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">Personal Sandbox VMs</h3>
                        <p class="mt-1 text-sm text-slate-500">Self-service student-created VMs and individually assigned labs.</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">{{ $personalSandboxVms->count() }} sandboxes</span>
                </div>
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    @forelse ($personalSandboxVms as $vm)
                        @include('dashboard.partials.admin-vm-card', ['vm' => $vm, 'students' => $students, 'statusClass' => $statusClass, 'statusLabel' => $statusLabel])
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 xl:col-span-2">No personal sandbox VMs are available.</div>
                    @endforelse
                </div>
            </div>

            <div>
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">Critical/System Infrastructure</h3>
                        <p class="mt-1 text-sm text-slate-500">Protected internal VMs that are visible for monitoring but blocked from unsafe actions.</p>
                    </div>
                    <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700">{{ $infrastructureVms->count() }} protected</span>
                </div>
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    @forelse ($infrastructureVms as $vm)
                        @include('dashboard.partials.admin-vm-card', ['vm' => $vm, 'students' => $students, 'statusClass' => $statusClass, 'statusLabel' => $statusLabel])
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 xl:col-span-2">No protected infrastructure records are visible.</div>
                    @endforelse
                </div>
            </div>
        </section>
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
                        <h2 class="text-base font-semibold text-slate-950">Audit Logs</h2>
                        <p class="mt-1 text-sm text-slate-500">Security-relevant VM, terminal, and dashboard activity.</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        <span class="font-semibold text-slate-900">{{ $auditLogs->count() }}</span> latest entries
                    </div>
                </div>
            </div>
            <div class="max-h-[34rem] overflow-auto">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead class="sticky top-0 z-10 bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Severity</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">User</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Action</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Description</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">VM</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($auditLogs as $log)
                            @php($severity = $auditSeverity($log->action))
                            <tr class="bg-white transition hover:bg-slate-50">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <x-badge :type="$severity['type']">{{ $severity['label'] }}</x-badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm">
                                    <span class="font-semibold text-slate-900">{{ $log->user?->name ?? '-' }}</span>
                                    @if ($log->user?->email)
                                        <p class="mt-0.5 text-xs text-slate-500">{{ $log->user->email }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200">{{ $log->action }}</span>
                                </td>
                                <td class="max-w-xl px-5 py-3 text-sm leading-6 text-slate-700">
                                    {{ $log->description }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-slate-600">
                                    {{ $log->vm?->name ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-slate-500">
                                    {{ $log->created_at?->format('Y-m-d H:i') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-sm text-slate-500">Belum ada audit log.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
