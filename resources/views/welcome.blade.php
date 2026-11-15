{{--
    Trang chủ Công khai Hiệu sách Book_Store (Public Storefront Homepage - Stage 01B.3.1-H)
    - Entry point: GET / (route 'home') -> HomeController::__invoke -> View 'welcome'
    - Phong cách: "Book_Store — Liquid White" (Nền trắng #FFFFFF, bề mặt #F5F5F7, nhấn xanh rừng #1E4D3B)
    - Quy tắc nội dung:
      + Khách, khách hàng đã đăng nhập và nhân sự đều truy cập bình thường như trải nghiệm độc giả.
      + Tuyệt đối không bịa đặt sách thật, giá bán, tồn kho, giảm giá hay đánh giá khi mô-đun Danh mục Sản phẩm chưa phát hành.
--}}
@extends('layouts.storefront')

@section('title', 'Book_Store — Không gian Sách in & Ấn phẩm Số')

@section('content')
    @php
        $initialQuery = trim((string) request()->query('tu-khoa', ''));
    @endphp

    {{-- Phần mở đầu hiệu sách (Hero & Điểm vào Tìm kiếm Sách) --}}
    <section class="bs-sf-hero" aria-labelledby="storefront-hero-title">
        <div class="bs-sf-container bs-sf-hero-grid">
            <div class="bs-sf-hero-main">
                <h1 id="storefront-hero-title" class="bs-sf-hero-title">
                    Hiệu sách Book_Store — Không gian Sách in &amp; Ấn phẩm Số
                </h1>
                <p class="bs-sf-hero-lead">
                    Một điểm đến cho cả sách in tại hệ thống chi nhánh Book_Store và tủ sách eBook bản quyền đồng bộ trực tiếp trên tài khoản độc giả của bạn.
                </p>

                {{-- Điểm vào Tìm kiếm Sách --}}
                <div id="tim-kiem-sach" class="bs-sf-search-block">
                    <form
                        method="GET"
                        action="{{ route('home') }}#tim-kiem-sach"
                        class="bs-sf-search-form"
                        role="search"
                        aria-label="Tìm kiếm ấn phẩm tại Book_Store"
                        data-storefront-search
                        data-initial-query="{{ $initialQuery }}"
                        @submit.prevent="submitSearch($event)"
                    >
                        <label for="sf-book-search" class="bs-sr-only">Tìm kiếm tên sách, tác giả hoặc định dạng</label>
                        <div class="bs-sf-search-input-wrap">
                            <span class="bs-sf-search-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="m21 21-4.3-4.3"/>
                                </svg>
                            </span>
                            <input
                                id="sf-book-search"
                                name="tu-khoa"
                                type="search"
                                class="bs-sf-search-input"
                                value="{{ $initialQuery }}"
                                placeholder="Tìm tên sách, tác giả hoặc ấn phẩm eBook..."
                                autocomplete="off"
                            >
                        </div>
                        <button type="submit" class="bs-sf-btn-accent bs-sf-search-submit">
                            Tìm sách
                        </button>
                    </form>

                    <div
                        class="bs-sf-search-status {{ $initialQuery !== '' ? '' : 'bs-hidden' }}"
                        data-search-feedback
                        :class="{ 'bs-hidden': !searchSubmitted }"
                        aria-live="polite"
                    >
                        <p class="bs-sf-status-text" data-search-message x-text="searchMessage">
                            @if ($initialQuery !== '')
                                Chưa có kết quả cho từ khóa "{{ $initialQuery }}" vì danh mục ấn phẩm đang được chuẩn bị và chưa mở bán đầu sách.
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Điều hướng hành động chính theo trạng thái đăng nhập --}}
                @auth
                    <div class="bs-sf-reader-banner" role="region" aria-label="Trạng thái độc giả đang đăng nhập">
                        <div class="bs-sf-reader-copy">
                            <p class="bs-sf-reader-greeting">
                                Xin chào, <strong>{{ $currentUser->name }}</strong>
                            </p>
                            <p class="bs-sf-reader-email">
                                Đang truy cập với địa chỉ <span>{{ $currentUser->email }}</span>
                            </p>
                        </div>

                        <div class="bs-sf-reader-actions">
                            @if ($needsEmailVerification)
                                <a href="{{ route('verification.notice') }}" class="bs-sf-btn-accent-sm">
                                    Xác thực địa chỉ email
                                </a>
                            @endif

                            <form method="POST" action="{{ route('logout') }}" data-auth-form x-data="authForm" @submit="handleSubmit">
                                @csrf
                                <button type="submit" class="bs-sf-btn-outline-sm" data-loading-text="Đang đăng xuất..." :disabled="submitting" :aria-busy="submitting ? 'true' : 'false'">
                                    <span data-submit-label>Đăng xuất tài khoản</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="bs-sf-hero-cta-row">
                        <a href="{{ route('register') }}" class="bs-sf-btn-accent">
                            Mở tài khoản độc giả
                        </a>
                        <a href="{{ route('login') }}" class="bs-sf-btn-outline">
                            Đăng nhập tài khoản
                        </a>
                        <a href="#khong-gian-kham-pha" class="bs-sf-text-link">
                            Khám phá định dạng phát hành
                        </a>
                    </div>
                @endauth
            </div>

            {{-- Khung giới thiệu kiến trúc 2 định dạng (xem trước không tương tác, không bịa đặt sản phẩm) --}}
            <aside class="bs-sf-format-stage" aria-label="Cấu trúc phát hành ấn phẩm của Book_Store">
                <div class="bs-sf-stage-header">
                    <span class="bs-sf-stage-badge">Trạng thái phát hành: Đang chuẩn bị kho sách</span>
                </div>

                <div class="bs-sf-format-list">
                    <div class="bs-sf-format-item">
                        <span class="bs-sf-format-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                            </svg>
                        </span>
                        <div class="bs-sf-format-body">
                            <h2 class="bs-sf-format-title">Sách in theo chi nhánh</h2>
                            <p class="bs-sf-format-desc">
                                Quản lý tồn kho tách biệt tại kho trung tâm và từng chi nhánh bán lẻ khi danh mục chính thức mở bán.
                            </p>
                        </div>
                    </div>

                    <div class="bs-sf-format-item">
                        <span class="bs-sf-format-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                                <path d="M8 7h8"/>
                                <path d="M8 11h6"/>
                            </svg>
                        </span>
                        <div class="bs-sf-format-body">
                            <h2 class="bs-sf-format-title">eBook bản quyền gắn tài khoản</h2>
                            <p class="bs-sf-format-desc">
                                Cấp quyền đọc và tải trực tuyến riêng tư theo giấy phép hợp lệ sau khi hoàn tất thanh toán.
                            </p>
                        </div>
                    </div>
                </div>

                <p class="bs-sf-stage-note">
                    Chưa có đầu sách, giá bán hoặc tồn kho mở bán công khai ở giai đoạn này.
                </p>
            </aside>
        </div>
    </section>

    {{-- Phần Khám phá Sách (Trạng thái rỗng minh bạch) --}}
    <section id="khong-gian-kham-pha" class="bs-sf-section bs-sf-section-tinted" aria-labelledby="discovery-heading">
        <div class="bs-sf-container">
            <div class="bs-sf-section-head">
                <h2 id="discovery-heading" class="bs-sf-section-title">Khám phá ấn phẩm tại Book_Store</h2>
                <p class="bs-sf-section-subtitle">
                    Mỗi tác phẩm tại Book_Store có thể phát hành đồng thời ở dạng sách in truyền thống và phiên bản eBook, với quy trình giao nhận và cấp quyền đọc riêng biệt.
                </p>
            </div>

            <div class="bs-sf-empty-shelf" role="status">
                <div class="bs-sf-empty-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                    </svg>
                </div>
                <div class="bs-sf-empty-body">
                    <h3 class="bs-sf-empty-title">Kệ sách trưng bày hiện đang trống</h3>
                    <p class="bs-sf-empty-desc">
                        Mô-đun Danh mục Sản phẩm và Tồn kho chưa được phát hành, vì vậy trang chủ không hiển thị sách mẫu giả lập, giá niêm yết hay đánh giá ảo.
                    </p>
                </div>
                <div class="bs-sf-empty-action">
                    @guest
                        <a href="{{ route('register') }}" class="bs-sf-btn-outline">Đăng ký nhận thông tin bằng tài khoản độc giả</a>
                    @else
                        @if ($needsEmailVerification)
                            <a href="{{ route('verification.notice') }}" class="bs-sf-btn-outline">Hoàn tất xác thực email</a>
                        @else
                            <a href="#danh-muc-sach" class="bs-sf-btn-outline">Xem danh mục sắp ra mắt</a>
                        @endif
                    @endguest
                </div>
            </div>
        </div>
    </section>

    {{-- Phần Danh mục Sách (Xem trước minh bạch theo định dạng) --}}
    <section id="danh-muc-sach" class="bs-sf-section" aria-labelledby="categories-heading" data-category-preview>
        <div class="bs-sf-container">
            <div class="bs-sf-section-head bs-sf-section-head-split">
                <div>
                    <h2 id="categories-heading" class="bs-sf-section-title">Danh mục sách theo định dạng</h2>
                    <p class="bs-sf-section-subtitle">
                        Chọn định dạng bên dưới để xem trạng thái chuẩn bị của từng nhóm ấn phẩm.
                    </p>
                </div>

                <div class="bs-sf-tabs" role="tablist" aria-label="Bộ lọc xem trước định dạng sách">
                    <button
                        type="button"
                        role="tab"
                        class="bs-sf-tab is-active"
                        data-category-tab="all"
                        aria-selected="true"
                        :class="{ 'is-active': selectedCategory === 'all' }"
                        :aria-selected="selectedCategory === 'all' ? 'true' : 'false'"
                        @click="selectCategory('all')"
                    >
                        Tất cả định dạng
                    </button>
                    <button
                        type="button"
                        role="tab"
                        class="bs-sf-tab"
                        data-category-tab="print"
                        aria-selected="false"
                        :class="{ 'is-active': selectedCategory === 'print' }"
                        :aria-selected="selectedCategory === 'print' ? 'true' : 'false'"
                        @click="selectCategory('print')"
                    >
                        Sách in
                    </button>
                    <button
                        type="button"
                        role="tab"
                        class="bs-sf-tab"
                        data-category-tab="ebook"
                        aria-selected="false"
                        :class="{ 'is-active': selectedCategory === 'ebook' }"
                        :aria-selected="selectedCategory === 'ebook' ? 'true' : 'false'"
                        @click="selectCategory('ebook')"
                    >
                        eBook bản quyền
                    </button>
                </div>
            </div>

            <div class="bs-sf-preview-strip" role="tabpanel" aria-live="polite">
                <div class="bs-sf-preview-row" data-category-panel="all" :class="{ 'bs-hidden': selectedCategory !== 'all' }">
                    <div class="bs-sf-preview-main">
                        <h3 class="bs-sf-preview-title">Chưa có danh mục phát hành chính thức</h3>
                        <p class="bs-sf-preview-copy">
                            Hệ thống sẽ phân loại tác phẩm theo chủ đề, nhà xuất bản, phiên bản sách in và giấy phép đọc eBook ngay khi mô-đun Danh mục Sản phẩm được phê duyệt triển khai.
                        </p>
                    </div>
                    <span class="bs-sf-preview-pill">Chờ dữ liệu danh mục</span>
                </div>

                <div class="bs-sf-preview-row bs-hidden" data-category-panel="print" :class="{ 'bs-hidden': selectedCategory !== 'print' }">
                    <div class="bs-sf-preview-main">
                        <h3 class="bs-sf-preview-title">Nhóm Sách in Đa chi nhánh</h3>
                        <p class="bs-sf-preview-copy">
                            Sách giấy sẽ được theo dõi tồn kho thực tế theo từng chi nhánh và kho trung tâm, hỗ trợ đặt trực tuyến hoặc thanh toán khi nhận hàng (COD).
                        </p>
                    </div>
                    <span class="bs-sf-preview-pill">Chưa mở bán</span>
                </div>

                <div class="bs-sf-preview-row bs-hidden" data-category-panel="ebook" :class="{ 'bs-hidden': selectedCategory !== 'ebook' }">
                    <div class="bs-sf-preview-main">
                        <h3 class="bs-sf-preview-title">Nhóm eBook Bản quyền</h3>
                        <p class="bs-sf-preview-copy">
                            Yêu cầu tài khoản độc giả để mua và đọc nội dung số riêng tư; quyền truy cập chỉ được cấp sau khi thanh toán hợp lệ được xác nhận.
                        </p>
                    </div>
                    <span class="bs-sf-preview-pill">Chưa phát hành</span>
                </div>
            </div>
        </div>
    </section>
@endsection
