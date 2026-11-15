<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loại hình địa điểm vận hành của Book_Store (cột `branches.type`).
 *
 * Quy tắc bất biến:
 * - Hệ thống chỉ có tối đa một kho trung tâm (`CentralWarehouse` / `central_warehouse`).
 * - Kho trung tâm không bao giờ được chuyển đổi sang chi nhánh bán lẻ (`RetailBranch`).
 */
enum BranchType: string
{
    case CentralWarehouse = 'central_warehouse';
    case RetailBranch = 'retail_branch';

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
        return __('enums.branch_type.'.$this->value);
    }

    /**
     * Kiểm tra địa điểm có phải kho trung tâm hay không.
     */
    public function isCentralWarehouse(): bool
    {
        return $this === self::CentralWarehouse;
    }

    /**
     * Kiểm tra địa điểm có phải chi nhánh bán lẻ hay không.
     */
    public function isRetailBranch(): bool
    {
        return $this === self::RetailBranch;
    }
}
