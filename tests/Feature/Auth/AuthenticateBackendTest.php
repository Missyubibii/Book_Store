<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\LogoutUserAction;
use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthenticateBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_authenticate_successfully(): void
    {
        $user = User::create([
            'name' => 'Phạm Văn C',
            'email' => 'phamvanc@gmail.com',
            'password' => bcrypt('MatKhau@123'),
        ]);

        $request = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => 'phamvanc@gmail.com',
            'password' => 'MatKhau@123',
            'remember' => true,
        ]);
        $request->setLaravelSession(session()->driver());

        $action = new AuthenticateUserAction;
        $authenticatedUser = $action->execute($request);

        $this->assertSame($user->id, $authenticatedUser->id);
        $this->assertAuthenticatedAs($user);

        // Ghi nhật ký login_success
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => 'phamvanc@gmail.com',
            'event_type' => 'login_success',
        ]);
    }

    public function test_authentication_fails_with_wrong_password_and_logs_failure(): void
    {
        $user = User::create([
            'name' => 'Hoàng Văn D',
            'email' => 'hoangvand@gmail.com',
            'password' => bcrypt('DungMatKhau@123'),
        ]);

        $request = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => 'hoangvand@gmail.com',
            'password' => 'SaiMatKhau@999',
        ]);
        $request->setLaravelSession(session()->driver());

        $action = new AuthenticateUserAction;

        try {
            $action->execute($request);
            $this->fail('Đáng lẽ phải ném ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('Thông tin đăng nhập không chính xác.', $e->errors()['email'][0]);
        }

        $this->assertGuest();

        // Ghi nhật ký login_failed gắn với user_id
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => 'hoangvand@gmail.com',
            'event_type' => 'login_failed',
        ]);
    }

    public function test_authentication_fails_when_user_does_not_exist_and_logs_failure(): void
    {
        $request = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => 'nonexistent@gmail.com',
            'password' => 'BatKy@123',
        ]);
        $request->setLaravelSession(session()->driver());

        $action = new AuthenticateUserAction;

        try {
            $action->execute($request);
            $this->fail('Đáng lẽ phải ném ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('Thông tin đăng nhập không chính xác.', $e->errors()['email'][0]);
        }

        $this->assertGuest();

        // Ghi nhật ký login_failed với user_id = null
        $this->assertDatabaseHas('login_events', [
            'user_id' => null,
            'email' => 'nonexistent@gmail.com',
            'event_type' => 'login_failed',
        ]);
    }

    public function test_suspended_user_cannot_authenticate_even_with_correct_password(): void
    {
        $user = User::create([
            'name' => 'Vũ Thị E',
            'email' => 'vuthie@gmail.com',
            'password' => bcrypt('ChinhXac@123'),
        ]);

        // Đổi trạng thái tài khoản sang suspended
        $user->account_status = AccountStatus::Suspended;
        $user->save();

        $request = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => 'vuthie@gmail.com',
            'password' => 'ChinhXac@123',
        ]);
        $request->setLaravelSession(session()->driver());

        $action = new AuthenticateUserAction;

        try {
            $action->execute($request);
            $this->fail('Đáng lẽ phải từ chối tài khoản bị suspended.');
        } catch (ValidationException $e) {
            $this->assertSame('Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.', $e->errors()['email'][0]);
        }

        // Tuyệt đối không tạo session
        $this->assertGuest();

        // Ghi nhật ký account_locked
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => 'vuthie@gmail.com',
            'event_type' => LoginEventType::AccountLocked->value,
        ]);
    }

    public function test_rate_limiting_locks_out_after_max_attempts(): void
    {
        $email = 'bruteforce@gmail.com';
        $request = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => $email,
            'password' => 'SaiMatKhau',
        ]);
        $request->setLaravelSession(session()->driver());

        $action = new AuthenticateUserAction;

        // 5 lần thử sai liên tiếp
        for ($i = 1; $i <= 5; $i++) {
            try {
                $action->execute($request);
            } catch (ValidationException $e) {
                // Mong đợi thất bại bình thường
            }
        }

        // Lần thứ 6 phải bị RateLimiter chặn với thông báo tiếng Việt
        try {
            $action->execute($request);
            $this->fail('Lần thứ 6 đáng lẽ phải bị chặn bởi throttle.');
        } catch (ValidationException $e) {
            $error = $e->errors()['email'][0];
            $this->assertStringContainsString('Quá nhiều lần đăng nhập không thành công', $error);
            $this->assertStringContainsString('giây', $error);
        }

        RateLimiter::clear($request->throttleKey());
        RateLimiter::clear($request->ipThrottleKey());
    }

    public function test_logout_action_clears_session_and_records_event(): void
    {
        $user = User::create([
            'name' => 'Ngô Văn F',
            'email' => 'ngovanf@gmail.com',
            'password' => bcrypt('MatKhau@123'),
        ]);

        Auth::login($user);
        $this->assertAuthenticatedAs($user);

        $request = Request::create('/dang-xuat', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(session()->driver());

        $action = new LogoutUserAction;
        $action->execute($request);

        $this->assertGuest();

        // Ghi nhật ký logout
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => 'ngovanf@gmail.com',
            'event_type' => 'logout',
        ]);
    }

    public function test_login_request_normalizes_whitespace_and_uppercase_email_for_validation_and_throttle_key(): void
    {
        $user = User::create([
            'name' => 'Chu Văn An',
            'email' => 'chuvanan@example.com',
            'password' => bcrypt('MatKhau@123'),
        ]);

        // 1. Kiểm tra qua HTTP POST /dang-nhap (kích hoạt đầy đủ prepareForValidation + rules)
        $response = $this->post('/dang-nhap', [
            'email' => '   CHUVANAN@EXAMPLE.COM   ',
            'password' => 'MatKhau@123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        // 2. Kiểm tra khóa throttleKey đồng nhất giữa email chưa chuẩn hóa và đã chuẩn hóa
        $rawRequest = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => '  CHUVANAN@EXAMPLE.COM  ',
            'password' => 'MatKhau@123',
        ]);
        $cleanRequest = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => 'chuvanan@example.com',
            'password' => 'MatKhau@123',
        ]);

        $this->assertSame($cleanRequest->throttleKey(), $rawRequest->throttleKey());
    }

    public function test_nonexistent_account_executes_dummy_hash_check_and_rejects_even_matching_dummy_secret(): void
    {
        $checkedHashes = [];
        Hash::partialMock()
            ->shouldReceive('check')
            ->once()
            ->withArgs(function (string $value, string $hashedValue) use (&$checkedHashes): bool {
                $checkedHashes[] = [$value, $hashedValue];

                return true; // Giả lập ngay cả khi mật khẩu trùng với chuỗi băm giả
            })
            ->andReturn(true);

        $request = LoginRequest::create('/dang-nhap', 'POST', [
            'email' => 'khongtontai@example.com',
            'password' => 'password',
        ]);
        $request->setLaravelSession(session()->driver());

        $action = new AuthenticateUserAction;

        try {
            $action->execute($request);
            $this->fail('Đáng lẽ phải từ chối đăng nhập khi tài khoản không tồn tại.');
        } catch (ValidationException $e) {
            $this->assertSame('Thông tin đăng nhập không chính xác.', $e->errors()['email'][0]);
        }

        // Xác nhận Hash::check thực sự được gọi với chuỗi băm Bcrypt hợp lệ ($2y$...)
        $this->assertCount(1, $checkedHashes);
        $this->assertSame('password', $checkedHashes[0][0]);
        $this->assertStringStartsWith('$2y$', $checkedHashes[0][1]);
        $this->assertGuest();
    }
}
