<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Danh mục vai trò RBAC (`roles`) phân tách chặt chẽ theo phạm vi `global` và `branch`.
 */
class Role extends Model
{
    /**
     * Giá trị mặc định cho vai trò tùy biến (vai trò hệ thống chỉ được đánh dấu qua seeder).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_system' => false,
    ];

    /**
     * Các trường được phép gán hàng loạt; cờ `is_system` được bảo vệ khỏi mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'scope',
        'description',
    ];

    /**
     * Tập quyền hạn gắn với vai trò này qua bảng `permission_role`.
     *
     * Flow: `Role` -> `permission_role` -> `Permission`.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /**
     * Các bản ghi gán vai trò phạm vi toàn hệ thống (`user_roles`).
     *
     * Flow: `Role` -> `UserRole` (`user_roles.role_id`).
     *
     * @return HasMany<UserRole, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Các bản ghi gán vai trò phạm vi chi nhánh (`branch_role_assignments`).
     *
     * Flow: `Role` -> `BranchRoleAssignment` (`branch_role_assignments.role_id`).
     *
     * @return HasMany<BranchRoleAssignment, $this>
     */
    public function branchRoleAssignments(): HasMany
    {
        return $this->hasMany(BranchRoleAssignment::class);
    }

    /**
     * Các tài khoản được gán vai trò toàn hệ thống qua `user_roles`.
     *
     * Flow: `Role` -> `user_roles` -> `User`.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['role_scope', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Định nghĩa ép kiểu thuộc tính chuẩn hóa.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
        ];
    }
}
