<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B3;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Authorization\AuthorizationService;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminBootstrapAndOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);
    }

    public function test_bootstrap_command_does_not_accept_cli_credential_arguments_or_public_http_routes(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('bookstore:bootstrap-super-admin', $commands);

        /** @var Command $command */
        $command = $commands['bookstore:bootstrap-super-admin'];
        $definition = $command->getDefinition();

        $this->assertFalse($definition->hasOption('password'));
        $this->assertFalse($definition->hasOption('email'));
        $this->assertFalse($definition->hasArgument('password'));
        $this->assertFalse($definition->hasArgument('email'));

        // Tuyệt đối không lộ route HTTP bootstrap công khai
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('bootstrap', $route->uri());
        }
    }

    public function test_bootstrap_command_succeeds_interactively_and_hashes_password_without_secret_leaks(): void
    {
        $initialPassphrase = 'Init!'.Str::random(10).'9aZ';

        $this->artisan('bookstore:bootstrap-super-admin')
            ->expectsQuestion('Họ và tên Quản trị viên cấp cao', 'Quản trị viên Hệ thống')
            ->expectsQuestion('Địa chỉ email Quản trị viên cấp cao', '  SuperAdmin@Gmail.Com  ')
            ->expectsQuestion(
                'Nhập mật khẩu khởi tạo (tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt)',
                $initialPassphrase,
            )
            ->expectsQuestion('Xác nhận lại mật khẩu khởi tạo', $initialPassphrase)
            ->expectsConfirmation("Xác nhận khởi tạo Quản trị viên cấp cao với email 'superadmin@gmail.com'?", 'yes')
            ->doesntExpectOutput($initialPassphrase)
            ->assertExitCode(0);

        $user = User::query()->where('email', 'superadmin@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Quản trị viên Hệ thống', $user->name);
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::Active, $user->staff_access_status);
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame($initialPassphrase, $user->password);
        $this->assertTrue(Hash::check($initialPassphrase, $user->password));

        $profile = StaffProfile::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('SA-0001', $profile->staff_code);

        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $superAdminRole->id,
            'role_scope' => RoleScope::Global->value,
        ]);

        /** @var AuditLog|null $auditLog */
        $auditLog = AuditLog::query()
            ->where('action', AuditAction::SuperAdminBootstrapped->value)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertNull($auditLog->actor_id);
        $this->assertSame($user->id, $auditLog->target_user_id);

        $serializedAudit = json_encode([
            'old' => $auditLog->old_values,
            'new' => $auditLog->new_values,
            'reason' => $auditLog->reason,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($initialPassphrase, $serializedAudit);
        $this->assertStringNotContainsString($user->password, $serializedAudit);
    }

    public function test_bootstrap_refuses_cancellation_duplicates_email_collisions_and_incomplete_catalog(): void
    {
        $ephemeralPass = 'Init!'.Str::random(10).'9aZ';

        // 1. Từ chối khi hủy xác nhận
        $this->artisan('bookstore:bootstrap-super-admin')
            ->expectsQuestion('Họ và tên Quản trị viên cấp cao', 'Quản trị viên')
            ->expectsQuestion('Địa chỉ email Quản trị viên cấp cao', 'superadmin@gmail.com')
            ->expectsQuestion(
                'Nhập mật khẩu khởi tạo (tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt)',
                $ephemeralPass,
            )
            ->expectsQuestion('Xác nhận lại mật khẩu khởi tạo', $ephemeralPass)
            ->expectsConfirmation("Xác nhận khởi tạo Quản trị viên cấp cao với email 'superadmin@gmail.com'?", 'no')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());

        // 2. Từ chối khi trùng email khách hàng hiện có và giữ nguyên tài khoản khách hàng
        $existingCustomer = User::factory()->create([
            'name' => 'Độc giả Hiện Hữu',
            'email' => 'superadmin@gmail.com',
        ]);

        try {
            app(BootstrapSuperAdminAction::class)->execute(
                'Quản trị mới',
                'superadmin@gmail.com',
                $ephemeralPass,
                $ephemeralPass,
            );
            $this->fail('Bootstrap must refuse colliding existing email.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('bootstrap_email_conflict', $exception->invariantCode);
        }

        $existingCustomer->refresh();
        $this->assertSame(StaffAccessStatus::NotApplicable, $existingCustomer->staff_access_status);
        $this->assertSame(0, UserRole::query()->count());

        // 3. Từ chối khi tạo mới thành công lần 1 rồi chạy lại lần 2
        app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị Đầu Tiên',
            'first-superadmin@gmail.com',
            $ephemeralPass,
            $ephemeralPass,
        );

        $this->artisan('bookstore:bootstrap-super-admin')
            ->assertExitCode(1);

        $this->assertSame(1, UserRole::query()->count());
    }

    public function test_bootstrap_rolls_back_atomically_if_catalog_is_incomplete_or_insert_fails(): void
    {
        $ephemeralPass = 'Init!'.Str::random(10).'9aZ';
        $beforeUserCount = User::query()->count();

        // Thiếu 1 quyền chuẩn -> bị chặn hoàn toàn, không tạo User/StaffProfile/UserRole/AuditLog
        Permission::query()->where('code', 'staff.suspend')->delete();

        try {
            app(BootstrapSuperAdminAction::class)->execute(
                'Quản trị Rollback',
                'superadmin@gmail.com',
                $ephemeralPass,
                $ephemeralPass,
            );
            $this->fail('Incomplete permission catalog must prevent bootstrap.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('permission_catalog_incomplete', $exception->invariantCode);
        }

        $this->assertSame($beforeUserCount, User::query()->count());
        $this->assertSame(0, StaffProfile::query()->count());
        $this->assertSame(0, UserRole::query()->count());
        $this->assertSame(0, AuditLog::query()->count());

        // Khôi phục đủ 20 quyền nhưng mô phỏng lỗi ghi AuditLog để chứng minh rollback giao dịch nguyên tử
        $this->seed(RoleAndPermissionSeeder::class);
        AuditLog::creating(static function (): bool {
            throw new \RuntimeException('Simulated audit insert failure.');
        });

        try {
            try {
                app(BootstrapSuperAdminAction::class)->execute(
                    'Quản trị Rollback 2',
                    'superadmin@gmail.com',
                    $ephemeralPass,
                    $ephemeralPass,
                );
                $this->fail('Simulated failure must bubble up.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Simulated audit insert failure.', $exception->getMessage());
            }
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@gmail.com']);
        $this->assertSame(0, StaffProfile::query()->count());
        $this->assertSame(0, UserRole::query()->count());
    }

    public function test_initial_super_admin_completes_mandatory_onboarding_and_accesses_protected_admin_entry(): void
    {
        $initialPass = 'Init!'.Str::random(10).'9aZ';
        $newPass = 'Next!'.Str::random(12).'8bQ';

        $superAdmin = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên Cấp Cao',
            'superadmin@gmail.com',
            $initialPass,
            $initialPass,
        );

        // 1. Đăng nhập lần đầu: phải tự chuyển hướng sang màn hình đổi mật khẩu bắt buộc
        $loginResponse = $this->post('/dang-nhap', [
            'email' => 'superadmin@gmail.com',
            'password' => $initialPass,
        ]);

        $this->assertAuthenticatedAs($superAdmin);
        $loginResponse->assertRedirect(route('staff.onboarding.password.edit'));

        // 2. Trước khi đổi mật khẩu: bị `staff.active` chặn ở `/quan-tri` nhưng truy cập được trang onboarding (`staff.onboarding`)
        $this->get('/quan-tri')
            ->assertForbidden()
            ->assertSeeText(__('auth.staff_password_change_required'))
            ->assertSee(route('staff.onboarding.password.edit'), false);

        $this->get('/nhan-su/doi-mat-khau-bat-buoc')
            ->assertOk()
            ->assertViewIs('staff.onboarding.password')
            ->assertSeeText('Đổi mật khẩu bắt buộc');

        // 3. Từ chối nếu sai mật khẩu hiện tại hoặc dùng lại mật khẩu cũ
        $this->post('/nhan-su/doi-mat-khau-bat-buoc', [
            'current_password' => 'Wrong!'.Str::random(8),
            'password' => $newPass,
            'password_confirmation' => $newPass,
        ])->assertSessionHasErrors('current_password');

        $this->post('/nhan-su/doi-mat-khau-bat-buoc', [
            'current_password' => $initialPass,
            'password' => $initialPass,
            'password_confirmation' => $initialPass,
        ])->assertSessionHasErrors('password');

        // 4. Đổi mật khẩu hợp lệ -> hoàn tất onboarding, ghi AuditLog (Zero-Secret) và chuyển sang `/quan-tri`
        $changeResponse = $this->post('/nhan-su/doi-mat-khau-bat-buoc', [
            'current_password' => $initialPass,
            'password' => $newPass,
            'password_confirmation' => $newPass,
        ]);

        $changeResponse->assertRedirect(route('admin.dashboard'));

        $superAdmin->refresh();
        $this->assertFalse($superAdmin->must_change_password);
        $this->assertNotNull($superAdmin->password_changed_at);
        $this->assertTrue(Hash::check($newPass, $superAdmin->password));
        $this->assertTrue(app(AuthorizationService::class)->isEffectiveSuperAdmin($superAdmin));

        /** @var AuditLog|null $onboardingLog */
        $onboardingLog = AuditLog::query()
            ->where('action', AuditAction::ForcedPasswordChanged->value)
            ->where('target_user_id', $superAdmin->id)
            ->first();
        $this->assertNotNull($onboardingLog);
        $encodedOnboardingAudit = json_encode($onboardingLog->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($initialPass, $encodedOnboardingAudit);
        $this->assertStringNotContainsString($newPass, $encodedOnboardingAudit);

        // 5. Sau khi onboarding: `/quan-tri` mở thành công với thông tin an toàn, còn tuyến onboarding bị khóa (403)
        $dashboardResponse = $this->get('/quan-tri');
        $dashboardResponse->assertOk()
            ->assertViewIs('admin.dashboard')
            ->assertSeeText('Xin chào, Quản trị viên Cấp Cao')
            ->assertSeeText('superadmin@gmail.com')
            ->assertSeeText('10 quyền chuẩn hóa')
            ->assertSeeText('Đang chuẩn bị (Chưa mở)')
            ->assertDontSeeText('SQLite')
            ->assertDontSeeText('database.sqlite');

        $this->get('/nhan-su/doi-mat-khau-bat-buoc')->assertForbidden();
    }

    public function test_pending_staff_can_complete_onboarding_but_remains_blocked_from_admin_entry(): void
    {
        $tempPass = 'Temp!'.Str::random(10).'7xM';
        $nextPass = 'Staff!'.Str::random(10).'3pQ';

        $pendingStaff = User::factory()->staffPending()->mustChangePassword()->create([
            'password' => $tempPass,
        ]);

        $this->actingAs($pendingStaff)->get('/quan-tri')->assertForbidden();
        $this->actingAs($pendingStaff)->get('/nhan-su/doi-mat-khau-bat-buoc')->assertOk();

        $this->actingAs($pendingStaff)->post('/nhan-su/doi-mat-khau-bat-buoc', [
            'current_password' => $tempPass,
            'password' => $nextPass,
            'password_confirmation' => $nextPass,
        ])->assertRedirect(route('home'));

        $pendingStaff->refresh();
        $this->assertFalse($pendingStaff->must_change_password);
        $this->assertSame(StaffAccessStatus::Pending, $pendingStaff->staff_access_status);

        // Vẫn bị chặn ở `/quan-tri` vì chưa được duyệt kích hoạt và không phải Super Admin
        $this->actingAs($pendingStaff)->get('/quan-tri')->assertForbidden();
    }

    public function test_admin_entry_denies_guest_customer_pending_inactive_and_branch_staff(): void
    {
        $branch = Branch::factory()->retailBranch()->create();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();

        $customer = User::factory()->create();
        $pendingStaff = User::factory()->staffPending()->create();
        $inactiveStaff = User::factory()->staffInactive()->create();

        $branchAdmin = User::factory()->staffActive()->create();
        BranchAssignment::query()->create([
            'user_id' => $branchAdmin->id,
            'branch_id' => $branch->id,
        ]);
        BranchRoleAssignment::query()->create([
            'user_id' => $branchAdmin->id,
            'branch_id' => $branch->id,
            'role_id' => $branchAdminRole->id,
        ]);

        // Khách chưa đăng nhập -> chuyển hướng đăng nhập
        $this->get('/quan-tri')->assertRedirect('/dang-nhap');

        // Khách hàng, pending staff, inactive staff, branch_admin đang active đều bị từ chối (403)
        $this->actingAs($customer)->get('/quan-tri')->assertForbidden();
        $this->actingAs($pendingStaff)->get('/quan-tri')->assertForbidden();
        $this->actingAs($inactiveStaff)->get('/quan-tri')->assertForbidden();
        $this->actingAs($branchAdmin)->get('/quan-tri')->assertForbidden();
    }
}
