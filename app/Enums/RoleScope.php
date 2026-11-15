<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phạm vi áp dụng của vai trò và quyền hạn (cột `roles.scope`, `permissions.scope`).
 *
 * Ràng buộc tương thích:
 * - Vai trò `Global` gán qua `user_roles` (`role_scope = 'global'`).
 * - Vai trò `Branch` gán qua `branch_role_assignments` (`role_scope = 'branch'`).
 */
enum RoleScope: string
{
    case Global = 'global';
    case Branch = 'branch';

    /**
     * Danh sách giá trị chuỗi hợp lệ lưu trong cơ sở dữ liệu.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Nhãn hiển thị tiếng Việt chuẩn hóa.
     */
    public function label(): string
    {
        return __('enums.role_scope.'.$this->value);
    }

    /**
     * Kiểm tra phạm vi toàn hệ thống hay không.
     */
    public function isGlobal(): bool
    {
        return $this === self::Global;
    }

    /**
     * Kiểm tra phạm vi theo chi nhánh hay không.
     */
    public function isBranch(): bool
    {
        return $this === self::Branch;
    }
}
