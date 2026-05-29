<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signed Out - PAM Virtual Lab</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />
    @unless (app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased">
    <main class="grid min-h-screen place-items-center px-4 py-10">
        <section class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-xl shadow-slate-950/5 sm:p-8">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-sm font-black text-white shadow-sm">
                PAM
            </div>

            <h1 class="mt-6 text-2xl font-bold text-slate-950">You have been signed out from PAM Virtual Lab.</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">
                If Cloudflare Access session is still active, reopening the app may sign you in again.
            </p>

            <a href="{{ url('/') }}" class="mt-8 inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100">
                Sign in again with Cloudflare Access
            </a>
        </section>
    </main>
</body>
</html>
