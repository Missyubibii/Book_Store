{{--
    Luồng Giao diện Xác thực (Authentication Layout Flow - Stage 01A.3.4)
    - Entry point: Được kế thừa bởi resources/views/auth/{login,register,verify-email}.blade.php
    - Phong cách thiết kế: "Book_Store — Liquid White" (Nền trắng #FFFFFF, Bề mặt nhóm mềm #F5F5F7, Bố cục tập trung 1 cột)
    - Tài nguyên Frontend (qua Laravel Vite):
      + CSS toàn cục & token: resources/css/app.css
      + CSS xác thực chuyên biệt: resources/css/auth.css
      + CSS thông báo động: resources/css/notifications.css
      + Khởi tạo JS dùng chung: resources/js/app.js
      + Tương tác JS/Alpine xác thực & thông báo: resources/js/auth.js, resources/js/notifications.js
--}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tài khoản Độc giả') — Book_Store</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#main-content" class="bs-skip-link">Chuyển đến nội dung chính</a>

    <div class="bs-shell">
        <header class="bs-topbar">
            <nav aria-label="Điều hướng phụ">
                <a href="{{ route('home') }}" class="bs-return-link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m15 18-6-6 6-6"/>
                    </svg>
                    <span>Về trang chủ</span>
                </a>
            </nav>
        </header>

        <x-notification-center :error-title="trim($__env->yieldContent('error_title', 'Vui lòng kiểm tra lại thông tin:'))" />

        <main id="main-content" class="bs-main">
            <section class="bs-auth-container" aria-labelledby="auth-sheet-title">
                <a href="{{ route('home') }}" class="bs-auth-brand" aria-label="Trang chủ Book_Store">
                    <span class="bs-brand-mark" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                        </svg>
                    </span>
                    <span class="bs-brand-name">Book_Store</span>
                </a>

                @yield('content')
            </section>
        </main>

        <footer class="bs-footer">
            <span>&copy; {{ date('Y') }} Book_Store</span>
            <span>Hệ thống Hiệu sách &amp; Thư viện Số</span>
        </footer>
    </div>
</body>
</html>
