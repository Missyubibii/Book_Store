<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Models\Branch;
use App\Models\StaffActivationProposal;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use App\Services\Authorization\DomainGuardService;

/**
 * Policy kiểm soát quy trình đề xuất và phê duyệt kích hoạt nhân sự.
 *
 * Bảo đảm tách biệt nghiêm ngặt người đề xuất ở chi nhánh và người phê duyệt cấp cao,
 * đồng thời chặn tuyệt đối việc tự đề xuất hoặc tự phê duyệt trên chính tài khoản mình.
 */
class StaffActivationProposalPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly DomainGuardService $domainGuards,
    ) {}

    public function propose(User $actor, User $targetUser, Branch $branch): bool
    {
        if ($this->domainGuards->isSelfAction($actor, $targetUser)) {
            return false;
        }

        if (
            $targetUser->account_status !== AccountStatus::Active
            || ! in_array($targetUser->staff_access_status, [StaffAccessStatus::Pending, StaffAccessStatus::Inactive], true)
        ) {
            return false;
        }

        return $this->authorization->hasBranchPermission($actor, 'staff.propose_activation', $branch);
    }

    public function review(User $actor, StaffActivationProposal $proposal): bool
    {
        if ((int) $actor->id === (int) $proposal->user_id) {
            return false;
        }

        if ($proposal->status !== StaffApprovalStatus::Pending) {
            return false;
        }

        return $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation');
    }
}
