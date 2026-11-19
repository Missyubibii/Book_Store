{{--
    Biểu mẫu Khởi tạo Nhân sự mới & Cấp Mật khẩu tạm
    - Entry point: GET /quan-tri/nhan-su/tao-moi -> StaffController::create
    - Submit: POST /quan-tri/nhan-su (`admin.staff.store`) -> StaffController::store -> StaffAdministrationService::createStaffAccount
    - Mật khẩu tạm có hiệu lực 24 giờ (`temp_password_expires_at`), băm Bcrypt ngay, không ghi log hay hiển thị lại plaintext.
--}}
@extends('layouts.admin')

@section('title', 'Tạo Nhân sự mới — Book_Store')
@section('header_title', 'Tạo nhân sự mới')

@section('content')
<div class="bs-ad-dashboard-canvas">
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <a href="{{ route('admin.staff.index') }}">Nhân sự</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Tạo nhân sự mới</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Khởi tạo Hồ sơ Nhân sự (Chờ kích hoạt)</p>
            <h1 class="bs-ad-title">Tạo Nhân sự mới</h1>
            <p class="bs-ad-subtitle">
                Tạo tài khoản nội bộ với mật khẩu tạm có hiệu lực {{ $ttlHours }} giờ, gán chi nhánh ban đầu và đặt trạng thái Chờ kích hoạt.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            <a href="{{ route('admin.staff.link.create') }}" class="bs-ad-btn-outline">
                Liên kết khách hàng hiện hữu
            </a>
            <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">
                Quay lại danh sách
            </a>
        </div>
    </div>

    <section class="bs-ad-panel bs-ad-panel-surface" aria-labelledby="staff-create-heading">
        <h2 id="staff-create-heading" class="bs-ad-panel-title">Thông tin tài khoản, mật khẩu tạm và phân công ban đầu</h2>
        <p class="bs-ad-panel-subtitle">
            Mật khẩu tạm do người tạo nhập để bàn giao trực tiếp qua kênh bảo mật ngoài hệ thống; hệ thống chỉ lưu bản băm Bcrypt và buộc đổi mật khẩu ở lần đăng nhập đầu tiên.
        </p>

        <form method="POST" action="{{ route('admin.staff.store') }}" data-admin-form>
            @csrf

            <div class="bs-ad-form-grid">
                <div class="bs-ad-field">
                    <label for="staff-name" class="bs-ad-label">Họ và tên nhân sự</label>
                    <input
                        id="staff-name"
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        maxlength="120"
                        required
                        placeholder="Ví dụ: Trần Minh Quân"
                        class="bs-ad-input @error('name') bs-ad-input-error @enderror"
                    >
                    @error('name')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="staff-email" class="bs-ad-label">Email đăng nhập</label>
                    <input
                        id="staff-email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        maxlength="255"
                        required
                        placeholder="vidu.nhansu@bookstore.vn"
                        class="bs-ad-input @error('email') bs-ad-input-error @enderror"
                    >
                    @error('email')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="staff-branch-id" class="bs-ad-label">Chi nhánh phân công ban đầu</label>
                    <select
                        id="staff-branch-id"
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
                    <label for="staff-code" class="bs-ad-label">Mã nhân sự (tùy chọn)</label>
                    <input
                        id="staff-code"
                        type="text"
                        name="staff_code"
                        value="{{ old('staff_code') }}"
                        maxlength="40"
                        placeholder="Để trống để tự cấp hoặc nhập, ví dụ: NV-HN01"
                        class="bs-ad-input @error('staff_code') bs-ad-input-error @enderror"
                    >
                    @error('staff_code')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="staff-job-title" class="bs-ad-label">Chức danh</label>
                    <input
                        id="staff-job-title"
                        type="text"
                        name="job_title"
                        value="{{ old('job_title') }}"
                        maxlength="120"
                        placeholder="Ví dụ: Nhân viên vận hành chi nhánh"
                        class="bs-ad-input @error('job_title') bs-ad-input-error @enderror"
                    >
                    @error('job_title')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="staff-hired-at" class="bs-ad-label">Ngày nhận việc</label>
                    <input
                        id="staff-hired-at"
                        type="date"
                        name="hired_at"
                        value="{{ old('hired_at') }}"
                        class="bs-ad-input @error('hired_at') bs-ad-input-error @enderror"
                    >
                    @error('hired_at')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="staff-temp-password" class="bs-ad-label">Mật khẩu tạm (hiệu lực {{ $ttlHours }} giờ)</label>
                    <input
                        id="staff-temp-password"
                        type="password"
                        name="temporary_password"
                        autocomplete="new-password"
                        required
                        class="bs-ad-input @error('temporary_password') bs-ad-input-error @enderror"
                    >
                    <p class="bs-ad-help">Tối thiểu 12 ký tự, gồm chữ hoa, chữ thường, chữ số và ký tự đặc biệt.</p>
                    @error('temporary_password')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="staff-temp-password-confirm" class="bs-ad-label">Xác nhận mật khẩu tạm</label>
                    <input
                        id="staff-temp-password-confirm"
                        type="password"
                        name="temporary_password_confirmation"
                        autocomplete="new-password"
                        required
                        class="bs-ad-input"
                    >
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
                    <label for="staff-notes" class="bs-ad-label">Ghi chú hồ sơ (không nhập thông tin nhạy cảm hoặc mật khẩu)</label>
                    <textarea
                        id="staff-notes"
                        name="notes"
                        maxlength="500"
                        placeholder="Ghi chú nội bộ về phạm vi công việc..."
                        class="bs-ad-textarea @error('notes') bs-ad-input-error @enderror"
                    >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bs-ad-form-actions">
                <button type="submit" class="bs-ad-btn-primary" data-loading-text="Đang khởi tạo hồ sơ nhân sự...">
                    <span data-submit-label>Khởi tạo hồ sơ nhân sự</span>
                </button>
                <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">Hủy và quay lại</a>
            </div>
        </form>
    </section>
</div>
@endsection
