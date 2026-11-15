<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\LoginEventType;
use App\Models\User;
use App\Support\LoginEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Tiện ích thu hồi phiên đăng nhập ngay lập tức cho các tài khoản không còn hoạt động.
 */
final class SessionRevoker
{
    public static function revokeSuspendedSession(Request $request, User $user): void
    {
        $userId = $user->id;
        $email = $user->email;

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        LoginEventLogger::log(
            eventType: LoginEventType::SessionRevoked,
            userId: $userId,
            email: $email,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
