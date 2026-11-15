/**
 * Hệ thống Thông báo Động Tái sử dụng — "Book_Store Quiet Glass — Dynamic Notifications" (Stage 01B.5.3)
 *
 * Flow:
 *   Laravel Session Flash / Validation Error Summary / CustomEvent('bs:notify') / window.BookStoreNotify.show(...)
 *   -> Alpine Component `bsNotificationCenter` (hoặc Progressive Controller `initNotificationCenter`)
 *   -> Hiển thị toast nổi phong cách iOS (Compact Pill <-> Expanded Toast), cập nhật ARIA role (status|alert) & aria-live
 *   -> Tự động đóng sau đúng 5000ms (5 giây) cho mọi thông báo tạm thời, tạm dừng khi hover/focus,
 *      hoặc đóng thủ công qua nút Đóng / phím Escape.
 */

export const TOAST_DURATION_MS = 5000;

export const DEFAULT_DURATIONS = Object.freeze({
    success: TOAST_DURATION_MS,
    info: TOAST_DURATION_MS,
    warning: TOAST_DURATION_MS,
    error: TOAST_DURATION_MS,
});

const VALID_TYPES = ['success', 'error', 'warning', 'info'];

function normalizePayload(raw = {}) {
    const type = VALID_TYPES.includes(raw.type) ? raw.type : 'info';
    const duration = typeof raw.duration === 'number' && raw.duration >= 0
        ? raw.duration
        : DEFAULT_DURATIONS[type];

    return {
        type,
        title: String(raw.title ?? 'Thông báo:'),
        message: String(raw.message ?? ''),
        duration,
        persistent: Boolean(raw.persistent) || duration === 0,
    };
}

/**
 * Đăng ký thành phần dữ liệu Alpine.js cho trung tâm thông báo trước khi Alpine khởi chạy.
 *
 * @param {object} Alpine Thực thể Alpine toàn cục
 */
export function registerNotificationAlpine(Alpine) {
    if (!Alpine || typeof Alpine.data !== 'function' || Alpine.__bsNotifyRegistered) {
        return;
    }

    Alpine.__bsNotifyRegistered = true;

    Alpine.data('bsNotificationCenter', () => ({
        visible: false,
        expanded: true,
        type: 'info',
        title: '',
        message: '',
        duration: TOAST_DURATION_MS,
        persistent: false,
        timerId: null,
        remainingMs: 0,
        startedAt: 0,
        lastSignature: '',

        init() {
            const rawAttr = this.$el.getAttribute('data-initial-notification');
            if (rawAttr) {
                this.$el.removeAttribute('data-initial-notification');
                try {
                    const parsed = JSON.parse(rawAttr);
                    this.show(parsed);
                } catch {
                    // Bỏ qua nếu JSON khởi tạo không hợp lệ
                }
            }
        },

        showFromEvent(event) {
            if (event && event.detail) {
                this.show(event.detail);
            }
        },

        show(payload) {
            const normalized = normalizePayload(payload);
            const signature = `${normalized.type}|${normalized.title}|${normalized.message}`;

            if (this.visible && this.lastSignature === signature) {
                return;
            }

            this.clearTimer();
            this.lastSignature = signature;
            this.type = normalized.type;
            this.title = normalized.title;
            this.message = normalized.message;
            this.duration = normalized.duration;
            this.persistent = normalized.persistent;
            this.expanded = normalized.message.length > 52 || normalized.type === 'error';
            this.visible = true;
            this.remainingMs = normalized.duration;

            if (!this.persistent && this.remainingMs > 0) {
                this.startTimer();
            }
        },

        toggleExpand() {
            this.expanded = !this.expanded;
        },

        pause() {
            if (!this.timerId || this.persistent) {
                return;
            }
            clearTimeout(this.timerId);
            this.timerId = null;
            const elapsed = Date.now() - this.startedAt;
            this.remainingMs = Math.max(400, this.remainingMs - elapsed);
        },

        resume() {
            if (!this.visible || this.persistent || this.timerId || this.remainingMs <= 0) {
                return;
            }
            this.startTimer();
        },

        startTimer() {
            this.startedAt = Date.now();
            this.timerId = setTimeout(() => {
                this.dismiss();
            }, this.remainingMs);
        },

        clearTimer() {
            if (this.timerId) {
                clearTimeout(this.timerId);
                this.timerId = null;
            }
        },

        dismiss() {
            this.clearTimer();
            this.visible = false;
            this.lastSignature = '';
        },
    }));
}

/**
 * Khởi tạo điều khiển thông báo Progressive Enhancement & API toàn cục `window.BookStoreNotify`.
 *
 * @param {Document|HTMLElement} root Gốc DOM
 */
