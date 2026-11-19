@props([
    'name' => 'search',
    'value' => '',
    'placeholder' => 'Tìm kiếm...',
    'label' => 'Tìm kiếm',
    'id' => null,
])

@php
    $inputId = $id ?? 'admin-search-'.$name;
@endphp

<div {{ $attributes->merge(['class' => 'bs-ad-compact-search']) }}>
    <label for="{{ $inputId }}" class="sr-only">{{ $label }}</label>
    <span class="bs-ad-compact-search-icon" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
    </span>
    <input
        id="{{ $inputId }}"
        type="search"
        name="{{ $name }}"
        value="{{ $value }}"
        placeholder="{{ $placeholder }}"
        class="bs-ad-compact-search-input"
    >
</div>
