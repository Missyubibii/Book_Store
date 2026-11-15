<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\StaffApprovalStatus;
use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewStaffProposalRequest;
use App\Models\StaffActivationProposal;
use App\Services\Staff\StaffAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Controller HTTP quản trị hàng đợi phê duyệt hoặc từ chối đề xuất kích hoạt nhân sự (Stage 01B.5.1).
 */
class StaffApprovalController extends Controller
{
    public function __construct(
        private readonly StaffAdministrationService $staffAdmin,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $statusInput = trim((string) $request->query('status', ''));
        $statusFilter = $statusInput !== '' ? StaffApprovalStatus::tryFrom($statusInput) : null;

        try {
            $queueSummary = $this->staffAdmin->approvalQueueSummary($actor);
            $proposals = $this->staffAdmin->paginateActivationProposals(
                actor: $actor,
                statusFilter: $statusFilter,
                perPage: 15,
            );
            $pendingStaffMembers = $this->staffAdmin->paginatePendingStaffForApprovalQueue(
                actor: $actor,
                perPage: 15,
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $readinessByStaffId = [];
        foreach ($pendingStaffMembers as $staffMember) {
            $readinessByStaffId[$staffMember->id] = $this->staffAdmin->evaluateDirectActivationReadiness(
                actor: $actor,
                staff: $staffMember,
            );
        }

        return view('admin.staff.approvals', [
            'queueSummary' => $queueSummary,
            'proposals' => $proposals,
            'pendingStaffMembers' => $pendingStaffMembers,
            'readinessByStaffId' => $readinessByStaffId,
            'statusFilter' => $statusFilter?->value ?? '',
            'statusOptions' => StaffApprovalStatus::cases(),
        ]);
    }

    public function approve(ReviewStaffProposalRequest $request, StaffActivationProposal $proposal): RedirectResponse
    {
        try {
            $approved = $this->staffAdmin->approveActivationProposal(
                actor: $request->user(),
                proposal: $proposal,
                reviewNote: $request->validated('review_note'),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.approvals.index')
            ->with('success', "Đã phê duyệt kích hoạt quyền nhân sự cho {$approved->user?->name}.");
    }

    public function reject(ReviewStaffProposalRequest $request, StaffActivationProposal $proposal): RedirectResponse
    {
        try {
            $rejected = $this->staffAdmin->rejectActivationProposal(
                actor: $request->user(),
                proposal: $proposal,
                reviewNote: (string) $request->validated('review_note'),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (DomainInvariantException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()
            ->route('admin.staff.approvals.index')
            ->with('success', "Đã từ chối đề xuất kích hoạt nhân sự của {$rejected->user?->name}.");
    }
}
