<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Authorization\AuthorizationService;
use App\Services\Branches\BranchAdministrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Hiển thị điểm vào khu vực quản trị được bảo vệ cho Super Administrator.
     *
     * Flow: GET /quan-tri (`admin.dashboard`, middleware `auth`, `account.active`, `staff.active`, `super_admin.effective`)
     *       -> AdminDashboardController::__invoke -> View `admin.dashboard`
     */
    public function __invoke(
        Request $request,
        AuthorizationService $authorization,
        BranchAdministrationService $branchAdmin,
    ): View {
        $user = $request->user();

        if ($user === null || ! $authorization->isEffectiveSuperAdmin($user)) {
            abort(403, __('auth.super_admin_required'));
        }

        $effectivePermissions = $authorization->effectiveGlobalPermissions($user);

        return view('admin.dashboard', [
            'adminUser' => $user,
            'effectiveGlobalPermissionCount' => count($effectivePermissions),
            'passwordChangedAtDisplay' => format_datetime($user->password_changed_at),
            'branchSummary' => $branchAdmin->retailBranchSummary(),
            'centralWarehouse' => $branchAdmin->getCentralWarehouse(),
            'plannedModules' => [
                [
                    'title' => 'Quản lý Chi nhánh & Kho trung tâm',
                    'status' => 'Đang hoạt động',
                    'isActive' => true,
                    'primaryRoute' => route('admin.branches.index'),
                    'primaryLabel' => 'Mở quản lý chi nhánh',
                    'secondaryRoute' => route('admin.central-warehouse.show'),
                    'secondaryLabel' => 'Xem Kho trung tâm',
                    'description' => 'Quản trị danh sách chi nhánh bán lẻ, tạo mới, cập nhật thông tin, kiểm soát trạng thái và theo dõi Kho trung tâm bất biến.',
                ],
                [
                    'title' => 'Quản lý & Phê duyệt Nhân sự',
                    'status' => 'Đang hoạt động',
                    'isActive' => true,
                    'primaryRoute' => route('admin.staff.index'),
                    'primaryLabel' => 'Mở quản lý nhân sự',
                    'secondaryRoute' => route('admin.staff.approvals.index'),
                    'secondaryLabel' => 'Hàng đợi phê duyệt',
                    'description' => 'Tạo hồ sơ nhân sự, cấp mật khẩu tạm 24 giờ, liên kết khách hàng, phân công chi nhánh đa vai trò và phê duyệt kích hoạt.',
                ],
                [
                    'title' => 'Danh mục Sách & Tồn kho FIFO',
                    'status' => 'Đang chuẩn bị (Chưa mở)',
                    'isActive' => false,
                    'description' => 'Quản trị đầu sách in, ấn bản số eBook, lô nhập kho FIFO và quy tắc giá thành.',
                ],
                [
                    'title' => 'Nhật ký Kiểm toán Hệ thống',
                    'status' => 'Đang hoạt động',
                    'isActive' => true,
                    'primaryRoute' => route('admin.audit-logs.index'),
                    'primaryLabel' => 'Mở nhật ký hoạt động',
                    'description' => 'Tra cứu nhật ký bất biến về phân quyền, phê duyệt và các thao tác quản trị nhạy cảm.',
                ],
            ],
        ]);
    }
}
