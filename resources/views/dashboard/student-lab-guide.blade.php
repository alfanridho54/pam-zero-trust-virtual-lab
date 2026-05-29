<x-layouts.student
    title="Help / Lab Guide"
    subtitle="Choose a template and follow the safe lab workflow."
    :user="$currentUser"
>
    <div class="space-y-8">
        <section class="rounded-2xl bg-gradient-to-br from-indigo-600 via-indigo-600 to-violet-600 p-6 text-white shadow-lg shadow-indigo-500/20 sm:p-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-wider text-indigo-100">Student Lab Guide</p>
                    <h2 class="mt-2 text-3xl font-bold leading-tight">Practice safely inside the PAM workspace</h2>
                    <p class="mt-3 text-sm leading-6 text-indigo-100">
                        Use these templates as starting points, open terminal only from your assigned VM, and keep commands inside the monitored lab session.
                    </p>
                </div>
                <a href="{{ route('student.vms.index') }}" class="inline-flex min-h-10 w-fit items-center justify-center rounded-lg bg-white px-4 text-sm font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-50">
                    Manage My VMs
                </a>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                </div>
                <h3 class="mt-4 text-sm font-bold text-slate-950">Create or use a VM</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">Start from an enabled lab template or use a VM assigned by your instructor.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 5h14v14H5z"/></svg>
                </div>
                <h3 class="mt-4 text-sm font-bold text-slate-950">Open terminal</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">Connect through the PAM terminal so sessions and command outcomes are recorded.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-amber-50 text-amber-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7 4h10l2 4v10a2 2 0 01-2 2H7a2 2 0 01-2-2V8l2-4z"/></svg>
                </div>
                <h3 class="mt-4 text-sm font-bold text-slate-950">Review activity</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">Check your Activity History to confirm sessions, VM actions, and blocked commands.</p>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-slate-950">Available Lab Templates</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Templates currently enabled for student practice.</p>
                </div>
                <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                    {{ $vmTemplates->count() }} templates
                </span>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @forelse ($vmTemplates as $template)
                    <article class="rounded-xl border border-slate-200 bg-white p-5 transition hover:border-indigo-200 hover:bg-indigo-50/20">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h4 class="text-sm font-bold text-slate-950">{{ $template->name }}</h4>
                                <p class="mt-2 text-sm leading-6 text-slate-500">{{ $template->description ?: 'Student practice template.' }}</p>
                            </div>
                            <x-badge type="succeeded">Enabled</x-badge>
                        </div>

                        <div class="mt-5 grid grid-cols-3 gap-3 text-sm">
                            <div class="rounded-lg bg-slate-50 p-3">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">CPU</p>
                                <p class="mt-1 font-semibold text-slate-950">{{ $template->cpu }} core</p>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">RAM</p>
                                <p class="mt-1 font-semibold text-slate-950">{{ $template->ram }} MB</p>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Disk</p>
                                <p class="mt-1 font-semibold text-slate-950">{{ $template->disk }} GB</p>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="lg:col-span-2">
                        <x-empty-state message="No lab templates are available right now." />
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.student>
