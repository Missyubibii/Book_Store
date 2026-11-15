<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Trạng thái hoạt động của chi nhánh hoặc kho trung tâm (cột `branches.status`).
 *
 * Quy tắc bất biến:
 * - Địa điểm loại `BranchType::CentralWarehouse` không bao giờ được phép chuyển sang `Inactive`
 *   (được bảo vệ ở tầng nghiệp vụ / domain guard).
 */
enum BranchStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

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
        return __('enums.branch_status.'.$this->value);
    }

    /**
     * Kiểm tra chi nhánh/kho đang ở trạng thái hoạt động hay không.
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Các trạng thái đích hợp lệ khi chuyển đổi từ trạng thái hiện tại.
     *
     * Lưu ý: Tầng domain phải chặn chuyển `Active -> Inactive` đối với kho trung tâm.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Inactive],
            self::Inactive => [self::Active],
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
