<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BranchStatus;
use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListBranchesRequest;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Http\Requests\Admin\UpdateBranchStatusRequest;
use App\Models\Branch;
use App\Services\Audit\AuditLogQueryService;
use App\Services\Authorization\AuthorizationService;
use App\Services\Branches\BranchAdministrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Controller HTTP quản trị danh sách, tạo mới, cập nhật và trạng thái chi nhánh bán lẻ.
 *
 * Flow:
 * GET  /quan-tri/chi-nhanh                  (`admin.branches.index`)         -> index()
 * GET  /quan-tri/chi-nhanh/tao-moi          (`admin.branches.create`)        -> create()
 * POST /quan-tri/chi-nhanh                  (`admin.branches.store`)         -> store()
 * GET  /quan-tri/chi-nhanh/{branch}         (`admin.branches.show`)          -> show()
 * PATCH /quan-tri/chi-nhanh/{branch}        (`admin.branches.update`)        -> update()
 * PATCH /quan-tri/chi-nhanh/{branch}/trang-thai (`admin.branches.status.update`) -> updateStatus()
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly BranchAdministrationService $branchAdmin,
        private readonly AuditLogQueryService $auditQuery,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(ListBranchesRequest $request): View
    {
        Gate::authorize('viewAny', Branch::class);

        $actor = $request->user();
        $search = $request->searchTerm();
        $statusFilter = $request->statusFilter();

        $branches = $this->branchAdmin->paginateRetailBranches(
            actor: $actor,
            search: $search,
            status: $statusFilter,
            perPage: 12,
        );

        return view('admin.branches.index', [
            'branches' => $branches,
            'summary' => $this->branchAdmin->retailBranchSummary(),
            'centralWarehouse' => $this->branchAdmin->getCentralWarehouse(),
            'search' => $search ?? '',
            'statusFilter' => $statusFilter?->value ?? '',
            'statusOptions' => BranchStatus::cases(),
            'canCreateBranch' => $actor->can('create', Branch::class),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('viewAny', Branch::class);
        Gate::authorize('create', Branch::class);

        return view('admin.branches.create', [
            'statusOptions' => BranchStatus::cases(),
            'centralWarehouse' => $this->branchAdmin->getCentralWarehouse(),
        ]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        Gate::authorize('viewAny', Branch::class);
        Gate::authorize('create', Branch::class);

        try {
            $branch = $this->branchAdmin->createRetailBranch(
                actor: $request->user(),
                input: $request->validated(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.branches.show', $branch)
            ->with('success', "Đã tạo mới chi nhánh bán lẻ {$branch->name} ({$branch->code}).");
    }

    public function show(Request $request, Branch $branch): View|RedirectResponse
    {
        Gate::authorize('viewAny', Branch::class);
        Gate::authorize('view', $branch);

        if ($branch->isCentralWarehouse()) {
            return redirect()
                ->route('admin.central-warehouse.show')
                ->with('info', 'Kho trung tâm Book_Store (CENTRAL_WH) được hiển thị tại chế độ chỉ đọc riêng.');
        }

        $actor = $request->user();
        $auditPage = $this->auditQuery->cursorPaginateForBranch($branch, null, 10, $actor);

        return view('admin.branches.show', [
            'branch' => $branch,
            'statusOptions' => BranchStatus::cases(),
            'canUpdateBranch' => $actor->can('update', $branch),
            'recentAudits' => $auditPage['items'],
            'nextAuditCursor' => $auditPage['next_cursor'],
            'hasMoreAudits' => $auditPage['has_more'],
            'canViewGlobalAuditLogs' => $this->authorization->isEffectiveSuperAdmin($actor),
        ]);
    }

    /**
     * API tải thêm sự kiện nhật ký kiểm toán cho chi nhánh theo cơ chế phân trang con trỏ (cursor pagination).
     */
    public function auditLogs(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('viewAny', Branch::class);
        Gate::authorize('view', $branch);

        $actor = $request->user();
        $cursor = $request->query('cursor');
        $perPage = max(5, min(50, (int) $request->query('per_page', 10)));

        $result = $this->auditQuery->cursorPaginateForBranch(
            branch: $branch,
            cursor: is_string($cursor) ? $cursor : null,
            perPage: $perPage,
            actor: $actor,
        );

        return response()->json([
            'data' => $result['formatted'],
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
        ]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('viewAny', Branch::class);
        Gate::authorize('update', $branch);

        try {
            $updated = $this->branchAdmin->updateRetailBranch(
                actor: $request->user(),
                branch: $branch,
                input: $request->validated(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.branches.show', $updated)
            ->with('success', "Đã cập nhật thông tin chi nhánh {$updated->name} ({$updated->code}).");
    }

    public function updateStatus(UpdateBranchStatusRequest $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('viewAny', Branch::class);
        Gate::authorize('update', $branch);

        $validated = $request->validated();
        $targetStatus = BranchStatus::from((string) $validated['status']);

        try {
            $updated = $this->branchAdmin->setRetailBranchStatus(
                actor: $request->user(),
                branch: $branch,
                targetStatus: $targetStatus,
                reason: $validated['reason'] ?? null,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        $actionLabel = $targetStatus === BranchStatus::Active
            ? 'kích hoạt hoạt động trở lại'
            : 'chuyển sang ngừng hoạt động';

        return redirect()
            ->route('admin.branches.show', $updated)
            ->with('success', "Đã {$actionLabel} chi nhánh {$updated->name} ({$updated->code}).");
    }
}
