<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginEventType;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\LoginEventLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticateUserAction
{
    /**
     * Chuỗi băm Bcrypt giả hợp lệ (cost 12) dùng để thực hiện phép kiểm tra đối xứng
     * khi email không tồn tại, giúp giảm thiểu chênh lệch thời gian phản hồi (Timing Side-Channel).
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /**
     * Xác thực người dùng, bảo vệ chống brute-force và chặn tài khoản bị đình chỉ.
     *
     *
     * @throws ValidationException
     */
    public function execute(LoginRequest $request): User
    {
        // 1. Kiểm tra trạng thái rate limiter trước
        $request->ensureIsNotRateLimited();

        $email = mb_strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');
        $remember = (bool) $request->boolean('remember');

        // 2. Tìm người dùng theo email
        $user = User::where('email', $email)->first();

        // 3. Xác minh mật khẩu an toàn mà KHÔNG tạo session trước.
        // Luôn chạy Hash::check ngay cả khi $user === null để tránh lộ sự tồn tại của email qua thời gian xử lý.
        $targetHash = $user !== null ? (string) $user->password : self::DUMMY_PASSWORD_HASH;
        $passwordMatches = Hash::check($password, $targetHash);
        $isPasswordValid = $user !== null && $passwordMatches;

        // 4. Xử lý trường hợp xác thực thất bại
        if (! $isPasswordValid) {
            // Tăng bộ đếm thử sai
            $request->hitRateLimiter();

            // Ghi nhật ký thất bại (kể cả khi user không tồn tại để lưu IP và email tấn công)
            LoginEventLogger::log(
                eventType: LoginEventType::LoginFailed,
                userId: $user?->id,
                email: $email,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            // Báo lỗi generic tiếng Việt (chống liệt kê tài khoản)
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // 5. Kiểm tra trạng thái tài khoản: Chỉ AccountStatus::Active mới được phép đăng nhập
        if (! $user->isActive()) {
            // Tăng bộ đếm throttle để chống thăm dò trạng thái tài khoản
            $request->hitRateLimiter();

            // Ghi nhật ký tài khoản bị khóa
            LoginEventLogger::log(
                eventType: LoginEventType::AccountLocked,
                userId: $user->id,
                email: $email,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            // TUYỆT ĐỐI KHÔNG GỌI Auth::login(). Không có session nào được tạo.
            throw ValidationException::withMessages([
                'email' => trans('auth.account_suspended'),
            ]);
        }

        // 5b. Kiểm tra hạn hiệu lực mật khẩu tạm thời (24 giờ) nếu tài khoản đang ở trạng thái bắt buộc đổi mật khẩu
        if ($user->isTemporaryPasswordExpired()) {
            $request->hitRateLimiter();

            LoginEventLogger::log(
                eventType: LoginEventType::LoginFailed,
                userId: $user->id,
                email: $email,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            throw ValidationException::withMessages([
                'email' => trans('auth.temp_password_expired'),
            ]);
        }

        // 6. Xác thực thành công: Thiết lập phiên đăng nhập
        Auth::login($user, $remember);

        // 7. Tái tạo session ID chống Session Fixation
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // 8. Xóa bộ đếm throttle
        $request->clearRateLimiter();

        // 9. Ghi nhật ký đăng nhập thành công
        LoginEventLogger::log(
            eventType: LoginEventType::LoginSuccess,
            userId: $user->id,
            email: $email,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        return $user;
    }
}
