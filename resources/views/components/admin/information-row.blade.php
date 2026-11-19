@props([
    'label',
    'value' => null,
])

<div {{ $attributes->merge(['class' => 'bs-ad-info-row']) }}>
    <dt class="bs-ad-info-label">{{ $label }}</dt>
    <dd class="bs-ad-info-value">
        @if (! $slot->isEmpty())
            {{ $slot }}
        @else
            {{ $value }}
        @endif
    </dd>
</div>
