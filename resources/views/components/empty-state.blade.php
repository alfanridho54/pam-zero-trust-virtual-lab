@props(['message' => 'No data available.', 'icon' => false])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center py-12 px-6 text-center']) }}>
    @if ($icon)
        <div class="mb-3 text-slate-300">
            {{ $icon }}
        </div>
    @endif
    <p class="text-sm text-slate-500">{{ $message }}</p>
</div>
