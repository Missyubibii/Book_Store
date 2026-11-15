<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B5;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Actions\Auth\CompleteMandatoryPasswordChangeAction;
use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompactAuditAndWarehouseRbacTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Branch $centralWarehouse;

    private Branch $retailBranch;

    private Role $warehouseManagerRole;

    private Role $branchAdminRole;

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

        $this->retailBranch = Branch::factory()->create([
            'code' => 'NA_VINH',
            'name' => 'Book Store Vinh',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
        ]);

        $this->warehouseManagerRole = Role::query()->where('code', 'warehouse_manager')->firstOrFail();
        $this->branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
    }

    public function test_audit_history_renders_compact_table_with_pagination_sorting_and_no_inline_payloads(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            AuditLog::query()->create([
                'actor_id' => $this->superAdmin->id,
                'action' => AuditAction::BranchUpdated,
                'subject_type' => Branch::class,
                'subject_id' => $this->retailBranch->id,
                'branch_id' => $this->retailBranch->id,
                'reason' => "Sự kiện bảng gọn #{$i}",
                'old_values' => ['secret_payload_marker' => "OLD_MARKER_{$i}"],
                'new_values' => ['payload_field' => "NEW_MARKER_{$i}"],
                'occurred_at' => now()->addSeconds($i),
            ]);
        }

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('bs-ad-audit-table', $html);
        $this->assertStringContainsString('data-label="Sự kiện"', $html);
        $this->assertStringContainsString('data-label="Thao tác"', $html);
        $this->assertStringContainsString('Xem chi tiết', $html);
        $this->assertStringContainsString('id="audit-detail-drawer"', $html);
        $this->assertMatchesRegularExpression('/id="audit-detail-drawer"[^>]*aria-hidden="true"/s', $html);

        // Không nhúng payload trước/sau đầy đủ vào HTML danh sách mặc định
        $this->assertStringNotContainsString('NEW_MARKER_25', $html);
        $this->assertStringNotContainsString('OLD_MARKER_25', $html);
    }

    public function test_audit_detail_endpoint_returns_redacted_json_for_super_admin_and_denies_others(): void
    {
        $audit = AuditLog::query()->create([
            'actor_id' => $this->superAdmin->id,
            'action' => AuditAction::TempPasswordIssued,
            'subject_type' => User::class,
            'subject_id' => $this->superAdmin->id,
            'target_user_id' => $this->superAdmin->id,
            'reason' => 'Kiểm thử làm sạch khóa nhạy cảm',
            'old_values' => ['password_hash' => 'secret-hash-123', 'status' => 'pending'],
            'new_values' => ['session_token' => 'tok-999', 'status' => 'active'],
            'occurred_at' => now(),
        ]);

        $customer = User::factory()->create();
        $this->actingAs($customer)
            ->getJson(route('admin.audit-logs.show', $audit))
            ->assertForbidden();

        $warehouseManager = $this->createStaff(
            StaffAccessStatus::Active,
            false,
            $this->centralWarehouse,
            [$this->warehouseManagerRole],
        );
        $this->actingAs($warehouseManager)
            ->getJson(route('admin.audit-logs.show', $audit))
            ->assertForbidden();

        $json = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.audit-logs.show', $audit))
            ->assertOk()
            ->json('data');

        $this->assertSame($audit->id, $json['id']);
        $this->assertSame('[Đã ẩn bảo mật]', $json['old_values']['password_hash']);
        $this->assertSame('pending', $json['old_values']['status']);
        $this->assertSame('[Đã ẩn bảo mật]', $json['new_values']['session_token']);
        $this->assertSame('active', $json['new_values']['status']);
    }

    public function test_warehouse_role_and_permissions_are_branch_scoped_and_isolated_from_branch_admin(): void
    {
        $this->assertSame(RoleScope::Branch, $this->warehouseManagerRole->scope);
        $this->assertSame('Quản lý Kho trung tâm', $this->warehouseManagerRole->name);

        $permCodes = $this->warehouseManagerRole->permissions()->pluck('code')->all();
        sort($permCodes);
        $this->assertSame(['warehouse.dashboard.access', 'warehouse.view'], $permCodes);

        foreach ($permCodes as $code) {
            $perm = Permission::query()->where('code', $code)->firstOrFail();
            $this->assertSame(RoleScope::Branch, $perm->scope);
        }

        // branch_admin không tự động có quyền kho trung tâm
        $branchAdminAtWarehouse = $this->createStaff(
            StaffAccessStatus::Active,
            false,
            $this->centralWarehouse,
            [$this->branchAdminRole],
        );

        $this->actingAs($branchAdminAtWarehouse)
            ->get(route('admin.central-warehouse.show'))
            ->assertForbidden();
    }

    public function test_warehouse_manager_cannot_be_assigned_to_retail_branch(): void
    {
        $staff = $this->createStaff(StaffAccessStatus::Pending);

        $this->actingAs($this->superAdmin)
            ->from(route('admin.staff.show', $staff))
            ->post(route('admin.staff.assignments.upsert', $staff), [
                '_form' => 'upsert_assignment',
                'branch_id' => $this->retailBranch->id,
                'role_ids' => [$this->warehouseManagerRole->id],
                'is_primary' => '1',
            ])
            ->assertRedirect(route('admin.staff.show', $staff))
            ->assertSessionHasErrors('role_ids');
    }

    public function test_pending_warehouse_manager_is_denied_and_active_warehouse_manager_can_access_central_warehouse_only(): void
    {
        $pendingManager = $this->createStaff(
            StaffAccessStatus::Pending,
            false,
            $this->centralWarehouse,
            [$this->warehouseManagerRole],
        );

        $this->actingAs($pendingManager)
            ->get(route('admin.central-warehouse.show'))
            ->assertForbidden();

        // Kích hoạt qua đề xuất + phê duyệt tách biệt
        $proposer = $this->createStaff(
            StaffAccessStatus::Active,
            false,
            $this->centralWarehouse,
            [$this->branchAdminRole],
        );

        $this->actingAs($proposer)
            ->post(route('admin.staff.proposals.store', $pendingManager), [
                '_form' => 'propose_activation',
                'branch_id' => $this->centralWarehouse->id,
                'proposal_reason' => 'Đủ điều kiện quản lý kho trung tâm',
            ])
            ->assertRedirect();

        $proposalId = $pendingManager->staffActivationProposals()->latest('id')->value('id');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.approvals.approve', [$proposalId]), [
                'confirmed' => '1',
                'review_note' => 'Phê duyệt quản lý kho',
            ])
            ->assertRedirect();

        $activeManager = $pendingManager->fresh();
        $this->assertSame(StaffAccessStatus::Active, $activeManager->staff_access_status);

        $warehouseResponse = $this->actingAs($activeManager)
            ->get(route('admin.central-warehouse.show'))
            ->assertOk();

        $html = $warehouseResponse->getContent();
        $this->assertStringContainsString('Quản lý Kho trung tâm', $html);
        $this->assertStringContainsString('CENTRAL_WH', $html);
        $this->assertStringNotContainsString('Nhật ký hoạt động', $html);
        $this->assertStringNotContainsString('Xem danh sách chi nhánh bán lẻ', $html);

        // Bị chặn khỏi các khu vực Super Admin / chi nhánh khác
        $this->actingAs($activeManager)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($activeManager)->get(route('admin.branches.index'))->assertForbidden();
        $this->actingAs($activeManager)->get(route('admin.audit-logs.index'))->assertForbidden();

        // Super Admin vẫn truy cập bình thường
        $this->actingAs($this->superAdmin)
            ->get(route('admin.central-warehouse.show'))
            ->assertOk()
            ->assertSeeText('Xem danh sách chi nhánh bán lẻ');
    }

    private function createEffectiveSuperAdmin(): User
    {
        $passphrase = 'Init!'.Str::random(10).'9aZ';
        $bootstrapped = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên cấp cao',
            'superadmin.stage01b54@example.com',
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
                    'role_scope' => RoleScope::Branch->value,
                    'assigned_at' => now(),
                ]);
            }
        }

        return $user->fresh() ?? $user;
    }
}
