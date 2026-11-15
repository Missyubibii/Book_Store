<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BranchAssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bản ghi phân công nhân sự vào một chi nhánh hoặc kho trung tâm (`branch_assignments`).
 *
 * Quy tắc Stage 01B.3 (Decision 2.7): Khi gỡ hoặc vô hiệu hóa phân công chi nhánh cuối cùng
 * của nhân viên không giữ vai trò toàn hệ thống `super_admin`, trạng thái `staff_access_status`
 * phải tự động chuyển sang `inactive` trong cùng giao dịch.
 */
class BranchAssignment extends Model
{
    /**
     * Giá trị mặc định cho phân công chi nhánh mới.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => BranchAssignmentStatus::Active->value,
        'is_primary' => false,
    ];

    /**
     * Các trường được phép gán hàng loạt cho phân công chi nhánh.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'branch_id',
        'status',
        'is_primary',
        'assigned_by',
        'assigned_at',
    ];

    /**
     * Tài khoản nhân sự được phân công.
     *
     * Flow: `BranchAssignment` -> `User` (`branch_assignments.user_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Chi nhánh hoặc kho trung tâm tiếp nhận phân công.
     *
     * Flow: `BranchAssignment` -> `Branch` (`branch_assignments.branch_id`).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Người quản trị đã tạo hoặc cập nhật phân công.
     *
     * Flow: `BranchAssignment` -> `User` (`branch_assignments.assigned_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Truy vấn trực tiếp các vai trò chi nhánh khớp chính xác cặp `(user_id, branch_id)`.
     *
     * Cố ý trả về Query Builder thuần thay vì quan hệ Eloquent `HasMany` để ngăn ngừa
     * rò rỉ dữ liệu xuyên nhân sự tại cùng chi nhánh khi dùng `with()`, `whereHas()` hoặc
     * `withCount()`. Nếu thiếu `user_id` hoặc `branch_id`, trả về truy vấn rỗng tuyệt đối.
     *
     * @return Builder<BranchRoleAssignment>
     */
    public function matchingRoleAssignments(): Builder
    {
        if ($this->user_id === null || $this->branch_id === null) {
            return BranchRoleAssignment::query()->whereRaw('1 = 0');
        }

        return BranchRoleAssignment::query()
            ->where('user_id', $this->user_id)
            ->where('branch_id', $this->branch_id);
    }

    /**
     * Định nghĩa ép kiểu thuộc tính chuẩn hóa.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchAssignmentStatus::class,
            'is_primary' => 'boolean',
            'assigned_at' => 'datetime',
        ];
    }
}
