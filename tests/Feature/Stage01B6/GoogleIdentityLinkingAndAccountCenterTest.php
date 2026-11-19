<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B6;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\LoginEventType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GoogleIdentityLinkingAndAccountCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations_production']);

        config([
            'services.google.client_id' => 'test-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
            'services.google.enabled' => true,
        ]);
    }

    private function createSuperAdmin(): User
    {
        $superAdmin = User::factory()->create([
            'email' => 'superadmin@bookstore.vn',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::Active,
        ]);

        $role = Role::query()->firstOrCreate(
            ['code' => 'super_admin'],
            [
                'name' => 'Super Administrator',
                'scope' => RoleScope::Global,
                'is_system' => true,
            ]
        );

        UserRole::query()->create([
            'user_id' => $superAdmin->id,
            'role_id' => $role->id,
            'role_scope' => RoleScope::Global,
            'assigned_by' => $superAdmin->id,
            'assigned_at' => now(),
        ]);

        return $superAdmin;
    }

    private function createBranchAdmin(Branch $branch): User
    {
        $admin = User::factory()->create([
            'email' => 'branchadmin@bookstore.vn',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::Active,
        ]);

        BranchAssignment::query()->create([
            'user_id' => $admin->id,
            'branch_id' => $branch->id,
            'status' => 'active',
            'is_primary' => true,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
        ]);

        return $admin;
    }

    public function test_user_external_identity_enforces_provider_and_subject_uniqueness(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserExternalIdentity::create([
            'user_id' => $user1->id,
            'provider' => 'google',
            'provider_subject' => 'google-sub-unique-1001',
            'provider_email' => 'user1@gmail.com',
            'linked_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Chèn trùng provider_subject của cùng provider phải ném QueryException
        UserExternalIdentity::create([
            'user_id' => $user2->id,
            'provider' => 'google',
            'provider_subject' => 'google-sub-unique-1001',
            'provider_email' => 'user2@gmail.com',
            'linked_at' => now(),
        ]);
    }

    public function test_authenticated_user_can_view_security_settings_and_initiate_deliberate_google_linking(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Secret123!@#')]);

        $response = $this->actingAs($user)->get(route('account.security'));
        $response->assertStatus(200);
        $response->assertSee('Bảo mật &amp; Phương thức Đăng nhập', false);
        $response->assertSee('Chưa liên kết tài khoản Google');

        $linkResponse = $this->actingAs($user)->post(route('account.google.link'));
        $linkResponse->assertStatus(302);

        $this->assertSame('link', session('google_oauth_intent'));
        $this->assertSame($user->id, session('google_oauth_user_id'));
    }

    public function test_deliberate_linking_callback_links_google_identity_to_authenticated_account(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Secret123!@#')]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-deliberate-777',
                'email' => 'deliberate@gmail.com',
                'email_verified' => true,
                'name' => 'Deliberate Linker',
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'google_oauth_state' => 'test-state',
                'google_oauth_intent' => 'link',
                'google_oauth_user_id' => $user->id,
            ])
            ->get(route('auth.google.callback', [
                'code' => 'test-code',
                'state' => 'test-state',
            ]));

        $response->assertRedirect(route('account.security'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('user_external_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'google-sub-deliberate-777',
            'provider_email' => 'deliberate@gmail.com',
        ]);
    }

    public function test_deliberate_linking_rejects_google_identity_already_linked_to_another_user(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserExternalIdentity::create([
            'user_id' => $user1->id,
            'provider' => 'google',
            'provider_subject' => 'already-taken-sub',
            'provider_email' => 'user1@gmail.com',
            'linked_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'already-taken-sub',
                'email' => 'user1@gmail.com',
                'email_verified' => true,
                'name' => 'Existing User',
            ], 200),
        ]);

        $response = $this->actingAs($user2)
            ->withSession([
                'google_oauth_state' => 'test-state',
                'google_oauth_intent' => 'link',
                'google_oauth_user_id' => $user2->id,
            ])
            ->get(route('auth.google.callback', [
                'code' => 'test-code',
                'state' => 'test-state',
            ]));

        $response->assertRedirect(route('account.security'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('user_external_identities', 1);
    }

    public function test_user_with_password_can_safely_unlink_google_identity(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!@#')]);

        UserExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'to-be-unlinked',
            'provider_email' => 'unlinkme@gmail.com',
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('account.google.unlink'), [
            'confirmed' => '1',
        ]);

        $response->assertRedirect(route('account.security'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('user_external_identities', ['provider_subject' => 'to-be-unlinked']);
    }

    public function test_user_without_password_cannot_unlink_their_sole_google_authentication_method(): void
    {
        // Tài khoản tạo qua Google không có mật khẩu
        $user = User::factory()->create(['password' => '']);

        UserExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'sole-method-sub',
            'provider_email' => 'sole@gmail.com',
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('account.google.unlink'), [
            'confirmed' => '1',
        ]);

        $response->assertRedirect(route('account.security'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('user_external_identities', ['provider_subject' => 'sole-method-sub']);
    }

    public function test_super_admin_can_generate_corporate_google_onboarding_signed_link_for_staff(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $staff = User::factory()->create([
            'staff_access_status' => StaffAccessStatus::Pending,
        ]);

        $response = $this->actingAs($superAdmin)->post(route('admin.staff.google-invite', $staff));
        $response->assertRedirect(route('admin.staff.show', $staff));
        $response->assertSessionHas('generated_google_invite_url');

        $inviteUrl = session('generated_google_invite_url');
        $this->assertNotEmpty($inviteUrl);
        $this->assertStringContainsString('/nhan-su/onboarding/google/'.$staff->id, $inviteUrl);
    }

    public function test_staff_corporate_google_onboarding_flow_links_identity_without_auto_activating_privileges(): void
    {
        $staff = User::factory()->create([
            'email' => 'corpstaff@bookstore.vn',
            'staff_access_status' => StaffAccessStatus::Pending,
            'must_change_password' => true,
        ]);

        // Tạo signed URL hợp lệ
        $signedUrl = URL::temporarySignedRoute(
            'staff.onboarding.google.claim',
            now()->addHours(24),
            ['user' => $staff->id],
        );

        $claimResponse = $this->get($signedUrl);
        $claimResponse->assertStatus(302);
        $this->assertSame('staff_onboard', session('google_oauth_intent'));
        $this->assertSame($staff->id, session('staff_onboard_user_id'));

        // Giả lập callback thành công từ Google
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'corp-google-sub-9999',
                'email' => 'corpstaff@bookstore.vn',
                'email_verified' => true,
                'name' => 'Corporate Staff Member',
            ], 200),
        ]);

        $callbackResponse = $this->withSession([
            'google_oauth_state' => 'test-state',
            'google_oauth_intent' => 'staff_onboard',
            'staff_onboard_user_id' => $staff->id,
        ])->get(route('auth.google.callback', [
            'code' => 'test-code',
            'state' => 'test-state',
        ]));

        // Do staff có must_change_password nên chuyển hướng sang trang đổi mật khẩu bắt buộc
        $callbackResponse->assertRedirect(route('staff.onboarding.password.edit'));
        $this->assertAuthenticatedAs($staff);

        // Kiểm tra định danh ngoài được lưu chính xác
        $this->assertDatabaseHas('user_external_identities', [
            'user_id' => $staff->id,
            'provider' => 'google',
            'provider_subject' => 'corp-google-sub-9999',
            'provider_email' => 'corpstaff@bookstore.vn',
        ]);

        // QUAN TRỌNG: Trạng thái nhân sự KHÔNG tự động chuyển sang active (phải giữ nguyên pending)
        $staff->refresh();
        $this->assertSame(StaffAccessStatus::Pending, $staff->staff_access_status);
    }

    public function test_corporate_google_onboarding_rejects_expired_or_invalid_signature(): void
    {
        $staff = User::factory()->create();

        // Thử truy cập với URL không có chữ ký hợp lệ
        $response = $this->get(route('staff.onboarding.google.claim', ['user' => $staff->id]));
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
    }

    public function test_account_takeover_prevention_rejects_unlinked_google_login_when_email_matches_existing_user(): void
    {
        $existingStaff = User::factory()->create([
            'email' => 'manager@bookstore.vn',
            'staff_access_status' => StaffAccessStatus::Active,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'unknown-external-sub-666',
                'email' => 'manager@bookstore.vn',
                'email_verified' => true,
                'name' => 'Potential Attacker',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'test-state'])
            ->get(route('auth.google.callback', [
                'code' => 'test-code',
                'state' => 'test-state',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
        $this->assertDatabaseMissing('user_external_identities', ['provider_subject' => 'unknown-external-sub-666']);
    }

    public function test_new_customer_google_registration_creates_external_identity_with_customer_only_defaults(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'fresh-google-sub-12345',
                'email' => 'freshcustomer@gmail.com',
                'email_verified' => true,
                'name' => 'Khách Hàng Mới Tinh',
            ], 200),
        ]);

        $response = $this->withSession(['google_oauth_state' => 'test-state'])
            ->get(route('auth.google.callback', [
                'code' => 'test-code',
                'state' => 'test-state',
            ]));

        $response->assertRedirect('/');
        $this->assertAuthenticated();

        $user = User::where('email', 'freshcustomer@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);

        $this->assertDatabaseHas('user_external_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'fresh-google-sub-12345',
            'provider_email' => 'freshcustomer@gmail.com',
        ]);
    }

    public function test_only_super_admin_can_access_account_management_center(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $branch = Branch::factory()->create();
        $branchAdmin = $this->createBranchAdmin($branch);
        $customer = User::factory()->create();

        // 1. Guest -> 302 về trang login
        $this->get(route('admin.accounts.index'))->assertRedirect(route('login'));

        // 2. Customer -> 403 Forbidden
        $this->actingAs($customer)->get(route('admin.accounts.index'))->assertStatus(403);

        // 3. Branch Admin -> 403 Forbidden (chỉ được quản lý nhân sự thuộc chi nhánh mình)
        $this->actingAs($branchAdmin)->get(route('admin.accounts.index'))->assertStatus(403);

        // 4. Super Admin -> 200 OK
        $response = $this->actingAs($superAdmin)->get(route('admin.accounts.index'));
        $response->assertStatus(200);
        $response->assertSee('Quản lý tài khoản');
        $response->assertSee('Tài khoản nhân sự');
        $response->assertSee('Tài khoản khách hàng');
    }

    public function test_account_center_filters_tabs_search_and_pagination(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $staffUser = User::factory()->create([
            'name' => 'Nhân Viên Thủ Thư Đặc Biệt',
            'email' => 'thuthudacbiet@bookstore.vn',
            'staff_access_status' => StaffAccessStatus::Active,
        ]);
        StaffProfile::factory()->create([
            'user_id' => $staffUser->id,
            'staff_code' => 'NV-SPECIAL-99',
        ]);

        $customerUser = User::factory()->create([
            'name' => 'Khách Hàng Đọc Truyện',
            'email' => 'doctruyen@gmail.com',
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        // Tab Nhân sự hiển thị staffUser, không hiển thị customerUser
        $responseStaff = $this->actingAs($superAdmin)->get(route('admin.accounts.index', ['tab' => 'staff']));
        $responseStaff->assertStatus(200);
        $responseStaff->assertSee('Nhân Viên Thủ Thư Đặc Biệt');
        $responseStaff->assertSee('NV-SPECIAL-99');
        $responseStaff->assertDontSee('Khách Hàng Đọc Truyện');

        // Tab Khách hàng hiển thị customerUser, không hiển thị staffUser
        $responseCustomer = $this->actingAs($superAdmin)->get(route('admin.accounts.index', ['tab' => 'customer']));
        $responseCustomer->assertStatus(200);
        $responseCustomer->assertSee('Khách Hàng Đọc Truyện');
        $responseCustomer->assertDontSee('Nhân Viên Thủ Thư Đặc Biệt');

        // Tìm kiếm theo mã nhân sự
        $responseSearch = $this->actingAs($superAdmin)->get(route('admin.accounts.index', ['tab' => 'staff', 'q' => 'NV-SPECIAL-99']));
        $responseSearch->assertStatus(200);
        $responseSearch->assertSee('Nhân Viên Thủ Thư Đặc Biệt');
    }

    public function test_customer_detail_page_renders_profile_logins_and_honest_empty_states(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $customer = User::factory()->create([
            'name' => 'Độc Giả Thân Thiết',
            'email' => 'docgia@example.com',
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        UserExternalIdentity::create([
            'user_id' => $customer->id,
            'provider' => 'google',
            'provider_subject' => '109876543210987654321',
            'provider_email' => 'docgia@gmail.com',
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($superAdmin)->get(route('admin.accounts.customer.show', $customer));
        $response->assertStatus(200);
        $response->assertSee('Độc Giả Thân Thiết');
        $response->assertSee('docgia@example.com');
        $response->assertSee('docgia@gmail.com');
        $response->assertSee('1098...4321'); // Che giấu Subject ID
        $response->assertSee('Phân hệ Đơn hàng đang được phát triển');
        $response->assertSee('Phân hệ Giao dịch &amp; Thanh toán đang được phát triển', false);
        $response->assertSee('Phân hệ Bản quyền Sách điện tử (eBook) đang được phát triển', false);

        // Không làm lộ mật khẩu hay token bảo mật
        $response->assertDontSee($customer->password);
    }

    public function test_super_admin_can_suspend_and_reactivate_customer_with_audit_log(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $customer = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        // 1. Khóa tài khoản
        $suspendResponse = $this->actingAs($superAdmin)->patch(
            route('admin.accounts.customer.status', $customer),
            [
                'action' => 'suspend',
                'confirmed' => '1',
                'reason' => 'Phát hiện hành vi gian lận',
            ]
        );

        $suspendResponse->assertRedirect(route('admin.accounts.customer.show', $customer));
        $suspendResponse->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame(AccountStatus::Suspended, $customer->account_status);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $superAdmin->id,
            'target_user_id' => $customer->id,
            'action' => AuditAction::AccountSuspended->value,
            'reason' => 'Phát hiện hành vi gian lận',
        ]);

        // 2. Mở khóa lại tài khoản
        $reactivateResponse = $this->actingAs($superAdmin)->patch(
            route('admin.accounts.customer.status', $customer),
            [
                'action' => 'reactivate',
                'confirmed' => '1',
                'reason' => 'Đã xác minh thông tin chính chủ',
            ]
        );

        $reactivateResponse->assertRedirect(route('admin.accounts.customer.show', $customer));
        $reactivateResponse->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame(AccountStatus::Active, $customer->account_status);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $superAdmin->id,
            'target_user_id' => $customer->id,
            'action' => AuditAction::AccountReactivated->value,
            'reason' => 'Đã xác minh thông tin chính chủ',
        ]);
    }

    public function test_super_admin_cannot_suspend_themselves_via_account_center(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $response = $this->actingAs($superAdmin)->patch(
            route('admin.accounts.customer.status', $superAdmin),
            [
                'action' => 'suspend',
                'confirmed' => '1',
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('warning'); // Do superAdmin có staff_access_status != not_applicable
        $superAdmin->refresh();
        $this->assertSame(AccountStatus::Active, $superAdmin->account_status);
    }
}
