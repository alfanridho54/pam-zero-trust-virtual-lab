<x-layouts.student
    title="Activity History"
    subtitle="Your recent lab activity inside the PAM protected workspace."
    :user="$currentUser"
>
    <div class="space-y-8">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">PAM Activity</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Activity History</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                    Your recent lab activity inside the PAM protected workspace.
                </p>
            </div>
            <a href="{{ route('dashboard') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700">
                Back to Dashboard
            </a>
        </section>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-student.quick-stat label="Terminal Sessions" value="{{ $summary['terminalSessions'] }}" tint="indigo">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 5h14v14H5z"/></svg>
                </x-slot:icon>
            </x-student.quick-stat>
            <x-student.quick-stat label="Commands Executed" value="{{ $summary['commandsExecuted'] }}" tint="emerald">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8l-4 4 4 4m10-8l4 4-4 4M14 4l-4 16"/></svg>
                </x-slot:icon>
            </x-student.quick-stat>
            <x-student.quick-stat label="Blocked Commands" value="{{ $summary['blockedCommands'] }}" tint="rose">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M5 5l14 14M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z"/></svg>
                </x-slot:icon>
            </x-student.quick-stat>
            <x-student.quick-stat label="Last Activity" value="{{ $summary['lastActivity'] ? $summary['lastActivity']->diffForHumans() : 'No activity yet' }}" tint="amber">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2m4-2a8 8 0 11-16 0 8 8 0 0116 0z"/></svg>
                </x-slot:icon>
            </x-student.quick-stat>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-slate-950">Recent Activity Timeline</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Newest events from your VM, terminal sessions, and command policy checks.</p>
                </div>
                <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                    {{ $activityItems->count() }} recent items
                </span>
            </div>

            <div class="space-y-4">
                @forelse ($activityItems as $item)
                    <article class="rounded-xl border border-slate-200 bg-white p-4 transition hover:border-indigo-200 hover:bg-indigo-50/20">
                        <div class="flex gap-4">
                            <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl {{ $item['status'] === 'blocked' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-600' }}">
                                @if ($item['icon'] === 'command')
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3"/></svg>
                                @elseif ($item['icon'] === 'blocked')
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M5 5l14 14"/></svg>
                                @elseif ($item['icon'] === 'access')
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7 4h10l2 4v10a2 2 0 01-2 2H7a2 2 0 01-2-2V8l2-4z"/></svg>
                                @else
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <h4 class="text-sm font-bold text-slate-950">{{ $item['title'] }}</h4>
                                        <p class="mt-1 line-clamp-2 text-sm leading-6 text-slate-500">{{ $item['description'] }}</p>
                                    </div>
                                    <x-badge type="{{ $item['status'] }}">{{ str($item['status'])->replace('-', ' ')->title() }}</x-badge>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                                        {{ $item['vm_name'] }}
                                    </span>
                                    <span class="font-mono">{{ $item['occurred_at']?->format('Y-m-d H:i') ?? '-' }}</span>
                                    <span>{{ $item['occurred_at']?->diffForHumans() ?? 'Unknown time' }}</span>
                                </div>
                            </div>
                        </div>
                    </article>
                @empty
                    <x-empty-state message="No student activity has been recorded yet." />
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.student>
