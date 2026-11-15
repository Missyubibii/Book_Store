<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\StaffAccessStatus;
use App\Support\Auth\SessionRevoker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware dành riêng cho nhóm tuyến đường đổi mật khẩu tạm bắt buộc (alias: `staff.onboarding`).
 *
 * Tuân thủ Correction B: Tách biệt độc lập khỏi `staff.active` để nhân sự ở trạng thái `pending`
 * hoặc `active` còn giữ mật khẩu tạm (`must_change_password = true`) vẫn hoàn tất bước đổi mật khẩu,
 * trong khi nhân sự `pending` vẫn tiếp tục bị chặn khỏi mọi tuyến nghiệp vụ `staff.active`.
 */
class EnsureEligibleForPasswordOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.staff_unauthenticated'),
                ], 401);
            }

            return redirect()->guest(route('login'));
        }

        if (! $user->isActive()) {
            SessionRevoker::revokeSuspendedSession($request, $user);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.account_suspended'),
                ], 403);
            }

            return redirect()->to(route('login'))->withErrors([
                'email' => __('auth.account_suspended'),
            ]);
        }

        $eligibleStatus = in_array(
            $user->staff_access_status,
            [StaffAccessStatus::Pending, StaffAccessStatus::Active],
            true,
        );

        if (! $eligibleStatus) {
            return $this->denyResponse($request, __('auth.staff_access_denied'));
        }

        if (! $user->must_change_password) {
            return $this->denyResponse($request, __('auth.staff_onboarding_not_required'));
        }

        if ($user->isTemporaryPasswordExpired()) {
            SessionRevoker::revokeSuspendedSession($request, $user);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.temp_password_expired'),
                ], 403);
            }

            return redirect()->to(route('login'))->withErrors([
                'email' => __('auth.temp_password_expired'),
            ]);
        }

        return $next($request);
    }

    private function denyResponse(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return abort(403, $message);
    }
}
