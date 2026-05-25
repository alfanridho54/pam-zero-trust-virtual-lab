@props(['title' => 'Dashboard', 'user' => null])

@php
$currentRoute = request()->route()?->getName() ?? '';
$navItems = [
    ['label' => 'Dashboard', 'route' => 'dashboard', 'section' => 'overview'],
    ['label' => 'Akses VM Praktikum', 'route' => 'dashboard.templates', 'section' => 'templates'],
    ['label' => 'Kelola Lab Pribadi', 'route' => 'dashboard.vms', 'section' => 'vms'],
    ['label' => 'Audit Logs', 'route' => 'dashboard.audit-logs', 'section' => 'audit-logs'],
    ['label' => 'SOC Monitoring', 'route' => 'dashboard.soc', 'section' => 'soc', 'admin' => true],
];
$isActive = fn($item) => $currentRoute === $item['route'];
$displayUser = $user ?? auth()->user();
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
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <div class="min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
        <aside class="hidden border-r border-slate-800 bg-slate-950 text-slate-300 lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col">
            <div class="flex items-center gap-3 border-b border-slate-800 px-6 py-5">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-sm font-extrabold text-white">
                    PAM
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-white">PAM Virtual Lab</p>
                    <p class="truncate text-xs text-slate-400">Zero Trust Dashboard</p>
                </div>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-4 py-5">
                @foreach ($navItems as $item)
                    @continue(($item['admin'] ?? false) && ($displayUser?->role !== 'admin'))
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition
                        {{ $isActive($item) ? 'bg-white text-slate-950' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $isActive($item) ? 'bg-indigo-600' : 'bg-slate-600' }}"></span>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            @if ($displayUser)
                <div class="border-t border-slate-800 px-6 py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-800 text-xs font-bold text-slate-200">
                            {{ strtoupper(substr($displayUser->name, 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-100">{{ $displayUser->name }}</p>
                            <p class="truncate text-xs capitalize text-slate-500">{{ $displayUser->role ?? 'user' }}</p>
                        </div>
                    </div>
                </div>
            @endif
        </aside>

        <main class="min-w-0">
            <input type="checkbox" id="mobile-nav-toggle" class="peer hidden">

            <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">PAM Virtual Lab</p>
                        <h1 class="truncate text-lg font-bold text-slate-950">{{ $title }}</h1>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        @if ($displayUser)
                            <div class="hidden items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm sm:flex">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                <span class="max-w-40 truncate">{{ $displayUser->name }}</span>
                            </div>
                        @endif
                        <label class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 lg:hidden" for="mobile-nav-toggle" aria-label="Open navigation">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </label>
                    </div>
                </div>
            </header>

            <label for="mobile-nav-toggle" class="fixed inset-0 z-40 hidden bg-slate-950/50 peer-checked:block lg:hidden" aria-label="Close navigation"></label>
            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-slate-950 text-slate-300 shadow-2xl transition-transform duration-200 peer-checked:translate-x-0 lg:hidden">
                <div class="flex items-center justify-between border-b border-slate-800 px-6 py-5">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-sm font-extrabold text-white">PAM</div>
                        <div>
                            <p class="text-sm font-semibold text-white">PAM Virtual Lab</p>
                            <p class="text-xs text-slate-400">Zero Trust Dashboard</p>
                        </div>
                    </div>
                    <label for="mobile-nav-toggle" class="cursor-pointer p-1 text-slate-400 hover:text-white" aria-label="Close navigation">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </label>
                </div>
                <nav class="flex-1 space-y-1 px-4 py-5">
                    @foreach ($navItems as $item)
                        @continue(($item['admin'] ?? false) && ($displayUser?->role !== 'admin'))
                        <a href="{{ route($item['route']) }}"
                           class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition
                            {{ $isActive($item) ? 'bg-white text-slate-950' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $isActive($item) ? 'bg-indigo-600' : 'bg-slate-600' }}"></span>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
                @if ($displayUser)
                    <div class="border-t border-slate-800 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-800 text-xs font-bold text-slate-200">{{ strtoupper(substr($displayUser->name, 0, 1)) }}</div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-100">{{ $displayUser->name }}</p>
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
