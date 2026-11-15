<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Giới hạn tối đa số lần đăng nhập sai liên tiếp theo email + IP.
     */
    public const MAX_ATTEMPTS_PER_CREDENTIAL = 5;

    /**
     * Giới hạn tối đa số lần đăng nhập sai liên tiếp theo IP (chống tấn công diện rộng).
     */
    public const MAX_ATTEMPTS_PER_IP = 20;

    /**
     * Thời gian khóa thử tính bằng giây (1 phút).
     */
    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Chuẩn hóa dữ liệu email (cắt khoảng trắng và chuyển chữ thường) trước khi
     * kiểm tra tính hợp lệ và tạo khóa giới hạn tần suất (Rate Limiter).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge([
                'email' => Str::lower(trim($this->email)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'lowercase', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Tên thuộc tính tiếng Việt dùng cho thông báo lỗi đăng nhập.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'địa chỉ email',
            'password' => 'mật khẩu',
            'remember' => 'ghi nhớ đăng nhập',
        ];
    }

    /**
     * Kiểm tra trạng thái giới hạn tần suất đăng nhập (Rate Limiter).
     *
     * @throws ValidationException khi vượt quá số lần thử cho phép.
     */
    public function ensureIsNotRateLimited(): void
    {
        // Kiểm tra giới hạn theo Email + IP
        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS_PER_CREDENTIAL)) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        // Kiểm tra giới hạn bổ sung theo IP
        if (RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP)) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($this->ipThrottleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    /**
     * Tăng số lần thử sai của cả 2 bộ đếm (Credential & IP).
     */
    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
        RateLimiter::hit($this->ipThrottleKey(), self::DECAY_SECONDS);
    }

    /**
     * Xóa các bộ đếm rate limiter sau khi đăng nhập thành công.
     */
    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->ipThrottleKey());
    }

    /**
     * Khóa nhận diện Rate Limiter kết hợp email đã chuẩn hóa và địa chỉ IP.
     */
    public function throttleKey(): string
    {
        $normalizedEmail = Str::lower(trim((string) $this->input('email')));

        return Str::transliterate($normalizedEmail.'|'.$this->ip());
    }

    /**
     * Khóa nhận diện Rate Limiter theo địa chỉ IP.
     */
    public function ipThrottleKey(): string
    {
        return 'login_ip|'.$this->ip();
    }
}

