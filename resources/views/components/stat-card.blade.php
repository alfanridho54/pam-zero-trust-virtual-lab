@props(['label' => '', 'value' => '', 'accent' => 'indigo'])

@php
$accentColors = [
    'indigo' => 'border-l-indigo-500',
    'emerald' => 'border-l-emerald-500',
    'amber' => 'border-l-amber-500',
    'red' => 'border-l-red-500',
    'blue' => 'border-l-blue-500',
    'purple' => 'border-l-purple-500',
    'slate' => 'border-l-slate-500',
];
@endphp

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl border border-slate-200 shadow-sm border-l-4 ' . ($accentColors[$accent] ?? 'border-l-indigo-500')]) }}>
    <div class="px-5 py-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
        <p class="mt-1.5 text-2xl font-bold text-slate-900">{{ $value }}</p>
    </div>
</div>
