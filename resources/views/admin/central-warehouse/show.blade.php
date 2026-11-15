{{--
    Màn hình Chỉ đọc Kho Trung tâm Book_Store (Stage 01B.4)
    - Entry point: GET /quan-tri/kho-trung-tam (`admin.central-warehouse.show`)
    - Chỉ đọc tuyệt đối: không có biểu mẫu sửa đổi, chuyển đổi loại, ngừng hoạt động hoặc xóa.
--}}
@extends('layouts.admin')

@section('title', 'Kho Trung tâm Book_Store (Chỉ đọc) — Book_Store')

@section('content')
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        @if ($isGlobalBranchViewer ?? false)
            <a href="{{ route('admin.branches.index') }}">Chi nhánh bán lẻ</a>
            <span aria-hidden="true">/</span>
        @endif
        <span aria-current="page">Kho trung tâm</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">
                {{ ($isGlobalBranchViewer ?? false) ? 'Thực thể Hạ tầng Bất biến • Chế độ Chỉ đọc' : 'Phân quyền phạm vi Kho trung tâm (CENTRAL_WH) • Chế độ Chỉ đọc' }}
            </p>
            <h1 class="bs-ad-title">
                {{ $centralWarehouse?->name ?? $canonicalName }}
            </h1>
            <p class="bs-ad-subtitle">
                Kho trung tâm là đầu mối điều phối duy nhất giữa nhà cung cấp và toàn bộ mạng lưới chi nhánh bán lẻ Book_Store.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            @if ($centralWarehouse !== null && auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
                <a href="{{ route('admin.audit-logs.index', ['branch_id' => $centralWarehouse->id]) }}" class="bs-ad-btn-outline">
                    Xem tất cả lịch sử
                </a>
            @endif
            @if ($isGlobalBranchViewer ?? false)
                <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">
                    Xem danh sách chi nhánh bán lẻ ({{ $retailSummary['total'] }})
                </a>
            @endif
        </div>
    </div>

    @if ($centralWarehouse === null)
        <section class="bs-ad-empty-state" aria-label="Chưa khởi tạo Kho trung tâm">
            <h2 class="bs-ad-panel-title">Chưa tìm thấy bản ghi Kho trung tâm</h2>
            <p class="bs-ad-panel-subtitle">
                Hệ thống chưa phát hiện bản ghi Kho trung tâm trong cơ sở dữ liệu hiện tại. Khi khởi tạo chuẩn hóa qua seeder, Kho trung tâm sẽ tự động hiển thị ở chế độ chỉ đọc tại đây.
            </p>
        </section>
    @else
        <section class="bs-ad-identity-grid" aria-label="Thông tin Kho trung tâm bất biến">
            <article class="bs-ad-stat-card">
                <p class="bs-ad-stat-label">Mã định danh chuẩn</p>
                <p class="bs-ad-stat-value">{{ $centralWarehouse->code }}</p>
            </article>

            <article class="bs-ad-stat-card">
                <p class="bs-ad-stat-label">Loại đơn vị</p>
                <p class="bs-ad-stat-value">{{ $centralWarehouse->type->label() }}</p>
            </article>

            <article class="bs-ad-stat-card">
                <p class="bs-ad-stat-label">Trạng thái vận hành</p>
                <p class="bs-ad-stat-value">{{ $centralWarehouse->status->label() }} (Bất biến)</p>
            </article>

            <article class="bs-ad-stat-card">
                <p class="bs-ad-stat-label">Số chi nhánh bán lẻ đang kết nối</p>
                <p class="bs-ad-stat-value">{{ $retailSummary['active'] }} hoạt động / {{ $retailSummary['total'] }} tổng số</p>
            </article>
        </section>

        <section class="bs-ad-panel" aria-labelledby="central-warehouse-details-heading">
            <h2 id="central-warehouse-details-heading" class="bs-ad-panel-title">Hồ sơ chi tiết Kho trung tâm</h2>
            <p class="bs-ad-panel-subtitle">
                Dữ liệu Kho trung tâm được khóa khỏi thao tác chỉnh sửa trực tiếp trên giao diện để bảo vệ toàn vẹn chuỗi cung ứng và kế toán FIFO.
            </p>

            <dl class="bs-ad-meta-list">
                <div class="bs-ad-meta-row">
                    <dt class="bs-ad-meta-label">Mã kho chuẩn:</dt>
                    <dd class="bs-ad-meta-value"><span class="bs-ad-code">{{ $centralWarehouse->code }}</span></dd>
                </div>
                <div class="bs-ad-meta-row">
                    <dt class="bs-ad-meta-label">Tên chính thức:</dt>
                    <dd class="bs-ad-meta-value">{{ $centralWarehouse->name }}</dd>
                </div>
                <div class="bs-ad-meta-row">
                    <dt class="bs-ad-meta-label">Số điện thoại liên hệ:</dt>
                    <dd class="bs-ad-meta-value">{{ $centralWarehouse->phone ?: 'Chưa khai báo số điện thoại riêng' }}</dd>
                </div>
                <div class="bs-ad-meta-row">
                    <dt class="bs-ad-meta-label">Địa chỉ kho:</dt>
                    <dd class="bs-ad-meta-value">{{ $centralWarehouse->address ?: 'Chưa khai báo địa chỉ kho trung tâm' }}</dd>
                </div>
                <div class="bs-ad-meta-row">
                    <dt class="bs-ad-meta-label">Khởi tạo lúc:</dt>
                    <dd class="bs-ad-meta-value">{{ format_datetime($centralWarehouse->created_at) }}</dd>
                </div>
            </dl>
        </section>
    @endif

    @if ($canViewGlobalAuditLogs)
        <section class="bs-ad-panel" aria-labelledby="central-warehouse-audit-heading">
            <div class="bs-ad-subcard-head">
                <div>
                    <h2 id="central-warehouse-audit-heading" class="bs-ad-panel-title">Nhật ký hoạt động gần đây</h2>
                    <p class="bs-ad-panel-subtitle">Lịch sử hoạt động và phân bổ nhân sự gắn với Kho trung tâm Book_Store.</p>
                </div>

                <a
                    href="{{ route('admin.audit-logs.index', ['branch_id' => $centralWarehouse->id]) }}"
                    class="bs-ad-btn-outline"
                >
                    Xem tất cả lịch sử
                </a>
            </div>

            <div class="bs-ad-smart-scroll-wrap"
                 data-smart-scroll
                 data-endpoint="{{ route('admin.branches.audit-logs', $centralWarehouse) }}"
                 data-cursor="{{ $nextAuditCursor ?? '' }}"
                 data-has-more="{{ !empty($hasMoreAudits) ? 'true' : 'false' }}"
                 data-scroll-mode="list">
                <div class="bs-ad-smart-scroll-container bs-ad-audit-scroll"
                     data-smart-scroll-container
                     tabindex="0"
                     role="region"
                     aria-label="Danh sách nhật ký hoạt động của Kho trung tâm">
                    @if ($recentAudits->isEmpty())
                        <div class="bs-ad-info-banner m-3" data-smart-scroll-empty>Chưa có bản ghi hoạt động nào cho Kho trung tâm.</div>
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
                                        <div class="flex-shrink-0 pt-0.5">
                                            <button type="button"
                                                    class="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:text-teal-900 focus:outline-none focus:underline"
                                                    data-audit-detail-trigger
                                                    data-audit-detail-url="{{ route('admin.audit-logs.show', $audit) }}"
                                                    aria-label="Xem chi tiết sự kiện #{{ $audit->id }}">
                                                Chi tiết
                                            </button>
                                        </div>
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

        @include('admin.audit-logs.partials.drawer')
    @endif

    <section class="bs-ad-panel bs-ad-panel-surface" aria-labelledby="central-warehouse-invariants-heading">
        <h2 id="central-warehouse-invariants-heading" class="bs-ad-panel-title">Bất biến nghiệp vụ Kho trung tâm</h2>
        <p class="bs-ad-panel-subtitle">
            Các quy tắc sau được thực thi đồng thời ở tầng dịch vụ nghiệp vụ, chính sách phân quyền và ràng buộc cơ sở dữ liệu:
        </p>

        <ul class="bs-ad-invariant-list">
            @foreach ($invariantRules as $rule)
                <li>{{ $rule }}</li>
            @endforeach
        </ul>
    </section>
@endsection
