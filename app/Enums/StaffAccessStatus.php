<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Trạng thái quan hệ & điều kiện truy cập nhân sự (cột `users.staff_access_status`).
 *
 * Quy tắc bất biến:
 * - Tài khoản khách hàng thông thường BẮT BUỘC dùng `NotApplicable` (`not_applicable`).
 * - Giá trị `Active` (`active`) chỉ xác nhận trạng thái nhân sự đang kích hoạt,
 *   tuyệt đối KHÔNG tự cấp quyền quản trị hay quyền theo chi nhánh.
 */
enum StaffAccessStatus: string
{
    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
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
        return __('enums.staff_access_status.'.$this->value);
    }

    /**
     * Chỉ `Active` mới đủ điều kiện bước đầu cho luồng nhân sự (vẫn cần RBAC & phạm vi chi nhánh).
     */
    public function isEligibleForStaffAccess(): bool
    {
        return $this === self::Active;
    }

    /**
     * Các trạng thái đích hợp lệ khi chuyển đổi trạng thái nhân sự.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NotApplicable => [self::Pending],
            self::Pending => [self::Active, self::Inactive, self::NotApplicable],
            self::Active => [self::Inactive, self::NotApplicable],
            self::Inactive => [self::Active, self::Pending, self::NotApplicable],
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
