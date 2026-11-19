@props([
    'label' => 'Điều hướng danh mục',
])

<div {{ $attributes->merge(['class' => 'bs-ui-line-tabs-wrap']) }}>
    <div class="bs-ui-line-tabs" role="tablist" aria-label="{{ $label }}">
        {{ $slot }}
    </div>
</div>
