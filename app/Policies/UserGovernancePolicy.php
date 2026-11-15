<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountStatus;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use App\Services\Authorization\DomainGuardService;

/**
 * Policy quản trị vòng đời nhân sự và đặc quyền tài khoản trên mô hình `User`.
 *
 * Mọi hành động đều bắt buộc vượt qua Self-Action Guard và Last Effective Super Admin Guard
 * trước khi kiểm tra quyền RBAC.
 */
class UserGovernancePolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly DomainGuardService $domainGuards,
    ) {}

    public function createStaff(User $actor, Branch $branch): bool
    {
        return $this->authorization->hasBranchPermission($actor, 'staff.create', $branch);
    }

    public function linkCustomerToStaff(User $actor, User $targetUser, Branch $branch): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        if ($targetUser->staff_access_status !== StaffAccessStatus::NotApplicable) {
            return false;
        }

        return $this->authorization->hasBranchPermission($actor, 'staff.link', $branch);
    }

    public function resetTempPassword(User $actor, User $targetUser): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        if ($targetUser->staff_access_status === StaffAccessStatus::NotApplicable) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'staff.reset_temp_password');
    }

    public function suspendStaff(User $actor, User $targetUser, ?Branch $branch = null): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        if ($branch !== null) {
            return $this->authorization->hasBranchPermission($actor, 'staff.suspend_branch', $branch);
        }

        if ($this->domainGuards->wouldLeaveZeroEffectiveSuperAdmins($targetUser)) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'staff.suspend');
    }

    public function reactivateStaff(User $actor, User $targetUser): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'staff.reactivate');
    }

    public function suspendAccount(User $actor, User $targetUser): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        if ($this->domainGuards->wouldLeaveZeroEffectiveSuperAdmins($targetUser)) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'users.suspend_account');
    }

    public function assignGlobalRole(User $actor, User $targetUser): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'roles.assign_global');
    }

    public function revokeGlobalRole(User $actor, User $targetUser, ?Role $role = null): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        if ($role !== null && $role->scope !== RoleScope::Global) {
            return false;
        }

        if (
            ($role === null || $role->code === 'super_admin')
            && $this->domainGuards->wouldLeaveZeroEffectiveSuperAdmins($targetUser)
        ) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'roles.assign_global');
    }

    /**
     * Kích hoạt trực tiếp tài khoản nhân sự đang ở trạng thái `pending` (chỉ dành cho Super Administrator hiệu lực).
     *
     * Bất biến bắt buộc:
     * - Không cho phép tự kích hoạt chính mình (`$actor->id !== $targetUser->id`).
     * - Chỉ áp dụng cho tài khoản đang có `account_status = active` và `staff_access_status = pending`.
     * - Bắt buộc người thực hiện là Super Administrator hiệu lực có quyền toàn hệ thống `staff.approve_activation`.
     */
    public function directActivateStaff(User $actor, User $targetUser): bool
    {
        if (
            $this->domainGuards->isSelfAction($actor, $targetUser)
            || $targetUser->account_status !== AccountStatus::Active
            || $targetUser->staff_access_status !== StaffAccessStatus::Pending
        ) {
            return false;
        }

        return $this->authorization->isEffectiveSuperAdmin($actor)
            && $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation');
    }
}
