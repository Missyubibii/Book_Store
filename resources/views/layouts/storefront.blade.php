{{--
    Bố cục Trang chủ & Hiệu sách Công khai (Public Storefront Layout - Stage 01B.3.1-H)
    - Phong cách thiết kế: "Book_Store — Liquid White" (Nền trắng #FFFFFF, bề mặt trung tính #F5F5F7, chữ đậm #1D1D1F, nhấn xanh rừng #1E4D3B)
    - Tài nguyên Frontend (qua Laravel Vite):
      + CSS: resources/css/app.css (tự nhập resources/css/storefront.css và resources/css/notifications.css)
      + JS: resources/js/app.js (khởi tạo resources/js/storefront.js, auth.js và notifications.js)
    - Điều hướng:
      + Khách (Guest): Đăng nhập (/dang-nhap), Mở tài khoản độc giả (/dang-ky)
      + Độc giả đã đăng nhập (Authenticated): Menu tài khoản, trạng thái xác thực email (/xac-thuc-email) và Đăng xuất (/dang-xuat)
      + Cửa sổ giỏ hàng trống minh bạch (chưa mở luồng thanh toán)
--}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Book_Store — Hiệu sách Trực tuyến & Thư viện eBook')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bs-sf-body">
    <a href="#main-content" class="bs-skip-link">Chuyển đến nội dung chính</a>

    <div class="bs-sf-shell" data-storefront-root x-data="storefrontShell" @keydown.escape.window="closePanels()">
        <header class="bs-sf-header">
            <div class="bs-sf-header-inner">
                <div class="bs-sf-brand-group">
                    <a href="{{ route('home') }}" class="bs-sf-brand" aria-label="Trang chủ Book_Store">
                        <span class="bs-sf-brand-mark" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                            </svg>
                        </span>
                        <span class="bs-sf-brand-name">Book_Store</span>
                    </a>

                    <nav class="bs-sf-nav-desktop" aria-label="Điều hướng hiệu sách">
                        <a href="#khong-gian-kham-pha" class="bs-sf-nav-link">Khám phá</a>
                        <a href="#danh-muc-sach" class="bs-sf-nav-link">Danh mục sách</a>
                        <a href="#tim-kiem-sach" class="bs-sf-nav-link">Tìm kiếm</a>
                    </nav>
                </div>

                <div class="bs-sf-actions">
                    {{-- Điểm vào Giỏ hàng (trạng thái trống minh bạch cho tới khi có mô-đun Giỏ hàng) --}}
                    <div class="bs-sf-popover-wrap" data-popover-container="cart">
                        <button
                            type="button"
                            class="bs-sf-icon-btn"
                            data-panel-toggle="cart"
                            aria-controls="sf-cart-panel"
                            aria-expanded="false"
                            :aria-expanded="activePanel === 'cart' ? 'true' : 'false'"
                            @click="togglePanel('cart')"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/>
                                <path d="M3 6h18"/>
                                <path d="M16 10a4 4 0 0 1-8 0"/>
                            </svg>
                            <span class="bs-sf-cart-label">Giỏ hàng</span>
                            <span class="bs-sf-cart-count" aria-label="0 sản phẩm">0</span>
                        </button>

                        <div
                            id="sf-cart-panel"
                            class="bs-sf-popover bs-hidden"
                            data-panel-content="cart"
                            :class="{ 'bs-hidden': activePanel !== 'cart' }"
                            role="region"
                            aria-label="Thông tin giỏ hàng"
                        >
                            <div class="bs-sf-popover-head">
                                <h2 class="bs-sf-popover-title">Giỏ hàng của bạn</h2>
                                <button type="button" class="bs-sf-popover-close" data-panel-close="cart" @click="closePanels()" aria-label="Đóng bảng giỏ hàng">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M18 6 6 18"/>
                                        <path d="m6 6 12 12"/>
                                    </svg>
                                </button>
                            </div>
                            <p class="bs-sf-popover-copy">
                                Giỏ hàng hiện đang trống. Chức năng thêm sách in và eBook vào giỏ hàng sẽ hoạt động khi mô-đun Danh mục Sản phẩm và Giỏ hàng được triển khai.
                            </p>
                            <div class="bs-sf-popover-actions">
                                @auth
                                    <a href="#danh-muc-sach" class="bs-sf-btn-ghost" data-panel-close="cart" @click="closePanels()">
                                        Xem cấu trúc danh mục
                                    </a>
                                @else
                                    <a href="{{ route('login') }}" class="bs-sf-btn-accent-sm">
                                        Đăng nhập tài khoản
                                    </a>
                                @endauth
                            </div>
                        </div>
                    </div>

                    {{-- Điều hướng Khách hoặc Tài khoản Độc giả --}}
                    @auth
                        <div class="bs-sf-popover-wrap bs-sf-auth-desktop" data-popover-container="account">
                            <button
                                type="button"
                                class="bs-sf-account-trigger"
                                data-panel-toggle="account"
                                aria-controls="sf-account-panel"
                                aria-expanded="false"
                                :aria-expanded="activePanel === 'account' ? 'true' : 'false'"
                                @click="togglePanel('account')"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                                <span class="bs-sf-account-name">{{ auth()->user()->name }}</span>
                            </button>

                            <div
                                id="sf-account-panel"
                                class="bs-sf-popover bs-hidden"
                                data-panel-content="account"
                                :class="{ 'bs-hidden': activePanel !== 'account' }"
                                role="region"
                                aria-label="Điều hướng tài khoản độc giả"
                            >
                                <div class="bs-sf-popover-head">
                                    <h2 class="bs-sf-popover-title">Tài khoản độc giả</h2>
                                    <button type="button" class="bs-sf-popover-close" data-panel-close="account" @click="closePanels()" aria-label="Đóng trình đơn tài khoản">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M18 6 6 18"/>
                                            <path d="m6 6 12 12"/>
                                        </svg>
                                    </button>
                                </div>

                                <div class="bs-sf-account-meta">
                                    <p class="bs-sf-account-fullname">{{ auth()->user()->name }}</p>
                                    <p class="bs-sf-account-email">{{ auth()->user()->email }}</p>
                                    @if (auth()->user()->hasVerifiedEmail())
                                        <p class="bs-sf-verify-pill is-verified">Email đã xác thực</p>
                                    @else
                                        <p class="bs-sf-verify-pill is-pending">Email chưa xác thực</p>
                                    @endif
                                </div>

                                <div class="bs-sf-popover-actions">
                                    @if (
                                        auth()->user()->must_change_password
                                        && in_array(auth()->user()->staff_access_status, [\App\Enums\StaffAccessStatus::Pending, \App\Enums\StaffAccessStatus::Active], true)
                                    )
                                        <a href="{{ route('staff.onboarding.password.edit') }}" class="bs-sf-btn-accent-sm">
                                            Đổi mật khẩu bắt buộc
                                        </a>
                                    @elseif (app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
                                        <a href="{{ route('admin.dashboard') }}" class="bs-sf-btn-accent-sm">
                                            Không gian quản trị hệ thống
                                        </a>
                                    @endif

                                    @unless (auth()->user()->hasVerifiedEmail())
                                        <a href="{{ route('verification.notice') }}" class="bs-sf-btn-accent-sm">
                                            Xác thực địa chỉ email
                                        </a>
                                    @endunless

                                    <a href="{{ route('account.security') }}" class="bs-sf-btn-outline-sm">
                                        Bảo mật &amp; Phương thức đăng nhập
                                    </a>

                                    <form method="POST" action="{{ route('logout') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
                                        @csrf
                                        <button type="submit" class="bs-sf-btn-ghost" data-loading-text="Đang đăng xuất..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                                            <span data-submit-label>Đăng xuất tài khoản</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="bs-sf-guest-desktop">
                            <a href="{{ route('login') }}" class="bs-sf-btn-outline">Đăng nhập</a>
                            <a href="{{ route('register') }}" class="bs-sf-btn-accent">Đăng ký</a>
                        </div>
                    @endauth

                    <button
                        type="button"
                        class="bs-sf-menu-toggle"
                        data-panel-toggle="mobile-menu"
                        aria-controls="sf-mobile-drawer"
                        aria-expanded="false"
                        :aria-expanded="activePanel === 'mobile-menu' ? 'true' : 'false'"
                        @click="togglePanel('mobile-menu')"
                        aria-label="Mở trình đơn điều hướng"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="4" x2="20" y1="6" y2="6"/>
                            <line x1="4" x2="20" y1="12" y2="12"/>
                            <line x1="4" x2="20" y1="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Ngăn điều hướng di động / cửa sổ hẹp --}}
            <div
                id="sf-mobile-drawer"
                class="bs-sf-mobile-drawer bs-hidden"
                data-panel-content="mobile-menu"
                :class="{ 'bs-hidden': activePanel !== 'mobile-menu' }"
            >
                <nav class="bs-sf-mobile-nav" aria-label="Điều hướng di động">
                    <a href="#tim-kiem-sach" class="bs-sf-mobile-link" data-panel-close="mobile-menu" @click="closePanels()">Tìm kiếm sách</a>
                    <a href="#khong-gian-kham-pha" class="bs-sf-mobile-link" data-panel-close="mobile-menu" @click="closePanels()">Khám phá ấn phẩm</a>
                    <a href="#danh-muc-sach" class="bs-sf-mobile-link" data-panel-close="mobile-menu" @click="closePanels()">Danh mục sách</a>
                </nav>

                <div class="bs-sf-mobile-account">
                    @auth
                        <div class="bs-sf-account-meta">
                            <p class="bs-sf-account-fullname">{{ auth()->user()->name }}</p>
                            <p class="bs-sf-account-email">{{ auth()->user()->email }}</p>
                        </div>

                        @if (
                            auth()->user()->must_change_password
                            && in_array(auth()->user()->staff_access_status, [\App\Enums\StaffAccessStatus::Pending, \App\Enums\StaffAccessStatus::Active], true)
                        )
                            <a href="{{ route('staff.onboarding.password.edit') }}" class="bs-sf-btn-accent-sm">
                                Đổi mật khẩu bắt buộc
                            </a>
                        @elseif (app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
                            <a href="{{ route('admin.dashboard') }}" class="bs-sf-btn-accent-sm">
                                Không gian quản trị hệ thống
                            </a>
                        @endif

                        @unless (auth()->user()->hasVerifiedEmail())
                            <a href="{{ route('verification.notice') }}" class="bs-sf-btn-accent-sm">
                                Xác thực địa chỉ email
                            </a>
                        @endunless

                        <form method="POST" action="{{ route('logout') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
                            @csrf
                            <button type="submit" class="bs-sf-btn-ghost" data-loading-text="Đang đăng xuất..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                                <span data-submit-label>Đăng xuất tài khoản</span>
                            </button>
                        </form>
                    @else
                        <div class="bs-sf-mobile-auth-grid">
                            <a href="{{ route('login') }}" class="bs-sf-btn-outline">Đăng nhập</a>
                            <a href="{{ route('register') }}" class="bs-sf-btn-accent">Mở tài khoản độc giả</a>
                        </div>
                    @endauth
                </div>
            </div>
        </header>

        <x-notification-center error-title="Thông báo hệ thống:" />

        <main id="main-content" class="bs-sf-main">
            @yield('content')
        </main>

        <footer class="bs-sf-footer">
            <div class="bs-sf-footer-inner">
                <div class="bs-sf-footer-brand">
                    <a href="{{ route('home') }}" class="bs-sf-brand" aria-label="Về đầu trang Book_Store">
                        <span class="bs-sf-brand-mark" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                            </svg>
                        </span>
                        <span class="bs-sf-brand-name">Book_Store</span>
                    </a>
                    <p class="bs-sf-footer-copy">
                        Hệ thống hiệu sách một thương hiệu bán sách in theo chi nhánh và ấn phẩm eBook bản quyền gắn với tài khoản độc giả.
                    </p>
                </div>

                <div class="bs-sf-footer-columns">
                    <div class="bs-sf-footer-col">
                        <h2 class="bs-sf-footer-heading">Hiệu sách</h2>
                        <ul class="bs-sf-footer-list">
                            <li><a href="#tim-kiem-sach" class="bs-sf-footer-link">Tìm kiếm ấn phẩm</a></li>
                            <li><a href="#khong-gian-kham-pha" class="bs-sf-footer-link">Khám phá sách in &amp; eBook</a></li>
                            <li><a href="#danh-muc-sach" class="bs-sf-footer-link">Danh mục sách</a></li>
                        </ul>
                    </div>

                    <div class="bs-sf-footer-col">
                        <h2 class="bs-sf-footer-heading">Tài khoản độc giả</h2>
                        <ul class="bs-sf-footer-list">
                            <li><a href="{{ route('home') }}" class="bs-sf-footer-link">Trang chủ hiệu sách</a></li>
                            @auth
                                @unless (auth()->user()->hasVerifiedEmail())
                                    <li><a href="{{ route('verification.notice') }}" class="bs-sf-footer-link">Xác thực địa chỉ email</a></li>
                                @endunless
                                <li>
                                    <form method="POST" action="{{ route('logout') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
                                        @csrf
                                        <button type="submit" class="bs-sf-footer-btn" data-loading-text="Đang đăng xuất..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                                            <span data-submit-label>Đăng xuất tài khoản</span>
                                        </button>
                                    </form>
                                </li>
                            @else
                                <li><a href="{{ route('login') }}" class="bs-sf-footer-link">Đăng nhập</a></li>
                                <li><a href="{{ route('register') }}" class="bs-sf-footer-link">Mở tài khoản độc giả</a></li>
                            @endauth
                        </ul>
                    </div>
                </div>
            </div>

            <div class="bs-sf-footer-bottom">
                <span>&copy; {{ date('Y') }} Book_Store</span>
                <span>Không gian Sách in Đa chi nhánh &amp; Thư viện eBook Bản quyền</span>
            </div>
        </footer>
    </div>
</body>
</html>
