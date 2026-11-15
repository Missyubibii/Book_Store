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
use Carbon\CarbonInterface;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelsFactoriesAndSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_casts_relationships_factories_and_audit_log_append_only_protection(): void
    {
        $issuer = User::factory()->staffActive()->create();
        $staffA = User::factory()->staffPending()->mustChangePassword(issuedBy: $issuer->id)->create();
        $staffA->forceFill(['password_changed_at' => now()])->save();

        $staffB = User::factory()->staffActive()->create();
        $proposer = User::factory()->staffActive()->create();
        $reviewer = User::factory()->staffActive()->create();

        $branch = Branch::factory()->retailBranch()->create();
        $centralWarehouse = Branch::factory()->centralWarehouse()->create();

        $this->assertSame(BranchType::RetailBranch, $branch->type);
        $this->assertSame(BranchStatus::Active, $branch->status);
        $this->assertSame(BranchType::CentralWarehouse, $centralWarehouse->type);
        $this->assertSame(BranchStatus::Active, $centralWarehouse->status);

        // User onboarding casts & issuer relationship
        $staffA->refresh();
        $this->assertTrue($staffA->must_change_password);
        $this->assertInstanceOf(CarbonInterface::class, $staffA->temp_password_issued_at);
        $this->assertInstanceOf(CarbonInterface::class, $staffA->temp_password_expires_at);
        $this->assertInstanceOf(CarbonInterface::class, $staffA->password_changed_at);
        $this->assertTrue($staffA->tempPasswordIssuer->is($issuer));

        // StaffProfile factory, casts, and relationships
        $profile = StaffProfile::factory()->activated()->create([
            'user_id' => $staffA->id,
            'suspended_at' => now(),
        ]);
        $profile->refresh();
        $this->assertInstanceOf(CarbonInterface::class, $profile->hired_at);
        $this->assertInstanceOf(CarbonInterface::class, $profile->activated_at);
        $this->assertInstanceOf(CarbonInterface::class, $profile->suspended_at);
        $this->assertTrue($profile->user->is($staffA));
        $this->assertTrue($staffA->staffProfile->is($profile));

        // Role, Permission, UserRole
        $globalRole = Role::query()->create([
            'code' => 'super_admin_model_test',
            'name' => 'Super Admin Model Test',
            'scope' => RoleScope::Global->value,
        ]);
        $globalRole->forceFill(['is_system' => true])->save();
        $globalRole->refresh();
        $this->assertSame(RoleScope::Global, $globalRole->scope);
        $this->assertTrue($globalRole->is_system);

        $branchRoleA = Role::query()->create([
            'code' => 'branch_admin_model_test',
            'name' => 'Branch Admin Model Test',
            'scope' => RoleScope::Branch->value,
        ]);
        $branchRoleB = Role::query()->create([
            'code' => 'staff_model_test',
            'name' => 'Staff Model Test',
            'scope' => RoleScope::Branch->value,
        ]);

        $permission = Permission::query()->create([
            'code' => 'branches.view_model_test',
            'module' => 'branches',
            'name' => 'Xem chi nhánh',
            'scope' => RoleScope::Global->value,
        ]);
        $this->assertSame(RoleScope::Global, $permission->scope);

        $globalRole->permissions()->attach($permission->id);
        $this->assertTrue($globalRole->permissions->contains($permission));
        $this->assertTrue($permission->roles->contains($globalRole));

        $userRole = UserRole::query()->create([
            'user_id' => $staffB->id,
            'role_id' => $globalRole->id,
            'assigned_by' => $issuer->id,
            'assigned_at' => now(),
        ]);
        $userRole->refresh();
        $this->assertSame(RoleScope::Global, $userRole->role_scope);
        $this->assertInstanceOf(CarbonInterface::class, $userRole->assigned_at);
        $this->assertTrue($userRole->user->is($staffB));
        $this->assertTrue($userRole->role->is($globalRole));
        $this->assertTrue($userRole->assignedBy->is($issuer));
        $this->assertTrue($staffB->userRoles->contains($userRole));
        $this->assertTrue($staffB->roles->contains($globalRole));
        $this->assertTrue($globalRole->userRoles->contains($userRole));
        $this->assertTrue($globalRole->users->contains($staffB));

        // BranchAssignment & BranchRoleAssignment (including pair-scoped relation check)
        $assignmentA = BranchAssignment::query()->create([
            'user_id' => $staffA->id,
            'branch_id' => $branch->id,
            'status' => BranchAssignmentStatus::Active->value,
            'is_primary' => true,
            'assigned_by' => $issuer->id,
            'assigned_at' => now(),
        ]);
        $assignmentA->refresh();
        $this->assertSame(BranchAssignmentStatus::Active, $assignmentA->status);
        $this->assertTrue($assignmentA->is_primary);
        $this->assertInstanceOf(CarbonInterface::class, $assignmentA->assigned_at);
        $this->assertTrue($assignmentA->user->is($staffA));
        $this->assertTrue($assignmentA->branch->is($branch));
        $this->assertTrue($assignmentA->assignedBy->is($issuer));

        $assignmentB = BranchAssignment::query()->create([
            'user_id' => $staffB->id,
            'branch_id' => $branch->id,
        ]);

        $branchRoleAssignmentA = BranchRoleAssignment::query()->create([
            'user_id' => $staffA->id,
            'branch_id' => $branch->id,
            'role_id' => $branchRoleA->id,
            'assigned_by' => $issuer->id,
            'assigned_at' => now(),
        ]);
        $branchRoleAssignmentB = BranchRoleAssignment::query()->create([
            'user_id' => $staffB->id,
            'branch_id' => $branch->id,
            'role_id' => $branchRoleB->id,
            'assigned_by' => $issuer->id,
            'assigned_at' => now(),
        ]);

        $branchRoleAssignmentA->refresh();
        $this->assertSame(RoleScope::Branch, $branchRoleAssignmentA->role_scope);
        $this->assertInstanceOf(CarbonInterface::class, $branchRoleAssignmentA->assigned_at);
        $this->assertTrue($branchRoleAssignmentA->user->is($staffA));
        $this->assertTrue($branchRoleAssignmentA->branch->is($branch));
        $this->assertTrue($branchRoleAssignmentA->role->is($branchRoleA));
        $this->assertTrue($branchRoleAssignmentA->assignedBy->is($issuer));

        $this->assertFalse(method_exists(BranchAssignment::class, 'branchRoleAssignments'));

        $matchedA = $assignmentA->matchingRoleAssignments()->get();
        $this->assertCount(1, $matchedA);
        $this->assertTrue($matchedA->contains($branchRoleAssignmentA));
        $this->assertFalse($matchedA->contains($branchRoleAssignmentB));

        $matchedB = $assignmentB->matchingRoleAssignments()->get();
        $this->assertCount(1, $matchedB);
        $this->assertTrue($matchedB->contains($branchRoleAssignmentB));
        $this->assertFalse($matchedB->contains($branchRoleAssignmentA));

        $missingUserAssignment = new BranchAssignment(['branch_id' => $branch->id]);
        $this->assertCount(0, $missingUserAssignment->matchingRoleAssignments()->get());

        $missingBranchAssignment = new BranchAssignment(['user_id' => $staffA->id]);
        $this->assertCount(0, $missingBranchAssignment->matchingRoleAssignments()->get());

        $this->assertTrue($staffA->branchAssignments->contains($assignmentA));
        $this->assertTrue($staffA->branches->contains($branch));
        $this->assertTrue($staffA->branchRoleAssignments->contains($branchRoleAssignmentA));
        $this->assertFalse($staffA->branchRoleAssignments->contains($branchRoleAssignmentB));
        $this->assertTrue($branch->branchAssignments->contains($assignmentA));
        $this->assertTrue($branch->branchRoleAssignments->contains($branchRoleAssignmentA));
        $this->assertTrue($branch->branchRoleAssignments->contains($branchRoleAssignmentB));
        $this->assertTrue($branch->users->contains($staffA));
        $this->assertTrue($branchRoleA->branchRoleAssignments->contains($branchRoleAssignmentA));

        // StaffActivationProposal factory, casts, and relationships
        $proposal = StaffActivationProposal::factory()->create([
            'user_id' => $staffA->id,
            'branch_id' => $branch->id,
            'proposed_by' => $proposer->id,
        ]);
        $proposal->forceFill([
            'status' => StaffApprovalStatus::Approved->value,
            'reviewed_by' => $reviewer->id,
            'review_note' => 'Đã đủ điều kiện kích hoạt.',
            'reviewed_at' => now(),
        ])->save();
        $proposal->refresh();

        $this->assertSame(StaffAccessStatus::Active, $proposal->target_status);
        $this->assertSame(StaffApprovalStatus::Approved, $proposal->status);
        $this->assertInstanceOf(CarbonInterface::class, $proposal->proposed_at);
        $this->assertInstanceOf(CarbonInterface::class, $proposal->reviewed_at);
        $this->assertTrue($proposal->user->is($staffA));
        $this->assertTrue($proposal->branch->is($branch));
        $this->assertTrue($proposal->proposedBy->is($proposer));
        $this->assertTrue($proposal->proposer->is($proposer));
        $this->assertTrue($proposal->reviewedBy->is($reviewer));
        $this->assertTrue($proposal->reviewer->is($reviewer));
        $this->assertTrue($staffA->staffActivationProposals->contains($proposal));
        $this->assertTrue($proposer->proposedStaffActivations->contains($proposal));
        $this->assertTrue($reviewer->reviewedStaffActivations->contains($proposal));
        $this->assertTrue($branch->staffActivationProposals->contains($proposal));

        // AuditLog casts, relationships, and append-only LogicException guard
        $auditLog = AuditLog::query()->create([
            'actor_id' => $reviewer->id,
            'target_user_id' => $staffA->id,
            'branch_id' => $branch->id,
            'action' => AuditAction::StaffActivationApproved->value,
            'subject_type' => StaffActivationProposal::class,
            'subject_id' => $proposal->id,
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'approved'],
            'reason' => 'Phê duyệt kích hoạt nhân sự.',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'occurred_at' => now(),
        ]);
        $auditLog->refresh();

        $this->assertFalse($auditLog->timestamps);
        $this->assertSame(AuditAction::StaffActivationApproved, $auditLog->action);
        $this->assertSame(['status' => 'pending'], $auditLog->old_values);
        $this->assertSame(['status' => 'approved'], $auditLog->new_values);
        $this->assertInstanceOf(CarbonInterface::class, $auditLog->occurred_at);
        $this->assertTrue($auditLog->actor->is($reviewer));
        $this->assertTrue($auditLog->targetUser->is($staffA));
        $this->assertTrue($auditLog->branch->is($branch));
        $this->assertTrue($auditLog->subject->is($proposal));
        $this->assertTrue($reviewer->actedAuditLogs->contains($auditLog));
        $this->assertTrue($staffA->targetedAuditLogs->contains($auditLog));
        $this->assertTrue($branch->auditLogs->contains($auditLog));

        // Append-only guard: updating throws LogicException
        try {
            $auditLog->update(['reason' => 'Cố tình sửa đổi']);
            $this->fail('AuditLog::update() must throw LogicException.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('audit_logs', $exception->getMessage());
        }

        // Append-only guard: deleting throws LogicException
        try {
            $auditLog->delete();
            $this->fail('AuditLog::delete() must throw LogicException.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('audit_logs', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditLog->id,
            'reason' => 'Phê duyệt kích hoạt nhân sự.',
        ]);
    }

    public function test_privileged_attributes_are_protected_against_mass_assignment_across_models(): void
    {
        $issuer = User::factory()->staffActive()->create();
        $proposer = User::factory()->staffActive()->create();
        $reviewer = User::factory()->staffActive()->create();
        $branch = Branch::factory()->create();

        // 1. User::create ignores account_status, staff_access_status, and temp password lifecycle columns
        $createdUser = User::create([
            'name' => 'Mass Assign User',
            'email' => 'mass-assign-user@example.com',
            'password' => 'secret123',
            'account_status' => AccountStatus::Suspended->value,
            'staff_access_status' => StaffAccessStatus::Active->value,
            'must_change_password' => true,
            'temp_password_issued_at' => now(),
            'temp_password_expires_at' => now()->addHours(24),
            'password_changed_at' => now(),
            'temp_password_issued_by' => $issuer->id,
        ]);
        $createdUser->refresh();

        $this->assertSame(AccountStatus::Active, $createdUser->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $createdUser->staff_access_status);
        $this->assertFalse($createdUser->must_change_password);
        $this->assertNull($createdUser->temp_password_issued_at);
        $this->assertNull($createdUser->temp_password_expires_at);
        $this->assertNull($createdUser->password_changed_at);
        $this->assertNull($createdUser->temp_password_issued_by);

        // 2. StaffProfile::create ignores activated_at and suspended_at
        $staffProfile = StaffProfile::create([
            'user_id' => $createdUser->id,
            'staff_code' => 'NV-MASS-01',
            'job_title' => 'Nhân viên kho',
            'activated_at' => now(),
            'suspended_at' => now(),
        ]);
        $staffProfile->refresh();

        $this->assertNull($staffProfile->activated_at);
        $this->assertNull($staffProfile->suspended_at);

        // 3. Role::create ignores is_system
        $customRole = Role::create([
            'code' => 'custom_mass_role',
            'name' => 'Custom Mass Role',
            'scope' => RoleScope::Global->value,
            'is_system' => true,
        ]);
        $customRole->refresh();
        $this->assertFalse($customRole->is_system);

        $branchRole = Role::create([
            'code' => 'custom_mass_branch_role',
            'name' => 'Custom Mass Branch Role',
            'scope' => RoleScope::Branch->value,
        ]);

        // 4. UserRole::create ignores role_scope override
        $userRole = UserRole::create([
            'user_id' => $createdUser->id,
            'role_id' => $customRole->id,
            'role_scope' => RoleScope::Branch->value,
        ]);
        $userRole->refresh();
        $this->assertSame(RoleScope::Global, $userRole->role_scope);

        // 5. BranchRoleAssignment::create ignores role_scope override
        BranchAssignment::create([
            'user_id' => $createdUser->id,
            'branch_id' => $branch->id,
        ]);

        $branchRoleAssignment = BranchRoleAssignment::create([
            'user_id' => $createdUser->id,
            'branch_id' => $branch->id,
            'role_id' => $branchRole->id,
            'role_scope' => RoleScope::Global->value,
        ]);
        $branchRoleAssignment->refresh();
        $this->assertSame(RoleScope::Branch, $branchRoleAssignment->role_scope);

        // 6. StaffActivationProposal::create ignores target_status, status, reviewed_by, review_note, reviewed_at
        $proposal = StaffActivationProposal::create([
            'user_id' => $createdUser->id,
            'branch_id' => $branch->id,
            'proposed_by' => $proposer->id,
            'proposal_reason' => 'Kiểm tra chống gán hàng loạt quyết định phê duyệt.',
            'target_status' => StaffAccessStatus::Inactive->value,
            'status' => StaffApprovalStatus::Approved->value,
            'reviewed_by' => $reviewer->id,
            'review_note' => 'Tự duyệt qua mass assignment',
            'reviewed_at' => now(),
        ]);
        $proposal->refresh();

        $this->assertSame(StaffAccessStatus::Active, $proposal->target_status);
        $this->assertSame(StaffApprovalStatus::Pending, $proposal->status);
        $this->assertNull($proposal->reviewed_by);
        $this->assertNull($proposal->review_note);
        $this->assertNull($proposal->reviewed_at);
    }

    public function test_role_and_permission_seeder_and_central_warehouse_seeder_are_idempotent_and_scope_pure(): void
    {
        // Chạy nhiều lần liên tiếp để kiểm tra tính idempotent
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(CentralWarehouseSeeder::class);
        $this->seed(CentralWarehouseSeeder::class);

        // Đúng 4 vai trò hệ thống chuẩn và đúng 22 quyền (10 global + 12 branch, 23 liên kết)
        $this->assertSame(4, Role::query()->count());
        $this->assertSame(22, Permission::query()->count());
        $this->assertSame(10, Permission::query()->where('scope', RoleScope::Global->value)->count());
        $this->assertSame(12, Permission::query()->where('scope', RoleScope::Branch->value)->count());
        $this->assertSame(23, DB::table('permission_role')->count());

        $superAdmin = Role::query()->where('code', 'super_admin')->firstOrFail();
        $branchAdmin = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $staff = Role::query()->where('code', 'staff')->firstOrFail();

        $this->assertSame(RoleScope::Global, $superAdmin->scope);
        $this->assertTrue($superAdmin->is_system);
        $this->assertCount(10, $superAdmin->permissions);
        $this->assertTrue($superAdmin->permissions->contains('code', 'staff.reset_temp_password'));
        $this->assertTrue($superAdmin->permissions->contains('code', 'staff.suspend'));

        $this->assertSame(RoleScope::Branch, $branchAdmin->scope);
        $this->assertTrue($branchAdmin->is_system);
        $this->assertCount(10, $branchAdmin->permissions);
        $this->assertTrue($branchAdmin->permissions->contains('code', 'roles.view'));

        $this->assertSame(RoleScope::Branch, $staff->scope);
        $this->assertTrue($staff->is_system);
        $this->assertCount(1, $staff->permissions);
        $this->assertSame(['branch_ops.access'], $staff->permissions->pluck('code')->all());

        // Không có bất kỳ liên kết permission_role nào lệch phạm vi (global vs branch)
        $crossScopeMismatches = DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereColumn('roles.scope', '!=', 'permissions.scope')
            ->count();
        $this->assertSame(0, $crossScopeMismatches);

        // Kho trung tâm duy nhất CENTRAL_WH và bảo toàn dữ liệu vận hành khi chạy lại seeder
        $this->assertSame(1, Branch::query()->where('type', BranchType::CentralWarehouse->value)->count());
        $centralWh = Branch::query()->where('code', 'CENTRAL_WH')->firstOrFail();
        $this->assertSame(BranchType::CentralWarehouse, $centralWh->type);
        $this->assertSame(BranchStatus::Active, $centralWh->status);

        $centralWh->update([
            'name' => 'Kho Trung tâm Hà Nội (Tùy chỉnh vận hành)',
            'address' => 'Số 10 Giải Phóng, Hà Nội',
            'phone' => '02438889999',
        ]);

        $this->seed(CentralWarehouseSeeder::class);
        $centralWh->refresh();

        $this->assertSame('Kho Trung tâm Hà Nội (Tùy chỉnh vận hành)', $centralWh->name);
        $this->assertSame('Số 10 Giải Phóng, Hà Nội', $centralWh->address);
        $this->assertSame('02438889999', $centralWh->phone);

        // Chạy DatabaseSeeder lặp lại cũng giữ nguyên số lượng bản ghi chuẩn
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(4, Role::query()->count());
        $this->assertSame(22, Permission::query()->count());
        $this->assertSame(23, DB::table('permission_role')->count());
        $this->assertSame(1, Branch::query()->count());
    }

    public function test_central_warehouse_seeder_throws_runtime_exception_on_conflicting_warehouse_code(): void
    {
        Branch::query()->create([
            'code' => 'MAIN_WH_CONFLICT',
            'name' => 'Kho Trung tâm sai mã',
            'type' => BranchType::CentralWarehouse->value,
            'status' => BranchStatus::Active->value,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->seed(CentralWarehouseSeeder::class);
    }

    public function test_central_warehouse_seeder_throws_runtime_exception_when_central_wh_code_is_not_central_warehouse(): void
    {
        Branch::query()->create([
            'code' => 'CENTRAL_WH',
            'name' => 'Chi nhánh dùng nhầm mã CENTRAL_WH',
            'type' => BranchType::RetailBranch->value,
            'status' => BranchStatus::Active->value,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->seed(CentralWarehouseSeeder::class);
    }

    public function test_central_warehouse_seeder_throws_runtime_exception_when_central_wh_is_inactive(): void
    {
        Branch::query()->create([
            'code' => 'CENTRAL_WH',
            'name' => 'Kho Trung tâm bị ngừng hoạt động',
            'type' => BranchType::CentralWarehouse->value,
            'status' => BranchStatus::Inactive->value,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->seed(CentralWarehouseSeeder::class);
    }

    public function test_database_seeder_creates_zero_users_and_grants_zero_admin_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, UserRole::query()->count());
        $this->assertSame(0, BranchAssignment::query()->count());
        $this->assertSame(0, BranchRoleAssignment::query()->count());
        $this->assertSame(0, StaffProfile::query()->count());
        $this->assertSame(0, StaffActivationProposal::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_pre_existing_customer_account_remains_completely_unchanged_after_seeding(): void
    {
        $customer = User::factory()->create([
            'name' => 'Khách Hàng Hiện Hữu',
            'email' => 'existing-customer@example.com',
        ]);
        $originalPasswordHash = $customer->password;

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $customer->refresh();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(AccountStatus::Active, $customer->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $customer->staff_access_status);
        $this->assertFalse($customer->must_change_password);
        $this->assertSame($originalPasswordHash, $customer->password);
        $this->assertNull($customer->temp_password_issued_at);
        $this->assertNull($customer->temp_password_expires_at);
        $this->assertNull($customer->temp_password_issued_by);
        $this->assertNull($customer->staffProfile);
        $this->assertSame(0, $customer->roles()->count());
        $this->assertSame(0, $customer->userRoles()->count());
        $this->assertSame(0, $customer->branchAssignments()->count());
        $this->assertSame(0, $customer->branchRoleAssignments()->count());
    }
}
