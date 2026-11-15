<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Audit\AuditLogQueryService;
use App\Services\Authorization\AuthorizationService;
use App\Services\Branches\BranchAdministrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Màn hình chỉ đọc Kho trung tâm Book_Store (`CENTRAL_WH`).
 *
 * Flow:
 * GET /quan-tri/kho-trung-tam (`admin.central-warehouse.show`)
 * -> Gate::authorize('viewCentralWarehouse', [Branch::class, $centralWarehouse]) (`branches.view` hoặc `warehouse.dashboard.access` + `warehouse.view` tại CENTRAL_WH)
 * -> View `admin.central-warehouse.show` (chỉ đọc; không cung cấp form sửa/xóa/ngừng hoạt động).
 */
class CentralWarehouseController extends Controller
{
    public function __invoke(
        Request $request,
        BranchAdministrationService $branchAdmin,
        AuthorizationService $authorization,
        AuditLogQueryService $auditQuery,
    ): View {
        $centralWarehouse = $branchAdmin->getCentralWarehouse();
        Gate::authorize('viewCentralWarehouse', [Branch::class, $centralWarehouse]);

        $actor = $request->user();
        $isGlobalBranchViewer = $authorization->hasGlobalPermission($actor, 'branches.view');
        $retailSummary = $branchAdmin->retailBranchSummary();

        $auditPage = $auditQuery->cursorPaginateForBranch($centralWarehouse, null, 10, $actor);

        return view('admin.central-warehouse.show', [
            'centralWarehouse' => $centralWarehouse,
            'isGlobalBranchViewer' => $isGlobalBranchViewer,
            'canonicalCode' => BranchAdministrationService::CENTRAL_WAREHOUSE_CODE,
            'canonicalName' => BranchAdministrationService::CENTRAL_WAREHOUSE_NAME,
            'retailSummary' => $retailSummary,
            'recentAudits' => $auditPage['items'],
            'nextAuditCursor' => $auditPage['next_cursor'],
            'hasMoreAudits' => $auditPage['has_more'],
            'canViewGlobalAuditLogs' => $authorization->isEffectiveSuperAdmin($actor),
            'invariantRules' => [
                'Hệ thống chỉ tồn tại tối đa một Kho trung tâm duy nhất (ràng buộc cơ sở dữ liệu + nghiệp vụ).',
                'Mã kho (CENTRAL_WH), tên chuẩn và loại kho trung tâm là bất biến — không thể đổi sang chi nhánh bán lẻ.',
                'Kho trung tâm luôn duy trì trạng thái đang hoạt động; không thể ngừng hoạt động hoặc xóa khỏi hệ thống.',
                'Mọi điều chuyển hàng giữa các chi nhánh bán lẻ trong các giai đoạn sau đều đi qua Kho trung tâm.',
            ],
        ]);
    }
}
