<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model ghi nhận các sự kiện đăng nhập, đăng ký và bảo mật tài khoản (login_events).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $email
 * @property LoginEventType|string $event_type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property-read User|null $user
 */
class LoginEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'login_events';

    protected $fillable = [
        'user_id',
        'email',
        'event_type',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => LoginEventType::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Tài khoản người dùng liên quan đến sự kiện (nếu có).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
