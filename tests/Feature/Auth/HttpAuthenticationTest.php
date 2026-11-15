<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HttpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/dang-ky');
        $response->assertStatus(200);
    }

    public function test_new_users_can_register_via_http(): void
    {
        Event::fake([Registered::class]);

        $response = $this->post('/dang-ky', [
            'name' => 'Nguyễn Thị Hoa',
            'email' => 'hoanguyen@example.com',
            'password' => 'MatKhau@123',
            'password_confirmation' => 'MatKhau@123',
            'account_status' => 'suspended',
            'staff_access_status' => 'active',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');

        $user = User::where('email', 'hoanguyen@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);
        $this->assertFalse($user->hasStaffAccess());

        Event::assertDispatched(Registered::class);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/dang-nhap');
        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_via_http(): void
    {
        $user = User::create([
            'name' => 'Trần Văn Nam',
            'email' => 'namtran@example.com',
            'password' => Hash::make('ChuanMatKhau@123'),
        ]);

        $response = $this->post('/dang-nhap', [
            'email' => 'namtran@example.com',
            'password' => 'ChuanMatKhau@123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        User::create([
            'name' => 'Lý Hùng',
            'email' => 'lyhung@example.com',
            'password' => Hash::make('ChuanMatKhau@123'),
        ]);

        $response = $this->post('/dang-nhap', [
            'email' => 'lyhung@example.com',
            'password' => 'SaiMatKhau@999',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email' => 'Thông tin đăng nhập không chính xác.']);
    }

    public function test_suspended_user_cannot_authenticate_via_http(): void
    {
        $user = User::create([
            'name' => 'Phan Thị Khóa',
            'email' => 'khoaphan@example.com',
            'password' => Hash::make('ChuanMatKhau@123'),
        ]);

        $user->account_status = AccountStatus::Suspended;
        $user->save();

        $response = $this->post('/dang-nhap', [
            'email' => 'khoaphan@example.com',
            'password' => 'ChuanMatKhau@123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.']);
    }

    public function test_users_can_logout_via_http(): void
    {
        $user = User::create([
            'name' => 'Đặng Văn Lâm',
            'email' => 'lamdang@example.com',
            'password' => Hash::make('MatKhau@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        $response = $this->post('/dang-xuat');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_email_verification_notice_screen_can_be_rendered(): void
    {
        $user = User::create([
            'name' => 'Võ Văn Kiệt',
            'email' => 'kietvo@example.com',
            'password' => Hash::make('MatKhau@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        $response = $this->actingAs($user)->get('/xac-thuc-email');
        $response->assertStatus(200);
    }

    public function test_email_can_be_verified_using_signed_url(): void
    {
        Event::fake([Verified::class]);

        $user = User::create([
            'name' => 'Dương Quá',
            'email' => 'duongqua@example.com',
            'password' => Hash::make('MatKhau@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect('/?verified=1');
    }

    public function test_email_verification_fails_with_invalid_hash(): void
    {
        $user = User::create([
            'name' => 'Tiểu Long Nữ',
            'email' => 'longnu@example.com',
            'password' => Hash::make('MatKhau@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        $invalidVerificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email@example.com')]
        );

        $response = $this->actingAs($user)->get($invalidVerificationUrl);
        $response->assertStatus(403);
        $response->assertSeeText('Truy cập bị từ chối');
        $response->assertSeeText('Bạn không có quyền thực hiện thao tác này hoặc liên kết xác thực không hợp lệ.');
        $response->assertDontSeeText('Forbidden');
        $response->assertDontSeeText('This action is unauthorized.');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_fails_with_tampered_signature_and_renders_vietnamese_403(): void
    {
        $user = User::create([
            'name' => 'Hoàng Dược Sư',
            'email' => 'hoangduocsu@example.com',
            'password' => Hash::make('MatKhau@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        $tamperedUrl = route('verification.verify', [
            'id' => $user->id,
            'hash' => sha1($user->email),
            'expires' => now()->addMinutes(60)->timestamp,
            'signature' => 'invalid-signature-value',
        ]);

        $response = $this->actingAs($user)->get($tamperedUrl);
        $response->assertStatus(403);
        $response->assertSeeText('Truy cập bị từ chối');
        $response->assertSeeText('Liên kết xác thực không hợp lệ hoặc đã hết hạn.');
        $response->assertDontSeeText('Forbidden');
        $response->assertDontSeeText('Invalid signature.');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_resend_works_and_is_throttled(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Quách Tĩnh',
            'email' => 'tinhtran@example.com',
            'password' => Hash::make('MatKhau@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        $response = $this->actingAs($user)->post('/xac-thuc-email/gui-lai');
        $response->assertSessionHas('status', 'verification-link-sent');

        // Gửi liên tiếp 6 lần để kích hoạt throttle middleware (throttle:6,1)
        for ($i = 0; $i < 6; $i++) {
            $lastResponse = $this->actingAs($user)->post('/xac-thuc-email/gui-lai');
        }

        $lastResponse->assertStatus(429);
        $lastResponse->assertSeeText('Quá nhiều yêu cầu');
        $lastResponse->assertSeeText('Bạn đã gửi quá nhiều yêu cầu liên tiếp trong thời gian ngắn.');
        $lastResponse->assertDontSeeText('Too Many Requests');
        $lastResponse->assertDontSeeText('Too Many Attempts.');
    }
}
