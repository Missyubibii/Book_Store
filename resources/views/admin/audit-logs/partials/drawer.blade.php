{{--
    Ngăn kéo chi tiết sự kiện kiểm toán dùng chung (Stage 01B.5.7)
    - Tải dữ liệu JSON làm sạch theo yêu cầu (`admin.audit-logs.show`), mặc định ẩn.
    - Hỗ trợ xem chi tiết sự kiện từ trang Nhật ký hoạt động, Hồ sơ nhân sự và Chi nhánh/Kho.
--}}
<div
    id="audit-detail-drawer"
    class="bs-ad-drawer-backdrop bs-hidden"
    data-audit-drawer
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="audit-drawer-title"
>
    <div class="bs-ad-drawer-panel" role="document">
        <header class="bs-ad-drawer-head">
            <div>
                <p class="bs-ad-eyebrow">Chi tiết sự kiện kiểm toán</p>
                <h2 id="audit-drawer-title" class="bs-ad-panel-title" data-audit-drawer-title>Đang tải...</h2>
            </div>
            <button
                type="button"
                class="bs-ad-btn-outline bs-ad-btn-sm"
                data-audit-drawer-close
                aria-label="Đóng chi tiết sự kiện"
            >
                Đóng
            </button>
        </header>

        <div class="bs-ad-drawer-body" data-audit-drawer-body>
            <p class="bs-ad-help" data-audit-drawer-status>Đang tải dữ liệu kiểm toán...</p>
            <dl class="bs-ad-meta-list bs-hidden" data-audit-drawer-meta></dl>
            <div class="bs-ad-audit-kv-grid bs-hidden" data-audit-drawer-changes></div>
        </div>
    </div>
</div>
