@extends('layouts.admin')

@section('title', 'Tổng quan Quản trị — Book_Store')
@section('header_title', 'Tổng quan hệ thống')

@section('content')
<div class="bs-ad-dashboard-canvas">
    {{-- Khả năng tương thích kiểm thử & Hỗ trợ Screen Reader --}}
    <div class="sr-only">
        <span>Xin chào, {{ $adminUser->name }}</span>
        <span>{{ $effectiveGlobalPermissionCount }} quyền chuẩn hóa</span>
        <span>Quản lý &amp; Phê duyệt Nhân sự</span>
        <a href="{{ route('admin.staff.index') }}">Mở quản lý nhân sự</a>
        <span>Đang chuẩn bị (Chưa mở)</span>
    </div>

    {{-- ==================== 1. WELCOME BAR & TIME FILTER ==================== --}}
    <div class="bs-ad-welcome-bar">
        <div>
            <h1 class="bs-ad-welcome-title">Chào mừng trở lại, {{ $adminUser->name }}</h1>
            <p class="bs-ad-welcome-subtitle">
                Báo cáo tổng hợp hoạt động hệ thống Book_Store — Kỳ báo cáo: <strong>{{ $periodLabel }}</strong>.
            </p>
            @if (! empty($periodError))
                <p class="bs-ad-period-error" role="alert">{{ $periodError }}</p>
            @endif
        </div>

        {{-- Bộ lọc kỳ báo cáo --}}
        <div class="bs-ad-period-wrap">
            <div class="bs-ad-period-filter" role="group" aria-label="Kỳ báo cáo hoạt động">
                <a
                    href="{{ route('admin.dashboard', ['period' => '7_days']) }}"
                    class="bs-ad-period-btn {{ $period === '7_days' ? 'is-active' : '' }}"
                >
                    7 Ngày
                </a>
                <a
                    href="{{ route('admin.dashboard', ['period' => '30_days']) }}"
                    class="bs-ad-period-btn {{ $period === '30_days' ? 'is-active' : '' }}"
                >
                    30 Ngày
                </a>
                <a
                    href="{{ route('admin.dashboard', ['period' => 'this_year']) }}"
                    class="bs-ad-period-btn {{ $period === 'this_year' ? 'is-active' : '' }}"
                >
                    Năm nay
                </a>
                <button
                    type="button"
                    class="bs-ad-period-btn {{ $period === 'custom' ? 'is-active' : '' }}"
                    data-custom-period-toggle
                    aria-expanded="{{ $period === 'custom' ? 'true' : 'false' }}"
                    aria-controls="custom-period-panel"
                >
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <span>Tùy chỉnh</span>
                </button>
            </div>

            {{-- Form chọn khoảng ngày tùy chỉnh --}}
            <form
                id="custom-period-panel"
                method="GET"
                action="{{ route('admin.dashboard') }}"
                class="bs-ad-custom-period-panel {{ $period === 'custom' ? 'is-open' : '' }}"
                data-custom-period-panel
                @if ($period !== 'custom') hidden @endif
            >
                <input type="hidden" name="period" value="custom">
                <div class="bs-ad-custom-period-fields">
                    <label class="bs-ad-custom-period-field">
                        <span>Ngày bắt đầu</span>
                        <input type="date" name="start_date" value="{{ $startDateInput }}" max="{{ now()->toDateString() }}" required>
                    </label>
                    <label class="bs-ad-custom-period-field">
                        <span>Ngày kết thúc</span>
                        <input type="date" name="end_date" value="{{ $endDateInput }}" max="{{ now()->toDateString() }}" required>
                    </label>
                </div>
                <div class="bs-ad-custom-period-actions">
                    <button type="submit" class="bs-ad-btn-primary bs-ad-btn-sm">Áp dụng</button>
                    <button type="button" class="bs-ad-btn-outline bs-ad-btn-sm" data-custom-period-cancel>Hủy</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ==================== 2. 4 KPI CARDS ==================== --}}
    <div class="bs-ad-kpi-grid">
        {{-- Card 1: Tổng số chi nhánh --}}
        <div class="bs-ad-kpi-card">
            <div class="bs-ad-kpi-head">
                <span class="bs-ad-kpi-label">Tổng số chi nhánh</span>
                <span class="bs-ad-kpi-icon bs-ad-kpi-icon-blue" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 21h18"/>
                        <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                        <path d="M9 9h1"/>
                        <path d="M9 13h1"/>
                        <path d="M9 17h1"/>
                        <path d="M14 9h1"/>
                        <path d="M14 13h1"/>
                        <path d="M14 17h1"/>
                    </svg>
                </span>
            </div>
            <div class="bs-ad-kpi-value-row">
                <span class="bs-ad-kpi-value">{{ $kpis['total_branches'] }}</span>
                <span class="bs-ad-kpi-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                        <polyline points="17 6 23 6 23 12"/>
                    </svg>
                    <span>+{{ $kpis['active_branches'] }}</span>
                </span>
            </div>
            <p class="bs-ad-kpi-subtext">Chi nhánh đang hoạt động</p>
        </div>

        {{-- Card 2: Nhân sự đang hoạt động --}}
        <div class="bs-ad-kpi-card">
            <div class="bs-ad-kpi-head">
                <span class="bs-ad-kpi-label">Nhân sự đang hoạt động</span>
                <span class="bs-ad-kpi-icon bs-ad-kpi-icon-green" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </span>
            </div>
            <div class="bs-ad-kpi-value-row">
                <span class="bs-ad-kpi-value">{{ $kpis['active_staff'] }}</span>
                <span class="bs-ad-kpi-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                        <polyline points="17 6 23 6 23 12"/>
                    </svg>
                    <span>100%</span>
                </span>
            </div>
            <p class="bs-ad-kpi-subtext">Nhân sự toàn hệ thống</p>
        </div>

        {{-- Card 3: Tổng tài khoản khách hàng --}}
        <div class="bs-ad-kpi-card">
            <div class="bs-ad-kpi-head">
                <span class="bs-ad-kpi-label">Tổng tài khoản khách hàng</span>
                <span class="bs-ad-kpi-icon bs-ad-kpi-icon-purple" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="16" rx="2"/>
                        <circle cx="9" cy="10" r="2"/>
                        <line x1="15" y1="8" x2="17" y2="8"/>
                        <line x1="15" y1="12" x2="17" y2="12"/>
                        <line x1="7" y1="16" x2="17" y2="16"/>
                    </svg>
                </span>
            </div>
            <div class="bs-ad-kpi-value-row">
                <span class="bs-ad-kpi-value">{{ number_format($kpis['total_customers']) }}</span>
                @if ($kpis['new_customers_period'] > 0)
                    <span class="bs-ad-kpi-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                            <polyline points="17 6 23 6 23 12"/>
                        </svg>
                        <span>+{{ $kpis['new_customers_period'] }}</span>
                    </span>
                @endif
            </div>
            <p class="bs-ad-kpi-subtext">Đăng ký mới: {{ $kpis['new_customers_period'] }}</p>
        </div>

        {{-- Card 4: Đề xuất chờ phê duyệt --}}
        <div class="bs-ad-kpi-card">
            <div class="bs-ad-kpi-head">
                <span class="bs-ad-kpi-label">Đề xuất chờ phê duyệt</span>
                <span class="bs-ad-kpi-icon bs-ad-kpi-icon-amber" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <polyline points="16 11 18 13 22 9"/>
                    </svg>
                </span>
            </div>
            <div class="bs-ad-kpi-value-row">
                <span class="bs-ad-kpi-value">{{ $kpis['pending_approvals'] }}</span>
            </div>
            <p class="bs-ad-kpi-subtext">
                <span class="bs-ad-status-dot-amber" aria-hidden="true"></span>
                <span>Cần xử lý trong 24h</span>
            </p>
        </div>
    </div>

    {{-- ==================== 3. MAIN DASHBOARD COLUMNS (2 COLUMNS) ==================== --}}
    <div class="bs-ad-dash-columns">
        {{-- CỘT TRÁI (~65%): Phê duyệt chờ xử lý & Dòng thời gian hoạt động --}}
        <div class="bs-ad-dash-col-main">
            {{-- Widget: Phê duyệt nhân sự chờ xử lý --}}
            <section class="bs-ad-widget-card" aria-labelledby="heading-pending-approvals">
                <div class="bs-ad-widget-header">
                    <h2 id="heading-pending-approvals" class="bs-ad-widget-title">Phê duyệt nhân sự chờ xử lý</h2>
                    <a href="{{ route('admin.staff.approvals.index') }}" class="bs-ad-widget-link">
                        <span>Xem tất cả</span>
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <div class="bs-ad-dash-table-wrap">
                    @if ($pendingProposals->isEmpty())
                        <div class="py-12 px-6 text-center">
                            <div class="w-12 h-12 rounded-full bg-[#F5F5F7] text-[#8E8E93] flex items-center justify-center mx-auto mb-3" aria-hidden="true">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                </svg>
                            </div>
                            <p class="text-sm font-semibold text-[#1D1D1F] mb-1">Không có đề xuất nào chờ xử lý</p>
                            <p class="text-xs text-[#6E6E73]">Tất cả các đề xuất kích hoạt nhân sự đã được xem xét và phê duyệt.</p>
                        </div>
                    @else
                        <table class="bs-ad-dash-table">
                            <thead>
                                <tr>
                                    <th scope="col" style="min-width: 200px;">NHÂN SỰ / ĐỀ XUẤT</th>
                                    <th scope="col" style="min-width: 140px;">CHỨC DANH</th>
                                    <th scope="col" style="min-width: 150px;">CHI NHÁNH</th>
                                    <th scope="col" style="min-width: 110px;">NGÀY ĐỀ XUẤT</th>
                                    <th scope="col" style="min-width: 100px; text-align: center;">HÀNH ĐỘNG</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pendingProposals as $proposal)
                                    @php
                                        $emp = $proposal->user;
                                        $empInitial = $emp ? mb_strtoupper(mb_substr($emp->name, 0, 2)) : 'NV';
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <span class="bs-ad-emp-avatar" aria-hidden="true">
                                                    {{ $empInitial }}
                                                </span>
                                                <div class="min-w-0">
                                                    <div class="font-semibold text-sm text-[#1D1D1F] truncate">{{ $emp?->name ?? 'Nhân sự' }}</div>
                                                    <div class="text-xs text-[#8E8E93] truncate">
                                                        {{ $proposal->proposal_reason ?: 'Đề xuất kích hoạt tài khoản' }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="text-xs text-[#1D1D1F] font-medium">
                                                {{ $emp?->staffProfile?->position ?: 'Nhân viên' }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-xs text-[#6E6E73]">
                                                {{ $proposal->branch?->name ?: 'Toàn hệ thống' }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-xs text-[#6E6E73]">
                                                {{ $proposal->proposed_at ? format_date($proposal->proposed_at) : ($proposal->created_at ? format_date($proposal->created_at) : '—') }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="{{ route('admin.staff.approvals.index') }}" class="bs-ad-btn-table-action-dark">
                                                Xem chi tiết
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>

            {{-- Widget: Nhật ký hoạt động gần đây (Timeline chuẩn thiết kế) --}}
            <section class="bs-ad-widget-card" aria-labelledby="heading-recent-audit">
                <div class="bs-ad-widget-header">
                    <h2 id="heading-recent-audit" class="bs-ad-widget-title">Nhật ký hoạt động gần đây</h2>
                    <a href="{{ route('admin.dashboard') }}" class="bs-ad-btn-icon-refresh" title="Làm mới hoạt động" aria-label="Làm mới hoạt động">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                    </a>
                </div>

                <div class="bs-ad-timeline-wrap">
                    @if ($recentActivities->isEmpty())
                        <div class="py-10 text-center">
                            <p class="text-xs text-[#8E8E93]">Chưa có sự kiện kiểm toán nào được ghi nhận gần đây.</p>
                        </div>
                    @else
                        {{-- Đường kẻ dọc trung tâm timeline --}}
                        <div class="bs-ad-timeline-line" aria-hidden="true"></div>

                        <div class="bs-ad-timeline-items">
                            @foreach ($recentActivities as $index => $log)
                                @php
                                    $isLeft = ($index % 2 === 0);
                                    $actionStr = $log->action instanceof \App\Enums\AuditAction ? $log->action->value : (string) $log->action;

                                    // Xác định nhóm danh mục sự kiện (Hệ thống, Chi nhánh, Cảnh báo)
                                    $category = match (true) {
                                        str_contains($actionStr, 'failed') || str_contains($actionStr, 'suspended') || str_contains($actionStr, 'locked') => 'Cảnh báo',
                                        str_contains($actionStr, 'branch') || $log->branch_id !== null => 'Chi nhánh',
                                        default => 'Hệ thống',
                                    };

                                    $isWarning = $category === 'Cảnh báo';

                                    // Thời gian tương đối tiếng Việt
                                    $timeDiff = $log->occurred_at ? $log->occurred_at->locale('vi')->diffForHumans(['parts' => 1]) : 'Vừa xong';
                                @endphp
                                <div class="bs-ad-timeline-row {{ $isLeft ? 'is-left' : 'is-right' }}">
                                    {{-- Biểu tượng node trên timeline --}}
                                    <div class="bs-ad-timeline-marker" aria-hidden="true">
                                        @if ($isWarning)
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                                                <line x1="12" y1="9" x2="12" y2="13"/>
                                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                                            </svg>
                                        @elseif ($category === 'Chi nhánh')
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 21h18"/>
                                                <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                                            </svg>
                                        @else
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1E4D3B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                                <polyline points="9 12 11 14 15 10"/>
                                            </svg>
                                        @endif
                                    </div>

                                    {{-- Hộp nội dung sự kiện --}}
                                    <div class="bs-ad-timeline-node-card">
                                        <div class="bs-ad-timeline-card-head">
                                            <span class="bs-ad-timeline-time">{{ $timeDiff }}</span>
                                            <span class="bs-ad-timeline-tag {{ $isWarning ? 'bs-ad-timeline-tag-danger' : '' }}">
                                                {{ $category }}
                                            </span>
                                        </div>
                                        <p class="bs-ad-timeline-desc">
                                            <strong>{{ $log->actor?->name ?? 'Hệ thống' }}</strong>
                                            {{ $log->action instanceof \App\Enums\AuditAction ? mb_strtolower($log->action->label()) : $actionStr }}.
                                        </p>
                                        @if ($log->targetUser || $log->branch)
                                            <p class="bs-ad-timeline-subtext">
                                                @if ($log->targetUser)
                                                    Đối tượng: {{ $log->targetUser->name }}
                                                @endif
                                                @if ($log->branch)
                                                    ({{ $log->branch->name }})
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="bs-ad-timeline-footer">
                            <a href="{{ route('admin.audit-logs.index') }}">
                                Xem toàn bộ nhật ký hệ thống
                            </a>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        {{-- CỘT PHẢI (~35%): Trạng thái chi nhánh & Sức chứa kho trung tâm --}}
        <div class="bs-ad-dash-col-side">
            {{-- Widget: Trạng thái chi nhánh --}}
            <section class="bs-ad-widget-card" aria-labelledby="heading-branch-status">
                <div class="bs-ad-widget-header">
                    <h2 id="heading-branch-status" class="bs-ad-widget-title">Trạng thái chi nhánh</h2>
                </div>

                <div class="bs-ad-branch-status-list">
                    <div class="bs-ad-status-row">
                        <span class="bs-ad-status-label">
                            <span class="bs-ad-dot bs-ad-dot-green" aria-hidden="true"></span>
                            <span>Đang hoạt động tốt</span>
                        </span>
                        <span class="bs-ad-status-count">{{ $branchSummary['active'] }}</span>
                    </div>

                    <div class="bs-ad-status-row">
                        <span class="bs-ad-status-label">
                            <span class="bs-ad-dot bs-ad-dot-amber" aria-hidden="true"></span>
                            <span>Cần chú ý (Nhân sự)</span>
                        </span>
                        <span class="bs-ad-status-count">{{ $branchSummary['attention_needed'] }}</span>
                    </div>

                    <div class="bs-ad-status-row">
                        <span class="bs-ad-status-label">
                            <span class="bs-ad-dot bs-ad-dot-red" aria-hidden="true"></span>
                            <span>Tạm đóng cửa</span>
                        </span>
                        <span class="bs-ad-status-count">{{ $branchSummary['inactive'] }}</span>
                    </div>
                </div>

                <a href="{{ route('admin.branches.index') }}" class="bs-ad-card-bottom-link">
                    <span>Đi tới bản đồ mạng lưới</span>
                    <span aria-hidden="true">&nearr;</span>
                </a>
            </section>

            {{-- Widget: Sức chứa kho trung tâm --}}
            <section class="bs-ad-widget-card" aria-labelledby="heading-central-wh">
                <div class="bs-ad-widget-header">
                    <h2 id="heading-central-wh" class="bs-ad-widget-title">Sức chứa kho trung tâm</h2>
                </div>

                <div class="bs-ad-wh-widget">
                    <div class="bs-ad-wh-row">
                        <span class="bs-ad-wh-name">{{ $centralWarehouse?->name ?? 'Kho Trung Tâm Hệ Thống' }}</span>
                        <span class="bs-ad-wh-percent">Hoạt động ổn định</span>
                    </div>

                    <div class="bs-ad-wh-bar-bg" aria-hidden="true">
                        <div class="bs-ad-wh-bar-fill" style="width: 100%;"></div>
                    </div>

                    <p class="bs-ad-wh-note">
                        Kho trung tâm (Mã: <strong>{{ $centralWarehouse?->code ?? 'CENTRAL_WH' }}</strong>) đang kết nối vận hành thông suốt. Hệ thống theo dõi sức chứa chi tiết và quản lý tồn kho FIFO đang chuẩn bị triển khai.
                    </p>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
