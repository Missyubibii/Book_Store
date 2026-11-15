<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bản ghi gán vai trò phạm vi toàn hệ thống (`user_roles`).
 *
 * Ràng buộc CSDL (`role_scope = 'global'` và khóa ngoại tổng hợp tới `roles(id, scope)`)
 * bảo đảm bảng này chỉ chứa các vai trò phạm vi toàn hệ thống.
 */
class UserRole extends Model
{
    /**
     * Khóa mặc định phạm vi toàn hệ thống khớp ràng buộc CSDL.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role_scope' => RoleScope::Global->value,
    ];

    /**
     * Các trường được phép gán hàng loạt (`role_scope` luôn cố định là `global`).
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'role_id',
        'assigned_by',
        'assigned_at',
    ];

    /**
     * Tài khoản được gán vai trò toàn hệ thống.
     *
     * Flow: `UserRole` -> `User` (`user_roles.user_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vai trò phạm vi `global` được gán.
     *
     * Flow: `UserRole` -> `Role` (`user_roles.role_id`).
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Người quản trị đã thực hiện gán vai trò (có thể null khi bootstrap CLI).
     *
     * Flow: `UserRole` -> `User` (`user_roles.assigned_by`).
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
