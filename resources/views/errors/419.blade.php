{{--
    Màn hình Lỗi 419 — Phiên làm việc đã hết hạn (Stage 01A.3.6)
    - Entry point: HTTP 419 (TokenMismatchException khi gửi biểu mẫu sau khi hết hạn session/CSRF)
    - Phong cách: "Book_Store — Liquid White" kế thừa layouts/auth.blade.php
--}}
@extends('layouts.auth')

@section('title', 'Phiên làm việc đã hết hạn (419)')
@section('error_title', 'Phiên làm việc đã hết hạn:')

@section('content')
    <header class="bs-sheet-header">
        <h1 id="auth-sheet-title" class="bs-sheet-title">Phiên làm việc đã hết hạn</h1>
        <p class="bs-sheet-subtitle">
            Phiên bảo mật của biểu mẫu đã hết hạn do trang được mở quá lâu không có thao tác. Vui lòng quay lại và thực hiện lại yêu cầu của bạn.
        </p>
    </header>

    <div class="bs-form">
        <div class="bs-policy-box">
            <p class="bs-policy-title">Hướng dẫn khôi phục thao tác (Mã trạng thái 419):</p>
            <ul class="bs-policy-list">
                <li>Tải lại trang biểu mẫu trước khi nhập lại thông tin đăng nhập hoặc đăng ký.</li>
                <li>Đảm bảo trình duyệt của bạn cho phép lưu cookie phiên làm việc của Book_Store.</li>
            </ul>
        </div>

        <a href="{{ route('login') }}" class="bs-btn-primary">
            <span>Quay lại trang đăng nhập</span>
        </a>

        <a href="{{ route('home') }}" class="bs-btn-secondary">
            <span>Về trang chủ Book_Store</span>
        </a>
    </div>
@endsection
