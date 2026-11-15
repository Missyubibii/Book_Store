<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B4;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Actions\Auth\CompleteMandatoryPasswordChangeAction;
use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
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
use App\Services\Branches\BranchAdministrationService;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BranchAndWarehouseAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Branch $centralWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        $this->centralWarehouse = Branch::query()
            ->where('type', BranchType::CentralWarehouse->value)
            ->firstOrFail();

        $this->superAdmin = $this->createEffectiveSuperAdmin();
    }

    public function test_guests_and_unauthorized_accounts_cannot_access_branch_or_warehouse_routes(): void
    {
        $retailBranch = Branch::factory()->retailBranch()->create([
            'code' => 'HN_TEST_01',
            'name' => 'Chi nhánh Test 01',
        ]);

        // 1. Khách chưa đăng nhập -> chuyển hướng về trang đăng nhập
        $this->get('/quan-tri/chi-nhanh')->assertRedirect('/dang-nhap');
        $this->get('/quan-tri/chi-nhanh/tao-moi')->assertRedirect('/dang-nhap');
        $this->post('/quan-tri/chi-nhanh', [])->assertRedirect('/dang-nhap');
        $this->get("/quan-tri/chi-nhanh/{$retailBranch->id}")->assertRedirect('/dang-nhap');
        $this->patch("/quan-tri/chi-nhanh/{$retailBranch->id}", [])->assertRedirect('/dang-nhap');
        $this->patch("/quan-tri/chi-nhanh/{$retailBranch->id}/trang-thai", [])->assertRedirect('/dang-nhap');
        $this->get('/quan-tri/kho-trung-tam')->assertRedirect('/dang-nhap');

        // 2. Khách hàng thông thường -> 403
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/quan-tri/chi-nhanh')->assertForbidden();
        $this->actingAs($customer)->get('/quan-tri/kho-trung-tam')->assertForbidden();

        // 3. Nhân sự pending và inactive -> 403
        $pendingStaff = User::factory()->staffPending()->create();
        $inactiveStaff = User::factory()->staffInactive()->create();

        $this->actingAs($pendingStaff)->get('/quan-tri/chi-nhanh')->assertForbidden();
        $this->actingAs($inactiveStaff)->get('/quan-tri/chi-nhanh')->assertForbidden();
        $this->actingAs($pendingStaff)->get('/quan-tri/kho-trung-tam')->assertForbidden();

        // 4. Nhân sự active nhưng còn bắt buộc đổi mật khẩu -> bị `staff.active` chặn 403 trên route quản trị,
        // trong khi vẫn truy cập được trang đổi mật khẩu onboarding bắt buộc (`staff.onboarding`).
        $onboardingStaff = User::factory()->staffActive()->mustChangePassword()->create();
        $this->actingAs($onboardingStaff)
            ->get('/quan-tri/chi-nhanh')
            ->assertForbidden();
        $this->actingAs($onboardingStaff)
            ->get(route('staff.onboarding.password.edit'))
            ->assertOk();

        // 5. Branch Admin chỉ có quyền ở một chi nhánh -> bị chặn ở quản trị chi nhánh toàn cục
        $branchAdmin = $this->createBranchAdminFor($retailBranch);
        $this->actingAs($branchAdmin)->get('/quan-tri/chi-nhanh')->assertForbidden();
        $this->actingAs($branchAdmin)->get('/quan-tri/chi-nhanh/tao-moi')->assertForbidden();
        $this->actingAs($branchAdmin)->post('/quan-tri/chi-nhanh', [
            'code' => 'UNAUTHORIZED_01',
            'name' => 'Không được phép',
        ])->assertForbidden();
        $this->actingAs($branchAdmin)->get("/quan-tri/chi-nhanh/{$retailBranch->id}")->assertForbidden();
        $this->actingAs($branchAdmin)->patch("/quan-tri/chi-nhanh/{$retailBranch->id}", [
            'name' => 'Đổi trái phép',
            'status' => BranchStatus::Inactive->value,
        ])->assertForbidden();
        $this->actingAs($branchAdmin)->get('/quan-tri/kho-trung-tam')->assertForbidden();

        // 6. Super Admin bị đình chỉ -> thu hồi phiên và chuyển hướng về đăng nhập
        $this->superAdmin->forceFill(['account_status' => AccountStatus::Suspended])->save();
        $this->actingAs($this->superAdmin)->get('/quan-tri/chi-nhanh')->assertRedirect(route('login'));
    }

    public function test_branch_policy_maintains_strict_cross_branch_isolation(): void
    {
        $branchA = Branch::factory()->retailBranch()->create(['code' => 'HN_A', 'name' => 'Chi nhánh A']);
        $branchB = Branch::factory()->retailBranch()->create(['code' => 'SG_B', 'name' => 'Chi nhánh B']);

        $branchAdminA = $this->createBranchAdminFor($branchA);

        $this->assertFalse(Gate::forUser($branchAdminA)->allows('viewAny', Branch::class));
        $this->assertTrue(Gate::forUser($branchAdminA)->allows('view', $branchA));
        $this->assertFalse(Gate::forUser($branchAdminA)->allows('view', $branchB));
        $this->assertFalse(Gate::forUser($branchAdminA)->allows('create', Branch::class));
        $this->assertFalse(Gate::forUser($branchAdminA)->allows('update', $branchA));
        $this->assertFalse(Gate::forUser($branchAdminA)->allows('update', $branchB));
    }

    public function test_explicit_global_branch_permissions_enforce_granular_access_without_super_admin(): void
    {
        $retailBranch = Branch::factory()->retailBranch()->create([
            'code' => 'DN_01',
            'name' => 'Chi nhánh Đà Nẵng',
        ]);

        // Cấp vai trò global chỉ có quyền `branches.view`
        $viewer = $this->createGlobalStaffWithPermissions(['branches.view'], 'branch_viewer');

        $this->actingAs($viewer)->get('/quan-tri')->assertForbidden();
        $this->actingAs($viewer)->get('/quan-tri/chi-nhanh')->assertOk();
        $this->actingAs($viewer)->get('/quan-tri/kho-trung-tam')->assertOk();
        $this->actingAs($viewer)->get("/quan-tri/chi-nhanh/{$retailBranch->id}")->assertOk()
            ->assertDontSeeText('Cập nhật thông tin chi nhánh');

        // Bị chặn mọi thao tác tạo và cập nhật
        $this->actingAs($viewer)->get('/quan-tri/chi-nhanh/tao-moi')->assertForbidden();
        $this->actingAs($viewer)->post('/quan-tri/chi-nhanh', [
            'code' => 'DN_02',
            'name' => 'Chi nhánh Đà Nẵng 2',
        ])->assertForbidden();
        $this->actingAs($viewer)->patch("/quan-tri/chi-nhanh/{$retailBranch->id}", [
            'name' => 'Sửa trái phép',
            'status' => BranchStatus::Active->value,
        ])->assertForbidden();

        // Service cũng chặn trực tiếp nếu thiếu quyền
        $service = app(BranchAdministrationService::class);
        $this->expectException(AuthorizationException::class);
        $service->createRetailBranch($viewer, [
            'code' => 'DN_02',
            'name' => 'Chi nhánh Đà Nẵng 2',
        ]);
    }

    public function test_super_admin_can_list_search_filter_create_update_and_toggle_retail_branches_with_audit_logs(): void
    {
        // Ban đầu chưa có chi nhánh bán lẻ nào -> hiển thị empty state trung thực bằng tiếng Việt
        $emptyResponse = $this->actingAs($this->superAdmin)->get('/quan-tri/chi-nhanh');
        $emptyResponse->assertOk()
            ->assertSeeText('Quản lý Chi nhánh Bán lẻ')
            ->assertSeeText('Chưa có chi nhánh bán lẻ nào trong hệ thống')
            ->assertDontSeeText('Kho Trung tâm Book_Store');

        // 1. Tạo chi nhánh bán lẻ mới (mã nhập chữ thường có khoảng trắng -> tự chuẩn hóa chữ in hoa)
        $createPage = $this->actingAs($this->superAdmin)->get('/quan-tri/chi-nhanh/tao-moi');
        $createPage->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSeeText('Tạo Chi nhánh Bán lẻ');

        $storeResponse = $this->actingAs($this->superAdmin)->post('/quan-tri/chi-nhanh', [
            'code' => '  hn_cau_giay  ',
            'name' => 'Book_Store Cầu Giấy',
            'status' => BranchStatus::Active->value,
            'phone' => '024 3888 1234',
            'address' => 'Số 102 Xuân Thủy, Cầu Giấy, Hà Nội',
        ]);

        $createdBranch = Branch::query()->where('code', 'HN_CAU_GIAY')->first();
        $this->assertNotNull($createdBranch);
        $this->assertSame(BranchType::RetailBranch, $createdBranch->type);
        $this->assertSame(BranchStatus::Active, $createdBranch->status);
        $this->assertSame('Book_Store Cầu Giấy', $createdBranch->name);

        $storeResponse->assertRedirect(route('admin.branches.show', $createdBranch));

        // Mở trang chi tiết sau chuyển hướng để tiêu thụ flash message như trình duyệt thật
        $this->actingAs($this->superAdmin)
            ->get(route('admin.branches.show', $createdBranch))
            ->assertOk()
            ->assertSeeText('Book_Store Cầu Giấy');

        // Kiểm tra AuditLog tạo mới
        $createAudit = AuditLog::query()
            ->where('action', AuditAction::BranchCreated->value)
            ->where('branch_id', $createdBranch->id)
            ->first();
        $this->assertNotNull($createAudit);
        $this->assertSame($this->superAdmin->id, $createAudit->actor_id);
        $this->assertSame(Branch::class, $createAudit->subject_type);
        $this->assertSame($createdBranch->id, $createAudit->subject_id);
        $this->assertSame('HN_CAU_GIAY', $createAudit->new_values['code']);
        $this->assertSame('retail_branch', $createAudit->new_values['type']);

        // Tạo thêm chi nhánh thứ hai ở trạng thái ngừng hoạt động để kiểm tra tìm kiếm & lọc
        Branch::factory()->retailBranch()->inactive()->create([
            'code' => 'HCM_Q1',
            'name' => 'Book_Store Quận 1',
            'address' => '15 Nguyễn Huệ, TP.HCM',
            'phone' => '028 3999 0001',
        ]);

        $filteredByStatus = $this->actingAs($this->superAdmin)->get('/quan-tri/chi-nhanh?status=inactive');
        $filteredByStatus->assertOk()
            ->assertSeeText('HCM_Q1')
            ->assertDontSeeText('HN_CAU_GIAY');

        $filteredBySearch = $this->actingAs($this->superAdmin)->get('/quan-tri/chi-nhanh?search=Xuân+Thủy');
        $filteredBySearch->assertOk()
            ->assertSeeText('HN_CAU_GIAY')
            ->assertDontSeeText('HCM_Q1');

        // 2. Cập nhật thông tin hành chính hợp lệ
        $updateResponse = $this->actingAs($this->superAdmin)->patch(route('admin.branches.update', $createdBranch), [
            '_form' => 'edit',
            'name' => 'Book_Store Cầu Giấy - Flagship',
            'status' => BranchStatus::Active->value,
            'phone' => '+84 24 3888 5678',
            'address' => 'Số 108 Xuân Thủy, Cầu Giấy, Hà Nội',
        ]);
        $updateResponse->assertRedirect(route('admin.branches.show', $createdBranch));

        $createdBranch->refresh();
        $this->assertSame('Book_Store Cầu Giấy - Flagship', $createdBranch->name);
        $this->assertSame('+84 24 3888 5678', $createdBranch->phone);
        $this->assertSame('Số 108 Xuân Thủy, Cầu Giấy, Hà Nội', $createdBranch->address);

        // 3. Thay đổi trạng thái: thiếu xác nhận -> từ chối
        $missingConfirm = $this->actingAs($this->superAdmin)
            ->from(route('admin.branches.show', $createdBranch))
            ->patch(route('admin.branches.status.update', $createdBranch), [
                'status' => BranchStatus::Inactive->value,
                'reason' => 'Sửa chữa nội thất',
            ]);
        $missingConfirm->assertRedirect(route('admin.branches.show', $createdBranch))
            ->assertSessionHasErrors('confirmed');

        $this->assertSame(BranchStatus::Active, $createdBranch->fresh()->status);

        // 4. Ngừng hoạt động khi có xác nhận -> thành công và ghi nhận AuditLog
        $deactivateResponse = $this->actingAs($this->superAdmin)
            ->patch(route('admin.branches.status.update', $createdBranch), [
                'status' => BranchStatus::Inactive->value,
                'confirmed' => '1',
                'reason' => 'Tạm đóng bảo trì hệ thống quầy thu ngân',
            ]);
        $deactivateResponse->assertRedirect(route('admin.branches.show', $createdBranch));
        $this->assertSame(BranchStatus::Inactive, $createdBranch->fresh()->status);

        // Kích hoạt trở lại khi có xác nhận
        $this->actingAs($this->superAdmin)
            ->patch(route('admin.branches.status.update', $createdBranch), [
                'status' => BranchStatus::Active->value,
                'confirmed' => '1',
                'reason' => 'Hoàn tất bảo trì',
            ])
            ->assertRedirect(route('admin.branches.show', $createdBranch));
        $this->assertSame(BranchStatus::Active, $createdBranch->fresh()->status);

        $updateAuditCount = AuditLog::query()
            ->where('action', AuditAction::BranchUpdated->value)
            ->where('branch_id', $createdBranch->id)
            ->count();
        $this->assertSame(3, $updateAuditCount);
    }

    public function test_retail_branch_creation_and_update_enforce_validation_code_uniqueness_and_immutability(): void
    {
        $existing = Branch::factory()->retailBranch()->create([
            'code' => 'HP_LE_CHAN',
            'name' => 'Chi nhánh Hải Phòng',
        ]);

        // Trùng mã không phân biệt hoa thường/khoảng trắng
        $this->actingAs($this->superAdmin)
            ->from('/quan-tri/chi-nhanh/tao-moi')
            ->post('/quan-tri/chi-nhanh', [
                'code' => ' hp_le_chan ',
                'name' => 'Trùng mã',
            ])
            ->assertRedirect('/quan-tri/chi-nhanh/tao-moi')
            ->assertSessionHasErrors('code');

        // Cấm dùng mã CENTRAL_WH hoặc định danh loại central_warehouse khi tạo chi nhánh
        $this->actingAs($this->superAdmin)
            ->from('/quan-tri/chi-nhanh/tao-moi')
            ->post('/quan-tri/chi-nhanh', [
                'code' => 'central_wh',
                'name' => 'Kho giả mạo',
            ])
            ->assertSessionHasErrors('code');

        $this->actingAs($this->superAdmin)
            ->from('/quan-tri/chi-nhanh/tao-moi')
            ->post('/quan-tri/chi-nhanh', [
                'code' => 'FAKE_WH_02',
                'name' => 'Kho trung tâm thứ hai',
                'type' => BranchType::CentralWarehouse->value,
            ])
            ->assertSessionHasErrors('type');

        // Số điện thoại hoặc mã không hợp lệ
        $this->actingAs($this->superAdmin)
            ->from('/quan-tri/chi-nhanh/tao-moi')
            ->post('/quan-tri/chi-nhanh', [
                'code' => 'mã có dấu!',
                'name' => 'Tên hợp lệ',
                'phone' => 'abc-invalid',
            ])
            ->assertSessionHasErrors(['code', 'phone']);

        // Không thể sửa `code` hoặc `type` sau khi tạo (cả HTTP & Service)
        $this->actingAs($this->superAdmin)
            ->from(route('admin.branches.show', $existing))
            ->patch(route('admin.branches.update', $existing), [
                '_form' => 'edit',
                'code' => 'HP_MODIFIED',
                'type' => BranchType::CentralWarehouse->value,
                'name' => 'Tên mới',
                'status' => BranchStatus::Active->value,
            ])
            ->assertSessionHasErrors(['code', 'type']);

        $existing->refresh();
        $this->assertSame('HP_LE_CHAN', $existing->code);
        $this->assertSame(BranchType::RetailBranch, $existing->type);

        $service = app(BranchAdministrationService::class);

        try {
            $service->updateRetailBranch($this->superAdmin, $existing, [
                'code' => 'HP_MODIFIED',
                'name' => 'Tên mới',
                'status' => BranchStatus::Active->value,
            ]);
            $this->fail('Phải ném DomainInvariantException khi cố tình đổi mã chi nhánh.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('BRANCH_CODE_IMMUTABLE', $exception->invariantCode);
        }

        try {
            $service->createRetailBranch($this->superAdmin, [
                'code' => 'NEW_01',
                'name' => 'Kho trung tâm 2',
                'type' => BranchType::CentralWarehouse->value,
            ]);
            $this->fail('Phải ném DomainInvariantException khi ép type = central_warehouse.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('CANNOT_CREATE_CENTRAL_WAREHOUSE', $exception->invariantCode);
        }
    }

    public function test_central_warehouse_is_strictly_read_only_and_protected_against_all_mutations(): void
    {
        // Màn hình chỉ đọc Kho trung tâm không chứa bất kỳ biểu mẫu chỉnh sửa chi nhánh/kho nào
        $warehousePage = $this->actingAs($this->superAdmin)->get('/quan-tri/kho-trung-tam');
        $warehousePage->assertOk()
            ->assertSeeText('CENTRAL_WH')
            ->assertSeeText('Kho Trung tâm Book_Store')
            ->assertSeeText('Bất biến nghiệp vụ Kho trung tâm')
            ->assertDontSeeText('Cập nhật thông tin chi nhánh')
            ->assertDontSeeText('Ngừng hoạt động chi nhánh');

        // Truy cập nhầm route chi tiết chi nhánh với ID Kho trung tâm -> chuyển hướng sang trang chỉ đọc
        $this->actingAs($this->superAdmin)
            ->get(route('admin.branches.show', $this->centralWarehouse))
            ->assertRedirect(route('admin.central-warehouse.show'));

        // Cố tình gửi PATCH chỉnh sửa hoặc chuyển trạng thái trực tiếp vào ID Kho trung tâm -> bị từ chối (422)
        $this->actingAs($this->superAdmin)
            ->patch(route('admin.branches.update', $this->centralWarehouse), [
                'name' => 'Kho Đã Bị Đổi Tên',
                'status' => BranchStatus::Inactive->value,
            ])
            ->assertUnprocessable();

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.branches.status.update', $this->centralWarehouse), [
                'status' => BranchStatus::Inactive->value,
                'confirmed' => '1',
                'reason' => 'Thử vô hiệu hóa kho trung tâm',
            ])
            ->assertUnprocessable();

        $this->centralWarehouse->refresh();
        $this->assertSame('CENTRAL_WH', $this->centralWarehouse->code);
        $this->assertSame(BranchAdministrationService::CENTRAL_WAREHOUSE_NAME, $this->centralWarehouse->name);
        $this->assertSame(BranchType::CentralWarehouse, $this->centralWarehouse->type);
        $this->assertSame(BranchStatus::Active, $this->centralWarehouse->status);

        // Kiểm tra trực tiếp tầng Service & Policy
        $this->assertFalse(Gate::forUser($this->superAdmin)->allows('delete', $this->centralWarehouse));

        $service = app(BranchAdministrationService::class);
        $this->expectException(DomainInvariantException::class);
        $service->setRetailBranchStatus($this->superAdmin, $this->centralWarehouse, BranchStatus::Inactive);
    }

    public function test_branch_creation_rolls_back_atomically_if_audit_log_fails(): void
    {
        $initialBranchCount = Branch::query()->count();
        $initialAuditCount = AuditLog::query()->count();

        $service = app(BranchAdministrationService::class);

        AuditLog::creating(static function (): void {
            throw new \RuntimeException('Mô phỏng lỗi ghi AuditLog trong giao dịch.');
        });

        try {
            $service->createRetailBranch($this->superAdmin, [
                'code' => 'ROLLBACK_01',
                'name' => 'Chi nhánh phải hoàn tác',
            ]);
            $this->fail('Phải ném ngoại lệ khi ghi AuditLog thất bại.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mô phỏng lỗi ghi AuditLog trong giao dịch.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertSame($initialBranchCount, Branch::query()->count());
        $this->assertSame($initialAuditCount, AuditLog::query()->count());
        $this->assertNull(Branch::query()->where('code', 'ROLLBACK_01')->first());
    }

    public function test_admin_ui_assets_follow_liquid_white_rules_and_csrf_without_regressions(): void
    {
        $branch = Branch::factory()->retailBranch()->create([
            'code' => 'CT_01',
            'name' => 'Book_Store Cần Thơ',
        ]);

        foreach ([
            '/quan-tri',
            '/quan-tri/chi-nhanh',
            '/quan-tri/chi-nhanh/tao-moi',
            "/quan-tri/chi-nhanh/{$branch->id}",
            '/quan-tri/kho-trung-tam',
        ] as $url) {
            $response = $this->actingAs($this->superAdmin)->get($url);
            $response->assertOk();
            $this->assertRendersViteAssets($response);
            $response->assertDontSee('<style>', false);
            $response->assertDontSee('<script>', false);
            $response->assertDontSee('href="#"', false);
        }

        // Xác nhận các route ghi dữ liệu thuộc nhóm `web` (có lớp ValidateCsrfToken)
        foreach (['admin.branches.store', 'admin.branches.update', 'admin.branches.status.update'] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $this->assertContains('web', $route->gatherMiddleware());
        }

        // Không gây hồi quy trang chủ công khai và luồng đổi mật khẩu onboarding
        $this->actingAs($this->superAdmin)->get('/')->assertOk();

        $onboardingAdmin = User::factory()->staffActive()->mustChangePassword()->create();
        $this->actingAs($onboardingAdmin)->get('/quan-tri')->assertForbidden();
        $this->actingAs($onboardingAdmin)->get(route('staff.onboarding.password.edit'))->assertOk();

        // Bổ sung: kiểm tra trực tiếp trường hợp createRetailBranch reject ValidationException nếu gọi sai mã ở service
        $service = app(BranchAdministrationService::class);
        $this->expectException(ValidationException::class);
        $service->createRetailBranch($this->superAdmin, [
            'code' => 'x',
            'name' => 'Quá ngắn',
        ]);
    }

    public function test_branch_status_confirmation_panel_is_hidden_initially_and_requires_explicit_trigger_and_acknowledgment(): void
    {
        $activeBranch = Branch::factory()->retailBranch()->create([
            'code' => 'UI_ACTIVE_01',
            'name' => 'Chi nhánh Active UI',
        ]);

        $inactiveBranch = Branch::factory()->retailBranch()->inactive()->create([
            'code' => 'UI_INACTIVE_01',
            'name' => 'Chi nhánh Inactive UI',
        ]);

        // Kiểm tra CSS & JS đã đăng ký [x-cloak], .bs-hidden và progressive confirmation handler
        $appCss = file_get_contents(resource_path('css/app.css'));
        $adminJs = file_get_contents(resource_path('js/admin.js'));
        $this->assertNotFalse($appCss);
        $this->assertNotFalse($adminJs);
        $this->assertStringContainsString('[x-cloak]', $appCss);
        $this->assertStringContainsString('[data-confirm-disclosure]', $adminJs);
        $this->assertStringContainsString('[data-confirm-cancel]', $adminJs);

        // 1. Trạng thái ban đầu của chi nhánh đang hoạt động: nút kích hoạt hiện, panel xác nhận BỊ ẨN (bs-hidden + x-cloak + aria-hidden="true")
        $activePage = $this->actingAs($this->superAdmin)->get(route('admin.branches.show', $activeBranch));
        $activePage->assertOk()
            ->assertSee('data-confirm-disclosure', false)
            ->assertSee('data-initial-open="false"', false)
            ->assertSee('data-confirm-open', false)
            ->assertSeeText('Ngừng hoạt động chi nhánh')
            ->assertSee('id="branch-status-confirm-panel"', false)
            ->assertSee('class="bs-ad-confirm-box bs-hidden"', false)
            ->assertSee('x-cloak', false)
            ->assertSee('aria-hidden="true"', false)
            ->assertSee('data-confirm-submit', false)
            ->assertSee('data-confirm-cancel', false)
            ->assertSeeText('Hủy thao tác');

        // 2. Trạng thái ban đầu của chi nhánh ngừng hoạt động: nhất quán cùng quy tắc ẩn panel xác nhận
        $inactivePage = $this->actingAs($this->superAdmin)->get(route('admin.branches.show', $inactiveBranch));
        $inactivePage->assertOk()
            ->assertSee('data-initial-open="false"', false)
            ->assertSeeText('Kích hoạt lại chi nhánh')
            ->assertSee('class="bs-ad-confirm-box bs-hidden"', false)
            ->assertSee('aria-hidden="true"', false);

        // 3. Nếu gửi request mà chưa tick xác nhận -> server từ chối và KHÔNG đột biến DB, đồng thời mở lại panel để hiện lỗi
        $rejectedResponse = $this->actingAs($this->superAdmin)
            ->followingRedirects()
            ->from(route('admin.branches.show', $activeBranch))
            ->patch(route('admin.branches.status.update', $activeBranch), [
                'status' => BranchStatus::Inactive->value,
                'reason' => 'Chưa xác nhận',
            ]);

        $rejectedResponse->assertOk()
            ->assertSee('data-initial-open="true"', false)
            ->assertSee('aria-hidden="false"', false);

        $this->assertSame(BranchStatus::Active, $activeBranch->fresh()->status);
    }

    private function createEffectiveSuperAdmin(): User
    {
        $passphrase = 'Init!'.Str::random(10).'9aZ';
        $bootstrapped = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên Cấp Cao',
            'superadmin.stage01b4@example.com',
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

    private function createBranchAdminFor(Branch $branch): User
    {
        $user = User::factory()->staffActive()->create();
        StaffProfile::factory()->activated()->create(['user_id' => $user->id]);

        BranchAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'is_primary' => true,
            'status' => BranchAssignmentStatus::Active,
            'assigned_at' => now(),
        ]);

        $branchAdminRole = Role::query()->where('code', 'branch_admin')->firstOrFail();

        BranchRoleAssignment::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role_id' => $branchAdminRole->id,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    private function createGlobalStaffWithPermissions(array $permissionCodes, string $roleCode): User
    {
        $user = User::factory()->staffActive()->create();
        StaffProfile::factory()->activated()->create(['user_id' => $user->id]);

        $role = Role::query()->create([
            'code' => $roleCode,
            'name' => 'Vai trò kiểm thử giới hạn',
            'scope' => RoleScope::Global,
            'description' => 'Chỉ phục vụ kiểm thử phân quyền chi nhánh.',
            'is_system' => false,
        ]);

        $permissionIds = Permission::query()
            ->whereIn('code', $permissionCodes)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'role_scope' => RoleScope::Global->value,
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
