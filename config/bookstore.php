<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Xác thực email Demo cục bộ (Chỉ dành cho môi trường local)
    |--------------------------------------------------------------------------
    | Tắt hoàn toàn theo mặc định. Chỉ kích hoạt khi APP_ENV=local và biến
    | BOOKSTORE_DEMO_EMAIL_VERIFICATION=true được cấu hình tường minh.
    */
    'demo_email_verification' => env('APP_ENV') === 'local'
        && filter_var(env('BOOKSTORE_DEMO_EMAIL_VERIFICATION', false), FILTER_VALIDATE_BOOL),
];
