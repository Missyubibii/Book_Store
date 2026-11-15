/**
 * Luồng Tương tác Giao diện Xác thực (Authentication UI Interactions - Stage 01A.3.4)
 *
 * Flow 1 (Ẩn/Hiện mật khẩu):
 *   User Interaction (Click nút mắt / Phím Space-Enter)
 *   -> Alpine.js Component `authPasswordField` (hoặc Progressive Handler `[data-password-toggle]`)
 *   -> Client-side State Change: Chuyển đổi `type="password|text"`, cập nhật `aria-pressed`, `aria-label` tiếng Việt và icon SVG.
 *
 * Flow 2 (Gửi biểu mẫu xác thực):
 *   User Interaction (Submit form `form[data-auth-form]`)
 *   -> Alpine.js Component `authForm` (hoặc Submit Handler)
 *   -> Client-side State Change: Khóa nút gửi (`disabled`, `aria-busy="true"`), hiển thị nhãn trạng thái đang xử lý
 *   -> HTTP POST Request tới Laravel Route (`login`, `register`, `verification.send`, `logout`)
 *   -> Controller xử lý & trả về Redirect / Validation Errors tiếng Việt.
 */

/**
 * Đăng ký các thành phần dữ liệu Alpine.js cho biểu mẫu xác thực trước khi Alpine khởi chạy.
 *
 * @param {object} Alpine Thực thể Alpine toàn cục
 */
export function registerAuthAlpine(Alpine) {
    if (!Alpine || typeof Alpine.data !== 'function' || Alpine.__bsAuthRegistered) {
        return;
    }

    Alpine.__bsAuthRegistered = true;

    Alpine.data('authPasswordField', (showLabel = 'Hiện mật khẩu', hideLabel = 'Ẩn mật khẩu') => ({
        revealed: false,
        toggle() {
            this.revealed = !this.revealed;
        },
        get inputType() {
            return this.revealed ? 'text' : 'password';
        },
        get ariaPressed() {
            return this.revealed ? 'true' : 'false';
        },
        get ariaLabel() {
            return this.revealed ? hideLabel : showLabel;
        },
    }));

    Alpine.data('authForm', () => ({
        submitting: false,
        handleSubmit() {
            this.submitting = true;
        },
    }));
}

/**
 * Khởi tạo tương tác cho các màn hình xác thực (Progressive Enhancement & đồng bộ trạng thái ARIA).
 *
 * @param {Document|HTMLElement} root Gốc DOM cần gắn sự kiện
 */
export function initAuthUi(root = document) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return;
    }

    // 1. Chuyển đổi ẩn/hiện mật khẩu (nếu không do Alpine điều khiển trực tiếp)
    const toggleButtons = root.querySelectorAll('[data-password-toggle]');
    toggleButtons.forEach((button) => {
        if (button.dataset.bsBound === 'true') {
            return;
        }
        button.dataset.bsBound = 'true';

        button.addEventListener('click', () => {
            if (window.Alpine && button.closest('[x-data]')) {
                return;
            }

            const targetId = button.getAttribute('data-password-toggle');
            const input = targetId ? document.getElementById(targetId) : null;
            if (!input) {
                return;
            }

            const isPassword = input.getAttribute('type') === 'password';
            const showLabel = button.getAttribute('data-label-show') || 'Hiện mật khẩu';
            const hideLabel = button.getAttribute('data-label-hide') || 'Ẩn mật khẩu';

            input.setAttribute('type', isPassword ? 'text' : 'password');
            button.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            button.setAttribute('aria-label', isPassword ? hideLabel : showLabel);

            const iconShow = button.querySelector('[data-icon-show]');
            const iconHide = button.querySelector('[data-icon-hide]');
            if (iconShow && iconHide) {
                iconShow.classList.toggle('bs-hidden', isPassword);
                iconHide.classList.toggle('bs-hidden', !isPassword);
            }
        });
    });

    // 2. Ngăn gửi trùng biểu mẫu & phản hồi trạng thái đang xử lý
    const authForms = root.querySelectorAll('form[data-auth-form]');
    authForms.forEach((form) => {
        if (form.dataset.bsBound === 'true') {
            return;
        }
        form.dataset.bsBound = 'true';

        form.addEventListener('submit', () => {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn || submitBtn.disabled) {
                return;
            }

            const loadingText = submitBtn.getAttribute('data-loading-text');
            const labelEl = submitBtn.querySelector('[data-submit-label]');
            if (loadingText && labelEl) {
                labelEl.textContent = loadingText;
            }

            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
        });
    });

    // 3. Đưa tiêu điểm (focus) vào trường không hợp lệ đầu tiên sau khi xác thực thất bại
    const firstInvalidField = root.querySelector(
        'form[data-auth-form] input[aria-invalid="true"], form[data-auth-form] select[aria-invalid="true"], form[data-auth-form] textarea[aria-invalid="true"]'
    );
    if (firstInvalidField && typeof firstInvalidField.focus === 'function') {
        firstInvalidField.focus();
    }
}

