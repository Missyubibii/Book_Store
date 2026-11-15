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
use App\Services\Authorization\BranchContextResolver;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BranchContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        Route::middleware(['web', 'staff.active', 'branch.context'])
            ->get('/_test/branches/{branch}/ops', function (Request $request) {
                $context = BranchContextResolver::fromRequest($request);

                return response()->json([
                    'branch_id' => $context?->branchId(),
                    'user_id' => $context?->userId(),
                    'via_super_admin' => $context?->viaSuperAdmin,
                    'roles' => $context?->roleCodes,
                    'permissions' => $context?->permissions,
                ]);
            });

        Route::middleware(['web', 'staff.active', 'branch.context'])
            ->get('/_test/session-branch/ops', function (Request $request) {
                $context = BranchContextResolver::fromRequest($request);

                return response()->json([
                    'branch_id' => $context?->branchId(),
                    'roles' => $context?->roleCodes,
                ]);
            });
    }

    public function test_branch_context_supports_multiple_branches_and_different_roles_without_singleton_leakage(): void
    {
        $branchA = Branch::factory()->retailBranch()->create(['code' => 'CN_CTX_A']);
        $branchB = Branch::factory()->retailBranch()->create(['code' => 'CN_CTX_B']);
        $branchC = Branch::factory()->retailBranch()->create(['code' => 'CN_CTX_C']);

        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();

        $roleViewer = Role::query()->create([
            'code' => 'branch_role_viewer',
            'name' => 'Xem vai trò chi nhánh',
            'scope' => RoleScope::Branch->value,
        ]);
        $roleViewer->permissions()->attach(
            Permission::query()->where('code', 'roles.view')->firstOrFail()->id,
        );

        $multiBranchStaff = User::factory()->staffActive()->create();
        $this->assignBranchRole($multiBranchStaff, $branchA, $staffRole);
        $this->assignBranchRole($multiBranchStaff, $branchA, $roleViewer);
        $this->assignBranchRole($multiBranchStaff, $branchB, $branchAdminRole);

        // Request 1: Chi nhánh A -> có đúng 2 vai trò ('branch_role_viewer', 'staff') và chỉ quyền tương ứng
        $responseA = $this->actingAs($multiBranchStaff)
            ->getJson("/_test/branches/{$branchA->id}/ops")
            ->assertOk()
            ->assertJson([
                'branch_id' => $branchA->id,
                'user_id' => $multiBranchStaff->id,
                'via_super_admin' => false,
                'roles' => ['branch_role_viewer', 'staff'],
            ]);

        $permsA = $responseA->json('permissions');
        $this->assertContains('branch_ops.access', $permsA);
        $this->assertContains('roles.view', $permsA);
        $this->assertNotContains('staff.create', $permsA);

        // Request 2 (cùng singleton container): Chi nhánh B -> chuyển sang vai trò 'branch_admin' (10 quyền), không rò rỉ state của A
        $responseB = $this->actingAs($multiBranchStaff)
            ->getJson("/_test/branches/{$branchB->id}/ops")
            ->assertOk()
            ->assertJson([
                'branch_id' => $branchB->id,
                'user_id' => $multiBranchStaff->id,
                'via_super_admin' => false,
                'roles' => ['branch_admin'],
            ]);
        $this->assertCount(10, $responseB->json('permissions'));

        // Request 3: Chi nhánh C chưa được phân công -> bị từ chối
        $this->actingAs($multiBranchStaff)
            ->getJson("/_test/branches/{$branchC->id}/ops")
            ->assertForbidden();
    }

    public function test_untrusted_client_branch_id_missing_inactive_revoked_and_cross_user_isolation(): void
    {
        $branchA = Branch::factory()->retailBranch()->create();
        $branchB = Branch::factory()->retailBranch()->create();
        $inactiveBranch = Branch::factory()->retailBranch()->inactive()->create();
        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();

        $user1 = User::factory()->staffActive()->create();
        $assignment1 = $this->assignBranchRole($user1, $branchA, $staffRole);

        // User 2 giữ branch_admin tại cùng branchA (chứng minh không rò rỉ khóa ghép user_id + branch_id)
        $user2 = User::factory()->staffActive()->create();
        $this->assignBranchRole($user2, $branchA, $branchAdminRole);
        $this->assignBranchRole($user2, $branchB, $branchAdminRole);

        // 1. Tham số query string `?branch_id=...` và header `X-Branch-Id` bị bỏ qua hoàn toàn
        $this->actingAs($user1)
            ->withHeaders(['X-Branch-Id' => (string) $branchB->id])
            ->getJson("/_test/session-branch/ops?branch_id={$branchB->id}")
            ->assertForbidden()
            ->assertJson(['code' => 'branch_context_missing']);

        // Khi route có {branch=branchB} dù session chọn branchA -> phải kiểm tra đúng branchB và từ chối user1
        $this->actingAs($user1)
            ->withSession([BranchContextResolver::SESSION_KEY => $branchA->id])
            ->withHeaders(['X-Branch-Id' => (string) $branchA->id])
            ->getJson("/_test/branches/{$branchB->id}/ops?branch_id={$branchA->id}")
            ->assertForbidden();

        // Khi dùng session hợp lệ -> user1 chỉ nhận đúng vai trò của riêng user1 (staff), KHÔNG dính branch_admin của user2
        $this->actingAs($user1)
            ->withSession([BranchContextResolver::SESSION_KEY => $branchA->id])
            ->getJson('/_test/session-branch/ops')
            ->assertOk()
            ->assertJson([
                'branch_id' => $branchA->id,
                'roles' => ['staff'],
            ]);

        // 2. Chi nhánh không tồn tại hoặc mã không hợp lệ
        $this->actingAs($user1)->getJson('/_test/branches/999999/ops')->assertNotFound();
        $this->actingAs($user1)->getJson('/_test/branches/abc/ops')->assertNotFound();

        // 3. Chi nhánh ngừng hoạt động (kể cả khi user có phân công)
        $this->assignBranchRole($user1, $inactiveBranch, $staffRole);
        $this->actingAs($user1)->getJson("/_test/branches/{$inactiveBranch->id}/ops")->assertForbidden();

        // 4. Phân công tại branchA bị thu hồi (`inactive`), trong khi user1 vẫn còn phân công hợp lệ tại branchC:
        //    -> `staff.active` cho qua nhờ branchC, nhưng `branch.context` lập tức từ chối ngữ cảnh branchA trong session.
        $branchC = Branch::factory()->retailBranch()->create();
        $this->assignBranchRole($user1, $branchC, $staffRole);

        $assignment1->update(['status' => BranchAssignmentStatus::Inactive->value]);
        $this->actingAs($user1)
            ->withSession([BranchContextResolver::SESSION_KEY => $branchA->id])
            ->getJson('/_test/session-branch/ops')
            ->assertForbidden()
            ->assertJson(['code' => 'branch_assignment_inactive_or_missing']);

        // 5. Phân công chi nhánh không có vai trò phạm vi branch -> từ chối
        BranchAssignment::query()->create([
            'user_id' => $user1->id,
            'branch_id' => $branchB->id,
            'status' => BranchAssignmentStatus::Active->value,
        ]);
        $this->actingAs($user1)
            ->getJson("/_test/branches/{$branchB->id}/ops")
            ->assertForbidden()
            ->assertJson(['code' => 'branch_role_assignment_missing']);
    }

    public function test_super_admin_branch_context_behavior_and_global_scope_isolation(): void
    {
        $retailBranch = Branch::factory()->retailBranch()->create();
        $inactiveBranch = Branch::factory()->retailBranch()->inactive()->create();
        $centralWh = Branch::query()->where('code', 'CENTRAL_WH')->firstOrFail();

        $superAdmin = User::factory()->staffActive()->create();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
        UserRole::query()->create([
            'user_id' => $superAdmin->id,
            'role_id' => $superAdminRole->id,
        ]);

        // Tạo một vai trò global phụ KHÔNG phải super_admin để kiểm tra phân tách scope
        $auditorRole = Role::query()->create([
            'code' => 'global_auditor',
            'name' => 'Kiểm toán viên toàn cục',
            'scope' => RoleScope::Global->value,
        ]);
        $auditorRole->permissions()->attach(
            Permission::query()->where('code', 'audit.view_global')->firstOrFail()->id,
        );
        $globalAuditor = User::factory()->staffActive()->create();
        UserRole::query()->create([
            'user_id' => $globalAuditor->id,
            'role_id' => $auditorRole->id,
        ]);

        // Global non-super-admin KHÔNG tự động có ngữ cảnh chi nhánh nếu chưa được phân công vào chi nhánh
        $this->actingAs($globalAuditor)
            ->getJson("/_test/branches/{$retailBranch->id}/ops")
            ->assertForbidden();

        // Super Admin hiệu lực truy cập được chi nhánh đang active và kho trung tâm active,
        // nhưng vẫn bị chặn tuyệt đối trên chi nhánh inactive
        $this->actingAs($superAdmin)
            ->getJson("/_test/branches/{$retailBranch->id}/ops")
            ->assertOk()
            ->assertJson([
                'branch_id' => $retailBranch->id,
                'via_super_admin' => true,
                'roles' => ['super_admin'],
            ]);

        $this->actingAs($superAdmin)
            ->getJson("/_test/branches/{$centralWh->id}/ops")
            ->assertOk()
            ->assertJson([
                'branch_id' => $centralWh->id,
                'via_super_admin' => true,
            ]);

        $this->actingAs($superAdmin)
            ->getJson("/_test/branches/{$inactiveBranch->id}/ops")
            ->assertForbidden()
            ->assertJson(['code' => 'branch_inactive']);
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
