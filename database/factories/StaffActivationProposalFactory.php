<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Models\Branch;
use App\Models\StaffActivationProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffActivationProposal>
 */
class StaffActivationProposalFactory extends Factory
{
    /**
     * Định nghĩa đề xuất kích hoạt nhân sự mặc định ở trạng thái chờ phê duyệt (`pending`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->staffPending(),
            'branch_id' => Branch::factory(),
            'target_status' => StaffAccessStatus::Active->value,
            'status' => StaffApprovalStatus::Pending->value,
            'proposed_by' => User::factory()->staffActive(),
            'proposal_reason' => 'Đề xuất kích hoạt tài khoản nhân sự mới sau khi hoàn tất đào tạo.',
            'proposed_at' => now(),
            'reviewed_by' => null,
            'review_note' => null,
            'reviewed_at' => null,
        ];
    }
}
