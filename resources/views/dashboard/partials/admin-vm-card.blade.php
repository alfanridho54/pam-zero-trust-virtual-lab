@php
    $isCriticalVm = $vm->isCritical();
    $isSystemVm = $vm->isSystemVm();
    $isProtectedVm = $vm->isProtectedVm();
    $canAssignVm = ! $vm->trashed() && ! $isSystemVm && ! $isCriticalVm;
    $terminalBlocked = $vm->trashed() || $isProtectedVm;
    $ownerLabel = $isSystemVm ? 'System VM' : ($vm->user?->name ?? 'Unassigned');
    $templateLabel = $vm->vmTemplate?->name ?? $vm->labTemplate?->name ?? 'Personal Sandbox';
@endphp

<article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-200">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h4 class="truncate text-base font-bold text-slate-950">{{ $vm->name }}</h4>
                <x-badge :type="$statusClass($vm)">{{ $statusLabel($vm) }}</x-badge>
                @if ($vm->isSharedPractical())
                    <x-badge type="owned">Shared Lab</x-badge>
                @endif
                @if ($isSystemVm)
                    <x-badge type="system">System</x-badge>
                @elseif ($isCriticalVm)
                    <x-badge type="critical">Critical</x-badge>
                @endif
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                <span>VMID {{ $vm->proxmoxVmid() ?? '-' }}</span>
                <span>{{ $vm->node }}</span>
                <span>{{ $templateLabel }}</span>
                <span>{{ $ownerLabel }}</span>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2">
            <form method="POST" action="{{ route('terminal-sessions.store', $vm) }}">
                @csrf
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50" @disabled($terminalBlocked)>
                    Open Terminal
                </button>
            </form>
            <details class="relative">
                <summary class="inline-flex min-h-10 cursor-pointer list-none items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50">
                    Actions
                </summary>
                <div class="absolute right-0 z-20 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-xl">
                    <div class="space-y-2">
                        <details>
                            <summary class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Assign Students</summary>
                            <div class="mt-2 rounded-lg bg-slate-50 p-3">
                                <form method="POST" action="{{ route('dashboard.vms.assignment.store', $vm) }}" class="space-y-2">
                                    @csrf
                                    <select name="student_id" class="min-h-9 w-full rounded-md border border-slate-300 px-2 text-xs" @disabled(! $canAssignVm || $students->isEmpty())>
                                        <option value="">Choose student</option>
                                        @foreach ($students as $student)
                                            <option value="{{ $student->id }}" @selected($vm->user_id === $student->id)>{{ $student->name }} ({{ $student->email }})</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="inline-flex min-h-9 w-full items-center justify-center rounded-lg bg-slate-900 px-3 text-xs font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $canAssignVm || $students->isEmpty())>Assign</button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.vms.assignment.destroy', $vm) }}" class="mt-2">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-700 disabled:cursor-not-allowed disabled:text-slate-400" @disabled(! $canAssignVm || $vm->user_id === null)>Unassign</button>
                                </form>
                            </div>
                        </details>

                        <details>
                            <summary class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Lab Access</summary>
                            <div class="mt-2 space-y-3 rounded-lg bg-slate-50 p-3">
                                @if (! $vm->isSharedPractical())
                                    <form method="POST" action="{{ route('dashboard.vms.shared-practical.store', $vm) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex min-h-9 w-full items-center justify-center rounded-lg bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $canAssignVm)>Enable Shared Lab</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('dashboard.vms.shared-practical.destroy', $vm) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex min-h-9 w-full items-center justify-center rounded-lg bg-red-50 px-3 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $canAssignVm)>Disable Shared Lab</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('dashboard.vms.practical-accesses.store', $vm) }}" class="space-y-2">
                                    @csrf
                                    <select name="target_mode" class="min-h-9 w-full rounded-md border border-slate-300 px-2 text-xs" @disabled(! $canAssignVm)>
                                        <option value="all">All students</option>
                                        <option value="selected">Selected students</option>
                                    </select>
                                    <select name="student_ids[]" multiple size="3" class="w-full rounded-md border border-slate-300 px-2 py-2 text-xs" @disabled(! $canAssignVm || $students->isEmpty())>
                                        @foreach ($students as $student)
                                            <option value="{{ $student->id }}" @selected($vm->practicalAccesses->contains('user_id', $student->id))>{{ $student->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="inline-flex min-h-9 w-full items-center justify-center rounded-lg bg-slate-900 px-3 text-xs font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $canAssignVm || $students->isEmpty())>Grant Access</button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.vms.practical-accesses.destroy', $vm) }}" class="space-y-2">
                                    @csrf
                                    @method('DELETE')
                                    <select name="target_mode" class="min-h-9 w-full rounded-md border border-slate-300 px-2 text-xs" @disabled(! $canAssignVm)>
                                        <option value="selected">Selected students</option>
                                        <option value="all">All students</option>
                                    </select>
                                    <select name="student_ids[]" multiple size="3" class="w-full rounded-md border border-slate-300 px-2 py-2 text-xs" @disabled(! $canAssignVm || $vm->practicalAccesses->isEmpty())>
                                        @foreach ($vm->practicalAccesses as $access)
                                            <option value="{{ $access->user_id }}" selected>{{ $access->user?->name ?? 'user_id='.$access->user_id }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="inline-flex min-h-9 w-full items-center justify-center rounded-lg bg-slate-100 px-3 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $canAssignVm || $vm->practicalAccesses->isEmpty())>Revoke Access</button>
                                </form>
                            </div>
                        </details>

                        <details>
                            <summary class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">View Details</summary>
                            <div class="mt-2 space-y-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                                <div class="flex justify-between gap-3"><span>CPU</span><span class="font-semibold text-slate-900">{{ $vm->cpu_cores }} core</span></div>
                                <div class="flex justify-between gap-3"><span>RAM</span><span class="font-semibold text-slate-900">{{ $vm->memory_mb }} MB</span></div>
                                <div class="flex justify-between gap-3"><span>Disk</span><span class="font-semibold text-slate-900">{{ $vm->disk_gb }} GB</span></div>
                                <div class="flex justify-between gap-3"><span>Access</span><span class="font-semibold text-slate-900">{{ $vm->practicalAccesses->count() }} students</span></div>
                            </div>
                        </details>

                        <form method="POST" action="{{ route('dashboard.simulate.vm.resources', $vm) }}">
                            @csrf
                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400" @disabled($vm->trashed() || $isProtectedVm)>Edit Resource</button>
                        </form>
                        <form method="POST" action="{{ route('dashboard.simulate.vm.delete', $vm) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-slate-400" @disabled($vm->trashed() || $isProtectedVm)>Soft Delete</button>
                        </form>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-4">
        <div class="rounded-xl bg-slate-50 p-3">
            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">CPU</p>
            <p class="mt-1 text-sm font-semibold text-slate-950">{{ $vm->cpu_cores }} core</p>
        </div>
        <div class="rounded-xl bg-slate-50 p-3">
            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">RAM</p>
            <p class="mt-1 text-sm font-semibold text-slate-950">{{ $vm->memory_mb }} MB</p>
        </div>
        <div class="rounded-xl bg-slate-50 p-3">
            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Disk</p>
            <p class="mt-1 text-sm font-semibold text-slate-950">{{ $vm->disk_gb }} GB</p>
        </div>
        <div class="rounded-xl bg-indigo-50 p-3">
            <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-600">Secure Connection</p>
            <p class="mt-1 text-sm font-semibold text-indigo-950">Managed by PAM</p>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-bold text-indigo-700">PAM Protected</span>
        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700">Audited</span>
        <span class="rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-bold text-violet-700">Secure Gateway</span>
    </div>
</article>
