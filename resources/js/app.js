import './bootstrap';
import { initAdminUi, registerAdminAlpine } from './admin';
import { initAuthUi, registerAuthAlpine } from './auth';
import { initNotificationCenter, registerNotificationAlpine } from './notifications';
import { initStorefrontUi, registerStorefrontAlpine } from './storefront';

/**
 * Khởi tạo dùng chung cho ứng dụng Book_Store.
 * Đảm bảo các thành phần Alpine được đăng ký trước khi Alpine khởi chạy và không tạo thực thể trùng lặp.
 */
document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        registerAdminAlpine(window.Alpine);
        registerAuthAlpine(window.Alpine);
        registerNotificationAlpine(window.Alpine);
        registerStorefrontAlpine(window.Alpine);
    }
});

if (window.Alpine) {
    registerAdminAlpine(window.Alpine);
    registerAuthAlpine(window.Alpine);
    registerNotificationAlpine(window.Alpine);
    registerStorefrontAlpine(window.Alpine);
}

const bootFrontendModules = () => {
    initNotificationCenter(document);
    initAuthUi(document);
    initStorefrontUi(document);
    initAdminUi(document);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootFrontendModules);
} else {
    bootFrontendModules();
}
