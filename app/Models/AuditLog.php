<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Nhật ký kiểm toán hành chính (`audit_logs`) — chỉ cho phép ghi mới (append-only).
 *
 * Quy tắc bảo mật (Zero-Secret Rule):
 * - Tuyệt đối KHÔNG lưu mật khẩu gốc, mật khẩu tạm, password hash, session ID, token
 *   hay bất kỳ bí mật xác thực nào vào `old_values`, `new_values` hoặc `reason`.
 * - Mọi thao tác cập nhật hoặc xóa bản ghi `AuditLog` qua Eloquent đều bị chặn bằng `LogicException`.
 */
class AuditLog extends Model
{
    /**
     * Bảng `audit_logs` chỉ sử dụng `occurred_at`, không có `created_at`/`updated_at`.
     */
    public $timestamps = false;

    /**
     * Các trường được phép ghi nhận khi tạo mới bản ghi kiểm toán.
     *
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'target_user_id',
        'branch_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'reason',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    /**
     * Ngăn chặn mọi thao tác chỉnh sửa hoặc xóa bản ghi kiểm toán ở tầng Eloquent.
     */
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('Nhật ký kiểm toán (audit_logs) chỉ cho phép ghi mới, không được phép cập nhật.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('Nhật ký kiểm toán (audit_logs) chỉ cho phép ghi mới, không được phép xóa.');
        });
    }

    /**
     * Tài khoản đã thực hiện hành động quản trị (`actor_id`).
     *
     * Flow: `AuditLog` -> `User` (`audit_logs.actor_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Tài khoản chịu tác động của hành động (nếu có).
     *
     * Flow: `AuditLog` -> `User` (`audit_logs.target_user_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Chi nhánh/kho liên quan đến sự kiện kiểm toán (nếu có).
     *
     * Flow: `AuditLog` -> `Branch` (`audit_logs.branch_id`).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Đối tượng nghiệp vụ phát sinh sự kiện (`subject_type`, `subject_id`).
     *
     * Flow: `AuditLog` -> `Model` đa hình theo `subject_type` / `subject_id`.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Định nghĩa ép kiểu thuộc tính chuẩn hóa.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
