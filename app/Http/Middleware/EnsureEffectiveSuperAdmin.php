<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Authorization\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware xác thực quyền Quản trị viên cấp cao toàn hệ thống đang hiệu lực (alias: `super_admin.effective`).
 *
 * Flow:
 * Request (sau `auth`, `account.active`, `staff.active`)
 * -> EnsureEffectiveSuperAdmin::handle()
 * -> AuthorizationService::isEffectiveSuperAdmin($user)
 * -> Cho phép đi tiếp hoặc từ chối HTTP 401/403 (deny-by-default).
 */
class EnsureEffectiveSuperAdmin
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

        if (! $this->authorization->isEffectiveSuperAdmin($user)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.super_admin_required'),
                ], 403);
            }

            return abort(403, __('auth.super_admin_required'));
        }

        return $next($request);
    }
}
