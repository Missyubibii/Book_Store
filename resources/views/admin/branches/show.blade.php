{{--
    Chi tiết & Cập nhật Chi nhánh Bán lẻ
    - Entry point: GET /quan-tri/chi-nhanh/{branch} (`admin.branches.show`)
    - Cập nhật thông tin hành chính: PATCH /quan-tri/chi-nhanh/{branch} (`admin.branches.update`)
    - Chuyển trạng thái có xác nhận: PATCH /quan-tri/chi-nhanh/{branch}/trang-thai (`admin.branches.status.update`)
    - Bảo vệ bất biến: `code` và `type` luôn chỉ đọc và bị từ chối thay đổi ở tầng server + domain guard.
--}}
@extends('layouts.admin')

@section('title', $branch->name . ' — Chi tiết Chi nhánh — Book_Store')
@section('header_title', 'Chi tiết chi nhánh')

@section('content')
@php
    $isEditSubmission = old('_form') === 'edit';
    $editStatusValue = $isEditSubmission ? old('status', $branch->status->value) : $branch->status->value;
    $statusConfirmHasErrors = ! $isEditSubmission && ($errors->has('confirmed') || $errors->has('reason') || $errors->has('status'));
    $nextStatus = $branch->isActive() ? \App\Enums\BranchStatus::Inactive : \App\Enums\BranchStatus::Active;
    $activeStaffAssignmentsCount = $branchAssignments->where('status', \App\Enums\BranchAssignmentStatus::Active)->count();
@endphp

