@props([
    'variant' => 'neutral',
    'dot' => false,
])

@php
    $badgeClass = match ($variant) {
        'success' => 'bs-ad-status-badge-success',
        'warning' => 'bs-ad-status-badge-warning',
        'danger' => 'bs-ad-status-badge-danger',
        'info' => 'bs-ad-status-badge-info',
        'brand' => 'bs-ad-status-badge-brand',
        default => 'bs-ad-status-badge-neutral',
    };
@endphp

<span {{ $attributes->merge(['class' => 'bs-ad-status-badge '.$badgeClass]) }}>
    @if ($dot)
        <span class="bs-ad-status-badge-dot" aria-hidden="true"></span>
    @endif
    <span>{{ $slot }}</span>
</span>
