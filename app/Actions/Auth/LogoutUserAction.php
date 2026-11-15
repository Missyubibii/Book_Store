<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginEventType;
use App\Support\LoginEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutUserAction
{
    /**
     * Đăng xuất người dùng an toàn, hủy session, làm mới CSRF token và ghi nhật ký.
     */
    public function execute(Request $request): void
    {
        $user = $request->user();

        if ($user) {
            LoginEventLogger::log(
                eventType: LoginEventType::Logout,
                userId: $user->id,
                email: $user->email,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );
        }

        // Đăng xuất khỏi guard web
        Auth::guard('web')->logout();

        // Hủy session hiện tại và tạo token mới
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
