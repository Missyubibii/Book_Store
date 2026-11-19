@props([
    'title' => null,
    'headingId' => null,
])

<section {{ $attributes->merge(['class' => 'bs-ad-table-card']) }} @if ($headingId) aria-labelledby="{{ $headingId }}" @endif>
    @if ($title || isset($headerActions))
        <div class="bs-ad-card-header">
            @if ($title)
                <h2 @if ($headingId) id="{{ $headingId }}" @endif class="bs-ad-card-title">{{ $title }}</h2>
            @endif
            @isset($headerActions)
                <div class="bs-ad-card-header-actions">
                    {{ $headerActions }}
                </div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</section>
