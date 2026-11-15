{{--
    Thành phần Thông báo Động Tái sử dụng — "Book_Store Quiet Glass — Dynamic Notifications" (Stage 01A.3.4)
    - Entry point: <x-notification-center /> trong layouts/auth.blade.php (và các layout tương lai của Book_Store)
    - Nguồn dữ liệu hỗ trợ:
      1. Lỗi xác thực / biểu mẫu ($errors->any()): Hiển thị 1 thông báo tóm tắt ngắn gọn (không lặp lại chồng chéo),
         trong khi lỗi chi tiết từng trường vẫn hiển thị cố định bên dưới từng ô nhập.
      2. Flash session: session('notification'), session('success'), session('error'), session('warning'), session('info'), session('status').
      3. Sự kiện Frontend động: window.BookStoreNotify.show({...}) hoặc CustomEvent('bs:notify').
    - Bảo mật & Trợ năng:
      + Tự động escape 100% chuỗi động (Blade {{ }} và JS textContent / x-text), chống XSS.
      + Sử dụng role="alert" (assertive) cho error/warning và role="status" (polite) cho success/info.
      + Tạm dừng bộ đếm tự đóng khi di chuột (hover) hoặc tập trung bàn phím (focusin).
--}}
@props([
    'errorTitle' => 'Vui lòng kiểm tra lại thông tin:',
])

@php
    $initialNotification = null;

    if (isset($errors) && $errors->any()) {
        $errorCount = $errors->count();
        $firstError = $errors->first();
        $summaryMessage = $errorCount > 1
            ? $firstError . ' (cùng ' . ($errorCount - 1) . ' mục khác cần kiểm tra bên dưới)'
            : $firstError;

        $initialNotification = [
            'type' => 'error',
            'title' => $errorTitle,
            'message' => $summaryMessage,
            'duration' => 5000,
            'persistent' => false,
        ];
    } elseif (is_array(session('notification'))) {
        $raw = session('notification');
        $type = in_array($raw['type'] ?? 'info', ['success', 'error', 'warning', 'info'], true)
            ? $raw['type']
            : 'info';

        $initialNotification = [
            'type' => $type,
            'title' => (string) ($raw['title'] ?? 'Thông báo hệ thống:'),
            'message' => (string) ($raw['message'] ?? ''),
            'duration' => isset($raw['duration']) ? (int) $raw['duration'] : 5000,
            'persistent' => (bool) ($raw['persistent'] ?? false),
        ];
    } elseif (session()->has('error')) {
        $initialNotification = [
            'type' => 'error',
            'title' => 'Không thể thực hiện thao tác:',
            'message' => (string) session('error'),
            'duration' => 5000,
            'persistent' => false,
        ];
    } elseif (session()->has('warning')) {
        $initialNotification = [
            'type' => 'warning',
            'title' => 'Lưu ý:',
            'message' => (string) session('warning'),
            'duration' => 5000,
            'persistent' => false,
        ];
    } elseif (session()->has('success')) {
        $initialNotification = [
            'type' => 'success',
            'title' => 'Hoàn tất:',
            'message' => (string) session('success'),
            'duration' => 5000,
            'persistent' => false,
        ];
    } elseif (session()->has('info')) {
        $initialNotification = [
            'type' => 'info',
            'title' => 'Thông tin:',
            'message' => (string) session('info'),
            'duration' => 5000,
            'persistent' => false,
        ];
    } elseif (session('status') === 'verification-link-sent') {
        $initialNotification = [
            'type' => 'success',
            'title' => 'Đã gửi liên kết mới:',
            'message' => 'Một liên kết xác nhận mới đã được gửi đến địa chỉ email bạn đã đăng ký. Vui lòng kiểm tra cả hộp thư đến và thư mục Quảng cáo hoặc Thư rác.',
            'duration' => 5000,
            'persistent' => false,
        ];
    } elseif (filled(session('status'))) {
        $initialNotification = [
            'type' => 'success',
            'title' => 'Thông báo:',
            'message' => (string) session('status'),
            'duration' => 5000,
            'persistent' => false,
        ];
    }

    $activeType = $initialNotification['type'] ?? 'info';
    $isAssertive = in_array($activeType, ['error', 'warning'], true);
