@php
    $statusClass = fn ($vm) => $vm->status === 'running' ? 'running' : ($vm->status === 'stopped' ? 'stopped' : 'default');
@endphp

<x-layouts.student
    title="My Virtual Machines"
    subtitle="Create and manage your own lab VM."
    :user="$currentUser"
>
    <div class="space-y-6">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <form method="POST" action="{{ route('student.vms.store') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(14rem,20rem)_auto] md:items-end">
                @csrf
                <div>
                    <label for="name" class="text-sm font-semibold text-slate-800">VM name</label>
                    <input
                        id="name"
                        name="name"
                        value="{{ old('name') }}"
                        class="mt-2 min-h-10 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                        placeholder="linux-lab-01"
                        maxlength="64"
                    >
                    @error('name')
                        <p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs text-slate-500">{{ $vms->count() }} of {{ $maxStudentVms }} VM quota used.</p>
                </div>
                <div>
                    <label for="vm_template_id" class="text-sm font-semibold text-slate-800">Template</label>
                    <select
                        id="vm_template_id"
                        name="vm_template_id"
                        class="mt-2 min-h-10 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                    >
                        <option value="">Select template</option>
                        @foreach ($vmTemplates as $template)
                            <option value="{{ $template->id }}" @selected((string) old('vm_template_id') === (string) $template->id)>
                                {{ $template->name }} - {{ $template->cpu }} CPU / {{ $template->ram }} MB
                            </option>
                        @endforeach
                    </select>
                    @error('vm_template_id')
                        <p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <button
                    type="submit"
                    class="inline-flex min-h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    @disabled($vms->count() >= $maxStudentVms || $vmTemplates->isEmpty())
                >
                    Create VM
                </button>
            </form>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-base font-semibold text-slate-900">Student VM Dashboard</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Name</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">VMID</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Node</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Owner</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Created</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($vms as $vm)
                            @php
                                $protected = $vm->isProtectedVm();
                                $running = $vm->status === 'running';
                            @endphp
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-5 py-4 text-sm">
                                    <span class="font-semibold text-slate-900">{{ $vm->name }}</span>
                                    @if ($protected)
                                        <span class="ml-2"><x-badge type="system">Protected</x-badge></span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ $vm->proxmoxVmid() ?? '-' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ $vm->node }}</td>
                                <td class="px-5 py-4"><x-badge :type="$statusClass($vm)">{{ $vm->status }}</x-badge></td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ $vm->user?->name ?? '-' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ $vm->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('student.vms.action', [$vm, 'start']) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50" @disabled($protected || $running)>Start</button>
                                        </form>
                                        <form method="POST" action="{{ route('student.vms.action', [$vm, 'stop']) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50" @disabled($protected || ! $running)>Stop</button>
                                        </form>
                                        <form method="POST" action="{{ route('student.vms.action', [$vm, 'shutdown']) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50" @disabled($protected || ! $running)>Shutdown</button>
                                        </form>
                                        <form method="POST" action="{{ route('terminal-sessions.store', $vm) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50" @disabled($protected)>Terminal</button>
                                        </form>
                                        <form method="POST" action="{{ route('student.vms.destroy', $vm) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50" @disabled($protected)>Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-center text-sm text-slate-500">No student VMs yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.student>
