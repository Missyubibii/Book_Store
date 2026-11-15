<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Trạng thái phân công nhân viên vào một chi nhánh/kho (cột `branch_assignments.status`).
 */
enum BranchAssignmentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

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
        return __('enums.branch_assignment_status.'.$this->value);
    }

    /**
     * Kiểm tra phân công chi nhánh có đang hoạt động hay không.
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Các trạng thái đích hợp lệ khi chuyển đổi trạng thái phân công.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Inactive],
            self::Inactive => [self::Active],
        };
    }

    /**
     * Kiểm tra chuyển đổi trạng thái hợp lệ.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
