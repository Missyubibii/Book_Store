<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B5;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Actions\Auth\CompleteMandatoryPasswordChangeAction;
use App\Actions\Auth\RecoverSuperAdminPasswordAction;
use App\Console\Commands\RecoverSuperAdminPasswordCommand;
use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\LoginEventType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SuperAdminPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        $this->tokenPath = storage_path(RecoverSuperAdminPasswordCommand::TOKEN_RELATIVE_PATH);
        $this->removeVerificationTokenFile();
    }

    protected function tearDown(): void
    {
        $this->removeVerificationTokenFile();

        parent::tearDown();
    }

    public function test_successful_local_recovery_updates_password_revokes_sessions_preserves_rbac_and_writes_zero_secret_audit(): void
    {
        $oldPassword = 'OldSuper!2026#Init99';
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@gmail.com', $oldPassword);
        $originalRememberToken = $superAdmin->remember_token;

        $otherUser = User::factory()->create();

        // Tạo phiên đăng nhập đang hoạt động cho Super Admin và một người dùng khác
        DB::table('sessions')->insert([
            [
                'id' => Str::random(40),
                'user_id' => $superAdmin->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit Browser 1',
                'payload' => base64_encode('session-1'),
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => Str::random(40),
                'user_id' => $superAdmin->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit Browser 2',
                'payload' => base64_encode('session-2'),
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => Str::random(40),
                'user_id' => $otherUser->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Other User Browser',
                'payload' => base64_encode('other-session'),
                'last_activity' => now()->timestamp,
            ],
        ]);

        $localToken = 'OwnerVerifyToken!2026#XYZ';
        $this->writeVerificationTokenFile($localToken);

        $newPassword = 'Recovered!2026#Super88';

        $this->artisan('bookstore:recover-super-admin')
            ->expectsQuestion('Nhập mã xác minh quyền sở hữu cục bộ (từ tệp storage/framework/super_admin_recovery.token)', $localToken)
            ->expectsQuestion('Nhập địa chỉ email tài khoản Quản trị viên cấp cao cần khôi phục', 'superadmin@gmail.com')
            ->expectsQuestion('Nhập mật khẩu mới (tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, chữ số và ký tự đặc biệt)', $newPassword)
            ->expectsQuestion('Xác nhận lại mật khẩu mới', $newPassword)
            ->expectsConfirmation("Xác nhận đặt lại mật khẩu và thu hồi toàn bộ phiên đăng nhập hiện tại của Quản trị viên cấp cao 'superadmin@gmail.com'?", 'yes')
            ->assertSuccessful();

        // Tệp mã xác minh một lần phải bị xóa ngay sau khi dùng
        $this->assertFileDoesNotExist($this->tokenPath);

        $superAdmin->refresh();

        $this->assertTrue(Hash::check($newPassword, $superAdmin->password));
        $this->assertFalse(Hash::check($oldPassword, $superAdmin->password));
        $this->assertNotSame($originalRememberToken, $superAdmin->remember_token);
        $this->assertFalse($superAdmin->must_change_password);
        $this->assertSame(AccountStatus::Active, $superAdmin->account_status);
        $this->assertSame(StaffAccessStatus::Active, $superAdmin->staff_access_status);

        // Không tạo thêm Super Admin thứ hai và không thay đổi vai trò/phân công
        $this->assertSame(1, UserRole::query()->count());
        $this->assertSame(0, $superAdmin->branchAssignments()->count());

        // Toàn bộ phiên cũ của Super Admin bị xóa; phiên người dùng khác giữ nguyên
        $this->assertSame(0, DB::table('sessions')->where('user_id', $superAdmin->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $otherUser->id)->count());

        // Ghi nhận nhật ký thu hồi phiên và kiểm toán Zero-Secret
        $this->assertDatabaseHas('login_events', [
            'user_id' => $superAdmin->id,
            'event_type' => LoginEventType::SessionRevoked->value,
        ]);

        $audit = AuditLog::query()
            ->where('target_user_id', $superAdmin->id)
            ->where('action', AuditAction::ForcedPasswordChanged->value)
            ->latest('id')
            ->firstOrFail();

        $serializedAudit = json_encode([$audit->old_values, $audit->new_values, $audit->reason], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($newPassword, (string) $serializedAudit);
        $this->assertStringNotContainsString($oldPassword, (string) $serializedAudit);
        $this->assertStringNotContainsString($localToken, (string) $serializedAudit);
        $this->assertStringNotContainsString((string) $superAdmin->password, (string) $serializedAudit);
        $this->assertSame(2, $audit->new_values['sessions_revoked_count'] ?? null);

        // Đăng nhập thực tế bằng mật khẩu vừa khôi phục và vào được /quan-tri
        $this->post('/dang-nhap', [
            'email' => 'superadmin@gmail.com',
            'password' => $newPassword,
        ])->assertRedirect(route('home'));

        $this->get('/quan-tri')->assertOk();
    }

    public function test_recovery_fails_authorization_when_verification_token_is_missing_mismatched_or_action_unverified(): void
    {
        $oldPassword = 'OldSuper!2026#Init99';
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@gmail.com', $oldPassword);

        // 1. Thiếu tệp mã xác minh cục bộ dù đang ở môi trường local/test
        $this->artisan('bookstore:recover-super-admin')
            ->assertFailed();
        $this->assertTrue(Hash::check($oldPassword, $superAdmin->fresh()->password));

        // 2. Nhập sai mã xác minh -> thất bại và tiêu hủy tệp token một lần
        $this->writeVerificationTokenFile('CorrectLocalToken!12345');

        $this->artisan('bookstore:recover-super-admin')
            ->expectsQuestion('Nhập mã xác minh quyền sở hữu cục bộ (từ tệp storage/framework/super_admin_recovery.token)', 'WrongToken!999999999')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->tokenPath);
        $this->assertTrue(Hash::check($oldPassword, $superAdmin->fresh()->password));

        // 3. Gọi trực tiếp Action với ownershipVerified = false bị chặn bởi AuthorizationException
        $this->expectException(AuthorizationException::class);
        app(RecoverSuperAdminPasswordAction::class)->execute(
            'superadmin@gmail.com',
            'NewStrong!2026#Ab1',
            'NewStrong!2026#Ab1',
            ownershipVerified: false,
        );
    }

    public function test_recovery_rejects_missing_or_duplicate_super_admin_and_non_super_admin_targets(): void
    {
        // 1. Chưa có Super Admin nào trong hệ thống -> từ chối
        $this->writeVerificationTokenFile('LocalVerify!2026#Missing');

        $this->artisan('bookstore:recover-super-admin')
            ->expectsQuestion('Nhập mã xác minh quyền sở hữu cục bộ (từ tệp storage/framework/super_admin_recovery.token)', 'LocalVerify!2026#Missing')
            ->expectsQuestion('Nhập địa chỉ email tài khoản Quản trị viên cấp cao cần khôi phục', 'superadmin@gmail.com')
            ->expectsQuestion('Nhập mật khẩu mới (tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, chữ số và ký tự đặc biệt)', 'NewStrong!2026#Ab1')
            ->expectsQuestion('Xác nhận lại mật khẩu mới', 'NewStrong!2026#Ab1')
            ->expectsConfirmation("Xác nhận đặt lại mật khẩu và thu hồi toàn bộ phiên đăng nhập hiện tại của Quản trị viên cấp cao 'superadmin@gmail.com'?", 'yes')
            ->assertFailed();

        // 2. Có đúng 1 Super Admin, nhưng trỏ vào email khách hàng -> bị từ chối 403
        $oldPassword = 'OldSuper!2026#Init99';
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@gmail.com', $oldPassword);
        $customer = User::factory()->create(['email' => 'customer@example.com']);

        try {
            app(RecoverSuperAdminPasswordAction::class)->execute(
                $customer->email,
                'NewStrong!2026#Ab1',
                'NewStrong!2026#Ab1',
                ownershipVerified: true,
            );
            $this->fail('Không được phép khôi phục tài khoản không phải Super Admin.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('target_not_super_admin', $exception->invariantCode);
        }

        // 3. Phát hiện 2 tài khoản Super Admin -> từ chối khôi phục tự động và giữ nguyên mật khẩu
        $secondAdmin = User::factory()->staffActive()->create(['email' => 'second.sa@example.com']);
        StaffProfile::factory()->activated()->create(['user_id' => $secondAdmin->id]);
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

        UserRole::query()->create([
            'user_id' => $secondAdmin->id,
            'role_id' => $superAdminRole->id,
            'role_scope' => RoleScope::Global->value,
            'assigned_at' => now(),
        ]);

        try {
            app(RecoverSuperAdminPasswordAction::class)->execute(
                'superadmin@gmail.com',
                'NewStrong!2026#Ab1',
                'NewStrong!2026#Ab1',
                ownershipVerified: true,
            );
            $this->fail('Phải từ chối khi tồn tại nhiều hơn một Super Admin.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('duplicate_super_admin_detected', $exception->invariantCode);
        }

        $this->assertTrue(Hash::check($oldPassword, $superAdmin->fresh()->password));
    }

    public function test_recovery_enforces_strong_password_policy_and_rolls_back_atomically_on_audit_failure(): void
    {
        $oldPassword = 'OldSuper!2026#Init99';
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@gmail.com', $oldPassword);
        $originalHash = $superAdmin->password;
        $originalRemember = $superAdmin->remember_token;

        $sessionId = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $superAdmin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit Browser',
            'payload' => base64_encode('active-session'),
            'last_activity' => now()->timestamp,
        ]);

        // 1. Mật khẩu yếu bị từ chối bởi chính sách mật khẩu mạnh
        try {
            app(RecoverSuperAdminPasswordAction::class)->execute(
                'superadmin@gmail.com',
                'weakpass',
                'weakpass',
                ownershipVerified: true,
            );
            $this->fail('Mật khẩu yếu phải bị từ chối.');
        } catch (ValidationException) {
            $this->assertSame($originalHash, $superAdmin->fresh()->password);
        }

        // 2. Lỗi ghi AuditLog phải rollback toàn bộ mật khẩu, remember_token và sessions
        AuditLog::creating(function (): void {
            throw new RuntimeException('Mô phỏng lỗi ghi AuditLog khi khôi phục.');
        });

        try {
            app(RecoverSuperAdminPasswordAction::class)->execute(
                'superadmin@gmail.com',
                'StrongRollback!2026#Zz',
                'StrongRollback!2026#Zz',
                ownershipVerified: true,
            );
            $this->fail('Phải ném RuntimeException khi AuditLog lỗi.');
        } catch (RuntimeException) {
            // Đúng kỳ vọng
        } finally {
            AuditLog::flushEventListeners();
        }

        $reloaded = $superAdmin->fresh();
        $this->assertSame($originalHash, $reloaded->password);
        $this->assertSame($originalRemember, $reloaded->remember_token);
        $this->assertSame(1, DB::table('sessions')->where('id', $sessionId)->count());
    }

    private function createEffectiveSuperAdmin(string $email, string $finalPassword): User
    {
        $tempPassphrase = 'Init!'.Str::random(10).'9aZ';
        $bootstrapped = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên Cấp Cao',
            $email,
            $tempPassphrase,
            $tempPassphrase,
        );

        app(CompleteMandatoryPasswordChangeAction::class)->execute(
            $bootstrapped,
            $finalPassword,
        );

        return $bootstrapped->fresh() ?? $bootstrapped;
    }

    private function writeVerificationTokenFile(string $token): void
    {
        $dir = dirname($this->tokenPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->tokenPath, $token);
    }

    private function removeVerificationTokenFile(): void
    {
        if (isset($this->tokenPath) && is_file($this->tokenPath)) {
            @unlink($this->tokenPath);
        }
    }
}