<div class="bs-ad-dashboard-canvas">
    {{-- ==================== 1. PAGE HEADER ==================== --}}
    <x-admin.page-header
        :title="$branch->name"
        :subtitle="'Hồ sơ vận hành chi nhánh bán lẻ mã ' . $branch->code . ' (#' . $branch->id . ').'"
    >
        <x-slot:breadcrumb>
            <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
                @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
                    <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
                    <span aria-hidden="true">/</span>
                @endif
                <a href="{{ route('admin.branches.index') }}">Quản lý chi nhánh</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">{{ $branch->code }}</span>
            </nav>
        </x-slot:breadcrumb>

        <x-slot:actions>
            @if ($branch->isActive())
                <x-admin.status-badge variant="success">{{ $branch->status->label() }}</x-admin.status-badge>
            @else
                <x-admin.status-badge variant="danger">{{ $branch->status->label() }}</x-admin.status-badge>
            @endif

            @if ($canViewGlobalAuditLogs)
                <a href="{{ route('admin.audit-logs.index', ['branch_id' => $branch->id]) }}" class="bs-ad-btn-outline">
                    Xem tất cả lịch sử
                </a>
            @endif
            <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Quay lại danh sách</a>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- ==================== 2. 4 KPI SUMMARY CARDS ==================== --}}
    <div class="bs-ad-kpi-grid" aria-label="Thông số định danh bất biến">
        <x-admin.kpi-card
            label="Mã chi nhánh (Bất biến)"
            :value="$branch->code"
            subtext="Định danh chuẩn hóa toàn hệ thống"
            variant="blue"
        >
            <x-slot:icon>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="16" rx="2"/>
                    <line x1="7" y1="8" x2="17" y2="8"/>
                    <line x1="7" y1="12" x2="13" y2="12"/>
                </svg>
            </x-slot:icon>
        </x-admin.kpi-card>

        <x-admin.kpi-card
            label="Loại đơn vị (Bất biến)"
            :value="$branch->type->label()"
            subtext="Khóa cứng theo nghiệp vụ"
            variant="green"
        >
            <x-slot:icon>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 21h18"/>
                    <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                </svg>
            </x-slot:icon>
        </x-admin.kpi-card>

        <x-admin.kpi-card
            label="Nhân sự đang phân công"
            :value="number_format($activeStaffAssignmentsCount, 0, ',', '.')"
            :subtext="'Tổng phân công: ' . number_format($branchAssignments->count(), 0, ',', '.') . ' hồ sơ'"
            variant="purple"
        >
            <x-slot:icon>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </x-slot:icon>
        </x-admin.kpi-card>

        <x-admin.kpi-card
            label="Cập nhật gần nhất"
            :value="format_datetime($branch->updated_at)"
            :subtext="'Khởi tạo: ' . format_datetime($branch->created_at)"
            variant="amber"
        >
            <x-slot:icon>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </x-slot:icon>
        </x-admin.kpi-card>
    </div>

    {{-- ==================== 3. BRANCH INFORMATION & PROGRESSIVE EDIT FORM ==================== --}}
    <x-admin.collapsible-section
        title="Thông tin hành chính chi nhánh"
        :subtitle="'Có thể cập nhật tên gọi, trạng thái hoạt động, số điện thoại và địa chỉ. Mã chi nhánh (' . $branch->code . ') và loại đơn vị là bất biến sau khi tạo.'"
        heading-id="edit-branch-heading"
        panel-id="branch-edit-form-panel"
        :initial-open="$isEditSubmission"
        :can-toggle="$canUpdateBranch"
        open-label="Chỉnh sửa thông tin"
        close-label="Đóng chỉnh sửa"
    >
        <x-slot:summary>
            <dl class="bs-ad-info-list">
                <x-admin.information-row label="Tên chi nhánh" :value="$branch->name" />
                <x-admin.information-row label="Mã chi nhánh">
                    <span class="bs-ad-branch-code-tag">{{ $branch->code }}</span>
                </x-admin.information-row>
                <x-admin.information-row label="Loại đơn vị" :value="$branch->type->label()" />
                <x-admin.information-row label="Trạng thái vận hành">
                    @if ($branch->isActive())
                        <x-admin.status-badge variant="success">{{ $branch->status->label() }}</x-admin.status-badge>
                    @else
                        <x-admin.status-badge variant="danger">{{ $branch->status->label() }}</x-admin.status-badge>
                    @endif
                </x-admin.information-row>
                <x-admin.information-row label="Số điện thoại" :value="$branch->phone ?: 'Chưa cập nhật'" />
                <x-admin.information-row label="Địa chỉ" :value="$branch->address ?: 'Chưa cập nhật địa chỉ'" />
            </dl>
        </x-slot:summary>

        <form
            method="POST"
            action="{{ route('admin.branches.update', $branch) }}"
            data-admin-form
            novalidate
        >
            @csrf
            @method('PATCH')
            <input type="hidden" name="_form" value="edit">

            <div class="bs-ad-form-grid">
                <x-admin.form-field
                    label="Mã chi nhánh"
                    :error="$isEditSubmission ? $errors->first('code') : null"
                >
                    <div class="bs-ad-readonly-box">
                        <span class="bs-ad-branch-code-tag">{{ $branch->code }}</span>
                        <span class="bs-ad-help">Định danh bất biến</span>
                    </div>
                </x-admin.form-field>

                <x-admin.form-field
                    label="Loại đơn vị"
                    :error="$isEditSubmission ? $errors->first('type') : null"
                >
                    <div class="bs-ad-readonly-box">
                        <span>{{ $branch->type->label() }}</span>
                        <span class="bs-ad-help">Không thể chuyển đổi sang Kho trung tâm</span>
                    </div>
                </x-admin.form-field>

                <x-admin.form-field
                    label="Tên chi nhánh"
                    for="edit-branch-name"
                    :required="true"
                    :error="$isEditSubmission ? $errors->first('name') : null"
                >
                    <input
                        id="edit-branch-name"
                        type="text"
                        name="name"
                        value="{{ $isEditSubmission ? old('name', $branch->name) : $branch->name }}"
                        required
                        maxlength="150"
                        class="bs-ad-input @if($isEditSubmission && $errors->has('name')) bs-ad-input-error @endif"
                    >
                </x-admin.form-field>

                <x-admin.form-field
                    label="Trạng thái vận hành"
                    for="edit-branch-status"
                    :required="true"
                    :error="$isEditSubmission ? $errors->first('status') : null"
                >
                    <select
                        id="edit-branch-status"
                        name="status"
                        class="bs-ad-select @if($isEditSubmission && $errors->has('status')) bs-ad-input-error @endif"
                    >
                        @foreach ($statusOptions as $option)
                            <option value="{{ $option->value }}" @selected($editStatusValue === $option->value)>
                                {{ $option->label() }}
                            </option>
                        @endforeach
                    </select>
                </x-admin.form-field>

                <x-admin.form-field
                    label="Số điện thoại liên hệ"
                    for="edit-branch-phone"
                    :error="$isEditSubmission ? $errors->first('phone') : null"
                >
                    <input
                        id="edit-branch-phone"
                        type="tel"
                        name="phone"
                        value="{{ $isEditSubmission ? old('phone', $branch->phone) : $branch->phone }}"
                        maxlength="30"
                        placeholder="Chưa cập nhật"
                        class="bs-ad-input @if($isEditSubmission && $errors->has('phone')) bs-ad-input-error @endif"
                    >
                </x-admin.form-field>

                <x-admin.form-field
                    label="Địa chỉ chi nhánh"
                    for="edit-branch-address"
                    :full="true"
                    :error="$isEditSubmission ? $errors->first('address') : null"
                >
                    <textarea
                        id="edit-branch-address"
                        name="address"
                        rows="3"
                        maxlength="500"
                        placeholder="Chưa cập nhật địa chỉ"
                        class="bs-ad-textarea @if($isEditSubmission && $errors->has('address')) bs-ad-input-error @endif"
                    >{{ $isEditSubmission ? old('address', $branch->address) : $branch->address }}</textarea>
                </x-admin.form-field>
            </div>

            <x-admin.form-actions>
                <button type="submit" class="bs-ad-btn-primary">
                    <span>Cập nhật thông tin chi nhánh</span>
                </button>
                <button type="button" class="bs-ad-btn-outline" data-disclosure-close>
                    Đóng chỉnh sửa
                </button>
            </x-admin.form-actions>
        </form>
    </x-admin.collapsible-section>

    {{-- ==================== 4. STAFF ASSIGNMENTS SECTION ==================== --}}
    <x-admin.content-card title="Nhân sự phân công tại chi nhánh" heading-id="branch-staff-assignments-heading">
        <x-slot:headerActions>
            <span class="bs-ad-muted-meta">Đang hiệu lực: {{ number_format($activeStaffAssignmentsCount, 0, ',', '.') }} nhân sự</span>
            @if ($canViewStaffDirectory)
                <a href="{{ route('admin.staff.index', ['branch_id' => $branch->id]) }}" class="bs-ad-btn-outline bs-ad-btn-sm">
                    Quản lý nhân sự
                </a>
            @endif
        </x-slot:headerActions>

        @if ($branchAssignments->isEmpty())
            <x-admin.empty-state
                description="Chưa có nhân sự nào được phân công vào chi nhánh này."
            >
                @if ($canViewStaffDirectory)
                    <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">Đi tới danh bạ nhân sự</a>
                @endif
            </x-admin.empty-state>
        @else
            <x-admin.bounded-scroll max-height="md" label="Danh sách nhân sự phân công tại chi nhánh">
                <x-admin.data-table>
                <x-slot:head>
                    <tr>
                        <th>NHÂN SỰ</th>
                        <th>VAI TRÒ TẠI CHI NHÁNH</th>
                        <th>TRẠNG THÁI PHÂN CÔNG</th>
                        <th>NGÀY PHÂN CÔNG</th>
                        @if ($canViewStaffDirectory)
                            <th class="bs-ad-th-action">THAO TÁC</th>
                        @endif
                    </tr>
                </x-slot:head>

                @foreach ($branchAssignments as $assignment)
                    @php
                        $staffUser = $assignment->user;
                        $userRoles = $staffUser ? ($roleAssignmentsByUser->get($staffUser->id) ?? collect()) : collect();
                    @endphp
                    <tr>
                        <td>
                            <div class="bs-ad-branch-cell">
                                <span class="bs-ad-user-name">{{ $staffUser?->name ?? 'Tài khoản không xác định' }}</span>
                                <span class="bs-ad-user-email">{{ $staffUser?->email ?? '' }}</span>
                                @if ($staffUser?->staffProfile?->employee_code)
                                    <span class="bs-ad-branch-code-tag">{{ $staffUser->staffProfile->employee_code }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @if ($userRoles->isEmpty())
                                <span class="bs-ad-muted-meta">Chưa gán vai trò</span>
                            @else
                                <div class="bs-ad-login-badges">
                                    @foreach ($userRoles as $roleAssignment)
                                        <x-admin.status-badge variant="info">{{ $roleAssignment->role?->name ?? $roleAssignment->role?->code }}</x-admin.status-badge>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td>
                            @if ($assignment->status === \App\Enums\BranchAssignmentStatus::Active)
                                <x-admin.status-badge variant="success">{{ $assignment->status->label() }}</x-admin.status-badge>
                            @else
                                <x-admin.status-badge variant="neutral">{{ $assignment->status->label() }}</x-admin.status-badge>
                            @endif
                            @if ($assignment->is_primary)
                                <x-admin.status-badge variant="info">Chi nhánh chính</x-admin.status-badge>
                            @endif
                        </td>
                        <td>
                            <span class="bs-ad-date-cell">{{ format_datetime($assignment->assigned_at ?? $assignment->created_at) }}</span>
                        </td>
                        @if ($canViewStaffDirectory)
                            <td class="bs-ad-td-action">
                                @if ($staffUser)
                                    <a href="{{ route('admin.staff.show', $staffUser) }}" class="bs-ad-btn-detail">
                                        Hồ sơ nhân sự
                                    </a>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </x-admin.data-table>
            </x-admin.bounded-scroll>

            <div class="bs-ad-mobile-cards">
                @foreach ($branchAssignments as $assignment)
                    @php
                        $staffUser = $assignment->user;
                        $userRoles = $staffUser ? ($roleAssignmentsByUser->get($staffUser->id) ?? collect()) : collect();
                    @endphp
                    <article class="bs-ad-mobile-card">
                        <div class="bs-ad-mobile-card-head">
                            <div>
                                <h3 class="bs-ad-user-name">{{ $staffUser?->name ?? 'Tài khoản không xác định' }}</h3>
                                <p class="bs-ad-user-email">{{ $staffUser?->email ?? '' }}</p>
                            </div>
                            @if ($assignment->status === \App\Enums\BranchAssignmentStatus::Active)
                                <x-admin.status-badge variant="success">{{ $assignment->status->label() }}</x-admin.status-badge>
                            @else
                                <x-admin.status-badge variant="neutral">{{ $assignment->status->label() }}</x-admin.status-badge>
                            @endif
                        </div>

                        <dl class="bs-ad-mobile-card-meta">
                            <div>
                                <dt>Vai trò tại chi nhánh</dt>
                                <dd>
                                    @if ($userRoles->isEmpty())
                                        Chưa gán vai trò
                                    @else
                                        {{ $userRoles->map(fn ($r) => $r->role?->name ?? $r->role?->code)->implode(', ') }}
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>Ngày phân công</dt>
                                <dd>{{ format_datetime($assignment->assigned_at ?? $assignment->created_at) }}</dd>
                            </div>
                        </dl>

                        @if ($canViewStaffDirectory && $staffUser)
                            <div class="bs-ad-mobile-card-actions">
                                <a href="{{ route('admin.staff.show', $staffUser) }}" class="bs-ad-btn-detail bs-ad-btn-full">
                                    Hồ sơ nhân sự
                                </a>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </x-admin.content-card>

    {{-- ==================== 5. BRANCH STATUS MANAGEMENT (WITH CONFIRMATION) ==================== --}}
    @if ($canUpdateBranch)
        <x-admin.confirmation-panel
            title="Kiểm soát trạng thái hoạt động chi nhánh"
            :subtitle="$branch->isActive()
                ? 'Chi nhánh hiện đang hoạt động. Việc chuyển sang ngừng hoạt động giúp khóa tạm thời điểm bán mà vẫn giữ toàn bộ lịch sử nghiệp vụ và kế toán.'
                : 'Chi nhánh hiện đang ngừng hoạt động. Bạn có thể kích hoạt lại chi nhánh khi điểm bán sẵn sàng vận hành trở lại.'"
            heading-id="status-control-heading"
            :initial-open="$statusConfirmHasErrors"
        >
            <x-slot:trigger>
                <button
                    type="button"
                    data-confirm-open
                    aria-expanded="{{ $statusConfirmHasErrors ? 'true' : 'false' }}"
                    aria-controls="branch-status-confirm-panel"
                    class="{{ $branch->isActive() ? 'bs-ad-btn-danger-outline' : 'bs-ad-btn-primary' }}"
                >
                    {{ $branch->isActive() ? 'Ngừng hoạt động chi nhánh' : 'Kích hoạt lại chi nhánh' }}
                </button>
            </x-slot:trigger>

            <form
                id="branch-status-confirm-panel"
                method="POST"
                action="{{ route('admin.branches.status.update', $branch) }}"
                @class(['bs-ad-confirm-box', 'bs-hidden' => ! $statusConfirmHasErrors])
                @if (! $statusConfirmHasErrors) x-cloak @endif
                data-confirm-panel
                aria-hidden="{{ $statusConfirmHasErrors ? 'false' : 'true' }}"
            >
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="{{ $nextStatus->value }}">

                <p class="bs-ad-confirm-heading">
                    @if ($branch->isActive())
                        Xác nhận ngừng hoạt động chi nhánh {{ $branch->name }} ({{ $branch->code }})
                    @else
                        Xác nhận kích hoạt lại chi nhánh {{ $branch->name }} ({{ $branch->code }})
                    @endif
                </p>

                <label class="bs-ad-check-row">
                    <input
                        type="checkbox"
                        name="confirmed"
                        value="1"
                        data-confirm-checkbox
                        @checked(! $isEditSubmission && old('confirmed'))
                    >
                    <span>
                        Tôi xác nhận thay đổi trạng thái chi nhánh <strong>{{ $branch->code }}</strong> sang
                        <strong>{{ $nextStatus->label() }}</strong> và ghi nhận vào nhật ký kiểm toán.
                    </span>
                </label>

                @if (! $isEditSubmission)
                    @error('confirmed')
                        <p class="bs-ad-field-error" role="alert">{{ $message }}</p>
                    @enderror
                @endif

                <x-admin.form-field
                    label="Lý do kiểm toán (tùy chọn)"
                    for="status-change-reason"
                    :error="! $isEditSubmission ? $errors->first('reason') : null"
                >
                    <input
                        id="status-change-reason"
                        type="text"
                        name="reason"
                        data-confirm-reset-input
                        value="{{ ! $isEditSubmission ? old('reason') : '' }}"
                        maxlength="255"
                        placeholder="Ví dụ: Tạm ngưng sửa chữa mặt bằng hoặc khai trương lại"
                        class="bs-ad-input @if(! $isEditSubmission && $errors->has('reason')) bs-ad-input-error @endif"
                    >
                </x-admin.form-field>

                <x-admin.form-actions>
                    <button
                        type="submit"
                        data-confirm-submit
                        class="{{ $branch->isActive() ? 'bs-ad-btn-danger-outline' : 'bs-ad-btn-primary' }}"
                        disabled
                    >
                        {{ $branch->isActive() ? 'Xác nhận ngừng hoạt động' : 'Xác nhận kích hoạt lại' }}
                    </button>

                    <button
                        type="button"
                        class="bs-ad-btn-outline"
                        data-confirm-cancel
                    >
                        Hủy thao tác
                    </button>
                </x-admin.form-actions>
            </form>
        </x-admin.confirmation-panel>
    @endif

    {{-- ==================== 6. RECENT BRANCH AUDIT ACTIVITY ==================== --}}
    <x-admin.detail-section
        title="Nhật ký hoạt động gần đây"
        :subtitle="'Lịch sử thay đổi thông tin và trạng thái hoạt động của chi nhánh ' . $branch->name . '.'"
        heading-id="branch-audit-history-heading"
    >
        @if ($canViewGlobalAuditLogs)
            <x-slot:headerActions>
                <a
                    href="{{ route('admin.audit-logs.index', ['branch_id' => $branch->id]) }}"
                    class="bs-ad-btn-outline bs-ad-btn-sm"
                >
                    Xem tất cả lịch sử
                </a>
            </x-slot:headerActions>
        @endif

        <div class="bs-ad-smart-scroll-wrap"
             data-smart-scroll
             data-endpoint="{{ route('admin.branches.audit-logs', $branch) }}"
             data-cursor="{{ $nextAuditCursor ?? '' }}"
             data-has-more="{{ !empty($hasMoreAudits) ? 'true' : 'false' }}"
             data-scroll-mode="list">
            <div class="bs-ad-smart-scroll-container bs-ad-audit-scroll"
                 data-smart-scroll-container
                 tabindex="0"
                 role="region"
                 aria-label="Danh sách nhật ký hoạt động của chi nhánh {{ $branch->name }}">
                @if ($recentAudits->isEmpty())
                    <div class="bs-ad-info-banner m-3" data-smart-scroll-empty>Chưa có bản ghi hoạt động nào cho chi nhánh này.</div>
                    <div data-smart-scroll-items></div>
                @else
                    <div class="divide-y divide-gray-100" data-smart-scroll-items>
                        @foreach ($recentAudits as $audit)
                            <article class="bs-ad-audit-item" data-item-id="{{ $audit->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-800">
                                                {{ $audit->action->label() }}
                                            </span>
                                            @if ($audit->targetUser !== null)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-gray-100 text-gray-700">
                                                    Nhân sự: {{ $audit->targetUser->name }}
                                                </span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-xs text-gray-600">
                                            <span class="font-medium text-gray-900">{{ $audit->actor?->name ?? 'Hệ thống / CLI' }}</span>
                                            &bull;
                                            <time datetime="{{ $audit->occurred_at }}" class="text-gray-500">{{ format_datetime($audit->occurred_at) }}</time>
                                        </p>
                                        @if ($audit->reason)
                                            <p class="mt-1 text-xs text-gray-500 italic line-clamp-2">
                                                "{{ $audit->reason }}"
                                            </p>
                                        @endif
                                    </div>
                                    @if ($canViewGlobalAuditLogs)
                                        <div class="flex-shrink-0 pt-0.5">
                                            <button type="button"
                                                    class="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:text-teal-900 focus:outline-none focus:underline"
                                                    data-audit-detail-trigger
                                                    data-audit-detail-url="{{ route('admin.audit-logs.show', $audit) }}"
                                                    aria-label="Xem chi tiết sự kiện #{{ $audit->id }}">
                                                Chi tiết
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
                <div class="bs-ad-smart-scroll-sentinel" data-smart-scroll-sentinel aria-hidden="true"></div>
            </div>
            <div class="bs-ad-smart-scroll-footer" data-smart-scroll-footer>
                <span class="bs-ad-smart-scroll-status bs-hidden" data-smart-scroll-status aria-live="polite"></span>
                <button type="button"
                        class="bs-ad-smart-scroll-btn {{ empty($hasMoreAudits) ? 'bs-hidden' : '' }}"
                        data-smart-scroll-load-more>
                    Tải thêm
                </button>
                <button type="button"
                        class="bs-ad-smart-scroll-btn bs-ad-smart-scroll-btn-retry bs-hidden"
                        data-smart-scroll-retry>
                    Thử lại
                </button>
            </div>
        </div>
    </x-admin.detail-section>

    @include('admin.audit-logs.partials.drawer')
</div>
@endsection
