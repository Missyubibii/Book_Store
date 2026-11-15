<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B5;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Role;
use App\Models\StaffActivationProposal;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Configuration\GoogleServicesSetupManager;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StaffActivationAndGoogleSetupTest extends TestCase
{
    use RefreshDatabase;

    private string $tempEnvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        $this->tempEnvPath = storage_path('framework/testing/.env.stage01b56.test');
        @unlink($this->tempEnvPath);
        @unlink($this->tempEnvPath.'.backup');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempEnvPath);
        @unlink($this->tempEnvPath.'.backup');

        parent::tearDown();
    }

    public function test_approval_page_displays_both_pending_proposals_and_pending_staff_without_proposals(): void
    {
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@bookstore.vn');
        $warehouse = Branch::query()->where('type', BranchType::CentralWarehouse->value)->firstOrFail();
        $warehouseRole = Role::query()->where('code', 'warehouse_manager')->firstOrFail();

        // Nhân sự 1: Đang ở trạng thái pending, đã đổi mật khẩu, đã có phân công & vai trò nhưng CHƯA có bản ghi đề xuất
        $pendingWithoutProposal = $this->createPendingStaffWithBranchRole(
            name: 'Nhân sự 1',
            email: 'nhansu1@bookstore.vn',
            branch: $warehouse,
            role: $warehouseRole,
            mustChangePassword: false,
        );

        // Nhân sự 2: Đang ở trạng thái pending và ĐÃ có bản ghi đề xuất chờ phê duyệt
        $pendingWithProposal = $this->createPendingStaffWithBranchRole(
            name: 'Nhân sự 2',
            email: 'nhansu2@bookstore.vn',
            branch: $warehouse,
            role: $warehouseRole,
            mustChangePassword: false,
        );

        StaffActivationProposal::query()->create([
            'user_id' => $pendingWithProposal->id,
            'branch_id' => $warehouse->id,
            'proposed_by' => $superAdmin->id,
            'proposal_reason' => 'Đề xuất kích hoạt Nhân sự 2 quản lý kho.',
            'status' => StaffApprovalStatus::Pending,
            'proposed_at' => now(),
        ]);

        $response = $this->actingAs($superAdmin)
            ->get(route('admin.staff.approvals.index'))
            ->assertOk();

        $response->assertSeeText('Đề xuất chờ phê duyệt (1)');
        $response->assertSeeText('Nhân sự chờ kích hoạt (2)');
        $response->assertSeeText('Nhân sự 1');
        $response->assertSeeText('nhansu1@bookstore.vn');
        $response->assertSeeText('Chưa gửi đề xuất');
        $response->assertSeeText('Kích hoạt trực tiếp (Super Admin)');
        $response->assertSeeText('Nhân sự 2');
        $response->assertSeeText('Đã có đề xuất chờ duyệt');
    }

    public function test_super_admin_can_directly_activate_eligible_pending_staff_with_audit_log(): void
    {
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@bookstore.vn');
        $warehouse = Branch::query()->where('type', BranchType::CentralWarehouse->value)->firstOrFail();
        $warehouseRole = Role::query()->where('code', 'warehouse_manager')->firstOrFail();

        $pendingStaff = $this->createPendingStaffWithBranchRole(
            name: 'Nhân sự Kho Trung tâm',
            email: 'nhansukho@bookstore.vn',
            branch: $warehouse,
            role: $warehouseRole,
            mustChangePassword: false,
        );

        // Không đánh dấu confirmed -> bị từ chối validation
        $this->actingAs($superAdmin)
            ->post(route('admin.staff.activate-direct', $pendingStaff), [
                'review_note' => 'Kích hoạt trực tiếp.',
            ])
            ->assertSessionHasErrors('confirmed');

        $this->assertSame(StaffAccessStatus::Pending, $pendingStaff->fresh()->staff_access_status);

        // Đánh dấu confirmed hợp lệ -> kích hoạt thành công
        $this->actingAs($superAdmin)
            ->post(route('admin.staff.activate-direct', $pendingStaff), [
                'confirmed' => '1',
                'review_note' => 'Đã kiểm tra đầy đủ phân công Kho trung tâm và kích hoạt trực tiếp.',
                'return_to' => 'approvals',
            ])
            ->assertRedirect(route('admin.staff.approvals.index'))
            ->assertSessionHas('success');

        $activated = $pendingStaff->fresh(['staffProfile']);
        $this->assertSame(StaffAccessStatus::Active, $activated->staff_access_status);
        $this->assertNotNull($activated->staffProfile->activated_at);

        $audit = AuditLog::query()
            ->where('target_user_id', $pendingStaff->id)
            ->where('action', AuditAction::StaffActivationApproved->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($superAdmin->id, $audit->actor_id);
        $this->assertSame('super_admin_direct', $audit->new_values['activation_mode'] ?? null);
        $this->assertSame(StaffAccessStatus::Active->value, $audit->new_values['staff_access_status'] ?? null);

        // Sau khi được kích hoạt trực tiếp, nhân sự có warehouse_manager truy cập được Kho trung tâm
        $this->actingAs($activated)
            ->get(route('admin.central-warehouse.show'))
            ->assertOk();
    }

    public function test_direct_activation_enforces_domain_invariants_and_blocks_non_super_admins(): void
    {
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@bookstore.vn');
        $retailBranch = Branch::factory()->create();
        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();

        $branchAdmin = $this->createActiveBranchStaff('branchadmin@bookstore.vn', $retailBranch, $branchAdminRole);

        // 1. Nhân sự chưa đổi mật khẩu tạm (must_change_password = true) -> bị chặn 422
        $unboardedStaff = $this->createPendingStaffWithBranchRole(
            name: 'Nhân sự Chưa Đổi MK',
            email: 'chuadoimk@bookstore.vn',
            branch: $retailBranch,
            role: $staffRole,
            mustChangePassword: true,
        );

        $this->actingAs($superAdmin)
            ->post(route('admin.staff.activate-direct', $unboardedStaff), [
                'confirmed' => '1',
            ])
            ->assertStatus(422);

        $this->assertSame(StaffAccessStatus::Pending, $unboardedStaff->fresh()->staff_access_status);

        // 2. Nhân sự chưa có phân công chi nhánh/vai trò hợp lệ -> bị chặn 422 (không tự động cấp vai trò)
        $unassignedStaff = User::factory()->staffPending()->create([
            'must_change_password' => false,
        ]);
        StaffProfile::factory()->create(['user_id' => $unassignedStaff->id]);

        $this->actingAs($superAdmin)
            ->post(route('admin.staff.activate-direct', $unassignedStaff), [
                'confirmed' => '1',
            ])
            ->assertStatus(422);

        $this->assertSame(StaffAccessStatus::Pending, $unassignedStaff->fresh()->staff_access_status);

        // 3. Tài khoản khách hàng bị khóa (account_status = suspended) -> bị chặn 403/422
        $suspendedStaff = $this->createPendingStaffWithBranchRole(
            name: 'Nhân sự Bị Khóa',
            email: 'bikhoa@bookstore.vn',
            branch: $retailBranch,
            role: $staffRole,
            mustChangePassword: false,
        );
        $suspendedStaff->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->actingAs($superAdmin)
            ->post(route('admin.staff.activate-direct', $suspendedStaff), [
                'confirmed' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(StaffAccessStatus::Pending, $suspendedStaff->fresh()->staff_access_status);

        // 4. Quản trị chi nhánh (Branch Admin) KHÔNG được phép kích hoạt trực tiếp (403)
        $eligibleStaff = $this->createPendingStaffWithBranchRole(
            name: 'Nhân sự Hợp Lệ',
            email: 'hople@bookstore.vn',
            branch: $retailBranch,
            role: $staffRole,
            mustChangePassword: false,
        );

        $this->actingAs($branchAdmin)
            ->post(route('admin.staff.activate-direct', $eligibleStaff), [
                'confirmed' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(StaffAccessStatus::Pending, $eligibleStaff->fresh()->staff_access_status);

        // 5. Cấm tự kích hoạt chính mình
        $pendingSuperAdminSelf = $this->createPendingStaffWithBranchRole(
            name: 'Tự Kích Hoạt',
            email: 'tukichhoat@bookstore.vn',
            branch: $retailBranch,
            role: $staffRole,
            mustChangePassword: false,
        );
        $this->assertFalse($superAdmin->can('directActivateStaff', $superAdmin));
        $this->assertFalse($pendingSuperAdminSelf->can('directActivateStaff', $pendingSuperAdminSelf));
    }

    public function test_real_email_verification_and_password_reset_use_vietnamese_and_unified_toast(): void
    {
        Event::fake([Verified::class]);

        $unverified = User::factory()->unverified()->create([
            'name' => 'Lê Minh Khôi',
            'email' => 'khoile@bookstore.vn',
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $unverified->id,
                'hash' => sha1($unverified->email),
            ],
        );

        $response = $this->actingAs($unverified)
            ->followingRedirects()
            ->get($signedUrl)
            ->assertOk();

        $this->assertTrue($unverified->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);

        $html = $response->getContent();
        $this->assertStringContainsString('data-initial-notification=', $html);
        $this->assertStringContainsString('Địa chỉ email của bạn đã được xác thực thành công.', $html);

        // Kiểm tra nội dung email đặt lại mật khẩu 100% tiếng Việt
        $resetNotification = new ResetPassword('token-kiem-thu-123');
        $mailMessage = $resetNotification->toMail($unverified);
        $this->assertSame('Yêu cầu đặt lại mật khẩu tài khoản - Book_Store', $mailMessage->subject);
        $this->assertSame('Đặt lại mật khẩu', $mailMessage->actionText);
    }

    public function test_interactive_setup_google_command_configures_gmail_oauth_diagnostics_and_disable_safely(): void
    {
        file_put_contents(
            $this->tempEnvPath,
            implode("\n", [
                'APP_NAME="Book_Store"',
                'APP_ENV=local',
                'APP_KEY=base64:PreservedTestKey123456789012345678901234567890=',
                'APP_URL=http://127.0.0.1:8000',
                '# Cấu hình gửi thư',
                'MAIL_MAILER=log',
                'MAIL_HOST=127.0.0.1',
                'MAIL_PORT=2525',
                'MAIL_USERNAME=null',
                'MAIL_PASSWORD=null',
                'MAIL_FROM_ADDRESS="hello@example.com"',
                'MAIL_FROM_NAME="${APP_NAME}"',
                'BOOKSTORE_DEMO_EMAIL_VERIFICATION=true',
                '',
            ]),
        );

        // 1. Từ chối mật khẩu Gmail thông thường (không phải App Password 16 ký tự) và giữ nguyên .env
        $this->artisan('bookstore:setup-google', ['--env-file' => $this->tempEnvPath])
            ->expectsChoice(
                'Vui lòng chọn tác vụ bạn muốn thực hiện',
                '1',
                [
                    '1' => '1. Cấu hình gửi email qua Gmail (SMTP với Mật khẩu ứng dụng 16 ký tự)',
                    '2' => '2. Cấu hình đăng nhập Google OAuth (Client ID & Client Secret)',
                    '3' => '3. Cấu hình đồng thời cả hai dịch vụ (Gmail SMTP & Google OAuth)',
                    '4' => '4. Kiểm tra chẩn đoán kết nối và trạng thái cấu hình hiện tại',
                    '5' => '5. Cấu hình lại an toàn (Sao lưu tệp .env.backup trước khi thay đổi)',
                    '6' => '6. Tắt dịch vụ (Chuyển gửi thư về chế độ cục bộ hoặc tắt Google OAuth)',
                ],
            )
            ->expectsQuestion('Nhập địa chỉ Gmail gửi thư hệ thống', 'newowner@gmail.com')
            ->expectsQuestion('Nhập Mật khẩu ứng dụng Google (16 ký tự, có thể kèm khoảng trắng)', 'MatKhauThuong@123')
            ->assertExitCode(1);

        $this->assertStringContainsString('MAIL_MAILER=log', (string) file_get_contents($this->tempEnvPath));

        // 2. Thiết lập đồng thời cả Gmail SMTP và thông tin xác thực Google OAuth (Chế độ 3)
        $this->artisan('bookstore:setup-google', ['--env-file' => $this->tempEnvPath])
            ->expectsChoice(
                'Vui lòng chọn tác vụ bạn muốn thực hiện',
                '3',
                [
                    '1' => '1. Cấu hình gửi email qua Gmail (SMTP với Mật khẩu ứng dụng 16 ký tự)',
                    '2' => '2. Cấu hình đăng nhập Google OAuth (Client ID & Client Secret)',
                    '3' => '3. Cấu hình đồng thời cả hai dịch vụ (Gmail SMTP & Google OAuth)',
                    '4' => '4. Kiểm tra chẩn đoán kết nối và trạng thái cấu hình hiện tại',
                    '5' => '5. Cấu hình lại an toàn (Sao lưu tệp .env.backup trước khi thay đổi)',
                    '6' => '6. Tắt dịch vụ (Chuyển gửi thư về chế độ cục bộ hoặc tắt Google OAuth)',
                ],
            )
            ->expectsQuestion('Nhập địa chỉ Gmail gửi thư hệ thống', 'newowner@gmail.com')
            ->expectsQuestion('Nhập Mật khẩu ứng dụng Google (16 ký tự, có thể kèm khoảng trắng)', 'abcd efgh ijkl mnop')
            ->expectsQuestion('Nhập tên hiển thị người gửi (MAIL_FROM_NAME)', 'Hiệu Sách Book_Store')
            ->expectsChoice(
                'Chọn cổng kết nối Gmail SMTP',
                '587',
                [
                    '587' => 'Cổng 587 (STARTTLS — Khuyên dùng)',
                    '465' => 'Cổng 465 (Implicit SSL/TLS)',
                ],
            )
            ->expectsConfirmation(
                'Bạn có muốn tắt chế độ xác thực email Demo cục bộ (BOOKSTORE_DEMO_EMAIL_VERIFICATION=false) để sử dụng hoàn toàn email xác thực thật?',
                'yes',
            )
            ->expectsQuestion(
                'Nhập Google OAuth Client ID (kết thúc bằng .apps.googleusercontent.com)',
                '1234567890-abcdef.apps.googleusercontent.com',
            )
            ->expectsQuestion('Nhập Google OAuth Client Secret (nhập ẩn)', 'GOCSPX-SecretKeyForTesting999')
            ->expectsQuestion('Nhập Authorized Redirect URI', 'http://127.0.0.1:8000/xac-thuc/google/callback')
            ->expectsConfirmation("Xác nhận lưu đồng thời cấu hình Gmail SMTP ('newowner@gmail.com') và thông tin xác thực Google OAuth?", 'yes')
            ->assertExitCode(0);

        // Bản sao lưu tạm phải được dọn dẹp ngay sau khi cập nhật nguyên tử thành công (không để bí mật tồn tại vô thời hạn)
        $this->assertFileDoesNotExist($this->tempEnvPath.'.backup');
        $updatedContent = (string) file_get_contents($this->tempEnvPath);

        // Bảo toàn APP_KEY, chú thích và cập nhật đúng khóa mới; Phản ánh đúng trạng thái triển khai Google OAuth
        $isImplemented = app(GoogleServicesSetupManager::class)->isGoogleOAuthSignInImplemented();
        $this->assertStringContainsString('APP_KEY=base64:PreservedTestKey123456789012345678901234567890=', $updatedContent);
        $this->assertStringContainsString('# Cấu hình gửi thư', $updatedContent);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $updatedContent);
        $this->assertStringContainsString('MAIL_HOST=smtp.gmail.com', $updatedContent);
        $this->assertStringContainsString('MAIL_PORT=587', $updatedContent);
        $this->assertStringContainsString('MAIL_USERNAME=newowner@gmail.com', $updatedContent);
        $this->assertStringContainsString('MAIL_PASSWORD=abcdefghijklmnop', $updatedContent);
        $this->assertStringContainsString('BOOKSTORE_DEMO_EMAIL_VERIFICATION=false', $updatedContent);
        $this->assertStringContainsString('BOOKSTORE_GOOGLE_OAUTH_ENABLED='.($isImplemented ? 'true' : 'false'), $updatedContent);
        $this->assertStringContainsString('GOOGLE_CLIENT_ID=1234567890-abcdef.apps.googleusercontent.com', $updatedContent);

        // 3. Chế độ 4: Chẩn đoán tách biệt SMTP và Google OAuth, không làm lộ mật khẩu ứng dụng hay Client Secret
        $this->artisan('bookstore:setup-google', ['--env-file' => $this->tempEnvPath])
            ->expectsChoice(
                'Vui lòng chọn tác vụ bạn muốn thực hiện',
                '4',
                [
                    '1' => '1. Cấu hình gửi email qua Gmail (SMTP với Mật khẩu ứng dụng 16 ký tự)',
                    '2' => '2. Cấu hình đăng nhập Google OAuth (Client ID & Client Secret)',
                    '3' => '3. Cấu hình đồng thời cả hai dịch vụ (Gmail SMTP & Google OAuth)',
                    '4' => '4. Kiểm tra chẩn đoán kết nối và trạng thái cấu hình hiện tại',
                    '5' => '5. Cấu hình lại an toàn (Sao lưu tệp .env.backup trước khi thay đổi)',
                    '6' => '6. Tắt dịch vụ (Chuyển gửi thư về chế độ cục bộ hoặc tắt Google OAuth)',
                ],
            )
            ->expectsConfirmation('Bạn có muốn kiểm tra bắt tay kết nối thực tế tới máy chủ Gmail SMTP ngay bây giờ (không gửi email)?', 'no')
            ->expectsOutputToContain('Đã cấu hình đầy đủ')
            ->expectsOutputToContain('Chưa kiểm tra kết nối thực tế')
            ->expectsOutputToContain($isImplemented ? 'Đã triển khai trong mã nguồn' : 'Chưa triển khai trong mã nguồn')
            ->expectsOutputToContain($isImplemented ? 'Đang bật' : 'Đang tắt an toàn')
            ->doesntExpectOutputToContain('abcdefghijklmnop')
            ->doesntExpectOutputToContain('GOCSPX-SecretKeyForTesting999')
            ->assertExitCode(0);

        // 4. Chế độ 6: Tắt cả hai dịch vụ an toàn
        $this->artisan('bookstore:setup-google', ['--env-file' => $this->tempEnvPath])
            ->expectsChoice(
                'Vui lòng chọn tác vụ bạn muốn thực hiện',
                '6',
                [
                    '1' => '1. Cấu hình gửi email qua Gmail (SMTP với Mật khẩu ứng dụng 16 ký tự)',
                    '2' => '2. Cấu hình đăng nhập Google OAuth (Client ID & Client Secret)',
                    '3' => '3. Cấu hình đồng thời cả hai dịch vụ (Gmail SMTP & Google OAuth)',
                    '4' => '4. Kiểm tra chẩn đoán kết nối và trạng thái cấu hình hiện tại',
                    '5' => '5. Cấu hình lại an toàn (Sao lưu tệp .env.backup trước khi thay đổi)',
                    '6' => '6. Tắt dịch vụ (Chuyển gửi thư về chế độ cục bộ hoặc tắt Google OAuth)',
                ],
            )
            ->expectsChoice(
                'Chọn dịch vụ bạn muốn tắt',
                'both',
                [
                    'gmail' => 'Tắt Gmail SMTP (Chuyển MAIL_MAILER về chế độ ghi log cục bộ và xóa mật khẩu ứng dụng)',
                    'oauth' => 'Tắt đăng nhập Google OAuth (Đặt BOOKSTORE_GOOGLE_OAUTH_ENABLED=false và xóa khóa bí mật)',
                    'both' => 'Tắt đồng thời cả hai dịch vụ',
                ],
            )
            ->expectsConfirmation('Xác nhận tắt dịch vụ đã chọn và xóa thông tin bí mật tương ứng khỏi tệp môi trường?', 'yes')
            ->assertExitCode(0);

        $disabledContent = (string) file_get_contents($this->tempEnvPath);
        $this->assertStringContainsString('MAIL_MAILER=log', $disabledContent);
        $this->assertStringContainsString('BOOKSTORE_GOOGLE_OAUTH_ENABLED=false', $disabledContent);
        $this->assertStringNotContainsString('abcdefghijklmnop', $disabledContent);
        $this->assertStringNotContainsString('GOCSPX-SecretKeyForTesting999', $disabledContent);
    }

    public function test_setup_google_preserves_app_key_and_rolls_back_when_both_mode_cancelled(): void
    {
        file_put_contents(
            $this->tempEnvPath,
            implode("\n", [
                'APP_KEY=base64:ImmutableKey999888777666555444333222111000=',
                'MAIL_MAILER=log',
                '',
            ]),
        );

        /** @var GoogleServicesSetupManager $manager */
        $manager = app(GoogleServicesSetupManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $manager->updateEnvironmentFile(['APP_KEY' => 'base64:MaliciousKey='], $this->tempEnvPath);
    }

    public function test_both_mode_cancellation_leaves_env_untouched_and_confirmation_panel_starts_collapsed(): void
    {
        $initialContent = implode("\n", [
            'APP_KEY=base64:ImmutableKey999888777666555444333222111000=',
            'MAIL_MAILER=log',
            '',
        ]);
        file_put_contents($this->tempEnvPath, $initialContent);

        $this->artisan('bookstore:setup-google', ['--env-file' => $this->tempEnvPath])
            ->expectsChoice(
                'Vui lòng chọn tác vụ bạn muốn thực hiện',
                '3',
                [
                    '1' => '1. Cấu hình gửi email qua Gmail (SMTP với Mật khẩu ứng dụng 16 ký tự)',
                    '2' => '2. Cấu hình đăng nhập Google OAuth (Client ID & Client Secret)',
                    '3' => '3. Cấu hình đồng thời cả hai dịch vụ (Gmail SMTP & Google OAuth)',
                    '4' => '4. Kiểm tra chẩn đoán kết nối và trạng thái cấu hình hiện tại',
                    '5' => '5. Cấu hình lại an toàn (Sao lưu tệp .env.backup trước khi thay đổi)',
                    '6' => '6. Tắt dịch vụ (Chuyển gửi thư về chế độ cục bộ hoặc tắt Google OAuth)',
                ],
            )
            ->expectsQuestion('Nhập địa chỉ Gmail gửi thư hệ thống', 'owner@gmail.com')
            ->expectsQuestion('Nhập Mật khẩu ứng dụng Google (16 ký tự, có thể kèm khoảng trắng)', 'abcd efgh ijkl mnop')
            ->expectsQuestion('Nhập tên hiển thị người gửi (MAIL_FROM_NAME)', 'Book_Store')
            ->expectsChoice(
                'Chọn cổng kết nối Gmail SMTP',
                '587',
                [
                    '587' => 'Cổng 587 (STARTTLS — Khuyên dùng)',
                    '465' => 'Cổng 465 (Implicit SSL/TLS)',
                ],
            )
            ->expectsConfirmation(
                'Bạn có muốn tắt chế độ xác thực email Demo cục bộ (BOOKSTORE_DEMO_EMAIL_VERIFICATION=false) để sử dụng hoàn toàn email xác thực thật?',
                'yes',
            )
            ->expectsQuestion(
                'Nhập Google OAuth Client ID (kết thúc bằng .apps.googleusercontent.com)',
                '1234567890-abcdef.apps.googleusercontent.com',
            )
            ->expectsQuestion('Nhập Google OAuth Client Secret (nhập ẩn)', 'GOCSPX-SecretKeyForTesting999')
            ->expectsQuestion('Nhập Authorized Redirect URI', 'http://127.0.0.1:8000/xac-thuc/google/callback')
            ->expectsConfirmation("Xác nhận lưu đồng thời cấu hình Gmail SMTP ('owner@gmail.com') và thông tin xác thực Google OAuth?", 'no')
            ->assertExitCode(1);

        $this->assertSame($initialContent, (string) file_get_contents($this->tempEnvPath));
        $this->assertFileDoesNotExist($this->tempEnvPath.'.backup');

        // Kiểm tra khung xác nhận kích hoạt trực tiếp mặc định đóng (collapsed) trên trang phê duyệt
        $superAdmin = $this->createEffectiveSuperAdmin('superadmin@bookstore.vn');
        $warehouse = Branch::query()->where('type', BranchType::CentralWarehouse->value)->firstOrFail();
        $warehouseRole = Role::query()->where('code', 'warehouse_manager')->firstOrFail();
        $pendingStaff = $this->createPendingStaffWithBranchRole(
            name: 'Nhân sự Đóng Panel',
            email: 'dongpanel@bookstore.vn',
            branch: $warehouse,
            role: $warehouseRole,
            mustChangePassword: false,
        );

        $html = $this->actingAs($superAdmin)
            ->get(route('admin.staff.approvals.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="direct-activate-panel-'.$pendingStaff->id.'"', $html);
        $this->assertStringContainsString('class="bs-ad-confirm-box bs-hidden"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    private function createEffectiveSuperAdmin(string $email): User
    {
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

        $user = User::factory()->staffActive()->create([
            'name' => 'Quản trị viên Cấp cao',
            'email' => $email,
            'must_change_password' => false,
        ]);

        StaffProfile::factory()->create([
            'user_id' => $user->id,
            'activated_at' => now(),
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $superAdminRole->id,
            'role_scope' => RoleScope::Global->value,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function createActiveBranchStaff(string $email, Branch $branch, Role $role): User
    {
        $user = User::factory()->staffActive()->create([
            'email' => $email,
            'must_change_password' => false,
        ]);

        StaffProfile::factory()->create([
            'user_id' => $user->id,
            'activated_at' => now(),
        ]);

        BranchAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'is_primary' => true,
            'status' => BranchAssignmentStatus::Active,
            'assigned_at' => now(),
        ]);

        BranchRoleAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role_id' => $role->id,
            'role_scope' => RoleScope::Branch->value,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function createPendingStaffWithBranchRole(
        string $name,
        string $email,
        Branch $branch,
        Role $role,
        bool $mustChangePassword,
    ): User {
        $user = User::factory()->staffPending()->create([
            'name' => $name,
            'email' => $email,
            'must_change_password' => $mustChangePassword,
        ]);

        StaffProfile::factory()->create([
            'user_id' => $user->id,
            'activated_at' => null,
        ]);

        BranchAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'is_primary' => true,
            'status' => BranchAssignmentStatus::Active,
            'assigned_at' => now(),
        ]);

        BranchRoleAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role_id' => $role->id,
            'role_scope' => RoleScope::Branch->value,
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
