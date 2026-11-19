@props([
    'id',
    'title',
])

<div
    id="{{ $id }}"
    class="bs-ui-modal-backdrop bs-hidden"
    data-ui-modal
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
    aria-hidden="true"
>
    <div class="bs-ui-modal-dialog" data-ui-modal-dialog>
        <div class="bs-ui-modal-header">
            <h2 id="{{ $id }}-title" class="bs-ui-modal-title">{{ $title }}</h2>
            <button
                type="button"
                class="bs-ui-modal-close"
                data-ui-modal-close
                aria-label="Đóng hộp thoại"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="bs-ui-modal-body">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="bs-ui-modal-footer">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
