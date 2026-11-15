<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model quản lý định danh liên kết bên ngoài của người dùng (Google OAuth 2.0).
 *
 * Flow:
 * User -> HasMany -> UserExternalIdentity (`user_external_identities`)
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_subject
 * @property string|null $provider_email
 * @property \Illuminate\Support\Carbon $linked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $user
 */
class UserExternalIdentity extends Model
{
    use HasFactory;

    /**
     * Tên bảng trong cơ sở dữ liệu.
     *
     * @var string
     */
    protected $table = 'user_external_identities';

    /**
     * Các trường được phép gán hàng loạt.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_subject',
        'provider_email',
        'linked_at',
    ];

    /**
     * Ép kiểu các thuộc tính.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    /**
     * Tài khoản người dùng sở hữu định danh ngoài này.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Che giấu mã Subject ID của nhà cung cấp phục vụ bảo mật hiển thị (chỉ để lại 4 ký tự đầu và cuối).
     */
    public function getMaskedSubjectAttribute(): string
    {
        $subject = (string) $this->provider_subject;
        $len = strlen($subject);

        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($subject, 0, 4) . '...' . substr($subject, -4);
    }
}
