<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loại sự kiện nhật ký xác thực (cột `login_events.event_type`).
 */
enum LoginEventType: string
{
    case RegisterSuccess = 'register_success';
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case AccountLocked = 'account_locked';
    case Logout = 'logout';
    case SessionRevoked = 'session_revoked';

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
        return __('enums.login_event_type.'.$this->value);
    }
}