@endphp

<div
    class="bs-notify-viewport"
    data-notification-center
    @if ($initialNotification)
        data-initial-notification="{{ json_encode($initialNotification, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) }}"
    @endif
    x-data="bsNotificationCenter"
    @bs:notify.window="showFromEvent($event)"
    @bs:notify-dismiss.window="dismiss()"
    @keydown.escape.window="dismiss()"
>
    <div
        class="bs-notify-island {{ $initialNotification ? 'is-visible is-expanded' : 'bs-hidden' }}"
        data-notify-island
        data-notify-type="{{ $activeType }}"
        :class="{
            'bs-hidden': !visible,
            'is-visible': visible,
            'is-compact': visible && !expanded,
            'is-expanded': visible && expanded
        }"
        :data-notify-type="type"
        role="{{ $isAssertive ? 'alert' : 'status' }}"
        :role="(type === 'error' || type === 'warning') ? 'alert' : 'status'"
        aria-live="{{ $isAssertive ? 'assertive' : 'polite' }}"
        :aria-live="(type === 'error' || type === 'warning') ? 'assertive' : 'polite'"
        aria-atomic="true"
        @mouseenter="pause()"
        @mouseleave="resume()"
        @focusin="pause()"
        @focusout="resume()"
    >
        <div class="bs-notify-bar">
            {{-- Huy hiệu ngữ nghĩa (Semantic Icon Badge) --}}
            <span class="bs-notify-badge" data-notify-badge aria-hidden="true">
                {{-- Icon Success --}}
                <svg
                    data-notify-icon="success"
                    class="{{ $activeType === 'success' ? '' : 'bs-hidden' }}"
                    :class="{ 'bs-hidden': type !== 'success' }"
                    xmlns="http://www.w3.org/2000/svg"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M20 6 9 17l-5-5"/>
                </svg>

                {{-- Icon Error --}}
                <svg
                    data-notify-icon="error"
                    class="{{ $activeType === 'error' ? '' : 'bs-hidden' }}"
                    :class="{ 'bs-hidden': type !== 'error' }"
                    xmlns="http://www.w3.org/2000/svg"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" x2="12" y1="8" y2="12"/>
                    <line x1="12" x2="12.01" y1="16" y2="16"/>
                </svg>

                {{-- Icon Warning --}}
                <svg
                    data-notify-icon="warning"
                    class="{{ $activeType === 'warning' ? '' : 'bs-hidden' }}"
                    :class="{ 'bs-hidden': type !== 'warning' }"
                    xmlns="http://www.w3.org/2000/svg"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/>
                    <path d="M12 9v4"/>
                    <path d="M12 17h.01"/>
                </svg>

                {{-- Icon Info --}}
                <svg
                    data-notify-icon="info"
                    class="{{ $activeType === 'info' ? '' : 'bs-hidden' }}"
                    :class="{ 'bs-hidden': type !== 'info' }"
                    xmlns="http://www.w3.org/2000/svg"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 16v-4"/>
                    <path d="M12 8h.01"/>
                </svg>
            </span>

            {{-- Vùng nội dung thông báo (Compact Pill -> Expanded Panel) --}}
            <div
                class="bs-notify-content"
                data-notify-toggle
                role="button"
                tabindex="0"
                aria-expanded="true"
                :aria-expanded="expanded ? 'true' : 'false'"
                aria-label="Mở rộng hoặc thu gọn nội dung thông báo"
                @click="toggleExpand()"
                @keydown.enter.prevent="toggleExpand()"
                @keydown.space.prevent="toggleExpand()"
            >
                <strong class="bs-notify-title" data-notify-title x-text="title">{{ $initialNotification['title'] ?? '' }}</strong>
                <span class="bs-notify-message" data-notify-message x-text="message">{{ $initialNotification['message'] ?? '' }}</span>
            </div>

            {{-- Nút đóng thông báo thủ công --}}
            <button
                type="button"
                class="bs-notify-close"
                data-notify-close
                @click.stop="dismiss()"
                aria-label="Đóng thông báo"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18"/>
                    <path d="m6 6 12 12"/>
                </svg>
            </button>
        </div>
    </div>
</div>

