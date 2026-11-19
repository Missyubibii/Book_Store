{{--
    Component Thẻ Xem trước Dữ liệu (Data-First Preview Card)
    - Mục đích: Hiển thị ngay dữ liệu tóm tắt hữu ích (3-5 bản ghi hoặc bảng gọn có giới hạn chiều cao)
      thay vì ẩn toàn bộ nội dung trong accordion đóng.
    - Props:
      * title: Tiêu đề khối xem trước.
      * subtitle: Mô tả ngắn tùy chọn.
      * count: Số lượng bản ghi hoặc chuỗi tóm tắt ngắn hiển thị bên cạnh tiêu đề.
      * headingId: ID tiêu đề phục vụ `aria-labelledby`.
      * footerHref / footerLabel: Liên kết "Xem tất cả" / "Xem thêm" ở chân thẻ (tùy chọn).
    - Slots: `headerActions`, `slot` (nội dung xem trước), `footer` (tùy chọn).
--}}
@props([
    'title',
    'subtitle' => null,
    'count' => null,
    'headingId' => null,
    'footerHref' => null,
    'footerLabel' => 'Xem tất cả',
])

<section
    {{ $attributes->merge(['class' => 'bs-ad-table-card bs-ui-preview-card']) }}
    @if ($headingId) aria-labelledby="{{ $headingId }}" @endif
>
    <div class="bs-ad-card-header">
        <div class="bs-ad-card-header-copy">
            <div class="bs-ui-preview-title-row">
                <h2 @if ($headingId) id="{{ $headingId }}" @endif class="bs-ad-card-title">{{ $title }}</h2>
                @if ($count !== null)
                    <span class="bs-ui-line-tab-count">{{ $count }}</span>
                @endif
            </div>
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

    <div class="bs-ui-preview-body">
        {{ $slot }}
    </div>

    @if ($footerHref || isset($footer))
        <div class="bs-ui-preview-footer">
            @isset($footer)
                {{ $footer }}
            @else
                <a href="{{ $footerHref }}" class="bs-ui-btn bs-ui-btn-link bs-ui-btn-sm">
                    <span>{{ $footerLabel }}</span>
                    <span aria-hidden="true">&rarr;</span>
                </a>
            @endisset
        </div>
    @endif
</section>