export function initNotificationCenter(root = document) {
    if (!root || typeof root.querySelector !== 'function') {
        return;
    }

    const viewport = root.querySelector('[data-notification-center]');
    if (!viewport || viewport.dataset.bsBound === 'true') {
        return;
    }
    viewport.dataset.bsBound = 'true';

    const island = viewport.querySelector('[data-notify-island]');
    const titleEl = viewport.querySelector('[data-notify-title]');
    const messageEl = viewport.querySelector('[data-notify-message]');
    const toggleArea = viewport.querySelector('[data-notify-toggle]');
    const closeBtn = viewport.querySelector('[data-notify-close]');
    const icons = viewport.querySelectorAll('[data-notify-icon]');

    if (!island || !titleEl || !messageEl) {
        return;
    }

    let timerId = null;
    let remainingMs = 0;
    let startedAt = 0;
    let isPersistent = false;
    let isVisible = !island.classList.contains('bs-hidden');
    let isExpanded = island.classList.contains('is-expanded');
    let lastSignature = '';

    const clearTimer = () => {
        if (timerId) {
            clearTimeout(timerId);
            timerId = null;
        }
    };

    const dismiss = () => {
        clearTimer();
        isVisible = false;
        lastSignature = '';
        island.classList.add('bs-hidden');
        island.classList.remove('is-visible', 'is-compact', 'is-expanded');
    };

    const startTimer = () => {
        if (isPersistent || remainingMs <= 0) {
            return;
        }
        startedAt = Date.now();
        timerId = setTimeout(dismiss, remainingMs);
    };

    const pause = () => {
        if (!timerId || isPersistent) {
            return;
        }
        clearTimer();
        const elapsed = Date.now() - startedAt;
        remainingMs = Math.max(400, remainingMs - elapsed);
    };

    const resume = () => {
        if (!isVisible || isPersistent || timerId || remainingMs <= 0) {
            return;
        }
        startTimer();
    };

    const setExpandedState = (expanded) => {
        isExpanded = Boolean(expanded);
        island.classList.toggle('is-expanded', isExpanded);
        island.classList.toggle('is-compact', !isExpanded);
        if (toggleArea) {
            toggleArea.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        }
    };

    const renderNotification = (rawPayload) => {
        const normalized = normalizePayload(rawPayload);
        const signature = `${normalized.type}|${normalized.title}|${normalized.message}`;

        if (isVisible && lastSignature === signature) {
            return;
        }

        clearTimer();
        lastSignature = signature;

        // Gán văn bản an toàn qua textContent (chống XSS tuyệt đối)
        titleEl.textContent = normalized.title;
        messageEl.textContent = normalized.message;

        island.setAttribute('data-notify-type', normalized.type);
        const assertive = normalized.type === 'error' || normalized.type === 'warning';
        island.setAttribute('role', assertive ? 'alert' : 'status');
        island.setAttribute('aria-live', assertive ? 'assertive' : 'polite');

        icons.forEach((icon) => {
            const match = icon.getAttribute('data-notify-icon') === normalized.type;
            icon.classList.toggle('bs-hidden', !match);
        });

        isPersistent = normalized.persistent;
        remainingMs = normalized.duration;
        isVisible = true;

        island.classList.remove('bs-hidden');
        island.classList.add('is-visible');
        setExpandedState(normalized.message.length > 52 || normalized.type === 'error');

        if (!isPersistent && remainingMs > 0) {
            startTimer();
        }
    };

    // Khởi tạo từ dữ liệu server-side nếu có (và khi Alpine chưa quản lý trực tiếp)
    if (!window.Alpine) {
        const initialJson = viewport.getAttribute('data-initial-notification');
        if (initialJson) {
            viewport.removeAttribute('data-initial-notification');
            try {
                renderNotification(JSON.parse(initialJson));
            } catch {
                // Giữ nguyên trạng thái server-rendered nếu JSON lỗi
            }
        }

        if (toggleArea) {
            toggleArea.addEventListener('click', () => {
                setExpandedState(!isExpanded);
            });
            toggleArea.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    setExpandedState(!isExpanded);
                }
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', (event) => {
                event.stopPropagation();
                dismiss();
            });
        }

        island.addEventListener('mouseenter', pause);
        island.addEventListener('mouseleave', resume);
        island.addEventListener('focusin', pause);
        island.addEventListener('focusout', resume);

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isVisible) {
                dismiss();
            }
        });

        window.addEventListener('bs:notify', (event) => {
            if (event && event.detail) {
                renderNotification(event.detail);
            }
        });

        window.addEventListener('bs:notify-dismiss', () => {
            dismiss();
        });
    }

    // Cung cấp API toàn cục tái sử dụng cho các phân hệ tương lai của Book_Store
    window.BookStoreNotify = {
        TOAST_DURATION_MS,
        DEFAULT_DURATIONS,
        show(payload) {
            window.dispatchEvent(new CustomEvent('bs:notify', { detail: payload }));
        },
        dismiss() {
            window.dispatchEvent(new CustomEvent('bs:notify-dismiss'));
        },
    };
}
