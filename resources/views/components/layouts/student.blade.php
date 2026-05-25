@props(['title' => 'Student Dashboard', 'subtitle' => null, 'user' => null])

@php
    $displayUser = $user ?? auth()->user();
    $initials = $displayUser
        ? str($displayUser->name)->explode(' ')->filter()->take(2)->map(fn ($part) => str($part)->substr(0, 1))->implode('')
        : 'ST';

    $navItems = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => request()->routeIs('dashboard')],
        ['label' => 'My Virtual Machines', 'route' => 'dashboard.vms', 'active' => request()->routeIs('dashboard.vms')],
        ['label' => 'Activity History', 'route' => 'dashboard.audit-logs', 'active' => request()->routeIs('dashboard.audit-logs')],
        ['label' => 'Help / Lab Guide', 'route' => 'dashboard.templates', 'active' => request()->routeIs('dashboard.templates')],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - PAM Virtual Lab</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />
    @unless (app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased">
    <div class="min-h-screen lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="hidden h-screen flex-col border-r border-slate-200 bg-white lg:sticky lg:top-0 lg:flex">
            <div class="border-b border-slate-100 p-6">
                <div class="flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-sm font-black text-white shadow-sm">
                        VL
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold leading-none">Virtual Lab</p>
                        <p class="mt-1 truncate text-xs text-slate-500">Student workspace</p>
                    </div>
                </div>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                @foreach ($navItems as $item)
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition
                        {{ $item['active'] ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}">
                        <span class="h-2 w-2 rounded-full {{ $item['active'] ? 'bg-indigo-500' : 'bg-slate-300' }}"></span>
                        <span class="truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-slate-100 p-4">
                <a href="{{ route('dashboard') }}" class="mb-3 flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-50 hover:text-slate-900">
                    PAM protected workspace
                </a>
                <div class="flex items-center gap-3 px-2">
                    <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700">
                        {{ strtoupper($initials) }}
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ $displayUser?->name ?? 'Student' }}</p>
                        <p class="truncate text-xs capitalize text-slate-500">{{ $displayUser?->role ?? 'student' }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="min-w-0">
            <input id="student-nav-toggle" type="checkbox" class="peer hidden">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <div class="min-w-0">
                        <h1 class="truncate text-lg font-bold">{{ $title }}</h1>
                        @if ($subtitle)
                            <p class="truncate text-xs text-slate-500">{{ $subtitle }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('dashboard.templates') }}" class="hidden text-xs font-semibold text-slate-500 hover:text-slate-950 sm:inline">
                            Need help?
                        </a>
                        <label for="student-nav-toggle" class="grid h-10 w-10 cursor-pointer place-items-center rounded-lg border border-slate-200 text-slate-600 lg:hidden" aria-label="Open navigation">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </label>
                    </div>
                </div>
            </header>

            <label for="student-nav-toggle" class="fixed inset-0 z-40 hidden bg-slate-950/50 peer-checked:block lg:hidden" aria-label="Close navigation"></label>
            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-white shadow-2xl transition-transform duration-200 peer-checked:translate-x-0 lg:hidden">
                <div class="flex items-center justify-between border-b border-slate-100 p-5">
                    <div class="flex items-center gap-3">
                        <div class="grid h-10 w-10 place-items-center rounded-xl bg-indigo-600 text-sm font-black text-white">VL</div>
                        <div>
                            <p class="text-sm font-bold">Virtual Lab</p>
                            <p class="text-xs text-slate-500">Student workspace</p>
                        </div>
                    </div>
                    <label for="student-nav-toggle" class="cursor-pointer p-1 text-slate-500" aria-label="Close navigation">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </label>
                </div>
                <nav class="flex-1 space-y-1 px-4 py-5">
                    @foreach ($navItems as $item)
                        <a href="{{ route($item['route']) }}"
                           class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition
                            {{ $item['active'] ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}">
                            <span class="h-2 w-2 rounded-full {{ $item['active'] ? 'bg-indigo-500' : 'bg-slate-300' }}"></span>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
            </aside>

            <div class="px-4 py-6 sm:px-6 lg:px-8">
                @if (session('status'))
                    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ session('error') }}</div>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
