<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Models\Branch;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;

/**
 * Policy quản lý quyền truy cập và bất biến trên mô hình `Branch`.
 *
 * Lưu ý: `Gate::before` không áp dụng cho các phương thức Policy nhằm bảo đảm
 * bất biến bảo vệ kho trung tâm và trạng thái chi nhánh luôn được thực thi đầy đủ.
 */
class BranchPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->authorization->hasGlobalPermission($actor, 'branches.view');
    }

    public function viewCentralWarehouse(User $actor, ?Branch $centralWarehouse = null): bool
    {
        if ($this->authorization->hasGlobalPermission($actor, 'branches.view')) {
            return true;
        }

        if (
            $centralWarehouse === null
            || $centralWarehouse->type !== BranchType::CentralWarehouse
            || $centralWarehouse->status !== BranchStatus::Active
        ) {
            return false;
        }

        return $this->authorization->hasCentralWarehousePermission($actor, 'warehouse.dashboard.access')
            && $this->authorization->hasCentralWarehousePermission($actor, 'warehouse.view');
    }

    public function view(User $actor, Branch $branch): bool
    {
        if ($this->authorization->hasGlobalPermission($actor, 'branches.view')) {
            return true;
        }

        return $this->authorization->hasBranchPermission($actor, 'branch_ops.access', $branch);
    }

    public function create(User $actor): bool
    {
        return $this->authorization->hasGlobalPermission($actor, 'branches.create');
    }

    public function update(User $actor, Branch $branch): bool
    {
        return $this->authorization->hasGlobalPermission($actor, 'branches.update');
    }

    /**
     * Kho trung tâm không bao giờ được phép xóa vật lý, kể cả bởi Super Administrator.
     */
    public function delete(User $actor, Branch $branch): bool
    {
        if ($branch->type === BranchType::CentralWarehouse) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'branches.update');
    }

    public function accessOperations(User $actor, Branch $branch): bool
    {
        return $this->authorization->hasBranchPermission($actor, 'branch_ops.access', $branch);
    }

    public function assignStaff(User $actor, Branch $branch): bool
    {
        return $branch->status === BranchStatus::Active
            && $this->authorization->hasBranchPermission($actor, 'branches.assign_staff', $branch);
    }

    public function assignBranchRole(User $actor, Branch $branch): bool
    {
        return $branch->status === BranchStatus::Active
            && $this->authorization->hasBranchPermission($actor, 'roles.assign_branch', $branch);
    }

    public function viewBranchRoles(User $actor, Branch $branch): bool
    {
        return $branch->status === BranchStatus::Active
            && $this->authorization->hasBranchPermission($actor, 'roles.view', $branch);
    }
}
