<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\StaffAccessStatus;
use App\Services\Authorization\AuthorizationService;
use App\Support\Auth\SessionRevoker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware bảo vệ các tuyến đường vận hành và quản trị nội bộ dành cho nhân sự (alias: `staff.active`).
 *
 * Flow:
 * Incoming Request -> EnsureStaffAccessIsActive::handle()
 * -> Kiểm tra xác thực -> account_status === Active -> staff_access_status === Active
 * -> Hoàn tất đổi mật khẩu tạm -> Có ít nhất một phân quyền nhân sự hiệu lực
 * -> Từ chối mặc định (HTTP 401/403 hoặc thu hồi phiên nếu tài khoản bị khóa).
 */
class EnsureStaffAccessIsActive
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

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

        if ($user->staff_access_status !== StaffAccessStatus::Active) {
            $message = match ($user->staff_access_status) {
                StaffAccessStatus::Pending => __('auth.staff_pending'),
                StaffAccessStatus::Inactive => __('auth.staff_inactive'),
                default => __('auth.staff_access_denied'),
            };

            return $this->denyResponse($request, $message);
        }

        if (! $this->authorization->hasCompletedOnboardingPasswordChange($user)) {
            return $this->denyResponse($request, __('auth.staff_password_change_required'));
        }

        if (! $this->authorization->hasEffectiveStaffAuthorization($user)) {
            return $this->denyResponse($request, __('auth.staff_authorization_missing'));
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
