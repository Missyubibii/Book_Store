import { initSmartScroll } from './smart-scroll.js';

/**
 * Tương tác khu vực Quản trị Book_Store (Stage 01B.4 -> 01B.5.1).
 *
 * Flow:
 * User Interaction (Click nút kích hoạt hành động nhạy cảm / Checkbox xác nhận / Nút Hủy / Escape)
 * -> Alpine Component (`adminBranchForm`, `branchStatusConfirm` khi có Alpine)
 *    HOẶC Progressive Handler `initAdminUi` (`[data-confirm-disclosure]`, `[data-admin-form]`)
 * -> Client-side State Change: Mở/đóng panel bằng `.bs-hidden` + `aria-expanded` + reset checkbox/trạng thái tạm khi đóng.
 */

export const registerAdminAlpine = (Alpine) => {
    if (!Alpine || typeof Alpine.data !== 'function' || window.__bsAdminAlpineRegistered) {
        return;
    }

    window.__bsAdminAlpineRegistered = true;

    Alpine.data('adminBranchForm', () => ({
        submitting: false,

        handleSubmit() {
            if (this.submitting) {
                return false;
            }

            this.submitting = true;
            return true;
        },

        normalizeCodeInput(event) {
            const input = event?.target;
            if (!input || typeof input.value !== 'string') {
                return;
            }

            const cursorStart = input.selectionStart;
            const upperValue = input.value.toUpperCase();

            if (upperValue !== input.value) {
                input.value = upperValue;
                if (typeof cursorStart === 'number') {
                    input.setSelectionRange(cursorStart, cursorStart);
                }
            }
        },
    }));

    Alpine.data('branchStatusConfirm', (initiallyOpen = false) => ({
        confirmOpen: Boolean(initiallyOpen),
        confirmedChecked: false,
        reasonText: '',
        submitting: false,

        openConfirm() {
            this.confirmedChecked = false;
            this.submitting = false;
            this.confirmOpen = true;
        },

        closeConfirm() {
            this.confirmOpen = false;
            this.confirmedChecked = false;
            this.reasonText = '';
            this.submitting = false;
        },

        handleSubmit(event) {
            if (!this.confirmedChecked) {
                event?.preventDefault();
                return false;
            }

            if (this.submitting) {
                event?.preventDefault();
                return false;
            }

            this.submitting = true;
            return true;
        },
    }));
};

const bindAdminFormGuards = (root) => {
    const forms = root.querySelectorAll('form[data-admin-form]');
    forms.forEach((form) => {
        if (form.dataset.bsAdminFormBound === 'true') {
            return;
        }
        form.dataset.bsAdminFormBound = 'true';

        form.addEventListener('submit', (event) => {
            if (window.Alpine && form.closest('[x-data]')) {
                return;
            }

            if (form.dataset.bsSubmitting === 'true') {
                event.preventDefault();
                return;
            }

            form.dataset.bsSubmitting = 'true';

            const submitButton = form.querySelector('button[type="submit"]:not([data-confirm-submit])');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                const labelEl = submitButton.querySelector('[data-submit-label]') ?? submitButton;
                const loadingText = submitButton.getAttribute('data-loading-text');
                if (loadingText) {
                    labelEl.textContent = loadingText;
                }
            }
        });
    });
};

