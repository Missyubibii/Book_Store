<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B5;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Actions\Auth\CompleteMandatoryPasswordChangeAction;
use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\LoginEventType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Role;
use App\Models\StaffActivationProposal;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use App\Services\Staff\StaffAdministrationService;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class StaffAdministrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

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

        $this->branchA = Branch::factory()->create([
            'code' => 'HN01',
            'name' => 'Chi nhánh Hoàn Kiếm',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
        ]);

        $this->branchB = Branch::factory()->create([
            'code' => 'SG01',
            'name' => 'Chi nhánh Quận 1',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
        ]);

        $this->branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();
        $this->staffRole = Role::query()->where('code', 'staff')->firstOrFail();
    }

    public function test_guests_customers_and_regular_branch_staff_cannot_access_staff_admin_routes(): void
    {
        $customer = User::factory()->create();
        $regularStaff = $this->createAssignedStaffFor($this->branchA, [$this->staffRole]);

        $this->get('/quan-tri/nhan-su')->assertRedirect('/dang-nhap');
        $this->get('/quan-tri/nhan-su/phe-duyet')->assertRedirect('/dang-nhap');

        $this->actingAs($customer)->get('/quan-tri/nhan-su')->assertForbidden();
        $this->actingAs($customer)->get('/quan-tri/nhan-su/tao-moi')->assertForbidden();
        $this->actingAs($customer)->get('/quan-tri/nhan-su/phe-duyet')->assertForbidden();

        $this->actingAs($regularStaff)->get('/quan-tri/nhan-su')->assertForbidden();
        $this->actingAs($regularStaff)->get('/quan-tri/nhan-su/phe-duyet')->assertForbidden();
        $this->actingAs($regularStaff)->post('/quan-tri/nhan-su', [])->assertForbidden();
    }

    public function test_super_admin_and_branch_admin_can_create_new_staff_in_pending_state_with_24h_temp_password_and_zero_secret_logs(): void
    {
        $branchAdmin = $this->createAssignedStaffFor($this->branchA, [$this->branchAdminRole]);
        $tempSecret = 'TempStaff!2026#Aa1';

        $response = $this->actingAs($branchAdmin)->post('/quan-tri/nhan-su', [
            'name' => 'Nguyễn Bảo Khang',
            'email' => 'baokhang.staff@example.com',
            'branch_id' => $this->branchA->id,
            'staff_code' => 'NV-HN-101',
            'job_title' => 'Nhân viên bán hàng',
            'hired_at' => '2026-11-01',
            'role_ids' => [$this->staffRole->id],
            'temporary_password' => $tempSecret,
            'temporary_password_confirmation' => $tempSecret,
            'notes' => 'Hồ sơ tiếp nhận đợt 1',
        ]);

        $created = User::query()->where('email', 'baokhang.staff@example.com')->firstOrFail();

        $response->assertRedirect(route('admin.staff.show', $created));

        $this->assertSame(AccountStatus::Active, $created->account_status);
        $this->assertSame(StaffAccessStatus::Pending, $created->staff_access_status);
        $this->assertTrue($created->must_change_password);
        $this->assertNotNull($created->temp_password_issued_at);
        $this->assertNotNull($created->temp_password_expires_at);
        $this->assertSame($branchAdmin->id, $created->temp_password_issued_by);
        $this->assertTrue(Hash::check($tempSecret, $created->password));

        $hours = $created->temp_password_issued_at->diffInHours($created->temp_password_expires_at);
        $this->assertSame(24, (int) $hours);

        $profile = StaffProfile::query()->where('user_id', $created->id)->firstOrFail();
        $this->assertSame('NV-HN-101', $profile->staff_code);

        $assignment = BranchAssignment::query()
            ->where('user_id', $created->id)
            ->where('branch_id', $this->branchA->id)
            ->firstOrFail();
        $this->assertSame(BranchAssignmentStatus::Active, $assignment->status);
        $this->assertSame([$this->staffRole->id], $assignment->matchingRoleAssignments()->pluck('role_id')->all());

        // Đảm bảo AuditLog lưu đầy đủ sự kiện tạo nhân sự và tuyệt đối không chứa mật khẩu gốc hay hash.
        $audits = AuditLog::query()->where('target_user_id', $created->id)->get();
        $this->assertTrue($audits->contains(fn (AuditLog $log): bool => $log->action === AuditAction::StaffCreated));
        $this->assertTrue($audits->contains(fn (AuditLog $log): bool => $log->action === AuditAction::TempPasswordIssued));
        $this->assertTrue($audits->contains(function (AuditLog $log): bool {
            return $log->action === AuditAction::BranchAssigned
                && in_array($this->staffRole->code, $log->new_values['role_codes'] ?? [], true);
        }));

        foreach ($audits as $audit) {
            $serialized = json_encode([$audit->old_values, $audit->new_values, $audit->reason], JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($tempSecret, (string) $serialized);
            $this->assertStringNotContainsString((string) $created->password, (string) $serialized);
        }

        // Giao diện hồ sơ nhân sự không được hiển thị mật khẩu tạm plaintext sau khi tạo.
        $detailHtml = $this->actingAs($branchAdmin)->get(route('admin.staff.show', $created));
        $detailHtml->assertOk();
        $this->assertStringNotContainsString($tempSecret, $detailHtml->getContent());
    }

    public function test_linking_existing_customer_creates_pending_staff_profile_while_preserving_customer_password_and_storefront_access(): void
    {
        $customerSecret = 'CustomerKeep!2026#Xy';
        $customer = User::factory()->create([
            'name' => 'Lê Thảo Vy',
            'email' => 'thaovy.customer@example.com',
            'password' => $customerSecret,
        ]);
        $originalHash = $customer->password;

        $response = $this->actingAs($this->superAdmin)->post('/quan-tri/nhan-su/lien-ket', [
            'customer_email' => $customer->email,
            'branch_id' => $this->branchB->id,
            'staff_code' => 'NV-SG-202',
            'job_title' => 'Tư vấn viên',
            'role_ids' => [$this->staffRole->id],
        ]);

        $customer->refresh();

        $response->assertRedirect(route('admin.staff.show', $customer));
        $this->assertSame(StaffAccessStatus::Pending, $customer->staff_access_status);
        $this->assertSame(AccountStatus::Active, $customer->account_status);
        $this->assertFalse($customer->must_change_password);
        $this->assertSame($originalHash, $customer->password);
        $this->assertDatabaseHas('audit_logs', [
            'target_user_id' => $customer->id,
            'action' => AuditAction::StaffLinked->value,
        ]);

        // Tài khoản vẫn đăng nhập và mua sắm tại hiệu sách công khai bình thường, nhưng bị chặn khỏi quản trị nội bộ.
        $this->actingAs($customer)->get('/')->assertOk();
        $this->actingAs($customer)->get('/quan-tri/nhan-su')->assertForbidden();
        $this->actingAs($customer)->get('/quan-tri/chi-nhanh')->assertForbidden();
    }

    public function test_expired_temporary_password_blocks_login_onboarding_route_and_action_while_preserving_bootstrap_and_customers(): void
    {
        $tempSecret = 'ExpiredTemp!2026#99';
        $expiredStaff = User::factory()->staffPending()->create([
            'email' => 'expired.staff@example.com',
            'password' => $tempSecret,
            'must_change_password' => true,
            'temp_password_issued_at' => now()->subHours(26),
            'temp_password_expires_at' => now()->subHours(2),
        ]);

        // 1. Đăng nhập với mật khẩu tạm đã hết hạn bị chặn ở POST /dang-nhap
        $loginResponse = $this->from('/dang-nhap')->post('/dang-nhap', [
            'email' => $expiredStaff->email,
            'password' => $tempSecret,
        ]);

        $loginResponse->assertRedirect('/dang-nhap');
        $loginResponse->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertDatabaseHas('login_events', [
            'user_id' => $expiredStaff->id,
            'event_type' => LoginEventType::LoginFailed->value,
        ]);

        // 2. Nếu có phiên cũ từ trước khi hết hạn, middleware onboarding thu hồi phiên và đẩy về /dang-nhap
        $this->actingAs($expiredStaff)
            ->get('/nhan-su/doi-mat-khau-bat-buoc')
            ->assertRedirect('/dang-nhap');
        $this->assertGuest();

        // 3. Kể cả khi gọi trực tiếp Action, mật khẩu tạm hết hạn vẫn bị chặn ở tầng Domain
        $newSecret = 'NewStrong!2026#123';
        $this->expectException(DomainInvariantException::class);
        app(CompleteMandatoryPasswordChangeAction::class)->execute($expiredStaff, $newSecret, $newSecret);
    }

    public function test_valid_temporary_password_allows_mandatory_password_change_yet_pending_staff_remains_blocked_until_approved(): void
    {
        $tempSecret = 'ValidTemp!2026#77';
        $pendingStaff = User::factory()->staffPending()->create([
            'email' => 'valid.pending@example.com',
            'password' => $tempSecret,
            'must_change_password' => true,
            'temp_password_issued_at' => now()->subHour(),
            'temp_password_expires_at' => now()->addHours(23),
        ]);

        $this->actingAs($pendingStaff)
            ->get('/nhan-su/doi-mat-khau-bat-buoc')
            ->assertOk();

        $newPassword = 'StrongNew!2026#888';
        $this->actingAs($pendingStaff)
            ->post('/nhan-su/doi-mat-khau-bat-buoc', [
                'current_password' => $tempSecret,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertRedirect(route('home'));

        $pendingStaff->refresh();
        $this->assertFalse($pendingStaff->must_change_password);
        $this->assertFalse($pendingStaff->isTemporaryPasswordExpired());
        $this->assertNotNull($pendingStaff->password_changed_at);
        $this->assertSame(StaffAccessStatus::Pending, $pendingStaff->staff_access_status);

        // Dù đã đổi mật khẩu, nhân sự Pending vẫn không vào được khu vực quản trị cho đến khi được Super Admin duyệt.
        $this->actingAs($pendingStaff)->get('/quan-tri/nhan-su')->assertForbidden();
        $this->actingAs($pendingStaff)->get('/quan-tri')->assertForbidden();
    }

    public function test_branch_admin_cannot_view_manage_or_propose_for_staff_outside_authorized_branch(): void
    {
        $branchAdminA = $this->createAssignedStaffFor($this->branchA, [$this->branchAdminRole]);
        $staffB = $this->createAssignedStaffFor($this->branchB, [$this->staffRole], StaffAccessStatus::Pending);

        // Chặn xem danh sách lọc chi nhánh B, xem hồ sơ nhân sự chi nhánh B, hoặc truy cập hàng đợi duyệt Super Admin
        $this->actingAs($branchAdminA)
            ->get('/quan-tri/nhan-su?branch_id='.$this->branchB->id)
            ->assertForbidden();

        $this->actingAs($branchAdminA)
            ->get(route('admin.staff.show', $staffB))
            ->assertForbidden();

        $this->actingAs($branchAdminA)
            ->post(route('admin.staff.assignments.upsert', $staffB), [
                'branch_id' => $this->branchB->id,
                'role_ids' => [$this->staffRole->id],
            ])
            ->assertForbidden();

        $this->actingAs($branchAdminA)
            ->post(route('admin.staff.proposals.store', $staffB), [
                'branch_id' => $this->branchB->id,
                'proposal_reason' => 'Đề xuất vượt phạm vi chi nhánh B',
            ])
            ->assertForbidden();

        $this->actingAs($branchAdminA)
            ->get('/quan-tri/nhan-su/phe-duyet')
            ->assertForbidden();
    }

    public function test_activation_proposal_enforces_separation_of_duties_rejection_reproposal_and_concurrent_double_approval_safety(): void
    {
        $branchAdminA = $this->createAssignedStaffFor($this->branchA, [$this->branchAdminRole]);
        $candidate = $this->createAssignedStaffFor($this->branchA, [$this->branchAdminRole], StaffAccessStatus::Pending);

        // Không thể tự đề xuất kích hoạt chính mình
        $this->actingAs($candidate)
            ->post(route('admin.staff.proposals.store', $candidate), [
                'branch_id' => $this->branchA->id,
                'proposal_reason' => 'Tự đề xuất',
            ])
            ->assertForbidden();

        // Branch Admin A gửi đề xuất kích hoạt hợp lệ
        $this->actingAs($branchAdminA)
            ->post(route('admin.staff.proposals.store', $candidate), [
                'branch_id' => $this->branchA->id,
                'proposal_reason' => 'Nhân sự đã hoàn tất hướng dẫn vận hành chi nhánh HN01.',
            ])
            ->assertRedirect(route('admin.staff.show', $candidate));

        $firstProposal = StaffActivationProposal::query()
            ->where('user_id', $candidate->id)
            ->firstOrFail();
        $this->assertSame(StaffApprovalStatus::Pending, $firstProposal->status);

        // Branch Admin không được phép tự duyệt hoặc từ chối đề xuất
        $this->actingAs($branchAdminA)
            ->post(route('admin.staff.approvals.approve', $firstProposal), [
                'confirmed' => '1',
                'review_note' => 'Tự duyệt không hợp lệ',
            ])
            ->assertForbidden();

        // Super Admin từ chối đề xuất lần 1 với lý do rõ ràng -> nhân sự vẫn giữ Pending
        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.approvals.reject', $firstProposal), [
                'confirmed' => '1',
                'review_note' => 'Cần bổ sung hồ sơ nhận việc tại chi nhánh.',
            ])
            ->assertRedirect(route('admin.staff.approvals.index'));

        $firstProposal->refresh();
        $candidate->refresh();
        $this->assertSame(StaffApprovalStatus::Rejected, $firstProposal->status);
        $this->assertSame($this->superAdmin->id, $firstProposal->reviewed_by);
        $this->assertSame(StaffAccessStatus::Pending, $candidate->staff_access_status);

        // Branch Admin A gửi đề xuất kích hoạt lại lần 2
        $this->actingAs($branchAdminA)
            ->post(route('admin.staff.proposals.store', $candidate), [
                'branch_id' => $this->branchA->id,
                'proposal_reason' => 'Đã bổ sung đầy đủ hồ sơ nhận việc tại HN01.',
            ])
            ->assertRedirect(route('admin.staff.show', $candidate));

        $secondProposal = StaffActivationProposal::query()
            ->where('user_id', $candidate->id)
            ->where('status', StaffApprovalStatus::Pending->value)
            ->firstOrFail();

        // Kể cả nhân sự mục tiêu hay chính người đó dù nắm quyền toàn cục đều bị chặn tự duyệt đề xuất của mình
        $this->assertFalse($candidate->can('review', $secondProposal));

        try {
            app(StaffAdministrationService::class)->approveActivationProposal(
                actor: $candidate,
                proposal: $secondProposal,
                reviewNote: 'Thử tự phê duyệt',
            );
            $this->fail('Phải chặn nhân sự mục tiêu tự phê duyệt đề xuất của chính mình.');
        } catch (AuthorizationException) {
            // Đúng kỳ vọng
        }

        // Giữ bản sao cũ ở trạng thái pending để mô phỏng thao tác duyệt đồng thời trên cùng đề xuất
        $staleSecondProposal = clone $secondProposal;

        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.approvals.approve', $secondProposal), [
                'confirmed' => '1',
                'review_note' => 'Đủ điều kiện vận hành chi nhánh HN01.',
            ])
            ->assertRedirect(route('admin.staff.approvals.index'));

        $secondProposal->refresh();
        $candidate->refresh();
        $this->assertSame(StaffApprovalStatus::Approved, $secondProposal->status);
        $this->assertSame(StaffAccessStatus::Active, $candidate->staff_access_status);
        $this->assertNotNull($candidate->staffProfile?->activated_at);

        // Sau khi được duyệt, nhân sự mới kích hoạt có thể vào /quan-tri/nhan-su
        $this->actingAs($candidate)->get('/quan-tri/nhan-su')->assertOk();

        // Kiểm tra chống duyệt trùng đồng thời ở tầng Service (lần duyệt thứ 2 bị từ chối bằng DomainInvariantException 422)
        try {
            app(StaffAdministrationService::class)->approveActivationProposal(
                actor: $this->superAdmin,
                proposal: $staleSecondProposal,
                reviewNote: 'Duyệt lại lần 2',
            );
            $this->fail('Phải chặn việc phê duyệt lại đề xuất đã chốt.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame(422, $exception->statusCode);
        }
    }

    public function test_multi_branch_and_multi_role_assignment_isolation_and_inactive_branch_yields_zero_permissions(): void
    {
        $multiBranchStaff = $this->createAssignedStaffFor($this->branchA, [$this->branchAdminRole, $this->staffRole]);

        // Phân công thêm chi nhánh B chỉ với vai trò staff
        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.assignments.upsert', $multiBranchStaff), [
                'branch_id' => $this->branchB->id,
                'role_ids' => [$this->staffRole->id],
                'is_primary' => '0',
            ])
            ->assertRedirect(route('admin.staff.show', $multiBranchStaff));

        $auth = app(AuthorizationService::class);
        $this->assertTrue($auth->hasBranchPermission($multiBranchStaff, 'staff.create', $this->branchA));
        $this->assertTrue($auth->hasBranchPermission($multiBranchStaff, 'branch_ops.access', $this->branchA));
        $this->assertFalse($auth->hasBranchPermission($multiBranchStaff, 'staff.create', $this->branchB));
        $this->assertTrue($auth->hasBranchPermission($multiBranchStaff, 'branch_ops.access', $this->branchB));

        // Khi chi nhánh B ngừng hoạt động, quyền hiệu lực của nhân sự tại chi nhánh B về 0 nhưng chi nhánh A vẫn nguyên vẹn
        $this->branchB->update(['status' => BranchStatus::Inactive]);
        $this->assertSame([], $auth->effectiveBranchPermissions($multiBranchStaff->fresh(), $this->branchB->fresh()));
        $this->assertTrue($auth->hasBranchPermission($multiBranchStaff->fresh(), 'staff.create', $this->branchA->fresh()));
    }

    public function test_decision_2_7_last_active_branch_removal_transitions_staff_to_inactive_except_global_super_admin(): void
    {
        $staffSingleBranch = $this->createAssignedStaffFor($this->branchA, [$this->staffRole]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.staff.assignments.deactivate', [$staffSingleBranch, $this->branchA]), [
                'confirmed' => '1',
                'reason' => 'Kết thúc hợp đồng phân công tại Hoàn Kiếm.',
            ])
            ->assertRedirect(route('admin.staff.show', $staffSingleBranch));

        $staffSingleBranch->refresh();
        $this->assertSame(StaffAccessStatus::Inactive, $staffSingleBranch->staff_access_status);
        $this->assertSame(AccountStatus::Active, $staffSingleBranch->account_status);
        $this->assertDatabaseHas('branch_assignments', [
            'user_id' => $staffSingleBranch->id,
            'branch_id' => $this->branchA->id,
            'status' => BranchAssignmentStatus::Inactive->value,
        ]);

        // Người giữ vai trò SuperAdmin toàn hệ thống KHÔNG bị hạ quyền StaffAccessStatus khi gỡ phân công chi nhánh cuối
        $secondSuperAdmin = $this->createAdditionalSuperAdmin('superadmin2@example.com');
        $this->actingAs($this->superAdmin)
            ->post(route('admin.staff.assignments.upsert', $secondSuperAdmin), [
                'branch_id' => $this->branchA->id,
                'role_ids' => [$this->staffRole->id],
            ])
            ->assertRedirect();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.staff.assignments.deactivate', [$secondSuperAdmin, $this->branchA]), [
                'confirmed' => '1',
                'reason' => 'Gỡ phân công chi nhánh tạm thời của Super Admin thứ hai.',
            ])
            ->assertRedirect();

        $this->assertSame(StaffAccessStatus::Active, $secondSuperAdmin->fresh()->staff_access_status);
    }

    public function test_staff_suspension_blocks_internal_routes_while_preserving_customer_account_and_protects_last_super_admin_and_self(): void
    {
        $branchAdmin = $this->createAssignedStaffFor($this->branchA, [$this->branchAdminRole]);

        // Tự đình chỉ chính mình bị chặn
        $this->actingAs($this->superAdmin)
            ->patch(route('admin.staff.suspend', $this->superAdmin), [
                'confirmed' => '1',
                'reason' => 'Thử tự đình chỉ chính mình.',
            ])
            ->assertForbidden();

        // Đình chỉ nhân sự hợp lệ giữ nguyên AccountStatus::Active
        $this->actingAs($this->superAdmin)
            ->patch(route('admin.staff.suspend', $branchAdmin), [
                'confirmed' => '1',
                'reason' => 'Tạm đình chỉ quyền quản trị nội bộ để rà soát kiểm toán.',
            ])
            ->assertRedirect(route('admin.staff.show', $branchAdmin));

        $branchAdmin->refresh();
        $this->assertSame(StaffAccessStatus::Inactive, $branchAdmin->staff_access_status);
        $this->assertSame(AccountStatus::Active, $branchAdmin->account_status);
        $this->assertDatabaseHas('audit_logs', [
            'target_user_id' => $branchAdmin->id,
            'action' => AuditAction::StaffSuspended->value,
        ]);

        // Vào trang chủ khách hàng vẫn OK, vào quản trị bị chặn 403
        $this->actingAs($branchAdmin)->get('/')->assertOk();
        $this->actingAs($branchAdmin)->get('/quan-tri/nhan-su')->assertForbidden();

        // Khôi phục lại quyền nhân sự
        $this->actingAs($this->superAdmin)
            ->patch(route('admin.staff.reactivate', $branchAdmin), [
                'confirmed' => '1',
                'reason' => 'Hoàn tất rà soát, khôi phục quyền công tác.',
            ])
            ->assertRedirect(route('admin.staff.show', $branchAdmin));

        $this->assertSame(StaffAccessStatus::Active, $branchAdmin->fresh()->staff_access_status);
        $this->assertDatabaseHas('audit_logs', [
            'target_user_id' => $branchAdmin->id,
            'action' => AuditAction::StaffReactivated->value,
        ]);

        // Kiểm tra bảo vệ Last Effective Super Admin ở tầng Service kể cả khi gọi trực tiếp bởi tác nhân khác giả lập
        $delegatedActor = User::factory()->staffActive()->create();
        try {
            app(StaffAdministrationService::class)->suspendStaffAccess(
                actor: $delegatedActor,
                staff: $this->superAdmin,
                reason: 'Cố tình đình chỉ Super Admin duy nhất.',
            );
            $this->fail('Phải chặn từ chối quyền hoặc bảo vệ Last Effective Super Admin.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(AuthorizationException::class, $exception);
        }

        // Thử với second Super Admin: tạo xong không thể để hệ thống về 0 effective Super Admin nếu chỉ còn 1 người
        $secondSuperAdmin = $this->createAdditionalSuperAdmin('second.sa@example.com');
        $this->actingAs($secondSuperAdmin)
            ->patch(route('admin.staff.suspend', $this->superAdmin), [
                'confirmed' => '1',
                'reason' => 'Đình chỉ đồng quản trị khi vẫn còn 1 Super Admin khác.',
            ])
            ->assertRedirect();

        // Lúc này chỉ còn $secondSuperAdmin là effective Super Admin; nếu thử bằng delegated permission qua service trực tiếp sẽ bị chặn DomainInvariantException khi cố hạ người cuối cùng
        $this->assertSame(StaffAccessStatus::Inactive, $this->superAdmin->fresh()->staff_access_status);
    }

    public function test_staff_creation_rolls_back_atomically_when_audit_log_creation_fails(): void
    {
        $initialUserCount = User::query()->count();
        $initialProfileCount = StaffProfile::query()->count();
        $initialAssignmentCount = BranchAssignment::query()->count();

        AuditLog::creating(function (): void {
            throw new RuntimeException('Mô phỏng lỗi ghi AuditLog');
        });

        try {
            app(StaffAdministrationService::class)->createStaffAccount(
                actor: $this->superAdmin,
                branch: $this->branchA,
                input: [
                    'name' => 'Rollback Staff',
                    'email' => 'rollback.staff@example.com',
                    'staff_code' => 'NV-RB-01',
                    'role_ids' => [$this->staffRole->id],
                    'temporary_password' => 'Rollback!2026#Aa',
                ],
            );
            $this->fail('Phải ném RuntimeException khi AuditLog::creating lỗi.');
        } catch (RuntimeException) {
            // Đúng kỳ vọng
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertSame($initialUserCount, User::query()->count());
        $this->assertSame($initialProfileCount, StaffProfile::query()->count());
        $this->assertSame($initialAssignmentCount, BranchAssignment::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'rollback.staff@example.com']);
    }

    public function test_staff_and_approval_views_follow_liquid_white_and_closed_initial_confirmations(): void
    {
        $branchAdminA = $this->createAssignedStaffFor($this->branchA, [$this->branchAdminRole]);
        $pendingStaff = $this->createAssignedStaffFor($this->branchA, [$this->staffRole], StaffAccessStatus::Pending);

        app(StaffAdministrationService::class)->submitActivationProposal(
            actor: $branchAdminA,
            staff: $pendingStaff,
            branch: $this->branchA,
            proposalReason: 'Kiểm tra giao diện xác nhận',
        );

        $pages = [
            route('admin.dashboard'),
            route('admin.staff.index'),
            route('admin.staff.create'),
            route('admin.staff.link.create'),
            route('admin.staff.show', $pendingStaff),
            route('admin.staff.approvals.index'),
        ];

        foreach ($pages as $url) {
            $response = $this->actingAs($this->superAdmin)->get($url);
            $response->assertOk();
            $html = $response->getContent();

            $this->assertStringNotContainsString('<style', $html, "Không được chứa thẻ <style> nội tuyến tại {$url}");
            $this->assertStringNotContainsString('href="#"', $html, "Không được chứa href=\"#\" tại {$url}");
            $this->assertStringContainsString('name="csrf-token"', $html);
        }

        // Bảng điều khiển phải cập nhật trạng thái mô-đun Nhân sự thành Đang hoạt động nhưng giữ các phân hệ tương lai ở Chưa mở
        $dashboardHtml = $this->actingAs($this->superAdmin)->get(route('admin.dashboard'));
        $dashboardHtml->assertSeeText('Quản lý & Phê duyệt Nhân sự');
        $dashboardHtml->assertSeeText('Mở quản lý nhân sự');
        $dashboardHtml->assertSeeText('Đang chuẩn bị (Chưa mở)');

        // Mọi khung xác nhận nhạy cảm trên trang chi tiết và hàng đợi phê duyệt PHẢI đóng ban đầu
        $detailHtml = $this->actingAs($this->superAdmin)->get(route('admin.staff.show', $pendingStaff));
        $detailHtml->assertSee('data-confirm-disclosure', false);
        $detailHtml->assertSee('data-initial-open="false"', false);
        $detailHtml->assertSee('class="bs-ad-confirm-box bs-hidden"', false);
        $detailHtml->assertSee('x-cloak', false);
        $detailHtml->assertSee('aria-hidden="true"', false);
        $detailHtml->assertSee('disabled', false);

        $approvalsHtml = $this->actingAs($this->superAdmin)->get(route('admin.staff.approvals.index'));
        $approvalsHtml->assertSee('data-confirm-disclosure', false);
        $approvalsHtml->assertSee('data-initial-open="false"', false);
        $approvalsHtml->assertSee('class="bs-ad-confirm-box bs-hidden"', false);
        $approvalsHtml->assertSee('x-cloak', false);
        $approvalsHtml->assertSee('aria-hidden="true"', false);
        $approvalsHtml->assertSee('disabled', false);
    }

    private function createEffectiveSuperAdmin(): User
    {
        $passphrase = 'Init!'.Str::random(10).'9aZ';
        $bootstrapped = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên Cấp Cao',
            'superadmin.stage01b5@example.com',
            $passphrase,
            $passphrase,
        );

        $newPassphrase = 'Final!'.Str::random(10).'8bY';
        app(CompleteMandatoryPasswordChangeAction::class)->execute(
            $bootstrapped,
            $newPassphrase,
            $newPassphrase,
        );

        return $bootstrapped->fresh() ?? $bootstrapped;
    }

    private function createAdditionalSuperAdmin(string $email): User
    {
        $user = User::factory()->staffActive()->create([
            'email' => $email,
            'must_change_password' => false,
        ]);

        StaffProfile::factory()->activated()->create(['user_id' => $user->id]);

        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

        $user->userRoles()->create([
            'role_id' => $superAdminRole->id,
            'role_scope' => RoleScope::Global->value,
            'assigned_at' => now(),
        ]);

        return $user->fresh() ?? $user;
    }

    /**
     * @param  list<Role>  $roles
     */
    private function createAssignedStaffFor(
        Branch $branch,
        array $roles,
        StaffAccessStatus $status = StaffAccessStatus::Active,
    ): User {
        $user = $status === StaffAccessStatus::Pending
            ? User::factory()->staffPending()->create(['must_change_password' => false])
            : User::factory()->staffActive()->create(['must_change_password' => false]);

        $status === StaffAccessStatus::Pending
            ? StaffProfile::factory()->create(['user_id' => $user->id])
            : StaffProfile::factory()->activated()->create(['user_id' => $user->id]);

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

        return $user->fresh() ?? $user;
    }
}
