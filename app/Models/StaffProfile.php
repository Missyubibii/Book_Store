<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hồ sơ nhân sự tách riêng khỏi bảng `users` nhằm bảo toàn thông tin công việc
 * và lịch sử nhân sự (`ON DELETE RESTRICT` trên `user_id`).
 */
class StaffProfile extends Model
{
    /** @use HasFactory<StaffProfileFactory> */
    use HasFactory;

    /**
     * Các trường hành chính cho phép gán hàng loạt.
     * Mốc trạng thái vòng đời (`activated_at`, `suspended_at`) bị chặn khỏi `$fillable`
     * để chỉ được cập nhật bởi dịch vụ phê duyệt/quản trị nhân sự.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'staff_code',
        'job_title',
        'hired_at',
        'notes',
    ];

    /**
     * Tài khoản người dùng gắn với hồ sơ nhân sự này.
     *
     * Flow: `StaffProfile` -> `User` (`staff_profiles.user_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Định nghĩa ép kiểu thuộc tính chuẩn hóa.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }
}
