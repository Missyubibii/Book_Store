{{--
    Màn hình Lỗi 403 — Truy cập bị từ chối / Liên kết xác thực không hợp lệ (Stage 01A.3.6)
    - Entry point: HTTP 403 (InvalidSignatureException trên route 'verification.verify' hoặc AuthorizationException)
    - Phong cách: "Book_Store — Liquid White" kế thừa layouts/auth.blade.php
--}}
@extends('layouts.auth')

@section('title', 'Truy cập bị từ chối (403)')
@section('error_title', 'Không thể thực hiện yêu cầu:')

@php
    $rawMessage = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $isEmailSignatureError = $rawMessage === '' || $rawMessage === 'Invalid signature.';
    $knownMap = [
        'Invalid signature.' => __('Invalid signature.'),
        'This action is unauthorized.' => __('This action is unauthorized.'),
        'Forbidden' => __('Forbidden'),
    ];
    $displayMessage = $knownMap[$rawMessage]
        ?? ($rawMessage !== '' && __($rawMessage) !== $rawMessage
            ? __($rawMessage)
            : ($rawMessage !== ''
                ? $rawMessage
                : 'Liên kết xác thực không hợp lệ, đã hết hạn hoặc bạn không có quyền truy cập trang này.'));
@endphp

@section('content')
    <header class="bs-sheet-header">
        <h1 id="auth-sheet-title" class="bs-sheet-title">Truy cập bị từ chối</h1>
        <p class="bs-sheet-subtitle">
            {{ $displayMessage }}
        </p>
    </header>

    <div class="bs-form">
        <div class="bs-policy-box">
            <p class="bs-policy-title">Hướng dẫn xử lý (Mã trạng thái 403):</p>
            <ul class="bs-policy-list">
                @if ($isEmailSignatureError)
                    <li>Liên kết xác nhận email chỉ có hiệu lực trong vòng 60 phút kể từ thời điểm gửi.</li>
                    <li>Nếu liên kết đã hết hạn hoặc bị cắt bớt khi sao chép, bạn có thể yêu cầu gửi lại liên kết xác nhận mới.</li>
                    <li>Đảm bảo bạn đang đăng nhập đúng tài khoản độc giả đã nhận thư xác thực.</li>
                @else
                    <li>Tài khoản hiện tại không đáp ứng đủ điều kiện trạng thái hoặc phạm vi phân quyền cho khu vực này.</li>
                    <li>Nếu đây là tài khoản nhân sự mới hoặc thay đổi chi nhánh, vui lòng liên hệ quản trị viên hệ thống để kiểm tra phân công.</li>
                @endif
            </ul>
        </div>

        @auth
            @if (
                auth()->user()->must_change_password
                && in_array(auth()->user()->staff_access_status, [\App\Enums\StaffAccessStatus::Pending, \App\Enums\StaffAccessStatus::Active], true)
            )
                <a href="{{ route('staff.onboarding.password.edit') }}" class="bs-btn-primary">
                    <span>Đổi mật khẩu tạm bắt buộc</span>
                </a>
            @elseif ($isEmailSignatureError && ! auth()->user()->hasVerifiedEmail())
                <a href="{{ route('verification.notice') }}" class="bs-btn-primary">
                    <span>Quay lại trang xác thực email</span>
                </a>
            @endif
        @else
            <a href="{{ route('login') }}" class="bs-btn-primary">
                <span>Đi tới trang đăng nhập</span>
            </a>
        @endauth

        <a href="{{ route('home') }}" class="bs-btn-secondary">
            <span>Về trang chủ Book_Store</span>
        </a>
    </div>
@endsection
