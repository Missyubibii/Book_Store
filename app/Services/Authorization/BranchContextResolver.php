<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\BranchStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\Branch;
use App\Models\User;
use App\Support\Authorization\BranchContext;
use Illuminate\Http\Request;

/**
 * Bộ phân giải ngữ cảnh chi nhánh theo từng HTTP request (Stateless & Request-Scoped).
 *
 * Bảo mật:
 * - Tuyệt đối không tin cậy `branch_id` từ query string, body input, header tùy ý hay cookie thô.
 * - Chỉ đọc nguồn được hỗ trợ tường minh: tham số route `{branch}` hoặc session nội bộ đã xác thực.
 * - Luôn truy vấn lại CSDL theo cặp `(user_id, branch_id)` trên từng request để từ chối ngay phân công đã thu hồi.
 */
class BranchContextResolver
{
    public const string SESSION_KEY = 'bookstore.active_branch_id';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly DomainGuardService $domainGuards,
    ) {}

    /**
     * Lấy `BranchContext` đã phân giải gắn trên request hiện tại (nếu có).
     */
    public static function fromRequest(Request $request): ?BranchContext
    {
        $context = $request->attributes->get(BranchContext::REQUEST_ATTRIBUTE);

        return $context instanceof BranchContext ? $context : null;
    }

    /**
     * Gắn `BranchContext` vào request hiện tại (không lưu state vào singleton).
     */
    public function attachToRequest(Request $request, BranchContext $context): void
    {
        $request->attributes->set(BranchContext::REQUEST_ATTRIBUTE, $context);
    }

    /**
     * Phân giải và xác thực `BranchContext` từ HTTP request hiện tại.
     */
    public function resolveFromRequest(Request $request): BranchContext
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new DomainInvariantException(
                __('auth.staff_unauthenticated'),
                'unauthenticated_branch_access',
                401,
            );
        }

        $branchSource = $this->extractExplicitBranchSource($request);

        return $this->resolveForUserAndBranch($user, $branchSource);
    }

    /**
     * Phân giải và xác thực `BranchContext` cho một nhân sự và chi nhánh mục tiêu cụ thể.
     */
    public function resolveForUserAndBranch(User $user, int|Branch $branchOrId): BranchContext
    {
        if (! $this->authorization->isEffectiveStaff($user)) {
            throw new DomainInvariantException(
                __('auth.branch_context_denied'),
                'staff_not_effective',
                403,
            );
        }

        if (! $this->authorization->hasCompletedOnboardingPasswordChange($user)) {
            throw new DomainInvariantException(
                __('auth.staff_password_change_required'),
                'password_change_required',
                403,
            );
        }

        $branchId = $branchOrId instanceof Branch ? (int) $branchOrId->id : $branchOrId;

        if ($branchId < 1) {
            throw new DomainInvariantException(
                'Định danh chi nhánh không hợp lệ.',
                'branch_id_invalid',
                404,
            );
        }

        // Luôn nạp mới từ CSDL thay vì tin model cache cũ.
        $branch = Branch::query()->find($branchId);

        if ($branch === null) {
            throw new DomainInvariantException(
                'Chi nhánh mục tiêu không tồn tại trong hệ thống.',
                'branch_not_found',
                404,
            );
        }

        if ($branch->status !== BranchStatus::Active) {
            throw new DomainInvariantException(
                'Chi nhánh mục tiêu hiện đang ngừng hoạt động và không thể truy cập tác nghiệp.',
                'branch_inactive',
                403,
            );
        }

        if ($this->authorization->isEffectiveSuperAdmin($user)) {
            return new BranchContext(
                branch: $branch,
                user: $user,
                viaSuperAdmin: true,
                roleCodes: ['super_admin'],
                permissions: $this->authorization->effectiveBranchPermissions($user, $branch),
            );
        }

        $this->domainGuards->ensureValidBranchAssignment($user, $branch);

        $roleCodes = $this->authorization->activeBranchRoleCodesFor($user, $branch);

        if ($roleCodes === []) {
            throw new DomainInvariantException(
                'Nhân sự chưa có vai trò chi nhánh hợp lệ tại địa điểm này.',
                'branch_role_assignment_missing',
                403,
            );
        }

        return new BranchContext(
            branch: $branch,
            user: $user,
            viaSuperAdmin: false,
            roleCodes: $roleCodes,
            permissions: $this->authorization->effectiveBranchPermissions($user, $branch),
        );
    }

    /**
     * Chỉ đọc chi nhánh từ tham số route `{branch}` hoặc khóa session nội bộ `bookstore.active_branch_id`.
     */
    private function extractExplicitBranchSource(Request $request): int|Branch
    {
        $route = $request->route();

        if ($route !== null && $route->hasParameter('branch')) {
            $routeParam = $route->parameter('branch');

            if ($routeParam instanceof Branch) {
                return $routeParam;
            }

            if (is_int($routeParam) && $routeParam > 0) {
                return $routeParam;
            }

            if (is_string($routeParam) && ctype_digit($routeParam) && (int) $routeParam > 0) {
                return (int) $routeParam;
            }

            throw new DomainInvariantException(
                'Tham số chi nhánh trên đường dẫn không hợp lệ.',
                'branch_route_param_invalid',
                404,
            );
        }

        if ($request->hasSession()) {
            $sessionBranchId = $request->session()->get(self::SESSION_KEY);

            if (is_int($sessionBranchId) && $sessionBranchId > 0) {
                return $sessionBranchId;
            }

            if (is_string($sessionBranchId) && ctype_digit($sessionBranchId) && (int) $sessionBranchId > 0) {
                return (int) $sessionBranchId;
            }
        }

        throw new DomainInvariantException(
            'Chưa có ngữ cảnh chi nhánh hợp lệ được chọn cho phiên làm việc.',
            'branch_context_missing',
            403,
        );
    }
}
