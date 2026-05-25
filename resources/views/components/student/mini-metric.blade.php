@props(['label', 'value'])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-white/15 bg-white/10 p-3 text-white backdrop-blur-sm']) }}>
    <div class="text-[10px] font-bold uppercase tracking-wider text-indigo-100">{{ $label }}</div>
    <div class="mt-1 font-mono text-xl font-bold tabular-nums">{{ $value }}</div>
</div>
