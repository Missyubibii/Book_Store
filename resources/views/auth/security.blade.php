@extends('layouts.storefront')

@section('title', 'Bảo mật & Phương thức Đăng nhập — Book_Store')

@section('content')
<main id="main-content" class="bs-sf-main">
    <div class="bs-sf-container bs-sf-auth-container">
        <div class="bs-sf-card bs-sf-security-card">
            <div class="bs-sf-card-head">
                <nav class="bs-sf-breadcrumb" aria-label="Đường dẫn điều hướng">
                    <a href="{{ route('home') }}">Trang chủ</a>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page">Bảo mật tài khoản</span>
                </nav>
                <h1 class="bs-sf-card-title">Bảo mật &amp; Phương thức Đăng nhập</h1>
                <p class="bs-sf-card-subtitle">
                    Quản lý mật khẩu và các tài khoản xác thực bên ngoài liên kết với tài khoản Book_Store của bạn.
                </p>
            </div>

            {{-- Thông tin tài khoản tổng quan --}}
            <div class="bs-sf-security-profile">
                <div class="bs-sf-security-profile-info">
                    <span class="bs-sf-security-label">Chủ tài khoản:</span>
                    <strong class="bs-sf-security-value">{{ $user->name }}</strong>
                </div>
                <div class="bs-sf-security-profile-info">
                    <span class="bs-sf-security-label">Email hệ thống:</span>
                    <span class="bs-sf-security-value">{{ $user->email }}</span>
                    @if ($user->hasVerifiedEmail())
                        <span class="bs-sf-badge-success">Đã xác thực</span>
                    @else
                        <span class="bs-sf-badge-warning">Chưa xác thực</span>
                    @endif
                </div>
                <div class="bs-sf-security-profile-info">
                    <span class="bs-sf-security-label">Loại tài khoản:</span>
                    @if ($user->staff_access_status && $user->staff_access_status->isEligibleForStaffAccess())
                        <span class="bs-sf-badge-accent">Nhân sự Book_Store ({{ $user->staff_access_status->label() }})</span>
                    @else
                        <span class="bs-sf-badge-neutral">Độc giả</span>
                    @endif
                </div>
            </div>

            <hr class="bs-sf-divider" />

            {{-- Danh sách phương thức đăng nhập --}}
            <section class="bs-sf-security-methods" aria-labelledby="methods-heading">
                <h2 id="methods-heading" class="bs-sf-section-title">Phương thức đăng nhập khả dụng</h2>

                {{-- 1. Mật khẩu truyền thống --}}
                <div class="bs-sf-method-item">
                    <div class="bs-sf-method-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>
                    <div class="bs-sf-method-content">
                        <h3 class="bs-sf-method-name">Mật khẩu tài khoản</h3>
                        <p class="bs-sf-method-desc">
                            @if ($hasPassword)
                                Đã thiết lập mật khẩu bảo vệ. Bạn có thể sử dụng email và mật khẩu này để đăng nhập vào Book_Store.
                            @else
                                Chưa thiết lập mật khẩu. Bạn hiện đang sử dụng liên kết Google để đăng nhập.
                            @endif
                        </p>
                    </div>
                    <div class="bs-sf-method-status">
                        @if ($hasPassword)
                            <span class="bs-sf-badge-success">Đang hoạt động</span>
                        @else
                            <span class="bs-sf-badge-warning">Chưa có</span>
                        @endif
                    </div>
                </div>

                {{-- 2. Đăng nhập qua Google OAuth --}}
                <div class="bs-sf-method-item">
                    <div class="bs-sf-method-icon" aria-hidden="true">
                        <svg class="bs-oauth-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                        </svg>
                    </div>
                    <div class="bs-sf-method-content">
                        <h3 class="bs-sf-method-name">Đăng nhập bằng Google</h3>
                        @if ($googleIdentity)
                            <p class="bs-sf-method-desc">
                                Đã liên kết với Google: <strong>{{ $googleIdentity->provider_email }}</strong>
                                (Mã định danh bảo mật: <code class="bs-sf-code">{{ $googleIdentity->masked_subject }}</code>)
                                <br />
                                <small class="bs-sf-muted">Thời điểm liên kết: {{ $googleIdentity->linked_at?->format('H:i d/m/Y') }}</small>
                            </p>
                        @else
                            <p class="bs-sf-method-desc">
                                Chưa liên kết tài khoản Google. Bạn có thể liên kết để đăng nhập nhanh chóng chỉ với một chạm.
                            </p>
                        @endif
                    </div>
                    <div class="bs-sf-method-action">
                        @if ($googleIdentity)
                            <div x-data="{ confirming: false }">
                                <button
                                    type="button"
                                    class="bs-sf-btn-outline-danger"
                                    x-show="!confirming"
                                    @click="confirming = true"
                                >
                                    Hủy liên kết
                                </button>

                                <div x-show="confirming" class="bs-sf-confirm-box" style="display: none;">
                                    <p class="bs-sf-confirm-text">Xác nhận hủy liên kết Google?</p>
                                    @if (!$canUnlinkGoogle)
                                        <p class="bs-sf-text-danger" style="font-size: 0.875rem;">
                                            Đây là phương thức đăng nhập duy nhất. Bạn không thể hủy cho đến khi thiết lập mật khẩu.
                                        </p>
                                        <button type="button" class="bs-sf-btn-ghost-sm" @click="confirming = false">Đóng</button>
                                    @else
                                        <form method="POST" action="{{ route('account.google.unlink') }}" class="bs-sf-inline-form">
                                            @csrf
                                            <input type="hidden" name="confirmed" value="1" />
                                            <button type="submit" class="bs-sf-btn-danger-sm">Xác nhận hủy</button>
                                            <button type="button" class="bs-sf-btn-ghost-sm" @click="confirming = false">Hủy bỏ</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @else
                            <form method="POST" action="{{ route('account.google.link') }}">
                                @csrf
                                <button type="submit" class="bs-sf-btn-outline">
                                    Liên kết Google
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </div>
</main>
@endsection
