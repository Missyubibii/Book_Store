{{--
    Layout dùng chung cho Khu vực Quản trị Hệ thống Book_Store — "Book_Store — Liquid White"
    - Cấu trúc: Left Sidebar cố định/thu gọn (1 nút điều khiển duy nhất trên Top Header), Top Header và Main Canvas.
    - Đáp ứng liên tục 320px -> 1920px+ và phóng đại 200%, không tràn ngang, trợ năng ARIA đầy đủ.
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

    $pendingApprovalCount = 0;
    if ($canApproveStaff && \Illuminate\Support\Facades\Schema::hasTable('staff_activation_proposals')) {
        $pendingApprovalCount = \App\Models\StaffActivationProposal::query()
            ->where('status', \App\Enums\StaffApprovalStatus::Pending->value)
            ->count();
    }

    $roleBadgeText = match (true) {
        $canAccessDashboard => 'Quản trị viên cấp cao',
        $canAccessCentralWarehouse && ! $canViewBranches => 'Quản lý Kho trung tâm',
        $canViewBranches => 'Quản trị viên chi nhánh',
        default => 'Nhân sự Book_Store',
    };

    $userInitial = $currentUser !== null ? mb_strtoupper(mb_substr($currentUser->name ?? 'A', 0, 1)) : 'A';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Quản trị Hệ thống — Book_Store')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script data-bs-sidebar-init>
        (function () {
            try {
                if (window.localStorage.getItem('bs_admin_sidebar_collapsed') === '1') {
                    document.documentElement.setAttribute('data-sidebar-collapsed', 'true');
                    document.documentElement.classList.add('is-sidebar-collapsed');
                }
            } catch (e) {}
        })();
    </script>
</head>
<body class="bs-ad-body">
    <a href="#main-content" class="bs-skip-link">Chuyển đến nội dung chính</a>

    <div
        class="bs-ad-app"
        data-admin-shell
    >
        {{-- Lớp phủ mờ nền khi mở Mobile Sidebar Drawer --}}
        <div
            class="bs-ad-mobile-backdrop lg:hidden"
            data-mobile-sidebar-backdrop
            aria-hidden="true"
        ></div>

        {{-- ==================== 1. LEFT SIDEBAR ==================== --}}
        <aside
            id="admin-primary-sidebar"
            class="bs-ad-sidebar"
            data-admin-sidebar
            aria-label="Thanh điều hướng quản trị chính"
        >
            {{-- Logo Header --}}
            <a href="{{ route('admin.dashboard') }}" class="bs-ad-sidebar-brand" title="Book_Store — Quản trị">
                <span class="bs-ad-brand-icon-lines" aria-hidden="true">
                    {{-- Biểu tượng 4 gáy sách nghiêng đứng chuẩn thiết kế --}}
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                        <line x1="4" y1="20" x2="4" y2="4"/>
                        <line x1="9" y1="20" x2="9" y2="6"/>
                        <line x1="14" y1="20" x2="14" y2="4"/>
                        <line x1="19" y1="20" x2="19" y2="7"/>
                    </svg>
                </span>
                <span class="bs-ad-brand-title">Book_Store</span>
            </a>

            {{-- Danh mục menu cuộn --}}
            <nav class="bs-ad-sidebar-nav">
                {{-- NHÓM: BẢNG ĐIỀU KHIỂN --}}
                <div class="bs-ad-sidebar-section-title">
                    BẢNG ĐIỀU KHIỂN
                </div>

                @if ($canAccessDashboard)
                    <a
                        href="{{ route('admin.dashboard') }}"
                        class="bs-ad-sidebar-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}"
                        @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif
                        title="Tổng quan hệ thống"
                        aria-label="Tổng quan hệ thống"
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="1"/>
                            <rect x="14" y="3" width="7" height="7" rx="1"/>
                            <rect x="14" y="14" width="7" height="7" rx="1"/>
                            <rect x="3" y="14" width="7" height="7" rx="1"/>
                        </svg>
                        <span class="bs-ad-nav-label">Tổng quan</span>
                    </a>
                @endif

                {{-- NHÓM: HỆ THỐNG --}}
                @if ($canViewBranches || $canAccessCentralWarehouse)
                    <div class="bs-ad-sidebar-section-title">
                        HỆ THỐNG
                    </div>

                    @if ($canViewBranches)
                        <a
                            href="{{ route('admin.branches.index') }}"
                            class="bs-ad-sidebar-link {{ request()->routeIs('admin.branches.*') ? 'is-active' : '' }}"
                            @if (request()->routeIs('admin.branches.*')) aria-current="page" @endif
                            title="Quản lý chi nhánh"
                            aria-label="Quản lý chi nhánh"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 21h18"/>
                                <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                                <path d="M9 9h1"/>
                                <path d="M9 13h1"/>
                                <path d="M9 17h1"/>
                                <path d="M14 9h1"/>
                                <path d="M14 13h1"/>
                                <path d="M14 17h1"/>
                            </svg>
                            <span class="bs-ad-nav-label">Quản lý chi nhánh</span>
                        </a>
                    @endif

                    @if ($canAccessCentralWarehouse)
                        <a
                            href="{{ route('admin.central-warehouse.show') }}"
                            class="bs-ad-sidebar-link {{ request()->routeIs('admin.central-warehouse.*') ? 'is-active' : '' }}"
                            @if (request()->routeIs('admin.central-warehouse.*')) aria-current="page" @endif
                            title="Kho trung tâm"
                            aria-label="Kho trung tâm"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                                <line x1="12" y1="22.08" x2="12" y2="12"/>
                            </svg>
                            <span class="bs-ad-nav-label">Kho trung tâm</span>
                        </a>
                    @endif
                @endif

                {{-- NHÓM: NHÂN SỰ & NGƯỜI DÙNG --}}
                @if ($canViewStaff || $canApproveStaff || $canAccessDashboard)
                    <div class="bs-ad-sidebar-section-title">
                        NHÂN SỰ &amp; NGƯỜI DÙNG
                    </div>

                    @if ($canAccessDashboard)
                        @php
                            $isAccountSectionActive = request()->routeIs(
                                'admin.accounts.*',
                                'admin.staff.index',
                                'admin.staff.create',
                                'admin.staff.link.*',
                                'admin.staff.show'
                            );
                        @endphp
                        <a
                            href="{{ route('admin.accounts.index') }}"
                            class="bs-ad-sidebar-link {{ $isAccountSectionActive ? 'is-active' : '' }}"
                            @if ($isAccountSectionActive) aria-current="page" @endif
                            title="Quản lý tài khoản"
                            aria-label="Quản lý tài khoản"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2"/>
                                <circle cx="9" cy="10" r="2"/>
                                <line x1="15" y1="8" x2="17" y2="8"/>
                                <line x1="15" y1="12" x2="17" y2="12"/>
                                <line x1="7" y1="16" x2="17" y2="16"/>
                            </svg>
                            <span class="bs-ad-nav-label">Quản lý tài khoản</span>
                        </a>
                    @elseif ($canViewStaff)
                        @php
                            $isBranchStaffActive = request()->routeIs(
                                'admin.staff.index',
                                'admin.staff.create',
                                'admin.staff.link.*',
                                'admin.staff.show'
                            );
                        @endphp
                        <a
                            href="{{ route('admin.staff.index') }}"
                            class="bs-ad-sidebar-link {{ $isBranchStaffActive ? 'is-active' : '' }}"
                            @if ($isBranchStaffActive) aria-current="page" @endif
                            title="Quản lý tài khoản"
                            aria-label="Quản lý tài khoản"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            <span class="bs-ad-nav-label">Quản lý tài khoản</span>
                        </a>
                    @endif

                    @if ($canApproveStaff)
                        <a
                            href="{{ route('admin.staff.approvals.index') }}"
                            class="bs-ad-sidebar-link {{ request()->routeIs('admin.staff.approvals.*') ? 'is-active' : '' }}"
                            @if (request()->routeIs('admin.staff.approvals.*')) aria-current="page" @endif
                            @if ($pendingApprovalCount > 0) data-has-badge="true" @endif
                            title="Phê duyệt nhân sự{{ $pendingApprovalCount > 0 ? ' ('.$pendingApprovalCount.' chờ duyệt)' : '' }}"
                            aria-label="Phê duyệt nhân sự{{ $pendingApprovalCount > 0 ? ' ('.$pendingApprovalCount.' chờ duyệt)' : '' }}"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <polyline points="16 11 18 13 22 9"/>
                            </svg>
                            <span class="bs-ad-nav-label">Phê duyệt nhân sự</span>
                            @if ($pendingApprovalCount > 0)
                                <span class="bs-ad-sidebar-badge">
                                    {{ $pendingApprovalCount }}
                                </span>
                            @endif
                        </a>
                    @endif
                @endif

                {{-- NHÓM: QUẢN TRỊ --}}
                @if ($canAccessDashboard)
                    <div class="bs-ad-sidebar-section-title">
                        QUẢN TRỊ
                    </div>

                    <a
                        href="{{ route('admin.audit-logs.index') }}"
                        class="bs-ad-sidebar-link {{ request()->routeIs('admin.audit-logs.*') ? 'is-active' : '' }}"
                        @if (request()->routeIs('admin.audit-logs.*')) aria-current="page" @endif
                        title="Nhật ký hoạt động"
                        aria-label="Nhật ký hoạt động"
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        <span class="bs-ad-nav-label">Nhật ký hoạt động</span>
                    </a>
                @endif
            </nav>
        </aside>

        {{-- ==================== 2. MAIN CONTENT WRAPPER ==================== --}}
        <div class="bs-ad-main-wrap">
            {{-- Top Header Bar --}}
            <header class="bs-ad-topbar">
                <div class="bs-ad-topbar-left">
                    {{-- Nút duy nhất điều khiển Sidebar (Desktop: thu gọn/mở rộng; Mobile: mở/đóng drawer) --}}
                    <button
                        type="button"
                        class="bs-ad-topbar-btn"
                        data-sidebar-toggle
                        aria-controls="admin-primary-sidebar"
                        aria-expanded="true"
                        title="Thu gọn thanh điều hướng"
                        aria-label="Thu gọn hoặc mở rộng thanh điều hướng"
                    >
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="18" x2="21" y2="18"/>
                        </svg>
                    </button>

                    <h1 class="bs-ad-topbar-title">@yield('header_title', 'Tổng quan hệ thống')</h1>
                </div>

                <div class="bs-ad-topbar-right">
                    {{-- Thanh tìm kiếm (tạm khóa do chưa có module tìm kiếm toàn cục) --}}
                    <div class="bs-ad-search-box-top bs-ad-header-control-disabled" role="search" title="Tính năng tìm kiếm toàn cục đang được phát triển">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        <input
                            type="search"
                            placeholder="Tìm kiếm toàn cục (Sắp ra mắt)"
                            aria-label="Tìm kiếm toàn cục (Tính năng đang được phát triển)"
                            title="Tính năng tìm kiếm toàn cục đang được phát triển"
                            disabled
                        />
                    </div>

                    {{-- Nút thông báo hệ thống (tạm khóa do chưa có module thông báo thời gian thực) --}}
                    <button
                        type="button"
                        class="bs-ad-topbar-btn bs-ad-header-control-disabled"
                        title="Trung tâm thông báo sự kiện đang được phát triển"
                        aria-label="Trung tâm thông báo sự kiện (Tính năng đang được phát triển)"
                        disabled
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                    </button>

                    {{-- Thông tin Quản trị viên & Menu Dropdown --}}
                    <div class="relative">
                        <button
                            type="button"
                            class="bs-ad-user-menu-btn"
                            data-user-menu-toggle
                            aria-expanded="false"
                            aria-haspopup="true"
                        >
                            <span class="bs-ad-user-avatar" aria-hidden="true">
                                {{ $userInitial }}
                            </span>
                            <div class="bs-ad-user-info">
                                <span class="bs-ad-user-name-text">{{ $currentUser?->name ?? 'Quản trị viên' }}</span>
                                <span class="bs-ad-user-role-text">{{ $roleBadgeText }}</span>
                            </div>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-neutral-400" aria-hidden="true">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>

                        {{-- Dropdown popover menu --}}
                        <div
                            class="bs-ad-user-dropdown"
                            data-user-menu-dropdown
                            hidden
                            role="menu"
                        >
                            <div class="bs-ad-dropdown-user-header">
                                <div class="font-semibold text-xs text-neutral-800">{{ $currentUser?->name }}</div>
                                <div class="bs-ad-dropdown-email">{{ $currentUser?->email }}</div>
                            </div>

                            <a href="{{ route('home') }}" class="bs-ad-dropdown-item" role="menuitem">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                    <polyline points="9 22 9 12 15 12 15 22"/>
                                </svg>
                                <span>Về hiệu sách công khai</span>
                            </a>

                            <a href="{{ route('account.security') }}" class="bs-ad-dropdown-item" role="menuitem">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                                <span>Bảo mật tài khoản</span>
                            </a>

                            <div class="my-1 border-t border-neutral-100" role="separator"></div>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="bs-ad-dropdown-item is-danger" role="menuitem">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                        <polyline points="16 17 21 12 16 7"/>
                                        <line x1="21" y1="12" x2="9" y2="12"/>
                                    </svg>
                                    <span>Đăng xuất</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Thông báo toàn cục unified toast --}}
            <x-notification-center error-title="Thông báo quản trị:" />

            {{-- Nội dung trang quản trị chính --}}
            <main id="main-content" class="flex-1 w-full">
                @yield('content')
            </main>

            {{-- Footer tối giản --}}
            <footer class="py-4 px-6 border-t border-[#E5E5EA] bg-white text-xs text-[#8E8E93] flex flex-wrap justify-between items-center gap-2">
                <span>&copy; {{ date('Y') }} Book_Store — Hệ thống Quản trị Doanh nghiệp</span>
                <span>Múi giờ máy chủ: Asia/Ho_Chi_Minh</span>
            </footer>
        </div>
    </div>
</body>
</html>
