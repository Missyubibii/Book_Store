<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B2;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffActivationProposal;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RbacSchemaAndConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_schema_defaults_apply_on_raw_inserts(): void
    {
        $now = now();

        $userId = DB::table('users')->insertGetId([
            'name' => 'Nhân viên Raw',
            'email' => 'raw-defaults-user@example.com',
            'password' => bcrypt('secret123'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $proposerId = DB::table('users')->insertGetId([
            'name' => 'Người đề xuất Raw',
            'email' => 'raw-defaults-proposer@example.com',
            'password' => bcrypt('secret123'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawUser = DB::table('users')->where('id', $userId)->first();
        $this->assertNotNull($rawUser);
        $this->assertSame(AccountStatus::Active->value, $rawUser->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable->value, $rawUser->staff_access_status);
        $this->assertSame(0, (int) $rawUser->must_change_password);
        $this->assertNull($rawUser->temp_password_issued_at);
        $this->assertNull($rawUser->temp_password_expires_at);
        $this->assertNull($rawUser->password_changed_at);
        $this->assertNull($rawUser->temp_password_issued_by);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'CN-RAW-01',
            'name' => 'Chi nhánh Raw',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawBranch = DB::table('branches')->where('id', $branchId)->first();
        $this->assertNotNull($rawBranch);
        $this->assertSame(BranchType::RetailBranch->value, $rawBranch->type);
        $this->assertSame(BranchStatus::Active->value, $rawBranch->status);

        $globalRoleId = DB::table('roles')->insertGetId([
            'code' => 'raw_global_role',
            'name' => 'Vai trò toàn hệ thống Raw',
            'scope' => RoleScope::Global->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $branchRoleId = DB::table('roles')->insertGetId([
            'code' => 'raw_branch_role',
            'name' => 'Vai trò chi nhánh Raw',
            'scope' => RoleScope::Branch->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawGlobalRole = DB::table('roles')->where('id', $globalRoleId)->first();
        $this->assertNotNull($rawGlobalRole);
        $this->assertSame(0, (int) $rawGlobalRole->is_system);

        $userRoleId = DB::table('user_roles')->insertGetId([
            'user_id' => $userId,
            'role_id' => $globalRoleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawUserRole = DB::table('user_roles')->where('id', $userRoleId)->first();
        $this->assertNotNull($rawUserRole);
        $this->assertSame(RoleScope::Global->value, $rawUserRole->role_scope);
        $this->assertNotNull($rawUserRole->assigned_at);

        $branchAssignmentId = DB::table('branch_assignments')->insertGetId([
            'user_id' => $userId,
            'branch_id' => $branchId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawBranchAssignment = DB::table('branch_assignments')->where('id', $branchAssignmentId)->first();
        $this->assertNotNull($rawBranchAssignment);
        $this->assertSame(BranchAssignmentStatus::Active->value, $rawBranchAssignment->status);
        $this->assertSame(0, (int) $rawBranchAssignment->is_primary);
        $this->assertNotNull($rawBranchAssignment->assigned_at);

        $branchRoleAssignmentId = DB::table('branch_role_assignments')->insertGetId([
            'user_id' => $userId,
            'branch_id' => $branchId,
            'role_id' => $branchRoleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawBranchRoleAssignment = DB::table('branch_role_assignments')->where('id', $branchRoleAssignmentId)->first();
        $this->assertNotNull($rawBranchRoleAssignment);
        $this->assertSame(RoleScope::Branch->value, $rawBranchRoleAssignment->role_scope);
        $this->assertNotNull($rawBranchRoleAssignment->assigned_at);

        $proposalId = DB::table('staff_activation_proposals')->insertGetId([
            'user_id' => $userId,
            'proposed_by' => $proposerId,
            'proposal_reason' => 'Kiểm tra giá trị mặc định ở tầng CSDL.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawProposal = DB::table('staff_activation_proposals')->where('id', $proposalId)->first();
        $this->assertNotNull($rawProposal);
        $this->assertSame(StaffAccessStatus::Active->value, $rawProposal->target_status);
        $this->assertSame(StaffApprovalStatus::Pending->value, $rawProposal->status);
        $this->assertNotNull($rawProposal->proposed_at);
    }

    public function test_all_database_check_constraints_reject_invalid_values(): void
    {
        $now = now();

        $user = User::factory()->staffPending()->create();
        $proposer = User::factory()->staffActive()->create();
        $branch = Branch::factory()->create();

        $globalRoleId = DB::table('roles')->insertGetId([
            'code' => 'check_global_role',
            'name' => 'Global Check Role',
            'scope' => RoleScope::Global->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $branchRoleId = DB::table('roles')->insertGetId([
            'code' => 'check_branch_role',
            'name' => 'Branch Check Role',
            'scope' => RoleScope::Branch->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('branch_assignments')->insert([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 1. users.must_change_password
        $this->assertQueryException(function () use ($now): void {
            DB::table('users')->insert([
                'name' => 'Invalid Must Change',
                'email' => 'invalid-must-change@example.com',
                'password' => bcrypt('secret123'),
                'must_change_password' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'users.must_change_password = 2 must fail CHECK constraint');

        // 2. branches.type
        $this->assertQueryException(function () use ($now): void {
            DB::table('branches')->insert([
                'code' => 'CN-BAD-TYPE',
                'name' => 'Invalid Branch Type',
                'type' => 'warehouse',
                'status' => BranchStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'branches.type invalid value must fail CHECK constraint');

        // 3. branches.status
        $this->assertQueryException(function () use ($now): void {
            DB::table('branches')->insert([
                'code' => 'CN-BAD-STATUS',
                'name' => 'Invalid Branch Status',
                'type' => BranchType::RetailBranch->value,
                'status' => 'archived',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'branches.status invalid value must fail CHECK constraint');

        // 4. roles.scope
        $this->assertQueryException(function () use ($now): void {
            DB::table('roles')->insert([
                'code' => 'bad_scope_role',
                'name' => 'Bad Scope Role',
                'scope' => 'department',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'roles.scope invalid value must fail CHECK constraint');

        // 5. roles.is_system
        $this->assertQueryException(function () use ($now): void {
            DB::table('roles')->insert([
                'code' => 'bad_is_system_role',
                'name' => 'Bad Is System Role',
                'scope' => RoleScope::Branch->value,
                'is_system' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'roles.is_system = 2 must fail CHECK constraint');

        // 6. permissions.scope
        $this->assertQueryException(function () use ($now): void {
            DB::table('permissions')->insert([
                'code' => 'bad.permission.scope',
                'module' => 'test',
                'name' => 'Bad Permission Scope',
                'scope' => 'system',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'permissions.scope invalid value must fail CHECK constraint');

        // 7. user_roles.role_scope = 'branch'
        $this->assertQueryException(function () use ($user, $globalRoleId, $now): void {
            DB::table('user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => $globalRoleId,
                'role_scope' => RoleScope::Branch->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'user_roles.role_scope = branch must fail CHECK constraint');

        // 8. branch_assignments.status
        $this->assertQueryException(function () use ($proposer, $branch, $now): void {
            DB::table('branch_assignments')->insert([
                'user_id' => $proposer->id,
                'branch_id' => $branch->id,
                'status' => 'suspended',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'branch_assignments.status invalid value must fail CHECK constraint');

        // 9. branch_assignments.is_primary
        $this->assertQueryException(function () use ($proposer, $branch, $now): void {
            DB::table('branch_assignments')->insert([
                'user_id' => $proposer->id,
                'branch_id' => $branch->id,
                'is_primary' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'branch_assignments.is_primary = 2 must fail CHECK constraint');

        // 10. branch_role_assignments.role_scope = 'global'
        $this->assertQueryException(function () use ($user, $branch, $branchRoleId, $now): void {
            DB::table('branch_role_assignments')->insert([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'role_id' => $branchRoleId,
                'role_scope' => RoleScope::Global->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'branch_role_assignments.role_scope = global must fail CHECK constraint');

        // 11. staff_activation_proposals.target_status
        $this->assertQueryException(function () use ($user, $proposer, $now): void {
            DB::table('staff_activation_proposals')->insert([
                'user_id' => $user->id,
                'target_status' => StaffAccessStatus::Inactive->value,
                'status' => StaffApprovalStatus::Pending->value,
                'proposed_by' => $proposer->id,
                'proposal_reason' => 'Invalid target status',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'staff_activation_proposals.target_status != active must fail CHECK constraint');

        // 12. staff_activation_proposals.status
        $this->assertQueryException(function () use ($user, $proposer, $now): void {
            DB::table('staff_activation_proposals')->insert([
                'user_id' => $user->id,
                'target_status' => StaffAccessStatus::Active->value,
                'status' => 'escalated',
                'proposed_by' => $proposer->id,
                'proposal_reason' => 'Invalid proposal status',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'staff_activation_proposals.status invalid value must fail CHECK constraint');

        // 13. staff_activation_proposals.reviewed_by = user_id (insert & update)
        $this->assertQueryException(function () use ($user, $proposer, $now): void {
            DB::table('staff_activation_proposals')->insert([
                'user_id' => $user->id,
                'target_status' => StaffAccessStatus::Active->value,
                'status' => StaffApprovalStatus::Approved->value,
                'proposed_by' => $proposer->id,
                'proposal_reason' => 'Self review on insert',
                'reviewed_by' => $user->id,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'staff_activation_proposals.reviewed_by = user_id on insert must fail CHECK constraint');

        $validProposalId = DB::table('staff_activation_proposals')->insertGetId([
            'user_id' => $user->id,
            'proposed_by' => $proposer->id,
            'proposal_reason' => 'Valid pending proposal before self-review update test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertQueryException(function () use ($validProposalId, $user): void {
            DB::table('staff_activation_proposals')
                ->where('id', $validProposalId)
                ->update(['reviewed_by' => $user->id]);
        }, 'staff_activation_proposals.reviewed_by = user_id on update must fail CHECK constraint');
    }

    public function test_sqlite_foreign_key_enforcement_restrict_null_and_cascade_behaviors(): void
    {
        $pragma = DB::select('PRAGMA foreign_keys;');
        $this->assertSame(1, (int) ($pragma[0]->foreign_keys ?? 0), 'PRAGMA foreign_keys must be enabled.');

        $now = now();
        $nonExistentUserId = 999999;
        $nonExistentBranchId = 999999;

        // Invalid FKs fail immediately
        $this->assertQueryException(function () use ($nonExistentUserId, $now): void {
            DB::table('staff_profiles')->insert([
                'user_id' => $nonExistentUserId,
                'staff_code' => 'NV-GHOST-01',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'Invalid staff_profiles.user_id must fail FK constraint');

        $validUser = User::factory()->staffActive()->create();
        $this->assertQueryException(function () use ($validUser, $nonExistentBranchId, $now): void {
            DB::table('branch_assignments')->insert([
                'user_id' => $validUser->id,
                'branch_id' => $nonExistentBranchId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'Invalid branch_assignments.branch_id must fail FK constraint');

        // RESTRICT ON DELETE checks
        $staffWithProfile = User::factory()->staffPending()->create();
        StaffProfile::factory()->create(['user_id' => $staffWithProfile->id]);

        $this->assertQueryException(function () use ($staffWithProfile): void {
            DB::table('users')->where('id', $staffWithProfile->id)->delete();
        }, 'Deleting user with staff_profiles must fail RESTRICT');
        $this->assertDatabaseHas('users', ['id' => $staffWithProfile->id]);

        $branchWithAssignment = Branch::factory()->create();
        $assignedStaff = User::factory()->staffActive()->create();
        BranchAssignment::query()->create([
            'user_id' => $assignedStaff->id,
            'branch_id' => $branchWithAssignment->id,
        ]);

        $this->assertQueryException(function () use ($assignedStaff): void {
            DB::table('users')->where('id', $assignedStaff->id)->delete();
        }, 'Deleting user with branch_assignments must fail RESTRICT');

        $this->assertQueryException(function () use ($branchWithAssignment): void {
            DB::table('branches')->where('id', $branchWithAssignment->id)->delete();
        }, 'Deleting branch with branch_assignments must fail RESTRICT');

        $proposerUser = User::factory()->staffActive()->create();
        $targetForRestrictProposal = User::factory()->staffPending()->create();
        StaffActivationProposal::factory()->create([
            'user_id' => $targetForRestrictProposal->id,
            'branch_id' => null,
            'proposed_by' => $proposerUser->id,
        ]);

        $this->assertQueryException(function () use ($proposerUser): void {
            DB::table('users')->where('id', $proposerUser->id)->delete();
        }, 'Deleting proposer user referenced by staff_activation_proposals.proposed_by must fail RESTRICT');

        // NULL ON DELETE checks
        $issuerUser = User::factory()->staffActive()->create();
        $onboardedUser = User::factory()->staffPending()->mustChangePassword(issuedBy: $issuerUser->id)->create();
        $reviewerUser = User::factory()->staffActive()->create();
        $proposalBranch = Branch::factory()->create();

        $reviewedProposal = StaffActivationProposal::query()->create([
            'user_id' => $onboardedUser->id,
            'branch_id' => $proposalBranch->id,
            'proposed_by' => $proposerUser->id,
            'proposal_reason' => 'Kiểm tra nullOnDelete',
            'proposed_at' => $now,
        ]);
        $reviewedProposal->forceFill([
            'status' => StaffApprovalStatus::Rejected->value,
            'reviewed_by' => $reviewerUser->id,
            'reviewed_at' => $now,
        ])->save();

        $auditActor = User::factory()->staffActive()->create();
        $auditTarget = User::factory()->staffPending()->create();
        $auditBranch = Branch::factory()->create();
        $auditLog = AuditLog::query()->create([
            'actor_id' => $auditActor->id,
            'target_user_id' => $auditTarget->id,
            'branch_id' => $auditBranch->id,
            'action' => AuditAction::StaffActivationProposed,
            'subject_type' => StaffActivationProposal::class,
            'subject_id' => $reviewedProposal->id,
            'old_values' => null,
            'new_values' => ['status' => 'pending'],
            'reason' => 'Kiểm tra giữ nguyên audit log khi xóa tham chiếu.',
            'occurred_at' => $now,
        ]);

        $issuerUser->delete();
        $reviewerUser->delete();
        $proposalBranch->delete();
        $auditActor->delete();
        $auditTarget->delete();
        $auditBranch->delete();

        $onboardedUser->refresh();
        $reviewedProposal->refresh();
        $auditLog->refresh();

        $this->assertNull($onboardedUser->temp_password_issued_by);
        $this->assertNull($reviewedProposal->reviewed_by);
        $this->assertNull($reviewedProposal->branch_id);
        $this->assertNull($auditLog->actor_id);
        $this->assertNull($auditLog->target_user_id);
        $this->assertNull($auditLog->branch_id);
        $this->assertDatabaseHas('staff_activation_proposals', ['id' => $reviewedProposal->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);

        // CASCADE ON DELETE checks
        $cascadeUser = User::factory()->staffPending()->create();
        $globalRole = Role::query()->create([
            'code' => 'cascade_global_role',
            'name' => 'Cascade Global Role',
            'scope' => RoleScope::Global->value,
        ]);
        $branchRole = Role::query()->create([
            'code' => 'cascade_branch_role',
            'name' => 'Cascade Branch Role',
            'scope' => RoleScope::Branch->value,
        ]);
        $permission = Permission::query()->create([
            'code' => 'cascade.permission.test',
            'module' => 'test',
            'name' => 'Cascade Permission Test',
            'scope' => RoleScope::Global->value,
        ]);
        $globalRole->permissions()->attach($permission->id);
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $globalRole->id,
            'permission_id' => $permission->id,
        ]);

        $assignerForNull = User::factory()->staffActive()->create();
        $userRole = UserRole::query()->create([
            'user_id' => $cascadeUser->id,
            'role_id' => $globalRole->id,
            'assigned_by' => $assignerForNull->id,
        ]);
        $branchAssignTarget = User::factory()->staffActive()->create();
        $branchAssignBranch = Branch::factory()->create();
        $nullAssignedByBranchAssignment = BranchAssignment::query()->create([
            'user_id' => $branchAssignTarget->id,
            'branch_id' => $branchAssignBranch->id,
            'assigned_by' => $assignerForNull->id,
        ]);
        $nullAssignedByBranchRole = BranchRoleAssignment::query()->create([
            'user_id' => $branchAssignTarget->id,
            'branch_id' => $branchAssignBranch->id,
            'role_id' => $branchRole->id,
            'assigned_by' => $assignerForNull->id,
        ]);

        $assignerForNull->delete();
        $userRole->refresh();
        $nullAssignedByBranchAssignment->refresh();
        $nullAssignedByBranchRole->refresh();
        $this->assertNull($userRole->assigned_by);
        $this->assertNull($nullAssignedByBranchAssignment->assigned_by);
        $this->assertNull($nullAssignedByBranchRole->assigned_by);

        $cascadeProposal = StaffActivationProposal::query()->create([
            'user_id' => $cascadeUser->id,
            'branch_id' => null,
            'proposed_by' => $proposerUser->id,
            'proposal_reason' => 'Kiểm tra cascadeOnDelete theo user_id.',
        ]);

        $cascadeUser->delete();
        $permission->delete();

        $this->assertDatabaseMissing('user_roles', ['id' => $userRole->id]);
        $this->assertDatabaseMissing('staff_activation_proposals', ['id' => $cascadeProposal->id]);
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $globalRole->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_composite_role_scope_foreign_keys_prevent_cross_scope_assignments_and_block_role_deletion(): void
    {
        $now = now();
        $user = User::factory()->staffActive()->create();
        $branch = Branch::factory()->create();

        $globalRole = Role::query()->create([
            'code' => 'super_admin_scope_test',
            'name' => 'Super Admin Scope Test',
            'scope' => RoleScope::Global->value,
        ]);

        $branchRole = Role::query()->create([
            'code' => 'branch_admin_scope_test',
            'name' => 'Branch Admin Scope Test',
            'scope' => RoleScope::Branch->value,
        ]);

        BranchAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        // Even with role_scope = 'global' (passing CHECK), composite FK (role_id, role_scope) -> roles(id, scope) rejects branch role
        $this->assertQueryException(function () use ($user, $branchRole, $now): void {
            DB::table('user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => $branchRole->id,
                'role_scope' => RoleScope::Global->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'Branch role inserted into user_roles must fail composite FK');

        // Even with role_scope = 'branch' (passing CHECK), composite FK (role_id, role_scope) -> roles(id, scope) rejects global role
        $this->assertQueryException(function () use ($user, $branch, $globalRole, $now): void {
            DB::table('branch_role_assignments')->insert([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'role_id' => $globalRole->id,
                'role_scope' => RoleScope::Branch->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'Global role inserted into branch_role_assignments must fail composite FK');

        // Valid scope-matched assignments succeed and block deleting referenced roles via RESTRICT
        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $globalRole->id,
        ]);

        BranchRoleAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role_id' => $branchRole->id,
        ]);

        $this->assertQueryException(function () use ($globalRole): void {
            DB::table('roles')->where('id', $globalRole->id)->delete();
        }, 'Deleting global role referenced in user_roles must fail RESTRICT');

        $this->assertQueryException(function () use ($branchRole): void {
            DB::table('roles')->where('id', $branchRole->id)->delete();
        }, 'Deleting branch role referenced in branch_role_assignments must fail RESTRICT');
    }

    public function test_branch_assignment_composite_fk_enforces_matching_pair_and_cascades_branch_roles(): void
    {
        $now = now();
        $user = User::factory()->staffActive()->create();
        $assignedBranch = Branch::factory()->create();
        $unassignedBranch = Branch::factory()->create();

        $branchRole = Role::query()->create([
            'code' => 'staff_composite_fk_test',
            'name' => 'Staff Composite FK Test',
            'scope' => RoleScope::Branch->value,
        ]);

        // Fails when user has NO branch assignment at all
        $this->assertQueryException(function () use ($user, $assignedBranch, $branchRole, $now): void {
            DB::table('branch_role_assignments')->insert([
                'user_id' => $user->id,
                'branch_id' => $assignedBranch->id,
                'role_id' => $branchRole->id,
                'role_scope' => RoleScope::Branch->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'Inserting branch_role_assignments without branch_assignments must fail composite FK');

        $assignment = BranchAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $assignedBranch->id,
        ]);

        // Fails when user IS assigned to assignedBranch, but role assignment targets unassignedBranch
        $this->assertQueryException(function () use ($user, $unassignedBranch, $branchRole, $now): void {
            DB::table('branch_role_assignments')->insert([
                'user_id' => $user->id,
                'branch_id' => $unassignedBranch->id,
                'role_id' => $branchRole->id,
                'role_scope' => RoleScope::Branch->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'Inserting branch_role_assignments for a different branch than branch_assignments must fail composite FK');

        // Succeeds for matching (user_id, branch_id) and cascades when branch_assignment is deleted
        $roleAssignment = BranchRoleAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $assignedBranch->id,
            'role_id' => $branchRole->id,
        ]);

        $this->assertDatabaseHas('branch_role_assignments', ['id' => $roleAssignment->id]);

        $assignment->delete();

        $this->assertDatabaseMissing('branch_role_assignments', ['id' => $roleAssignment->id]);
    }

    public function test_partial_unique_index_enforces_at_most_one_central_warehouse_while_allowing_multiple_retail_branches(): void
    {
        $now = now();

        Branch::factory()->centralWarehouse()->create();

        // Second active central_warehouse with different code fails
        $this->assertQueryException(function () use ($now): void {
            DB::table('branches')->insert([
                'code' => 'CENTRAL_WH_2',
                'name' => 'Kho Trung tâm thứ hai (Active)',
                'type' => BranchType::CentralWarehouse->value,
                'status' => BranchStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 'Second active central_warehouse must fail partial unique index');

        // Second inactive central_warehouse also fails
        $this->assertQueryException(function (): void {
            Branch::query()->create([
                'code' => 'CENTRAL_WH_3',
                'name' => 'Kho Trung tâm thứ ba (Inactive)',
                'type' => BranchType::CentralWarehouse->value,
                'status' => BranchStatus::Inactive->value,
            ]);
        }, 'Second inactive central_warehouse must fail partial unique index');

        // Multiple retail branches (both active and inactive) succeed
        Branch::factory()->retailBranch()->create(['code' => 'CN-HN-01']);
        Branch::factory()->retailBranch()->inactive()->create(['code' => 'CN-HCM-01']);

        $this->assertSame(1, Branch::query()->where('type', BranchType::CentralWarehouse->value)->count());
        $this->assertSame(2, Branch::query()->where('type', BranchType::RetailBranch->value)->count());
    }

    public function test_partial_unique_index_enforces_at_most_one_pending_proposal_per_user_while_allowing_history(): void
    {
        $now = now();
        $staff = User::factory()->staffPending()->create();
        $proposer = User::factory()->staffActive()->create();
        $reviewer = User::factory()->staffActive()->create();

        // Historical terminal proposals for the same user are permitted
        foreach ([StaffApprovalStatus::Rejected, StaffApprovalStatus::Cancelled, StaffApprovalStatus::Approved] as $terminalStatus) {
            DB::table('staff_activation_proposals')->insert([
                'user_id' => $staff->id,
                'target_status' => StaffAccessStatus::Active->value,
                'status' => $terminalStatus->value,
                'proposed_by' => $proposer->id,
                'proposal_reason' => "Lịch sử đề xuất {$terminalStatus->value}",
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // First pending proposal succeeds
        StaffActivationProposal::query()->create([
            'user_id' => $staff->id,
            'proposed_by' => $proposer->id,
            'proposal_reason' => 'Đề xuất đang chờ duyệt duy nhất.',
        ]);

        // Second pending proposal for the same user fails partial unique index
        $this->assertQueryException(function () use ($staff, $proposer): void {
            StaffActivationProposal::query()->create([
                'user_id' => $staff->id,
                'proposed_by' => $proposer->id,
                'proposal_reason' => 'Đề xuất chờ duyệt trùng lặp.',
            ]);
        }, 'Second pending proposal for the same user must fail partial unique index');

        $this->assertSame(
            4,
            StaffActivationProposal::query()->where('user_id', $staff->id)->count()
        );
    }

    public function test_stage_01b2_migrations_rollback_and_reapply_cleanly_without_touching_stage_01a_tables(): void
    {
        $stage01B2Tables = [
            'branches',
            'staff_profiles',
            'roles',
            'permissions',
            'permission_role',
            'user_roles',
            'branch_assignments',
            'branch_role_assignments',
            'staff_activation_proposals',
            'audit_logs',
        ];

        $stage01AUsersColumns = [
            'id',
            'name',
            'email',
            'password',
            'account_status',
            'staff_access_status',
        ];

        $onboardingColumns = [
            'must_change_password',
            'temp_password_issued_at',
            'temp_password_expires_at',
            'password_changed_at',
            'temp_password_issued_by',
        ];

        foreach ($stage01B2Tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Stage 01B.2 table '{$table}' to exist before rollback.");
        }
        $this->assertTrue(Schema::hasColumns('users', $onboardingColumns));

        $migrator = app('migrator');
        $rolledBack = $migrator->rollback([database_path('migrations')], ['step' => 8]);

        $this->assertCount(8, $rolledBack);
        foreach ($rolledBack as $filePath) {
            $this->assertStringContainsString('2026_11_12_', basename($filePath));
        }

        // All Stage 01B.2 tables and users onboarding columns are removed
        foreach ($stage01B2Tables as $table) {
            $this->assertFalse(Schema::hasTable($table), "Expected Stage 01B.2 table '{$table}' to be dropped after rollback.");
        }
        foreach ($onboardingColumns as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "Expected onboarding column '{$column}' to be dropped after rollback.");
        }

        // Stage 01A tables and indexes remain intact
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('login_events'));
        $this->assertTrue(Schema::hasColumns('users', $stage01AUsersColumns));

        $usersIndexesAfterRollback = collect(Schema::getIndexes('users'))->pluck('name')->all();
        $this->assertContains('users_email_unique', $usersIndexesAfterRollback);
        $this->assertContains('users_account_status_index', $usersIndexesAfterRollback);
        $this->assertContains('users_staff_access_status_index', $usersIndexesAfterRollback);
        $this->assertNotContains('users_must_change_password_index', $usersIndexesAfterRollback);

        // Re-apply all 8 Stage 01B.2 migrations cleanly
        $reapplied = $migrator->run([database_path('migrations')]);
        $this->assertCount(8, $reapplied);

        foreach ($stage01B2Tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Stage 01B.2 table '{$table}' to exist after re-migrate.");
        }
        $this->assertTrue(Schema::hasColumns('users', $onboardingColumns));

        $usersIndexesAfterReapply = collect(Schema::getIndexes('users'))->pluck('name')->all();
        $this->assertContains('users_must_change_password_index', $usersIndexesAfterReapply);

        $branchIndexes = collect(Schema::getIndexes('branches'))->pluck('name')->all();
        $this->assertContains('branches_single_central_warehouse_unique', $branchIndexes);

        $proposalIndexes = collect(Schema::getIndexes('staff_activation_proposals'))->pluck('name')->all();
        $this->assertContains('staff_activation_proposals_one_pending_per_user', $proposalIndexes);
    }

    public function test_pre_existing_users_records_and_stage_01a_indexes_survive_onboarding_columns_migration(): void
    {
        $migrator = app('migrator');
        $rolledBack = $migrator->rollback([database_path('migrations')], ['step' => 8]);
        $this->assertCount(8, $rolledBack);

        $now = now();
        $customerPasswordHash = bcrypt('customer-secret-123');
        $suspendedPasswordHash = bcrypt('suspended-secret-456');

        // Insert Stage 01A records before 2026_11_12_000001 runs
        $customerId = DB::table('users')->insertGetId([
            'name' => 'Khách Hàng Tồn Tại Trước',
            'email' => 'legacy-customer@example.com',
            'password' => $customerPasswordHash,
            'account_status' => AccountStatus::Active->value,
            'staff_access_status' => StaffAccessStatus::NotApplicable->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $suspendedId = DB::table('users')->insertGetId([
            'name' => 'Tài Khoản Bị Khóa Trước',
            'email' => 'legacy-suspended@example.com',
            'password' => $suspendedPasswordHash,
            'account_status' => AccountStatus::Suspended->value,
            'staff_access_status' => StaffAccessStatus::Inactive->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $preMigrationIndexes = collect(Schema::getIndexes('users'))->pluck('name')->all();
        $this->assertContains('users_email_unique', $preMigrationIndexes);
        $this->assertContains('users_account_status_index', $preMigrationIndexes);
        $this->assertContains('users_staff_access_status_index', $preMigrationIndexes);

        // Apply only the onboarding credential columns migration first
        $migrator->runPending([
            database_path('migrations/2026_11_12_000001_add_onboarding_credential_columns_to_users_table.php'),
        ]);

        $postMigrationIndexes = collect(Schema::getIndexes('users'))->pluck('name')->all();
        $this->assertContains('users_email_unique', $postMigrationIndexes);
        $this->assertContains('users_account_status_index', $postMigrationIndexes);
        $this->assertContains('users_staff_access_status_index', $postMigrationIndexes);
        $this->assertContains('users_must_change_password_index', $postMigrationIndexes);

        $customerRow = DB::table('users')->where('id', $customerId)->first();
        $this->assertNotNull($customerRow);
        $this->assertSame('Khách Hàng Tồn Tại Trước', $customerRow->name);
        $this->assertSame('legacy-customer@example.com', $customerRow->email);
        $this->assertSame($customerPasswordHash, $customerRow->password);
        $this->assertSame(AccountStatus::Active->value, $customerRow->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable->value, $customerRow->staff_access_status);
        $this->assertSame(0, (int) $customerRow->must_change_password);
        $this->assertNull($customerRow->temp_password_issued_at);
        $this->assertNull($customerRow->temp_password_expires_at);
        $this->assertNull($customerRow->password_changed_at);
        $this->assertNull($customerRow->temp_password_issued_by);

        $suspendedRow = DB::table('users')->where('id', $suspendedId)->first();
        $this->assertNotNull($suspendedRow);
        $this->assertSame('legacy-suspended@example.com', $suspendedRow->email);
        $this->assertSame($suspendedPasswordHash, $suspendedRow->password);
        $this->assertSame(AccountStatus::Suspended->value, $suspendedRow->account_status);
        $this->assertSame(StaffAccessStatus::Inactive->value, $suspendedRow->staff_access_status);
        $this->assertSame(0, (int) $suspendedRow->must_change_password);

        // Restore remaining Stage 01B.2 migrations so test state remains complete
        $migrator->run([database_path('migrations')]);
    }

    private function assertQueryException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail($message.' (no QueryException thrown)');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
