{{--
    Chi tiết Tài khoản Khách hàng
    - Entry point: GET /quan-tri/tai-khoan/khach-hang/{user} (`admin.accounts.customer.show`)
    - Hiển thị chính xác trạng thái xác thực email, Phương thức đã liên kết và Đăng nhập gần nhất.
    - Khung xác nhận khóa/mở khóa sử dụng `data-confirm-disclosure` thuần Vanilla JS.
--}}
@extends('layouts.admin')

@section('title', 'Chi tiết Khách hàng: ' . $customer->name . ' — Book_Store')
@section('header_title', 'Chi tiết tài khoản khách hàng')

@section('content')
@php
    $statusConfirmHasErrors = $errors->has('confirmed') || $errors->has('reason') || $errors->has('action');
    $isCustomerActive = $customer->account_status === \App\Enums\AccountStatus::Active;
    $hasUsablePassword = $customer->hasUsablePasswordAuthentication();
    $authMethodsLabel = $customer->authenticationMethodsLabel();
    $lastLoginLabel = $customer->lastSuccessfulLoginMethodLabel();
@endphp

<div class="bs-ad-dashboard-canvas">
    {{-- ==================== 1. PAGE HEADER ==================== --}}
    <x-admin.page-header
        :title="$customer->name"
        :subtitle="'Hồ sơ độc giả khách hàng #' . $customer->id . ' — ' . $customer->email"
    >
        <x-slot:breadcrumb>
            <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
                <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('admin.accounts.index', ['tab' => 'customer']) }}">Quản lý tài khoản</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">{{ $customer->name }}</span>
            </nav>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <x-admin.button variant="secondary" :href="route('admin.accounts.index', ['tab' => 'customer'])">
                Quay lại danh sách
            </x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="bs-ad-grid-2col">
        {{-- Cột 1: Thông tin cơ bản, Xác thực & Khóa/Mở khóa --}}
        <div class="bs-ad-dash-col-main">
            <x-admin.detail-section title="Thông tin tài khoản" heading-id="card-account-info">
                <dl class="bs-ad-info-list">
                    <x-admin.information-row label="Họ và tên độc giả" :value="$customer->name" />
                    <x-admin.information-row label="Địa chỉ email">
                        <span>{{ $customer->email }}</span>
                        <div style="margin-top: 0.35rem;">
                            @if ($customer->hasVerifiedEmail())
                                <x-admin.status-badge variant="success" :dot="true">Đã xác thực</x-admin.status-badge>
                            @else
                                <x-admin.status-badge variant="warning" :dot="true">Chưa xác thực</x-admin.status-badge>
                            @endif
                        </div>
                    </x-admin.information-row>
                    <x-admin.information-row label="Ngày đăng ký" :value="format_datetime($customer->created_at)" />
                    <x-admin.information-row label="Trạng thái tài khoản">
                        @if ($isCustomerActive)
                            <x-admin.status-badge variant="success" :dot="true">Đang hoạt động bình thường</x-admin.status-badge>
                        @else
                            <x-admin.status-badge variant="danger" :dot="true">Đang bị khóa tài khoản</x-admin.status-badge>
                        @endif
                    </x-admin.information-row>
                    <x-admin.information-row label="Phương thức đã liên kết">
                        <x-admin.status-badge :variant="$googleIdentity ? 'info' : 'neutral'">
                            {{ $authMethodsLabel }}
                        </x-admin.status-badge>
                    </x-admin.information-row>
                    <x-admin.information-row label="Đăng nhập gần nhất" :value="$lastLoginLabel" />
                </dl>
            </x-admin.detail-section>

            {{-- Khung khóa / khôi phục tài khoản khách hàng --}}
            <x-admin.confirmation-panel
                :title="$isCustomerActive ? 'Khóa tài khoản độc giả' : 'Khôi phục hoạt động tài khoản'"
                :subtitle="$isCustomerActive
                    ? 'Khách hàng sẽ không thể đăng nhập hoặc thực hiện giao dịch cho đến khi được mở khóa lại.'
                    : 'Tài khoản khách hàng sẽ được kích hoạt lại trạng thái hoạt động bình thường.'"
                heading-id="customer-status-control-heading"
                :initial-open="$statusConfirmHasErrors"
            >
                <x-slot:trigger>
                    <x-admin.button
                        :variant="$isCustomerActive ? 'danger-soft' : 'primary'"
                        data-confirm-open
                        :aria-expanded="$statusConfirmHasErrors ? 'true' : 'false'"
                        aria-controls="customer-status-confirm-panel"
                    >
                        {{ $isCustomerActive ? 'Khóa tài khoản độc giả' : 'Khôi phục hoạt động tài khoản' }}
                    </x-admin.button>
                </x-slot:trigger>

                <form
                    id="customer-status-confirm-panel"
                    method="POST"
                    action="{{ route('admin.accounts.customer.status', $customer) }}"
                    @class(['bs-ad-confirm-box', 'bs-hidden' => ! $statusConfirmHasErrors])
                    data-confirm-panel
                    aria-hidden="{{ $statusConfirmHasErrors ? 'false' : 'true' }}"
                >
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="action" value="{{ $isCustomerActive ? 'suspend' : 'reactivate' }}" />

                    <p class="bs-ad-confirm-heading">
                        {{ $isCustomerActive ? 'Xác nhận khóa tài khoản độc giả' : 'Xác nhận mở khóa tài khoản độc giả' }}
                    </p>

                    <label class="bs-ad-check-row">
                        <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox required />
                        <span>
                            {{ $isCustomerActive
                                ? 'Tôi xác nhận quyết định khóa tài khoản khách hàng này và ghi nhận vào nhật ký kiểm toán.'
                                : 'Tôi xác nhận mở khóa hoạt động tài khoản khách hàng này và ghi nhận vào nhật ký kiểm toán.' }}
                        </span>
                    </label>

                    @error('confirmed')
                        <p class="bs-ad-field-error" role="alert">{{ $message }}</p>
                    @enderror

                    <x-admin.form-field label="Lý do (tùy chọn)" for="customer-status-reason">
                        <input
                            type="text"
                            id="customer-status-reason"
                            name="reason"
                            data-confirm-reset-input
                            placeholder="Nhập lý do thay đổi trạng thái tài khoản..."
                            class="bs-ad-input"
                        />
                    </x-admin.form-field>

                    <x-admin.form-actions>
                        <x-admin.button
                            :variant="$isCustomerActive ? 'danger' : 'primary'"
                            type="submit"
                            data-confirm-submit
                            disabled
                        >
                            {{ $isCustomerActive ? 'Khóa tài khoản' : 'Mở khóa hoạt động' }}
                        </x-admin.button>
                        <x-admin.button variant="ghost" data-confirm-cancel>
                            Hủy bỏ
                        </x-admin.button>
                    </x-admin.form-actions>
                </form>
            </x-admin.confirmation-panel>

            {{-- Chi tiết Phương thức đăng nhập & Liên kết ngoài --}}
            <x-admin.detail-section title="Phương thức đăng nhập & Liên kết ngoài" heading-id="card-auth-methods">
                <div class="bs-ad-method-row">
                    <div>
                        <strong>Mật khẩu truyền thống:</strong>
                        <p class="bs-ad-card-subtitle">
                            {{ $hasUsablePassword ? 'Đã thiết lập mật khẩu đăng nhập hợp lệ' : 'Chưa thiết lập mật khẩu (chỉ đăng nhập qua Google)' }}
                        </p>
                    </div>
                    <x-admin.status-badge :variant="$hasUsablePassword ? 'success' : 'neutral'" :dot="$hasUsablePassword">
                        {{ $hasUsablePassword ? 'Khả dụng' : 'Chưa có' }}
                    </x-admin.status-badge>
                </div>

                <div class="bs-ad-method-row">
                    <div>
                        <strong>Google OAuth 2.0:</strong>
                        @if ($googleIdentity)
                            <p class="bs-ad-card-subtitle">
                                Đã liên kết: <strong>{{ $googleIdentity->provider_email }}</strong><br>
                                Subject ID ẩn: <span class="bs-ad-branch-code-tag">{{ $googleIdentity->masked_subject }}</span>
                                — Liên kết lúc: {{ format_datetime($googleIdentity->linked_at) }}
                            </p>
                        @else
                            <p class="bs-ad-card-subtitle">Chưa liên kết tài khoản Google</p>
                        @endif
                    </div>
                    <x-admin.status-badge :variant="$googleIdentity ? 'info' : 'neutral'" :dot="(bool) $googleIdentity">
                        {{ $googleIdentity ? 'Đã liên kết' : 'Chưa liên kết' }}
                    </x-admin.status-badge>
                </div>
            </x-admin.detail-section>

            {{-- Lịch sử đăng nhập gần đây --}}
            <x-admin.content-card title="Lịch sử đăng nhập gần đây" heading-id="card-recent-logins">
                @if ($recentLogins->isEmpty())
                    <x-admin.empty-state description="Chưa ghi nhận sự kiện đăng nhập nào của tài khoản này." />
                @else
                    <x-admin.bounded-scroll max-height="sm" label="Lịch sử đăng nhập gần đây của khách hàng">
                        <x-admin.data-table>
                        <x-slot:head>
                            <tr>
                                <th>THỜI ĐIỂM</th>
                                <th>SỰ KIỆN</th>
                                <th>PHƯƠNG THỨC</th>
                                <th>ĐỊA CHỈ IP</th>
                            </tr>
                        </x-slot:head>

                        @foreach ($recentLogins as $event)
                            @php
                                $eventMethod = match ($event->auth_method ?? null) {
                                    'password' => 'Mật khẩu',
                                    'google' => 'Google',
                                    default => 'Chưa rõ',
                                };
                            @endphp
                            <tr>
                                <td><span class="bs-ad-date-cell">{{ format_datetime($event->occurred_at) }}</span></td>
                                <td>
                                    <x-admin.status-badge variant="neutral">
                                        {{ $event->event_type?->label() ?? $event->event_type }}
                                    </x-admin.status-badge>
                                </td>
                                <td>{{ $eventMethod }}</td>
                                <td><span class="bs-ad-branch-code-tag">{{ $event->ip_address ?? '—' }}</span></td>
                            </tr>
                        @endforeach
                        </x-admin.data-table>
                    </x-admin.bounded-scroll>
                @endif
            </x-admin.content-card>
        </div>

        {{-- Cột 2: Phân hệ mở rộng với Honest Empty States --}}
        <div class="bs-ad-dash-col-side">
            <x-admin.detail-section title="Lịch sử đơn hàng sách" heading-id="card-orders">
                <x-admin.empty-state
                    title="Phân hệ Đơn hàng"
                    description="Phân hệ Đơn hàng đang được phát triển theo lộ trình dự án. Khi hoàn thiện, lịch sử mua sách in và eBook của độc giả sẽ tự động hiển thị tại đây."
                />
            </x-admin.detail-section>

            <x-admin.detail-section title="Giao dịch & Thanh toán" heading-id="card-payments">
                <x-admin.empty-state
                    title="Phân hệ Thanh toán"
                    description="Phân hệ Giao dịch & Thanh toán đang được phát triển theo lộ trình dự án."
                />
            </x-admin.detail-section>

            <x-admin.detail-section title="Bản quyền Sách điện tử (eBook)" heading-id="card-ebooks">
                <x-admin.empty-state
                    title="Phân hệ Bản quyền eBook"
                    description="Phân hệ Bản quyền Sách điện tử (eBook) đang được phát triển theo lộ trình dự án."
                />
            </x-admin.detail-section>
        </div>
    </div>
</div>
@endsection
