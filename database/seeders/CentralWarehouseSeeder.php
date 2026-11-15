<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Khởi tạo kho trung tâm duy nhất của Book_Store (`CENTRAL_WH`).
 *
 * Bảo vệ bất biến kho trung tâm:
 * - Nếu dữ liệu hiện có bị xung đột (kho trung tâm mang mã khác `CENTRAL_WH`, mã `CENTRAL_WH`
 *   không phải loại `central_warehouse`, có nhiều hơn một kho trung tâm, hoặc bị đánh dấu
 *   `inactive`), seeder sẽ ném `\RuntimeException` thay vì tự ý ghi đè hoặc tạo bản ghi thứ hai.
 * - Nếu `CENTRAL_WH` đã tồn tại hợp lệ, giữ nguyên toàn bộ dữ liệu vận hành hiện tại.
 */
class CentralWarehouseSeeder extends Seeder
{
    private const CANONICAL_CODE = 'CENTRAL_WH';

    private const CANONICAL_NAME = 'Kho Trung tâm Book_Store';

    /**
     * Chạy seeder kho trung tâm.
     */
    public function run(): void
    {
        $centralWarehouses = Branch::query()
            ->where('type', BranchType::CentralWarehouse->value)
            ->get();

        if ($centralWarehouses->count() > 1) {
            throw new \RuntimeException(sprintf(
                'Phát hiện %d bản ghi kho trung tâm trong bảng branches; hệ thống chỉ cho phép duy nhất một kho trung tâm.',
                $centralWarehouses->count(),
            ));
        }

        /** @var Branch|null $existingCentral */
        $existingCentral = $centralWarehouses->first();

        if ($existingCentral !== null && $existingCentral->code !== self::CANONICAL_CODE) {
            throw new \RuntimeException(sprintf(
                "Xung đột kho trung tâm: đã tồn tại bản ghi type='central_warehouse' với mã '%s' khác mã chuẩn '%s'.",
                $existingCentral->code,
                self::CANONICAL_CODE,
            ));
        }

        /** @var Branch|null $existingByCode */
        $existingByCode = Branch::query()
            ->where('code', self::CANONICAL_CODE)
            ->first();

        if ($existingByCode !== null && $existingByCode->type !== BranchType::CentralWarehouse) {
            throw new \RuntimeException(sprintf(
                "Xung đột mã chi nhánh '%s': bản ghi hiện có mang type='%s' thay vì 'central_warehouse'.",
                self::CANONICAL_CODE,
                $existingByCode->type->value,
            ));
        }

        if ($existingByCode !== null) {
            if ($existingByCode->status !== BranchStatus::Active) {
                throw new \RuntimeException(sprintf(
                    "Kho trung tâm '%s' đang ở trạng thái '%s'; kho trung tâm bắt buộc phải luôn hoạt động ('active').",
                    self::CANONICAL_CODE,
                    $existingByCode->status->value,
                ));
            }

            // Đã tồn tại hợp lệ: giữ nguyên dữ liệu vận hành đã tùy biến.
            return;
        }

        Branch::query()->create([
            'code' => self::CANONICAL_CODE,
            'name' => self::CANONICAL_NAME,
            'type' => BranchType::CentralWarehouse->value,
            'status' => BranchStatus::Active->value,
        ]);
    }
}
