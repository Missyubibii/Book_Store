<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bản ghi gán vai trò phạm vi chi nhánh (`branch_role_assignments`).
 *
 * Ràng buộc CSDL bảo đảm chỉ gán vai trò có `scope = 'branch'` và nhân sự phải có
 * bản ghi tương ứng trong `branch_assignments(user_id, branch_id)`.
 */
class BranchRoleAssignment extends Model
{
    /**
     * Khóa mặc định phạm vi chi nhánh khớp ràng buộc CSDL.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role_scope' => RoleScope::Branch->value,
    ];

    /**
     * Các trường được phép gán hàng loạt (`role_scope` luôn cố định là `branch`).
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'branch_id',
        'role_id',
        'assigned_by',
        'assigned_at',
    ];

    /**
     * Tài khoản nhân sự được gán vai trò tại chi nhánh.
     *
     * Flow: `BranchRoleAssignment` -> `User` (`branch_role_assignments.user_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Chi nhánh áp dụng vai trò.
     *
     * Flow: `BranchRoleAssignment` -> `Branch` (`branch_role_assignments.branch_id`).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Vai trò phạm vi `branch` được gán.
     *
     * Flow: `BranchRoleAssignment` -> `Role` (`branch_role_assignments.role_id`).
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Người quản trị đã thực hiện gán vai trò chi nhánh.
     *
     * Flow: `BranchRoleAssignment` -> `User` (`branch_role_assignments.assigned_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Định nghĩa ép kiểu thuộc tính chuẩn hóa.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_scope' => RoleScope::class,
            'assigned_at' => 'datetime',
        ];
    }
}
