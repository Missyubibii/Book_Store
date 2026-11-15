<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\RegisterCustomerAction;
use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Enums\StaffAccessStatus;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RegisterBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_be_registered_with_active_account_and_not_applicable_staff_status(): void
    {
        Event::fake([Registered::class]);

        $action = new RegisterCustomerAction;

        $user = $action->execute([
            'name' => 'Lê Thị Thu',
            'email' => 'lethithu@gmail.com',
            'password' => 'MatKhau@123',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Lê Thị Thu', $user->name);
        $this->assertSame('lethithu@gmail.com', $user->email);
        $this->assertTrue(Hash::check('MatKhau@123', $user->password));

        // Kiểm tra an ninh: Khách hàng mới bắt buộc có AccountStatus::Active và StaffAccessStatus::NotApplicable
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->hasStaffAccess());

        // Kiểm tra event Registered đã được kích hoạt
        Event::assertDispatched(Registered::class, function ($event) use ($user) {
            return $event->user->id === $user->id;
        });

        // Kiểm tra phiên đăng nhập đã được thiết lập
        $this->assertAuthenticatedAs($user);

        // Kiểm tra nhật ký login_events được ghi nhận bằng LoginEventType chuẩn hóa
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => 'lethithu@gmail.com',
            'event_type' => LoginEventType::RegisterSuccess->value,
        ]);
    }

    public function test_register_validation_rules_require_strong_password_and_unique_email(): void
    {
        User::create([
            'name' => 'Nguyễn A',
            'email' => 'duplicate@gmail.com',
            'password' => bcrypt('MatKhau@123'),
        ]);

        $request = new RegisterRequest;

        // 1. Kiểm tra email trùng lặp
        $validator = Validator::make([
            'name' => 'Người Dùng Mới',
            'email' => 'duplicate@gmail.com',
            'password' => 'MatKhau@123',
            'password_confirmation' => 'MatKhau@123',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertSame('Trường địa chỉ email đã tồn tại trên hệ thống.', $validator->errors()->first('email'));

        // 2. Kiểm tra mật khẩu yếu (thiếu ký tự đặc biệt, thiếu số...)
        $weakValidator = Validator::make([
            'name' => 'Người Dùng Mới',
            'email' => 'newuser@gmail.com',
            'password' => 'simplepass',
            'password_confirmation' => 'simplepass',
        ], $request->rules());

        $this->assertTrue($weakValidator->fails());
        $this->assertNotEmpty($weakValidator->errors()->get('password'));
    }

    public function test_registered_event_runs_outside_open_write_transaction_and_handles_mail_failure_safely(): void
    {
        $baselineTransactionLevel = DB::transactionLevel();
        $observedTransactionLevel = null;

        Event::listen(Registered::class, function () use (&$observedTransactionLevel) {
            $observedTransactionLevel = DB::transactionLevel();
            throw new \RuntimeException('Giả lập lỗi kết nối SMTP đồng bộ');
        });

        $request = Request::create('/dang-ky', 'POST');
        $request->setLaravelSession(session()->driver());
        $initialSessionId = $request->session()->getId();

        $action = new RegisterCustomerAction;
        $user = $action->execute([
            'name' => '  Đặng Trần Côn  ',
            'email' => '  DANGTRANCON@EXAMPLE.COM  ',
            'password' => 'MatKhau@123',
        ], $request);

        // Sự kiện Registered phải được phát ở cùng mức transaction nền (không nằm trong DB::transaction nội bộ của Action)
        $this->assertSame($baselineTransactionLevel, $observedTransactionLevel);

        // Dù dịch vụ gửi email ném ngoại lệ, dữ liệu User, trạng thái đăng nhập, session mới và audit log vẫn nhất quán
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'dangtrancon@example.com',
            'account_status' => AccountStatus::Active->value,
            'staff_access_status' => StaffAccessStatus::NotApplicable->value,
        ]);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($initialSessionId, $request->session()->getId());
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => 'dangtrancon@example.com',
            'event_type' => LoginEventType::RegisterSuccess->value,
        ]);
    }
}
