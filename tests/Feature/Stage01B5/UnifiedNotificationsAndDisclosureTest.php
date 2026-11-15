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
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnifiedNotificationsAndDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Branch $branchA;

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

        $this->branchA = Branch::factory()->create([
            'code' => 'HN_TEST',
            'name' => 'Book Store Hà Nội Test',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
        ]);

        $this->branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $this->staffRole = Role::query()->where('code', 'staff')->firstOrFail();
    }

    public function test_single_notification_renderer_across_storefront_auth_and_admin_layouts(): void
    {
        $staff = $this->createStaff(StaffAccessStatus::Pending, mustChangePassword: false);

        foreach ([
            route('home'),
            route('login'),
            route('register'),
        ] as $publicUrl) {
            $response = $this->get($publicUrl)->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), 'data-notification-center'));
        }

        foreach ([
            route('admin.staff.show', $staff),
            route('admin.branches.show', $this->branchA),
        ] as $adminUrl) {
            $response = $this->actingAs($this->superAdmin)->get($adminUrl)->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), 'data-notification-center'));
        }
    }

    public function test_all_transient_flash_types_use_unified_5000ms_duration_and_escape_xss(): void
    {
        foreach (['success', 'error', 'warning', 'info', 'status'] as $flashKey) {
            $payloadText = 'Thông báo kiểm thử <script>alert("xss")</script> & "dấu ngoặc"';

            $response = $this->withSession([$flashKey => $payloadText])
                ->get(route('login'))
                ->assertOk();

            $html = $response->getContent();
            $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
            $this->assertTrue(
                preg_match('/data-initial-notification="([^"]+)"/', $html, $matches) === 1,
                "Expected data-initial-notification for flash key [{$flashKey}]."
            );

            $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            $this->assertIsArray($decoded);
            $this->assertSame(5000, $decoded['duration']);
            $this->assertFalse($decoded['persistent']);
            $this->assertSame($payloadText, $decoded['message']);
        }
    }

    public function test_staff_assignment_save_uses_single_toast_without_duplicate_inline_banner(): void
    {
        $staff = $this->createStaff(StaffAccessStatus::Pending, mustChangePassword: false);

        $response = $this->actingAs($this->superAdmin)
            ->followingRedirects()
            ->post(route('admin.staff.assignments.upsert', $staff), [
                '_form' => 'upsert_assignment',
                'branch_id' => $this->branchA->id,
                'role_ids' => [$this->branchAdminRole->id],
                'is_primary' => '1',
            ])
            ->assertOk();

        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-notification-center'));
        $this->assertStringContainsString('data-initial-notification=', $html);
        $this->assertStringNotContainsString('bs-ad-success-banner', $html);
        $this->assertStringNotContainsString('Đã lưu phân công thành công:', $html);
        $this->assertStringContainsString($this->branchA->name, $html);
        $this->assertStringContainsString($this->branchAdminRole->name, $html);
    }

    public function test_staff_detail_forms_are_collapsed_by_default_and_reopen_independently_on_validation_errors(): void
    {
        $staff = $this->createStaff(
            StaffAccessStatus::Pending,
            mustChangePassword: false,
            branch: $this->branchA,
            roles: [$this->staffRole],
        );

        // 1. Trạng thái mặc định: bảng phân công hiện rõ, hai form chỉnh sửa và đề xuất đều đóng
        $initialHtml = $this->actingAs($this->superAdmin)
            ->get(route('admin.staff.show', $staff))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->branchA->name, $initialHtml);
        $this->assertStringContainsString('Chờ kích hoạt', $initialHtml);
        $this->assertMatchesRegularExpression('/id="staff-upsert-assignment-panel"[^>]*aria-hidden="true"[^>]*bs-hidden/s', $initialHtml);
        $this->assertMatchesRegularExpression('/id="staff-propose-activation-panel"[^>]*aria-hidden="true"[^>]*bs-hidden/s', $initialHtml);

        // 2. Lỗi validation ở form phân công -> chỉ mở lại form phân công
        $upsertErrorHtml = $this->actingAs($this->superAdmin)
            ->from(route('admin.staff.show', $staff))
            ->followingRedirects()
            ->post(route('admin.staff.assignments.upsert', $staff), [
                '_form' => 'upsert_assignment',
                'branch_id' => $this->branchA->id,
                'role_ids' => [],
            ])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="staff-upsert-assignment-panel"[^>]*aria-hidden="false"/s', $upsertErrorHtml);
        $this->assertStringNotContainsString('id="staff-upsert-assignment-panel" data-disclosure-panel aria-hidden="false" class="bs-ad-disclosure-body bs-hidden"', $upsertErrorHtml);
        $this->assertMatchesRegularExpression('/id="staff-propose-activation-panel"[^>]*aria-hidden="true"[^>]*bs-hidden/s', $upsertErrorHtml);

        // 3. Lỗi validation ở form đề xuất -> chỉ mở lại form đề xuất
        $proposeErrorHtml = $this->actingAs($this->superAdmin)
            ->from(route('admin.staff.show', $staff))
            ->followingRedirects()
            ->post(route('admin.staff.proposals.store', $staff), [
                '_form' => 'propose_activation',
                'branch_id' => 999999,
            ])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="staff-propose-activation-panel"[^>]*aria-hidden="false"/s', $proposeErrorHtml);
        $this->assertMatchesRegularExpression('/id="staff-upsert-assignment-panel"[^>]*aria-hidden="true"[^>]*bs-hidden/s', $proposeErrorHtml);
    }

    public function test_branch_detail_edit_and_status_confirm_are_collapsed_by_default_and_reopen_on_error(): void
    {
        // 1. Trạng thái mặc định: thông tin chi nhánh hiển thị, form chỉnh sửa và hộp xác nhận đều đóng
        $initialHtml = $this->actingAs($this->superAdmin)
            ->get(route('admin.branches.show', $this->branchA))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->branchA->name, $initialHtml);
        $this->assertStringContainsString('Chỉnh sửa thông tin', $initialHtml);
        $this->assertMatchesRegularExpression('/id="branch-edit-form-panel"[^>]*aria-hidden="true"[^>]*bs-hidden/s', $initialHtml);
        $this->assertMatchesRegularExpression('/id="branch-status-confirm-panel"[^>]*bs-hidden/s', $initialHtml);

        // 2. Lỗi validation ở form cập nhật thông tin -> chỉ mở lại form cập nhật
        $editErrorHtml = $this->actingAs($this->superAdmin)
            ->from(route('admin.branches.show', $this->branchA))
            ->followingRedirects()
            ->patch(route('admin.branches.update', $this->branchA), [
                '_form' => 'edit',
                'name' => '',
                'status' => BranchStatus::Active->value,
            ])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="branch-edit-form-panel"[^>]*aria-hidden="false"/s', $editErrorHtml);
        $this->assertMatchesRegularExpression('/id="branch-status-confirm-panel"[^>]*bs-hidden/s', $editErrorHtml);

        // 3. Lỗi validation ở form chuyển trạng thái -> chỉ mở lại hộp xác nhận trạng thái
        $statusErrorHtml = $this->actingAs($this->superAdmin)
            ->from(route('admin.branches.show', $this->branchA))
            ->followingRedirects()
            ->patch(route('admin.branches.status.update', $this->branchA), [
                'status' => BranchStatus::Inactive->value,
            ])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="branch-edit-form-panel"[^>]*aria-hidden="true"[^>]*bs-hidden/s', $statusErrorHtml);
        $this->assertMatchesRegularExpression('/id="branch-status-confirm-panel"[^>]*class="bs-ad-confirm-box"[^>]*aria-hidden="false"/s', $statusErrorHtml);
    }

    public function test_audit_preview_limit_10_approval_workflow_and_unauthorized_access_remain_intact(): void
    {
        $customer = User::factory()->create();
        $staff = $this->createStaff(
            StaffAccessStatus::Pending,
            mustChangePassword: false,
            branch: $this->branchA,
            roles: [$this->staffRole],
        );

        $this->actingAs($customer)
            ->get(route('admin.staff.show', $staff))
            ->assertForbidden();

        $this->actingAs($customer)
            ->get(route('admin.branches.show', $this->branchA))
            ->assertForbidden();

        for ($i = 1; $i <= 12; $i++) {
            AuditLog::query()->create([
                'actor_id' => $this->superAdmin->id,
                'action' => AuditAction::BranchAssigned,
                'subject_type' => User::class,
                'subject_id' => $staff->id,
                'branch_id' => $this->branchA->id,
                'reason' => "Sự kiện kiểm thử #{$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $staffShowHtml = $this->actingAs($this->superAdmin)
            ->get(route('admin.staff.show', $staff))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Xem tất cả lịch sử', $staffShowHtml);
        $this->assertStringContainsString('Sự kiện kiểm thử #12', $staffShowHtml);
        $this->assertStringNotContainsString('Sự kiện kiểm thử #1<', $staffShowHtml);

        // Giữ nguyên quy trình đề xuất & phê duyệt tách biệt
        $proposer = $this->createStaff(
            StaffAccessStatus::Active,
            mustChangePassword: false,
            branch: $this->branchA,
            roles: [$this->branchAdminRole],
        );

        $this->actingAs($proposer)
            ->post(route('admin.staff.proposals.store', $staff), [
                '_form' => 'propose_activation',
                'branch_id' => $this->branchA->id,
                'proposal_reason' => 'Đủ điều kiện kích hoạt',
            ])
            ->assertRedirect();

        $proposalId = $staff->staffActivationProposals()->latest('id')->value('id');
        $this->assertNotNull($proposalId);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.approvals.approve', [$proposalId]), [
                'confirmed' => '1',
                'review_note' => 'Phê duyệt hợp lệ',
            ])
            ->assertRedirect();

        $this->assertSame(StaffAccessStatus::Active, $staff->fresh()->staff_access_status);
    }

    private function createEffectiveSuperAdmin(): User
    {
        $passphrase = 'Init!'.Str::random(10).'9aZ';
        $bootstrapped = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên cấp cao',
            'superadmin.stage01b53@example.com',
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
