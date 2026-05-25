<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'PAM Virtual Lab') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />
    @unless (app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
</head>
<body class="bg-slate-900 text-slate-100 antialiased font-sans min-h-screen flex flex-col">
    <header class="px-6 py-5 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600 text-white text-xs font-bold">PAM</div>
            <span class="text-sm font-semibold text-white">PAM Virtual Lab</span>
        </div>
        <nav class="flex items-center gap-4">
            @auth
                <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Dashboard</a>
            @else
                @if (Route::has('login'))
                    <a href="{{ route('login') }}" class="text-sm font-medium text-slate-300 hover:text-white transition-colors">Log in</a>
                @endif
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Register</a>
                @endif
            @endauth
        </nav>
    </header>

    <main class="flex-1 flex flex-col items-center justify-center px-6 py-16">
        <div class="w-full max-w-lg text-center">
            <div class="flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-blue-600 text-white text-xl font-bold mx-auto mb-6 shadow-lg shadow-indigo-500/20">PAM</div>
            <h1 class="text-4xl font-bold text-white tracking-tight">Privileged Access Management</h1>
            <p class="mt-4 text-lg text-slate-400 leading-relaxed">Virtual Lab Platform with Zero Trust Architecture. Secure SSH terminal session monitoring with Cloudflare integration.</p>
            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                @auth
                    <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 w-full sm:w-auto">Go to Dashboard</a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-800 px-6 py-3 text-sm font-semibold text-slate-200 ring-1 ring-inset ring-slate-700 hover:bg-slate-700 hover:text-white w-full sm:w-auto">Log in</a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 w-full sm:w-auto">Get Started</a>
                    @endif
                @endauth
            </div>
        </div>

        <div class="mt-20 w-full max-w-4xl grid grid-cols-1 sm:grid-cols-3 gap-6">
            <div class="bg-slate-800/50 rounded-xl border border-slate-700/50 p-6 text-center">
                <div class="w-10 h-10 rounded-lg bg-indigo-500/10 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h3 class="text-sm font-semibold text-white">Zero Trust Security</h3>
                <p class="mt-2 text-xs text-slate-400 leading-relaxed">Cloudflare Access integration with per-request authentication and authorization.</p>
            </div>
            <div class="bg-slate-800/50 rounded-xl border border-slate-700/50 p-6 text-center">
                <div class="w-10 h-10 rounded-lg bg-emerald-500/10 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <h3 class="text-sm font-semibold text-white">SSH Session Monitoring</h3>
                <p class="mt-2 text-xs text-slate-400 leading-relaxed">Real-time command execution logging, blocking, and audit trail for all terminal sessions.</p>
            </div>
            <div class="bg-slate-800/50 rounded-xl border border-slate-700/50 p-6 text-center">
                <div class="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                </div>
                <h3 class="text-sm font-semibold text-white">Proxmox VE Integration</h3>
                <p class="mt-2 text-xs text-slate-400 leading-relaxed">Manage virtual machines, templates, and lab environments through Proxmox API.</p>
            </div>
        </div>
    </main>

    <footer class="px-6 py-4 border-t border-slate-800">
        <p class="text-center text-xs text-slate-500">&copy; {{ date('Y') }} PAM Virtual Lab. All rights reserved.</p>
    </footer>
</body>
</html>
