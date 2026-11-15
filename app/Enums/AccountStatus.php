<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Trạng thái tổng thể của tài khoản người dùng (cột `users.account_status`).
 *
 * Dùng để kiểm soát khả năng đăng nhập và duy trì phiên làm việc của tài khoản,
 * tách biệt hoàn toàn với trạng thái truy cập nhân sự (`StaffAccessStatus`).
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

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
        return __('enums.account_status.'.$this->value);
    }

    /**
     * Kiểm tra tài khoản có đủ điều kiện đăng nhập và duy trì phiên hay không.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * Các trạng thái đích hợp lệ khi chuyển đổi từ trạng thái hiện tại.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
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
