<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Danh mục quyền hạn chi tiết (`permissions`) phân tách phạm vi `global` và `branch`.
 */
class Permission extends Model
{
    /**
     * Các trường danh mục quyền được phép gán hàng loạt.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'module',
        'name',
        'scope',
        'description',
    ];

    /**
     * Các vai trò sở hữu quyền hạn này qua bảng `permission_role`.
     *
     * Flow: `Permission` -> `permission_role` -> `Role`.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
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
        ];
    }
}
