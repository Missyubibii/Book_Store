{{--
    Màn hình Lỗi 429 — Quá nhiều yêu cầu (Stage 01A.3.6)
    - Entry point: HTTP 429 (ThrottleRequestsException trên các tuyến xác thực có giới hạn tần suất)
    - Phong cách: "Book_Store — Liquid White" kế thừa layouts/auth.blade.php
--}}
@extends('layouts.auth')

@section('title', 'Quá nhiều yêu cầu (429)')
@section('error_title', 'Tạm giới hạn tần suất yêu cầu:')

@section('content')
    <header class="bs-sheet-header">
        <h1 id="auth-sheet-title" class="bs-sheet-title">Quá nhiều yêu cầu</h1>
        <p class="bs-sheet-subtitle">
            Bạn đã gửi quá nhiều yêu cầu liên tiếp trong thời gian ngắn. Vui lòng chờ ít phút trước khi thử lại để bảo vệ an toàn cho tài khoản.
        </p>
    </header>

    <div class="bs-form">
        <div class="bs-policy-box">
            <p class="bs-policy-title">Lưu ý bảo mật (Mã trạng thái 429):</p>
            <ul class="bs-policy-list">
                <li>Hệ thống giới hạn tối đa 6 yêu cầu xác thực hoặc gửi lại email trong mỗi phút.</li>
                <li>Vui lòng kiểm tra hộp thư đến (bao gồm cả mục Quảng cáo hoặc Thư rác) trước khi yêu cầu gửi lại thư mới.</li>
            </ul>
        </div>

        @auth
            <a href="{{ route('verification.notice') }}" class="bs-btn-primary">
                <span>Quay lại trang xác thực email</span>
            </a>
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
