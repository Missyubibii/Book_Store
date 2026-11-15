<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Trạng thái xử lý đề xuất kích hoạt nhân viên (cột `staff_activation_proposals.status`).
 *
 * Chỉ đề xuất ở trạng thái `Pending` mới có thể chuyển sang một trong các trạng thái kết thúc
 * (`Approved`, `Rejected`, `Cancelled`). Mỗi người dùng chỉ được có tối đa một đề xuất `Pending`.
 */
enum StaffApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Danh sách giá trị chuỗi hợp lệ lưu trong cơ sở dữ liệu.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Nhãn hiển thị tiếng Việt chuẩn hóa.
     */
    public function label(): string
    {
        return __('enums.staff_approval_status.'.$this->value);
    }

    /**
     * Kiểm tra đề xuất có đang chờ phê duyệt hay không.
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Kiểm tra đề xuất đã ở trạng thái kết thúc (không thể chuyển tiếp) hay chưa.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Các trạng thái đích hợp lệ từ trạng thái hiện tại.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved, self::Rejected, self::Cancelled => [],
        };
    }

    /**
     * Kiểm tra chuyển đổi trạng thái hợp lệ.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
