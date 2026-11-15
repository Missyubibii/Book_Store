<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B3;

use App\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Authorization\PermissionCatalog;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_permission_catalog_counts_and_role_mappings_are_consistent(): void
    {
        $permissions = PermissionCatalog::permissions();
        $roles = PermissionCatalog::roles();
        $scopeByCode = PermissionCatalog::scopeByCode();

        $this->assertCount(22, $permissions);
        $this->assertCount(22, $scopeByCode);
        $this->assertCount(4, $roles);

        $globalPermissions = array_values(array_filter(
            $permissions,
            static fn (array $item): bool => $item['scope'] === RoleScope::Global,
        ));
        $branchPermissions = array_values(array_filter(
            $permissions,
            static fn (array $item): bool => $item['scope'] === RoleScope::Branch,
        ));

        $this->assertCount(10, $globalPermissions);
        $this->assertCount(12, $branchPermissions);

        $rolesByCode = collect($roles)->keyBy('code');
        $this->assertCount(10, $rolesByCode['super_admin']['permissions']);
        $this->assertCount(10, $rolesByCode['branch_admin']['permissions']);
        $this->assertCount(1, $rolesByCode['staff']['permissions']);
        $this->assertCount(2, $rolesByCode['warehouse_manager']['permissions']);

        $totalLinks = collect($roles)->sum(static fn (array $role): int => count($role['permissions']));
        $this->assertSame(23, $totalLinks);

        // Xác minh mỗi quyền được gán cho vai trò đều cùng phạm vi và có tồn tại trong danh mục
        foreach ($roles as $role) {
            foreach ($role['permissions'] as $code) {
                $this->assertArrayHasKey($code, $scopeByCode);
                $this->assertSame($role['scope'], $scopeByCode[$code]);
            }
        }

        // Xác minh `roles.revoke_global` không tồn tại vì đã được hợp nhất vào `roles.assign_global`
        $this->assertArrayNotHasKey('roles.revoke_global', $scopeByCode);
        $this->assertArrayHasKey('roles.assign_global', $scopeByCode);
    }

    public function test_all_policy_and_service_permission_references_match_canonical_catalog(): void
    {
        $scopeByCode = PermissionCatalog::scopeByCode();
        $files = array_merge(
            glob(app_path('Policies/*.php')) ?: [],
            glob(app_path('Services/Authorization/*.php')) ?: [],
        );

        $this->assertNotEmpty($files);

        $discoveredCount = 0;

        foreach ($files as $filePath) {
            $contents = (string) file_get_contents($filePath);

            // Không được tồn tại tham chiếu `roles.revoke_global` ở bất kỳ Policy hoặc Service nào
            $this->assertStringNotContainsString('roles.revoke_global', $contents, "Tìm thấy tham chiếu lỗi thời trong {$filePath}");

            if (preg_match_all('/hasGlobalPermission\([^,]+,\s*[\'"]([^\'"]+)[\'"]\)/', $contents, $globalMatches)) {
                foreach ($globalMatches[1] as $permissionCode) {
                    $discoveredCount++;
                    $this->assertArrayHasKey($permissionCode, $scopeByCode, "Quyền chưa định nghĩa '{$permissionCode}' tại {$filePath}");
                    $this->assertSame(RoleScope::Global, $scopeByCode[$permissionCode], "Sai phạm vi global cho '{$permissionCode}' tại {$filePath}");
                }
            }

            if (preg_match_all('/hasBranchPermission\([^,]+,\s*[\'"]([^\'"]+)[\'"]/', $contents, $branchMatches)) {
                foreach ($branchMatches[1] as $permissionCode) {
                    $discoveredCount++;
                    $this->assertArrayHasKey($permissionCode, $scopeByCode, "Quyền chưa định nghĩa '{$permissionCode}' tại {$filePath}");
                    $this->assertSame(RoleScope::Branch, $scopeByCode[$permissionCode], "Sai phạm vi branch cho '{$permissionCode}' tại {$filePath}");
                }
            }
        }

        $this->assertGreaterThanOrEqual(20, $discoveredCount);
    }

    public function test_role_and_permission_seeder_is_idempotent_and_reconciles_legacy_17_permission_catalog(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $existingUser = User::factory()->staffActive()->create();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
        UserRole::query()->create([
            'user_id' => $existingUser->id,
            'role_id' => $superAdminRole->id,
        ]);

        // Mô phỏng trạng thái Stage 01B.2.2 (17 quyền / 18 liên kết)
        Permission::query()->whereIn('code', [
            'staff.reset_temp_password',
            'staff.suspend',
            'roles.view',
        ])->delete();

        $this->assertSame(19, Permission::query()->count());
        $this->assertSame(20, DB::table('permission_role')->count());

        // Chạy lại seeder nhiều lần liên tiếp
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame(22, Permission::query()->count());
        $this->assertSame(4, Role::query()->count());
        $this->assertSame(23, DB::table('permission_role')->count());
        $this->assertSame(1, UserRole::query()->where('user_id', $existingUser->id)->count());

        // Super Admin không thể tự thu hồi vai trò của chính mình dù hợp lệ toàn cục
        $this->assertFalse(Gate::forUser($existingUser)->allows('revokeGlobalRole', [$existingUser, $superAdminRole]));
    }
}
