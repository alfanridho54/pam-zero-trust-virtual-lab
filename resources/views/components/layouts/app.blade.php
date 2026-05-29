@props(['title' => 'Dashboard', 'user' => null])

@php
$currentRoute = request()->route()?->getName() ?? '';
$navItems = [
    ['label' => 'Dashboard', 'route' => 'dashboard', 'section' => 'overview'],
    ['label' => 'Template Library', 'route' => 'dashboard.templates', 'section' => 'templates'],
    ['label' => 'Kelola VM Siswa', 'route' => 'dashboard.vms', 'section' => 'vms'],
    ['label' => 'Audit Logs', 'route' => 'dashboard.audit-logs', 'section' => 'audit-logs', 'admin' => true],
    ['label' => 'SOC Monitoring', 'route' => 'dashboard.soc', 'section' => 'soc', 'admin' => true],
];
$isActive = fn($item) => $currentRoute === $item['route'];
$displayUser = $user ?? auth()->user();
$initials = $displayUser
    ? str($displayUser->name)->explode(' ')->filter()->take(2)->map(fn ($part) => str($part)->substr(0, 1))->implode('')
    : 'AD';
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
                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-sm font-black text-white shadow-sm">
                        PAM
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold leading-none">PAM Virtual Lab</p>
                        <p class="mt-1 truncate text-xs text-slate-500">Admin workspace</p>
                    </div>
                </div>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                @foreach ($navItems as $item)
                    @continue(($item['admin'] ?? false) && ($displayUser?->role !== 'admin'))
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition
                        {{ $isActive($item) ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}">
                        <span class="h-2 w-2 rounded-full {{ $isActive($item) ? 'bg-indigo-500' : 'bg-slate-300' }}"></span>
                        <span class="truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            @if ($displayUser)
                <div class="border-t border-slate-100 p-4">
                    <div class="mb-3 rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">
                        PAM protected workspace
                    </div>
                    <div class="flex items-center gap-3 px-2">
                        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700">
                            {{ strtoupper($initials) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ $displayUser->name }}</p>
                            <p class="truncate text-xs capitalize text-slate-500">{{ $displayUser->role ?? 'admin' }}</p>
                        </div>
                    </div>
                </div>
            @endif
        </aside>

        <main class="min-w-0">
            <input type="checkbox" id="mobile-nav-toggle" class="peer hidden">

            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <div class="min-w-0">
                        <h1 class="truncate text-lg font-bold text-slate-950">{{ $title }}</h1>
                        <p class="truncate text-xs text-slate-500">Zero Trust PAM control center</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        @if ($displayUser)
                            <details class="relative">
                                <summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-full border border-slate-200 bg-white px-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50/40 hover:text-indigo-700 [&::-webkit-details-marker]:hidden">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-indigo-100 text-[11px] font-bold text-indigo-700">
                                        {{ strtoupper($initials) }}
                                    </span>
                                    <span class="hidden max-w-36 truncate sm:inline">{{ $displayUser->name }}</span>
                                    <svg class="hidden h-4 w-4 text-slate-400 sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/>
                                    </svg>
                                </summary>
                                <div class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl shadow-slate-950/10">
                                    <div class="border-b border-slate-100 px-4 py-3">
                                        <p class="truncate text-sm font-bold text-slate-950">{{ $displayUser->name }}</p>
                                        <span class="mt-2 inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-indigo-700">
                                            {{ $displayUser->role ?? 'admin' }}
                                        </span>
                                    </div>
                                    <form method="POST" action="{{ route('logout') }}" class="p-2">
                                        @csrf
                                        <button type="submit" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-indigo-50 hover:text-indigo-700">
                                            <span>Logout</span>
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H3m12 0-4-4m4 4-4 4M21 5v14a2 2 0 0 1-2 2h-6m0-18h6a2 2 0 0 1 2 2"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </details>
                        @endif
                        <label class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 lg:hidden" for="mobile-nav-toggle" aria-label="Open navigation">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </label>
                    </div>
                </div>
            </header>

            <label for="mobile-nav-toggle" class="fixed inset-0 z-40 hidden bg-slate-950/50 peer-checked:block lg:hidden" aria-label="Close navigation"></label>
            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-white shadow-2xl transition-transform duration-200 peer-checked:translate-x-0 lg:hidden">
                <div class="flex items-center justify-between border-b border-slate-100 p-5">
                    <div class="flex items-center gap-3">
                        <div class="grid h-10 w-10 place-items-center rounded-xl bg-indigo-600 text-sm font-black text-white">PAM</div>
                        <div>
                            <p class="text-sm font-bold">PAM Virtual Lab</p>
                            <p class="text-xs text-slate-500">Admin workspace</p>
                        </div>
                    </div>
                    <label for="mobile-nav-toggle" class="cursor-pointer p-1 text-slate-500" aria-label="Close navigation">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </label>
                </div>
                <nav class="flex-1 space-y-1 px-4 py-5">
                    @foreach ($navItems as $item)
                        @continue(($item['admin'] ?? false) && ($displayUser?->role !== 'admin'))
                        <a href="{{ route($item['route']) }}"
                           class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition
                            {{ $isActive($item) ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}">
                            <span class="h-2 w-2 rounded-full {{ $isActive($item) ? 'bg-indigo-500' : 'bg-slate-300' }}"></span>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
                @if ($displayUser)
                    <div class="border-t border-slate-100 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="grid h-9 w-9 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700">{{ strtoupper($initials) }}</div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ $displayUser->name }}</p>
                                <p class="truncate text-xs capitalize text-slate-500">{{ $displayUser->role ?? 'user' }}</p>
                            </div>
                        </div>
                    </div>
                @endif
            </aside>

            <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                @if (session('status'))
                    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
                @endif

                @if (session('error'))
                    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</div>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
