<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Khởi tạo danh mục vai trò hệ thống và quyền hạn RBAC chuẩn hóa cho Stage 01B.
 *
 * Bảo đảm tính idempotent khi chạy lại nhiều lần, tách biệt hoàn toàn phạm vi `global`
 * và `branch`, và KHÔNG BAO GIỜ gán vai trò cho bất kỳ người dùng nào.
 */
class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Chạy seeder danh mục vai trò và quyền hạn.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissionsByCode = $this->seedPermissions();
            $this->seedRolesAndLinks($permissionsByCode);
        });
    }

    /**
     * Khởi tạo danh mục 20 quyền hạn chuẩn từ `PermissionCatalog`.
     *
     * @return array<string, Permission>
     */
    private function seedPermissions(): array
    {
        $seeded = [];

        foreach (PermissionCatalog::permissions() as $definition) {
            /** @var Permission $permission */
            $permission = Permission::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'module' => $definition['module'],
                    'name' => $definition['name'],
                    'scope' => $definition['scope']->value,
                    'description' => $definition['description'],
                ],
            );

            $seeded[$permission->code] = $permission;
        }

        return $seeded;
    }

    /**
     * Khởi tạo 3 vai trò hệ thống chuẩn và đồng bộ quyền hạn tương thích phạm vi.
     *
     * @param  array<string, Permission>  $permissionsByCode
     */
    private function seedRolesAndLinks(array $permissionsByCode): void
    {
        foreach (PermissionCatalog::roles() as $roleDefinition) {
            /** @var Role $role */
            $role = Role::query()->firstOrNew(['code' => $roleDefinition['code']]);

            if ($role->exists && $role->scope !== $roleDefinition['scope']) {
                throw new \RuntimeException(sprintf(
                    "Xung đột phạm vi cho vai trò hệ thống '%s': đang có '%s', yêu cầu '%s'.",
                    $roleDefinition['code'],
                    $role->scope->value,
                    $roleDefinition['scope']->value,
                ));
            }

            $role->forceFill([
                'name' => $roleDefinition['name'],
                'scope' => $roleDefinition['scope']->value,
                'description' => $roleDefinition['description'],
                'is_system' => true,
            ])->save();

            $permissionIds = [];

            foreach ($roleDefinition['permissions'] as $permissionCode) {
                $permission = $permissionsByCode[$permissionCode] ?? null;

                if ($permission === null) {
                    throw new \LogicException("Không tìm thấy quyền '{$permissionCode}' trong danh mục vừa khởi tạo.");
                }

                if ($permission->scope !== $role->scope) {
                    throw new \LogicException(sprintf(
                        "Vi phạm tính tương thích phạm vi RBAC: không thể gán quyền '%s' (%s) cho vai trò '%s' (%s).",
                        $permission->code,
                        $permission->scope->value,
                        $role->code,
                        $role->scope->value,
                    ));
                }

                $permissionIds[] = $permission->id;
            }

            $role->permissions()->sync($permissionIds);
        }
    }
}
