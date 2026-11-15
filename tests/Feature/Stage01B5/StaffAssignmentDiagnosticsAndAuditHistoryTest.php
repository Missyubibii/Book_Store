<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B5;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Actions\Auth\CompleteMandatoryPasswordChangeAction;
use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\StaffAccessStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Audit\AuditLogQueryService;
use App\Services\Staff\StaffAdministrationService;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffAssignmentDiagnosticsAndAuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Branch $centralWarehouse;

    private Branch $branchA;

    private Branch $branchB;

    private Role $branchAdminRole;

    private Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        $this->superAdmin = $this->createEffectiveSuperAdmin();

        $this->centralWarehouse = Branch::query()
            ->where('type', BranchType::CentralWarehouse->value)
            ->firstOrFail();

        $this->branchA = Branch::factory()->create([
            'code' => 'NA_VINH',
            'name' => 'Book Store Vinh',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
        ]);

        $this->branchB = Branch::factory()->create([
            'code' => 'HN_HK',
            'name' => 'Book Store Hoàn Kiếm',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
        ]);

        $this->branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $this->staffRole = Role::query()->where('code', 'staff')->firstOrFail();
    }

    public function test_assignment_persistence_multiple_branches_multiple_roles_summary_and_validation_errors(): void
    {
        $pendingStaff = $this->createStaff(StaffAccessStatus::Pending, mustChangePassword: false);

        // 1. Lỗi validation khi thiếu vai trò chi nhánh
        $this->actingAs($this->superAdmin)
            ->from(route('admin.staff.show', $pendingStaff))
            ->post(route('admin.staff.assignments.upsert', $pendingStaff), [
                'branch_id' => $this->branchA->id,
                'role_ids' => [],
            ])
            ->assertRedirect(route('admin.staff.show', $pendingStaff))
            ->assertSessionHasErrors('role_ids');

        // 2. Gán nhiều vai trò trên chi nhánh A và tiếp tục gán chi nhánh B
        $this->actingAs($this->superAdmin)
            ->followingRedirects()
            ->post(route('admin.staff.assignments.upsert', $pendingStaff), [
                'branch_id' => $this->branchA->id,
                'role_ids' => [$this->branchAdminRole->id, $this->staffRole->id],
                'is_primary' => '1',
            ])
            ->assertOk()
            ->assertSeeText('Đã lưu phân công và vai trò cho nhân sự tại chi nhánh Book Store Vinh (NA_VINH).')
            ->assertDontSeeText('Đã lưu phân công thành công:')
            ->assertSeeText('Book Store Vinh')
            ->assertSeeText('NA_VINH')
            ->assertSeeText('Quản lý chi nhánh')
            ->assertSeeText('Nhân viên chi nhánh')
            ->assertSeeText('Đã hoàn tất đổi mật khẩu & đã lưu phân công, đang Chờ kích hoạt');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.assignments.upsert', $pendingStaff), [
                'branch_id' => $this->branchB->id,
                'role_ids' => [$this->staffRole->id],
                'is_primary' => '0',
            ])
            ->assertRedirect(route('admin.staff.show', $pendingStaff));

        $detail = $this->actingAs($this->superAdmin)->get(route('admin.staff.show', $pendingStaff));
        $detail->assertOk()
            ->assertSeeText('Book Store Vinh')
            ->assertSeeText('Book Store Hoàn Kiếm')
            ->assertSeeText('Cập nhật gần nhất:');
    }

    public function test_pending_staff_with_assignment_is_denied_until_approved_and_branch_admin_has_no_implicit_warehouse_access(): void
    {
        // Tái hiện chính xác kịch bản Nhân sự 1: đã đổi mật khẩu, được gán CENTRAL_WH + branch_admin, nhưng vẫn Pending
        $staff1 = $this->createStaff(StaffAccessStatus::Pending, mustChangePassword: false);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.assignments.upsert', $staff1), [
                'branch_id' => $this->centralWarehouse->id,
                'role_ids' => [$this->branchAdminRole->id],
                'is_primary' => '1',
            ])
            ->assertRedirect(route('admin.staff.show', $staff1));

        // Trang chi tiết phải hiển thị cảnh báo Kho trung tâm & trạng thái Chờ kích hoạt rõ ràng
        $this->actingAs($this->superAdmin)
            ->get(route('admin.staff.show', $staff1))
            ->assertOk()
            ->assertSeeText('Lưu ý Kho trung tâm (CENTRAL_WH):')
            ->assertSeeText('Chưa có hiệu lực nội bộ');

        // 1. Khi còn Pending, nhân sự bị chặn toàn bộ khu vực nội bộ & Kho trung tâm
        $this->actingAs($staff1)->get('/quan-tri/kho-trung-tam')->assertForbidden();
        $this->actingAs($staff1)->get('/quan-tri/nhan-su')->assertForbidden();

        // 2. Sau khi được Super Admin phê duyệt kích hoạt, nhân sự có hiệu lực Active,
        // nhưng vai trò branch_admin tại CENTRAL_WH vẫn KHÔNG ngầm cấp quyền quản trị Kho trung tâm!
        $proposal = app(StaffAdministrationService::class)->submitActivationProposal(
            actor: $this->superAdmin,
            staff: $staff1,
            branch: $this->centralWarehouse,
            proposalReason: 'Kiểm tra ranh giới quyền Kho trung tâm',
        );

        app(StaffAdministrationService::class)->approveActivationProposal(
            actor: $this->superAdmin,
            proposal: $proposal,
            reviewNote: 'Kích hoạt tài khoản để kiểm chứng ranh giới kho.',
        );

        $staff1->refresh();
        $this->assertSame(StaffAccessStatus::Active, $staff1->staff_access_status);

        // Không được phép ngầm vào Kho trung tâm chỉ vì giữ branch_admin tại CENTRAL_WH
        $this->actingAs($staff1)->get('/quan-tri/kho-trung-tam')->assertForbidden();

        // Nếu gán thêm chi nhánh bán lẻ A, nhân sự xem được nhân sự chi nhánh A nhưng bị chặn chéo chi nhánh B
        app(StaffAdministrationService::class)->assignBranchAndRoles(
            actor: $this->superAdmin,
            staff: $staff1,
            branch: $this->branchA,
            roleIds: [$this->branchAdminRole->id],
            isPrimary: false,
        );

        $staffB = $this->createStaff(StaffAccessStatus::Active, mustChangePassword: false, branch: $this->branchB, roles: [$this->staffRole]);

        $this->actingAs($staff1)->get('/quan-tri/nhan-su')->assertOk();
        $this->actingAs($staff1)->get(route('admin.staff.show', $staffB))->assertForbidden();
    }

    public function test_staff_detail_audit_preview_limits_to_10_newest_events_and_renders_scroll_and_full_history_link(): void
    {
        $staff = $this->createStaff(StaffAccessStatus::Active, mustChangePassword: false, branch: $this->branchA, roles: [$this->staffRole]);

        for ($i = 1; $i <= 14; $i++) {
            AuditLog::query()->create([
                'actor_id' => $this->superAdmin->id,
                'target_user_id' => $staff->id,
                'branch_id' => $this->branchA->id,
                'action' => AuditAction::StaffReactivated,
                'subject_type' => User::class,
                'subject_id' => $staff->id,
                'old_values' => null,
                'new_values' => ['sequence' => $i],
                'reason' => sprintf('Sự kiện kiểm toán thứ %02d', $i),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'occurred_at' => now()->subMinutes(20 - $i),
            ]);
        }

        $response = $this->actingAs($this->superAdmin)->get(route('admin.staff.show', $staff));
        $response->assertOk();

        // Server-side LIMIT 10: chỉ render tối đa 10 mục `.bs-ad-audit-item`, giữ 10 sự kiện mới nhất (#14 -> #05) và không nạp #01..#04
        $html = $response->getContent();
        $this->assertSame(10, substr_count($html, 'class="bs-ad-audit-item"'));
        $this->assertStringContainsString('bs-ad-audit-scroll', $html);
        $this->assertStringContainsString('Xem tất cả lịch sử', $html);
        $this->assertStringContainsString('target_user_id='.$staff->id, $html);
        $this->assertStringContainsString('Sự kiện kiểm toán thứ 14', $html);
        $this->assertStringContainsString('Sự kiện kiểm toán thứ 05', $html);
        $this->assertStringNotContainsString('Sự kiện kiểm toán thứ 04', $html);
        $this->assertStringNotContainsString('Sự kiện kiểm toán thứ 01', $html);
    }

    public function test_full_audit_history_page_enforces_super_admin_authorization_filters_pagination_and_redaction(): void
    {
        $customer = User::factory()->create();
        $ordinaryStaff = $this->createStaff(StaffAccessStatus::Active, mustChangePassword: false, branch: $this->branchA, roles: [$this->staffRole]);
        $branchAdmin = $this->createStaff(StaffAccessStatus::Active, mustChangePassword: false, branch: $this->branchA, roles: [$this->branchAdminRole]);

        // 1. Chỉ Super Admin hiệu lực mới xem được trang nhật ký toàn hệ thống
        $this->get('/quan-tri/nhat-ky-hoat-dong')->assertRedirect('/dang-nhap');
        $this->actingAs($customer)->get('/quan-tri/nhat-ky-hoat-dong')->assertForbidden();
        $this->actingAs($ordinaryStaff)->get('/quan-tri/nhat-ky-hoat-dong')->assertForbidden();
        $this->actingAs($branchAdmin)->get('/quan-tri/nhat-ky-hoat-dong')->assertForbidden();

        // 2. Tạo 15 bản ghi lịch sử đa dạng để kiểm tra phân trang, tìm kiếm, lọc và ẩn thông tin nhạy cảm
        for ($i = 1; $i <= 12; $i++) {
            AuditLog::query()->create([
                'actor_id' => $this->superAdmin->id,
                'target_user_id' => $ordinaryStaff->id,
                'branch_id' => $this->branchA->id,
                'action' => AuditAction::BranchAssigned,
                'subject_type' => User::class,
                'subject_id' => $ordinaryStaff->id,
                'old_values' => ['secret_token' => 'SHOULD_NEVER_DISPLAY'],
                'new_values' => ['password_hash' => 'SHOULD_NEVER_DISPLAY', 'step' => $i],
                'reason' => "Ghi nhận phân công đợt {$i}",
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'occurred_at' => now()->subMinutes(30 - $i),
            ]);
        }

        // Phân trang per_page=10 (bảng gọn không nhúng payload nhạy cảm, chi tiết qua endpoint riêng)
        $pageOne = $this->actingAs($this->superAdmin)->get('/quan-tri/nhat-ky-hoat-dong?per_page=10');
        $pageOne->assertOk()
            ->assertSeeText('Nhật ký Hoạt động Hệ thống')
            ->assertDontSee('SHOULD_NEVER_DISPLAY')
            ->assertSeeText('Xem chi tiết');

        $latestAudit = AuditLog::query()->latest('id')->firstOrFail();
        $detailJson = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.audit-logs.show', $latestAudit))
            ->assertOk()
            ->json('data');
        $this->assertSame('[Đã ẩn bảo mật]', $detailJson['old_values']['secret_token']);
        $this->assertSame('[Đã ẩn bảo mật]', $detailJson['new_values']['password_hash']);

        $pageHtml = $pageOne->getContent();
        $this->assertStringNotContainsString('<style', $pageHtml);
        $this->assertStringNotContainsString('href="#"', $pageHtml);
        $this->assertStringContainsString('name="csrf-token"', $pageHtml);

        // Lọc theo từ khóa + hành động + đơn vị
        $filtered = $this->actingAs($this->superAdmin)->get('/quan-tri/nhat-ky-hoat-dong?'.http_build_query([
            'search' => 'đợt 12',
            'action' => AuditAction::BranchAssigned->value,
            'branch_id' => $this->branchA->id,
            'actor_id' => $this->superAdmin->id,
        ]));

        $filtered->assertOk()
            ->assertSee(route('admin.audit-logs.show', $latestAudit))
            ->assertDontSeeText('Ghi nhận phân công đợt 11');

        // Kiểm tra hàm redactPayload độc lập
        $redacted = AuditLogQueryService::redactPayload([
            'password' => '123456',
            'nested' => ['api_token' => 'xyz', 'safe_note' => 'ok'],
        ]);
        $this->assertSame('[Đã ẩn bảo mật]', $redacted['password']);
        $this->assertSame('[Đã ẩn bảo mật]', $redacted['nested']['api_token']);
        $this->assertSame('ok', $redacted['nested']['safe_note']);
    }

    private function createEffectiveSuperAdmin(): User
    {
        $passphrase = 'Init!'.Str::random(10).'9aZ';
        $bootstrapped = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên Cấp Cao',
            'superadmin.stage01b52@example.com',
            $passphrase,
            $passphrase,
        );

        $newPassphrase = 'Final!'.Str::random(10).'8bY';
        app(CompleteMandatoryPasswordChangeAction::class)->execute(
            $bootstrapped,
            $newPassphrase,
        );

        return $bootstrapped->fresh() ?? $bootstrapped;
    }

    /**
     * @param  list<Role>  $roles
     */
    private function createStaff(
        StaffAccessStatus $status,
        bool $mustChangePassword = false,
        ?Branch $branch = null,
        array $roles = [],
    ): User {
        $user = $status === StaffAccessStatus::Pending
            ? User::factory()->staffPending()->create(['must_change_password' => $mustChangePassword])
            : User::factory()->staffActive()->create(['must_change_password' => $mustChangePassword]);

        $status === StaffAccessStatus::Pending
            ? StaffProfile::factory()->create(['user_id' => $user->id])
            : StaffProfile::factory()->activated()->create(['user_id' => $user->id]);

        if ($branch !== null) {
            BranchAssignment::query()->create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'is_primary' => true,
                'status' => BranchAssignmentStatus::Active,
                'assigned_at' => now(),
            ]);

            foreach ($roles as $role) {
                BranchRoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'branch_id' => $branch->id,
                    'role_id' => $role->id,
                    'assigned_at' => now(),
                ]);
            }
        }

        return $user->fresh() ?? $user;
    }
}
