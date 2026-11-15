<?php

declare(strict_types=1);

namespace App\Services\Branches;

use App\Enums\AuditAction;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use App\Services\Authorization\DomainGuardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Nghiệp vụ quản trị Chi nhánh bán lẻ và Kho trung tâm bất biến (Stage 01B.4).
 *
 * Flow tổng quát:
 * BranchController / CentralWarehouseController
 * -> BranchAdministrationService (xác minh quyền global + khóa giao dịch SQLite BEGIN IMMEDIATE
 *    + thực thi bất biến DomainGuardService + ghi nhận AuditLog bất biến)
 * -> Branch & AuditLog (SQLite)
 */
class BranchAdministrationService
{
    public const CENTRAL_WAREHOUSE_CODE = 'CENTRAL_WH';

    public const CENTRAL_WAREHOUSE_NAME = 'Kho Trung tâm Book_Store';

    public const RETAIL_CODE_REGEX = '/^[A-Z0-9_-]{3,32}$/';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly DomainGuardService $domainGuard,
    ) {}

    /**
     * Chuẩn hóa mã chi nhánh theo chuẩn in hoa không khoảng trắng thừa.
     */
    public function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    /**
     * Truy xuất bản ghi Kho trung tâm duy nhất của hệ thống (nếu đã khởi tạo).
     */
    public function getCentralWarehouse(): ?Branch
    {
        return Branch::query()
            ->where('type', BranchType::CentralWarehouse->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * Thống kê tổng quan các chi nhánh bán lẻ phục vụ màn hình quản trị.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    public function retailBranchSummary(): array
    {
        $baseQuery = Branch::query()
            ->where('type', BranchType::RetailBranch->value);

        $total = (clone $baseQuery)->count();
        $active = (clone $baseQuery)
            ->where('status', BranchStatus::Active->value)
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => max(0, $total - $active),
        ];
    }

    /**
     * Phân trang danh sách chi nhánh bán lẻ kèm tìm kiếm an toàn và lọc theo trạng thái.
     *
     * @throws AuthorizationException
     */
    public function paginateRetailBranches(
        User $actor,
        ?string $search = null,
        ?BranchStatus $status = null,
        int $perPage = 12,
    ): LengthAwarePaginator {
        $this->ensureGlobalPermission($actor, 'branches.view');

        $query = Branch::query()
            ->where('type', BranchType::RetailBranch->value);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $trimmedSearch = trim((string) $search);
        if ($trimmedSearch !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($trimmedSearch));
            $likePattern = '%'.$escaped.'%';

            $query->where(function ($subQuery) use ($likePattern): void {
                $subQuery
                    ->whereRaw('LOWER(code) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereRaw('LOWER(name) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereRaw('LOWER(COALESCE(address, \'\')) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ? ESCAPE ?', [$likePattern, '\\']);
            });
        }

        return $query
            ->orderBy('status')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Tạo mới một chi nhánh bán lẻ.
     *
     * Bất biến bắt buộc:
     * - Tác nhân phải có quyền toàn hệ thống `branches.create`.
     * - Loại thực thể bị khóa cứng là `BranchType::RetailBranch`.
     * - Không bao giờ được phép tạo mã `CENTRAL_WH` hoặc trùng mã hiện có (không phân biệt hoa/thường).
     * - Ghi nhật ký `AuditAction::BranchCreated` trong cùng giao dịch SQLite `BEGIN IMMEDIATE`.
     *
     * @param  array{
     *     code: string,
     *     name: string,
     *     type?: string|BranchType|null,
     *     status?: string|BranchStatus|null,
     *     address?: string|null,
     *     phone?: string|null,
     * }  $input
     *
     * @throws AuthorizationException|DomainInvariantException|ValidationException
     */
    public function createRetailBranch(
        User $actor,
        array $input,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Branch {
        $this->ensureGlobalPermission($actor, 'branches.create');

        if (array_key_exists('type', $input) && $input['type'] !== null && $input['type'] !== '') {
            $requestedType = $input['type'] instanceof BranchType
                ? $input['type']->value
                : (string) $input['type'];

            if ($requestedType !== BranchType::RetailBranch->value) {
                throw new DomainInvariantException(
                    'Mọi chi nhánh tạo mới từ phân hệ quản trị chỉ được phép mang loại Chi nhánh bán lẻ; không thể tạo Kho trung tâm thứ hai.',
                    'CANNOT_CREATE_CENTRAL_WAREHOUSE',
                    422,
                );
            }
        }

        $normalizedCode = $this->normalizeCode((string) ($input['code'] ?? ''));
        $normalizedName = trim((string) ($input['name'] ?? ''));
        $normalizedAddress = $this->normalizeNullableString($input['address'] ?? null);
        $normalizedPhone = $this->normalizeNullableString($input['phone'] ?? null);
        $targetStatus = $this->resolveStatusInput($input['status'] ?? BranchStatus::Active->value);

        $this->guardRetailBranchCode($normalizedCode);

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $normalizedCode,
            $normalizedName,
            $normalizedAddress,
            $normalizedPhone,
            $targetStatus,
            $ipAddress,
            $userAgent,
        ): Branch {
            if ($this->branchCodeExists($normalizedCode)) {
                throw ValidationException::withMessages([
                    'code' => 'Mã chi nhánh này đã tồn tại trong hệ thống. Vui lòng chọn mã khác.',
                ]);
            }

            $branch = new Branch;
            $branch->forceFill([
                'code' => $normalizedCode,
                'name' => $normalizedName,
                'type' => BranchType::RetailBranch,
                'status' => $targetStatus,
                'address' => $normalizedAddress,
                'phone' => $normalizedPhone,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'target_user_id' => null,
                'branch_id' => $branch->id,
                'action' => AuditAction::BranchCreated,
                'subject_type' => Branch::class,
                'subject_id' => $branch->id,
                'old_values' => null,
                'new_values' => [
                    'code' => $branch->code,
                    'name' => $branch->name,
                    'type' => $branch->type->value,
                    'status' => $branch->status->value,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                ],
                'reason' => 'Khởi tạo chi nhánh bán lẻ mới.',
                'ip_address' => $ipAddress,
                'user_agent' => $this->normalizeUserAgent($userAgent),
                'occurred_at' => now(),
            ]);

            return $branch->fresh() ?? $branch;
        }, attempts: 5);
    }

    /**
     * Cập nhật thông tin cho phép của một chi nhánh bán lẻ (`name`, `status`, `address`, `phone`).
     *
     * Bất biến bắt buộc:
     * - Từ chối tuyệt đối mọi tác động vào Kho trung tâm (`CENTRAL_WH`).
     * - Giữ nguyên bất biến `code` và `type` sau khi chi nhánh được khởi tạo.
     * - Ghi nhật ký `AuditAction::BranchUpdated` khi có thay đổi thực tế.
     *
     * @param  array{
     *     code?: string|null,
     *     name: string,
     *     type?: string|BranchType|null,
     *     status: string|BranchStatus,
     *     address?: string|null,
     *     phone?: string|null,
     * }  $input
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function updateRetailBranch(
        User $actor,
        Branch $branch,
        array $input,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Branch {
        $this->ensureGlobalPermission($actor, 'branches.update');

        $targetStatus = $this->resolveStatusInput($input['status']);
        $normalizedName = trim((string) ($input['name'] ?? ''));
        $normalizedAddress = $this->normalizeNullableString($input['address'] ?? null);
        $normalizedPhone = $this->normalizeNullableString($input['phone'] ?? null);

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $branch,
            $input,
            $targetStatus,
            $normalizedName,
            $normalizedAddress,
            $normalizedPhone,
            $ipAddress,
            $userAgent,
        ): Branch {
            /** @var Branch $lockedBranch */
            $lockedBranch = Branch::query()->findOrFail($branch->id);

            $this->assertMutableRetailBranch($lockedBranch, $input, $targetStatus);

            $oldValues = [
                'name' => $lockedBranch->name,
                'status' => $lockedBranch->status->value,
                'address' => $lockedBranch->address,
                'phone' => $lockedBranch->phone,
            ];

            $newValues = [
                'name' => $normalizedName,
                'status' => $targetStatus->value,
                'address' => $normalizedAddress,
                'phone' => $normalizedPhone,
            ];

            if ($oldValues === $newValues) {
                return $lockedBranch;
            }

            $lockedBranch->forceFill([
                'name' => $normalizedName,
                'status' => $targetStatus,
                'address' => $normalizedAddress,
                'phone' => $normalizedPhone,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'target_user_id' => null,
                'branch_id' => $lockedBranch->id,
                'action' => AuditAction::BranchUpdated,
                'subject_type' => Branch::class,
                'subject_id' => $lockedBranch->id,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'reason' => 'Cập nhật thông tin hành chính chi nhánh bán lẻ.',
                'ip_address' => $ipAddress,
                'user_agent' => $this->normalizeUserAgent($userAgent),
                'occurred_at' => now(),
            ]);

            return $lockedBranch->fresh() ?? $lockedBranch;
        }, attempts: 5);
    }

    /**
     * Kích hoạt lại hoặc ngừng hoạt động một chi nhánh bán lẻ.
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function setRetailBranchStatus(
        User $actor,
        Branch $branch,
        BranchStatus $targetStatus,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Branch {
        $this->ensureGlobalPermission($actor, 'branches.update');

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $branch,
            $targetStatus,
            $reason,
            $ipAddress,
            $userAgent,
        ): Branch {
            /** @var Branch $lockedBranch */
            $lockedBranch = Branch::query()->findOrFail($branch->id);

            $this->assertMutableRetailBranch($lockedBranch, [], $targetStatus);

            if ($lockedBranch->status === $targetStatus) {
                return $lockedBranch;
            }

            $previousStatus = $lockedBranch->status;

            $lockedBranch->forceFill([
                'status' => $targetStatus,
            ])->save();

            $cleanedReason = $this->normalizeNullableString($reason);
            $auditReason = $cleanedReason ?? ($targetStatus === BranchStatus::Inactive
                ? 'Ngừng hoạt động chi nhánh bán lẻ.'
                : 'Kích hoạt lại chi nhánh bán lẻ.');

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'target_user_id' => null,
                'branch_id' => $lockedBranch->id,
                'action' => AuditAction::BranchUpdated,
                'subject_type' => Branch::class,
                'subject_id' => $lockedBranch->id,
                'old_values' => [
                    'status' => $previousStatus->value,
                ],
                'new_values' => [
                    'status' => $targetStatus->value,
                ],
                'reason' => $auditReason,
                'ip_address' => $ipAddress,
                'user_agent' => $this->normalizeUserAgent($userAgent),
                'occurred_at' => now(),
            ]);

            return $lockedBranch->fresh() ?? $lockedBranch;
        }, attempts: 5);
    }

    /**
     * Bảo đảm người gọi thực sự sở hữu quyền toàn hệ thống được yêu cầu.
     *
     * @throws AuthorizationException
     */
    private function ensureGlobalPermission(User $actor, string $permissionCode): void
    {
        if (! $this->authorization->hasGlobalPermission($actor, $permissionCode)) {
            throw new AuthorizationException('Bạn không có quyền thực hiện thao tác quản trị chi nhánh này.');
        }
    }

    /**
     * Kiểm tra tính hợp lệ và không trùng mã dành riêng của Kho trung tâm khi tạo chi nhánh bán lẻ.
     */
    private function guardRetailBranchCode(string $normalizedCode): void
    {
        if ($normalizedCode === self::CENTRAL_WAREHOUSE_CODE) {
            throw new DomainInvariantException(
                'Mã CENTRAL_WH được dành riêng bất biến cho Kho trung tâm Book_Store.',
                'RESERVED_CENTRAL_WAREHOUSE_CODE',
                422,
            );
        }

        if (preg_match(self::RETAIL_CODE_REGEX, $normalizedCode) !== 1) {
            throw ValidationException::withMessages([
                'code' => 'Mã chi nhánh phải gồm 3–32 ký tự chữ in hoa, số, gạch ngang (-) hoặc gạch dưới (_).',
            ]);
        }
    }

    /**
     * Bảo đảm thực thể đang thao tác là chi nhánh bán lẻ hợp lệ và không thay đổi `code` / `type`.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertMutableRetailBranch(Branch $lockedBranch, array $input, BranchStatus $targetStatus): void
    {
        if ($lockedBranch->isCentralWarehouse()) {
            $requestedType = BranchType::CentralWarehouse;
            if (array_key_exists('type', $input) && $input['type'] !== null && $input['type'] !== '') {
                $requestedType = BranchType::tryFrom((string) $input['type']) ?? BranchType::RetailBranch;
            }

            // Gọi tường minh DomainGuardService để đồng bộ lớp kiểm soát bất biến Kho trung tâm.
            $this->domainGuard->ensureCentralWarehouseMutationAllowed(
                $lockedBranch,
                $requestedType,
                $targetStatus,
                false,
            );

            throw new DomainInvariantException(
                'Kho trung tâm Book_Store (CENTRAL_WH) là thực thể hệ thống bất biến và chỉ cho phép xem thông tin.',
                'CENTRAL_WAREHOUSE_IMMUTABLE',
                422,
            );
        }

        if (array_key_exists('type', $input) && $input['type'] !== null && $input['type'] !== '') {
            $requestedType = $input['type'] instanceof BranchType
                ? $input['type']->value
                : (string) $input['type'];

            if ($requestedType !== BranchType::RetailBranch->value) {
                if ($requestedType === BranchType::CentralWarehouse->value) {
                    $this->domainGuard->ensureCanEstablishCentralWarehouse($lockedBranch->id);
                }

                throw new DomainInvariantException(
                    'Không thể thay đổi loại chi nhánh bán lẻ sau khi đã khởi tạo.',
                    'BRANCH_TYPE_IMMUTABLE',
                    422,
                );
            }
        }

        if (array_key_exists('code', $input) && $input['code'] !== null && $input['code'] !== '') {
            $requestedCode = $this->normalizeCode((string) $input['code']);
            if ($requestedCode !== $lockedBranch->code) {
                throw new DomainInvariantException(
                    'Mã chi nhánh là định danh bất biến và không thể thay đổi sau khi tạo.',
                    'BRANCH_CODE_IMMUTABLE',
                    422,
                );
            }
        }
    }

    private function branchCodeExists(string $normalizedCode): bool
    {
        return Branch::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->exists();
    }

    private function resolveStatusInput(string|BranchStatus|null $status): BranchStatus
    {
        if ($status instanceof BranchStatus) {
            return $status;
        }

        $resolved = BranchStatus::tryFrom((string) ($status ?? BranchStatus::Active->value));

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'status' => 'Trạng thái chi nhánh không hợp lệ.',
            ]);
        }

        return $resolved;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $trimmed = trim($userAgent);

        return $trimmed === '' ? null : Str::limit($trimmed, 500, '');
    }

    private function configureSqliteBusyTimeout(mixed $connection): void
    {
        if ($connection->transactionLevel() === 0 && $connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA busy_timeout = 5000');
        }
    }
}
