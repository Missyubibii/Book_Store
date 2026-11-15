<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B3;

use App\Enums\BranchAssignmentStatus;
use App\Enums\LoginEventType;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StaffMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        // Đăng ký tuyến thử nghiệm chỉ tồn tại trong môi trường kiểm thử cô lập (`testing`).
        Route::middleware(['web', 'staff.active'])->get('/_test/internal/staff-area', function () {
            return response()->json(['status' => 'ok']);
        })->name('test.internal.staff');

        Route::middleware(['web', 'staff.onboarding'])->get('/_test/internal/onboarding', function () {
            return response()->json(['status' => 'onboarding-ok']);
        })->name('test.internal.onboarding');
    }

    public function test_guest_customer_pending_inactive_and_suspended_users_are_denied_internal_access(): void
    {
        $branch = Branch::factory()->retailBranch()->create();
        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();

        // 1. Khách vãng lai
        $this->get('/_test/internal/staff-area')->assertRedirect('/dang-nhap');
        $this->getJson('/_test/internal/staff-area')->assertUnauthorized();

        // 2. Khách hàng thông thường (not_applicable)
        $customer = User::factory()->create();
        $this->actingAs($customer)
            ->get('/_test/internal/staff-area')
            ->assertForbidden()
            ->assertSeeText('Bạn không có quyền truy cập vào khu vực nhân viên.');
        $this->actingAs($customer)
            ->getJson('/_test/internal/staff-area')
            ->assertForbidden()
            ->assertJson(['message' => __('auth.staff_access_denied')]);

        // 3. Nhân sự đang chờ phê duyệt (pending) dù đã gán chi nhánh/vai trò
        $pendingStaff = User::factory()->staffPending()->create();
        $this->assignBranchRole($pendingStaff, $branch, $staffRole);

        $this->actingAs($pendingStaff)
            ->get('/_test/internal/staff-area')
            ->assertForbidden()
            ->assertSeeText(__('auth.staff_pending'));

        // 4. Nhân sự tạm ngưng (inactive) dù còn vai trò
        $inactiveStaff = User::factory()->staffInactive()->create();
        $this->assignBranchRole($inactiveStaff, $branch, $staffRole);

        $this->actingAs($inactiveStaff)
            ->getJson('/_test/internal/staff-area')
            ->assertForbidden()
            ->assertJson(['message' => __('auth.staff_inactive')]);

        // 5. Tài khoản bị khóa (suspended) phải bị thu hồi phiên và ghi nhật ký
        $suspendedStaff = User::factory()->staffActive()->suspended()->create();
        $this->assignBranchRole($suspendedStaff, $branch, $staffRole);

        $this->actingAs($suspendedStaff)
            ->get('/_test/internal/staff-area')
            ->assertRedirect('/dang-nhap');

        $this->assertGuest();
        $this->assertDatabaseHas('login_events', [
            'user_id' => $suspendedStaff->id,
            'event_type' => LoginEventType::SessionRevoked->value,
        ]);
    }

    public function test_mandatory_password_onboarding_separation_and_deny_by_default_without_roles(): void
    {
        $branch = Branch::factory()->retailBranch()->create();
        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();

        // 1. Nhân sự pending giữ mật khẩu tạm: truy cập được onboarding nhưng vẫn bị chặn khỏi khu vực nội bộ
        $pendingOnboarding = User::factory()->staffPending()->mustChangePassword()->create();
        $this->actingAs($pendingOnboarding)->getJson('/_test/internal/onboarding')->assertOk();
        $this->actingAs($pendingOnboarding)->getJson('/_test/internal/staff-area')->assertForbidden();

        // Sau khi đổi mật khẩu tạm nhưng chưa được duyệt kích hoạt (vẫn pending) -> bị chặn ở cả hai nơi
        $pendingOnboarding->forceFill(['must_change_password' => false])->save();
        $this->actingAs($pendingOnboarding)->getJson('/_test/internal/onboarding')->assertForbidden();
        $this->actingAs($pendingOnboarding)->getJson('/_test/internal/staff-area')->assertForbidden();

        // 2. Nhân sự active nhưng vẫn giữ mật khẩu tạm -> phải qua onboarding trước, chưa vào được khu vực nội bộ
        $activeOnboarding = User::factory()->staffActive()->mustChangePassword()->create();
        $this->assignBranchRole($activeOnboarding, $branch, $staffRole);

        $this->actingAs($activeOnboarding)->getJson('/_test/internal/onboarding')->assertOk();
        $this->actingAs($activeOnboarding)
            ->getJson('/_test/internal/staff-area')
            ->assertForbidden()
            ->assertJson(['message' => __('auth.staff_password_change_required')]);

        // 3. Nhân sự active + đã đổi mật khẩu nhưng KHÔNG có vai trò/phân công hiệu lực nào -> từ chối mặc định
        $orphanActiveStaff = User::factory()->staffActive()->create();
        $this->actingAs($orphanActiveStaff)
            ->getJson('/_test/internal/staff-area')
            ->assertForbidden()
            ->assertJson(['message' => __('auth.staff_authorization_missing')]);

        // 4. Nhân sự active + đã đổi mật khẩu + có phân công chi nhánh và vai trò hợp lệ -> được phép
        $validStaff = User::factory()->staffActive()->create();
        $this->assignBranchRole($validStaff, $branch, $staffRole);

        $this->actingAs($validStaff)
            ->getJson('/_test/internal/staff-area')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
        $this->actingAs($validStaff)->getJson('/_test/internal/onboarding')->assertForbidden();
    }

    public function test_public_storefront_and_customer_auth_routes_remain_unaffected(): void
    {
        $customer = User::factory()->unverified()->create();
        $pendingStaff = User::factory()->staffPending()->mustChangePassword()->create();

        $this->get('/')->assertOk();
        $this->get('/dang-nhap')->assertOk();
        $this->get('/dang-ky')->assertOk();

        $this->actingAs($customer)->get('/')->assertOk();
        $this->actingAs($customer)->get('/xac-thuc-email')->assertOk();
        $this->actingAs($customer)->post('/dang-xuat')->assertRedirect('/');

        $this->actingAs($pendingStaff)->get('/')->assertOk();
    }

    public function test_authorization_engine_and_gate_fail_closed_when_pending_permissions_are_absent_from_database(): void
    {
        $branch = Branch::factory()->retailBranch()->create();
        $superAdmin = User::factory()->staffActive()->create();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
        UserRole::query()->create([
            'user_id' => $superAdmin->id,
            'role_id' => $superAdminRole->id,
        ]);

        $branchAdmin = User::factory()->staffActive()->create();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $this->assignBranchRole($branchAdmin, $branch, $branchAdminRole);

        // Mô phỏng trạng thái CSDL phát triển hiện tại (17 quyền) chưa seed 3 quyền mới của Stage 01B.3
        Permission::query()->whereIn('code', [
            'staff.reset_temp_password',
            'staff.suspend',
            'roles.view',
        ])->delete();

        /** @var AuthorizationService $authorization */
        $authorization = app(AuthorizationService::class);

        $this->assertFalse($authorization->hasGlobalPermission($superAdmin, 'staff.suspend'));
        $this->assertFalse($authorization->hasGlobalPermission($superAdmin, 'staff.reset_temp_password'));
        $this->assertFalse($authorization->hasBranchPermission($superAdmin, 'roles.view', $branch));
        $this->assertFalse($authorization->hasBranchPermission($branchAdmin, 'roles.view', $branch));

        $this->assertFalse(Gate::forUser($superAdmin)->allows('staff.suspend'));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('staff.reset_temp_password'));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('roles.view', $branch));
        $this->assertFalse(Gate::forUser($branchAdmin)->allows('roles.view', $branch));

        // Các quyền đã seed vẫn vận hành bình thường
        $this->assertTrue(Gate::forUser($superAdmin)->allows('branches.create'));
        $this->assertTrue(Gate::forUser($branchAdmin)->allows('staff.view', $branch));
    }

    private function assignBranchRole(User $user, Branch $branch, Role $role): void
    {
        BranchAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'status' => BranchAssignmentStatus::Active->value,
        ]);

        BranchRoleAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role_id' => $role->id,
        ]);
    }
}
