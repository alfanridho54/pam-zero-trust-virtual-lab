@props(['label', 'value', 'tint' => 'indigo'])

@php
    $tints = [
        'indigo' => 'bg-indigo-50 text-indigo-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'emerald' => 'bg-emerald-50 text-emerald-600',
        'rose' => 'bg-rose-50 text-rose-600',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm']) }}>
    <div class="grid h-11 w-11 place-items-center rounded-xl {{ $tints[$tint] ?? $tints['indigo'] }}">
        {{ $icon ?? '' }}
    </div>
    <div class="min-w-0">
        <p class="text-xs text-slate-500">{{ $label }}</p>
        <p class="truncate text-lg font-semibold tracking-tight text-slate-950">{{ $value }}</p>
    </div>
</div>
