<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Str;

/**
 * Dịch vụ truy vấn nhật ký kiểm toán an toàn cho khu vực Quản trị Book_Store (Stage 01B.5.2).
 *
 * Bảo đảm:
 * - Chỉ Quản trị viên cấp cao có hiệu lực (`isEffectiveSuperAdmin`) mới được truy cập lịch sử kiểm toán toàn hệ thống.
 * - Mọi bộ lọc và phân trang đều kiểm soát ở server, tải quan hệ eager (`actor`, `targetUser`, `branch`) tránh N+1.
 * - Tự động làm sạch (redact) mọi khóa nhạy cảm trước khi hiển thị ra giao diện.
 */
class AuditLogQueryService
{
    public const SENSITIVE_KEY_PATTERN = '/(password|secret|token|hash|credential|session|remember)/i';

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Giải mã an toàn chuỗi con trỏ (cursor) phân trang. Trả về null nếu chuỗi không hợp lệ thay vì ném ngoại lệ.
     */
    public function parseCursor(?string $cursor): ?Cursor
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        try {
            $parsed = Cursor::fromEncoded(trim($cursor));

            return $parsed instanceof Cursor ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Định dạng danh sách sự kiện kiểm toán cho phản hồi JSON hoặc danh sách xem trước an toàn.
     *
     * @param  iterable<int, AuditLog>  $items
     * @return array<int, array<string, mixed>>
     */
    public function formatAuditCollectionForPreview(iterable $items, ?User $actor = null): array
    {
        $canViewDetail = $actor !== null && $this->authorization->isEffectiveSuperAdmin($actor);
        $formatted = [];

        foreach ($items as $item) {
            /** @var AuditLog $item */
            $formatted[] = [
                'id' => $item->id,
                'action_value' => $item->action->value,
                'action_label' => $item->action->label(),
                'occurred_at' => format_datetime($item->occurred_at),
                'actor_name' => $item->actor !== null ? $item->actor->name : 'Hệ thống / CLI',
                'actor_email' => $item->actor?->email,
                'branch_name' => $item->branch?->name,
                'branch_code' => $item->branch?->code,
                'target_user_name' => $item->targetUser?->name,
                'target_user_url' => $item->targetUser !== null ? route('admin.staff.show', $item->targetUser) : null,
                'subject_summary' => class_basename((string) $item->subject_type).' #'.$item->subject_id,
                'reason' => $item->reason,
                'detail_url' => $canViewDetail ? route('admin.audit-logs.show', $item) : null,
            ];
        }

        return $formatted;
    }

    /**
     * Lấy tối đa 10 sự kiện kiểm toán gần nhất cho thẻ xem trước trên hồ sơ nhân sự.
     *
     * @return Collection<int, AuditLog>
     */
    public function recentForStaff(User $staff, int $limit = 10): Collection
    {
        return AuditLog::query()
            ->with(['actor', 'branch', 'targetUser'])
            ->where(function (Builder $query) use ($staff): void {
                $query->where('target_user_id', $staff->id)
                    ->orWhere(function (Builder $subQuery) use ($staff): void {
                        $subQuery->where('subject_type', User::class)
                            ->where('subject_id', $staff->id);
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Lấy tối đa 10 sự kiện kiểm toán gần nhất cho thẻ xem trước trên chi nhánh hoặc kho.
     *
     * @return Collection<int, AuditLog>
     */
    public function recentForBranch(Branch $branch, int $limit = 10): Collection
    {
        return AuditLog::query()
            ->with(['actor', 'branch', 'targetUser'])
            ->where(function (Builder $query) use ($branch): void {
                $query->where('branch_id', $branch->id)
                    ->orWhere(function (Builder $subQuery) use ($branch): void {
                        $subQuery->where('subject_type', Branch::class)
                            ->where('subject_id', $branch->id);
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Phân trang con trỏ (cursor pagination) nhật ký kiểm toán gắn với một nhân sự.
     *
     * @return array{
     *     items: Collection<int, AuditLog>,
     *     formatted: array<int, array<string, mixed>>,
     *     next_cursor: ?string,
     *     has_more: bool
     * }
     */
    public function cursorPaginateForStaff(User $staff, ?string $cursor = null, int $perPage = 10, ?User $actor = null): array
    {
        $cursorObj = $this->parseCursor($cursor);
        $safePerPage = max(5, min(50, $perPage));

        $query = AuditLog::query()
            ->with(['actor', 'branch', 'targetUser'])
            ->where(function (Builder $subQuery) use ($staff): void {
                $subQuery->where('target_user_id', $staff->id)
                    ->orWhere(function (Builder $q) use ($staff): void {
                        $q->where('subject_type', User::class)
                            ->where('subject_id', $staff->id);
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($safePerPage, ['*'], 'cursor', $cursorObj);

        $items = new Collection($paginator->items());

        return [
            'items' => $items,
            'formatted' => $this->formatAuditCollectionForPreview($items, $actor),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Phân trang con trỏ (cursor pagination) nhật ký kiểm toán gắn với một chi nhánh hoặc kho.
     *
     * @return array{
     *     items: Collection<int, AuditLog>,
     *     formatted: array<int, array<string, mixed>>,
     *     next_cursor: ?string,
     *     has_more: bool
     * }
     */
    public function cursorPaginateForBranch(Branch $branch, ?string $cursor = null, int $perPage = 10, ?User $actor = null): array
    {
        $cursorObj = $this->parseCursor($cursor);
        $safePerPage = max(5, min(50, $perPage));

        $query = AuditLog::query()
            ->with(['actor', 'branch', 'targetUser'])
            ->where(function (Builder $subQuery) use ($branch): void {
                $subQuery->where('branch_id', $branch->id)
                    ->orWhere(function (Builder $q) use ($branch): void {
                        $q->where('subject_type', Branch::class)
                            ->where('subject_id', $branch->id);
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($safePerPage, ['*'], 'cursor', $cursorObj);

        $items = new Collection($paginator->items());

        return [
            'items' => $items,
            'formatted' => $this->formatAuditCollectionForPreview($items, $actor),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Khởi tạo Query Builder lọc kiểm toán cho Super Administrator với eager loading.
     *
     * @param  array{
     *     search?: string|null,
     *     action?: string|null,
     *     actor_id?: int|null,
     *     target_user_id?: int|null,
     *     branch_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     per_page?: int|null
     * }  $filters
     */
    public function buildFilteredQuery(array $filters): Builder
    {
        $query = AuditLog::query()
            ->with(['actor', 'targetUser', 'branch'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $action = $filters['action'] ?? null;
        if (is_string($action) && $action !== '' && AuditAction::tryFrom($action) !== null) {
            $query->where('action', $action);
        }

        $actorId = $filters['actor_id'] ?? null;
        if (is_int($actorId) && $actorId > 0) {
            $query->where('actor_id', $actorId);
        }

        $targetUserId = $filters['target_user_id'] ?? null;
        if (is_int($targetUserId) && $targetUserId > 0) {
            $query->where(function (Builder $subQuery) use ($targetUserId): void {
                $subQuery->where('target_user_id', $targetUserId)
                    ->orWhere(function (Builder $subjectQuery) use ($targetUserId): void {
                        $subjectQuery->where('subject_type', User::class)
                            ->where('subject_id', $targetUserId);
                    });
            });
        }

        $branchId = $filters['branch_id'] ?? null;
        if (is_int($branchId) && $branchId > 0) {
            $query->where(function (Builder $subQuery) use ($branchId): void {
                $subQuery->where('branch_id', $branchId)
                    ->orWhere(function (Builder $subjectQuery) use ($branchId): void {
                        $subjectQuery->where('subject_type', Branch::class)
                            ->where('subject_id', $branchId);
                    });
            });
        }

        $displayTz = (string) config('app.display_timezone', 'Asia/Ho_Chi_Minh');

        $dateFrom = $filters['date_from'] ?? null;
        if (is_string($dateFrom) && $dateFrom !== '') {
            $utcStart = CarbonImmutable::createFromFormat('Y-m-d', $dateFrom, $displayTz)
                ->startOfDay()
                ->utc();
            $query->where('occurred_at', '>=', $utcStart);
        }

        $dateTo = $filters['date_to'] ?? null;
        if (is_string($dateTo) && $dateTo !== '') {
            $utcEnd = CarbonImmutable::createFromFormat('Y-m-d', $dateTo, $displayTz)
                ->endOfDay()
                ->utc();
            $query->where('occurred_at', '<=', $utcEnd);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search));
            $likePattern = '%'.$escaped.'%';

            $query->where(function (Builder $subQuery) use ($likePattern): void {
                $subQuery
                    ->whereRaw('LOWER(COALESCE(reason, \'\')) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereRaw('LOWER(action) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereRaw('LOWER(subject_type) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereHas('actor', function (Builder $actorQuery) use ($likePattern): void {
                        $actorQuery
                            ->whereRaw('LOWER(name) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                            ->orWhereRaw('LOWER(email) LIKE ? ESCAPE ?', [$likePattern, '\\']);
                    })
                    ->orWhereHas('targetUser', function (Builder $targetQuery) use ($likePattern): void {
                        $targetQuery
                            ->whereRaw('LOWER(name) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                            ->orWhereRaw('LOWER(email) LIKE ? ESCAPE ?', [$likePattern, '\\']);
                    })
                    ->orWhereHas('branch', function (Builder $branchQuery) use ($likePattern): void {
                        $branchQuery
                            ->whereRaw('LOWER(name) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                            ->orWhereRaw('LOWER(code) LIKE ? ESCAPE ?', [$likePattern, '\\']);
                    });
            });
        }

        return $query;
    }

    /**
     * Phân trang nhật ký kiểm toán toàn hệ thống kèm bộ lọc an toàn (chỉ dành cho Super Administrator).
     *
     * @param  array{
     *     search?: string|null,
     *     action?: string|null,
     *     actor_id?: int|null,
     *     target_user_id?: int|null,
     *     branch_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     per_page?: int|null
     * }  $filters
     *
     * @throws AuthorizationException
     */
    public function paginateForSuperAdmin(User $actor, array $filters): LengthAwarePaginator
    {
        if (! $this->authorization->isEffectiveSuperAdmin($actor)) {
            throw new AuthorizationException('Chỉ Quản trị viên cấp cao có hiệu lực mới được phép truy cập nhật ký hoạt động toàn hệ thống.');
        }

        $query = $this->buildFilteredQuery($filters);

        $perPage = $filters['per_page'] ?? 15;
        $safePerPage = max(10, min(50, is_int($perPage) ? $perPage : 15));

        return $query->paginate($safePerPage)->withQueryString();
    }

    /**
     * Phân trang con trỏ (cursor pagination) nhật ký kiểm toán toàn hệ thống kèm bộ lọc an toàn.
     *
     * @param  array{
     *     search?: string|null,
     *     action?: string|null,
     *     actor_id?: int|null,
     *     target_user_id?: int|null,
     *     branch_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     per_page?: int|null
     * }  $filters
     * @return array{
     *     items: Collection<int, AuditLog>,
     *     formatted: array<int, array<string, mixed>>,
     *     next_cursor: ?string,
     *     has_more: bool
     * }
     *
     * @throws AuthorizationException
     */
    public function cursorPaginateForSuperAdmin(User $actor, array $filters, ?string $cursor = null, int $perPage = 15): array
    {
        if (! $this->authorization->isEffectiveSuperAdmin($actor)) {
            throw new AuthorizationException('Chỉ Quản trị viên cấp cao có hiệu lực mới được phép truy cập nhật ký hoạt động toàn hệ thống.');
        }

        $cursorObj = $this->parseCursor($cursor);
        $safePerPage = max(5, min(50, $perPage));

        $query = $this->buildFilteredQuery($filters);

        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($safePerPage, ['*'], 'cursor', $cursorObj);

        $items = new Collection($paginator->items());

        return [
            'items' => $items,
            'formatted' => $this->formatAuditCollectionForPreview($items, $actor),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Truy xuất chi tiết một sự kiện kiểm toán đã được làm sạch (chỉ dành cho Super Administrator).
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function findForSuperAdmin(User $actor, AuditLog $auditLog): array
    {
        if (! $this->authorization->isEffectiveSuperAdmin($actor)) {
            throw new AuthorizationException('Chỉ Quản trị viên cấp cao có hiệu lực mới được phép xem chi tiết nhật ký kiểm toán.');
        }

        $auditLog->loadMissing([
            'actor:id,name,email',
            'targetUser:id,name,email',
            'branch:id,code,name',
        ]);

        return [
            'id' => $auditLog->id,
            'action_value' => $auditLog->action->value,
            'action_label' => $auditLog->action->label(),
            'occurred_at' => format_datetime($auditLog->occurred_at),
            'actor' => $auditLog->actor !== null
                ? $auditLog->actor->name.' ('.$auditLog->actor->email.')'
                : 'Hệ thống / CLI',
            'target_user' => $auditLog->targetUser !== null
                ? [
                    'name' => $auditLog->targetUser->name.' ('.$auditLog->targetUser->email.')',
                    'url' => route('admin.staff.show', $auditLog->targetUser),
                ]
                : null,
            'branch' => $auditLog->branch !== null
                ? $auditLog->branch->name.' ('.$auditLog->branch->code.')'
                : null,
            'subject_type' => class_basename((string) $auditLog->subject_type),
            'subject_id' => $auditLog->subject_id,
            'reason' => $auditLog->reason,
            'old_values' => self::redactPayload($auditLog->old_values),
            'new_values' => self::redactPayload($auditLog->new_values),
        ];
    }

    /**
     * Danh sách người thực hiện từng xuất hiện trong `audit_logs` để hiển thị bộ lọc.
     *
     * @return Collection<int, User>
     */
    public function availableActors(): Collection
    {
        $actorIds = AuditLog::query()
            ->whereNotNull('actor_id')
            ->distinct()
            ->pluck('actor_id')
            ->all();

        if ($actorIds === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Danh sách chi nhánh / kho để lọc ngữ cảnh.
     *
     * @return Collection<int, Branch>
     */
    public function availableBranches(): Collection
    {
        return Branch::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'type', 'status']);
    }

    /**
     * Làm sạch dữ liệu `old_values` / `new_values` trước khi hiển thị ra giao diện, bảo đảm không bao giờ lộ khóa nhạy cảm.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public static function redactPayload(?array $payload): array
    {
        if ($payload === null || $payload === []) {
            return [];
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            $stringKey = (string) $key;

            if (preg_match(self::SENSITIVE_KEY_PATTERN, $stringKey) === 1) {
                $sanitized[$stringKey] = '[Đã ẩn bảo mật]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$stringKey] = self::redactPayload($value);

                continue;
            }

            $sanitized[$stringKey] = $value;
        }

        return $sanitized;
    }
}