const bindConfirmDisclosures = (root) => {
    const containers = root.querySelectorAll('[data-confirm-disclosure]');
    containers.forEach((container) => {
        if (container.dataset.bsConfirmBound === 'true') {
            return;
        }
        container.dataset.bsConfirmBound = 'true';

        if (window.Alpine && container.hasAttribute('x-data')) {
            return;
        }

        const triggerWrap = container.querySelector('[data-confirm-trigger-wrap]');
        const openButton = container.querySelector('[data-confirm-open]');
        const panel = container.querySelector('[data-confirm-panel]');
        const cancelButton = container.querySelector('[data-confirm-cancel]');
        const checkbox = container.querySelector('[data-confirm-checkbox]');
        const submitButton = container.querySelector('[data-confirm-submit]');
        const resetInputs = container.querySelectorAll('[data-confirm-reset-input]');
        const form = panel?.tagName === 'FORM' ? panel : panel?.querySelector('form');

        if (!openButton || !panel || !submitButton) {
            return;
        }

        let isSubmitting = false;

        const syncSubmitState = () => {
            const acknowledged = checkbox ? Boolean(checkbox.checked) : true;
            submitButton.disabled = isSubmitting || !acknowledged;
        };

        const setOpenState = (open, { resetInputsOnToggle = false } = {}) => {
            isSubmitting = false;

            if (resetInputsOnToggle) {
                if (checkbox) {
                    checkbox.checked = false;
                }
                resetInputs.forEach((field) => {
                    field.value = '';
                });
            }

            panel.removeAttribute('x-cloak');
            panel.classList.toggle('bs-hidden', !open);
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');

            if (triggerWrap) {
                triggerWrap.classList.toggle('bs-hidden', open);
            }
            openButton.setAttribute('aria-expanded', open ? 'true' : 'false');

            syncSubmitState();
        };

        // Luôn đồng bộ trạng thái khởi tạo đóng (trừ trường hợp mở lại sau validation error server)
        const initiallyOpen = container.getAttribute('data-initial-open') === 'true';
        setOpenState(initiallyOpen, { resetInputsOnToggle: !initiallyOpen });

        openButton.addEventListener('click', () => {
            setOpenState(true, { resetInputsOnToggle: true });
            const focusTarget = checkbox ?? panel;
            focusTarget?.focus?.();
        });

        cancelButton?.addEventListener('click', () => {
            setOpenState(false, { resetInputsOnToggle: true });
            openButton.focus();
        });

        checkbox?.addEventListener('change', () => {
            syncSubmitState();
        });

        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.classList.contains('bs-hidden')) {
                event.preventDefault();
                setOpenState(false, { resetInputsOnToggle: true });
                openButton.focus();
            }
        });

        form?.addEventListener('submit', (event) => {
            if (checkbox && !checkbox.checked) {
                event.preventDefault();
                syncSubmitState();
                return;
            }

            if (isSubmitting) {
                event.preventDefault();
                return;
            }

            isSubmitting = true;
            syncSubmitState();
            submitButton.setAttribute('aria-busy', 'true');
        });
    });
};

const bindFormDisclosures = (root) => {
    const containers = root.querySelectorAll('[data-form-disclosure]');
    containers.forEach((container) => {
        if (container.dataset.bsFormDisclosureBound === 'true') {
            return;
        }
        container.dataset.bsFormDisclosureBound = 'true';

        const toggleButtons = container.querySelectorAll('[data-disclosure-toggle]');
        const closeButtons = container.querySelectorAll('[data-disclosure-close]');
        const panel = container.querySelector('[data-disclosure-panel]');
        const summary = container.querySelector('[data-disclosure-summary]');

        if (!panel || toggleButtons.length === 0) {
            return;
        }

        const primaryToggle = toggleButtons[0];
        const openLabel = primaryToggle.getAttribute('data-label-open') || primaryToggle.textContent.trim();
        const closeLabel = primaryToggle.getAttribute('data-label-close') || 'Đóng chỉnh sửa';
        let isOpen = container.getAttribute('data-initial-open') === 'true';

        const applyState = (open, { focusTarget = null } = {}) => {
            isOpen = Boolean(open);
            panel.classList.toggle('bs-hidden', !isOpen);
            panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

            if (summary) {
                summary.classList.toggle('bs-hidden', isOpen);
            }

            toggleButtons.forEach((btn) => {
                btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                const labelEl = btn.querySelector('[data-disclosure-label]') ?? btn;
                labelEl.textContent = isOpen ? closeLabel : openLabel;
            });

            if (focusTarget) {
                focusTarget.focus?.();
            }
        };

        applyState(isOpen);

        toggleButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const nextOpen = !isOpen;
                const firstInput = nextOpen
                    ? panel.querySelector('input:not([type="hidden"]), select, textarea')
                    : primaryToggle;
                applyState(nextOpen, { focusTarget: firstInput });
            });
        });

        closeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                applyState(false, { focusTarget: primaryToggle });
            });
        });

        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isOpen) {
                event.preventDefault();
                applyState(false, { focusTarget: primaryToggle });
            }
        });
    });
};

