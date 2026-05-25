@props(['title' => null, 'subtitle' => null, 'action' => null, 'danger' => false])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl border shadow-sm ' . ($danger ? 'border-red-200' : 'border-slate-200')]) }}>
    @if ($title || $action)
        <div class="{{ $danger ? 'bg-red-50' : 'bg-white' }} border-b border-slate-100 rounded-t-xl px-6 py-4 flex items-center justify-between gap-4">
            <div>
                @if ($title)
                    <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>
            @if ($action)
                <div class="flex-shrink-0">{{ $action }}</div>
            @endif
        </div>
    @endif
    <div class="p-0">
        {{ $slot }}
    </div>
</div>
