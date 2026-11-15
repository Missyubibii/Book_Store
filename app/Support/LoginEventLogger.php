<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LoginEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoginEventLogger
{
    /**
     * Ghi nhận sự kiện nhật ký đăng nhập an toàn bằng Enum chuẩn hóa `LoginEventType`.
     *
     * @param  LoginEventType  $eventType  Loại sự kiện xác thực chuẩn hóa
     * @param  int|null  $userId  ID người dùng nếu có
     * @param  string|null  $email  Email liên quan
     * @param  string|null  $ipAddress  Địa chỉ IP
     * @param  string|null  $userAgent  Thông tin User-Agent
     */
    public static function log(
        LoginEventType $eventType,
        ?int $userId = null,
        ?string $email = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        try {
            DB::table('login_events')->insert([
                'user_id' => $userId,
                'email' => $email ? mb_substr($email, 0, 255) : null,
                'event_type' => $eventType->value,
                'ip_address' => $ipAddress ? mb_substr($ipAddress, 0, 45) : null,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 1000) : null,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Chính sách an toàn: Không làm sập ứng dụng hay gián đoạn trải nghiệm người dùng
            // nếu việc ghi nhật ký audit tạm thời lỗi, nhưng ghi nhận vào system log để điều tra.
            Log::error('Không thể ghi nhật ký sự kiện đăng nhập vào login_events', [
                'event_type' => $eventType->value,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
