@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'bs-ad-welcome-bar']) }}>
    <div>
        @isset($breadcrumb)
            <div class="bs-ad-breadcrumb-inline">{{ $breadcrumb }}</div>
        @endisset
        <h1 class="bs-ad-welcome-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="bs-ad-welcome-subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="bs-ad-page-actions">
            {{ $actions }}
        </div>
    @endisset
</div>
