<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\AccountStatus;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StaffActivationProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hợp đồng bảo vệ bất biến nghiệp vụ độc lập với `Gate::before` (Correction A & C).
 *
 * Mọi Policy và Action đột biến ở Stage 01B.3.2+ bắt buộc phải đi qua các phương thức này
 * để bảo đảm ngay cả Super Administrator hợp lệ cũng không thể vượt qua các rào cản bất biến.
 */
class DomainGuardService
{
    /**
     * Kiểm tra xem tác nhân có đang tự tác động lên chính tài khoản của mình hay không.
     */
    public function isSelfAction(User $actor, User $target): bool
    {
        return $actor->exists && $target->exists && (int) $actor->id === (int) $target->id;
    }

    /**
     * Chặn tuyệt đối thao tác tự thay đổi trạng thái nhân sự, vai trò hoặc phê duyệt của chính mình (Decision 2.6).
     * Đồng thời từ chối mặc định nếu một trong hai bản ghi chưa tồn tại trong CSDL.
     */
    public function ensureNotSelfAction(
        User $actor,
        User $target,
        string $message = 'Bạn không được phép thực hiện thao tác quản trị hoặc phân quyền lên chính tài khoản của mình.',
    ): void {
        if (! $actor->exists || ! $target->exists) {
            throw new DomainInvariantException(
                'Tài khoản thực hiện hoặc tài khoản mục tiêu không hợp lệ.',
                'invalid_actor_or_target',
                422,
            );
        }

        if ($this->isSelfAction($actor, $target)) {
            throw new DomainInvariantException($message, 'self_action_forbidden', 403);
        }
    }

    /**
     * Xác thực ngữ cảnh chi nhánh tồn tại và đang hoạt động (`BranchStatus::Active`).
     */
    public function ensureValidActiveBranchContext(?Branch $branch): Branch
    {
        if ($branch === null || ! $branch->exists) {
            throw new DomainInvariantException(
                'Ngữ cảnh chi nhánh làm việc không tồn tại hoặc chưa được chọn.',
                'branch_context_missing',
                422,
            );
        }

        if ($branch->status !== BranchStatus::Active) {
            throw new DomainInvariantException(
                'Chi nhánh mục tiêu hiện đang ngừng hoạt động và không thể thực hiện tác nghiệp.',
                'branch_inactive',
                422,
            );
        }

        return $branch;
    }

    /**
     * Cấm sử dụng kho trung tâm (`central_warehouse`) cho các nghiệp vụ chỉ dành cho chi nhánh bán lẻ.
     */
    public function ensureRetailBranchOperationAllowed(?Branch $branch): Branch
    {
        $activeBranch = $this->ensureValidActiveBranchContext($branch);

        if ($activeBranch->type !== BranchType::RetailBranch) {
            throw new DomainInvariantException(
                'Kho trung tâm tách biệt hoàn toàn về vận hành và không thể dùng cho nghiệp vụ chỉ dành cho chi nhánh bán lẻ.',
                'central_warehouse_not_retail_branch',
                422,
            );
        }

        return $activeBranch;
    }

    /**
     * Bảo vệ bất biến của kho trung tâm (không xóa, không hạ thành retail_branch, không ngừng hoạt động, không trùng lặp).
     */
    public function ensureCentralWarehouseMutationAllowed(
        Branch $branch,
        BranchType $targetType,
        BranchStatus $targetStatus,
        bool $isDeleting = false,
    ): void {
        if ($branch->type === BranchType::CentralWarehouse) {
            if ($isDeleting) {
                throw new DomainInvariantException(
                    'Không được phép xóa kho trung tâm duy nhất của hệ thống Book_Store.',
                    'central_warehouse_delete_forbidden',
                    422,
                );
            }

            if ($targetType !== BranchType::CentralWarehouse) {
                throw new DomainInvariantException(
                    'Kho trung tâm không bao giờ được phép chuyển đổi loại sang chi nhánh bán lẻ.',
                    'central_warehouse_type_immutable',
                    422,
                );
            }

            if ($targetStatus !== BranchStatus::Active) {
                throw new DomainInvariantException(
                    'Kho trung tâm bắt buộc phải luôn duy trì trạng thái đang hoạt động.',
                    'central_warehouse_must_remain_active',
                    422,
                );
            }

            return;
        }

        if ($targetType === BranchType::CentralWarehouse) {
            $this->ensureCanEstablishCentralWarehouse($branch->id);
        }
    }

