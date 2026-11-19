@props([
    'title',
    'subtitle' => null,
    'headingId' => null,
    'initialOpen' => false,
])

<section
    {{ $attributes->merge(['class' => 'bs-ad-table-card']) }}
    @if ($headingId) aria-labelledby="{{ $headingId }}" @endif
    data-confirm-disclosure
    data-initial-open="{{ $initialOpen ? 'true' : 'false' }}"
>
    <div class="bs-ad-card-header">
        <div class="bs-ad-card-header-copy">
            <h2 @if ($headingId) id="{{ $headingId }}" @endif class="bs-ad-card-title">{{ $title }}</h2>
            @if ($subtitle)
                <p class="bs-ad-card-subtitle">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($trigger)
            <div @class(['bs-ad-card-header-actions', 'bs-hidden' => $initialOpen]) data-confirm-trigger-wrap>
                {{ $trigger }}
            </div>
        @endisset
    </div>

    <div class="bs-ad-card-body">
        {{ $slot }}
    </div>
</section>
