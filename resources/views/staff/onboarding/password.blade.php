{{--
    Màn hình Đổi Mật Khẩu Bắt Buộc Onboarding Nhân sự (Stage 01B.3.3)
    - Entry point: GET /nhan-su/doi-mat-khau-bat-buoc (`staff.onboarding.password.edit`, middleware `staff.onboarding`)
    - Next step: POST /nhan-su/doi-mat-khau-bat-buoc (`staff.onboarding.password.update`) -> ForceChangePasswordRequest -> CompleteMandatoryPasswordChangeAction
    - Client-side Flow: resources/js/auth.js (`authPasswordField` + `authForm`)
--}}
@extends('layouts.auth')

@section('title', 'Đổi mật khẩu bắt buộc')
@section('error_title', 'Không thể cập nhật mật khẩu mới:')

@section('content')
    <header class="bs-sheet-header">
        <h1 id="auth-sheet-title" class="bs-sheet-title">Đổi mật khẩu bắt buộc</h1>
        <p class="bs-sheet-subtitle">
            Tài khoản <strong>{{ auth()->user()->email }}</strong> đang sử dụng mật khẩu khởi tạo tạm thời. Vui lòng thiết lập mật khẩu mới trước khi sử dụng quyền truy cập nội bộ.
        </p>
    </header>

    <form method="POST" action="{{ route('staff.onboarding.password.update') }}" class="bs-form" data-auth-form x-data="authForm" @submit="handleSubmit" novalidate>
        @csrf

        {{-- Mật khẩu hiện tại (tạm thời) --}}
        <div class="bs-field">
            <div class="bs-label-row">
                <label for="current_password" class="bs-label">Mật khẩu hiện tại</label>
            </div>
            <div class="bs-input-wrap" x-data="authPasswordField('Hiện mật khẩu hiện tại', 'Ẩn mật khẩu hiện tại')">
                <input
                    id="current_password"
                    name="current_password"
                    type="password"
                    :type="inputType"
                    class="bs-input bs-input-has-toggle"
                    placeholder="Nhập mật khẩu khởi tạo hiện tại"
                    autocomplete="current-password"
                    required
                    autofocus
                    aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}"
                    @if ($errors->has('current_password')) aria-describedby="current-password-error" @endif
                >
                <button
                    type="button"
                    class="bs-pw-toggle"
                    data-password-toggle="current_password"
                    data-label-show="Hiện mật khẩu hiện tại"
                    data-label-hide="Ẩn mật khẩu hiện tại"
                    @click="toggle"
                    :aria-pressed="ariaPressed"
                    :aria-label="ariaLabel"
                    aria-pressed="false"
                    aria-label="Hiện mật khẩu hiện tại"
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
            @error('current_password')
                <p id="current-password-error" class="bs-field-error" role="alert">
                    <span>{{ $message }}</span>
                </p>
            @enderror
        </div>

        {{-- Mật khẩu mới --}}
        <div class="bs-field">
            <div class="bs-label-row">
                <label for="password" class="bs-label">Mật khẩu mới</label>
            </div>
            <div class="bs-input-wrap" x-data="authPasswordField('Hiện mật khẩu mới', 'Ẩn mật khẩu mới')">
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
                    aria-describedby="{{ $errors->has('password') ? 'password-error onboarding-policy-hint' : 'onboarding-policy-hint' }}"
                >
                <button
                    type="button"
                    class="bs-pw-toggle"
                    data-password-toggle="password"
                    data-label-show="Hiện mật khẩu mới"
                    data-label-hide="Ẩn mật khẩu mới"
                    @click="toggle"
                    :aria-pressed="ariaPressed"
                    :aria-label="ariaLabel"
                    aria-pressed="false"
                    aria-label="Hiện mật khẩu mới"
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
        </div>

        {{-- Xác nhận mật khẩu mới --}}
        <div class="bs-field">
            <div class="bs-label-row">
                <label for="password_confirmation" class="bs-label">Xác nhận mật khẩu mới</label>
            </div>
            <div class="bs-input-wrap" x-data="authPasswordField('Hiện xác nhận mật khẩu', 'Ẩn xác nhận mật khẩu')">
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    :type="inputType"
                    class="bs-input bs-input-has-toggle"
                    placeholder="Nhập lại mật khẩu mới"
                    autocomplete="new-password"
                    required
                    aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}"
                >
                <button
                    type="button"
                    class="bs-pw-toggle"
                    data-password-toggle="password_confirmation"
                    data-label-show="Hiện xác nhận mật khẩu"
                    data-label-hide="Ẩn xác nhận mật khẩu"
                    @click="toggle"
                    :aria-pressed="ariaPressed"
                    :aria-label="ariaLabel"
                    aria-pressed="false"
                    aria-label="Hiện xác nhận mật khẩu"
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

        <div id="onboarding-policy-hint" class="bs-policy-box">
            <p class="bs-policy-title">Tiêu chuẩn mật khẩu bảo mật Book_Store:</p>
            <ul class="bs-policy-list">
                <li>Phải khác hoàn toàn mật khẩu khởi tạo hiện tại.</li>
                <li>Tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, chữ số và ký tự đặc biệt.</li>
            </ul>
        </div>

        <div>
            <button type="submit" class="bs-btn-primary" data-loading-text="Đang cập nhật mật khẩu..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                <span data-submit-label>Lưu mật khẩu mới &amp; tiếp tục</span>
            </button>
        </div>
    </form>

    <div class="bs-sheet-footer">
        <form method="POST" action="{{ route('logout') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
            @csrf
            <button type="submit" class="bs-btn-secondary" data-loading-text="Đang đăng xuất..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                <span data-submit-label>Đăng xuất tài khoản</span>
            </button>
        </form>

        <p class="bs-security-note">
            Bước xác nhận mật khẩu này là bắt buộc đối với tài khoản nhân sự sử dụng mật khẩu tạm thời và được ghi nhận vào nhật ký kiểm toán bảo mật.
        </p>
    </div>
@endsection
