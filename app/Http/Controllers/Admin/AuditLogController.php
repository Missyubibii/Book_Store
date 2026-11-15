<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogsRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Audit\AuditLogQueryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller HTTP hiển thị nhật ký hoạt động và kiểm toán toàn hệ thống cho Super Administrator (Stage 01B.5.2).
 *
 * Flow:
 * GET /quan-tri/nhat-ky-hoat-dong (`admin.audit-logs.index`, middleware `auth`, `account.active`, `staff.active`, `super_admin.effective`)
 * -> ListAuditLogsRequest -> AuditLogController::index -> AuditLogQueryService::paginateForSuperAdmin
 * -> View `admin.audit-logs.index`.
 */
class AuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogQueryService $auditQuery,
    ) {}

    public function index(ListAuditLogsRequest $request): View|JsonResponse
    {
        $actor = $request->user();
        $filters = $request->filters();

        if ($request->wantsJson() || $request->query('format') === 'json') {
            $cursor = $request->query('cursor');
            $perPage = max(5, min(50, (int) $request->query('per_page', 15)));

            try {
                $cursorResult = $this->auditQuery->cursorPaginateForSuperAdmin(
                    actor: $actor,
                    filters: $filters,
                    cursor: is_string($cursor) ? $cursor : null,
                    perPage: $perPage,
                );
            } catch (AuthorizationException $exception) {
                abort(403, $exception->getMessage());
            }

            return response()->json([
                'data' => $cursorResult['formatted'],
                'next_cursor' => $cursorResult['next_cursor'],
                'has_more' => $cursorResult['has_more'],
            ]);
        }

        try {
            $auditLogs = $this->auditQuery->paginateForSuperAdmin($actor, $filters);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $contextTargetUser = $filters['target_user_id'] !== null
            ? User::query()->select(['id', 'name', 'email'])->find($filters['target_user_id'])
            : null;

        $contextBranch = $filters['branch_id'] !== null
            ? Branch::query()->select(['id', 'code', 'name', 'type'])->find($filters['branch_id'])
            : null;

        return view('admin.audit-logs.index', [
            'auditLogs' => $auditLogs,
            'filters' => $filters,
            'actionOptions' => AuditAction::cases(),
            'actorOptions' => $this->auditQuery->availableActors(),
            'branchOptions' => $this->auditQuery->availableBranches(),
            'contextTargetUser' => $contextTargetUser,
            'contextBranch' => $contextBranch,
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        try {
            $detail = $this->auditQuery->findForSuperAdmin($request->user(), $auditLog);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        return response()->json([
            'data' => $detail,
        ]);
    }
}
