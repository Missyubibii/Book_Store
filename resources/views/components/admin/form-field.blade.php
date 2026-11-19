@props([
    'label',
    'for' => null,
    'required' => false,
    'help' => null,
    'error' => null,
    'full' => false,
])

<div {{ $attributes->merge(['class' => 'bs-ad-field'.($full ? ' bs-ad-field-full' : '')]) }}>
    @if ($for)
        <label for="{{ $for }}" class="bs-ad-label">
            {{ $label }}
            @if ($required)
                <span class="bs-ad-required-mark" aria-hidden="true">*</span>
            @endif
        </label>
    @else
        <span class="bs-ad-label">
            {{ $label }}
            @if ($required)
                <span class="bs-ad-required-mark" aria-hidden="true">*</span>
            @endif
        </span>
    @endif

    {{ $slot }}

    @if ($help)
        <p class="bs-ad-help">{{ $help }}</p>
    @endif

    @if ($error)
        <p class="bs-ad-field-error" role="alert">{{ $error }}</p>
    @endif
</div>
