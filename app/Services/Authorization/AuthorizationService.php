<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\AccountStatus;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Động cơ phân quyền cốt lõi RBAC của Book_Store (deny-by-default).
 *
 * Luồng đánh giá phân quyền:
 * Caller / Laravel Gate / Policy -> AuthorizationService
 * -> Kiểm tra account_status, staff_access_status, must_change_password
 * -> Tách biệt phạm vi global (`user_roles`) và branch (`branch_assignments` + `branch_role_assignments`)
 * -> Khớp chặt cặp `(user_id, branch_id)` và phạm vi vai trò/quyền.
 */
class AuthorizationService
{
    /**
     * Xác định tài khoản có tư cách nhân sự đang hoạt động ở cấp hệ thống hay không.
     *
     * Điều kiện cần nhưng KHÔNG tự cấp bất kỳ quyền RBAC nào: cả `account_status` và
     * `staff_access_status` đều phải là `active`.
     */
    public function isEffectiveStaff(?User $user): bool
    {
        if ($user === null || ! $user->exists) {
            return false;
        }

        return $user->account_status === AccountStatus::Active
            && $user->staff_access_status === StaffAccessStatus::Active;
    }

    /**
     * Kiểm tra nhân sự đã hoàn tất đổi mật khẩu tạm bắt buộc (nếu có) hay chưa.
     */
    public function hasCompletedOnboardingPasswordChange(?User $user): bool
    {
        return $user !== null && $user->exists && ! $user->must_change_password;
    }

