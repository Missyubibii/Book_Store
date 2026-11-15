{{--
    Màn hình Tổng quan Khu vực Quản trị Hệ thống (Stage 01B.3.3 -> Stage 01B.4)
    - Entry point: GET /quan-tri (`admin.dashboard`, middleware `auth`, `account.active`, `staff.active`, `super_admin.effective`)
    - Hiển thị danh tính quản trị, thống kê chi nhánh / kho trung tâm và liên kết trực tiếp tới các phân hệ đã mở khóa.
--}}
@extends('layouts.admin')

@section('title', 'Không gian Quản trị Hệ thống — Book_Store')

@section('content')
    <section class="bs-ad-hero" aria-labelledby="admin-dashboard-title">
        <p class="bs-ad-eyebrow">Xác thực &amp; Phân quyền Toàn hệ thống Đang hiệu lực</p>
        <h1 id="admin-dashboard-title" class="bs-ad-title">
            Xin chào, {{ $adminUser->name }}
        </h1>
        <p class="bs-ad-subtitle">
            Đây là điểm vào khu vực quản trị Book_Store đã được xác thực đầy đủ theo chuẩn RBAC toàn hệ thống. Phân hệ Quản lý Chi nhánh bán lẻ và Kho trung tâm đã mở hoạt động; các phân hệ tiếp theo sẽ được kích hoạt theo lộ trình phê duyệt.
        </p>
    </section>

    <section class="bs-ad-identity-grid" aria-label="Thông tin tài khoản quản trị hiện tại">
        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Tài khoản đăng nhập</p>
            <p class="bs-ad-stat-value">{{ $adminUser->email }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Vai trò hiệu lực</p>
            <p class="bs-ad-stat-value">Quản trị viên cấp cao (Global)</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Số quyền toàn hệ thống đang hiệu lực</p>
            <p class="bs-ad-stat-value">{{ $effectiveGlobalPermissionCount }} quyền chuẩn hóa</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Xác nhận đổi mật khẩu onboarding</p>
            <p class="bs-ad-stat-value">{{ $passwordChangedAtDisplay }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Chi nhánh bán lẻ đang hoạt động</p>
            <p class="bs-ad-stat-value">{{ $branchSummary['active'] }} / {{ $branchSummary['total'] }} chi nhánh</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Kho trung tâm hệ thống</p>
            <p class="bs-ad-stat-value">
                {{ $centralWarehouse !== null ? $centralWarehouse->code . ' — ' . $centralWarehouse->status->label() : 'Chưa khởi tạo' }}
            </p>
        </article>
    </section>

    <section class="bs-ad-section" aria-labelledby="planned-modules-title">
        <h2 id="planned-modules-title" class="bs-ad-section-title">Các phân hệ quản trị hệ thống</h2>
        <p class="bs-ad-section-note">
            Quản lý chi nhánh bán lẻ, Kho trung tâm, Nhân sự và Nhật ký kiểm toán đã sẵn sàng; mô-đun Danh mục sách &amp; Tồn kho FIFO sẽ mở khóa ở giai đoạn tiếp theo.
        </p>

        <div class="bs-ad-modules-grid">
            @foreach ($plannedModules as $module)
                <article class="bs-ad-module-card">
                    <span @class([
                        'bs-ad-module-status',
                        'bs-ad-module-status-active' => ! empty($module['isActive']),
                    ])>
                        {{ $module['status'] }}
                    </span>
                    <h3 class="bs-ad-module-title">{{ $module['title'] }}</h3>
                    <p class="bs-ad-module-desc">{{ $module['description'] }}</p>

                    @if (! empty($module['isActive']))
                        <div class="bs-ad-module-actions">
                            @if (! empty($module['primaryRoute']))
                                <a href="{{ $module['primaryRoute'] }}" class="bs-ad-btn-primary">
                                    {{ $module['primaryLabel'] }}
                                </a>
                            @endif
                            @if (! empty($module['secondaryRoute']))
                                <a href="{{ $module['secondaryRoute'] }}" class="bs-ad-btn-outline">
                                    {{ $module['secondaryLabel'] }}
                                </a>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endsection
