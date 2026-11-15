<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use App\Support\LoginEventLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegisterCustomerAction
{
    /**
     * Đăng ký tài khoản khách hàng mới với các giá trị đặc quyền được gán an toàn từ nội bộ.
     *
     * Flow: RegisterController@store -> RegisterCustomerAction::execute()
     *       -> DB::transaction (chỉ lưu bản ghi User)
     *       -> Auth::login & tái tạo session (ngoài transaction)
     *       -> Ghi nhật ký register_success & phát sự kiện Registered.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function execute(array $data, ?Request $request = null): User
    {
        $normalizedName = trim($data['name']);
        $normalizedEmail = mb_strtolower(trim($data['email']));
        $hashedPassword = Hash::make($data['password']);

        // 1. Giao dịch cơ sở dữ liệu chỉ chứa thao tác ghi bản ghi User cần thiết
        $user = DB::transaction(function () use ($normalizedName, $normalizedEmail, $hashedPassword): User {
            $user = new User([
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'password' => $hashedPassword,
            ]);

            // Gán tường minh các trường trạng thái đặc quyền qua logic nội bộ bằng Enum chuẩn hóa
            $user->account_status = AccountStatus::Active;
            $user->staff_access_status = StaffAccessStatus::NotApplicable; // Khách hàng không thuộc quan hệ nhân sự

            $user->save();

            return $user;
        });

        // 2. Thiết lập phiên đăng nhập và tái tạo session ngoài transaction sau khi DB đã commit
        Auth::login($user);

        if ($request && $request->hasSession()) {
            $request->session()->regenerate();
        }

        // 3. Ghi nhật ký đăng ký thành công
        LoginEventLogger::log(
            eventType: LoginEventType::RegisterSuccess,
            userId: $user->id,
            email: $user->email,
            ipAddress: $request?->ip(),
            userAgent: $request?->userAgent()
        );

        // 4. Kích hoạt event Registered ngoài transaction để việc gửi email đồng bộ
        // không giữ khóa DB hoặc làm hỏng trạng thái phiên đăng nhập nếu dịch vụ thư gặp sự cố.
        try {
            event(new Registered($user));
        } catch (Throwable $e) {
            Log::error('Không thể gửi email xác thực ngay sau khi đăng ký tài khoản', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }
}
