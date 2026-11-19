@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'bs-ad-empty-box']) }}>
    @isset($icon)
        <div class="bs-ad-empty-icon-wrap" aria-hidden="true">
            {{ $icon }}
        </div>
    @endisset

    @if ($title)
        <h3 class="bs-ad-empty-title">{{ $title }}</h3>
    @endif

    @if ($description)
        <p class="bs-ad-empty-desc">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="bs-ad-empty-actions">
            {{ $slot }}
        </div>
    @endif
</div>
