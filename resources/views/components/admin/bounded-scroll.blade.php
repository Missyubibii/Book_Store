{{--
    Component Vùng Cuộn Độc lập Có Giới hạn Chiều cao (Bounded Scroll Region)
    - Mục đích: Giới hạn chiều cao danh sách/bảng xem trước và cho phép cuộn dọc độc lập
      mà không kéo dài vô hạn trang quản trị hoặc gây tràn ngang.
    - Props:
      * maxHeight: 'sm' (16rem), 'md' (22rem, mặc định), 'lg' (28rem).
      * label: Nhãn trợ năng `aria-label` cho vùng cuộn bàn phím (`tabindex="0"`).
--}}
@props([
    'maxHeight' => 'md',
    'label' => 'Vùng danh sách cuộn độc lập',
])

@php
    $heightClass = match ($maxHeight) {
        'sm' => 'bs-ui-bounded-scroll-sm',
        'lg' => 'bs-ui-bounded-scroll-lg',
        default => 'bs-ui-bounded-scroll-md',
    };
@endphp

<div
    {{ $attributes->merge(['class' => 'bs-ui-bounded-scroll '.$heightClass]) }}
    tabindex="0"
    role="region"
    aria-label="{{ $label }}"
>
    {{ $slot }}
</div>