    /**
     * Xác nhận chưa tồn tại một kho trung tâm khác trong hệ thống (Decision 2.3).
     */
    public function ensureCanEstablishCentralWarehouse(?int $ignoreBranchId = null): void
    {
        $query = Branch::query()->where('type', BranchType::CentralWarehouse->value);

        if ($ignoreBranchId !== null) {
            $query->where('id', '!=', $ignoreBranchId);
        }

        if ($query->exists()) {
            throw new DomainInvariantException(
                'Hệ thống chỉ cho phép tồn tại duy nhất một kho trung tâm.',
                'central_warehouse_already_exists',
                422,
            );
        }
    }

    /**
     * Bảo đảm vai trò chỉ được gán đúng phạm vi (`global` hoặc `branch`).
     */
    public function ensureRoleScopeMatchesTarget(Role $role, RoleScope $expectedScope): void
    {
        if ($role->scope !== $expectedScope) {
            throw new DomainInvariantException(
                sprintf(
                    "Vai trò '%s' thuộc phạm vi '%s' nên không hợp lệ cho ngữ cảnh '%s'.",
                    $role->code,
                    $role->scope->value,
                    $expectedScope->value,
                ),
                'role_scope_mismatch',
                422,
            );
        }
    }

    /**
     * Đếm số lượng Super Administrator đang thực sự có hiệu lực trong toàn hệ thống.
     */
    public function countEffectiveSuperAdmins(): int
    {
        return (int) DB::table('users')
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('users.account_status', AccountStatus::Active->value)
            ->where('users.staff_access_status', StaffAccessStatus::Active->value)
            ->where('users.must_change_password', false)
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.code', 'super_admin')
            ->where('roles.scope', RoleScope::Global->value)
            ->distinct('users.id')
            ->count('users.id');
    }

    /**
     * Đánh giá trước xem việc vô hiệu hóa / thu hồi quyền của `$targetUser` có làm hệ thống
     * mất đi Super Administrator hiệu lực cuối cùng hay không.
     */
    public function wouldLeaveZeroEffectiveSuperAdmins(User $targetUser): bool
    {
        if (! $this->isCountedAsEffectiveSuperAdmin($targetUser)) {
            return false;
        }

        return $this->countEffectiveSuperAdmins() <= 1;
    }

    /**
     * Thực thi một thao tác nhạy cảm trên Super Administrator bên trong giao dịch tuần tự
     * ở tầng CSDL (`BEGIN IMMEDIATE` trên SQLite / `lockForUpdate` trên MySQL-PgSQL),
     * chỉ cho phép commit khi hệ thống vẫn còn ít nhất 1 Effective Super Admin (Correction C).
     *
     * @template TResult
     *
     * @param  callable(): TResult  $mutation
     * @return TResult
     */
    public function ensureEffectiveSuperAdminRemainsAfterMutation(User $targetUser, callable $mutation): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($connection->transactionLevel() === 0 && $driver === 'sqlite') {
            $connection->statement('PRAGMA busy_timeout = 5000');
        }

