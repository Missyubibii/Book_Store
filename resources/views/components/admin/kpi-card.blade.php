@props([
    'label',
    'value',
    'subtext' => null,
    'variant' => 'blue',
])

@php
    $iconVariantClass = match ($variant) {
        'green' => 'bs-ad-kpi-icon-green',
        'purple' => 'bs-ad-kpi-icon-purple',
        'amber' => 'bs-ad-kpi-icon-amber',
        default => 'bs-ad-kpi-icon-blue',
    };
@endphp

<article {{ $attributes->merge(['class' => 'bs-ad-kpi-card']) }}>
    <div class="bs-ad-kpi-head">
        <span class="bs-ad-kpi-label">{{ $label }}</span>
        @isset($icon)
            <span class="bs-ad-kpi-icon {{ $iconVariantClass }}" aria-hidden="true">
                {{ $icon }}
            </span>
        @endisset
    </div>

    <div class="bs-ad-kpi-value-row">
        <span class="bs-ad-kpi-value">{{ $value }}</span>
        @isset($badge)
            {{ $badge }}
        @endisset
    </div>

    @if ($subtext)
        <p class="bs-ad-kpi-subtext">{{ $subtext }}</p>
    @endif
</article>
