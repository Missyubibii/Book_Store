{{--
    Chi tiết Hồ sơ Nhân sự, Phân công Đa chi nhánh & Kiểm soát Trạng thái (Stage 01B.5.1)
    - Entry point: GET /quan-tri/nhan-su/{staff} (`admin.staff.show`) -> StaffController::show
    - Gồm các luồng có kiểm soát giao dịch nguyên tử:
      1. Cập nhật phân công chi nhánh & đa vai trò (`admin.staff.assignments.upsert`)
      2. Gỡ phân công chi nhánh với hộp xác nhận đóng ban đầu (`admin.staff.assignments.deactivate`)
      3. Gửi đề xuất kích hoạt (`admin.staff.proposals.store`) hoặc Phê duyệt/Từ chối nếu có đề xuất chờ duyệt
      4. Đình chỉ / Khôi phục quyền nhân sự (`admin.staff.suspend`, `admin.staff.reactivate`) giữ nguyên tài khoản khách hàng
--}}
@extends('layouts.admin')

@section('title', 'Hồ sơ Nhân sự: ' . $staff->name . ' — Book_Store')

@section('content')
    @php
        $staffPillClass = match ($staff->staff_access_status) {
            \App\Enums\StaffAccessStatus::Active => 'bs-ad-pill-active',
            \App\Enums\StaffAccessStatus::Pending => 'bs-ad-pill-warning',
            default => 'bs-ad-pill-inactive',
        };
        $accountPillClass = $staff->account_status === \App\Enums\AccountStatus::Active
            ? 'bs-ad-pill-active'
            : 'bs-ad-pill-inactive';
        $pendingApproveScope = $pendingProposal !== null ? 'approve_pending_' . $pendingProposal->id : '';
        $pendingRejectScope = $pendingProposal !== null ? 'reject_pending_' . $pendingProposal->id : '';
        $openPendingApprove = $pendingProposal !== null && old('action_scope') === $pendingApproveScope && ($errors->has('confirmed') || $errors->has('review_note'));
        $openPendingReject = $pendingProposal !== null && old('action_scope') === $pendingRejectScope && ($errors->has('confirmed') || $errors->has('review_note'));
        $openSuspendConfirm = old('action_scope') === 'suspend_staff_global' && ($errors->has('confirmed') || $errors->has('reason'));
        $openReactivateConfirm = old('action_scope') === 'reactivate_staff_global' && ($errors->has('confirmed') || $errors->has('reason'));
        $openDirectActivate = old('action_scope') === 'direct_activate_staff_detail' && ($errors->has('confirmed') || $errors->has('review_note'));
        $openUpsertAssignment = old('_form') === 'upsert_assignment' && $errors->any();
        $openProposeForm = old('_form') === 'propose_activation' && $errors->any();
    @endphp

    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <a href="{{ route('admin.staff.index') }}">Nhân sự</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $staff->name }}</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Hồ sơ Nhân sự &amp; Thẩm quyền Chi nhánh</p>
            <h1 class="bs-ad-title">{{ $staff->name }}</h1>
            <p class="bs-ad-subtitle">
                Mã nhân sự <span class="bs-ad-code">{{ $staff->staffProfile?->staff_code ?? ('USER-' . $staff->id) }}</span>
                — {{ $staff->email }}
            </p>
        </div>

        <div class="bs-ad-page-actions">
            <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">
                Quay lại danh bạ
            </a>
        </div>
    </div>

    <section class="bs-ad-identity-grid" aria-label="Trạng thái và định danh nhân sự">
        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Trạng thái truy cập nhân sự</p>
            <p class="bs-ad-stat-value">
                <span class="bs-ad-pill {{ $staffPillClass }}">
                    {{ $staff->staff_access_status?->label() ?? 'Không xác định' }}
                </span>
            </p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Trạng thái tài khoản khách hàng</p>
            <p class="bs-ad-stat-value">
                <span class="bs-ad-pill {{ $accountPillClass }}">
                    {{ $staff->account_status?->label() ?? 'Đang hoạt động' }}
                </span>
            </p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Mật khẩu tạm / Bắt buộc đổi</p>
            <p class="bs-ad-stat-value">
                @if (! $staff->must_change_password)
                    Không yêu cầu đổi mật khẩu
                @elseif ($staff->isTemporaryPasswordExpired())
                    Đã hết hạn ({{ format_datetime($staff->temp_password_expires_at) }})
                @elseif ($staff->temp_password_expires_at !== null)
                    Còn hiệu lực đến {{ format_datetime($staff->temp_password_expires_at) }}
                @else
                    Bắt buộc đổi ở lần đăng nhập kế tiếp
                @endif
            </p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Chức danh &amp; Nhận việc</p>
            <p class="bs-ad-stat-value">
                {{ $staff->staffProfile?->job_title ?: 'Chưa cập nhật' }}
                @if ($staff->staffProfile?->hired_at)
                    — {{ format_date($staff->staffProfile->hired_at) }}
                @endif
            </p>
        </article>
    </section>

    <div class="bs-ad-staff-detail-grid">
        <div class="bs-ad-stack">
            <section class="bs-ad-panel" aria-labelledby="assignments-heading">
                <div class="bs-ad-subcard-head">
                    <div>
                        <h2 id="assignments-heading" class="bs-ad-panel-title">Tóm tắt Phân công đã lưu &amp; Vai trò hiệu lực</h2>
                        <p class="bs-ad-panel-subtitle">
                            Mỗi vai trò chi nhánh gắn chặt theo cặp (nhân sự, chi nhánh). Chi nhánh ngừng hoạt động sẽ làm quyền hiệu lực tại chi nhánh đó về 0.
                        </p>
                    </div>
                    @if ($latestAssignmentUpdatedAt !== null)
                        <span class="bs-ad-pill bs-ad-pill-neutral">
                            Cập nhật gần nhất: {{ format_datetime($latestAssignmentUpdatedAt) }}
                        </span>
                    @endif
                </div>

                <div class="bs-ad-diagnostic-box">
                    <p class="bs-ad-meta-row">
                        <span class="bs-ad-meta-label">Hiệu lực truy cập thực tế:</span>
                        <span class="bs-ad-meta-value">{{ $effectiveAccessSummary }}</span>
                    </p>
                    <p class="bs-ad-help">{{ $nextActionGuide }}</p>
                </div>

                @if ($assignmentsWithRoles === [])
                    <div class="bs-ad-info-banner">Nhân sự này chưa có phân công chi nhánh nào.</div>
                @else
                    <div class="bs-ad-stack">
                        @foreach ($assignmentsWithRoles as $item)
                            @php
                                $assignment = $item['assignment'];
                                $branch = $item['branch'];
                                $roles = $item['roles'];
                                $effectivePerms = $item['effectivePermissions'];
                                $isActiveAssignment = $assignment->status === \App\Enums\BranchAssignmentStatus::Active;
                                $isCentralWarehouse = $branch->type === \App\Enums\BranchType::CentralWarehouse;
                                $deactivateScope = 'deactivate_branch_' . $branch->id;
                                $openDeactivate = old('action_scope') === $deactivateScope && ($errors->has('confirmed') || $errors->has('reason'));
                            @endphp
                            <article class="bs-ad-subcard">
                                <div class="bs-ad-subcard-head">
                                    <div>
                                        <h3 class="bs-ad-subcard-title">
                                            {{ $branch->name }}
                                            <span class="bs-ad-code">{{ $branch->code }}</span>
                                        </h3>
                                        <p class="bs-ad-help">
                                            Loại đơn vị: <strong>{{ $branch->type->label() }}</strong>
                                            — Trạng thái đơn vị: {{ $branch->status->label() }}
                                            @if ($assignment->is_primary)
                                                — Đơn vị chính
                                            @endif
                                        </p>
                                    </div>

                                    <span class="bs-ad-pill {{ $isActiveAssignment ? 'bs-ad-pill-active' : 'bs-ad-pill-inactive' }}">
                                        {{ $assignment->status->label() }}
                                    </span>
                                </div>

                                <div class="bs-ad-tag-row">
                                    @forelse ($roles as $role)
                                        <span class="bs-ad-pill bs-ad-pill-neutral">{{ $role->name }}</span>
                                    @empty
                                        <span class="bs-ad-help">Chưa gán vai trò chi nhánh nào.</span>
                                    @endforelse
                                </div>

                                <p class="bs-ad-help">
                                    Cập nhật lúc: <strong>{{ format_datetime($assignment->updated_at ?? $assignment->assigned_at) }}</strong>
                                    — Số quyền hiệu lực tại đơn vị này: <strong>{{ count($effectivePerms) }}</strong>
                                    @if (! $branch->isActive())
                                        (Đơn vị đang ngừng hoạt động nên quyền hiệu lực bị thu hồi tạm thời).
                                    @endif
                                </p>

                                @if ($isCentralWarehouse)
                                    <div class="bs-ad-warning-banner">
                                        <strong>Lưu ý Kho trung tâm ({{ $branch->code }}):</strong> Vai trò Quản lý chi nhánh / Nhân viên chi nhánh không tự động cấp quyền truy cập màn hình quản trị Kho trung tâm. Tính năng phân quyền Quản lý Kho chuyên biệt đang chờ chủ sở hữu phê duyệt.
                                    </div>
                                @endif

                                @if ($isActiveAssignment && $item['canManageAssignment'])
                                    <div data-confirm-disclosure data-initial-open="{{ $openDeactivate ? 'true' : 'false' }}">
                                        <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                            <button
                                                type="button"
                                                class="bs-ad-btn-danger-outline"
                                                data-confirm-open
                                                aria-expanded="{{ $openDeactivate ? 'true' : 'false' }}"
                                                aria-controls="deactivate-branch-{{ $branch->id }}"
                                            >
                                                Gỡ phân công chi nhánh
                                            </button>
                                        </div>

                                        <form
                                            id="deactivate-branch-{{ $branch->id }}"
                                            method="POST"
                                            action="{{ route('admin.staff.assignments.deactivate', [$staff, $branch]) }}"
                                            data-confirm-panel
                                            class="bs-ad-confirm-box{{ $openDeactivate ? '' : ' bs-hidden' }}"
                                            @if (! $openDeactivate) x-cloak @endif
                                            aria-hidden="{{ $openDeactivate ? 'false' : 'true' }}"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="action_scope" value="{{ $deactivateScope }}">

                                            <div class="bs-ad-field">
                                                <label for="deactivate-reason-{{ $branch->id }}" class="bs-ad-label">Lý do gỡ phân công (tối thiểu 5 ký tự)</label>
                                                <textarea
                                                    id="deactivate-reason-{{ $branch->id }}"
                                                    name="reason"
                                                    minlength="5"
                                                    maxlength="500"
                                                    required
                                                    data-confirm-reset-input
                                                    placeholder="Ví dụ: Điều chuyển nhân sự hoặc kết thúc phân công tại chi nhánh..."
                                                    class="bs-ad-textarea"
                                                >{{ $openDeactivate ? old('reason') : '' }}</textarea>
                                                @if ($openDeactivate)
                                                    @error('reason')
                                                        <p class="bs-ad-field-error">{{ $message }}</p>
                                                    @enderror
                                                @endif
                                            </div>

                                            <label class="bs-ad-check-row">
                                                <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openDeactivate && old('confirmed'))>
                                                <span>Tôi xác nhận gỡ phân công tại chi nhánh {{ $branch->name }}; nếu là chi nhánh hoạt động cuối cùng (và không có vai trò toàn hệ thống), quyền nhân sự sẽ chuyển Ngừng hoạt động.</span>
                                            </label>
                                            @if ($openDeactivate)
                                                @error('confirmed')
                                                    <p class="bs-ad-field-error">{{ $message }}</p>
                                                @enderror
                                            @endif

                                            <div class="bs-ad-disclosure-actions">
                                                <button type="submit" class="bs-ad-btn-danger-outline" data-confirm-submit @disabled(! $openDeactivate)>
                                                    Xác nhận gỡ phân công
                                                </button>
                                                <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                                    Hủy thao tác
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($assignableBranches->isNotEmpty() && auth()->id() !== $staff->id)
                <section
                    class="bs-ad-panel bs-ad-panel-surface"
                    aria-labelledby="upsert-assignment-heading"
                    data-form-disclosure
                    data-initial-open="{{ $openUpsertAssignment ? 'true' : 'false' }}"
                >
                    <div class="bs-ad-panel-head">
                        <div>
                            <h2 id="upsert-assignment-heading" class="bs-ad-panel-title">Cập nhật hoặc bổ sung phân công chi nhánh</h2>
                            <p class="bs-ad-panel-subtitle">
                                Chọn chi nhánh đang hoạt động và đồng bộ tập vai trò chi nhánh cho nhân sự tại chi nhánh đó.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="bs-ad-btn-outline"
                            data-disclosure-toggle
                            data-label-open="Chỉnh sửa phân công"
                            data-label-close="Đóng chỉnh sửa"
                            aria-expanded="{{ $openUpsertAssignment ? 'true' : 'false' }}"
                            aria-controls="staff-upsert-assignment-panel"
                        >
                            <span data-disclosure-label>{{ $openUpsertAssignment ? 'Đóng chỉnh sửa' : 'Chỉnh sửa phân công' }}</span>
                        </button>
                    </div>

                    <div
                        id="staff-upsert-assignment-panel"
                        data-disclosure-panel
                        aria-hidden="{{ $openUpsertAssignment ? 'false' : 'true' }}"
                        @class(['bs-ad-disclosure-body', 'bs-hidden' => ! $openUpsertAssignment])
                    >
                        <form method="POST" action="{{ route('admin.staff.assignments.upsert', $staff) }}" data-admin-form>
                            @csrf
                            <input type="hidden" name="_form" value="upsert_assignment">

                        <div class="bs-ad-form-grid">
                            <div class="bs-ad-field bs-ad-field-full">
                                <label for="assign-branch-id" class="bs-ad-label">Chi nhánh áp dụng</label>
                                <select
                                    id="assign-branch-id"
                                    name="branch_id"
                                    required
                                    class="bs-ad-select @error('branch_id') bs-ad-input-error @enderror"
                                >
                                    <option value="">-- Chọn chi nhánh đang hoạt động --</option>
                                    @foreach ($assignableBranches as $branch)
                                        <option value="{{ $branch->id }}" @selected((int) old('branch_id') === (int) $branch->id)>
                                            {{ $branch->name }} ({{ $branch->code }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('branch_id')
                                    <p class="bs-ad-field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="bs-ad-field bs-ad-field-full">
                                <span class="bs-ad-label">Vai trò tại chi nhánh</span>
                                @php
                                    $selectedAssignRoleIds = array_map('intval', (array) old('role_ids', []));
                                @endphp
                                <div class="bs-ad-role-check-grid">
                                    @foreach ($branchRoles as $role)
                                        <label class="bs-ad-role-check-card">
                                            <input
                                                type="checkbox"
                                                name="role_ids[]"
                                                value="{{ $role->id }}"
                                                @checked(in_array((int) $role->id, $selectedAssignRoleIds, true))
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
                            </div>

                            <div class="bs-ad-field bs-ad-field-full">
                                <label class="bs-ad-check-row">
                                    <input type="checkbox" name="is_primary" value="1" @checked((bool) old('is_primary'))>
                                    <span>Đặt làm chi nhánh công tác chính</span>
                                </label>
                            </div>
                        </div>

                        <div class="bs-ad-form-actions">
                            <button type="submit" class="bs-ad-btn-primary" data-loading-text="Đang cập nhật phân công...">
                                <span data-submit-label>Lưu phân công &amp; vai trò</span>
                            </button>
                            <button type="button" class="bs-ad-btn-outline" data-disclosure-close>
                                Đóng chỉnh sửa
                            </button>
                        </div>
                    </form>
                    </div>
                </section>
            @endif
        </div>

        <div class="bs-ad-stack">
            <section class="bs-ad-panel" aria-labelledby="proposal-governance-heading">
                <h2 id="proposal-governance-heading" class="bs-ad-panel-title">Đề xuất &amp; Kích hoạt Nhân sự</h2>
                <p class="bs-ad-panel-subtitle">
                    Mọi đề xuất kích hoạt phải tuân thủ nguyên tắc tách biệt giữa người đề xuất/người được đề xuất và người phê duyệt cuối cùng.
                </p>

                @if ($pendingProposal !== null)
                    <div class="bs-ad-warning-banner">
                        <p class="bs-ad-panel-title">Đang có đề xuất kích hoạt Chờ duyệt</p>
                        <p class="bs-ad-help">
                            Đề xuất bởi {{ $pendingProposal->proposedBy?->name ?? 'Hệ thống' }} lúc {{ format_datetime($pendingProposal->proposed_at) }}
                            @if ($pendingProposal->branch)
                                tại {{ $pendingProposal->branch->name }} ({{ $pendingProposal->branch->code }})
                            @endif
                            .
                        </p>
                        @if ($pendingProposal->proposal_reason)
                            <p class="bs-ad-help">Lý do: {{ $pendingProposal->proposal_reason }}</p>
                        @endif
                    </div>

                    @if ($canReviewPendingProposal)
                        <div class="bs-ad-stack">
                            <div data-confirm-disclosure data-initial-open="{{ $openPendingApprove ? 'true' : 'false' }}">
                                <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                    <button
                                        type="button"
                                        class="bs-ad-btn-primary"
                                        data-confirm-open
                                        aria-expanded="{{ $openPendingApprove ? 'true' : 'false' }}"
                                        aria-controls="detail-approve-panel"
                                    >
                                        Phê duyệt đề xuất kích hoạt
                                    </button>
                                </div>

                                <form
                                    id="detail-approve-panel"
                                    method="POST"
                                    action="{{ route('admin.staff.approvals.approve', $pendingProposal) }}"
                                    data-confirm-panel
                                    class="bs-ad-confirm-box{{ $openPendingApprove ? '' : ' bs-hidden' }}"
                                    @if (! $openPendingApprove) x-cloak @endif
                                    aria-hidden="{{ $openPendingApprove ? 'false' : 'true' }}"
                                >
                                    @csrf
                                    <input type="hidden" name="action_scope" value="{{ $pendingApproveScope }}">

                                    <div class="bs-ad-field">
                                        <label for="detail-approve-note" class="bs-ad-label">Ghi chú phê duyệt (tùy chọn)</label>
                                        <textarea
                                            id="detail-approve-note"
                                            name="review_note"
                                            maxlength="500"
                                            data-confirm-reset-input
                                            class="bs-ad-textarea"
                                        >{{ $openPendingApprove ? old('review_note') : '' }}</textarea>
                                    </div>

                                    <label class="bs-ad-check-row">
                                        <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openPendingApprove && old('confirmed'))>
                                        <span>Tôi xác nhận kích hoạt quyền nhân sự cho {{ $staff->name }}.</span>
                                    </label>
                                    @if ($openPendingApprove)
                                        @error('confirmed')
                                            <p class="bs-ad-field-error">{{ $message }}</p>
                                        @enderror
                                    @endif

                                    <div class="bs-ad-disclosure-actions">
                                        <button type="submit" class="bs-ad-btn-primary" data-confirm-submit @disabled(! $openPendingApprove)>
                                            Xác nhận phê duyệt
                                        </button>
                                        <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                            Hủy thao tác
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div data-confirm-disclosure data-initial-open="{{ $openPendingReject ? 'true' : 'false' }}">
                                <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                    <button
                                        type="button"
                                        class="bs-ad-btn-danger-outline"
                                        data-confirm-open
                                        aria-expanded="{{ $openPendingReject ? 'true' : 'false' }}"
                                        aria-controls="detail-reject-panel"
                                    >
                                        Từ chối đề xuất kích hoạt
                                    </button>
                                </div>

                                <form
                                    id="detail-reject-panel"
                                    method="POST"
                                    action="{{ route('admin.staff.approvals.reject', $pendingProposal) }}"
                                    data-confirm-panel
                                    class="bs-ad-confirm-box{{ $openPendingReject ? '' : ' bs-hidden' }}"
                                    @if (! $openPendingReject) x-cloak @endif
                                    aria-hidden="{{ $openPendingReject ? 'false' : 'true' }}"
                                >
                                    @csrf
                                    <input type="hidden" name="action_scope" value="{{ $pendingRejectScope }}">

                                    <div class="bs-ad-field">
                                        <label for="detail-reject-note" class="bs-ad-label">Lý do từ chối (tối thiểu 5 ký tự)</label>
                                        <textarea
                                            id="detail-reject-note"
                                            name="review_note"
                                            minlength="5"
                                            maxlength="500"
                                            required
                                            data-confirm-reset-input
                                            class="bs-ad-textarea"
                                        >{{ $openPendingReject ? old('review_note') : '' }}</textarea>
                                        @if ($openPendingReject)
                                            @error('review_note')
                                                <p class="bs-ad-field-error">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <label class="bs-ad-check-row">
                                        <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openPendingReject && old('confirmed'))>
                                        <span>Tôi xác nhận từ chối đề xuất kích hoạt của {{ $staff->name }}.</span>
                                    </label>
                                    @if ($openPendingReject)
                                        @error('confirmed')
                                            <p class="bs-ad-field-error">{{ $message }}</p>
                                        @enderror
                                    @endif

                                    <div class="bs-ad-disclosure-actions">
                                        <button type="submit" class="bs-ad-btn-danger-outline" data-confirm-submit @disabled(! $openPendingReject)>
                                            Xác nhận từ chối
                                        </button>
                                        <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                            Hủy thao tác
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endif
                @elseif ($staff->staff_access_status === \App\Enums\StaffAccessStatus::Pending && $proposableBranches->isNotEmpty())
                    <div
                        data-form-disclosure
                        data-initial-open="{{ $openProposeForm ? 'true' : 'false' }}"
                    >
                        <div class="bs-ad-form-actions">
                            <button
                                type="button"
                                class="bs-ad-btn-outline"
                                data-disclosure-toggle
                                data-label-open="Đề xuất kích hoạt"
                                data-label-close="Đóng chỉnh sửa"
                                aria-expanded="{{ $openProposeForm ? 'true' : 'false' }}"
                                aria-controls="staff-propose-activation-panel"
                            >
                                <span data-disclosure-label>{{ $openProposeForm ? 'Đóng chỉnh sửa' : 'Đề xuất kích hoạt' }}</span>
                            </button>
                        </div>

                        <div
                            id="staff-propose-activation-panel"
                            data-disclosure-panel
                            aria-hidden="{{ $openProposeForm ? 'false' : 'true' }}"
                            @class(['bs-ad-disclosure-body', 'bs-hidden' => ! $openProposeForm])
                        >
                            <form method="POST" action="{{ route('admin.staff.proposals.store', $staff) }}" data-admin-form>
                                @csrf
                                <input type="hidden" name="_form" value="propose_activation">

                                <div class="bs-ad-stack">
                                    <div class="bs-ad-field">
                                        <label for="propose-branch-id" class="bs-ad-label">Chi nhánh gửi đề xuất</label>
                                        <select id="propose-branch-id" name="branch_id" required class="bs-ad-select">
                                            @foreach ($proposableBranches as $branch)
                                                <option value="{{ $branch->id }}" @selected($openProposeForm && (int) old('branch_id') === (int) $branch->id)>
                                                    {{ $branch->name }} ({{ $branch->code }})
                                                </option>
                                            @endforeach
                                        </select>
                                        @if ($openProposeForm)
                                            @error('branch_id')
                                                <p class="bs-ad-field-error">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <div class="bs-ad-field">
                                        <label for="propose-reason" class="bs-ad-label">Lý do đề xuất kích hoạt</label>
                                        <textarea
                                            id="propose-reason"
                                            name="proposal_reason"
                                            maxlength="500"
                                            placeholder="Ví dụ: Nhân sự đã hoàn tất đào tạo quy trình tại chi nhánh..."
                                            class="bs-ad-textarea"
                                        >{{ $openProposeForm ? old('proposal_reason') : '' }}</textarea>
                                        @if ($openProposeForm)
                                            @error('proposal_reason')
                                                <p class="bs-ad-field-error">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <div class="bs-ad-form-actions">
                                        <button type="submit" class="bs-ad-btn-primary" data-loading-text="Đang gửi đề xuất...">
                                            <span data-submit-label>Gửi đề xuất kích hoạt</span>
                                        </button>
                                        <button type="button" class="bs-ad-btn-outline" data-disclosure-close>
                                            Đóng chỉnh sửa
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @elseif ($staff->staff_access_status === \App\Enums\StaffAccessStatus::Pending)
                    <div class="bs-ad-info-banner">
                        Nhân sự đang ở trạng thái Chờ kích hoạt nhưng hiện chưa có chi nhánh hợp lệ để gửi đề xuất hoặc bạn không có quyền đề xuất.
                    </div>
                @else
                    <div class="bs-ad-info-banner">
                        Trạng thái nhân sự hiện tại: <strong>{{ $staff->staff_access_status?->label() }}</strong>.
                    </div>
                @endif

                @if ($staff->staff_access_status === \App\Enums\StaffAccessStatus::Pending && ($canDirectActivate ?? false))
                    @if (($directActivationReadiness['ready'] ?? false) === true)
                        <div data-confirm-disclosure data-initial-open="{{ $openDirectActivate ? 'true' : 'false' }}" style="margin-top: 0.85rem;">
                            <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                <button
                                    type="button"
                                    class="bs-ad-btn-primary"
                                    data-confirm-open
                                    aria-expanded="{{ $openDirectActivate ? 'true' : 'false' }}"
                                    aria-controls="detail-direct-activate-panel"
                                >
                                    Kích hoạt trực tiếp (Super Admin)
                                </button>
                            </div>

                            <form
                                id="detail-direct-activate-panel"
                                method="POST"
                                action="{{ route('admin.staff.activate-direct', $staff) }}"
                                data-confirm-panel
                                class="bs-ad-confirm-box{{ $openDirectActivate ? '' : ' bs-hidden' }}"
                                @if (! $openDirectActivate) x-cloak @endif
                                aria-hidden="{{ $openDirectActivate ? 'false' : 'true' }}"
                            >
                                @csrf
                                <input type="hidden" name="action_scope" value="direct_activate_staff_detail">

                                <div class="bs-ad-field">
                                    <label for="detail-direct-activate-note" class="bs-ad-label">Ghi chú kích hoạt trực tiếp (tùy chọn)</label>
                                    <textarea
                                        id="detail-direct-activate-note"
                                        name="review_note"
                                        maxlength="500"
                                        data-confirm-reset-input
                                        placeholder="Ghi chú lý do kích hoạt trực tiếp quyền truy cập nhân sự..."
                                        class="bs-ad-textarea"
                                    >{{ $openDirectActivate ? old('review_note') : '' }}</textarea>
                                    @if ($openDirectActivate)
                                        @error('review_note')
                                            <p class="bs-ad-field-error">{{ $message }}</p>
                                        @enderror
                                    @endif
                                </div>

                                <label class="bs-ad-check-row">
                                    <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openDirectActivate && old('confirmed'))>
                                    <span>Tôi xác nhận đã kiểm tra trạng thái đổi mật khẩu và phân công chi nhánh của {{ $staff->name }} để kích hoạt trực tiếp quyền nhân sự.</span>
                                </label>
                                @if ($openDirectActivate)
                                    @error('confirmed')
                                        <p class="bs-ad-field-error">{{ $message }}</p>
                                    @enderror
                                @endif

                                <div class="bs-ad-disclosure-actions">
                                    <button type="submit" class="bs-ad-btn-primary" data-confirm-submit @disabled(! $openDirectActivate)>
                                        Xác nhận kích hoạt trực tiếp
                                    </button>
                                    <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                        Hủy thao tác
                                    </button>
                                </div>
                            </form>
                        </div>
                    @elseif (! empty($directActivationReadiness['reason']))
                        <div class="bs-ad-info-banner" style="margin-top: 0.75rem;">
                            <strong>Điều kiện kích hoạt trực tiếp (Super Admin):</strong> {{ $directActivationReadiness['reason'] }}
                        </div>
                    @endif
                @endif
            </section>

            <section class="bs-ad-panel" aria-labelledby="access-control-heading">
                <h2 id="access-control-heading" class="bs-ad-panel-title">Kiểm soát quyền truy cập nhân sự</h2>
                <p class="bs-ad-panel-subtitle">
                    Đình chỉ quyền nhân sự chỉ khóa quyền truy cập nội bộ; tài khoản khách hàng công khai (`account_status`) vẫn được giữ độc lập.
                </p>

                <div class="bs-ad-stack">
                    @if ($canSuspendGlobally)
                        <div data-confirm-disclosure data-initial-open="{{ $openSuspendConfirm ? 'true' : 'false' }}">
                            <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                <button
                                    type="button"
                                    class="bs-ad-btn-danger-outline"
                                    data-confirm-open
                                    aria-expanded="{{ $openSuspendConfirm ? 'true' : 'false' }}"
                                    aria-controls="suspend-staff-panel"
                                >
                                    Đình chỉ quyền nhân sự
                                </button>
                            </div>

                            <form
                                id="suspend-staff-panel"
                                method="POST"
                                action="{{ route('admin.staff.suspend', $staff) }}"
                                data-confirm-panel
                                class="bs-ad-confirm-box{{ $openSuspendConfirm ? '' : ' bs-hidden' }}"
                                @if (! $openSuspendConfirm) x-cloak @endif
                                aria-hidden="{{ $openSuspendConfirm ? 'false' : 'true' }}"
                            >
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action_scope" value="suspend_staff_global">

                                <div class="bs-ad-field">
                                    <label for="suspend-staff-reason" class="bs-ad-label">Lý do đình chỉ (tối thiểu 5 ký tự)</label>
                                    <textarea
                                        id="suspend-staff-reason"
                                        name="reason"
                                        minlength="5"
                                        maxlength="500"
                                        required
                                        data-confirm-reset-input
                                        placeholder="Nêu rõ lý do đình chỉ quyền truy cập nhân sự..."
                                        class="bs-ad-textarea"
                                    >{{ $openSuspendConfirm ? old('reason') : '' }}</textarea>
                                    @if ($openSuspendConfirm)
                                        @error('reason')
                                            <p class="bs-ad-field-error">{{ $message }}</p>
                                        @enderror
                                    @endif
                                </div>

                                <label class="bs-ad-check-row">
                                    <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openSuspendConfirm && old('confirmed'))>
                                    <span>Tôi xác nhận đình chỉ quyền truy cập nội bộ của {{ $staff->name }} (vẫn giữ tài khoản khách hàng công khai).</span>
                                </label>
                                @if ($openSuspendConfirm)
                                    @error('confirmed')
                                        <p class="bs-ad-field-error">{{ $message }}</p>
                                    @enderror
                                @endif

                                <div class="bs-ad-disclosure-actions">
                                    <button type="submit" class="bs-ad-btn-danger-outline" data-confirm-submit @disabled(! $openSuspendConfirm)>
                                        Xác nhận đình chỉ
                                    </button>
                                    <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                        Hủy thao tác
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif

                    @if ($canReactivateGlobally)
                        <div data-confirm-disclosure data-initial-open="{{ $openReactivateConfirm ? 'true' : 'false' }}">
                            <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                <button
                                    type="button"
                                    class="bs-ad-btn-primary"
                                    data-confirm-open
                                    aria-expanded="{{ $openReactivateConfirm ? 'true' : 'false' }}"
                                    aria-controls="reactivate-staff-panel"
                                >
                                    Khôi phục quyền nhân sự
                                </button>
                            </div>

                            <form
                                id="reactivate-staff-panel"
                                method="POST"
                                action="{{ route('admin.staff.reactivate', $staff) }}"
                                data-confirm-panel
                                class="bs-ad-confirm-box{{ $openReactivateConfirm ? '' : ' bs-hidden' }}"
                                @if (! $openReactivateConfirm) x-cloak @endif
                                aria-hidden="{{ $openReactivateConfirm ? 'false' : 'true' }}"
                            >
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action_scope" value="reactivate_staff_global">

                                <div class="bs-ad-field">
                                    <label for="reactivate-staff-reason" class="bs-ad-label">Lý do khôi phục quyền (tối thiểu 5 ký tự)</label>
                                    <textarea
                                        id="reactivate-staff-reason"
                                        name="reason"
                                        minlength="5"
                                        maxlength="500"
                                        required
                                        data-confirm-reset-input
                                        placeholder="Nêu rõ lý do khôi phục quyền truy cập..."
                                        class="bs-ad-textarea"
                                    >{{ $openReactivateConfirm ? old('reason') : '' }}</textarea>
                                    @if ($openReactivateConfirm)
                                        @error('reason')
                                            <p class="bs-ad-field-error">{{ $message }}</p>
                                        @enderror
                                    @endif
                                </div>

                                <label class="bs-ad-check-row">
                                    <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openReactivateConfirm && old('confirmed'))>
                                    <span>Tôi xác nhận khôi phục quyền truy cập nhân sự cho {{ $staff->name }}.</span>
                                </label>
                                @if ($openReactivateConfirm)
                                    @error('confirmed')
                                        <p class="bs-ad-field-error">{{ $message }}</p>
                                    @enderror
                                @endif

                                <div class="bs-ad-disclosure-actions">
                                    <button type="submit" class="bs-ad-btn-primary" data-confirm-submit @disabled(! $openReactivateConfirm)>
                                        Xác nhận khôi phục
                                    </button>
                                    <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                        Hủy thao tác
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif

                    <div class="bs-ad-info-banner">
                        <strong>Cấp lại mật khẩu tạm (`staff.reset_temp_password`):</strong> Luồng tự sinh và bàn giao lại mật khẩu tạm qua giao diện quản trị đang được giữ ở trạng thái chờ chủ sở hữu phê duyệt kênh bàn giao an toàn trong giai đoạn tiếp theo.
                    </div>
                </div>
            </section>

            <section class="bs-ad-panel" aria-labelledby="audit-history-heading">
                <div class="bs-ad-subcard-head">
                    <div>
                        <h2 id="audit-history-heading" class="bs-ad-panel-title">Nhật ký hoạt động gần đây</h2>
                        <p class="bs-ad-panel-subtitle">Mọi thay đổi liên quan đến nhân sự được ghi bất biến và không bao giờ chứa thông tin mật.</p>
                    </div>

                    @if ($canViewGlobalAuditLogs)
                        <a
                            href="{{ route('admin.audit-logs.index', ['target_user_id' => $staff->id]) }}"
                            class="bs-ad-btn-outline"
                        >
                            Xem tất cả lịch sử
                        </a>
                    @endif
                </div>

                <div class="bs-ad-smart-scroll-wrap"
                     data-smart-scroll
                     data-endpoint="{{ route('admin.staff.audit-logs', $staff) }}"
                     data-cursor="{{ $nextAuditCursor ?? '' }}"
                     data-has-more="{{ !empty($hasMoreAudits) ? 'true' : 'false' }}"
                     data-scroll-mode="list">
                    <div class="bs-ad-smart-scroll-container bs-ad-audit-scroll"
                         data-smart-scroll-container
                         tabindex="0"
                         role="region"
                         aria-label="Danh sách nhật ký hoạt động của nhân sự {{ $staff->name }}">
                        @if ($recentAudits->isEmpty())
                            <div class="bs-ad-info-banner m-3" data-smart-scroll-empty>Chưa có bản ghi hoạt động nào cho nhân sự này.</div>
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
                                                    @if ($audit->branch !== null)
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-blue-50 text-blue-700">
                                                            {{ $audit->branch->name }}
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
        </div>
    </div>

    @include('admin.audit-logs.partials.drawer')
@endsection
