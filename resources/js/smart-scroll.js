/**
 * Smart Scroll - Cuộn thông minh tải tăng dần phía máy chủ cho Book_Store (Stage 01B.5.7).
 *
 * Tính năng:
 * - Quan sát điểm chặn (sentinel) qua IntersectionObserver với root là chính khung cuộn nội bộ.
 * - Dự phòng nút "Tải thêm" hiển thị rõ ràng cho tương tác thủ công và trình duyệt không hỗ trợ observer.
 * - Chống trùng lặp dữ liệu bằng Set lưu trữ ID các bản ghi đã xuất hiện trong DOM.
 * - Xử lý lỗi mạng với nút "Thử lại", thông báo aria-live phục vụ trợ năng.
 * - Hỗ trợ cả chế độ danh sách thẻ (list) và bảng dữ liệu (table).
 * - Render an toàn tuyệt đối (chống XSS), tích hợp sự kiện mở chi tiết kiểm toán (drawer).
 */

const escapeHtml = (str) => {
    if (str === null || str === undefined) {
        return '';
    }
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

const renderAuditListItem = (item) => {
    const detailButton = item.detail_url
        ? `<button type="button"
                   class="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:text-teal-900 focus:outline-none focus:underline"
                   data-audit-detail-trigger
                   data-audit-detail-url="${escapeHtml(item.detail_url)}"
                   aria-label="Xem chi tiết sự kiện #${escapeHtml(item.id)}">
               Chi tiết
           </button>`
        : '';

    const targetUserBadge = item.target_user_name
        ? `<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-gray-100 text-gray-700">
               Nhân sự: ${escapeHtml(item.target_user_name)}
           </span>`
        : '';

    const branchBadge = item.branch_name
        ? `<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-blue-50 text-blue-700">
               ${escapeHtml(item.branch_name)}
           </span>`
        : '';

    const reasonRow = item.reason
        ? `<p class="mt-1 text-xs text-gray-500 italic line-clamp-2">
               "${escapeHtml(item.reason)}"
           </p>`
        : '';

    return `
        <article class="bs-ad-audit-item"
                 data-item-id="${escapeHtml(item.id)}">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-800">
                            ${escapeHtml(item.action_label)}
                        </span>
                        ${targetUserBadge}
                        ${branchBadge}
                    </div>
                    <p class="mt-1 text-xs text-gray-600">
                        <span class="font-medium text-gray-900">${escapeHtml(item.actor_name)}</span>
                        &bull;
                        <time datetime="${escapeHtml(item.occurred_at)}" class="text-gray-500">${escapeHtml(item.occurred_at)}</time>
                    </p>
                    ${reasonRow}
                </div>
                <div class="flex-shrink-0 pt-0.5">
                    ${detailButton}
                </div>
            </div>
        </article>
    `;
};

const renderAuditTableRow = (item) => {
    const detailButton = item.detail_url
        ? `<button type="button"
                   class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-teal-700 hover:text-teal-900 hover:bg-teal-50 rounded transition-colors focus:outline-none focus:ring-1 focus:ring-teal-500"
                   data-audit-detail-trigger
                   data-audit-detail-url="${escapeHtml(item.detail_url)}"
                   aria-label="Xem chi tiết sự kiện #${escapeHtml(item.id)}">
               Chi tiết
           </button>`
        : '<span class="text-xs text-gray-400">&mdash;</span>';

    return `
        <tr class="hover:bg-gray-50/80 transition-colors border-b border-gray-100" data-item-id="${escapeHtml(item.id)}">
            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                #${escapeHtml(item.id)}
            </td>
            <td class="px-4 py-3 text-xs text-gray-700 whitespace-nowrap">
                ${escapeHtml(item.occurred_at)}
            </td>
            <td class="px-4 py-3 text-xs">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-800">
                    ${escapeHtml(item.action_label)}
                </span>
            </td>
            <td class="px-4 py-3 text-xs text-gray-800">
                <div class="font-medium">${escapeHtml(item.actor_name)}</div>
                ${item.actor_email ? `<div class="text-[11px] text-gray-400">${escapeHtml(item.actor_email)}</div>` : ''}
            </td>
            <td class="px-4 py-3 text-xs text-gray-600">
                ${item.target_user_name ? `<span class="font-medium text-gray-800">${escapeHtml(item.target_user_name)}</span>` : '<span class="text-gray-400">&mdash;</span>'}
            </td>
            <td class="px-4 py-3 text-xs text-gray-600">
                ${item.branch_name ? `<span>${escapeHtml(item.branch_name)}</span>` : '<span class="text-gray-400">&mdash;</span>'}
            </td>
            <td class="px-4 py-3 text-xs text-right whitespace-nowrap">
                ${detailButton}
            </td>
        </tr>
    `;
};

export const initSmartScroll = (root = document) => {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return;
    }

    const instances = root.querySelectorAll('[data-smart-scroll]');

    instances.forEach((containerWrapper) => {
        if (containerWrapper.dataset.bsSmartScrollBound === 'true') {
            return;
        }
        containerWrapper.dataset.bsSmartScrollBound = 'true';

        const endpoint = containerWrapper.dataset.endpoint;
        if (!endpoint) {
            return;
        }

        const scrollContainer = containerWrapper.querySelector('[data-smart-scroll-container]');
        const itemsContainer = containerWrapper.querySelector('[data-smart-scroll-items]');
        const sentinel = containerWrapper.querySelector('[data-smart-scroll-sentinel]');
        const loadMoreBtn = containerWrapper.querySelector('[data-smart-scroll-load-more]');
        const statusEl = containerWrapper.querySelector('[data-smart-scroll-status]');
        const retryBtn = containerWrapper.querySelector('[data-smart-scroll-retry]');
        const emptyEl = containerWrapper.querySelector('[data-smart-scroll-empty]');
        const mode = containerWrapper.dataset.scrollMode || 'list';

        let nextCursor = containerWrapper.dataset.cursor || null;
        let hasMore = containerWrapper.dataset.hasMore === 'true';
        let isLoading = false;
        let abortController = null;

        // Lưu trữ ID các phần tử đã có trong DOM để chống trùng lặp
        const seenIds = new Set();
        if (itemsContainer) {
            itemsContainer.querySelectorAll('[data-item-id]').forEach((el) => {
                const id = el.dataset.itemId;
                if (id) {
                    seenIds.add(String(id));
                }
            });
        }

        const updateFooterUi = (state, message = '') => {
            if (statusEl) {
                statusEl.textContent = message;
                statusEl.classList.toggle('bs-hidden', !message);
            }

            if (retryBtn) {
                retryBtn.classList.toggle('bs-hidden', state !== 'error');
            }

            if (loadMoreBtn) {
                if (state === 'loading') {
                    loadMoreBtn.disabled = true;
                    loadMoreBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    loadMoreBtn.textContent = 'Đang tải...';
                    loadMoreBtn.classList.remove('bs-hidden');
                } else if (hasMore && state !== 'error') {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'bs-hidden');
                    loadMoreBtn.textContent = 'Tải thêm';
                } else {
                    loadMoreBtn.classList.add('bs-hidden');
                }
            }

            if (sentinel) {
                sentinel.classList.toggle('bs-hidden', !hasMore || state === 'loading' || state === 'error');
            }
        };

        const fetchNextBatch = async () => {
            if (isLoading || !hasMore || !nextCursor) {
                return;
            }

            isLoading = true;
            updateFooterUi('loading', 'Đang tải thêm dữ liệu...');

            if (abortController) {
                abortController.abort();
            }
            abortController = new AbortController();

            try {
                const separator = endpoint.includes('?') ? '&' : '?';
                const url = `${endpoint}${separator}cursor=${encodeURIComponent(nextCursor)}&format=json`;

                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: abortController.signal,
                });

                if (!response.ok) {
                    throw new Error(`Lỗi kết nối máy chủ (${response.status})`);
                }

                const result = await response.json();
                const items = Array.isArray(result?.data) ? result.data : [];
                nextCursor = result?.next_cursor || null;
                hasMore = Boolean(result?.has_more && nextCursor);

                containerWrapper.dataset.cursor = nextCursor || '';
                containerWrapper.dataset.hasMore = hasMore ? 'true' : 'false';

                if (itemsContainer) {
                    let newHtml = '';
                    let addedCount = 0;

                    items.forEach((item) => {
                        const idStr = String(item.id);
                        if (!seenIds.has(idStr)) {
                            seenIds.add(idStr);
                            newHtml += mode === 'table' ? renderAuditTableRow(item) : renderAuditListItem(item);
                            addedCount++;
                        }
                    });

                    if (newHtml !== '') {
                        itemsContainer.insertAdjacentHTML('beforeend', newHtml);
                    }

                    if (emptyEl && seenIds.size > 0) {
                        emptyEl.classList.add('bs-hidden');
                    }
                }

                isLoading = false;

                if (hasMore) {
                    updateFooterUi('idle', '');
                } else {
                    updateFooterUi('end', seenIds.size > 0 ? 'Đã hiển thị toàn bộ nhật ký gần đây.' : '');
                }
            } catch (err) {
                if (err.name === 'AbortError') {
                    return;
                }
                isLoading = false;
                updateFooterUi('error', 'Không thể tải thêm dữ liệu. Vui lòng bấm thử lại.');
            }
        };

        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', () => {
                fetchNextBatch();
            });
        }

        if (retryBtn) {
            retryBtn.addEventListener('click', () => {
                fetchNextBatch();
            });
        }

        // Thiết lập IntersectionObserver với root là scrollContainer nội bộ
        if ('IntersectionObserver' in window && scrollContainer && sentinel) {
            const observer = new IntersectionObserver(
                (entries) => {
                    const entry = entries[0];
                    if (entry && entry.isIntersecting && hasMore && !isLoading) {
                        fetchNextBatch();
                    }
                },
                {
                    root: scrollContainer,
                    rootMargin: '100px 0px',
                    threshold: 0.1,
                }
            );

            observer.observe(sentinel);
        }

        // Khởi tạo trạng thái ban đầu của footer
        if (!hasMore) {
            updateFooterUi('end', seenIds.size > 0 ? 'Đã hiển thị toàn bộ nhật ký gần đây.' : '');
        } else {
            updateFooterUi('idle', '');
        }
    });
};
