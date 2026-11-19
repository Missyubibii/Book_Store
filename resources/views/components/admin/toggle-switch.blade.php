@props([
    'name',
    'label',
    'checked' => false,
    'disabled' => false,
    'id' => null,
])

@php
    $inputId = $id ?? 'bs-toggle-'.$name;
@endphp

<label for="{{ $inputId }}" {{ $attributes->merge(['class' => 'bs-ui-toggle-row'.($disabled ? ' is-disabled' : '')]) }}>
    <span class="bs-ui-toggle-label">{{ $label }}</span>
    <span class="bs-ui-toggle-control">
        <input
            id="{{ $inputId }}"
            type="checkbox"
            name="{{ $name }}"
            value="1"
            class="bs-ui-toggle-input"
            @checked($checked)
            @disabled($disabled)
        >
        <span class="bs-ui-toggle-track" aria-hidden="true">
            <span class="bs-ui-toggle-thumb"></span>
        </span>
    </span>
</label>
