{{--
    Component Danh sách Trạng thái Gọn (Compact Status List)
    - Mục đích: Hiển thị nhanh các chỉ số/trạng thái quan trọng theo từng hàng bo tròn
      trên nền `#F5F5F7` với chấm màu ngữ nghĩa và giá trị căn phải.
    - Props:
      * items: Mảng các mục `['label' => string, 'value' => string, 'dot' => 'green'|'amber'|'red'|'blue'|'neutral']`.
--}}
@props([
    'items' => [],
])

<div {{ $attributes->merge(['class' => 'bs-ui-compact-status-list']) }}>
    @foreach ($items as $item)
        @php
            $dotClass = match ($item['dot'] ?? 'neutral') {
                'green' => 'bs-ad-dot-green',
                'amber' => 'bs-ad-dot-amber',
                'red' => 'bs-ad-dot-red',
                'blue' => 'bs-ui-dot-blue',
                default => 'bs-ui-dot-neutral',
            };
        @endphp
        <div class="bs-ui-compact-status-row">
            <span class="bs-ui-compact-status-label">
                <span class="bs-ad-dot {{ $dotClass }}" aria-hidden="true"></span>
                <span>{{ $item['label'] }}</span>
            </span>
            <span class="bs-ui-compact-status-value">{{ $item['value'] }}</span>
        </div>
    @endforeach
    {{ $slot }}
</div>
