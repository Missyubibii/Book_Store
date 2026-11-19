@props([
    'title' => null,
    'subtitle' => null,
    'headingId' => null,
])

<section
    {{ $attributes->merge(['class' => 'bs-ad-table-card']) }}
    @if ($headingId) aria-labelledby="{{ $headingId }}" @endif
>
    @if ($title || $subtitle || isset($headerActions))
        <div class="bs-ad-card-header">
            <div class="bs-ad-card-header-copy">
                @if ($title)
                    <h2 @if ($headingId) id="{{ $headingId }}" @endif class="bs-ad-card-title">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="bs-ad-card-subtitle">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($headerActions)
                <div class="bs-ad-card-header-actions">
                    {{ $headerActions }}
                </div>
            @endisset
        </div>
    @endif

    <div class="bs-ad-card-body">
        {{ $slot }}
    </div>
</section>
