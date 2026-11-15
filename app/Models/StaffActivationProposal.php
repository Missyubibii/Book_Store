<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use Database\Factories\StaffActivationProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Đề xuất kích hoạt tài khoản nhân sự (`staff_activation_proposals`).
 *
 * Bất biến quy trình:
 * - Tách biệt đề xuất (Branch Admin) và phê duyệt/từ chối (Super Admin).
 * - Người duyệt (`reviewed_by`) tuyệt đối không thể trùng với nhân sự mục tiêu (`user_id`).
 * - Mỗi tài khoản chỉ có tối đa một đề xuất đang ở trạng thái `pending`.
 */
class StaffActivationProposal extends Model
{
    /** @use HasFactory<StaffActivationProposalFactory> */
    use HasFactory;

    /**
     * Giá trị mặc định khớp ràng buộc trạng thái khởi tạo đề xuất.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'target_status' => StaffAccessStatus::Active->value,
        'status' => StaffApprovalStatus::Pending->value,
    ];

    /**
     * Chỉ cho phép gán hàng loạt thông tin khởi tạo đề xuất.
     * Các trường quyết định phê duyệt (`status`, `target_status`, `reviewed_by`, `review_note`,
     * `reviewed_at`) bị chặn khỏi `$fillable` để chỉ dịch vụ phê duyệt được phép ghi nhận.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'branch_id',
        'proposed_by',
        'proposal_reason',
        'proposed_at',
    ];

    /**
     * Nhân sự được đề xuất kích hoạt quyền truy cập.
     *
     * Flow: `StaffActivationProposal` -> `User` (`staff_activation_proposals.user_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Chi nhánh liên quan đến đề xuất kích hoạt (nếu có).
     *
     * Flow: `StaffActivationProposal` -> `Branch` (`staff_activation_proposals.branch_id`).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Người khởi tạo đề xuất kích hoạt (`proposed_by`).
     *
     * Flow: `StaffActivationProposal` -> `User` (`staff_activation_proposals.proposed_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * Bí danh thân thiện cho `proposedBy()`.
     *
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->proposedBy();
    }

    /**
     * Người thẩm định đề xuất kích hoạt (`reviewed_by`).
     *
     * Flow: `StaffActivationProposal` -> `User` (`staff_activation_proposals.reviewed_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Bí danh thân thiện cho `reviewedBy()`.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->reviewedBy();
    }

    /**
     * Định nghĩa ép kiểu thuộc tính chuẩn hóa.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_status' => StaffAccessStatus::class,
            'status' => StaffApprovalStatus::class,
            'proposed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
