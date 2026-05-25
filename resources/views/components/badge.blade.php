@props(['type' => 'default'])

@php
$normalizedType = str_starts_with($type, 'badge-') ? substr($type, 6) : $type;
$classes = [
    'active' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
    'running' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
    'expired' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
    'revoked' => 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20',
    'closed' => 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-500/20',
    'stopped' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
    'deleted' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
    'blocked' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
    'allowed' => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-600/20',
    'protected' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
    'critical' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
    'system' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
    'succeeded' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
    'failed' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
    'pending' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
    'inactive' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-500/20',
    'owned' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
    'unassigned' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-500/20',
    'ended' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
    'ended-student' => 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-500/20',
    'default' => 'bg-slate-50 text-slate-600 ring-1 ring-inset ring-slate-500/20',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' . ($classes[$normalizedType] ?? $classes['default'])]) }}>
    {{ $slot }}
</span>
