{{--
    Danh bạ Quản trị Nhân sự Book_Store (Stage 01B.5.1)
    - Entry point: GET /quan-tri/nhan-su (`admin.staff.index`) -> StaffController::index
    - Hiển thị danh sách nhân sự trong phạm vi được phép (Super Admin thấy toàn hệ thống; Quản trị chi nhánh chỉ thấy nhân sự thuộc chi nhánh được phân công).
    - Nêu rõ tách biệt giữa trạng thái tài khoản (`AccountStatus`) và trạng thái truy cập nhân sự (`StaffAccessStatus`).
--}}
@extends('layouts.admin')

@section('title', 'Quản lý Nhân sự — Book_Store')

@section('content')
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <span aria-current="page">Nhân sự</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Quản trị Nội bộ &amp; Phân công Đa chi nhánh</p>
            <h1 class="bs-ad-title">Quản lý Nhân sự</h1>
            <p class="bs-ad-subtitle">
                Khởi tạo tài khoản nhân sự mới, liên kết khách hàng hiện hữu, phân công đa chi nhánh và theo dõi đề xuất kích hoạt quyền nội bộ.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            @if ($canApproveProposals)
                <a href="{{ route('admin.staff.approvals.index') }}" class="bs-ad-btn-outline">
                    Hàng đợi duyệt ({{ $summary['pendingProposals'] }})
                </a>
            @endif

            @if ($canLinkStaff)
                <a href="{{ route('admin.staff.link.create') }}" class="bs-ad-btn-outline">
                    Liên kết khách hàng
                </a>
            @endif

            @if ($canCreateStaff)
                <a href="{{ route('admin.staff.create') }}" class="bs-ad-btn-primary">
                    Tạo nhân sự mới
                </a>
            @endif
        </div>
    </div>

    <section class="bs-ad-identity-grid" aria-label="Thống kê nhân sự">
        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Tổng nhân sự trong phạm vi</p>
            <p class="bs-ad-stat-value">{{ $summary['total'] }} nhân sự</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Đang hoạt động nội bộ</p>
            <p class="bs-ad-stat-value">{{ $summary['active'] }} nhân sự</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Chờ kích hoạt</p>
            <p class="bs-ad-stat-value">{{ $summary['pending'] }} nhân sự</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Đề xuất chờ duyệt</p>
            <p class="bs-ad-stat-value">{{ $summary['pendingProposals'] }} đề xuất</p>
        </article>
    </section>

    <section class="bs-ad-panel" aria-labelledby="staff-filter-heading">
        <h2 id="staff-filter-heading" class="bs-ad-panel-title">Tìm kiếm và lọc nhân sự</h2>
        <p class="bs-ad-panel-subtitle">
            Tra cứu theo họ tên, email, mã nhân sự hoặc chức danh; lọc theo trạng thái truy cập nhân sự và chi nhánh phân công.
        </p>

        <form method="GET" action="{{ route('admin.staff.index') }}" class="bs-ad-staff-filter-form">
            <div class="bs-ad-field">
                <label for="staff-search" class="bs-ad-label">Từ khóa tìm kiếm</label>
                <input
                    id="staff-search"
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    maxlength="100"
                    placeholder="Ví dụ: Nguyễn Văn A, NV001, email..."
                    class="bs-ad-input @error('search') bs-ad-input-error @enderror"
                >
                @error('search')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-field">
                <label for="staff-status-filter" class="bs-ad-label">Trạng thái nhân sự</label>
                <select
                    id="staff-status-filter"
                    name="status"
                    class="bs-ad-select @error('status') bs-ad-input-error @enderror"
                >
                    <option value="">Tất cả trạng thái</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option->value }}" @selected($statusFilter === $option->value)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
                @error('status')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-field">
                <label for="staff-branch-filter" class="bs-ad-label">Chi nhánh phân công</label>
                <select
                    id="staff-branch-filter"
                    name="branch_id"
                    class="bs-ad-select @error('branch_id') bs-ad-input-error @enderror"
                >
                    <option value="">Tất cả chi nhánh được phép</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $branchIdFilter === (int) $branch->id)>
                            {{ $branch->name }} ({{ $branch->code }})
                        </option>
                    @endforeach
                </select>
                @error('branch_id')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-page-actions">
                <button type="submit" class="bs-ad-btn-primary">Lọc kết quả</button>
                @if ($search !== '' || $statusFilter !== '' || $branchIdFilter !== null)
                    <a href="{{ route('admin.staff.index') }}" class="bs-ad-btn-outline">Đặt lại</a>
                @endif
            </div>
        </form>
    </section>

    <section class="bs-ad-panel" aria-labelledby="staff-list-heading">
        <div class="bs-ad-subcard-head">
            <div>
                <h2 id="staff-list-heading" class="bs-ad-panel-title">Danh sách nhân sự</h2>
                <p class="bs-ad-panel-subtitle">
                    Hiển thị {{ $staffMembers->count() }} / {{ $staffMembers->total() }} nhân sự trong phạm vi quản lý của bạn.
                </p>
            </div>
        </div>

        @if ($staffMembers->isEmpty())
            <div class="bs-ad-empty-state">
                <h3 class="bs-ad-panel-title">Không tìm thấy nhân sự phù hợp</h3>
                <p class="bs-ad-panel-subtitle">
                    Chưa có nhân sự nào khớp bộ lọc hiện tại hoặc thuộc phạm vi chi nhánh bạn phụ trách.
                </p>
                @if ($canCreateStaff)
                    <a href="{{ route('admin.staff.create') }}" class="bs-ad-btn-primary">
                        Tạo nhân sự mới
                    </a>
                @endif
            </div>
        @else
            <div class="bs-ad-branch-list">
                @foreach ($staffMembers as $member)
                    @php
                        $staffStatus = $member->staff_access_status;
                        $staffPillClass = match ($staffStatus) {
                            \App\Enums\StaffAccessStatus::Active => 'bs-ad-pill-active',
                            \App\Enums\StaffAccessStatus::Pending => 'bs-ad-pill-warning',
                            default => 'bs-ad-pill-inactive',
                        };
                        $pendingProp = $member->staffActivationProposals
                            ->first(fn ($p) => $p->status === \App\Enums\StaffApprovalStatus::Pending);
                    @endphp
                    <article class="bs-ad-branch-card">
                        <div class="bs-ad-branch-card-head">
                            <span class="bs-ad-code">{{ $member->staffProfile?->staff_code ?? ('USER-' . $member->id) }}</span>
                            <span class="bs-ad-pill {{ $staffPillClass }}">
                                {{ $staffStatus?->label() ?? 'Không xác định' }}
                            </span>
                        </div>

                        <h3 class="bs-ad-branch-title">{{ $member->name }}</h3>

                        <dl class="bs-ad-meta-list">
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Email:</dt>
                                <dd class="bs-ad-meta-value">{{ $member->email }}</dd>
                            </div>
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Chức danh:</dt>
                                <dd class="bs-ad-meta-value">{{ $member->staffProfile?->job_title ?: 'Chưa cập nhật' }}</dd>
                            </div>
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Tài khoản chung:</dt>
                                <dd class="bs-ad-meta-value">{{ $member->account_status?->label() ?? 'Đang hoạt động' }}</dd>
                            </div>
                            <div class="bs-ad-meta-row">
                                <dt class="bs-ad-meta-label">Chi nhánh:</dt>
                                <dd class="bs-ad-meta-value">
                                    @if ($member->branchAssignments->isEmpty())
                                        Chưa có phân công chi nhánh
                                    @else
                                        {{ $member->branchAssignments->filter(fn ($a) => $a->status === \App\Enums\BranchAssignmentStatus::Active)->count() }}
                                        chi nhánh đang hoạt động
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        <div class="bs-ad-tag-row">
                            @if ($member->must_change_password)
                                <span class="bs-ad-pill bs-ad-pill-warning">
                                    {{ $member->isTemporaryPasswordExpired() ? 'Mật khẩu tạm đã hết hạn' : 'Chờ đổi mật khẩu tạm' }}
                                </span>
                            @endif

                            @if ($pendingProp !== null)
                                <span class="bs-ad-pill bs-ad-pill-neutral">Đang có đề xuất chờ duyệt</span>
                            @endif
                        </div>

                        <div class="bs-ad-branch-card-foot">
                            <span class="bs-ad-help">
                                Tạo lúc {{ format_datetime($member->created_at) }}
                            </span>
                            <a href="{{ route('admin.staff.show', $member) }}" class="bs-ad-btn-outline">
                                Xem hồ sơ
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="bs-ad-pagination">
                {{ $staffMembers->links() }}
            </div>
        @endif
    </section>
@endsection