    /**
     * Xác định tài khoản có ít nhất một phân quyền nhân sự hiệu lực ở thời điểm hiện tại.
     *
     * Điều kiện deny-by-default cho middleware khu vực nội bộ:
     * - Tài khoản phải là Effective Staff và đã hoàn tất đổi mật khẩu tạm.
     * - Có vai trò `super_admin` toàn cục hợp lệ, HOẶC có ít nhất một vai trò `global`
     *   sở hữu quyền hợp lệ trong CSDL, HOẶC có ít nhất một phân công chi nhánh đang hoạt động
     *   trên chi nhánh đang hoạt động kèm vai trò `branch` hợp lệ chứa quyền trong CSDL.
     */
    public function hasEffectiveStaffAuthorization(?User $user): bool
    {
        if (! $this->isEffectiveStaff($user) || ! $this->hasCompletedOnboardingPasswordChange($user)) {
            return false;
        }

        if ($this->isEffectiveSuperAdmin($user)) {
            return true;
        }

        $hasEffectiveGlobalRole = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.scope', RoleScope::Global->value)
            ->where('permissions.scope', RoleScope::Global->value)
            ->exists();

        if ($hasEffectiveGlobalRole) {
            return true;
        }

        return DB::table('branch_assignments')
            ->join('branches', 'branches.id', '=', 'branch_assignments.branch_id')
            ->join('branch_role_assignments', function ($join): void {
                $join->on('branch_role_assignments.user_id', '=', 'branch_assignments.user_id')
                    ->on('branch_role_assignments.branch_id', '=', 'branch_assignments.branch_id');
            })
            ->join('roles', 'roles.id', '=', 'branch_role_assignments.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('branch_assignments.user_id', $user->id)
            ->where('branch_assignments.status', BranchAssignmentStatus::Active->value)
            ->where('branches.status', BranchStatus::Active->value)
            ->where('branch_role_assignments.user_id', $user->id)
            ->where('branch_role_assignments.role_scope', RoleScope::Branch->value)
            ->where('roles.scope', RoleScope::Branch->value)
            ->where('permissions.scope', RoleScope::Branch->value)
            ->exists();
    }

    /**
     * Lấy danh sách mã vai trò phạm vi chi nhánh hiệu lực của một nhân sự tại chi nhánh cụ thể.
     *
     * Luôn ràng buộc đồng thời `user_id` và `branch_id` trên cả `branch_assignments` lẫn `branch_role_assignments`.
     *
     * @return list<string>
     */
    public function activeBranchRoleCodesFor(?User $user, ?Branch $branch): array
    {
        if (
            ! $this->isEffectiveStaff($user)
            || ! $this->hasCompletedOnboardingPasswordChange($user)
            || $branch === null
            || ! $branch->exists
            || $branch->status !== BranchStatus::Active
        ) {
            return [];
        }

        $hasActiveAssignment = DB::table('branch_assignments')
            ->where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->where('status', BranchAssignmentStatus::Active->value)
            ->exists();

        if (! $hasActiveAssignment) {
            return [];
        }

        return DB::table('branch_role_assignments')
            ->join('roles', 'roles.id', '=', 'branch_role_assignments.role_id')
            ->where('branch_role_assignments.user_id', $user->id)
            ->where('branch_role_assignments.branch_id', $branch->id)
            ->where('branch_role_assignments.role_scope', RoleScope::Branch->value)
            ->where('roles.scope', RoleScope::Branch->value)
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->all();
    }

    /**
     * Xác định tài khoản có phải là Super Administrator hiệu lực ở thời điểm hiện tại.
     *
     * Điều kiện bắt buộc:
     * 1. `account_status = active` và `staff_access_status = active`.
     * 2. Đã hoàn tất đổi mật khẩu tạm (`must_change_password = false`).
     * 3. Sở hữu bản ghi `user_roles` (`role_scope = 'global'`) nối tới `roles.code = 'super_admin'`.
     */
    public function isEffectiveSuperAdmin(?User $user): bool
    {
        if (! $this->isEffectiveStaff($user) || ! $this->hasCompletedOnboardingPasswordChange($user)) {
            return false;
        }

        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.code', 'super_admin')
            ->where('roles.scope', RoleScope::Global->value)
            ->exists();
    }

    /**
     * Đánh giá quyền phạm vi toàn hệ thống (`RoleScope::Global`).
     */
    public function hasGlobalPermission(?User $user, string $permissionCode): bool
    {
        if (! $this->isEffectiveStaff($user) || ! $this->hasCompletedOnboardingPasswordChange($user)) {
            return false;
        }

        $permission = Permission::query()->where('code', $permissionCode)->first();

        if ($permission === null || $permission->scope !== RoleScope::Global) {
            return false;
        }

        // Super Administrator hiệu lực được bỏ qua kiểm tra quyền RBAC thông thường trong phạm vi global,
        // nhưng mọi bất biến nghiệp vụ vẫn do DomainGuardService và Policy cưỡng chế độc lập.
        if ($this->isEffectiveSuperAdmin($user)) {
            return true;
        }

        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.scope', RoleScope::Global->value)
            ->where('permissions.id', $permission->id)
            ->where('permissions.scope', RoleScope::Global->value)
            ->exists();
    }

    /**
     * Đánh giá quyền phạm vi chi nhánh (`RoleScope::Branch`) trong ngữ cảnh một chi nhánh cụ thể.
     *
     * Truy vấn trực tiếp theo cả `user_id` và `branch_id` (không dùng quan hệ `HasMany` khóa đơn).
     */
    public function hasBranchPermission(?User $user, string $permissionCode, ?Branch $branch): bool
    {
        if (! $this->isEffectiveStaff($user) || ! $this->hasCompletedOnboardingPasswordChange($user)) {
            return false;
        }

        if ($branch === null || ! $branch->exists || $branch->status !== BranchStatus::Active) {
            return false;
        }

        $permission = Permission::query()->where('code', $permissionCode)->first();

        if ($permission === null || $permission->scope !== RoleScope::Branch) {
            return false;
        }

        // Super Administrator hiệu lực được phép thực thi quyền trong chi nhánh đang hoạt động,
        // nhưng KHÔNG được bỏ qua yêu cầu chi nhánh tồn tại/active hoặc các Domain Guard bất biến.
        if ($this->isEffectiveSuperAdmin($user)) {
            return true;
        }

        $hasActiveAssignment = DB::table('branch_assignments')
            ->where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->where('status', BranchAssignmentStatus::Active->value)
            ->exists();

        if (! $hasActiveAssignment) {
            return false;
        }

        return DB::table('branch_role_assignments')
            ->join('roles', 'roles.id', '=', 'branch_role_assignments.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('branch_role_assignments.user_id', $user->id)
            ->where('branch_role_assignments.branch_id', $branch->id)
            ->where('branch_role_assignments.role_scope', RoleScope::Branch->value)
            ->where('roles.scope', RoleScope::Branch->value)
            ->where('permissions.id', $permission->id)
            ->where('permissions.scope', RoleScope::Branch->value)
            ->exists();
    }

    /**
     * Lấy thực thể Kho trung tâm chuẩn duy nhất (`CENTRAL_WH`) đang hoạt động (nếu có).
     */
    public function getCentralWarehouse(): ?Branch
    {
        return Branch::query()
            ->where('type', BranchType::CentralWarehouse->value)
            ->first();
    }

    /**
     * Kiểm tra nhân sự có quyền kho trung tâm (`warehouse.*`) gắn trực tiếp với `CENTRAL_WH` đang hoạt động.
     *
     * Tuyệt đối không kế thừa từ phân công tại các chi nhánh bán lẻ khác.
     */
    public function hasCentralWarehousePermission(?User $user, string $permissionCode): bool
    {
        if (! str_starts_with($permissionCode, 'warehouse.')) {
            return false;
        }

        $centralWarehouse = $this->getCentralWarehouse();

        if ($centralWarehouse === null || $centralWarehouse->status !== BranchStatus::Active) {
            return false;
        }

        return $this->hasBranchPermission($user, $permissionCode, $centralWarehouse);
    }

    /**
     * Xác định tài khoản có quyền mở không gian Kho trung tâm (`/quan-tri/kho-trung-tam`).
     */
    public function canAccessCentralWarehouse(?User $user): bool
    {
        if ($this->hasGlobalPermission($user, 'branches.view')) {
            return true;
        }

        return $this->hasCentralWarehousePermission($user, 'warehouse.dashboard.access')
            && $this->hasCentralWarehousePermission($user, 'warehouse.view');
    }

    /**
     * Trả về danh sách mã quyền `global` hiệu lực của tài khoản.
     *
     * @return list<string>
     */
    public function effectiveGlobalPermissions(?User $user): array
    {
        if (! $this->isEffectiveStaff($user) || ! $this->hasCompletedOnboardingPasswordChange($user)) {
            return [];
        }

        if ($this->isEffectiveSuperAdmin($user)) {
            return Permission::query()
                ->where('scope', RoleScope::Global->value)
                ->orderBy('code')
                ->pluck('code')
                ->all();
        }

        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.scope', RoleScope::Global->value)
            ->where('permissions.scope', RoleScope::Global->value)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->all();
    }

    /**
     * Trả về danh sách mã quyền `branch` hiệu lực của tài khoản tại một chi nhánh cụ thể.
     *
     * @return list<string>
     */
    public function effectiveBranchPermissions(?User $user, ?Branch $branch): array
    {
        if (
            ! $this->isEffectiveStaff($user)
            || ! $this->hasCompletedOnboardingPasswordChange($user)
            || $branch === null
            || ! $branch->exists
            || $branch->status !== BranchStatus::Active
        ) {
            return [];
        }

        if ($this->isEffectiveSuperAdmin($user)) {
            return Permission::query()
                ->where('scope', RoleScope::Branch->value)
                ->orderBy('code')
                ->pluck('code')
                ->all();
        }

        $hasActiveAssignment = DB::table('branch_assignments')
            ->where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->where('status', BranchAssignmentStatus::Active->value)
            ->exists();

        if (! $hasActiveAssignment) {
            return [];
        }

        return DB::table('branch_role_assignments')
            ->join('roles', 'roles.id', '=', 'branch_role_assignments.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('branch_role_assignments.user_id', $user->id)
            ->where('branch_role_assignments.branch_id', $branch->id)
            ->where('branch_role_assignments.role_scope', RoleScope::Branch->value)
            ->where('roles.scope', RoleScope::Branch->value)
            ->where('permissions.scope', RoleScope::Branch->value)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->all();
    }

    /**
     * Trả về danh sách các vai trò phạm vi chi nhánh mà nhân sự được phép xem qua quyền `roles.view`.
     *
     * Không bao giờ trả về các vai trò phạm vi `global` (như `super_admin`).
     *
     * @return Collection<int, Role>
     */
    public function visibleBranchRolesFor(?User $user, ?Branch $branch): Collection
    {
        if (! $this->hasBranchPermission($user, 'roles.view', $branch)) {
            return new Collection;
        }

        return Role::query()
            ->where('scope', RoleScope::Branch->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * Lấy danh sách chi nhánh đang hoạt động mà tài khoản có quyền tiếp cận hợp lệ.
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(?User $user): Collection
    {
        if (! $this->isEffectiveStaff($user) || ! $this->hasCompletedOnboardingPasswordChange($user)) {
            return new Collection;
        }

        if ($this->isEffectiveSuperAdmin($user)) {
            return Branch::query()
                ->where('status', BranchStatus::Active->value)
                ->orderBy('id')
                ->get();
        }

        return Branch::query()
            ->where('status', BranchStatus::Active->value)
            ->whereExists(function ($query) use ($user): void {
                $query->selectRaw('1')
                    ->from('branch_assignments')
                    ->join('branch_role_assignments', function ($join): void {
                        $join->on('branch_role_assignments.user_id', '=', 'branch_assignments.user_id')
                            ->on('branch_role_assignments.branch_id', '=', 'branch_assignments.branch_id');
                    })
                    ->whereColumn('branch_assignments.branch_id', 'branches.id')
                    ->where('branch_assignments.user_id', $user->id)
                    ->where('branch_assignments.status', BranchAssignmentStatus::Active->value)
                    ->where('branch_role_assignments.role_scope', RoleScope::Branch->value);
            })
            ->orderBy('id')
            ->get();
    }
}
