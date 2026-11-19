@props([
    'variant' => 'primary', // primary, secondary, danger, danger-soft, ghost, link, icon
    'size' => 'md', // sm, md
    'href' => null,
    'type' => 'button',
    'loading' => false,
    'disabled' => false,
])

@php
    $variantClass = match ($variant) {
        'secondary' => 'bs-ui-btn-secondary',
        'danger' => 'bs-ui-btn-danger',
        'danger-soft' => 'bs-ui-btn-danger-soft',
        'ghost' => 'bs-ui-btn-ghost',
        'link' => 'bs-ui-btn-link',
        'icon' => 'bs-ui-btn-icon',
        default => 'bs-ui-btn-primary',
    };

    $sizeClass = $size === 'sm' ? 'bs-ui-btn-sm' : '';
    $classes = trim("bs-ui-btn {$variantClass} {$sizeClass}".($loading ? ' is-loading' : ''));
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => $classes]) }}
        @if ($disabled) aria-disabled="true" tabindex="-1" @endif
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->merge(['class' => $classes]) }}
        @if ($disabled || $loading) disabled @endif
        @if ($loading) aria-busy="true" @endif
    >
        @if ($loading)
            <span class="bs-ui-spinner" aria-hidden="true"></span>
        @endif
        {{ $slot }}
    </button>
@endif
