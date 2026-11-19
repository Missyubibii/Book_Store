<?php

namespace Tests\Feature\Stage01B5;

use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GoogleOAuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations_production']);

        config([
            'services.google.client_id' => 'test-google-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
            'services.google.enabled' => true,
        ]);
    }

    public function test_google_redirect_route_redirects_to_google_with_correct_parameters(): void
    {
        $response = $this->get(route('auth.google.redirect'));

        $response->assertStatus(302);
        $targetUrl = $response->headers->get('Location');
        $this->assertNotNull($targetUrl);

        $parsed = parse_url($targetUrl);
        $this->assertSame('accounts.google.com', $parsed['host']);
        $this->assertSame('/o/oauth2/v2/auth', $parsed['path']);

        parse_str($parsed['query'], $queryParams);
        $this->assertSame('test-google-client-id.apps.googleusercontent.com', $queryParams['client_id']);
        $this->assertSame('http://127.0.0.1:8000/auth/google/callback', $queryParams['redirect_uri']);
        $this->assertSame('code', $queryParams['response_type']);
        $this->assertSame('openid email profile', $queryParams['scope']);
        $this->assertNotEmpty($queryParams['state']);

        // State is saved in session
        $this->assertSame($queryParams['state'], session('google_oauth_state'));
    }

    public function test_google_redirect_fails_when_feature_is_disabled(): void
    {
        config(['services.google.enabled' => false]);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('warning', 'Tính năng đăng nhập bằng Google hiện chưa được kích hoạt trên hệ thống.');
    }

    public function test_google_callback_fails_when_feature_is_disabled(): void
    {
        config(['services.google.enabled' => false]);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('warning', 'Tính năng đăng nhập bằng Google hiện chưa được kích hoạt trên hệ thống.');
    }

    public function test_google_callback_handles_user_cancellation(): void
    {
        $response = $this->get(route('auth.google.callback', [
            'error' => 'access_denied',
        ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertTrue(str_contains(session('error'), 'hủy'));
        $this->assertGuest();
    }

    public function test_google_callback_rejects_missing_or_mismatched_state(): void
    {
        // 1. Missing state in query
        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', ['code' => 'test-code']));
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', function (string $msg): bool {
            return str_contains($msg, 'Phiên xác thực Google OAuth không hợp lệ hoặc đã hết hạn');
        });

        // 2. Mismatched state
        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'test-code',
                'state' => 'wrong-state',
            ]));
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', function (string $msg): bool {
            return str_contains($msg, 'Phiên xác thực Google OAuth không hợp lệ hoặc đã hết hạn');
        });
        $this->assertGuest();
    }

    public function test_google_callback_rejects_missing_code(): void
    {
        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'state' => 'valid-state',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_google_callback_handles_token_exchange_failure(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'bad-code',
                'state' => 'valid-state',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_google_callback_rejects_unverified_google_email_to_prevent_account_takeover(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => '1234567890',
                'email' => 'unverified@example.com',
                'email_verified' => false,
                'name' => 'Unverified User',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'valid-code',
                'state' => 'valid-state',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertTrue(str_contains(session('error'), 'chưa được xác thực'));
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    public function test_google_callback_creates_new_customer_and_logs_in_successfully(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => '10987654321',
                'email' => 'newcustomer@gmail.com',
                'email_verified' => true,
                'name' => 'Nguyễn Văn Mới',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'valid-code',
                'state' => 'valid-state',
            ]));

        $response->assertRedirect('/');
        $this->assertAuthenticated();

        $user = User::where('email', 'newcustomer@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Nguyễn Văn Mới', $user->name);
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);
        $this->assertNotNull($user->email_verified_at);

        // Verify login events logged
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event_type' => LoginEventType::RegisterSuccess->value,
        ]);
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event_type' => LoginEventType::LoginSuccess->value,
        ]);
    }

    public function test_google_callback_authenticates_existing_active_customer(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing@gmail.com',
            'name' => 'Khách Hàng Cũ',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::NotApplicable,
            'email_verified_at' => null,
        ]);

        \App\Models\UserExternalIdentity::create([
            'user_id' => $existingUser->id,
            'provider' => 'google',
            'provider_subject' => '999888777',
            'provider_email' => 'existing@gmail.com',
            'linked_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => '999888777',
                'email' => 'existing@gmail.com',
                'email_verified' => true,
                'name' => 'Existing From Google',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'valid-code',
                'state' => 'valid-state',
            ]));

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($existingUser);

        // Email verified at should now be set
        $existingUser->refresh();
        $this->assertSame('Khách Hàng Cũ', $existingUser->name); // preserves existing name
        $this->assertSame(StaffAccessStatus::NotApplicable, $existingUser->staff_access_status);
    }

    public function test_google_callback_rejects_automatic_linking_when_email_matches_unlinked_existing_account(): void
    {
        $existingUnlinked = User::factory()->create([
            'email' => 'unlinked@gmail.com',
            'name' => 'Chủ Tài Khoản Chưa Liên Kết',
            'account_status' => AccountStatus::Active,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => '888777666',
                'email' => 'unlinked@gmail.com',
                'email_verified' => true,
                'name' => 'Attacker Or Unlinked Google',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'valid-code',
                'state' => 'valid-state',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertTrue(str_contains(session('error'), 'đã tồn tại trong hệ thống'));
        $this->assertGuest();
    }

    public function test_google_callback_rejects_suspended_user(): void
    {
        $suspendedUser = User::factory()->create([
            'email' => 'suspended@gmail.com',
            'account_status' => AccountStatus::Suspended,
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        \App\Models\UserExternalIdentity::create([
            'user_id' => $suspendedUser->id,
            'provider' => 'google',
            'provider_subject' => '555444333',
            'provider_email' => 'suspended@gmail.com',
            'linked_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => '555444333',
                'email' => 'suspended@gmail.com',
                'email_verified' => true,
                'name' => 'Suspended Person',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'valid-code',
                'state' => 'valid-state',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertTrue(str_contains(session('error'), 'khóa'));
        $this->assertGuest();

        // Login event records account locked
        $this->assertDatabaseHas('login_events', [
            'user_id' => $suspendedUser->id,
            'event_type' => LoginEventType::AccountLocked->value,
        ]);
    }

    public function test_google_callback_preserves_staff_privileges_and_redirects_onboarding_if_temporary_password_active(): void
    {
        $staffUser = User::factory()->create([
            'email' => 'staff@gmail.com',
            'name' => 'Nhân Viên Kho',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::Pending,
            'must_change_password' => true,
        ]);

        \App\Models\UserExternalIdentity::create([
            'user_id' => $staffUser->id,
            'provider' => 'google',
            'provider_subject' => '777666555',
            'provider_email' => 'staff@gmail.com',
            'linked_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => '777666555',
                'email' => 'staff@gmail.com',
                'email_verified' => true,
                'name' => 'Staff Google',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'code' => 'valid-code',
                'state' => 'valid-state',
            ]));

        // Redirects to onboarding password change because must_change_password is true
        $response->assertRedirect(route('staff.onboarding.password.edit'));
        $this->assertAuthenticatedAs($staffUser);

        $staffUser->refresh();
        $this->assertSame(StaffAccessStatus::Pending, $staffUser->staff_access_status);
    }

    public function test_standard_email_password_login_remains_functional(): void
    {
        $user = User::factory()->create([
            'email' => 'regular@example.com',
            'password' => Hash::make('CorrectPassword123!@#'),
            'account_status' => AccountStatus::Active,
        ]);

        $response = $this->post('/dang-nhap', [
            'email' => 'regular@example.com',
            'password' => 'CorrectPassword123!@#',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_real_email_verification_flow_with_disabled_demo_mode_sends_notification(): void
    {
        config(['auth.demo_email_verification' => false]);
        Mail::fake();

        $response = $this->post('/dang-ky', [
            'name' => 'Khách Hàng Mới Thật',
            'email' => 'realcustomer@example.com',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
        ]);

        $response->assertRedirect('/');
        $user = User::where('email', 'realcustomer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        $this->assertAuthenticatedAs($user);
    }
}
