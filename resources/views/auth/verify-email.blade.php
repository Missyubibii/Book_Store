{{--
    Màn hình Xác thực Địa chỉ Email (Email Verification Notice View - Stage 01A.3.4)
    - Entry point: GET /xac-thuc-email (route 'verification.notice') -> EmailVerificationController@notice
    - Next steps:
      + Gửi lại liên kết: POST /xac-thuc-email/gui-lai (route 'verification.send') -> EmailVerificationController@send (throttle:6,1)
      + Đăng xuất: POST /dang-xuat (route 'logout') -> LogoutController@destroy
    - Client-side Flow: resources/js/auth.js (authForm)
--}}
@extends('layouts.auth')

@section('title', 'Xác thực địa chỉ email')
@section('error_title', 'Thông báo hệ thống:')

@section('content')
    <header class="bs-sheet-header">
        <h1 id="auth-sheet-title" class="bs-sheet-title">Xác thực địa chỉ email</h1>
        <p class="bs-sheet-subtitle">
            Chúng tôi đã gửi liên kết xác nhận đến
            @if (auth()->user()?->email)
                <strong>{{ auth()->user()->email }}</strong>.
            @else
                hộp thư của bạn.
            @endif
            Vui lòng mở thư và nhấn vào liên kết để kích hoạt tài khoản.
        </p>
    </header>

    <div class="bs-form">
        <div class="bs-policy-box">
            <p class="bs-policy-title">Hướng dẫn khi chưa nhận được thư hoặc liên kết hết hạn:</p>
            <ul class="bs-policy-list">
                <li>Liên kết xác thực có hiệu lực bảo mật trong vòng 60 phút kể từ lúc gửi.</li>
                <li>Nếu liên kết cũ đã hết hạn hoặc không còn hợp lệ, bạn chỉ cần nhấn nút gửi lại bên dưới để nhận liên kết mới.</li>
                <li>Hệ thống giới hạn tối đa 6 yêu cầu gửi lại mỗi phút để bảo vệ hộp thư của bạn.</li>
            </ul>
        </div>

        {{-- Biểu mẫu Gửi lại email xác nhận --}}
        <form method="POST" action="{{ route('verification.send') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
            @csrf
            <button type="submit" class="bs-btn-primary" data-loading-text="Đang gửi liên kết xác nhận..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect width="20" height="16" x="2" y="4" rx="2"/>
                    <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                </svg>
                <span data-submit-label>Gửi lại email xác nhận</span>
            </button>
        </form>

        @if ($demoVerificationEnabled ?? false)
            <div class="bs-policy-box" role="region" aria-label="Xác thực email Demo cục bộ">
                <p class="bs-policy-title">Chế độ phát triển cục bộ (Demo):</p>
                <ul class="bs-policy-list">
                    <li>Đây là tính năng phục vụ phát triển cục bộ, không gửi email thực tế ra bên ngoài.</li>
                    <li>Thao tác chỉ xác thực địa chỉ email cho tài khoản đang đăng nhập hiện tại.</li>
                    <li>Việc phê duyệt kích hoạt quyền nhân sự và phân quyền kho/chi nhánh vẫn là quy trình tách biệt.</li>
                </ul>

                <form method="POST" action="{{ route('verification.demo') }}" data-auth-form x-data="authForm" @submit="handleSubmit" style="margin-top: 0.75rem;">
                    @csrf
                    <button type="submit" class="bs-btn-secondary" data-loading-text="Đang xác thực (Demo)..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                        <span data-submit-label>Xác thực email (Demo)</span>
                    </button>
                </form>
            </div>
        @endif

        {{-- Biểu mẫu Đăng xuất tài khoản --}}
        <form method="POST" action="{{ route('logout') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
            @csrf
            <button type="submit" class="bs-btn-secondary" data-loading-text="Đang đăng xuất..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" x2="9" y1="12" y2="12"/>
                </svg>
                <span data-submit-label>Đăng xuất tài khoản</span>
            </button>
        </form>
    </div>
@endsection
