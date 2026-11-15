{{--
    Danh sách Quản trị Chi nhánh Bán lẻ (Stage 01B.4)
    - Entry point: GET /quan-tri/chi-nhanh (`admin.branches.index`) -> BranchController::index
    - Hiển thị danh sách chi nhánh bán lẻ (`BranchType::RetailBranch`), tìm kiếm, lọc trạng thái và lối vào tạo mới.
    - Kho trung tâm (`CENTRAL_WH`) không nằm chung danh sách chỉnh sửa mà xem riêng ở chế độ chỉ đọc.
--}}
@extends('layouts.admin')

@section('title', 'Quản lý Chi nhánh Bán lẻ — Book_Store')

@section('content')
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <span aria-current="page">Chi nhánh bán lẻ</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Quản trị Điểm bán &amp; Cấu trúc Mạng lưới</p>
            <h1 class="bs-ad-title">Quản lý Chi nhánh Bán lẻ</h1>
            <p class="bs-ad-subtitle">
                Khởi tạo, tra cứu, cập nhật thông tin liên hệ và kiểm soát trạng thái vận hành của từng chi nhánh bán lẻ Book_Store.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            <a href="{{ route('admin.central-warehouse.show') }}" class="bs-ad-btn-outline">
                Kho trung tâm ({{ $centralWarehouse?->code ?? 'CENTRAL_WH' }})
            </a>

            @if ($canCreateBranch)
                <a href="{{ route('admin.branches.create') }}" class="bs-ad-btn-primary">
                    Tạo chi nhánh bán lẻ
                </a>
            @endif
        </div>
    </div>

    <section class="bs-ad-identity-grid" aria-label="Thống kê chi nhánh bán lẻ">
        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Tổng chi nhánh bán lẻ</p>
            <p class="bs-ad-stat-value">{{ $summary['total'] }} chi nhánh</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Đang hoạt động</p>
            <p class="bs-ad-stat-value">{{ $summary['active'] }} chi nhánh</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Đang ngừng hoạt động</p>
            <p class="bs-ad-stat-value">{{ $summary['inactive'] }} chi nhánh</p>
        </article>

        <article class="bs-ad-stat-card">
            <p class="bs-ad-stat-label">Kho trung tâm duy nhất</p>
            <p class="bs-ad-stat-value">
                {{ $centralWarehouse !== null ? $centralWarehouse->code . ' (' . $centralWarehouse->status->label() . ')' : 'Chưa có dữ liệu' }}
            </p>
        </article>
    </section>

    <section class="bs-ad-panel" aria-labelledby="branch-filter-heading">
        <h2 id="branch-filter-heading" class="bs-ad-panel-title">Tìm kiếm và lọc chi nhánh bán lẻ</h2>
        <p class="bs-ad-panel-subtitle">
            Tra cứu theo mã chi nhánh, tên chi nhánh, địa chỉ hoặc số điện thoại liên hệ.
        </p>

        <form method="GET" action="{{ route('admin.branches.index') }}" class="bs-ad-filter-form">
            <div class="bs-ad-field">
                <label for="branch-search" class="bs-ad-label">Từ khóa tìm kiếm</label>
                <input
                    id="branch-search"
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    maxlength="100"
                    placeholder="Ví dụ: HN01, Quận 1, Đường Láng..."
                    class="bs-ad-input @error('search') bs-ad-input-error @enderror"
                >
                @error('search')
                    <p class="bs-ad-field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="bs-ad-field">
                <label for="branch-status-filter" class="bs-ad-label">Trạng thái vận hành</label>
                <select
                    id="branch-status-filter"
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

            <div class="bs-ad-page-actions">
                <button type="submit" class="bs-ad-btn-primary">Lọc danh sách</button>
                @if ($search !== '' || $statusFilter !== '')
                    <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Đặt lại</a>
                @endif
            </div>
        </form>
    </section>

    @if ($branches->count() === 0)
        <section class="bs-ad-empty-state" aria-label="Chưa có dữ liệu chi nhánh phù hợp">
            <h2 class="bs-ad-panel-title">
                @if ($search !== '' || $statusFilter !== '')
                    Không tìm thấy chi nhánh bán lẻ nào khớp bộ lọc
                @else
                    Chưa có chi nhánh bán lẻ nào trong hệ thống
                @endif
            </h2>
            <p class="bs-ad-panel-subtitle">
                @if ($search !== '' || $statusFilter !== '')
                    Thử xóa bộ lọc tìm kiếm hoặc kiểm tra lại mã và tên chi nhánh cần tra cứu.
                @else
                    Hệ thống hiện chỉ có Kho trung tâm bất biến. Bạn có thể tạo chi nhánh bán lẻ đầu tiên ngay bên dưới.
                @endif
            </p>
            <div class="bs-ad-page-actions">
                @if ($search !== '' || $statusFilter !== '')
                    <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Xem toàn bộ danh sách</a>
                @endif
                @if ($canCreateBranch)
                    <a href="{{ route('admin.branches.create') }}" class="bs-ad-btn-primary">Tạo chi nhánh bán lẻ mới</a>
                @endif
            </div>
        </section>
    @else
        <section class="bs-ad-branch-list" aria-label="Danh sách chi nhánh bán lẻ">
            @foreach ($branches as $branch)
                <article class="bs-ad-branch-card">
                    <div class="bs-ad-branch-card-head">
                        <span class="bs-ad-code">{{ $branch->code }}</span>
                        <span @class([
                            'bs-ad-pill',
                            'bs-ad-pill-active' => $branch->isActive(),
                            'bs-ad-pill-inactive' => ! $branch->isActive(),
                        ])>
                            {{ $branch->status->label() }}
                        </span>
                    </div>

                    <h2 class="bs-ad-branch-title">{{ $branch->name }}</h2>

                    <dl class="bs-ad-meta-list">
                        <div class="bs-ad-meta-row">
                            <dt class="bs-ad-meta-label">Loại đơn vị:</dt>
                            <dd class="bs-ad-meta-value">{{ $branch->type->label() }}</dd>
                        </div>
                        <div class="bs-ad-meta-row">
                            <dt class="bs-ad-meta-label">Điện thoại:</dt>
                            <dd class="bs-ad-meta-value">{{ $branch->phone ?: 'Chưa cập nhật' }}</dd>
                        </div>
                        <div class="bs-ad-meta-row">
                            <dt class="bs-ad-meta-label">Địa chỉ:</dt>
                            <dd class="bs-ad-meta-value">{{ $branch->address ?: 'Chưa cập nhật địa chỉ' }}</dd>
                        </div>
                        <div class="bs-ad-meta-row">
                            <dt class="bs-ad-meta-label">Cập nhật lần cuối:</dt>
                            <dd class="bs-ad-meta-value">{{ format_datetime($branch->updated_at) }}</dd>
                        </div>
                    </dl>

                    <div class="bs-ad-branch-card-foot">
                        <span class="bs-ad-help">Định danh hệ thống #{{ $branch->id }}</span>
                        <a href="{{ route('admin.branches.show', $branch) }}" class="bs-ad-btn-outline">
                            Xem &amp; quản trị
                        </a>
                    </div>
                </article>
            @endforeach
        </section>

        @if ($branches->hasPages())
            <div class="bs-ad-pagination">
                {{ $branches->links() }}
            </div>
        @endif
    @endif
@endsection
