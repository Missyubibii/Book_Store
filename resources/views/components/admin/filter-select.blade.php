@props([
    'name',
    'label',
    'id' => null,
])

@php
    $selectId = $id ?? 'admin-filter-'.$name;
@endphp

<div {{ $attributes->merge(['class' => 'bs-ad-compact-select-wrap']) }}>
    <label for="{{ $selectId }}" class="sr-only">{{ $label }}</label>
    <select id="{{ $selectId }}" name="{{ $name }}" class="bs-ad-compact-select">
        {{ $slot }}
    </select>
</div>
