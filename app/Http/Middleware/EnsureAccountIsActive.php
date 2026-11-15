<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\SessionRevoker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Xử lý request tiếp theo: Kiểm tra tài khoản đang đăng nhập có ở trạng thái `AccountStatus::Active` hay không.
     * Nếu bị `Suspended` hoặc không còn `Active`, lập tức thu hồi phiên làm việc, xóa session và CSRF token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            SessionRevoker::revokeSuspendedSession($request, $user);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.account_suspended'),
                ], 403);
            }

            return redirect()->to('/dang-nhap')->withErrors([
                'email' => __('auth.account_suspended'),
            ]);
        }

        return $next($request);
    }
}
