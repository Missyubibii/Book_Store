<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B3;

use App\Enums\BranchAssignmentStatus;
use App\Enums\RoleScope;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Authorization\AuthorizationService;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationEngineTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $authorization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        $this->authorization = app(AuthorizationService::class);
    }

    public function test_deny_by_default_and_guest_access_denial(): void
    {
        $branch = Branch::factory()->retailBranch()->create();

        // Khách vãng lai chưa đăng nhập
        $this->assertFalse(Gate::allows('branches.view'));
        $this->assertFalse(Gate::allows('staff.view', $branch));
        $this->assertFalse(Gate::allows('viewAny', Branch::class));
        $this->assertFalse($this->authorization->isEffectiveStaff(null));
        $this->assertFalse($this->authorization->isEffectiveSuperAdmin(null));
        $this->assertFalse($this->authorization->hasGlobalPermission(null, 'branches.view'));
        $this->assertFalse($this->authorization->hasBranchPermission(null, 'staff.view', $branch));
        $this->assertSame([], $this->authorization->effectiveGlobalPermissions(null));
        $this->assertSame([], $this->authorization->effectiveBranchPermissions(null, $branch));

        // Quyền không tồn tại trong danh mục luôn bị từ chối kể cả với Super Admin
        $superAdmin = $this->createSuperAdmin();
        $this->assertFalse(Gate::forUser($superAdmin)->allows('unknown.nonexistent_ability'));
        $this->assertFalse($this->authorization->hasGlobalPermission($superAdmin, 'unknown.nonexistent_ability'));
        $this->assertFalse($this->authorization->hasBranchPermission($superAdmin, 'unknown.nonexistent_ability', $branch));
    }

    public function test_customer_pending_inactive_suspended_and_must_change_password_users_are_denied(): void
    {
        $branch = Branch::factory()->retailBranch()->create();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

        // 1. Tài khoản khách hàng (not_applicable)
        $customer = User::factory()->create();
        $this->assertFalse($this->authorization->isEffectiveStaff($customer));
        $this->assertFalse(Gate::forUser($customer)->allows('branches.view'));
        $this->assertFalse(Gate::forUser($customer)->allows('branch_ops.access', $branch));

        // 2. Nhân sự Pending dù đã có phân công và vai trò chi nhánh vẫn bị từ chối
        $pendingStaff = User::factory()->staffPending()->create();
        $this->assignBranchRole($pendingStaff, $branch, $branchAdminRole);
        $this->assertFalse($this->authorization->isEffectiveStaff($pendingStaff));
        $this->assertFalse($this->authorization->hasBranchPermission($pendingStaff, 'staff.view', $branch));
        $this->assertFalse(Gate::forUser($pendingStaff)->allows('branch_ops.access', $branch));

        // 3. Nhân sự Inactive dù còn vai trò vẫn bị từ chối
        $inactiveStaff = User::factory()->staffInactive()->create();
        $this->assignBranchRole($inactiveStaff, $branch, $branchAdminRole);
        $this->assertFalse($this->authorization->isEffectiveStaff($inactiveStaff));
        $this->assertFalse($this->authorization->hasBranchPermission($inactiveStaff, 'staff.view', $branch));

        // 4. Tài khoản Suspended dù staff_access_status = active + giữ super_admin vẫn bị từ chối
        $suspendedSuperAdmin = User::factory()->staffActive()->suspended()->create();
        UserRole::query()->create(['user_id' => $suspendedSuperAdmin->id, 'role_id' => $superAdminRole->id]);
        $this->assertFalse($this->authorization->isEffectiveSuperAdmin($suspendedSuperAdmin));
        $this->assertFalse(Gate::forUser($suspendedSuperAdmin)->allows('branches.create'));
        $this->assertFalse(Gate::forUser($suspendedSuperAdmin)->allows('staff.create', $branch));

        // 5. Nhân sự / SuperAdmin chưa đổi mật khẩu tạm bắt buộc (must_change_password = true) bị chặn đặc quyền
        $onboardingSuperAdmin = User::factory()->staffActive()->mustChangePassword()->create();
        UserRole::query()->create(['user_id' => $onboardingSuperAdmin->id, 'role_id' => $superAdminRole->id]);
        $this->assertTrue($this->authorization->isEffectiveStaff($onboardingSuperAdmin));
        $this->assertFalse($this->authorization->isEffectiveSuperAdmin($onboardingSuperAdmin));
        $this->assertFalse(Gate::forUser($onboardingSuperAdmin)->allows('branches.create'));
        $this->assertFalse(Gate::forUser($onboardingSuperAdmin)->allows('staff.create', $branch));
    }

    public function test_multiple_roles_per_branch_and_different_roles_across_branches_with_cross_branch_isolation(): void
    {
        $branchA = Branch::factory()->retailBranch()->create(['code' => 'CN_A']);
        $branchB = Branch::factory()->retailBranch()->create(['code' => 'CN_B']);
        $branchC = Branch::factory()->retailBranch()->create(['code' => 'CN_C']);
        $centralWh = Branch::query()->where('code', 'CENTRAL_WH')->firstOrFail();

        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();

        // Tạo một vai trò chi nhánh tùy biến chỉ có quyền roles.view
        $roleViewer = Role::query()->create([
            'code' => 'branch_role_viewer',
            'name' => 'Người xem danh mục vai trò chi nhánh',
            'scope' => RoleScope::Branch->value,
        ]);
        $rolesViewPermission = Permission::query()->where('code', 'roles.view')->firstOrFail();
        $roleViewer->permissions()->attach($rolesViewPermission->id);

        // User 1 (kiêm khách hàng + nhân viên):
        // - Tại branchA: giữ đồng thời 2 vai trò (`staff` + `branch_role_viewer`) -> hợp nhất quyền của cả hai
        // - Tại branchB: giữ `branch_admin` -> có đủ 10 quyền chi nhánh
        // - Tại branchC và centralWh: KHÔNG có phân công -> bị từ chối hoàn toàn
        $dualUser = User::factory()->staffActive()->create();
        $this->assignBranchRole($dualUser, $branchA, $staffRole);
        $this->assignBranchRole($dualUser, $branchA, $roleViewer);
        $this->assignBranchRole($dualUser, $branchB, $branchAdminRole);

        // Tạo thêm User 2 giữ branch_admin tại branchA để chứng minh không rò rỉ chéo người dùng
        $otherManagerAtA = User::factory()->staffActive()->create();
        $this->assignBranchRole($otherManagerAtA, $branchA, $branchAdminRole);

        // Kiểm tra đa vai trò tại branchA
        $this->assertTrue(Gate::forUser($dualUser)->allows('branch_ops.access', $branchA));
        $this->assertTrue(Gate::forUser($dualUser)->allows('roles.view', $branchA));
        $this->assertFalse(Gate::forUser($dualUser)->allows('staff.create', $branchA));
        $this->assertFalse(Gate::forUser($dualUser)->allows('roles.assign_branch', $branchA));

        // Kiểm tra vai trò khác nhau tại branchB
        $this->assertTrue(Gate::forUser($dualUser)->allows('staff.create', $branchB));
        $this->assertTrue(Gate::forUser($dualUser)->allows('roles.assign_branch', $branchB));
        $this->assertTrue(Gate::forUser($dualUser)->allows('branch_ops.access', $branchB));

        // roles.view chỉ trả về các vai trò scope = 'branch', không bao giờ lộ 'super_admin'
        $visibleRolesAtA = $this->authorization->visibleBranchRolesFor($dualUser, $branchA);
        $this->assertTrue($visibleRolesAtA->contains('code', 'branch_admin'));
        $this->assertTrue($visibleRolesAtA->contains('code', 'staff'));
        $this->assertFalse($visibleRolesAtA->contains('code', 'super_admin'));

        // Từ chối truy cập chéo chi nhánh chưa được phân công (branchC & Kho trung tâm)
        $this->assertFalse(Gate::forUser($dualUser)->allows('branch_ops.access', $branchC));
        $this->assertFalse(Gate::forUser($dualUser)->allows('branch_ops.access', $centralWh));

        // Từ chối quyền global trên user chỉ có vai trò chi nhánh
        $this->assertFalse(Gate::forUser($dualUser)->allows('branches.create'));
        $this->assertFalse($this->authorization->hasGlobalPermission($dualUser, 'branches.create'));
        $this->assertSame([], $this->authorization->effectiveGlobalPermissions($dualUser));

        // Danh sách chi nhánh khả dụng chỉ chứa branchA và branchB
        $accessibleCodes = $this->authorization->accessibleBranches($dualUser)->pluck('code')->all();
        $this->assertSame(['CN_A', 'CN_B'], $accessibleCodes);
    }

    public function test_inactive_assignment_inactive_branch_and_missing_branch_context_deny_access(): void
    {
        $activeBranch = Branch::factory()->retailBranch()->create();
        $inactiveBranch = Branch::factory()->retailBranch()->inactive()->create();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();

        $branchAdmin = User::factory()->staffActive()->create();
        $assignment = $this->assignBranchRole($branchAdmin, $activeBranch, $branchAdminRole);

        // Khi branch_assignment chuyển sang inactive (dù row branch_role_assignments vẫn tồn tại) -> mất quyền ngay lập tức
        $assignment->update(['status' => BranchAssignmentStatus::Inactive->value]);
        $this->assertFalse($this->authorization->hasBranchPermission($branchAdmin, 'staff.create', $activeBranch));
        $this->assertFalse(Gate::forUser($branchAdmin)->allows('branch_ops.access', $activeBranch));

        // Chi nhánh đang inactive -> từ chối cả BranchAdmin lẫn SuperAdmin
        $this->assignBranchRole($branchAdmin, $inactiveBranch, $branchAdminRole);
        $superAdmin = $this->createSuperAdmin();

        $this->assertFalse($this->authorization->hasBranchPermission($branchAdmin, 'staff.create', $inactiveBranch));
        $this->assertFalse(Gate::forUser($branchAdmin)->allows('staff.create', $inactiveBranch));
        $this->assertFalse($this->authorization->hasBranchPermission($superAdmin, 'staff.create', $inactiveBranch));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('staff.create', $inactiveBranch));

        // Thiếu ngữ cảnh chi nhánh cho quyền scope = branch -> từ chối cả BranchAdmin lẫn SuperAdmin
        $this->assertFalse(Gate::forUser($branchAdmin)->allows('staff.create'));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('staff.create'));
        $this->assertFalse($this->authorization->hasBranchPermission($superAdmin, 'staff.create', null));
    }

    public function test_super_admin_permission_bypass_and_scope_consistency_checks(): void
    {
        $branch = Branch::factory()->retailBranch()->create();
        $superAdmin = $this->createSuperAdmin();

        // Super Admin hợp lệ được cấp 10 quyền global (bao gồm 2 quyền global mới bổ sung)
        $this->assertTrue($this->authorization->isEffectiveSuperAdmin($superAdmin));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('branches.create'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('staff.approve_activation'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('staff.reset_temp_password'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('staff.suspend'));
        $this->assertCount(10, $this->authorization->effectiveGlobalPermissions($superAdmin));

        // Super Admin cũng vượt qua kiểm tra quyền branch thông thường KHI có ngữ cảnh chi nhánh đang active
        $this->assertTrue(Gate::forUser($superAdmin)->allows('staff.create', $branch));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('roles.view', $branch));
        $this->assertCount(12, $this->authorization->effectiveBranchPermissions($superAdmin, $branch));

        // Tách biệt chặt chẽ global vs branch: không đánh giá nhầm scope giữa hai phương thức
        $this->assertFalse($this->authorization->hasGlobalPermission($superAdmin, 'staff.create'));
        $this->assertFalse($this->authorization->hasBranchPermission($superAdmin, 'branches.create', $branch));

        // Ngay cả nếu có liên kết chéo phạm vi được chèn thô vào permission_role, AuthorizationService vẫn lọc scope chặt chẽ
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $globalPerm = Permission::query()->where('code', 'branches.create')->firstOrFail();
        DB::table('permission_role')->insert([
            'role_id' => $branchAdminRole->id,
            'permission_id' => $globalPerm->id,
        ]);

        $branchAdmin = User::factory()->staffActive()->create();
        $this->assignBranchRole($branchAdmin, $branch, $branchAdminRole);
        $this->assertFalse($this->authorization->hasGlobalPermission($branchAdmin, 'branches.create'));
        $this->assertFalse($this->authorization->hasBranchPermission($branchAdmin, 'branches.create', $branch));

        // Chạy lại RoleAndPermissionSeeder đồng bộ sạch về đúng 23 liên kết chuẩn
        $this->seed(RoleAndPermissionSeeder::class);
        $this->assertSame(22, Permission::query()->count());
        $this->assertSame(23, DB::table('permission_role')->count());
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

    private function assignBranchRole(User $user, Branch $branch, Role $role): BranchAssignment
    {
        $assignment = BranchAssignment::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'branch_id' => $branch->id,
            ],
            [
                'status' => BranchAssignmentStatus::Active->value,
            ],
        );

        BranchRoleAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role_id' => $role->id,
        ]);

        return $assignment;
    }
}
