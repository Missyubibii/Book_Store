{{--
    Màn hình Quản lý tài khoản hệ thống Book_Store
    - Entry point: GET /quan-tri/tai-khoan (`admin.accounts.index`) -> AccountManagementController::index
    - Hợp nhất quản lý Tài khoản nhân sự và Tài khoản khách hàng trên cùng giao diện.
    - Hiển thị chính xác: Xác thực email, Phương thức đã liên kết và Đăng nhập gần nhất.
--}}
@extends('layouts.admin')

@section('title', 'Quản lý tài khoản — Book_Store')
@section('header_title', 'Quản lý tài khoản')

@section('content')
<div class="bs-ad-dashboard-canvas">
    {{-- ==================== 1. PAGE HEADER ==================== --}}
    <x-admin.page-header
        title="Quản lý tài khoản"
        subtitle="Quản lý tài khoản nhân sự và tài khoản khách hàng, phân công công tác, trạng thái xác thực và phương thức đăng nhập."
    >
        <x-slot:breadcrumb>
            <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
                <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Quản lý tài khoản</span>
            </nav>
        </x-slot:breadcrumb>

        @if ($tab === 'staff')
            <x-slot:actions>
                @if (! empty($canApproveProposals))
                    <x-admin.button variant="secondary" :href="route('admin.staff.approvals.index')">
                        Phê duyệt nhân sự{{ ($pendingProposalsCount ?? 0) > 0 ? ' ('.$pendingProposalsCount.')' : '' }}
                    </x-admin.button>
                @endif

                @if (! empty($canLinkStaff))
                    <x-admin.button variant="secondary" :href="route('admin.staff.link.create')">
                        Liên kết khách hàng
                    </x-admin.button>
                @endif

                @if (! empty($canCreateStaff))
                    <x-admin.button variant="primary" :href="route('admin.staff.create')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <span>Tạo nhân sự mới</span>
                    </x-admin.button>
                @endif
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- ==================== 2. LINE TABS (KHÔNG CUỘN DỌC) ==================== --}}
    <x-admin.line-tabs label="Phân nhóm tài khoản">
        <a
            href="{{ route('admin.accounts.index', ['tab' => 'staff']) }}"
            class="bs-ui-line-tab {{ $tab === 'staff' ? 'is-active' : '' }}"
            role="tab"
            aria-selected="{{ $tab === 'staff' ? 'true' : 'false' }}"
        >
            <span>Tài khoản nhân sự</span>
            <span class="bs-ui-line-tab-count">{{ number_format($staffCount ?? 0, 0, ',', '.') }}</span>
        </a>
        <a
            href="{{ route('admin.accounts.index', ['tab' => 'customer']) }}"
            class="bs-ui-line-tab {{ $tab === 'customer' ? 'is-active' : '' }}"
            role="tab"
            aria-selected="{{ $tab === 'customer' ? 'true' : 'false' }}"
        >
            <span>Tài khoản khách hàng</span>
            <span class="bs-ui-line-tab-count">{{ number_format($customerCount ?? 0, 0, ',', '.') }}</span>
        </a>
    </x-admin.line-tabs>

    {{-- ==================== 3. CONTENT CARD + COMPACT FILTER BAR + TABLE ==================== --}}
    <x-admin.content-card
        :title="$tab === 'staff' ? 'Danh sách tài khoản nhân sự' : 'Danh sách tài khoản khách hàng'"
        heading-id="heading-accounts-list"
    >
        <x-slot:headerActions>
            <span class="bs-ad-muted-meta">
                Hiển thị {{ $users->firstItem() ?? 0 }} – {{ $users->lastItem() ?? 0 }} / {{ number_format($users->total(), 0, ',', '.') }} tài khoản
            </span>
        </x-slot:headerActions>

        {{-- Thanh tìm kiếm & lọc gọn gàng --}}
        <x-admin.filter-toolbar :action="route('admin.accounts.index')">
            <input type="hidden" name="tab" value="{{ $tab }}" />

            <x-admin.search-field
                id="acc-search"
                name="q"
                :value="$search"
                label="Từ khóa tìm kiếm tài khoản"
                :placeholder="$tab === 'staff' ? 'Tìm theo tên, email, ID hoặc mã nhân sự...' : 'Tìm theo tên, email hoặc ID độc giả...'"
            />

            <x-admin.filter-select
                id="acc-status"
                name="status"
                label="Lọc theo trạng thái tài khoản"
            >
                <option value="">Tất cả trạng thái</option>
                <option value="active" @selected($statusFilter === 'active')>Đang hoạt động</option>
                <option value="suspended" @selected($statusFilter === 'suspended')>Đang bị khóa</option>
            </x-admin.filter-select>

            @if ($tab === 'staff')
                <x-admin.filter-select
                    id="acc-staff-status"
                    name="staff_status"
                    label="Lọc theo quyền nhân sự"
                >
                    <option value="">Tất cả quyền nhân sự</option>
                    <option value="active" @selected(($staffStatusFilter ?? '') === 'active')>Hoạt động</option>
                    <option value="pending" @selected(($staffStatusFilter ?? '') === 'pending')>Chờ kích hoạt</option>
                    <option value="inactive" @selected(($staffStatusFilter ?? '') === 'inactive')>Ngừng hoạt động</option>
                </x-admin.filter-select>

                <x-admin.filter-select
                    id="acc-branch"
                    name="branch_id"
                    label="Lọc theo chi nhánh"
                >
                    <option value="">Tất cả chi nhánh</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(($branchFilter ?? '') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </x-admin.filter-select>
            @endif

            <x-admin.filter-select
                id="acc-provider"
                name="provider"
                label="Lọc theo phương thức đã liên kết"
            >
                <option value="">Tất cả phương thức</option>
                <option value="password" @selected($providerFilter === 'password')>Mật khẩu</option>
                <option value="google" @selected($providerFilter === 'google')>Google</option>
                <option value="both" @selected($providerFilter === 'both')>Mật khẩu và Google</option>
            </x-admin.filter-select>

            <x-slot:actions>
                @if ($search !== '' || $statusFilter || $providerFilter || ($staffStatusFilter ?? null) || ($branchFilter ?? null))
                    <x-admin.button variant="secondary" :href="route('admin.accounts.index', ['tab' => $tab])">
                        Đặt lại
                    </x-admin.button>
                @endif
                <x-admin.button variant="primary" type="submit">
                    Áp dụng lọc
                </x-admin.button>
            </x-slot:actions>
        </x-admin.filter-toolbar>

        @if ($users->isEmpty())
            <x-admin.empty-state
                title="Không tìm thấy tài khoản phù hợp"
                description="Không có bản ghi nào khớp với điều kiện lọc hoặc từ khóa hiện tại."
            >
                <x-admin.button variant="secondary" :href="route('admin.accounts.index', ['tab' => $tab])">
                    Xóa bộ lọc tìm kiếm
                </x-admin.button>
            </x-admin.empty-state>
        @else
            {{-- Bảng dữ liệu Desktop / Tablet --}}
            <x-admin.data-table>
                <x-slot:head>
                    <tr>
                        @if ($tab === 'staff')
                            <th>NHÂN SỰ</th>
                            <th>CHỨC DANH / ĐƠN VỊ</th>
                            <th>XÁC THỰC EMAIL</th>
                            <th>TRẠNG THÁI</th>
                            <th>PHƯƠNG THỨC ĐÃ LIÊN KẾT</th>
                            <th class="bs-ad-th-action">THAO TÁC</th>
                        @else
                            <th>KHÁCH HÀNG</th>
                            <th>XÁC THỰC EMAIL</th>
                            <th>TRẠNG THÁI</th>
                            <th>PHƯƠNG THỨC ĐÃ LIÊN KẾT</th>
                            <th>NGÀY ĐĂNG KÝ</th>
                            <th class="bs-ad-th-action">THAO TÁC</th>
                        @endif
                    </tr>
                </x-slot:head>

                @foreach ($users as $u)
                    @php
                        $authMethodsLabel = $u->authenticationMethodsLabel();
                        $lastLoginLabel = $u->lastSuccessfulLoginMethodLabel();
                        $hasGoogle = $u->hasLinkedGoogleIdentity();
                        $hasPassword = $u->hasUsablePasswordAuthentication();
                        $nameParts = preg_split('/\s+/u', trim((string) $u->name)) ?: [];
                        $initials = count($nameParts) >= 2
                            ? mb_strtoupper(mb_substr($nameParts[0], 0, 1).mb_substr(end($nameParts), 0, 1))
                            : mb_strtoupper(mb_substr((string) $u->name, 0, 2));
                    @endphp
                    <tr>
                        @if ($tab === 'staff')
                            <td>
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="bs-ad-emp-avatar" aria-hidden="true">{{ $initials }}</span>
                                    <div class="bs-ad-user-cell">
                                        <span class="bs-ad-user-name">{{ $u->name }}</span>
                                        <span class="bs-ad-user-email">{{ $u->email }}</span>
                                        <span class="bs-ad-branch-code-tag">{{ $u->staffProfile?->staff_code ?? ('ID #' . $u->id) }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @php
                                    $primaryBranch = $u->branchAssignments->firstWhere('status', 'active')?->branch;
                                @endphp
                                <div class="bs-ad-position-cell">
                                    <span class="bs-ad-position-title">{{ $u->staffProfile?->position ?? 'Chưa thiết lập' }}</span>
                                    <span class="bs-ad-branch-name">{{ $primaryBranch?->name ?? 'Chưa phân công' }}</span>
                                </div>
                            </td>
                            <td>
                                @if ($u->hasVerifiedEmail())
                                    <x-admin.status-badge variant="success" :dot="true">Đã xác thực</x-admin.status-badge>
                                @else
                                    <x-admin.status-badge variant="warning" :dot="true">Chưa xác thực</x-admin.status-badge>
                                @endif
                            </td>
                            <td>
                                <div class="bs-ad-auth-meta-stack">
                                    @if ($u->account_status === \App\Enums\AccountStatus::Active)
                                        <x-admin.status-badge variant="success" :dot="true">Hoạt động</x-admin.status-badge>
                                    @else
                                        <x-admin.status-badge variant="danger" :dot="true">Bị khóa</x-admin.status-badge>
                                    @endif

                                    @if ($u->staff_access_status === \App\Enums\StaffAccessStatus::Pending)
                                        <x-admin.status-badge variant="warning">NV: Chờ kích hoạt</x-admin.status-badge>
                                    @elseif ($u->staff_access_status === \App\Enums\StaffAccessStatus::Inactive)
                                        <x-admin.status-badge variant="neutral">NV: Ngừng hoạt động</x-admin.status-badge>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="bs-ad-auth-meta-stack">
                                    <x-admin.status-badge :variant="$hasGoogle ? 'info' : 'neutral'">
                                        {{ $authMethodsLabel }}
                                    </x-admin.status-badge>
                                    <span class="bs-ad-last-login-note">Đăng nhập gần nhất: {{ $lastLoginLabel }}</span>
                                </div>
                            </td>
                            <td class="bs-ad-td-action">
                                <x-admin.button variant="primary" size="sm" :href="route('admin.staff.show', $u)">
                                    Xem chi tiết
                                </x-admin.button>
                            </td>
                        @else
                            <td>
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="bs-ad-emp-avatar" aria-hidden="true">{{ $initials }}</span>
                                    <div class="bs-ad-user-cell">
                                        <span class="bs-ad-user-name">{{ $u->name }}</span>
                                        <span class="bs-ad-user-email">{{ $u->email }}</span>
                                        <span class="bs-ad-branch-code-tag">KH #{{ $u->id }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($u->hasVerifiedEmail())
                                    <x-admin.status-badge variant="success" :dot="true">Đã xác thực</x-admin.status-badge>
                                @else
                                    <x-admin.status-badge variant="warning" :dot="true">Chưa xác thực</x-admin.status-badge>
                                @endif
                            </td>
                            <td>
                                @if ($u->account_status === \App\Enums\AccountStatus::Active)
                                    <x-admin.status-badge variant="success" :dot="true">Hoạt động</x-admin.status-badge>
                                @else
                                    <x-admin.status-badge variant="danger" :dot="true">Bị khóa</x-admin.status-badge>
                                @endif
                            </td>
                            <td>
                                <div class="bs-ad-auth-meta-stack">
                                    <x-admin.status-badge :variant="$hasGoogle ? 'info' : 'neutral'">
                                        {{ $authMethodsLabel }}
                                    </x-admin.status-badge>
                                    <span class="bs-ad-last-login-note">Đăng nhập gần nhất: {{ $lastLoginLabel }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="bs-ad-date-cell">{{ format_datetime($u->created_at) }}</span>
                            </td>
                            <td class="bs-ad-td-action">
                                <x-admin.button variant="primary" size="sm" :href="route('admin.accounts.customer.show', $u)">
                                    Xem chi tiết
                                </x-admin.button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-admin.data-table>

            {{-- Danh sách thẻ Mobile (< 768px) --}}
            <div class="bs-ad-mobile-cards">
                @foreach ($users as $u)
                    @php
                        $authMethodsLabel = $u->authenticationMethodsLabel();
                        $lastLoginLabel = $u->lastSuccessfulLoginMethodLabel();
                    @endphp
                    <article class="bs-ad-mobile-card">
                        <div class="bs-ad-mobile-card-head">
                            <div>
                                <h3 class="bs-ad-user-name">{{ $u->name }}</h3>
                                <p class="bs-ad-user-email">{{ $u->email }}</p>
                            </div>
                            @if ($u->account_status === \App\Enums\AccountStatus::Active)
                                <x-admin.status-badge variant="success" :dot="true">Hoạt động</x-admin.status-badge>
                            @else
                                <x-admin.status-badge variant="danger" :dot="true">Bị khóa</x-admin.status-badge>
                            @endif
                        </div>

                        <dl class="bs-ad-mobile-card-meta">
                            <div>
                                <dt>Xác thực email</dt>
                                <dd>{{ $u->hasVerifiedEmail() ? 'Đã xác thực' : 'Chưa xác thực' }}</dd>
                            </div>
                            <div>
                                <dt>Phương thức đã liên kết</dt>
                                <dd>{{ $authMethodsLabel }}</dd>
                            </div>
                            <div>
                                <dt>Đăng nhập gần nhất</dt>
                                <dd>{{ $lastLoginLabel }}</dd>
                            </div>
                        </dl>

                        <div class="bs-ad-mobile-card-actions">
                            <x-admin.button
                                variant="primary"
                                class="bs-ad-btn-full"
                                :href="$tab === 'staff' ? route('admin.staff.show', $u) : route('admin.accounts.customer.show', $u)"
                            >
                                Xem chi tiết
                            </x-admin.button>
                        </div>
                    </article>
                @endforeach
            </div>

            <x-admin.pagination :paginator="$users" />
        @endif
    </x-admin.content-card>
</div>
@endsection
