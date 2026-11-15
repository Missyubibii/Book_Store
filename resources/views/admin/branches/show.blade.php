{{--
    Chi tiết & Cập nhật Chi nhánh Bán lẻ (Stage 01B.4)
    - Entry point: GET /quan-tri/chi-nhanh/{branch} (`admin.branches.show`)
    - Cập nhật thông tin hành chính: PATCH /quan-tri/chi-nhanh/{branch} (`admin.branches.update`)
    - Chuyển trạng thái có xác nhận: PATCH /quan-tri/chi-nhanh/{branch}/trang-thai (`admin.branches.status.update`)
    - Bảo vệ bất biến: `code` và `type` luôn chỉ đọc và bị từ chối thay đổi ở tầng server + domain guard.
--}}
@extends('layouts.admin')

@section('title', $branch->name . ' — Chi tiết Chi nhánh — Book_Store')

@section('content')
    @php
        $isEditSubmission = old('_form') === 'edit';
        $editStatusValue = $isEditSubmission ? old('status', $branch->status->value) : $branch->status->value;
        $statusConfirmHasErrors = ! $isEditSubmission && ($errors->has('confirmed') || $errors->has('reason') || $errors->has('status'));
        $nextStatus = $branch->isActive() ? \App\Enums\BranchStatus::Inactive : \App\Enums\BranchStatus::Active;
    @endphp

    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <a href="{{ route('admin.branches.index') }}">Chi nhánh bán lẻ</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $branch->code }}</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Hồ sơ Chi nhánh Bán lẻ #{{ $branch->id }}</p>
            <h1 class="bs-ad-title">{{ $branch->name }}</h1>
            <div class="bs-ad-form-actions">
                <span class="bs-ad-code">{{ $branch->code }}</span>
                <span class="bs-ad-pill bs-ad-pill-neutral">{{ $branch->type->label() }}</span>
                <span @class([
                    'bs-ad-pill',
                    'bs-ad-pill-active' => $branch->isActive(),
                    'bs-ad-pill-inactive' => ! $branch->isActive(),
                ])>
                    {{ $branch->status->label() }}
                </span>
            </div>
        </div>

        <div class="bs-ad-page-actions">
            @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
                <a href="{{ route('admin.audit-logs.index', ['branch_id' => $branch->id]) }}" class="bs-ad-btn-outline">
                    Xem tất cả lịch sử
                </a>
            @endif
            <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Quay lại danh sách</a>
        </div>
    </div>

    <section class="bs-ad-identity-grid" aria-label="Thông số định danh bất biến">
        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Mã chi nhánh (Bất biến)</p>
            <p class="bs-ad-stat-value">{{ $branch->code }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Loại đơn vị (Bất biến)</p>
            <p class="bs-ad-stat-value">{{ $branch->type->label() }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Khởi tạo lúc</p>
            <p class="bs-ad-stat-value">{{ format_datetime($branch->created_at) }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Cập nhật gần nhất</p>
            <p class="bs-ad-stat-value">{{ format_datetime($branch->updated_at) }}</p>
        </article>
    </section>

    <section
        class="bs-ad-panel"
        aria-labelledby="edit-branch-heading"
        @if ($canUpdateBranch)
            data-form-disclosure
            data-initial-open="{{ $isEditSubmission ? 'true' : 'false' }}"
        @endif
    >
        <div class="bs-ad-panel-head">
            <div>
                <h2 id="edit-branch-heading" class="bs-ad-panel-title">Thông tin hành chính chi nhánh</h2>
                <p class="bs-ad-panel-subtitle">
                    Có thể cập nhật tên gọi, trạng thái hoạt động, số điện thoại và địa chỉ. Mã chi nhánh ({{ $branch->code }}) và loại đơn vị là bất biến sau khi tạo.
                </p>
            </div>
            @if ($canUpdateBranch)
                <button
                    type="button"
                    class="bs-ad-btn-outline"
                    data-disclosure-toggle
                    data-label-open="Chỉnh sửa thông tin"
                    data-label-close="Đóng chỉnh sửa"
                    aria-expanded="{{ $isEditSubmission ? 'true' : 'false' }}"
                    aria-controls="branch-edit-form-panel"
                >
                    <span data-disclosure-label>{{ $isEditSubmission ? 'Đóng chỉnh sửa' : 'Chỉnh sửa thông tin' }}</span>
                </button>
            @endif
        </div>

        <dl class="bs-ad-meta-list @if($canUpdateBranch && $isEditSubmission) bs-hidden @endif" @if($canUpdateBranch) data-disclosure-summary @endif>
            <div class="bs-ad-meta-row">
                <dt class="bs-ad-meta-label">Tên chi nhánh:</dt>
                <dd class="bs-ad-meta-value">{{ $branch->name }}</dd>
            </div>
            <div class="bs-ad-meta-row">
                <dt class="bs-ad-meta-label">Số điện thoại:</dt>
                <dd class="bs-ad-meta-value">{{ $branch->phone ?: 'Chưa cập nhật' }}</dd>
            </div>
            <div class="bs-ad-meta-row">
                <dt class="bs-ad-meta-label">Địa chỉ:</dt>
                <dd class="bs-ad-meta-value">{{ $branch->address ?: 'Chưa cập nhật địa chỉ' }}</dd>
            </div>
        </dl>

        @if ($canUpdateBranch)
            <div
                id="branch-edit-form-panel"
                data-disclosure-panel
                aria-hidden="{{ $isEditSubmission ? 'false' : 'true' }}"
                @class(['bs-hidden' => ! $isEditSubmission])
            >
                <form
                    method="POST"
                    action="{{ route('admin.branches.update', $branch) }}"
                    data-admin-form
                    x-data="adminBranchForm"
                    @submit="handleSubmit"
                    novalidate
                >
                @csrf
                @method('PATCH')
                <input type="hidden" name="_form" value="edit">

                <div class="bs-ad-form-grid">
                    <div class="bs-ad-field">
                        <span class="bs-ad-label">Mã chi nhánh</span>
                        <div class="bs-ad-readonly-box">
                            <span class="bs-ad-code">{{ $branch->code }}</span>
                            <span class="bs-ad-help">Định danh bất biến</span>
                        </div>
                    </div>

                    <div class="bs-ad-field">
                        <span class="bs-ad-label">Loại đơn vị</span>
                        <div class="bs-ad-readonly-box">
                            <span>{{ $branch->type->label() }}</span>
                            <span class="bs-ad-help">Không thể chuyển đổi sang Kho trung tâm</span>
                        </div>
                    </div>

                    <div class="bs-ad-field">
                        <label for="edit-branch-name" class="bs-ad-label">Tên chi nhánh *</label>
                        <input
                            id="edit-branch-name"
                            type="text"
                            name="name"
                            value="{{ $isEditSubmission ? old('name', $branch->name) : $branch->name }}"
                            required
                            maxlength="150"
                            class="bs-ad-input @if($isEditSubmission && $errors->has('name')) bs-ad-input-error @endif"
                        >
                        @if ($isEditSubmission)
                            @error('name')
                                <p class="bs-ad-field-error">{{ $message }}</p>
                            @enderror
                        @endif
                    </div>

                    <div class="bs-ad-field">
                        <label for="edit-branch-status" class="bs-ad-label">Trạng thái vận hành *</label>
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
                        @if ($isEditSubmission)
                            @error('status')
                                <p class="bs-ad-field-error">{{ $message }}</p>
                            @enderror
                        @endif
                    </div>

                    <div class="bs-ad-field">
                        <label for="edit-branch-phone" class="bs-ad-label">Số điện thoại liên hệ</label>
                        <input
                            id="edit-branch-phone"
                            type="tel"
                            name="phone"
                            value="{{ $isEditSubmission ? old('phone', $branch->phone) : $branch->phone }}"
                            maxlength="30"
                            placeholder="Chưa cập nhật"
                            class="bs-ad-input @if($isEditSubmission && $errors->has('phone')) bs-ad-input-error @endif"
                        >
                        @if ($isEditSubmission)
                            @error('phone')
                                <p class="bs-ad-field-error">{{ $message }}</p>
                            @enderror
                        @endif
                    </div>

                    <div class="bs-ad-field bs-ad-field-full">
                        <label for="edit-branch-address" class="bs-ad-label">Địa chỉ chi nhánh</label>
                        <textarea
                            id="edit-branch-address"
                            name="address"
                            rows="3"
                            maxlength="500"
                            placeholder="Chưa cập nhật địa chỉ"
                            class="bs-ad-textarea @if($isEditSubmission && $errors->has('address')) bs-ad-input-error @endif"
                        >{{ $isEditSubmission ? old('address', $branch->address) : $branch->address }}</textarea>
                        @if ($isEditSubmission)
                            @error('address')
                                <p class="bs-ad-field-error">{{ $message }}</p>
                            @enderror
                        @endif
                    </div>
                </div>

                <div class="bs-ad-form-actions">
                    <button
                        type="submit"
                        class="bs-ad-btn-primary"
                        :disabled="submitting"
                        :aria-busy="submitting ? 'true' : 'false'"
                    >
                        <span x-text="submitting ? 'Đang cập nhật...' : 'Cập nhật thông tin chi nhánh'">Cập nhật thông tin chi nhánh</span>
                    </button>
                    <button type="button" class="bs-ad-btn-outline" data-disclosure-close>
                        Đóng chỉnh sửa
                    </button>
                </div>
            </form>
            </div>
        @endif
    </section>

    @if ($canUpdateBranch)
        <section
            class="bs-ad-panel"
            aria-labelledby="status-control-heading"
            data-confirm-disclosure
            data-initial-open="{{ $statusConfirmHasErrors ? 'true' : 'false' }}"
            x-data="branchStatusConfirm(@json($statusConfirmHasErrors))"
        >
            <h2 id="status-control-heading" class="bs-ad-panel-title">Kiểm soát trạng thái hoạt động chi nhánh</h2>
            <p class="bs-ad-panel-subtitle">
                @if ($branch->isActive())
                    Chi nhánh hiện đang hoạt động. Việc chuyển sang ngừng hoạt động giúp khóa tạm thời điểm bán mà vẫn giữ toàn bộ lịch sử nghiệp vụ và kế toán.
                @else
                    Chi nhánh hiện đang ngừng hoạt động. Bạn có thể kích hoạt lại chi nhánh khi điểm bán sẵn sàng vận hành trở lại.
                @endif
            </p>

            <div
                @class(['bs-ad-form-actions', 'bs-hidden' => $statusConfirmHasErrors])
                :class="{ 'bs-hidden': confirmOpen }"
                data-confirm-trigger-wrap
                x-show="!confirmOpen"
            >
                <button
                    type="button"
                    data-confirm-open
                    aria-expanded="{{ $statusConfirmHasErrors ? 'true' : 'false' }}"
                    aria-controls="branch-status-confirm-panel"
                    @click="openConfirm"
                    class="{{ $branch->isActive() ? 'bs-ad-btn-danger-outline' : 'bs-ad-btn-primary' }}"
                >
                    {{ $branch->isActive() ? 'Ngừng hoạt động chi nhánh' : 'Kích hoạt lại chi nhánh' }}
                </button>
            </div>

            <form
                id="branch-status-confirm-panel"
                method="POST"
                action="{{ route('admin.branches.status.update', $branch) }}"
                @class(['bs-ad-confirm-box', 'bs-hidden' => ! $statusConfirmHasErrors])
                :class="{ 'bs-hidden': !confirmOpen }"
                @if (! $statusConfirmHasErrors) x-cloak @endif
                data-confirm-panel
                aria-hidden="{{ $statusConfirmHasErrors ? 'false' : 'true' }}"
                x-show="confirmOpen"
                @submit="handleSubmit"
            >
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="{{ $nextStatus->value }}">

                <p class="bs-ad-panel-title">
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
                        x-model="confirmedChecked"
                        @checked(! $isEditSubmission && old('confirmed'))
                    >
                    <span>
                        Tôi xác nhận thay đổi trạng thái chi nhánh <strong>{{ $branch->code }}</strong> sang
                        <strong>{{ $nextStatus->label() }}</strong> và ghi nhận vào nhật ký kiểm toán.
                    </span>
                </label>

                @if (! $isEditSubmission)
                    @error('confirmed')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                @endif

                <div class="bs-ad-field">
                    <label for="status-change-reason" class="bs-ad-label">Lý do kiểm toán (tùy chọn)</label>
                    <input
                        id="status-change-reason"
                        type="text"
                        name="reason"
                        data-confirm-reset-input
                        x-ref="reasonInput"
                        value="{{ ! $isEditSubmission ? old('reason') : '' }}"
                        maxlength="255"
                        placeholder="Ví dụ: Tạm ngưng sửa chữa mặt bằng hoặc khai trương lại"
                        class="bs-ad-input @if(! $isEditSubmission && $errors->has('reason')) bs-ad-input-error @endif"
                    >
                    @if (! $isEditSubmission)
                        @error('reason')
                            <p class="bs-ad-field-error">{{ $message }}</p>
                        @enderror
                    @endif
                </div>

                <div class="bs-ad-form-actions">
                    <button
                        type="submit"
                        data-confirm-submit
                        class="{{ $branch->isActive() ? 'bs-ad-btn-danger-outline' : 'bs-ad-btn-primary' }}"
                        :disabled="submitting || !confirmedChecked"
                        disabled
                    >
                        {{ $branch->isActive() ? 'Xác nhận ngừng hoạt động' : 'Xác nhận kích hoạt lại' }}
                    </button>

                    <button
                        type="button"
                        class="bs-ad-btn-outline"
                        data-confirm-cancel
                        @click="closeConfirm(); if ($refs.reasonInput) { $refs.reasonInput.value = ''; }"
                    >
                        Hủy thao tác
                    </button>
                </div>
            </form>
        </section>
    @endif

    <section class="bs-ad-panel" aria-labelledby="branch-audit-history-heading">
        <div class="bs-ad-subcard-head">
            <div>
                <h2 id="branch-audit-history-heading" class="bs-ad-panel-title">Nhật ký hoạt động gần đây</h2>
                <p class="bs-ad-panel-subtitle">Lịch sử thay đổi thông tin và trạng thái hoạt động của chi nhánh {{ $branch->name }}.</p>
            </div>

            @if ($canViewGlobalAuditLogs)
                <a
                    href="{{ route('admin.audit-logs.index', ['branch_id' => $branch->id]) }}"
                    class="bs-ad-btn-outline"
                >
                    Xem tất cả lịch sử
                </a>
            @endif
        </div>

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
                            <article class="bs-ad-audit-item"
                                     data-item-id="{{ $audit->id }}">
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
    </section>

    <section class="bs-ad-panel bs-ad-panel-surface" aria-label="Phạm vi giai đoạn hiện tại">
        <h2 class="bs-ad-panel-title">Phạm vi phân hệ liên quan</h2>
        <p class="bs-ad-panel-subtitle">
            Phân công nhân sự chi nhánh, quản trị tồn kho FIFO và điều phối nhập/xuất hàng sẽ được mở ở các giai đoạn tiếp theo sau khi hoàn tất cấu trúc chi nhánh bán lẻ.
        </p>
    </section>

    @include('admin.audit-logs.partials.drawer')
@endsection
