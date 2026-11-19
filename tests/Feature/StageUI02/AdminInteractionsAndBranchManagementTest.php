<?php

declare(strict_types=1);

namespace Tests\Feature\StageUI02;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\StaffAccessStatus;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInteractionsAndBranchManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->superAdmin = app(BootstrapSuperAdminAction::class)->execute(
            'Quản trị viên Cấp Cao',
            'superadmin-ui02@bookstore.test',
            'StrongPass!1234',
            'StrongPass!1234',
        );
        $this->superAdmin->forceFill(['must_change_password' => false])->save();
    }

    public function test_admin_shell_contains_single_header_sidebar_toggle_user_menu_and_logout_form(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('data-bs-sidebar-init', false)
            ->assertSee('bs_admin_sidebar_collapsed', false)
            ->assertSee('data-admin-shell', false)
            ->assertSee('data-admin-sidebar', false)
            ->assertSee('id="admin-primary-sidebar"', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('aria-controls="admin-primary-sidebar"', false)
            ->assertSee('bs-ad-nav-label', false)
            ->assertDontSee('data-sidebar-collapse-toggle', false)
            ->assertDontSee('bs-ad-sidebar-footer', false)
            ->assertSee('data-user-menu-toggle', false)
            ->assertSee('data-user-menu-dropdown', false)
            ->assertSee(route('logout'), false)
            ->assertSee('Đăng xuất');

        $html = $response->getContent();
        $headInitPos = strpos($html, 'data-bs-sidebar-init');
        $bodyPos = strpos($html, '<body');
        $this->assertNotFalse($headInitPos);
        $this->assertNotFalse($bodyPos);
        $this->assertLessThan($bodyPos, $headInitPos, 'Pre-paint sidebar script must execute inside <head> before <body>');
    }

    public function test_logout_requires_post_and_invalidates_session(): void
    {
        $this->actingAs($this->superAdmin);

        $this->get('/dang-xuat')->assertStatus(405);

        $logoutResponse = $this->post(route('logout'));
        $logoutResponse->assertRedirect();
        $this->assertGuest();
    }

    public function test_dashboard_reporting_period_filters_and_custom_date_validation(): void
    {
        User::factory()->create([
            'staff_access_status' => StaffAccessStatus::NotApplicable,
            'created_at' => now()->subDays(3),
        ]);

        User::factory()->create([
            'staff_access_status' => StaffAccessStatus::NotApplicable,
            'created_at' => now()->subDays(20),
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard', ['period' => '7_days']))
            ->assertOk()
            ->assertSeeText('7 ngày qua');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard', ['period' => '30_days']))
            ->assertOk()
            ->assertSeeText('30 ngày qua');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard', ['period' => 'this_year']))
            ->assertOk()
            ->assertSeeText('Năm nay');

        $validStart = now()->subDays(10)->toDateString();
        $validEnd = now()->toDateString();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard', [
                'period' => 'custom',
                'start_date' => $validStart,
                'end_date' => $validEnd,
            ]))
            ->assertOk()
            ->assertSee('data-custom-period-panel', false);

        // Khoảng ngày không hợp lệ (ngày bắt đầu > ngày kết thúc)
        $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard', [
                'period' => 'custom',
                'start_date' => now()->toDateString(),
                'end_date' => now()->subDays(5)->toDateString(),
            ]))
            ->assertOk()
            ->assertSeeText('Ngày bắt đầu phải trước hoặc bằng ngày kết thúc');
    }

    public function test_branch_management_index_renders_redesigned_components_and_excludes_central_warehouse(): void
    {
        Branch::factory()->create([
            'code' => 'CENTRAL_WH',
            'name' => 'Kho Trung Tâm Hệ Thống',
            'type' => BranchType::CentralWarehouse,
            'status' => BranchStatus::Active,
        ]);

        $retail = Branch::factory()->create([
            'code' => 'HCM_Q1',
            'name' => 'Book_Store Quận 1',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Active,
            'address' => '123 Nguyễn Huệ, Quận 1, TP.HCM',
        ]);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.branches.index'));

        $response->assertOk()
            ->assertSee('bs-ad-compact-filter-bar', false)
            ->assertSee('bs-ad-compact-search', false)
            ->assertSee('bs-ad-compact-select-wrap', false)
            ->assertSeeText('Quản lý chi nhánh')
            ->assertSeeText('Thêm chi nhánh')
            ->assertSeeText('Tổng số chi nhánh bán lẻ')
            ->assertSeeText('Chi nhánh đang hoạt động')
            ->assertSeeText('Chi nhánh tạm ngừng')
            ->assertSeeText('Nhân sự đang được phân công')
            ->assertSeeText($retail->name)
            ->assertSeeText($retail->code)
            ->assertDontSeeText('Kho Trung Tâm Hệ Thống');

        $inactiveRetail = Branch::factory()->create([
            'code' => 'HN_CG',
            'name' => 'Book_Store Cầu Giấy',
            'type' => BranchType::RetailBranch,
            'status' => BranchStatus::Inactive,
        ]);

        // Kiểm tra lọc theo từ khóa và trạng thái kèm nút Đặt lại
        $filteredResponse = $this->actingAs($this->superAdmin)->get(route('admin.branches.index', [
            'search' => 'Cầu Giấy',
            'status' => BranchStatus::Inactive->value,
        ]));

        $filteredResponse->assertOk()
            ->assertSeeText($inactiveRetail->name)
            ->assertDontSeeText($retail->name)
            ->assertSeeText('Đặt lại');

        // Kiểm tra trang tạo mới chi nhánh (Create UI)
        $createResponse = $this->actingAs($this->superAdmin)->get(route('admin.branches.create'));
        $createResponse->assertOk()
            ->assertSee('bs-ad-form-section-card', false)
            ->assertSee('data-branch-code-input', false)
            ->assertSeeText('Tạo Chi nhánh Bán lẻ')
            ->assertSeeText('Lưu chi nhánh bán lẻ');

        // Kiểm tra trang chi tiết chi nhánh (Show, Progressive Edit, Staff Assignments & Status Confirmation)
        $detailResponse = $this->actingAs($this->superAdmin)->get(route('admin.branches.show', $retail));
        $detailResponse->assertOk()
            ->assertSeeText($retail->name)
            ->assertSeeText($retail->code)
            ->assertSee('bs-ad-info-list', false)
            ->assertSee('id="branch-edit-form-panel"', false)
            ->assertSee('id="branch-status-confirm-panel"', false)
            ->assertSeeText('Nhân sự phân công tại chi nhánh')
            ->assertSeeText('Kiểm soát trạng thái hoạt động chi nhánh')
            ->assertSeeText('Nhật ký hoạt động gần đây');
    }

    public function test_branch_management_denies_unauthorized_actors(): void
    {
        $this->get(route('admin.branches.index'))->assertRedirect(route('login'));

        $customer = User::factory()->create();
        $this->actingAs($customer)
            ->get(route('admin.branches.index'))
            ->assertForbidden();
    }
}