        return $connection->transaction(function () use ($driver, $mutation): mixed {
            if ($driver !== 'sqlite') {
                DB::table('users')
                    ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->where('user_roles.role_scope', RoleScope::Global->value)
                    ->where('roles.code', 'super_admin')
                    ->where('roles.scope', RoleScope::Global->value)
                    ->select('users.id')
                    ->lockForUpdate()
                    ->get();
            }

            $result = $mutation();

            if ($this->countEffectiveSuperAdmins() < 1) {
                throw new DomainInvariantException(
                    'Không thể thực hiện thao tác vì hệ thống bắt buộc phải còn ít nhất một Quản trị viên cấp cao đang hoạt động hợp lệ.',
                    'last_effective_super_admin_required',
                    422,
                );
            }

            return $result;
        }, attempts: 5);
    }

    /**
     * Xác thực phân công chi nhánh và vai trò chi nhánh hợp lệ theo cặp khóa ghép `(user_id, branch_id)`.
     */
    public function ensureValidBranchAssignment(User $user, Branch $branch): void
    {
        $activeBranch = $this->ensureValidActiveBranchContext($branch);

        if (
            ! $user->exists
            || $user->account_status !== AccountStatus::Active
            || $user->staff_access_status !== StaffAccessStatus::Active
            || $user->must_change_password
        ) {
            throw new DomainInvariantException(
                'Tài khoản nhân sự chưa đủ điều kiện trạng thái hoạt động để làm việc tại chi nhánh.',
                'staff_not_effective',
                403,
            );
        }

        $hasActiveAssignment = DB::table('branch_assignments')
            ->where('user_id', $user->id)
            ->where('branch_id', $activeBranch->id)
            ->where('status', BranchAssignmentStatus::Active->value)
            ->exists();

        if (! $hasActiveAssignment) {
            throw new DomainInvariantException(
                'Nhân sự không có phân công đang hoạt động tại chi nhánh này.',
                'branch_assignment_inactive_or_missing',
                403,
            );
        }

        $hasBranchRole = DB::table('branch_role_assignments')
            ->join('roles', 'roles.id', '=', 'branch_role_assignments.role_id')
            ->where('branch_role_assignments.user_id', $user->id)
            ->where('branch_role_assignments.branch_id', $activeBranch->id)
            ->where('branch_role_assignments.role_scope', RoleScope::Branch->value)
            ->where('roles.scope', RoleScope::Branch->value)
            ->exists();

        if (! $hasBranchRole) {
            throw new DomainInvariantException(
                'Nhân sự chưa được gán vai trò chi nhánh hợp lệ tại địa điểm này.',
                'branch_role_assignment_missing',
                403,
            );
        }
    }

    /**
     * Bảo vệ quy trình thu hồi vai trò toàn cục (`global`), chặn tự thu hồi và bảo vệ Super Admin cuối cùng.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $mutation
     * @return TResult
     */
    public function ensureGlobalRoleRevocationAllowed(User $actor, User $targetUser, Role $role, callable $mutation): mixed
    {
        $this->ensureNotSelfAction(
            $actor,
            $targetUser,
            'Bạn không được phép tự thu hồi vai trò quản trị toàn cục của chính tài khoản mình.',
        );

        $this->ensureRoleScopeMatchesTarget($role, RoleScope::Global);

        if ($role->code === 'super_admin') {
            return $this->ensureEffectiveSuperAdminRemainsAfterMutation($targetUser, $mutation);
        }

        return $mutation();
    }

    /**
     * Ràng buộc điều kiện trước khi gửi đề xuất kích hoạt quyền nhân sự (Decision 2.4).
     */
    public function ensureStaffActivationProposalAllowed(User $actor, User $targetUser, ?Branch $branch = null): void
    {
        $this->ensureNotSelfAction(
            $actor,
            $targetUser,
            'Bạn không được phép tự đề xuất kích hoạt quyền nhân sự cho chính mình.',
        );

        if ($targetUser->account_status !== AccountStatus::Active) {
            throw new DomainInvariantException(
                'Tài khoản người dùng đang bị khóa nên không đủ điều kiện đề xuất kích hoạt nhân sự.',
                'target_account_suspended',
                422,
            );
        }

        if (! in_array($targetUser->staff_access_status, [StaffAccessStatus::Pending, StaffAccessStatus::Inactive], true)) {
            throw new DomainInvariantException(
                'Chỉ tài khoản nhân sự ở trạng thái chờ kích hoạt hoặc đang tạm ngưng mới có thể tạo đề xuất kích hoạt.',
                'invalid_staff_status_for_proposal',
                422,
            );
        }

        if (! DB::table('staff_profiles')->where('user_id', $targetUser->id)->exists()) {
            throw new DomainInvariantException(
                'Tài khoản chưa có hồ sơ nhân sự nên không thể đề xuất kích hoạt.',
                'staff_profile_missing',
                422,
            );
        }

        if ($branch !== null) {
            $this->ensureValidActiveBranchContext($branch);
        }

        $hasActiveBranchAndRole = DB::table('branch_assignments')
            ->join('branches', 'branches.id', '=', 'branch_assignments.branch_id')
            ->join('branch_role_assignments', function ($join): void {
                $join->on('branch_role_assignments.user_id', '=', 'branch_assignments.user_id')
                    ->on('branch_role_assignments.branch_id', '=', 'branch_assignments.branch_id');
            })
            ->where('branch_assignments.user_id', $targetUser->id)
            ->where('branch_assignments.status', BranchAssignmentStatus::Active->value)
            ->where('branches.status', BranchStatus::Active->value)
            ->where('branch_role_assignments.role_scope', RoleScope::Branch->value)
            ->when($branch !== null, fn ($query) => $query->where('branch_assignments.branch_id', $branch->id))
            ->exists();

        if (! $hasActiveBranchAndRole) {
            throw new DomainInvariantException(
                'Nhân sự phải có ít nhất một phân công chi nhánh đang hoạt động kèm vai trò chi nhánh hợp lệ trước khi đề xuất kích hoạt.',
                'staff_branch_role_missing',
                422,
            );
        }

        $hasPendingProposal = DB::table('staff_activation_proposals')
            ->where('user_id', $targetUser->id)
            ->where('status', StaffApprovalStatus::Pending->value)
            ->exists();

        if ($hasPendingProposal) {
            throw new DomainInvariantException(
                'Nhân sự này hiện đã có một đề xuất kích hoạt đang chờ phê duyệt.',
                'pending_activation_proposal_exists',
                422,
            );
        }
    }

    /**
     * Ràng buộc điều kiện trước khi Super Administrator phê duyệt hoặc từ chối đề xuất kích hoạt.
     */
    public function ensureActivationApprovalAllowed(User $actor, StaffActivationProposal $proposal, bool $approving = true): void
    {
        $targetUser = $proposal->user;

        if ($targetUser === null) {
            throw new DomainInvariantException(
                'Không tìm thấy tài khoản nhân sự mục tiêu của đề xuất kích hoạt.',
                'proposal_target_missing',
                422,
            );
        }

        $this->ensureNotSelfAction(
            $actor,
            $targetUser,
            'Quản trị viên không bao giờ được phép tự phê duyệt hoặc tự từ chối đề xuất kích hoạt của chính mình.',
        );

        if ($proposal->status !== StaffApprovalStatus::Pending) {
            throw new DomainInvariantException(
                'Đề xuất kích hoạt này đã được xử lý trước đó và không thể chuyển trạng thái thêm.',
                'proposal_not_pending',
                422,
            );
        }

        if (! $approving) {
            return;
        }

        if ($targetUser->account_status !== AccountStatus::Active) {
            throw new DomainInvariantException(
                'Không thể phê duyệt kích hoạt nhân sự khi tài khoản tổng thể đang bị khóa.',
                'target_account_suspended',
                422,
            );
        }

        if (! $targetUser->staff_access_status->canTransitionTo(StaffAccessStatus::Active)) {
            throw new DomainInvariantException(
                'Trạng thái nhân sự hiện tại không cho phép chuyển sang hoạt động.',
                'invalid_staff_activation_transition',
                422,
            );
        }

        if ($proposal->branch !== null) {
            $this->ensureValidActiveBranchContext($proposal->branch);
        }
    }

    /**
     * Ràng buộc khi liên kết tài khoản khách hàng sẵn có sang nhân sự (không ghi đè mật khẩu khách hàng).
     */
    public function ensureCustomerLinkingAllowed(User $actor, User $targetUser): void
    {
        $this->ensureNotSelfAction(
            $actor,
            $targetUser,
            'Bạn không được phép tự liên kết tài khoản cá nhân của mình thành nhân sự.',
        );

        if ($targetUser->staff_access_status !== StaffAccessStatus::NotApplicable) {
            throw new DomainInvariantException(
                'Chỉ tài khoản khách hàng chưa có quan hệ nhân sự mới có thể liên kết làm nhân sự.',
                'target_already_has_staff_relationship',
                422,
            );
        }

        if (DB::table('staff_profiles')->where('user_id', $targetUser->id)->exists()) {
            throw new DomainInvariantException(
                'Tài khoản này đã tồn tại hồ sơ nhân sự.',
                'staff_profile_already_exists',
                422,
            );
        }
    }

    /**
     * Kiểm tra trực tiếp xem một tài khoản có đang nằm trong tập Effective Super Admin hay không.
     */
    private function isCountedAsEffectiveSuperAdmin(User $user): bool
    {
        if (
            ! $user->exists
            || $user->account_status !== AccountStatus::Active
            || $user->staff_access_status !== StaffAccessStatus::Active
            || $user->must_change_password
        ) {
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
}
