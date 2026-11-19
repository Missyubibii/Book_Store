{{--
    Danh sách Quản trị Chi nhánh Bán lẻ (Stage UI-02.1)
    - Entry point: GET /quan-tri/chi-nhanh (`admin.branches.index`) -> BranchController::index
    - Sử dụng hệ thống component tái sử dụng Liquid White Admin Design System.
    - Kho trung tâm (`CENTRAL_WH`) tách biệt hoàn toàn khỏi danh sách chi nhánh bán lẻ.
--}}
@extends('layouts.admin')

@section('title', 'Quản lý chi nhánh — Book_Store')
@section('header_title', 'Quản lý chi nhánh')

@section('content')
<div class="bs-ad-dashboard-canvas">
    <span class="sr-only">Quản lý Chi nhánh Bán lẻ</span>

    {{-- ==================== 1. PAGE HEADER ==================== --}}
    <x-admin.page-header
        title="Quản lý chi nhánh"
        subtitle="Quản lý thông tin, trạng thái và nhân sự tại các chi nhánh của hệ thống."
    >
        <x-slot:breadcrumb>
            <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
                @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
                    <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
                    <span aria-hidden="true">/</span>
                @endif
                <span aria-current="page">Quản lý chi nhánh</span>
            </nav>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <a href="{{ route('admin.central-warehouse.show') }}" class="bs-ad-btn-outline">
                Kho trung tâm ({{ $centralWarehouse?->code ?? 'CENTRAL_WH' }})
            </a>

            @if ($canCreateBranch)
                <a href="{{ route('admin.branches.create') }}" class="bs-ad-btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>Thêm chi nhánh</span>
                </a>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- ==================== 2. 4 KPI SUMMARY CARDS ==================== --}}
    <div class="bs-ad-kpi-grid" aria-label="Thống kê chi nhánh bán lẻ">
        <x-admin.kpi-card
            label="Tổng số chi nhánh bán lẻ"
            :value="number_format($summary['total'], 0, ',', '.')"
            subtext="Không bao gồm kho trung tâm"
            variant="blue"
        >
            <x-slot:icon>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 21h18"/>
                    <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                    <path d="M9 9h1"/>
                    <path d="M9 13h1"/>
                    <path d="M14 9h1"/>
                    <path d="M14 13h1"/>
                </svg>
            </x-slot:icon>
        </x-admin.kpi-card>

        <x-admin.kpi-card
            label="Chi nhánh đang hoạt động"
            :value="number_format($summary['active'], 0, ',', '.')"
            subtext="Sẵn sàng tiếp nhận vận hành"
            variant="green"
        >
            <x-slot:icon>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </x-slot:icon>
        </x-admin.kpi-card>

        <x-admin.kpi-card
            label="Chi nhánh tạm ngừng"
            :value="number_format($summary['inactive'], 0, ',', '.')"
            subtext="Đang bảo trì hoặc tạm đóng"
            variant="amber"
        >
            <x-slot:icon>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </x-slot:icon>
        </x-admin.kpi-card>

        <x-admin.kpi-card
            label="Nhân sự đang được phân công"
            :value="number_format($summary['active_staff_total'] ?? 0, 0, ',', '.')"
            subtext="Phân công đang hiệu lực"
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
    </div>

    {{-- ==================== 3. FILTER TOOLBAR & DATA TABLE ==================== --}}
    <x-admin.content-card title="Danh sách chi nhánh bán lẻ" heading-id="heading-branches-list">
        <x-slot:headerActions>
            <span class="bs-ad-muted-meta">Tổng: {{ number_format($branches->total(), 0, ',', '.') }} kết quả</span>
        </x-slot:headerActions>

        {{-- Thanh tìm kiếm & lọc gọn gàng 1 hàng trên Desktop --}}
        <x-admin.filter-toolbar :action="route('admin.branches.index')">
            <x-admin.search-field
                id="branch-search"
                name="search"
                :value="$search"
                label="Tìm kiếm theo tên, mã chi nhánh hoặc địa điểm"
                placeholder="Tìm theo tên, mã chi nhánh hoặc địa chỉ..."
            />

            <x-admin.filter-select
                id="branch-status"
                name="status"
                label="Trạng thái chi nhánh"
            >
                <option value="">Tất cả trạng thái</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option->value }}" @selected($statusFilter === $option->value)>
                        {{ $option->label() }}
                    </option>
                @endforeach
            </x-admin.filter-select>

            <x-slot:actions>
                @if ($search !== '' || $statusFilter !== '')
                    <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-reset">Đặt lại</a>
                @endif
                <button type="submit" class="bs-ad-btn-apply">Áp dụng lọc</button>
            </x-slot:actions>
        </x-admin.filter-toolbar>

        @if ($branches->isEmpty())
            <x-admin.empty-state
                :description="($search !== '' || $statusFilter !== '')
                    ? 'Không tìm thấy chi nhánh bán lẻ nào phù hợp với điều kiện lọc hiện tại.'
                    : 'Chưa có chi nhánh bán lẻ nào trong hệ thống.'"
            >
                @if ($search !== '' || $statusFilter !== '')
                    <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Đặt lại</a>
                @endif
            </x-admin.empty-state>
        @else
            {{-- Bảng dữ liệu Desktop / Tablet --}}
            <x-admin.data-table>
                <x-slot:head>
                    <tr>
                        <th>CHI NHÁNH</th>
                        <th>ĐỊA ĐIỂM</th>
                        <th>TRẠNG THÁI</th>
                        <th>NHÂN SỰ</th>
                        <th>NGÀY CẬP NHẬT</th>
                        <th class="bs-ad-th-action">THAO TÁC</th>
                    </tr>
                </x-slot:head>

                @foreach ($branches as $branch)
                    <tr>
                        <td>
                            <div class="bs-ad-branch-cell">
                                <span class="bs-ad-user-name">{{ $branch->name }}</span>
                                <span class="bs-ad-branch-code-tag">{{ $branch->code }}</span>
                            </div>
                        </td>
                        <td>
                            <span class="bs-ad-branch-address">{{ $branch->address ?: 'Chưa cập nhật địa chỉ' }}</span>
                        </td>
                        <td>
                            @if ($branch->status === \App\Enums\BranchStatus::Active)
                                <x-admin.status-badge variant="success">{{ $branch->status->label() }}</x-admin.status-badge>
                            @else
                                <x-admin.status-badge variant="danger">{{ $branch->status->label() }}</x-admin.status-badge>
                            @endif
                        </td>
                        <td>
                            <span class="bs-ad-staff-count">{{ number_format($branch->active_staff_count ?? 0, 0, ',', '.') }} nhân sự</span>
                        </td>
                        <td>
                            <span class="bs-ad-date-cell">{{ format_datetime($branch->updated_at) }}</span>
                        </td>
                        <td class="bs-ad-td-action">
                            <a href="{{ route('admin.branches.show', $branch) }}" class="bs-ad-btn-detail">
                                Xem chi tiết
                            </a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.data-table>

            {{-- Danh sách thẻ Mobile (< 768px) --}}
            <div class="bs-ad-mobile-cards">
                @foreach ($branches as $branch)
                    <article class="bs-ad-mobile-card">
                        <div class="bs-ad-mobile-card-head">
                            <div>
                                <h3 class="bs-ad-user-name">{{ $branch->name }}</h3>
                                <span class="bs-ad-branch-code-tag">{{ $branch->code }}</span>
                            </div>
                            @if ($branch->status === \App\Enums\BranchStatus::Active)
                                <x-admin.status-badge variant="success">{{ $branch->status->label() }}</x-admin.status-badge>
                            @else
                                <x-admin.status-badge variant="danger">{{ $branch->status->label() }}</x-admin.status-badge>
                            @endif
                        </div>

                        <dl class="bs-ad-mobile-card-meta">
                            <div>
                                <dt>Địa điểm</dt>
                                <dd>{{ $branch->address ?: 'Chưa cập nhật địa chỉ' }}</dd>
                            </div>
                            <div>
                                <dt>Nhân sự phân công</dt>
                                <dd>{{ number_format($branch->active_staff_count ?? 0, 0, ',', '.') }} nhân sự</dd>
                            </div>
                            <div>
                                <dt>Cập nhật</dt>
                                <dd>{{ format_datetime($branch->updated_at) }}</dd>
                            </div>
                        </dl>

                        <div class="bs-ad-mobile-card-actions">
                            <a href="{{ route('admin.branches.show', $branch) }}" class="bs-ad-btn-detail bs-ad-btn-full">
                                Xem chi tiết
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            <x-admin.pagination :paginator="$branches" />
        @endif
    </x-admin.content-card>
</div>
@endsection
