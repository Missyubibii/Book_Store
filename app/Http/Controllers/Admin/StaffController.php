<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\StaffAccessStatus;
use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignStaffBranchRequest;
use App\Http\Requests\Admin\DeactivateStaffBranchAssignmentRequest;
use App\Http\Requests\Admin\DirectActivateStaffRequest;
use App\Http\Requests\Admin\LinkCustomerStaffRequest;
use App\Http\Requests\Admin\ListStaffRequest;
use App\Http\Requests\Admin\ProposeStaffActivationRequest;
use App\Http\Requests\Admin\ReactivateStaffRequest;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\SuspendStaffRequest;
use App\Models\Branch;
use App\Models\StaffActivationProposal;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use App\Services\Staff\StaffAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Controller HTTP quản trị danh bạ nhân sự, khởi tạo, liên kết khách hàng,
 * phân công đa chi nhánh và kiểm soát trạng thái truy cập nhân sự (Stage 01B.5.1).
 */
class StaffController extends Controller
{
    public function __construct(
        private readonly StaffAdministrationService $staffAdmin,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(ListStaffRequest $request): View
    {
        $actor = $request->user();
        $search = $request->searchTerm();
        $statusFilter = $request->statusFilter();
        $branchIdFilter = $request->branchIdFilter();

        try {
            $staffPaginator = $this->staffAdmin->paginateStaff(
                actor: $actor,
                search: $search,
                status: $statusFilter,
                branchId: $branchIdFilter,
                perPage: 12,
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        return view('admin.staff.index', [
            'staffMembers' => $staffPaginator,
            'summary' => $this->staffAdmin->staffSummary($actor),
            'branches' => $this->staffAdmin->viewableBranchesFor($actor),
            'search' => $search ?? '',
            'statusFilter' => $statusFilter?->value ?? '',
            'branchIdFilter' => $branchIdFilter,
            'statusOptions' => [
                StaffAccessStatus::Pending,
                StaffAccessStatus::Active,
                StaffAccessStatus::Inactive,
            ],
            'canCreateStaff' => $this->staffAdmin->manageableBranchesFor($actor, 'staff.create')->isNotEmpty(),
            'canLinkStaff' => $this->staffAdmin->manageableBranchesFor($actor, 'staff.link')->isNotEmpty(),
            'canApproveProposals' => $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation'),
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $request->user();
        $creatableBranches = $this->staffAdmin->manageableBranchesFor($actor, 'staff.create');

        if ($creatableBranches->isEmpty()) {
            abort(403, 'Bạn không có quyền tạo tài khoản nhân sự tại bất kỳ chi nhánh nào.');
        }

        return view('admin.staff.create', [
            'branches' => $creatableBranches,
            'branchRoles' => $this->staffAdmin->availableBranchRoles(),
            'ttlHours' => max(1, (int) config('auth.staff_temporary_password_ttl_hours', 24)),
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validated();
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail((int) $validated['branch_id']);

        try {
            $createdStaff = $this->staffAdmin->createStaffAccount(
                actor: $actor,
                branch: $branch,
                input: $validated,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.show', $createdStaff)
            ->with('success', "Đã khởi tạo hồ sơ nhân sự {$createdStaff->name} ở trạng thái Chờ kích hoạt (mật khẩu tạm hiệu lực 24 giờ).");
    }

    public function createLink(Request $request): View
    {
        $actor = $request->user();
        $linkableBranches = $this->staffAdmin->manageableBranchesFor($actor, 'staff.link');

        if ($linkableBranches->isEmpty()) {
            abort(403, 'Bạn không có quyền liên kết tài khoản khách hàng sang nhân sự.');
        }

        return view('admin.staff.link', [
            'branches' => $linkableBranches,
            'branchRoles' => $this->staffAdmin->availableBranchRoles(),
        ]);
    }

    public function storeLink(LinkCustomerStaffRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validated();

        /** @var User $customer */
        $customer = User::query()->where('email', $validated['customer_email'])->firstOrFail();
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail((int) $validated['branch_id']);

        try {
            $linkedStaff = $this->staffAdmin->linkExistingCustomerToStaff(
                actor: $actor,
                customer: $customer,
                branch: $branch,
                input: $validated,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.show', $linkedStaff)
            ->with('success', "Đã liên kết tài khoản {$linkedStaff->email} vào hồ sơ nhân sự ở trạng thái Chờ kích hoạt (mật khẩu khách hàng được giữ nguyên).");
    }

    public function show(Request $request, User $staff): View
    {
        $actor = $request->user();

        try {
            $payload = $this->staffAdmin->getStaffDetailsPayload($actor, $staff);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $assignableBranches = $this->staffAdmin->manageableBranchesFor($actor, 'branches.assign_staff')
            ->filter(fn (Branch $b): bool => $this->authorization->hasBranchPermission($actor, 'roles.assign_branch', $b))
            ->values();

        $proposableBranches = $this->staffAdmin->manageableBranchesFor($actor, 'staff.propose_activation')
            ->filter(fn (Branch $b): bool => $actor->can('propose', [StaffActivationProposal::class, $staff, $b]))
            ->values();

        return view('admin.staff.show', [
            'staff' => $payload['staff'],
            'assignmentsWithRoles' => $payload['assignmentsWithRoles'],
            'globalRoles' => $payload['globalRoles'],
            'proposals' => $payload['proposals'],
            'pendingProposal' => $payload['pendingProposal'],
            'recentAudits' => $payload['recentAudits'],
            'nextAuditCursor' => $payload['nextAuditCursor'],
            'hasMoreAudits' => $payload['hasMoreAudits'],
            'latestAssignmentUpdatedAt' => $payload['latestAssignmentUpdatedAt'],
            'hasCentralWarehouseAssignment' => $payload['hasCentralWarehouseAssignment'],
            'effectiveAccessSummary' => $payload['effectiveAccessSummary'],
            'nextActionGuide' => $payload['nextActionGuide'],
            'canViewGlobalAuditLogs' => $this->authorization->isEffectiveSuperAdmin($actor),
            'assignableBranches' => $assignableBranches,
            'proposableBranches' => $proposableBranches,
            'branchRoles' => $this->staffAdmin->availableBranchRoles(),
            'canSuspendGlobally' => $actor->can('suspendStaff', [$staff, null]) && $staff->staff_access_status !== StaffAccessStatus::Inactive,
            'canReactivateGlobally' => $actor->can('reactivateStaff', $staff) && $staff->staff_access_status === StaffAccessStatus::Inactive,
            'canReviewPendingProposal' => $payload['pendingProposal'] !== null && $actor->can('review', $payload['pendingProposal']),
            'canDirectActivate' => $actor->can('directActivateStaff', $staff),
            'directActivationReadiness' => $this->staffAdmin->evaluateDirectActivationReadiness($actor, $staff),
        ]);
    }

    /**
     * API tải thêm sự kiện nhật ký kiểm toán cho nhân sự theo cơ chế phân trang con trỏ (cursor pagination).
     */
    public function auditLogs(Request $request, User $staff): JsonResponse
    {
        $actor = $request->user();
        $cursor = $request->query('cursor');
        $perPage = max(5, min(50, (int) $request->query('per_page', 10)));

        try {
            $result = $this->staffAdmin->cursorPaginateStaffAudits(
                actor: $actor,
                staff: $staff,
                cursor: is_string($cursor) ? $cursor : null,
                perPage: $perPage,
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        return response()->json([
            'data' => $result['formatted'],
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
        ]);
    }

    public function upsertAssignment(AssignStaffBranchRequest $request, User $staff): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validated();
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail((int) $validated['branch_id']);

        try {
            $this->staffAdmin->assignBranchAndRoles(
                actor: $actor,
                staff: $staff,
                branch: $branch,
                roleIds: $validated['role_ids'],
                isPrimary: (bool) ($validated['is_primary'] ?? false),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.show', $staff)
            ->with('success', "Đã lưu phân công và vai trò cho nhân sự tại chi nhánh {$branch->name} ({$branch->code}).");
    }

    public function deactivateAssignment(
        DeactivateStaffBranchAssignmentRequest $request,
        User $staff,
        Branch $branch,
    ): RedirectResponse {
        $actor = $request->user();

        try {
            $updated = $this->staffAdmin->deactivateBranchAssignment(
                actor: $actor,
                staff: $staff,
                branch: $branch,
                reason: $request->validated('reason'),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        $message = $updated->staff_access_status === StaffAccessStatus::Inactive
            ? "Đã gỡ phân công tại chi nhánh {$branch->name}. Do không còn phân công hiệu lực nào, quyền truy cập nhân sự đã tự động chuyển sang Ngừng hoạt động."
            : "Đã gỡ phân công của nhân sự tại chi nhánh {$branch->name}.";

        return redirect()
            ->route('admin.staff.show', $updated)
            ->with('success', $message);
    }

    public function submitProposal(ProposeStaffActivationRequest $request, User $staff): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validated();
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail((int) $validated['branch_id']);

        try {
            $this->staffAdmin->submitActivationProposal(
                actor: $actor,
                staff: $staff,
                branch: $branch,
                proposalReason: (string) $validated['proposal_reason'],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.show', $staff)
            ->with('success', 'Đã gửi đề xuất kích hoạt quyền nhân sự lên Quản trị viên cấp cao thẩm định.');
    }

    public function suspend(SuspendStaffRequest $request, User $staff): RedirectResponse
    {
        try {
            $this->staffAdmin->suspendStaffAccess(
                actor: $request->user(),
                staff: $staff,
                reason: (string) $request->validated('reason'),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.show', $staff)
            ->with('success', "Đã đình chỉ quyền truy cập nhân sự của {$staff->name}. Tài khoản khách hàng tổng thể vẫn giữ nguyên hoạt động.");
    }

    public function reactivate(ReactivateStaffRequest $request, User $staff): RedirectResponse
    {
        try {
            $this->staffAdmin->reactivateStaffAccess(
                actor: $request->user(),
                staff: $staff,
                reason: (string) ($request->validated('reason') ?? ''),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.show', $staff)
            ->with('success', "Đã khôi phục quyền truy cập nhân sự cho {$staff->name}.");
    }

    public function activateDirect(DirectActivateStaffRequest $request, User $staff): RedirectResponse
    {
        try {
            $activated = $this->staffAdmin->activateStaffDirectly(
                actor: $request->user(),
                staff: $staff,
                reviewNote: $request->validated('review_note'),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        $redirectRoute = $request->input('return_to') === 'approvals'
            ? route('admin.staff.approvals.index')
            : route('admin.staff.show', $activated);

        return redirect()
            ->to($redirectRoute)
            ->with('success', "Đã kích hoạt trực tiếp quyền truy cập nhân sự cho {$activated->name}.");
    }
}