const bindAuditDetailDrawer = (root) => {
    const drawer = root.querySelector('[data-audit-drawer]');

    if (!drawer || drawer.dataset.bsAuditDrawerBound === 'true') {
        return;
    }
    drawer.dataset.bsAuditDrawerBound = 'true';

    const titleEl = drawer.querySelector('[data-audit-drawer-title]');
    const statusEl = drawer.querySelector('[data-audit-drawer-status]');
    const metaEl = drawer.querySelector('[data-audit-drawer-meta]');
    const changesEl = drawer.querySelector('[data-audit-drawer-changes]');
    const closeButtons = drawer.querySelectorAll('[data-audit-drawer-close]');
    let lastTrigger = null;

    const closeDrawer = () => {
        drawer.classList.add('bs-hidden');
        drawer.setAttribute('aria-hidden', 'true');
        lastTrigger?.focus?.();
        lastTrigger = null;
    };

    const appendMetaRow = (label, value, href = null) => {
        const row = document.createElement('div');
        row.className = 'bs-ad-meta-row';
        const dt = document.createElement('dt');
        dt.className = 'bs-ad-meta-label';
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.className = 'bs-ad-meta-value';
        if (href) {
            const link = document.createElement('a');
            link.href = href;
            link.className = 'bs-ad-audit-link';
            link.textContent = value;
            dd.appendChild(link);
        } else {
            dd.textContent = value;
        }
        row.appendChild(dt);
        row.appendChild(dd);
        metaEl.appendChild(row);
    };

    const appendKvBox = (heading, obj) => {
        const entries = Object.entries(obj || {});
        if (entries.length === 0) {
            return false;
        }
        const box = document.createElement('div');
        box.className = 'bs-ad-audit-kv-box';
        const strong = document.createElement('strong');
        strong.textContent = heading;
        box.appendChild(strong);
        const ul = document.createElement('ul');
        ul.className = 'bs-ad-audit-kv-list';
        entries.forEach(([k, v]) => {
            const li = document.createElement('li');
            const keySpan = document.createElement('span');
            keySpan.className = 'bs-ad-meta-label';
            keySpan.textContent = `${k}: `;
            li.appendChild(keySpan);
            const valText = typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v);
            li.appendChild(document.createTextNode(valText));
            ul.appendChild(li);
        });
        box.appendChild(ul);
        changesEl.appendChild(box);
        return true;
    };

    const openDrawer = async (trigger) => {
        lastTrigger = trigger;
        const url = trigger.getAttribute('data-detail-url') || trigger.getAttribute('data-audit-detail-url') || trigger.dataset.auditDetailUrl || trigger.dataset.detailUrl;
        if (!url) {
            return;
        }

        titleEl.textContent = 'Đang tải chi tiết sự kiện...';
        statusEl.textContent = 'Đang truy xuất dữ liệu kiểm toán...';
        statusEl.classList.remove('bs-hidden');
        metaEl.innerHTML = '';
        metaEl.classList.add('bs-hidden');
        changesEl.innerHTML = '';
        changesEl.classList.add('bs-hidden');

        drawer.classList.remove('bs-hidden');
        drawer.setAttribute('aria-hidden', 'false');
        closeButtons[0]?.focus?.();

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Không thể tải chi tiết sự kiện.');
            }

            const payload = await response.json();
            const d = payload?.data;
            if (!d) {
                throw new Error('Dữ liệu chi tiết không hợp lệ.');
            }

            titleEl.textContent = `${d.action_label} (#${d.id})`;
            statusEl.classList.add('bs-hidden');

            appendMetaRow('Mã hành động:', d.action_value);
            appendMetaRow('Thời điểm:', d.occurred_at);
            appendMetaRow('Người thực hiện:', d.actor);
            if (d.target_user) {
                appendMetaRow('Tài khoản mục tiêu:', d.target_user.name, d.target_user.url);
            }
            if (d.branch) {
                appendMetaRow('Đơn vị / Chi nhánh:', d.branch);
            }
            if (d.reason) {
                appendMetaRow('Lý do / Diễn giải:', d.reason);
            }
            metaEl.classList.remove('bs-hidden');

            const hasOld = appendKvBox('Giá trị trước thay đổi', d.old_values);
            const hasNew = appendKvBox('Giá trị sau thay đổi', d.new_values);
            if (hasOld || hasNew) {
                changesEl.classList.remove('bs-hidden');
            }
        } catch {
            titleEl.textContent = 'Không thể hiển thị chi tiết';
            statusEl.textContent = 'Không thể tải dữ liệu sự kiện kiểm toán. Vui lòng thử lại.';
            statusEl.classList.remove('bs-hidden');
        }
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-audit-detail-trigger]');
        if (trigger) {
            event.preventDefault();
            openDrawer(trigger);
        }
    });

    closeButtons.forEach((btn) => {
        btn.addEventListener('click', closeDrawer);
    });

    drawer.addEventListener('click', (event) => {
        if (event.target === drawer) {
            closeDrawer();
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drawer.getAttribute('aria-hidden') === 'false') {
            event.preventDefault();
            closeDrawer();
        }
    });
};

export const initAdminUi = (root = document) => {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return;
    }

    const codeInputs = root.querySelectorAll('[data-branch-code-input]');
    codeInputs.forEach((input) => {
        if (input.dataset.bsBound === '1') {
            return;
        }
        input.dataset.bsBound = '1';

        input.addEventListener('input', () => {
            const cursorStart = input.selectionStart;
            const upperValue = input.value.toUpperCase();
            if (upperValue !== input.value) {
                input.value = upperValue;
                if (typeof cursorStart === 'number') {
                    input.setSelectionRange(cursorStart, cursorStart);
                }
            }
        });

        input.addEventListener('blur', () => {
            input.value = input.value.trim().toUpperCase();
        });
    });

    bindAdminFormGuards(root);
    bindConfirmDisclosures(root);
    bindFormDisclosures(root);
    bindAuditDetailDrawer(root);
    initSmartScroll(root);
};
