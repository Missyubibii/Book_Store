<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B3;

use App\Enums\AccountStatus;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Role;
use App\Models\StaffActivationProposal;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Authorization\DomainGuardService;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DomainGuardAndPolicyTest extends TestCase
{
    use RefreshDatabase;

    private DomainGuardService $domainGuards;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        $this->domainGuards = app(DomainGuardService::class);
    }

    public function test_super_admin_cannot_bypass_self_action_policies_or_domain_guards(): void
    {
        $branch = Branch::factory()->retailBranch()->create();
        $superAdmin1 = $this->createSuperAdmin();
        $superAdmin2 = $this->createSuperAdmin();

        $otherStaff = User::factory()->staffPending()->create();
        $proposalForSuperAdmin1 = StaffActivationProposal::factory()->create([
            'user_id' => $superAdmin1->id,
            'branch_id' => $branch->id,
            'proposed_by' => $superAdmin2->id,
        ]);
        $proposalForOtherStaff = StaffActivationProposal::factory()->create([
            'user_id' => $otherStaff->id,
            'branch_id' => $branch->id,
            'proposed_by' => $superAdmin2->id,
        ]);

        // Gate::before KHÔNG được phép bỏ qua bất kỳ Policy bảo vệ tự thao tác nào
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('review', $proposalForSuperAdmin1));
        $this->assertTrue(Gate::forUser($superAdmin1)->allows('review', $proposalForOtherStaff));

        $this->assertFalse(Gate::forUser($superAdmin1)->allows('propose', [StaffActivationProposal::class, $superAdmin1, $branch]));
        $this->assertTrue(Gate::forUser($superAdmin1)->allows('propose', [StaffActivationProposal::class, $otherStaff, $branch]));

        $this->assertFalse(Gate::forUser($superAdmin1)->allows('resetTempPassword', $superAdmin1));
        $this->assertTrue(Gate::forUser($superAdmin1)->allows('resetTempPassword', $otherStaff));

        $this->assertFalse(Gate::forUser($superAdmin1)->allows('suspendStaff', $superAdmin1));
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('suspendAccount', $superAdmin1));
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('reactivateStaff', $superAdmin1));
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('assignGlobalRole', $superAdmin1));
        $this->assertTrue(Gate::forUser($superAdmin1)->allows('assignGlobalRole', $otherStaff));

        // Khi còn >= 2 Super Admin hiệu lực, superAdmin1 có thể thao tác trên superAdmin2 (khác mình)
        $this->assertTrue(Gate::forUser($superAdmin1)->allows('suspendStaff', $superAdmin2));
        $this->assertTrue(Gate::forUser($superAdmin1)->allows('suspendAccount', $superAdmin2));

        // DomainGuardService::ensureNotSelfAction ném DomainInvariantException (403)
        try {
            $this->domainGuards->ensureNotSelfAction($superAdmin1, $superAdmin1);
            $this->fail('ensureNotSelfAction must reject identical actor and target.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('self_action_forbidden', $exception->invariantCode);
            $this->assertSame(403, $exception->statusCode);
        }
    }

    public function test_last_effective_super_admin_guard_enforces_invariant_and_rolls_back_on_sqlite(): void
    {
        $lastSuperAdmin = $this->createSuperAdmin();
        $otherActor = User::factory()->staffActive()->create();

        // Tài khoản chưa hoàn tất onboarding (must_change_password = true) KHÔNG được tính là Effective Super Admin dự phòng
        $unreadySuperAdmin = User::factory()->staffActive()->mustChangePassword()->create();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
        UserRole::query()->create(['user_id' => $unreadySuperAdmin->id, 'role_id' => $superAdminRole->id]);

        $this->assertSame(1, $this->domainGuards->countEffectiveSuperAdmins());
        $this->assertTrue($this->domainGuards->wouldLeaveZeroEffectiveSuperAdmins($lastSuperAdmin));
        $this->assertFalse(Gate::forUser($otherActor)->allows('suspendStaff', $lastSuperAdmin));
        $this->assertFalse(Gate::forUser($otherActor)->allows('suspendAccount', $lastSuperAdmin));

        // Thao tác thu hồi vai trò Super Admin cuối cùng phải bị chặn và hoàn tác toàn bộ trong transaction
        try {
            $this->domainGuards->ensureEffectiveSuperAdminRemainsAfterMutation($lastSuperAdmin, function () use ($lastSuperAdmin, $superAdminRole): void {
                UserRole::query()
                    ->where('user_id', $lastSuperAdmin->id)
                    ->where('role_id', $superAdminRole->id)
                    ->delete();
            });
            $this->fail('Last effective Super Admin mutation must roll back.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('last_effective_super_admin_required', $exception->invariantCode);
        }

        // Sau rollback, bản ghi user_roles của Super Admin cuối cùng vẫn còn nguyên vẹn
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $lastSuperAdmin->id,
            'role_id' => $superAdminRole->id,
        ]);
        $this->assertSame(1, $this->domainGuards->countEffectiveSuperAdmins());

        // Khi có thêm Super Admin hiệu lực thứ hai, hạ cấp một người được phép thành công
        $secondSuperAdmin = $this->createSuperAdmin();
        $this->assertSame(2, $this->domainGuards->countEffectiveSuperAdmins());
        $this->assertFalse($this->domainGuards->wouldLeaveZeroEffectiveSuperAdmins($lastSuperAdmin));

        $this->domainGuards->ensureEffectiveSuperAdminRemainsAfterMutation($lastSuperAdmin, function () use ($lastSuperAdmin): void {
            $lastSuperAdmin->forceFill(['staff_access_status' => StaffAccessStatus::Inactive->value])->save();
        });

        $this->assertSame(1, $this->domainGuards->countEffectiveSuperAdmins());
        $this->assertSame(StaffAccessStatus::Inactive, $lastSuperAdmin->fresh()->staff_access_status);
    }

    public function test_central_warehouse_and_branch_context_invariants_cannot_be_bypassed(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $centralWh = Branch::query()->where('code', 'CENTRAL_WH')->firstOrFail();
        $retailBranch = Branch::factory()->retailBranch()->create();
        $inactiveBranch = Branch::factory()->retailBranch()->inactive()->create();

        // Gate::before KHÔNG thể cho phép xóa Kho trung tâm qua BranchPolicy::delete
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $centralWh));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $retailBranch));

        // BranchPolicy::assignStaff / accessOperations bị chặn trên chi nhánh inactive ngay cả với Super Admin
        $this->assertFalse(Gate::forUser($superAdmin)->allows('accessOperations', $inactiveBranch));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('assignStaff', $inactiveBranch));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('accessOperations', $retailBranch));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('assignStaff', $retailBranch));

        // DomainGuardService: kiểm tra các bất biến kho trung tâm
        try {
            $this->domainGuards->ensureRetailBranchOperationAllowed($centralWh);
            $this->fail('Central warehouse must be rejected for retail-branch-only operations.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('central_warehouse_not_retail_branch', $exception->invariantCode);
        }

        try {
            $this->domainGuards->ensureCentralWarehouseMutationAllowed($centralWh, BranchType::RetailBranch, BranchStatus::Active);
            $this->fail('Central warehouse must never be converted to retail branch.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('central_warehouse_type_immutable', $exception->invariantCode);
        }

        try {
            $this->domainGuards->ensureCentralWarehouseMutationAllowed($centralWh, BranchType::CentralWarehouse, BranchStatus::Inactive);
            $this->fail('Central warehouse must never transition to inactive.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('central_warehouse_must_remain_active', $exception->invariantCode);
        }

        try {
            $this->domainGuards->ensureCentralWarehouseMutationAllowed($centralWh, BranchType::CentralWarehouse, BranchStatus::Active, isDeleting: true);
            $this->fail('Central warehouse must never be deleted.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('central_warehouse_delete_forbidden', $exception->invariantCode);
        }

        try {
            $this->domainGuards->ensureCentralWarehouseMutationAllowed($retailBranch, BranchType::CentralWarehouse, BranchStatus::Active);
            $this->fail('A second central warehouse must never be established.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('central_warehouse_already_exists', $exception->invariantCode);
        }

        // Branch context null hoặc inactive
        try {
            $this->domainGuards->ensureValidActiveBranchContext(null);
            $this->fail('Null branch context must be rejected.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('branch_context_missing', $exception->invariantCode);
        }

        try {
            $this->domainGuards->ensureValidActiveBranchContext($inactiveBranch);
            $this->fail('Inactive branch context must be rejected.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('branch_inactive', $exception->invariantCode);
        }
    }

    public function test_role_scope_and_staff_lifecycle_guard_contracts(): void
    {
        $actor = $this->createSuperAdmin();
        $branch = Branch::factory()->retailBranch()->create();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();

        // Role scope consistency
        try {
            $this->domainGuards->ensureRoleScopeMatchesTarget($superAdminRole, RoleScope::Branch);
            $this->fail('Global role must be rejected for branch scope.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('role_scope_mismatch', $exception->invariantCode);
        }

        try {
            $this->domainGuards->ensureRoleScopeMatchesTarget($branchAdminRole, RoleScope::Global);
            $this->fail('Branch role must be rejected for global scope.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('role_scope_mismatch', $exception->invariantCode);
        }

        // Customer linking checks (không cho liên kết chính mình hoặc tài khoản đã có quan hệ nhân sự)
        $customer = User::factory()->create();
        $this->domainGuards->ensureCustomerLinkingAllowed($actor, $customer);
        $this->assertTrue(Gate::forUser($actor)->allows('linkCustomerToStaff', [$customer, $branch]));
        $this->assertFalse(Gate::forUser($actor)->allows('linkCustomerToStaff', [$actor, $branch]));
        $this->assertFalse(Gate::forUser($actor)->allows('resetTempPassword', $customer));

        $pendingStaff = User::factory()->staffPending()->create();
        $this->assertFalse(Gate::forUser($actor)->allows('linkCustomerToStaff', [$pendingStaff, $branch]));

        try {
            $this->domainGuards->ensureCustomerLinkingAllowed($actor, $pendingStaff);
            $this->fail('Non-customer account must not be linked again as customer.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('target_already_has_staff_relationship', $exception->invariantCode);
        }

        // Staff activation proposal guard: yêu cầu đủ hồ sơ + phân công active + vai trò chi nhánh
        StaffProfile::factory()->create(['user_id' => $pendingStaff->id]);

        try {
            $this->domainGuards->ensureStaffActivationProposalAllowed($actor, $pendingStaff, $branch);
            $this->fail('Staff without branch assignment and branch role cannot be proposed.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('staff_branch_role_missing', $exception->invariantCode);
        }

        BranchAssignment::query()->create([
            'user_id' => $pendingStaff->id,
            'branch_id' => $branch->id,
            'status' => BranchAssignmentStatus::Active->value,
        ]);
        BranchRoleAssignment::query()->create([
            'user_id' => $pendingStaff->id,
            'branch_id' => $branch->id,
            'role_id' => $staffRole->id,
        ]);

        $this->domainGuards->ensureStaffActivationProposalAllowed($actor, $pendingStaff, $branch);

        $proposal = StaffActivationProposal::factory()->create([
            'user_id' => $pendingStaff->id,
            'branch_id' => $branch->id,
            'proposed_by' => $actor->id,
        ]);

        $this->domainGuards->ensureActivationApprovalAllowed($actor, $proposal, approving: true);

        // Chặn tự duyệt
        try {
            $this->domainGuards->ensureActivationApprovalAllowed($pendingStaff, $proposal, approving: true);
            $this->fail('Target user must never approve their own proposal.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('self_action_forbidden', $exception->invariantCode);
        }

        // Chặn gửi thêm proposal thứ hai khi đang pending
        try {
            $this->domainGuards->ensureStaffActivationProposalAllowed($actor, $pendingStaff, $branch);
            $this->fail('Duplicate pending proposal must be rejected.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('pending_activation_proposal_exists', $exception->invariantCode);
        }

        // Proposal đã xử lý (approved) không thể review lại
        $proposal->forceFill(['status' => StaffApprovalStatus::Approved->value, 'reviewed_by' => $actor->id])->save();
        $this->assertFalse(Gate::forUser($actor)->allows('review', $proposal));

        try {
            $this->domainGuards->ensureActivationApprovalAllowed($actor, $proposal->fresh());
            $this->fail('Non-pending proposal must be rejected.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('proposal_not_pending', $exception->invariantCode);
        }

        // Đảm bảo tài khoản tổng thể bị khóa không thể được phê duyệt
        $suspendedPending = User::factory()->staffPending()->suspended()->create();
        $suspendedProposal = StaffActivationProposal::factory()->create([
            'user_id' => $suspendedPending->id,
            'branch_id' => $branch->id,
            'proposed_by' => $actor->id,
        ]);

        try {
            $this->domainGuards->ensureActivationApprovalAllowed($actor, $suspendedProposal, approving: true);
            $this->fail('Suspended account cannot be approved for staff activation.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('target_account_suspended', $exception->invariantCode);
            $this->assertSame(AccountStatus::Suspended, $suspendedPending->account_status);
        }
    }

    private function createSuperAdmin(): User
    {
        $superAdmin = User::factory()->staffActive()->create();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

        UserRole::query()->create([
            'user_id' => $superAdmin->id,
            'role_id' => $superAdminRole->id,
        ]);

        return $superAdmin;
    }
}
