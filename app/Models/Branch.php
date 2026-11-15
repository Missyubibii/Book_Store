<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BranchStatus;
use App\Enums\BranchType;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Địa điểm vận hành của Book_Store (kho trung tâm hoặc chi nhánh bán lẻ).
 *
 * Bất biến nghiệp vụ cho Stage 01B.3+:
 * - Hệ thống chỉ có tối đa một kho trung tâm (`BranchType::CentralWarehouse`).
 * - Kho trung tâm không bao giờ được chuyển loại sang `BranchType::RetailBranch`
 *   và không bao giờ được phép chuyển trạng thái sang `BranchStatus::Inactive`.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /**
     * Giá trị mặc định an toàn cho chi nhánh mới.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => BranchType::RetailBranch->value,
        'status' => BranchStatus::Active->value,
    ];

    /**
     * Các trường được phép gán hàng loạt cho quản trị chi nhánh.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'status',
        'address',
        'phone',
    ];

    /**
     * Danh sách phân công nhân sự tại chi nhánh/kho này.
     *
     * Flow: `Branch` -> `BranchAssignment` (`branch_assignments.branch_id`).
     *
     * @return HasMany<BranchAssignment, $this>
     */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(BranchAssignment::class);
    }

    /**
     * Danh sách phân quyền vai trò theo chi nhánh tại địa điểm này.
     *
     * Flow: `Branch` -> `BranchRoleAssignment` (`branch_role_assignments.branch_id`).
     *
     * @return HasMany<BranchRoleAssignment, $this>
     */
    public function branchRoleAssignments(): HasMany
    {
        return $this->hasMany(BranchRoleAssignment::class);
    }

    /**
     * Các đề xuất kích hoạt nhân sự gắn với chi nhánh này.
     *
     * Flow: `Branch` -> `StaffActivationProposal` (`staff_activation_proposals.branch_id`).
     *
     * @return HasMany<StaffActivationProposal, $this>
     */
    public function staffActivationProposals(): HasMany
    {
        return $this->hasMany(StaffActivationProposal::class);
    }

    /**
     * Nhật ký kiểm toán phát sinh trong phạm vi chi nhánh này.
     *
     * Flow: `Branch` -> `AuditLog` (`audit_logs.branch_id`).
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Danh sách tài khoản nhân sự được phân công vào chi nhánh qua `branch_assignments`.
     *
     * Flow: `Branch` -> `branch_assignments` -> `User`.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_assignments')
            ->withPivot(['status', 'is_primary', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Kiểm tra thực thể hiện tại có phải Kho trung tâm bất biến hay không.
     */
    public function isCentralWarehouse(): bool
    {
        return $this->type === BranchType::CentralWarehouse;
    }

    /**
     * Kiểm tra chi nhánh/kho có đang ở trạng thái hoạt động hay không.
     */
    public function isActive(): bool
    {
        return $this->status === BranchStatus::Active;
    }

    /**
     * Định nghĩa ép kiểu thuộc tính chuẩn hóa.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BranchType::class,
            'status' => BranchStatus::class,
        ];
    }
}
