{{--
    Trang Nhật ký Hoạt động & Kiểm toán Hệ thống
    - Entry point: GET /quan-tri/nhat-ky-hoat-dong (`admin.audit-logs.index`)
    - Chỉ dành cho Quản trị viên cấp cao có hiệu lực (`super_admin.effective`).
    - Hiển thị danh sách phân trang, hỗ trợ tìm kiếm, lọc khoảng ngày, hành động, người thực hiện và ngữ cảnh nhân sự/chi nhánh.
    - Toàn bộ dữ liệu chi tiết được làm sạch qua `AuditLogQueryService::redactPayload()` để không bao giờ lộ bí mật.
--}}
@extends('layouts.admin')

@section('title', 'Nhật ký Hoạt động Hệ thống — Book_Store')
@section('header_title', 'Nhật ký hoạt động')

@section('content')
<div class="bs-ad-dashboard-canvas">
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Nhật ký hoạt động</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Kiểm toán Bất biến &amp; Truy vết Bảo mật</p>
            <h1 class="bs-ad-title">Nhật ký Hoạt động Hệ thống</h1>
            <p class="bs-ad-subtitle">
                Tra cứu toàn bộ sự kiện quản trị nhân sự, phân công chi nhánh, phê duyệt và thay đổi mật khẩu theo thời gian chuẩn Việt Nam.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            <a href="{{ route('admin.accounts.index') }}" class="bs-ad-btn-outline">Quản lý tài khoản</a>
            <a href="{{ route('admin.dashboard') }}" class="bs-ad-btn-outline">Về tổng quan</a>
        </div>
    </div>

    @if ($contextTargetUser !== null || $contextBranch !== null)
        <div class="bs-ad-info-banner" role="region" aria-label="Ngữ cảnh lọc đang áp dụng">
            <strong>Đang lọc theo ngữ cảnh:</strong>
            @if ($contextTargetUser !== null)
                Nhân sự <span class="bs-ad-code">{{ $contextTargetUser->name }}</span> ({{ $contextTargetUser->email }})
            @endif
            @if ($contextBranch !== null)
                @if ($contextTargetUser !== null) • @endif
                Đơn vị <span class="bs-ad-code">{{ $contextBranch->name }} ({{ $contextBranch->code }})</span>
            @endif
            — <a href="{{ route('admin.audit-logs.index') }}">Xóa lọc ngữ cảnh</a>
        </div>
    @endif

    <section class="bs-ad-panel" aria-labelledby="audit-filter-heading">
        <h2 id="audit-filter-heading" class="bs-ad-panel-title">Tìm kiếm và lọc nhật ký kiểm toán</h2>
        <p class="bs-ad-panel-subtitle">
            Lọc theo từ khóa, loại hành động, người thực hiện, đơn vị hoặc khoảng ngày (múi giờ Việt Nam).
        </p>

        <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="bs-ad-audit-filter-form">
            @if ($filters['target_user_id'] !== null)
                <input type="hidden" name="target_user_id" value="{{ $filters['target_user_id'] }}">
            @endif

            <div class="bs-ad-field">
                <label for="audit-search" class="bs-ad-label">Từ khóa tìm kiếm</label>
                <input
                    id="audit-search"
                    type="search"
                    name="search"
                    value="{{ $filters['search'] ?? '' }}"
                    maxlength="120"
                    placeholder="Tên, email, mã chi nhánh, lý do..."
                    class="bs-ad-input @error('search') bs-ad-input-error @enderror"
                >
                @error('search')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-field">
                <label for="audit-action" class="bs-ad-label">Loại hành động</label>
                <select id="audit-action" name="action" class="bs-ad-select @error('action') bs-ad-input-error @enderror">
                    <option value="">Tất cả hành động</option>
                    @foreach ($actionOptions as $option)
                        <option value="{{ $option->value }}" @selected($filters['action'] === $option->value)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
                @error('action')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-field">
                <label for="audit-actor" class="bs-ad-label">Người thực hiện</label>
                <select id="audit-actor" name="actor_id" class="bs-ad-select">
                    <option value="">Tất cả người thực hiện</option>
                    @foreach ($actorOptions as $actorOption)
                        <option value="{{ $actorOption->id }}" @selected($filters['actor_id'] === (int) $actorOption->id)>
                            {{ $actorOption->name }} ({{ $actorOption->email }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="bs-ad-field">
                <label for="audit-branch" class="bs-ad-label">Chi nhánh / Kho</label>
                <select id="audit-branch" name="branch_id" class="bs-ad-select">
                    <option value="">Tất cả đơn vị</option>
                    @foreach ($branchOptions as $branchOption)
                        <option value="{{ $branchOption->id }}" @selected($filters['branch_id'] === (int) $branchOption->id)>
                            {{ $branchOption->name }} ({{ $branchOption->code }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="bs-ad-field">
                <label for="audit-date-from" class="bs-ad-label">Từ ngày</label>
                <input
                    id="audit-date-from"
                    type="date"
                    name="date_from"
                    value="{{ $filters['date_from'] ?? '' }}"
                    class="bs-ad-input @error('date_from') bs-ad-input-error @enderror"
                >
                @error('date_from')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-field">
                <label for="audit-date-to" class="bs-ad-label">Đến ngày</label>
                <input
                    id="audit-date-to"
                    type="date"
                    name="date_to"
                    value="{{ $filters['date_to'] ?? '' }}"
                    class="bs-ad-input @error('date_to') bs-ad-input-error @enderror"
                >
                @error('date_to')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-page-actions">
                <button type="submit" class="bs-ad-btn-primary">Lọc nhật ký</button>
                <a href="{{ route('admin.audit-logs.index') }}" class="bs-ad-btn-outline">Đặt lại</a>
            </div>
        </form>
    </section>

    <section class="bs-ad-panel" aria-labelledby="audit-results-heading">
        <div class="bs-ad-subcard-head">
            <div>
                <h2 id="audit-results-heading" class="bs-ad-panel-title">Danh sách sự kiện kiểm toán</h2>
                <p class="bs-ad-panel-subtitle">
                    Hiển thị {{ $auditLogs->count() }} / {{ $auditLogs->total() }} bản ghi (sắp xếp mới nhất trước).
                </p>
            </div>
        </div>

        @if ($auditLogs->isEmpty())
            <div class="bs-ad-empty-state">
                <h3 class="bs-ad-panel-title">Không tìm thấy bản ghi kiểm toán phù hợp</h3>
                <p class="bs-ad-panel-subtitle">
                    Không có sự kiện nào khớp điều kiện lọc hiện tại. Bạn có thể đặt lại bộ lọc để xem toàn bộ lịch sử.
                </p>
                <a href="{{ route('admin.audit-logs.index') }}" class="bs-ad-btn-outline">Xem toàn bộ nhật ký</a>
            </div>
        @else
            <div class="bs-ad-audit-table-wrap">
                <table class="bs-ad-audit-table">
                    <thead>
                        <tr>
                            <th scope="col">Sự kiện</th>
                            <th scope="col">Người thực hiện</th>
                            <th scope="col">Thời gian</th>
                            <th scope="col">Đối tượng</th>
                            <th scope="col" class="bs-ad-audit-col-action">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($auditLogs as $audit)
                            @php
                                $subjectSummary = match (true) {
                                    $audit->targetUser !== null => $audit->targetUser->name,
                                    $audit->branch !== null => $audit->branch->name . ' (' . $audit->branch->code . ')',
                                    default => class_basename((string) $audit->subject_type) . ' #' . $audit->subject_id,
                                };
                            @endphp
                            <tr class="bs-ad-audit-row" data-audit-id="{{ $audit->id }}">
                                <td data-label="Sự kiện">
                                    <div class="bs-ad-audit-event-cell">
                                        <strong class="bs-ad-audit-event-name">{{ $audit->action->label() }}</strong>
                                        <span class="bs-ad-audit-event-meta">
                                            <span class="bs-ad-code">#{{ $audit->id }}</span>
                                            <span class="bs-ad-pill bs-ad-pill-neutral">{{ $audit->action->value }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Người thực hiện">
                                    {{ $audit->actor?->name ?? 'Hệ thống / CLI' }}
                                </td>
                                <td data-label="Thời gian" class="bs-ad-audit-time-cell">
                                    {{ format_datetime($audit->occurred_at) }}
                                </td>
                                <td data-label="Đối tượng">
                                    @if ($audit->targetUser !== null)
                                        <a href="{{ route('admin.staff.show', $audit->targetUser) }}" class="bs-ad-audit-link">
                                            {{ $audit->targetUser->name }}
                                        </a>
                                    @else
                                        <span>{{ $subjectSummary }}</span>
                                    @endif
                                </td>
                                <td data-label="Thao tác" class="bs-ad-audit-action-cell">
                                    <button
                                        type="button"
                                        class="bs-ad-btn-outline bs-ad-btn-sm"
                                        data-audit-detail-trigger
                                        data-detail-url="{{ route('admin.audit-logs.show', $audit) }}"
                                        aria-haspopup="dialog"
                                        aria-controls="audit-detail-drawer"
                                    >
                                        Xem chi tiết
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bs-ad-pagination">
                {{ $auditLogs->links() }}
            </div>
        @endif
    </section>

    @include('admin.audit-logs.partials.drawer')
</div>
@endsection
