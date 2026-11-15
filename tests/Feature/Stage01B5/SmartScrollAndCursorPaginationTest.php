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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartScrollAndCursorPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Branch $centralWarehouse;

    private Branch $retailBranch;

    private User $staffUser;

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
            'code' => 'TEST_BR',
            'name' => 'Chi nhánh Thử nghiệm',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
        ]);

        $this->staffUser = User::factory()->create([
            'email' => 'nhansu.scroll@example.com',
            'name' => 'Nhân sự Scroll',
            'staff_access_status' => StaffAccessStatus::Active,
        ]);

        StaffProfile::factory()->create([
            'user_id' => $this->staffUser->id,
            'staff_code' => 'STF-SCROLL-01',
        ]);

        $assignment = BranchAssignment::query()->create([
            'user_id' => $this->staffUser->id,
            'branch_id' => $this->retailBranch->id,
            'assigned_at' => now(),
            'status' => BranchAssignmentStatus::Active,
        ]);

        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();
        BranchRoleAssignment::query()->create([
            'branch_assignment_id' => $assignment->id,
            'user_id' => $this->staffUser->id,
            'branch_id' => $this->retailBranch->id,
            'role_id' => $staffRole->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_staff_show_renders_clean_heading_and_initial_ten_audits_with_cursor(): void
    {
        $baseTime = Carbon::parse('2026-11-15 10:00:00');

        for ($i = 1; $i <= 15; $i++) {
            AuditLog::query()->create([
                'actor_id' => $this->superAdmin->id,
                'action' => AuditAction::BranchAssigned,
                'subject_type' => User::class,
                'subject_id' => $this->staffUser->id,
                'target_user_id' => $this->staffUser->id,
                'branch_id' => $this->retailBranch->id,
                'reason' => "Sự kiện kiểm toán nhân sự #{$i}",
                'occurred_at' => $baseTime->copy()->addMinutes($i),
            ]);
        }

        $response = $this->actingAs($this->superAdmin)->get(route('admin.staff.show', $this->staffUser));

        $response->assertOk();
        $response->assertSee('Nhật ký hoạt động gần đây');
        $response->assertDontSee('(Tối đa 10 sự kiện)');
        $response->assertSee('data-smart-scroll', false);
        $response->assertSee('data-has-more="true"', false);
        $response->assertSee('Tải thêm');

        // Bản ghi mới nhất (#15) phải xuất hiện trong 10 bản ghi đầu
        $response->assertSee('Sự kiện kiểm toán nhân sự #15');
        $response->assertSee('Sự kiện kiểm toán nhân sự #6');
        // Bản ghi cũ hơn (#5 đến #1) không nằm trong đợt đầu
        $response->assertDontSee('Sự kiện kiểm toán nhân sự #5');
    }

    public function test_staff_audit_logs_cursor_pagination_endpoint_returns_next_batch_without_duplicates(): void
    {
        $baseTime = Carbon::parse('2026-11-15 10:00:00');

        for ($i = 1; $i <= 15; $i++) {
            AuditLog::query()->create([
                'actor_id' => $this->superAdmin->id,
                'action' => AuditAction::BranchAssigned,
                'subject_type' => User::class,
                'subject_id' => $this->staffUser->id,
                'target_user_id' => $this->staffUser->id,
                'branch_id' => $this->retailBranch->id,
                'reason' => "Sự kiện kiểm toán #{$i}",
                'occurred_at' => $baseTime->copy()->addMinutes($i),
            ]);
        }

        // Lấy trang đầu qua API cursor
        $batch1 = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.staff.audit-logs', ['staff' => $this->staffUser, 'per_page' => 10]));

        $batch1->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'action_value', 'action_label', 'occurred_at', 'actor_name', 'reason'],
                ],
                'next_cursor',
                'has_more',
            ]);

        $this->assertCount(10, $batch1->json('data'));
        $this->assertTrue($batch1->json('has_more'));
        $nextCursor = $batch1->json('next_cursor');
        $this->assertNotEmpty($nextCursor);

        // Lấy trang thứ 2 dùng con trỏ
        $batch2 = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.staff.audit-logs', [
                'staff' => $this->staffUser,
                'cursor' => $nextCursor,
                'per_page' => 10,
            ]));

        $batch2->assertOk();
        $this->assertCount(5, $batch2->json('data'));
        $this->assertFalse($batch2->json('has_more'));

        // Kiểm tra không có ID nào trùng lặp giữa 2 đợt
        $batch1Ids = collect($batch1->json('data'))->pluck('id')->all();
        $batch2Ids = collect($batch2->json('data'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($batch1Ids, $batch2Ids));

        // Thứ tự sắp xếp phải là mới nhất trước (occurred_at DESC, id DESC)
        $this->assertSame('Sự kiện kiểm toán #15', $batch1->json('data.0.reason'));
        $this->assertSame('Sự kiện kiểm toán #1', $batch2->json('data.4.reason'));
    }

    public function test_branch_audit_logs_cursor_pagination_endpoint(): void
    {
        $baseTime = Carbon::parse('2026-11-15 11:00:00');

        for ($i = 1; $i <= 12; $i++) {
            AuditLog::query()->create([
                'actor_id' => $this->superAdmin->id,
                'action' => AuditAction::BranchUpdated,
                'subject_type' => Branch::class,
                'subject_id' => $this->retailBranch->id,
                'branch_id' => $this->retailBranch->id,
                'reason' => "Cập nhật chi nhánh đợt #{$i}",
                'occurred_at' => $baseTime->copy()->addMinutes($i),
            ]);
        }

        // Kiểm tra view chi nhánh có panel Smart Scroll
        $viewResponse = $this->actingAs($this->superAdmin)->get(route('admin.branches.show', $this->retailBranch));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Nhật ký hoạt động gần đây');
        $viewResponse->assertSee('data-smart-scroll', false);

        // Gọi API tải thêm của chi nhánh
        $apiResponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.branches.audit-logs', ['branch' => $this->retailBranch, 'per_page' => 10]));

        $apiResponse->assertOk();
        $this->assertCount(10, $apiResponse->json('data'));
        $this->assertTrue($apiResponse->json('has_more'));

        $nextCursor = $apiResponse->json('next_cursor');
        $secondBatch = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.branches.audit-logs', [
                'branch' => $this->retailBranch,
                'cursor' => $nextCursor,
                'per_page' => 10,
            ]));

        $secondBatch->assertOk();
        $this->assertCount(2, $secondBatch->json('data'));
        $this->assertFalse($secondBatch->json('has_more'));
    }

    public function test_central_warehouse_show_renders_contextual_audits_and_drawer(): void
    {
        AuditLog::query()->create([
            'actor_id' => $this->superAdmin->id,
            'action' => AuditAction::BranchCreated,
            'subject_type' => Branch::class,
            'subject_id' => $this->centralWarehouse->id,
            'branch_id' => $this->centralWarehouse->id,
            'reason' => 'Khởi tạo kho trung tâm chuẩn',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.central-warehouse.show'));

        $response->assertOk();
        $response->assertSee('Nhật ký hoạt động gần đây');
        $response->assertSee('Khởi tạo kho trung tâm chuẩn');
        $response->assertSee('id="audit-detail-drawer"', false);
    }

    public function test_invalid_or_tampered_cursor_falls_back_gracefully_without_exception(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.staff.audit-logs', [
                'staff' => $this->staffUser,
                'cursor' => 'tampered_malformed_base64_string!!!',
            ]));

        $response->assertOk();
        $response->assertJsonStructure(['data', 'has_more']);
    }

    public function test_unauthorized_actors_are_denied_from_audit_endpoints(): void
    {
        $unauthenticated = $this->getJson(route('admin.staff.audit-logs', $this->staffUser));
        $unauthenticated->assertUnauthorized();

        $normalCustomer = User::factory()->create([
            'staff_access_status' => StaffAccessStatus::NotApplicable,
        ]);

        $forbidden = $this->actingAs($normalCustomer)->getJson(route('admin.staff.audit-logs', $this->staffUser));
        $forbidden->assertForbidden();
    }

    public function test_audit_responses_never_expose_sensitive_keys(): void
    {
        $audit = AuditLog::query()->create([
            'actor_id' => $this->superAdmin->id,
            'action' => AuditAction::StaffCreated,
            'subject_type' => User::class,
            'subject_id' => $this->staffUser->id,
            'target_user_id' => $this->staffUser->id,
            'branch_id' => $this->retailBranch->id,
            'reason' => 'Tạo nhân sự thử nghiệm bảo mật',
            'old_values' => [
                'password_hash' => '$2y$12$secretHashValueMustBeRedacted',
                'temporary_password' => 'PlainSecretTempPass',
            ],
            'new_values' => [
                'session_token' => 'TokenPayloadSecret123',
                'public_field' => 'Giá trị công khai an toàn',
            ],
            'occurred_at' => now(),
        ]);

        $detailResponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.audit-logs.show', $audit));

        $detailResponse->assertOk();
        $detailData = $detailResponse->json('data');

        $this->assertSame('[Đã ẩn bảo mật]', $detailData['old_values']['password_hash']);
        $this->assertSame('[Đã ẩn bảo mật]', $detailData['old_values']['temporary_password']);
        $this->assertSame('[Đã ẩn bảo mật]', $detailData['new_values']['session_token']);
        $this->assertSame('Giá trị công khai an toàn', $detailData['new_values']['public_field']);

        $content = $detailResponse->getContent();
        $this->assertStringNotContainsString('secretHashValueMustBeRedacted', $content);
        $this->assertStringNotContainsString('PlainSecretTempPass', $content);
        $this->assertStringNotContainsString('TokenPayloadSecret123', $content);
    }

    public function test_user_facing_headings_contain_no_technical_count_suffixes(): void
    {
        $staffShow = $this->actingAs($this->superAdmin)->get(route('admin.staff.show', $this->staffUser));
        $staffShow->assertDontSee('(Tối đa 10 sự kiện)');
    }

    private function createEffectiveSuperAdmin(): User
    {
        $superAdmin = (new BootstrapSuperAdminAction)->execute(
            name: 'Super Admin Scroll',
            email: 'admin.scroll.'.Str::random(6).'@example.com',
            plainPassword: 'StrongSuperAdminPass123!',
            plainPasswordConfirmation: 'StrongSuperAdminPass123!',
        );

        (new CompleteMandatoryPasswordChangeAction)->execute(
            user: $superAdmin,
            newPlainPassword: 'StrongSuperAdminPass123!Updated',
        );

        return $superAdmin->fresh();
    }
}
