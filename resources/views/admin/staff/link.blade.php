{{--
    Biểu mẫu Liên kết Tài khoản Khách hàng Hiện hữu sang Nhân sự (Stage 01B.5.1)
    - Entry point: GET /quan-tri/nhan-su/lien-ket -> StaffController::createLink
    - Submit: POST /quan-tri/nhan-su/lien-ket (`admin.staff.link.store`) -> StaffController::storeLink -> StaffAdministrationService::linkExistingCustomerToStaff
    - Bảo toàn mật khẩu hiện tại, phiên đăng nhập khách hàng và `account_status` của tài khoản gốc.
--}}
@extends('layouts.admin')

@section('title', 'Liên kết Khách hàng sang Nhân sự — Book_Store')

@section('content')
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <a href="{{ route('admin.staff.index') }}">Nhân sự</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Liên kết khách hàng</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Chuyển đổi &amp; Gắn hồ sơ nội bộ</p>
            <h1 class="bs-ad-title">Liên kết Khách hàng hiện hữu</h1>
            <p class="bs-ad-subtitle">
                Gắn hồ sơ nhân sự (ở trạng thái Chờ kích hoạt) vào một tài khoản khách hàng đang hoạt động mà không đặt lại mật khẩu của khách hàng.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            <a href="{{ route('admin.staff.create') }}" class="bs-ad-btn-outline">
                Tạo tài khoản nhân sự mới
            </a>
            <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">
                Quay lại danh sách
            </a>
        </div>
    </div>

    <section class="bs-ad-panel" aria-labelledby="link-rules-heading">
        <h2 id="link-rules-heading" class="bs-ad-panel-title">Quy tắc bảo toàn tài khoản gốc</h2>
        <ul class="bs-ad-invariant-list">
            <li>Chỉ liên kết được với tài khoản khách hàng đang hoạt động (`staff_access_status = not_applicable`, chưa có hồ sơ nhân sự).</li>
            <li>Mật khẩu hiện tại của khách hàng và quyền truy cập hiệu sách công khai được giữ nguyên; không tự động cấp mật khẩu tạm.</li>
            <li>Sau khi liên kết, nhân sự ở trạng thái <strong>Chờ kích hoạt</strong> và cần đề xuất được Super Admin phê duyệt trước khi truy cập khu vực nội bộ.</li>
        </ul>
    </section>

    <section class="bs-ad-panel bs-ad-panel-surface" aria-labelledby="link-form-heading">
        <h2 id="link-form-heading" class="bs-ad-panel-title">Thông tin liên kết &amp; phân công ban đầu</h2>
        <p class="bs-ad-panel-subtitle">Nhập chính xác email tài khoản khách hàng cần liên kết và chi nhánh/vai trò ban đầu.</p>

        <form method="POST" action="{{ route('admin.staff.link.store') }}" data-admin-form>
            @csrf

            <div class="bs-ad-form-grid">
                <div class="bs-ad-field">
                    <label for="link-customer-email" class="bs-ad-label">Email tài khoản khách hàng</label>
                    <input
                        id="link-customer-email"
                        type="email"
                        name="customer_email"
                        value="{{ old('customer_email') }}"
                        maxlength="255"
                        required
                        placeholder="khachhang@example.com"
                        class="bs-ad-input @error('customer_email') bs-ad-input-error @enderror"
                    >
                    @error('customer_email')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="link-branch-id" class="bs-ad-label">Chi nhánh phân công ban đầu</label>
                    <select
                        id="link-branch-id"
                        name="branch_id"
                        required
                        class="bs-ad-select @error('branch_id') bs-ad-input-error @enderror"
                    >
                        <option value="">-- Chọn chi nhánh đang hoạt động --</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id') === (int) $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('branch_id')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="link-staff-code" class="bs-ad-label">Mã nhân sự (tùy chọn)</label>
                    <input
                        id="link-staff-code"
                        type="text"
                        name="staff_code"
                        value="{{ old('staff_code') }}"
                        maxlength="40"
                        placeholder="Để trống để tự sinh mã NV..."
                        class="bs-ad-input @error('staff_code') bs-ad-input-error @enderror"
                    >
                    @error('staff_code')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="link-job-title" class="bs-ad-label">Chức danh</label>
                    <input
                        id="link-job-title"
                        type="text"
                        name="job_title"
                        value="{{ old('job_title') }}"
                        maxlength="120"
                        placeholder="Ví dụ: Tư vấn viên chi nhánh"
                        class="bs-ad-input @error('job_title') bs-ad-input-error @enderror"
                    >
                    @error('job_title')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="link-hired-at" class="bs-ad-label">Ngày nhận việc</label>
                    <input
                        id="link-hired-at"
                        type="date"
                        name="hired_at"
                        value="{{ old('hired_at') }}"
                        class="bs-ad-input @error('hired_at') bs-ad-input-error @enderror"
                    >
                    @error('hired_at')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field bs-ad-field-full">
                    <span class="bs-ad-label">Vai trò chi nhánh ban đầu (chọn một hoặc nhiều)</span>
                    @php
                        $selectedRoleIds = array_map('intval', (array) old('role_ids', []));
                    @endphp
                    <div class="bs-ad-role-check-grid">
                        @foreach ($branchRoles as $role)
                            <label class="bs-ad-role-check-card">
                                <input
                                    type="checkbox"
                                    name="role_ids[]"
                                    value="{{ $role->id }}"
                                    @checked(in_array((int) $role->id, $selectedRoleIds, true))
                                >
                                <span class="bs-ad-role-check-copy">
                                    <span class="bs-ad-role-check-name">{{ $role->name }}</span>
                                    @if ($role->description)
                                        <span class="bs-ad-role-check-desc">{{ $role->description }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('role_ids')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                    @error('role_ids.*')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field bs-ad-field-full">
                    <label for="link-notes" class="bs-ad-label">Ghi chú liên kết</label>
                    <textarea
                        id="link-notes"
                        name="notes"
                        maxlength="500"
                        placeholder="Ghi chú nội bộ về việc chuyển đổi tài khoản..."
                        class="bs-ad-textarea @error('notes') bs-ad-input-error @enderror"
                    >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bs-ad-form-actions">
                <button type="submit" class="bs-ad-btn-primary" data-loading-text="Đang liên kết tài khoản...">
                    <span data-submit-label>Liên kết thành nhân sự</span>
                </button>
                <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">Hủy và quay lại</a>
            </div>
        </form>
    </section>
@endsection
