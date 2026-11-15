{{--
    Layout dùng chung cho Khu vực Quản trị Hệ thống Book_Store — "Book_Store — Liquid White"
    - Bao gồm thanh điều hướng nhất quán: Tổng quan, Chi nhánh, Kho trung tâm và các mô-đun đang phát triển.
    - Nạp tài sản qua Vite (`resources/css/app.css` gồm `admin.css` và `resources/js/app.js` gồm `admin.js`).
    - Đáp ứng liên tục 320px -> 1920px+ và phóng đại 200%, không chứa CSS/JS nội tuyến hay href="#".
--}}
@php
    $currentUser = auth()->user();
    $authService = app(\App\Services\Authorization\AuthorizationService::class);
    $staffService = app(\App\Services\Staff\StaffAdministrationService::class);
    $canAccessDashboard = $currentUser !== null && $authService->isEffectiveSuperAdmin($currentUser);
    $canViewBranches = $currentUser !== null && $currentUser->can('viewAny', \App\Models\Branch::class);
    $canAccessCentralWarehouse = $currentUser !== null && $authService->canAccessCentralWarehouse($currentUser);
    $canViewStaff = $currentUser !== null && $staffService->canViewStaffDirectory($currentUser);
    $canApproveStaff = $currentUser !== null && $authService->hasGlobalPermission($currentUser, 'staff.approve_activation');
    $brandUrl = match (true) {
        $canAccessDashboard => route('admin.dashboard'),
        $canViewBranches => route('admin.branches.index'),
        $canAccessCentralWarehouse => route('admin.central-warehouse.show'),
        $canViewStaff => route('admin.staff.index'),
        default => route('home'),
    };
    $roleBadgeText = match (true) {
        $canAccessDashboard => 'Quản trị cấp cao',
        $canAccessCentralWarehouse && ! $canViewBranches => 'Quản lý Kho trung tâm',
        default => 'Quản trị hệ thống',
    };
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Quản trị Hệ thống — Book_Store')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bs-ad-body">
    <a href="#main-content" class="bs-skip-link">Chuyển đến nội dung chính</a>

    <div class="bs-ad-shell">
        <header class="bs-ad-header">
            <div class="bs-ad-header-inner">
                <a href="{{ $brandUrl }}" class="bs-ad-brand" aria-label="Không gian Quản trị Book_Store">
                    <span class="bs-sf-brand-mark" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                        </svg>
                    </span>
                    <span>Book_Store</span>
                    <span class="bs-ad-badge">{{ $roleBadgeText }}</span>
                </a>

                <div class="bs-ad-header-actions">
                    <a href="{{ route('home') }}" class="bs-ad-btn-outline">Về hiệu sách công khai</a>

                    <form method="POST" action="{{ route('logout') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
                        @csrf
                        <button type="submit" class="bs-ad-btn-ghost" data-loading-text="Đang đăng xuất..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                            <span data-submit-label>Đăng xuất</span>
                        </button>
                    </form>
                </div>
            </div>

            <nav class="bs-ad-nav-bar" aria-label="Điều hướng phân hệ quản trị">
                <div class="bs-ad-container">
                    <div class="bs-ad-nav-list">
                        @if ($canAccessDashboard)
                            <a
                                href="{{ route('admin.dashboard') }}"
                                class="bs-ad-nav-link"
                                @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif
                            >
                                Tổng quan
                            </a>
                        @endif

                        @if ($canViewBranches)
                            <a
                                href="{{ route('admin.branches.index') }}"
                                class="bs-ad-nav-link"
                                @if (request()->routeIs('admin.branches.*')) aria-current="page" @endif
                            >
                                Chi nhánh
                            </a>
                        @endif

                        @if ($canAccessCentralWarehouse)
                            <a
                                href="{{ route('admin.central-warehouse.show') }}"
                                class="bs-ad-nav-link"
                                @if (request()->routeIs('admin.central-warehouse.*')) aria-current="page" @endif
                            >
                                Kho trung tâm
                            </a>
                        @endif

                        @if ($canViewStaff)
                            <a
                                href="{{ route('admin.staff.index') }}"
                                class="bs-ad-nav-link"
                                @if (request()->routeIs('admin.staff.index', 'admin.staff.create', 'admin.staff.link.*', 'admin.staff.show')) aria-current="page" @endif
                            >
                                Nhân sự
                            </a>
                        @endif

                        @if ($canApproveStaff)
                            <a
                                href="{{ route('admin.staff.approvals.index') }}"
                                class="bs-ad-nav-link"
                                @if (request()->routeIs('admin.staff.approvals.*')) aria-current="page" @endif
                            >
                                Phê duyệt nhân sự
                            </a>
                        @endif

                        @if ($canAccessDashboard)
                            <a
                                href="{{ route('admin.audit-logs.index') }}"
                                class="bs-ad-nav-link"
                                @if (request()->routeIs('admin.audit-logs.*')) aria-current="page" @endif
                            >
                                Nhật ký hoạt động
                            </a>
                        @endif

                        <span class="bs-ad-nav-disabled" aria-disabled="true">
                            <span>Tồn kho FIFO</span>
                            <span class="bs-ad-nav-soon">Đang phát triển</span>
                        </span>
                    </div>
                </div>
            </nav>
        </header>

        <x-notification-center error-title="Thông báo quản trị:" />

        <main id="main-content" class="bs-ad-main">
            <div class="bs-ad-container">
                @yield('content')
            </div>
        </main>

        <footer class="bs-ad-footer">
            <div class="bs-ad-container bs-ad-footer-inner">
                <span>&copy; {{ date('Y') }} Book_Store — Quản trị Hệ thống</span>
                <span>Múi giờ hiển thị: Asia/Ho_Chi_Minh</span>
            </div>
        </footer>
    </div>
</body>
</html>
