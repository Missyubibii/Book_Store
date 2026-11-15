{{--
    Màn hình Đăng ký Tài khoản Độc giả (Reader Registration View - Stage 01A.3.4)
    - Entry point: GET /dang-ky (route 'register') -> RegisterController@create
    - Next step: POST /dang-ky (route 'register') -> RegisterController@store -> RegisterRequest -> RegisterCustomerAction
    - Client-side Flow: resources/js/auth.js (authPasswordField + authForm)
--}}
@extends('layouts.auth')

@section('title', 'Mở tài khoản độc giả')
@section('error_title', 'Vui lòng kiểm tra lại thông tin đăng ký:')

@section('content')
    <header class="bs-sheet-header">
        <h1 id="auth-sheet-title" class="bs-sheet-title">Mở tài khoản độc giả</h1>
        <p class="bs-sheet-subtitle">
            Tạo tài khoản để đặt sách in và lưu trữ eBook bản quyền tại Book_Store.
        </p>
    </header>

    <form method="POST" action="{{ route('register') }}" class="bs-form" data-auth-form x-data="authForm" @submit="handleSubmit" novalidate>
        @csrf

        {{-- Trường Họ và tên --}}
        <div class="bs-field">
            <div class="bs-label-row">
                <label for="name" class="bs-label">Họ và tên độc giả</label>
            </div>
            <div class="bs-input-wrap">
                <input
                    id="name"
                    name="name"
                    type="text"
                    value="{{ old('name') }}"
                    class="bs-input"
                    placeholder="Ví dụ: Nguyễn Minh Châu"
                    autocomplete="name"
                    required
                    autofocus
                    aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                    @if ($errors->has('name')) aria-describedby="name-error" @endif
                >
            </div>
            @error('name')
                <p id="name-error" class="bs-field-error" role="alert">
                    <span>{{ $message }}</span>
                </p>
            @enderror
        </div>

        {{-- Trường Địa chỉ Email --}}
        <div class="bs-field">
            <div class="bs-label-row">
                <label for="email" class="bs-label">Địa chỉ email</label>
                <span class="bs-label-hint">Dùng để nhận ấn bản số &amp; hóa đơn</span>
            </div>
            <div class="bs-input-wrap">
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    class="bs-input"
                    placeholder="tenban@example.com"
                    autocomplete="email"
                    required
                    aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                    @if ($errors->has('email')) aria-describedby="email-error" @endif
                >
            </div>
            @error('email')
                <p id="email-error" class="bs-field-error" role="alert">
                    <span>{{ $message }}</span>
                </p>
            @enderror
        </div>

        {{-- Trường Mật khẩu --}}
        <div class="bs-field">
            <div class="bs-label-row">
                <label for="password" class="bs-label">Mật khẩu thiết lập</label>
            </div>
            <div class="bs-input-wrap" x-data="authPasswordField('Hiện mật khẩu', 'Ẩn mật khẩu')">
                <input
                    id="password"
                    name="password"
                    type="password"
                    :type="inputType"
                    class="bs-input bs-input-has-toggle"
                    placeholder="Tối thiểu 8 ký tự bảo mật"
                    autocomplete="new-password"
                    required
                    aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                    aria-describedby="{{ $errors->has('password') ? 'password-error password-policy-hint' : 'password-policy-hint' }}"
                >
                <button
                    type="button"
                    class="bs-pw-toggle"
                    data-password-toggle="password"
                    data-label-show="Hiện mật khẩu"
                    data-label-hide="Ẩn mật khẩu"
                    @click="toggle"
                    :aria-pressed="ariaPressed"
                    :aria-label="ariaLabel"
                    aria-pressed="false"
                    aria-label="Hiện mật khẩu"
                >
                    <svg data-icon-show :class="{ 'bs-hidden': revealed }" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg data-icon-hide class="bs-hidden" :class="{ 'bs-hidden': !revealed }" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/>
                        <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/>
                        <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/>
                        <path d="m2 2 20 20"/>
                    </svg>
                </button>
            </div>
            @error('password')
                <p id="password-error" class="bs-field-error" role="alert">
                    <span>{{ $message }}</span>
                </p>
            @enderror

            <div id="password-policy-hint" class="bs-policy-box">
                <p class="bs-policy-title">Tiêu chuẩn bảo mật mật khẩu:</p>
                <ul class="bs-policy-list">
                    <li>Độ dài từ 8 ký tự trở lên.</li>
                    <li>Bao gồm cả chữ cái viết hoa (A–Z) và chữ cái viết thường (a–z).</li>
                    <li>Có ít nhất một chữ số (0–9) và một ký tự đặc biệt (như @, #, $, !).</li>
                </ul>
            </div>
        </div>

        {{-- Trường Xác nhận mật khẩu --}}
        <div class="bs-field">
            <div class="bs-label-row">
                <label for="password_confirmation" class="bs-label">Xác nhận lại mật khẩu</label>
            </div>
            <div class="bs-input-wrap" x-data="authPasswordField('Hiện mật khẩu xác nhận', 'Ẩn mật khẩu xác nhận')">
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    :type="inputType"
                    class="bs-input bs-input-has-toggle"
                    placeholder="Nhập lại mật khẩu đã tạo"
                    autocomplete="new-password"
                    required
                    aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}"
                >
                <button
                    type="button"
                    class="bs-pw-toggle"
                    data-password-toggle="password_confirmation"
                    data-label-show="Hiện mật khẩu xác nhận"
                    data-label-hide="Ẩn mật khẩu xác nhận"
                    @click="toggle"
                    :aria-pressed="ariaPressed"
                    :aria-label="ariaLabel"
                    aria-pressed="false"
                    aria-label="Hiện mật khẩu xác nhận"
                >
                    <svg data-icon-show :class="{ 'bs-hidden': revealed }" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg data-icon-hide class="bs-hidden" :class="{ 'bs-hidden': !revealed }" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/>
                        <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/>
                        <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/>
                        <path d="m2 2 20 20"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Nút Gửi biểu mẫu Đăng ký --}}
        <div>
            <button type="submit" class="bs-btn-primary" data-loading-text="Đang khởi tạo tài khoản..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                <span data-submit-label>Tạo tài khoản độc giả</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 12h14"/>
                    <path d="m12 5 7 7-7 7"/>
                </svg>
            </button>
        </div>
    </form>

    @if (config('services.google.enabled'))
        <div class="bs-oauth-divider" role="separator" aria-label="Hoặc đăng ký qua phương thức khác">
            <span class="bs-oauth-divider-line"></span>
            <span class="bs-oauth-divider-text">hoặc tiếp tục với</span>
            <span class="bs-oauth-divider-line"></span>
        </div>

        <div class="bs-oauth-actions">
            <a href="{{ route('auth.google.redirect') }}" class="bs-btn-oauth" aria-label="Đăng ký tài khoản nhanh bằng Google">
                <svg class="bs-oauth-icon" viewBox="0 0 24 24" width="20" height="20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/>
                </svg>
                <span>Tiếp tục với Google</span>
            </a>
        </div>
    @endif

    <footer class="bs-sheet-footer">
        <p class="bs-switch-prompt">
            Đã có tài khoản tại Book_Store?
            <a href="{{ route('login') }}" class="bs-link">Đăng nhập ngay</a>
        </p>
    </footer>
@endsection
