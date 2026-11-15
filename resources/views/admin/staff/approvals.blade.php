{{--
    Hàng đợi Phê duyệt Đề xuất Kích hoạt Nhân sự (Stage 01B.5.1)
    - Entry point: GET /quan-tri/nhan-su/phe-duyet -> StaffApprovalController::index
    - Approve: POST /quan-tri/nhan-su/phe-duyet/{proposal}/duyet -> StaffApprovalController::approve
    - Reject: POST /quan-tri/nhan-su/phe-duyet/{proposal}/tu-choi -> StaffApprovalController::reject
    - Tất cả khung xác nhận phê duyệt/từ chối khởi tạo ở trạng thái đóng, chỉ mở khi người dùng nhấn kích hoạt và khóa nút gửi cho đến khi tick xác nhận.
--}}
@extends('layouts.admin')

@section('title', 'Phê duyệt Kích hoạt Nhân sự — Book_Store')

@section('content')
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <a href="{{ route('admin.staff.index') }}">Nhân sự</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Phê duyệt kích hoạt</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Kiểm soát Phân tách Quyền hạn</p>
            <h1 class="bs-ad-title">Hàng đợi Phê duyệt Nhân sự</h1>
            <p class="bs-ad-subtitle">
                Chỉ người có thẩm quyền phê duyệt (Super Admin) và không phải người được đề xuất hoặc người tự đề xuất mới được duyệt hoặc từ chối kích hoạt.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">
                Danh sách nhân sự
            </a>
        </div>
    </div>

    <section class="bs-ad-identity-grid" aria-label="Thống kê đề xuất và nhân sự chờ kích hoạt">
        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Đề xuất chờ phê duyệt</p>
            <p class="bs-ad-stat-value">{{ $queueSummary['pendingProposals'] ?? 0 }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Nhân sự chờ kích hoạt</p>
            <p class="bs-ad-stat-value">{{ $queueSummary['pendingStaffTotal'] ?? 0 }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Chờ kích hoạt (chưa gửi đề xuất)</p>
            <p class="bs-ad-stat-value">{{ $queueSummary['pendingStaffWithoutProposal'] ?? 0 }}</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Tổng đề xuất đã ghi nhận</p>
            <p class="bs-ad-stat-value">{{ $queueSummary['totalProposals'] ?? 0 }}</p>
        </article>
    </section>

    <section class="bs-ad-panel" aria-labelledby="approval-filter-heading">
        <h2 id="approval-filter-heading" class="bs-ad-panel-title">Lọc trạng thái đề xuất</h2>
        <form method="GET" action="{{ route('admin.staff.approvals.index') }}" class="bs-ad-filter-form">
            <div class="bs-ad-field">
                <label for="proposal-status-filter" class="bs-ad-label">Trạng thái đề xuất</label>
                <select id="proposal-status-filter" name="status" class="bs-ad-select">
                    <option value="">Tất cả trạng thái</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option->value }}" @selected($statusFilter === $option->value)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="bs-ad-page-actions">
                <button type="submit" class="bs-ad-btn-primary">Lọc đề xuất</button>
                @if ($statusFilter !== '')
                    <a href="{{ route('admin.staff.approvals.index') }}" class="bs-ad-btn-outline">Đặt lại</a>
                @endif
            </div>
        </form>
    </section>

    <section class="bs-ad-panel" aria-labelledby="approval-list-heading">
        <h2 id="approval-list-heading" class="bs-ad-panel-title">Đề xuất chờ phê duyệt ({{ $queueSummary['pendingProposals'] ?? 0 }})</h2>
        <p class="bs-ad-panel-subtitle">
            Hiển thị các bản ghi đề xuất kích hoạt (`staff_activation_proposals`) đã được gửi lên hệ thống (Tổng {{ $proposals->total() }} bản ghi theo bộ lọc).
        </p>

        @if ($proposals->isEmpty())
            <div class="bs-ad-empty-state">
                <h3 class="bs-ad-panel-title">Chưa có đề xuất nào trong danh sách</h3>
                <p class="bs-ad-panel-subtitle">
                    Khi Quản trị chi nhánh gửi đề xuất kích hoạt nhân sự, bản ghi đề xuất sẽ xuất hiện tại đây. Nếu có tài khoản nhân sự đang ở trạng thái <strong>Chờ kích hoạt quyền nhân viên</strong> nhưng chưa có đề xuất, vui lòng xem mục <strong>Nhân sự chờ kích hoạt</strong> bên dưới.
                </p>
            </div>
        @else
            <div class="bs-ad-stack">
                @foreach ($proposals as $proposal)
                    @php
                        $proposalPillClass = match ($proposal->status) {
                            \App\Enums\StaffApprovalStatus::Approved => 'bs-ad-pill-active',
                            \App\Enums\StaffApprovalStatus::Pending => 'bs-ad-pill-warning',
                            default => 'bs-ad-pill-inactive',
                        };
                        $targetUser = $proposal->user;
                        $canReviewThis = $proposal->status === \App\Enums\StaffApprovalStatus::Pending
                            && auth()->user()?->can('review', $proposal);
                        $approveScope = 'approve_proposal_' . $proposal->id;
                        $rejectScope = 'reject_proposal_' . $proposal->id;
                        $openApproveInitial = old('action_scope') === $approveScope && ($errors->has('confirmed') || $errors->has('review_note'));
                        $openRejectInitial = old('action_scope') === $rejectScope && ($errors->has('confirmed') || $errors->has('review_note'));
                    @endphp
                    <article class="bs-ad-subcard">
                        <div class="bs-ad-subcard-head">
                            <div>
                                <h3 class="bs-ad-subcard-title">
                                    {{ $targetUser?->name ?? ('Nhân sự #' . $proposal->user_id) }}
                                    <span class="bs-ad-code">{{ $targetUser?->staffProfile?->staff_code ?? ('USER-' . $proposal->user_id) }}</span>
                                </h3>
                                <p class="bs-ad-help">
                                    {{ $targetUser?->email ?? '' }}
                                    @if ($proposal->branch !== null)
                                        — Chi nhánh: {{ $proposal->branch->name }} ({{ $proposal->branch->code }})
                                    @endif
                                </p>
                            </div>

                            <div class="bs-ad-tag-row">
                                <span class="bs-ad-pill {{ $proposalPillClass }}">
                                    {{ $proposal->status->label() }}
                                </span>
                                @if ($targetUser !== null)
                                    <a href="{{ route('admin.staff.show', $targetUser) }}" class="bs-ad-btn-outline">
                                        Mở hồ sơ nhân sự
                                    </a>
                                @endif
                            </div>
                        </div>

                        <dl class="bs-ad-meta-list">
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Người đề xuất:</dt>
                                <dd class="bs-ad-meta-value">
                                    {{ $proposal->proposedBy?->name ?? 'Hệ thống' }} ({{ format_datetime($proposal->proposed_at) }})
                                </dd>
                            </div>
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Lý do đề xuất:</dt>
                                <dd class="bs-ad-meta-value">{{ $proposal->proposal_reason ?: 'Không có ghi chú thêm' }}</dd>
                            </div>
                            @if ($proposal->reviewed_at !== null)
                                <div class="bs-ad-meta-row">
                                    <dt class="bs-ad-meta-label">Người xử lý:</dt>
                                    <dd class="bs-ad-meta-value">
                                        {{ $proposal->reviewedBy?->name ?? 'Không xác định' }} ({{ format_datetime($proposal->reviewed_at) }})
                                    </dd>
                                </div>
                                <div class="bs-ad-meta-row">
                                    <dt class="bs-ad-meta-label">Ghi chú phê duyệt:</dt>
                                    <dd class="bs-ad-meta-value">{{ $proposal->review_note ?: 'Không có' }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ($canReviewThis)
                            <div class="bs-ad-stack">
                                <div data-confirm-disclosure data-initial-open="{{ $openApproveInitial ? 'true' : 'false' }}">
                                    <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                        <button
                                            type="button"
                                            class="bs-ad-btn-primary"
                                            data-confirm-open
                                            aria-expanded="{{ $openApproveInitial ? 'true' : 'false' }}"
                                            aria-controls="approve-panel-{{ $proposal->id }}"
                                        >
                                            Phê duyệt kích hoạt
                                        </button>
                                    </div>

                                    <form
                                        id="approve-panel-{{ $proposal->id }}"
                                        method="POST"
                                        action="{{ route('admin.staff.approvals.approve', $proposal) }}"
                                        data-confirm-panel
                                        class="bs-ad-confirm-box{{ $openApproveInitial ? '' : ' bs-hidden' }}"
                                        @if (! $openApproveInitial) x-cloak @endif
                                        aria-hidden="{{ $openApproveInitial ? 'false' : 'true' }}"
                                    >
                                        @csrf
                                        <input type="hidden" name="action_scope" value="{{ $approveScope }}">

                                        <div class="bs-ad-field">
                                            <label for="approve-note-{{ $proposal->id }}" class="bs-ad-label">Ghi chú phê duyệt (tùy chọn)</label>
                                            <textarea
                                                id="approve-note-{{ $proposal->id }}"
                                                name="review_note"
                                                maxlength="500"
                                                data-confirm-reset-input
                                                placeholder="Ghi chú quyết định kích hoạt..."
                                                class="bs-ad-textarea"
                                            >{{ $openApproveInitial ? old('review_note') : '' }}</textarea>
                                            @if ($openApproveInitial)
                                                @error('review_note')
                                                    <p class="bs-ad-field-error">{{ $message }}</p>
                                                @enderror
                                            @endif
                                        </div>

                                        <label class="bs-ad-check-row">
                                            <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openApproveInitial && old('confirmed'))>
                                            <span>Tôi xác nhận đã kiểm tra thông tin nhân sự và phê duyệt kích hoạt quyền truy cập nội bộ.</span>
                                        </label>
                                        @if ($openApproveInitial)
                                            @error('confirmed')
                                                <p class="bs-ad-field-error">{{ $message }}</p>
                                            @enderror
                                        @endif

                                        <div class="bs-ad-disclosure-actions">
                                            <button type="submit" class="bs-ad-btn-primary" data-confirm-submit @disabled(! $openApproveInitial)>
                                                Xác nhận phê duyệt
                                            </button>
                                            <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                                Hủy thao tác
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <div data-confirm-disclosure data-initial-open="{{ $openRejectInitial ? 'true' : 'false' }}">
                                    <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                        <button
                                            type="button"
                                            class="bs-ad-btn-danger-outline"
                                            data-confirm-open
                                            aria-expanded="{{ $openRejectInitial ? 'true' : 'false' }}"
                                            aria-controls="reject-panel-{{ $proposal->id }}"
                                        >
                                            Từ chối đề xuất
                                        </button>
                                    </div>

                                    <form
                                        id="reject-panel-{{ $proposal->id }}"
                                        method="POST"
                                        action="{{ route('admin.staff.approvals.reject', $proposal) }}"
                                        data-confirm-panel
                                        class="bs-ad-confirm-box{{ $openRejectInitial ? '' : ' bs-hidden' }}"
                                        @if (! $openRejectInitial) x-cloak @endif
                                        aria-hidden="{{ $openRejectInitial ? 'false' : 'true' }}"
                                    >
                                        @csrf
                                        <input type="hidden" name="action_scope" value="{{ $rejectScope }}">

                                        <div class="bs-ad-field">
                                            <label for="reject-note-{{ $proposal->id }}" class="bs-ad-label">Lý do từ chối (bắt buộc tối thiểu 5 ký tự)</label>
                                            <textarea
                                                id="reject-note-{{ $proposal->id }}"
                                                name="review_note"
                                                minlength="5"
                                                maxlength="500"
                                                required
                                                data-confirm-reset-input
                                                placeholder="Nêu rõ lý do từ chối để chi nhánh bổ sung..."
                                                class="bs-ad-textarea"
                                            >{{ $openRejectInitial ? old('review_note') : '' }}</textarea>
                                            @if ($openRejectInitial)
                                                @error('review_note')
                                                    <p class="bs-ad-field-error">{{ $message }}</p>
                                                @enderror
                                            @endif
                                        </div>

                                        <label class="bs-ad-check-row">
                                            <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openRejectInitial && old('confirmed'))>
                                            <span>Tôi xác nhận từ chối đề xuất kích hoạt này và giữ nhân sự ở trạng thái Chờ kích hoạt.</span>
                                        </label>
                                        @if ($openRejectInitial)
                                            @error('confirmed')
                                                <p class="bs-ad-field-error">{{ $message }}</p>
                                            @enderror
                                        @endif

                                        <div class="bs-ad-disclosure-actions">
                                            <button type="submit" class="bs-ad-btn-danger-outline" data-confirm-submit @disabled(! $openRejectInitial)>
                                                Xác nhận từ chối
                                            </button>
                                            <button type="button" class="bs-ad-btn-outline" data-confirm-cancel>
                                                Hủy thao tác
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @elseif ($proposal->status === \App\Enums\StaffApprovalStatus::Pending)
                            <div class="bs-ad-info-banner">
                                Bạn không đủ thẩm quyền hoặc không thể tự duyệt đề xuất liên quan đến chính mình.
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>

            <div class="bs-ad-pagination">
                {{ $proposals->links() }}
            </div>
        @endif
    </section>

    <section class="bs-ad-panel" aria-labelledby="pending-staff-list-heading">
        <h2 id="pending-staff-list-heading" class="bs-ad-panel-title">Nhân sự chờ kích hoạt ({{ $queueSummary['pendingStaffTotal'] ?? 0 }})</h2>
        <p class="bs-ad-panel-subtitle">
            Danh sách toàn bộ tài khoản nhân sự đang có <code>staff_access_status = pending</code> (Chờ kích hoạt quyền nhân viên), bao gồm cả các tài khoản chưa gửi đề xuất lên hàng đợi.
        </p>

        @if (! isset($pendingStaffMembers) || $pendingStaffMembers->isEmpty())
            <div class="bs-ad-empty-state">
                <h3 class="bs-ad-panel-title">Không có nhân sự nào đang chờ kích hoạt</h3>
                <p class="bs-ad-panel-subtitle">Tất cả tài khoản nhân sự hiện tại đã được kích hoạt hoặc đang ở trạng thái ngừng hoạt động.</p>
            </div>
        @else
            <div class="bs-ad-stack">
                @foreach ($pendingStaffMembers as $pendingStaff)
                    @php
                        $staffPendingProposal = $pendingStaff->staffActivationProposals
                            ->first(fn ($p): bool => $p->status === \App\Enums\StaffApprovalStatus::Pending);
                        $readiness = $readinessByStaffId[$pendingStaff->id] ?? [
                            'ready' => false,
                            'reason' => 'Chưa đủ điều kiện kích hoạt.',
                        ];
                        $canDirectActivateThis = $readiness['ready']
                            && auth()->user()?->can('directActivateStaff', $pendingStaff);
                        $directScope = 'direct_activate_staff_' . $pendingStaff->id;
                        $openDirectInitial = old('action_scope') === $directScope && ($errors->has('confirmed') || $errors->has('review_note'));
                        $activeAssignments = $pendingStaff->branchAssignments
                            ->filter(fn ($a): bool => $a->status === \App\Enums\BranchAssignmentStatus::Active && $a->branch !== null);
                    @endphp
                    <article class="bs-ad-subcard">
                        <div class="bs-ad-subcard-head">
                            <div>
                                <h3 class="bs-ad-subcard-title">
                                    {{ $pendingStaff->name }}
                                    <span class="bs-ad-code">{{ $pendingStaff->staffProfile?->staff_code ?? ('USER-' . $pendingStaff->id) }}</span>
                                </h3>
                                <p class="bs-ad-help">
                                    {{ $pendingStaff->email }}
                                    @if ($pendingStaff->staffProfile?->job_title)
                                        — {{ $pendingStaff->staffProfile->job_title }}
                                    @endif
                                </p>
                            </div>

                            <div class="bs-ad-tag-row">
                                <span class="bs-ad-pill bs-ad-pill-warning">
                                    {{ $pendingStaff->staff_access_status?->label() ?? 'Chờ kích hoạt quyền nhân viên' }}
                                </span>
                                @if ($staffPendingProposal !== null)
                                    <span class="bs-ad-pill bs-ad-pill-warning">Đã có đề xuất chờ duyệt</span>
                                @else
                                    <span class="bs-ad-pill bs-ad-pill-neutral">Chưa gửi đề xuất</span>
                                @endif
                                <a href="{{ route('admin.staff.show', $pendingStaff) }}" class="bs-ad-btn-outline">
                                    Mở hồ sơ nhân sự
                                </a>
                            </div>
                        </div>

                        <dl class="bs-ad-meta-list">
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Đổi mật khẩu lần đầu:</dt>
                                <dd class="bs-ad-meta-value">
                                    @if (! $pendingStaff->must_change_password)
                                        Đã hoàn tất đổi mật khẩu onboarding
                                    @else
                                        Chưa hoàn tất đổi mật khẩu tạm thời
                                    @endif
                                </dd>
                            </div>
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Phân công &amp; Vai trò hiện tại:</dt>
                                <dd class="bs-ad-meta-value">
                                    @if ($activeAssignments->isEmpty())
                                        Chưa có phân công chi nhánh đang hoạt động
                                    @else
                                        @foreach ($activeAssignments as $assignment)
                                            @php
                                                $branchRoleNames = $pendingStaff->branchRoleAssignments
                                                    ->filter(fn ($bra): bool => (int) $bra->branch_id === (int) $assignment->branch_id && $bra->role !== null)
                                                    ->map(fn ($bra): string => $bra->role->name)
                                                    ->values()
                                                    ->all();
                                            @endphp
                                            <span class="bs-ad-pill bs-ad-pill-neutral">
                                                {{ $assignment->branch->name }} ({{ $assignment->branch->code }}):
                                                {{ $branchRoleNames !== [] ? implode(', ', $branchRoleNames) : 'Chưa gán vai trò' }}
                                            </span>
                                        @endforeach
                                    @endif
                                </dd>
                            </div>
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Chẩn đoán điều kiện kích hoạt:</dt>
                                <dd class="bs-ad-meta-value">{{ $readiness['reason'] }}</dd>
                            </div>
                        </dl>

                        @if ($canDirectActivateThis)
                            <div data-confirm-disclosure data-initial-open="{{ $openDirectInitial ? 'true' : 'false' }}">
                                <div data-confirm-trigger-wrap class="bs-ad-disclosure-actions">
                                    <button
                                        type="button"
                                        class="bs-ad-btn-primary"
                                        data-confirm-open
                                        aria-expanded="{{ $openDirectInitial ? 'true' : 'false' }}"
                                        aria-controls="direct-activate-panel-{{ $pendingStaff->id }}"
                                    >
                                        Kích hoạt trực tiếp (Super Admin)
                                    </button>
                                </div>

                                <form
                                    id="direct-activate-panel-{{ $pendingStaff->id }}"
                                    method="POST"
                                    action="{{ route('admin.staff.activate-direct', $pendingStaff) }}"
                                    data-confirm-panel
                                    class="bs-ad-confirm-box{{ $openDirectInitial ? '' : ' bs-hidden' }}"
                                    @if (! $openDirectInitial) x-cloak @endif
                                    aria-hidden="{{ $openDirectInitial ? 'false' : 'true' }}"
                                >
                                    @csrf
                                    <input type="hidden" name="action_scope" value="{{ $directScope }}">
                                    <input type="hidden" name="return_to" value="approvals">

                                    <div class="bs-ad-field">
                                        <label for="direct-activate-note-{{ $pendingStaff->id }}" class="bs-ad-label">Ghi chú kích hoạt trực tiếp (tùy chọn)</label>
                                        <textarea
                                            id="direct-activate-note-{{ $pendingStaff->id }}"
                                            name="review_note"
                                            maxlength="500"
                                            data-confirm-reset-input
                                            placeholder="Ghi chú lý do kích hoạt trực tiếp hồ sơ nhân sự..."
                                            class="bs-ad-textarea"
                                        >{{ $openDirectInitial ? old('review_note') : '' }}</textarea>
                                        @if ($openDirectInitial)
                                            @error('review_note')
                                                <p class="bs-ad-field-error">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <label class="bs-ad-check-row">
                                        <input type="checkbox" name="confirmed" value="1" data-confirm-checkbox @checked($openDirectInitial && old('confirmed'))>
                                        <span>Tôi xác nhận đã kiểm tra phân công chi nhánh, vai trò và trạng thái đổi mật khẩu của {{ $pendingStaff->name }} để kích hoạt trực tiếp quyền nhân sự.</span>
                                    </label>
                                    @if ($openDirectInitial)
                                        @error('confirmed')
                                            <p class="bs-ad-field-error">{{ $message }}</p>
                                        @enderror
                                    @endif

                                    <div class="bs-ad-disclosure-actions">
                                        <button type="submit" class="bs-ad-btn-primary" data-confirm-submit @disabled(! $openDirectInitial)>
                                            Xác nhận kích hoạt trực tiếp
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

            <div class="bs-ad-pagination">
                {{ $pendingStaffMembers->links() }}
            </div>
        @endif
    </section>
@endsection
