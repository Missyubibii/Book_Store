@props([
    'title',
    'subtitle' => null,
    'headingId' => null,
    'panelId',
    'initialOpen' => false,
    'canToggle' => true,
    'openLabel' => 'Chỉnh sửa thông tin',
    'closeLabel' => 'Đóng chỉnh sửa',
])

<section
    {{ $attributes->merge(['class' => 'bs-ad-table-card']) }}
    @if ($headingId) aria-labelledby="{{ $headingId }}" @endif
    @if ($canToggle)
        data-form-disclosure
        data-initial-open="{{ $initialOpen ? 'true' : 'false' }}"
    @endif
>
    <div class="bs-ad-card-header">
        <div class="bs-ad-card-header-copy">
            <h2 @if ($headingId) id="{{ $headingId }}" @endif class="bs-ad-card-title">{{ $title }}</h2>
            @if ($subtitle)
                <p class="bs-ad-card-subtitle">{{ $subtitle }}</p>
            @endif
        </div>

        @if ($canToggle)
            <div class="bs-ad-card-header-actions">
                <button
                    type="button"
                    class="bs-ad-btn-outline"
                    data-disclosure-toggle
                    data-label-open="{{ $openLabel }}"
                    data-label-close="{{ $closeLabel }}"
                    aria-expanded="{{ $initialOpen ? 'true' : 'false' }}"
                    aria-controls="{{ $panelId }}"
                >
                    <span data-disclosure-label>{{ $initialOpen ? $closeLabel : $openLabel }}</span>
                </button>
            </div>
        @endif
    </div>

    <div class="bs-ad-card-body">
        @isset($summary)
            <div
                @class(['bs-ad-disclosure-summary', 'bs-hidden' => $canToggle && $initialOpen])
                @if ($canToggle) data-disclosure-summary @endif
            >
                {{ $summary }}
            </div>
        @endisset

        @if ($canToggle)
            <div
                id="{{ $panelId }}"
                data-disclosure-panel
                aria-hidden="{{ $initialOpen ? 'false' : 'true' }}"
                @class(['bs-ad-disclosure-panel', 'bs-hidden' => ! $initialOpen])
            >
                {{ $slot }}
            </div>
        @endif
    </div>
</section>
