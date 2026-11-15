/**
 * Luồng Tương tác Trang chủ Hiệu sách Công khai (Public Storefront Interactions - Stage 01B.3.1-H)
 *
 * Flow 1 (Popover Giỏ hàng, Tài khoản & Menu di động):
 *   User Interaction (Click nút Giỏ hàng / Tài khoản / Menu hoặc phím Escape / Click ngoài)
 *   -> Alpine Component `storefrontShell` (hoặc Progressive Handler `initStorefrontUi`)
 *   -> Client-side State Change: Cập nhật `activePanel`, `aria-expanded` và lớp `.bs-hidden`.
 *
 * Flow 2 (Tìm kiếm sách & Chuyển tab xem trước Danh mục):
 *   User Interaction (Gửi form `[data-storefront-search]` hoặc chọn `[data-category-tab]`)
 *   -> Cập nhật phản hồi trạng thái rỗng tiếng Việt minh bạch (chưa truy vấn catalog vì mô-đun sản phẩm chưa mở bán).
 */

const VALID_CATEGORIES = ['all', 'print', 'ebook'];

function buildSearchMessage(keyword) {
    const cleaned = String(keyword ?? '').trim();

    if (cleaned === '') {
        return 'Vui lòng nhập tên sách hoặc tác giả để tra cứu khi danh mục sản phẩm được mở bán.';
    }

    return `Chưa có kết quả cho từ khóa "${cleaned}" vì danh mục ấn phẩm đang được chuẩn bị và chưa mở bán đầu sách.`;
}

/**
 * Đăng ký thành phần dữ liệu Alpine.js cho giao diện hiệu sách trước khi Alpine khởi chạy.
 *
 * @param {object} Alpine Thực thể Alpine toàn cục
 */
export function registerStorefrontAlpine(Alpine) {
    if (!Alpine || typeof Alpine.data !== 'function' || Alpine.__bsStorefrontRegistered) {
        return;
    }

    Alpine.__bsStorefrontRegistered = true;

    Alpine.data('storefrontShell', () => ({
        activePanel: null,
        selectedCategory: 'all',
        searchSubmitted: false,
        searchMessage: '',

        init() {
            const form = this.$el?.querySelector('[data-storefront-search]');
            const initial = String(form?.dataset.initialQuery ?? '').trim();

            if (initial !== '') {
                this.searchSubmitted = true;
                this.searchMessage = buildSearchMessage(initial);
            }
        },

        togglePanel(name) {
            this.activePanel = this.activePanel === name ? null : name;
        },

        closePanels() {
            this.activePanel = null;
        },

        selectCategory(category) {
            this.selectedCategory = VALID_CATEGORIES.includes(category) ? category : 'all';
        },

        submitSearch(event) {
            const form = event?.target;
            const input = form?.querySelector('input[name="tu-khoa"]');
            this.searchMessage = buildSearchMessage(input?.value ?? '');
            this.searchSubmitted = true;
        },
    }));
}

/**
 * Khởi tạo tương tác cho trang chủ hiệu sách công khai (Progressive Enhancement).
 *
 * @param {Document|HTMLElement} root Gốc DOM cần gắn sự kiện
 */
export function initStorefrontUi(root = document) {
    if (!root || typeof root.querySelector !== 'function') {
        return;
    }

    const shell = root.querySelector('[data-storefront-root]');
    if (!shell || shell.dataset.bsStorefrontBound === 'true') {
        return;
    }
    shell.dataset.bsStorefrontBound = 'true';

    let activePanel = null;

    const syncPanels = () => {
        if (window.Alpine) {
            return;
        }

        const toggles = shell.querySelectorAll('[data-panel-toggle]');
        const panels = shell.querySelectorAll('[data-panel-content]');

        toggles.forEach((btn) => {
            const name = btn.getAttribute('data-panel-toggle');
            btn.setAttribute('aria-expanded', activePanel === name ? 'true' : 'false');
        });

        panels.forEach((panel) => {
            const name = panel.getAttribute('data-panel-content');
            panel.classList.toggle('bs-hidden', activePanel !== name);
        });
    };

    shell.querySelectorAll('[data-panel-toggle]').forEach((button) => {
        button.addEventListener('click', (event) => {
            if (window.Alpine) {
                return;
            }
            event.stopPropagation();
            const target = button.getAttribute('data-panel-toggle');
            activePanel = activePanel === target ? null : target;
            syncPanels();
        });
    });

    shell.querySelectorAll('[data-panel-close]').forEach((button) => {
        button.addEventListener('click', () => {
            if (window.Alpine) {
                return;
            }
            activePanel = null;
            syncPanels();
        });
    });

    document.addEventListener('click', (event) => {
        if (window.Alpine || activePanel === null) {
            return;
        }

        const clickedInsidePopover = event.target instanceof Element
            && (event.target.closest('[data-popover-container]') || event.target.closest('[data-panel-content]'));

        if (!clickedInsidePopover) {
            activePanel = null;
            syncPanels();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!window.Alpine && event.key === 'Escape' && activePanel !== null) {
            activePanel = null;
            syncPanels();
        }
    });

    // Chuyển tab xem trước danh mục
    const categoryContainer = shell.querySelector('[data-category-preview]');
    if (categoryContainer) {
        const tabs = categoryContainer.querySelectorAll('[data-category-tab]');
        const panels = categoryContainer.querySelectorAll('[data-category-panel]');

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                if (window.Alpine) {
                    return;
                }

                const selected = tab.getAttribute('data-category-tab');
                const target = VALID_CATEGORIES.includes(selected) ? selected : 'all';

                tabs.forEach((item) => {
                    const isActive = item.getAttribute('data-category-tab') === target;
                    item.classList.toggle('is-active', isActive);
                    item.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                panels.forEach((panel) => {
                    const match = panel.getAttribute('data-category-panel') === target;
                    panel.classList.toggle('bs-hidden', !match);
                });
            });
        });
    }

    // Xử lý phản hồi tìm kiếm trung thực khi chưa có dữ liệu sản phẩm
    const searchForm = shell.querySelector('[data-storefront-search]');
    const feedbackBox = shell.querySelector('[data-search-feedback]');
    const feedbackText = shell.querySelector('[data-search-message]');

    if (searchForm && feedbackBox && feedbackText) {
        searchForm.addEventListener('submit', (event) => {
            if (window.Alpine) {
                return;
            }

            event.preventDefault();
            const input = searchForm.querySelector('input[name="tu-khoa"]');
            feedbackText.textContent = buildSearchMessage(input ? input.value : '');
            feedbackBox.classList.remove('bs-hidden');
        });
    }
}
