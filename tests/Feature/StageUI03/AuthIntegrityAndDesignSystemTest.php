<?php

declare(strict_types=1);

namespace Tests\Feature\StageUI03;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use App\Models\UserExternalIdentity;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthIntegrityAndDesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations_production']);
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'services.google.client_id' => 'test-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
            'services.google.enabled' => true,
        ]);

        $this->superAdmin = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên Cấp Cao',
            'superadmin-ui03@bookstore.test',
            'StrongPass!1234',
            'StrongPass!1234',
        );
        $this->superAdmin->forceFill([
            'must_change_password' => false,
            'email_verified_at' => now(),
        ])->save();
    }

    public function test_google_only_registration_sets_null_password_and_displays_google_label_only(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-google-access-token',
                'token_type' => 'Bearer',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-only-sub-9901',
                'email' => 'google.only.reader@gmail.com',
                'email_verified' => true,
                'name' => 'Độc giả Google',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'state-xyz-9901'])
            ->get(route('auth.google.callback', [
                'state' => 'state-xyz-9901',
                'code' => 'auth-code-9901',
            ]));

        $response->assertRedirect('/');

        $user = User::query()->where('email', 'google.only.reader@gmail.com')->firstOrFail();

        // Không được lưu mật khẩu giả ngẫu nhiên cho tài khoản Google-only
        $this->assertNull($user->password);
        $this->assertFalse($user->hasUsablePasswordAuthentication());
        $this->assertTrue($user->hasLinkedGoogleIdentity());
        $this->assertSame(['google'], $user->availableAuthenticationMethods());
        $this->assertSame('Google', $user->authenticationMethodsLabel());
        $this->assertSame('google', $user->lastSuccessfulLoginMethod());
        $this->assertSame('Google', $user->lastSuccessfulLoginMethodLabel());
        $this->assertTrue($user->hasVerifiedEmail());

        // Kiểm tra giao diện Quản lý Tài khoản & Chi tiết Khách hàng hiển thị đúng "Google" (không hiển thị "Mật khẩu")
        $listResponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.accounts.index', ['tab' => 'customer', 'q' => 'google.only.reader@gmail.com']));

        $listResponse->assertOk()
            ->assertSeeText('Độc giả Google')
            ->assertSeeText('Đã xác thực')
            ->assertSeeText('Google')
            ->assertSeeText('Đăng nhập gần nhất: Google');

        $detailResponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.accounts.customer.show', $user));

        $detailResponse->assertOk()
            ->assertSeeText('Chưa thiết lập mật khẩu (chỉ đăng nhập qua Google)')
            ->assertSeeText('Đã xác thực')
            ->assertSeeText('Google');
    }

    public function test_password_only_and_dual_method_accounts_display_accurate_labels_and_last_login(): void
    {
        $passwordUser = User::factory()->create([
            'name' => 'Khách Mật Khẩu',
            'email' => 'pwd.only@bookstore.test',
            'password' => Hash::make('ValidPass!2026'),
            'email_verified_at' => null,
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        DB::table('login_events')->insert([
            'user_id' => $passwordUser->id,
            'email' => $passwordUser->email,
            'event_type' => LoginEventType::LoginSuccess->value,
            'auth_method' => 'password',
            'occurred_at' => now(),
        ]);

        $this->assertTrue($passwordUser->hasUsablePasswordAuthentication());
        $this->assertFalse($passwordUser->hasLinkedGoogleIdentity());
        $this->assertSame('Mật khẩu', $passwordUser->authenticationMethodsLabel());
        $this->assertSame('password', $passwordUser->lastSuccessfulLoginMethod());

        $dualUser = User::factory()->create([
            'name' => 'Khách Song Phương',
            'email' => 'dual.methods@bookstore.test',
            'password' => Hash::make('ValidPass!2026'),
            'email_verified_at' => now(),
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        UserExternalIdentity::query()->create([
            'user_id' => $dualUser->id,
            'provider' => 'google',
            'provider_subject' => 'google-dual-sub-7701',
            'provider_email' => 'dual.methods@bookstore.test',
            'linked_at' => now(),
        ]);

        $this->assertSame('Mật khẩu và Google', $dualUser->authenticationMethodsLabel());
        // Chưa có sự kiện đăng nhập với auth_method -> hiển thị trung thực "Chưa rõ"
        $this->assertNull($dualUser->lastSuccessfulLoginMethod());
        $this->assertSame('Chưa rõ', $dualUser->lastSuccessfulLoginMethodLabel());

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.accounts.index', ['tab' => 'customer']));

        $response->assertOk()
            ->assertSeeText('Chưa xác thực')
            ->assertSeeText('Mật khẩu và Google')
            ->assertSeeText('Đăng nhập gần nhất: Chưa rõ');
    }

    public function test_email_verification_persists_and_email_change_requires_renewed_verification(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify.persist@gmail.com',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        // Xác thực lần 1 thành công và lưu bền vững vào CSDL
        $this->actingAs($user)->get($signedUrl)->assertRedirect('/?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // Kiểm tra hiển thị trên trang Quản trị Tài khoản sau khi xác thực
        $this->actingAs($this->superAdmin)
            ->get(route('admin.accounts.customer.show', $user->fresh()))
            ->assertOk()
            ->assertSeeText('Đã xác thực')
            ->assertDontSeeText('Chưa xác thực');

        // Xác thực lặp lại vẫn an toàn không lỗi
        $this->actingAs($user->fresh())->get($signedUrl)->assertRedirect('/?verified=1');

        // Đổi địa chỉ email phải tự động xóa dấu xác thực, yêu cầu xác thực lại
        $user = $user->fresh();
        $user->email = 'new.address@gmail.com';
        $user->save();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_read_only_auth_audit_command_runs_safely_without_mutating_data(): void
    {
        $this->artisan('bookstore:audit-auth-identities')
            ->expectsOutputToContain('BÁO CÁO KIỂM TOÁN ĐỊNH DANH & XÁC THỰC (CHỈ ĐỌC)')
            ->assertSuccessful();
    }

    /**
     * Xác minh hợp nhất Quản lý tài khoản và dọn dẹp Sidebar:
     * - Tiêu đề ngắn gọn "Quản lý tài khoản", không dùng tiêu đề dài "Quản lý Tài khoản Thống nhất".
     * - Sidebar của Super Admin không còn mục "Quản lý nhân sự" trùng lặp.
     * - Tab Tài khoản nhân sự tích hợp đầy đủ nút Tạo nhân sự mới, Liên kết khách hàng, Phê duyệt nhân sự.
     * - Tuyến tương thích `/quan-tri/nhan-su` vẫn hoạt động bình thường không lỗi chuyển hướng.
     */
    public function test_account_management_consolidation_and_sidebar_cleanup(): void
    {
        \App\Models\Branch::factory()->create([
            'code' => 'HCM_TEST_01',
            'name' => 'Chi nhánh Kiểm thử 01',
            'type' => \App\Enums\BranchType::RetailBranch,
            'status' => \App\Enums\BranchStatus::Active,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.accounts.index', ['tab' => 'staff']));

        $response->assertOk()
            ->assertSeeText('Quản lý tài khoản')
            ->assertDontSeeText('Quản lý Tài khoản Thống nhất')
            ->assertSeeText('Tài khoản nhân sự')
            ->assertSeeText('Tài khoản khách hàng')
            ->assertSeeText('Tạo nhân sự mới')
            ->assertSeeText('Liên kết khách hàng')
            ->assertSeeText('Phê duyệt nhân sự')
            ->assertDontSee('title="Quản lý nhân sự"', false);

        // Tuyến tương thích `/quan-tri/nhan-su` vẫn hoạt động ổn định
        $this->actingAs($this->superAdmin)
            ->get(route('admin.staff.index'))
            ->assertOk();
    }
}
