<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Role;
use App\Models\StaffActivationProposal;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Audit\AuditLogQueryService;
use App\Services\Authorization\AuthorizationService;
use App\Services\Authorization\DomainGuardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Dịch vụ Quản trị Nhân sự Cốt lõi (Stage 01B.5.1).
 *
 * Bảo đảm mọi quy trình tuyển dụng onboarding, liên kết khách hàng, phân công đa chi nhánh,
 * gán đa vai trò phạm vi chi nhánh, đề xuất kích hoạt, phê duyệt tách biệt, đình chỉ
 * và kiểm toán đều thực thi bất biến nghiệp vụ trong giao dịch SQLite `BEGIN IMMEDIATE`.
 */
class StaffAdministrationService
{
    private readonly AuditLogQueryService $auditQueryService;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly DomainGuardService $domainGuards,
        ?AuditLogQueryService $auditQuery = null,
    ) {
        $this->auditQueryService = $auditQuery ?? app(AuditLogQueryService::class);
    }

    /**
     * Xác định tác nhân có quyền vào không gian quản trị nhân sự (toàn hệ thống hoặc theo chi nhánh).
     */
    public function canViewStaffDirectory(?User $actor): bool
    {
        if (! $this->authorization->isEffectiveStaff($actor) || ! $this->authorization->hasCompletedOnboardingPasswordChange($actor)) {
            return false;
        }

        if ($this->authorization->isEffectiveSuperAdmin($actor)) {
            return true;
        }

        return $this->manageableBranchesFor($actor, 'staff.view')->isNotEmpty();
    }

    /**
     * Kiểm tra tác nhân có quyền xem hồ sơ của một nhân sự cụ thể hay không (chống rò rỉ xuyên chi nhánh).
     */
    public function canAccessStaffMember(?User $actor, User $staff): bool
    {
        if (! $this->canViewStaffDirectory($actor)) {
            return false;
        }

        if (
            $staff->staff_access_status === StaffAccessStatus::NotApplicable
            && ! $staff->staffProfile()->exists()
        ) {
            return false;
        }

        if ($this->authorization->isEffectiveSuperAdmin($actor)) {
            return true;
        }

        $viewableBranchIds = $this->manageableBranchesFor($actor, 'staff.view')->pluck('id')->all();

        if ($viewableBranchIds === []) {
            return false;
        }

        $hasScopedAssignment = BranchAssignment::query()
            ->where('user_id', $staff->id)
            ->whereIn('branch_id', $viewableBranchIds)
            ->exists();

        if ($hasScopedAssignment) {
            return true;
        }

        return StaffActivationProposal::query()
            ->where('user_id', $staff->id)
            ->whereIn('branch_id', $viewableBranchIds)
            ->exists();
    }

    /**
     * Danh sách các chi nhánh đang hoạt động nơi tác nhân giữ một quyền phạm vi chi nhánh cụ thể.
     *
     * @return Collection<int, Branch>
     */
    public function manageableBranchesFor(?User $actor, string $permissionCode): Collection
    {
        if (! $this->authorization->isEffectiveStaff($actor) || ! $this->authorization->hasCompletedOnboardingPasswordChange($actor)) {
            return new Collection;
        }

        $activeBranches = Branch::query()
            ->where('status', BranchStatus::Active->value)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($this->authorization->isEffectiveSuperAdmin($actor)) {
            return $activeBranches;
        }

        return $activeBranches->filter(
            fn (Branch $branch): bool => $this->authorization->hasBranchPermission($actor, $permissionCode, $branch)
        )->values();
    }

    /**
     * Danh sách các chi nhánh được hiển thị trong bộ lọc danh mục nhân sự.
     *
     * @return Collection<int, Branch>
     */
    public function viewableBranchesFor(User $actor): Collection
    {
        if ($this->authorization->isEffectiveSuperAdmin($actor)) {
            return Branch::query()
                ->orderBy('status')
                ->orderBy('name')
                ->orderBy('id')
                ->get();
        }

        return $this->manageableBranchesFor($actor, 'staff.view');
    }

    /**
     * Danh mục vai trò phạm vi chi nhánh hợp lệ (`RoleScope::Branch` — tuyệt đối không trả về vai trò `global`).
     *
     * @return Collection<int, Role>
     */
    public function availableBranchRoles(): Collection
    {
        return Role::query()
            ->where('scope', RoleScope::Branch->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * Thống kê tổng quan nhân sự trong phạm vi mà tác nhân được phép quan sát.
     *
     * @return array{total: int, pending: int, active: int, inactive: int, pendingProposals: int}
     */
    public function staffSummary(User $actor): array
    {
        $isSuperAdmin = $this->authorization->isEffectiveSuperAdmin($actor);
        $scopedBranchIds = $isSuperAdmin ? [] : $this->manageableBranchesFor($actor, 'staff.view')->pluck('id')->all();

        if (! $isSuperAdmin && $scopedBranchIds === []) {
            return [
                'total' => 0,
                'pending' => 0,
                'active' => 0,
                'inactive' => 0,
                'pendingProposals' => 0,
            ];
        }

        $baseQuery = $this->scopedStaffBaseQuery($isSuperAdmin, $scopedBranchIds);

        $total = (clone $baseQuery)->count();
        $pending = (clone $baseQuery)->where('staff_access_status', StaffAccessStatus::Pending->value)->count();
        $active = (clone $baseQuery)->where('staff_access_status', StaffAccessStatus::Active->value)->count();
        $inactive = (clone $baseQuery)->where('staff_access_status', StaffAccessStatus::Inactive->value)->count();

        $pendingProposals = $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation')
            ? StaffActivationProposal::query()->where('status', StaffApprovalStatus::Pending->value)->count()
            : StaffActivationProposal::query()
                ->where('status', StaffApprovalStatus::Pending->value)
                ->whereIn('branch_id', $scopedBranchIds)
                ->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'active' => $active,
            'inactive' => $inactive,
            'pendingProposals' => $pendingProposals,
        ];
    }

    /**
     * Phân trang danh sách nhân sự trong phạm vi cho phép kèm tìm kiếm & lọc chi nhánh/trạng thái.
     *
     * @throws AuthorizationException
     */
    public function paginateStaff(
        User $actor,
        ?string $search = null,
        ?StaffAccessStatus $status = null,
        ?int $branchId = null,
        int $perPage = 12,
    ): LengthAwarePaginator {
        if (! $this->canViewStaffDirectory($actor)) {
            throw new AuthorizationException('Bạn không có quyền xem danh sách nhân sự.');
        }

        $isSuperAdmin = $this->authorization->isEffectiveSuperAdmin($actor);
        $scopedBranchIds = $isSuperAdmin ? [] : $this->manageableBranchesFor($actor, 'staff.view')->pluck('id')->all();

        if ($branchId !== null && ! $isSuperAdmin && ! in_array($branchId, $scopedBranchIds, true)) {
            throw new AuthorizationException('Bạn không được phép lọc danh sách nhân sự thuộc chi nhánh ngoài phạm vi quản lý.');
        }

        $query = $this->scopedStaffBaseQuery($isSuperAdmin, $scopedBranchIds)
            ->with([
                'staffProfile',
                'branchAssignments.branch',
                'branchRoleAssignments.role',
                'userRoles.role',
                'staffActivationProposals' => fn ($q) => $q->latest('id'),
            ]);

        if ($status !== null) {
            if ($status === StaffAccessStatus::NotApplicable) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('staff_access_status', $status->value);
            }
        }

        if ($branchId !== null) {
            $query->whereHas('branchAssignments', function ($subQuery) use ($branchId): void {
                $subQuery->where('branch_id', $branchId);
            });
        }

        $trimmedSearch = trim((string) $search);
        if ($trimmedSearch !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($trimmedSearch));
            $likePattern = '%'.$escaped.'%';

            $query->where(function ($subQuery) use ($likePattern): void {
                $subQuery
                    ->whereRaw('LOWER(name) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereRaw('LOWER(email) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                    ->orWhereHas('staffProfile', function ($profileQuery) use ($likePattern): void {
                        $profileQuery
                            ->whereRaw('LOWER(staff_code) LIKE ? ESCAPE ?', [$likePattern, '\\'])
                            ->orWhereRaw('LOWER(COALESCE(job_title, \'\')) LIKE ? ESCAPE ?', [$likePattern, '\\']);
                    });
            });
        }

        return $query
            ->orderByRaw("CASE staff_access_status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 WHEN 'inactive' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Truy xuất đầy đủ ngữ cảnh phân công, vai trò hiệu lực, đề xuất kích hoạt và nhật ký kiểm toán của một nhân sự.
     *
     * @return array{
     *     staff: User,
     *     assignmentsWithRoles: list<array{
     *         assignment: BranchAssignment,
     *         branch: Branch,
     *         roles: Collection<int, Role>,
     *         effectivePermissions: list<string>,
     *         canManageAssignment: bool,
     *         canProposeActivation: bool,
     *         canSuspendInBranch: bool
     *     }>,
     *     globalRoles: Collection<int, UserRole>,
     *     proposals: Collection<int, StaffActivationProposal>,
     *     pendingProposal: ?StaffActivationProposal,
     *     recentAudits: Collection<int, AuditLog>
     * }
     *
     * @throws AuthorizationException
     */
    public function getStaffDetailsPayload(User $actor, User $staff): array
    {
        if (! $this->canAccessStaffMember($actor, $staff)) {
            throw new AuthorizationException('Bạn không có quyền truy cập hồ sơ nhân sự này.');
        }

        $staff->load([
            'staffProfile',
            'branchAssignments.branch',
            'userRoles.role',
            'staffActivationProposals.branch',
            'staffActivationProposals.proposedBy',
            'staffActivationProposals.reviewedBy',
        ]);

        $assignmentsWithRoles = [];

        foreach ($staff->branchAssignments as $assignment) {
            /** @var Branch|null $branch */
            $branch = $assignment->branch;
            if ($branch === null) {
                continue;
            }

            // Dùng phương thức chuẩn hóa theo cặp (user_id, branch_id), không dùng quan hệ khóa đơn.
            $roleIds = $assignment->matchingRoleAssignments()
                ->pluck('role_id')
                ->all();

            $roles = $roleIds === []
                ? new Collection
                : Role::query()
                    ->whereIn('id', $roleIds)
                    ->where('scope', RoleScope::Branch->value)
                    ->orderBy('id')
                    ->get();

            $effectivePermissions = $assignment->status === BranchAssignmentStatus::Active && $branch->isActive()
                ? $this->authorization->effectiveBranchPermissions($staff, $branch)
                : [];

            $assignmentsWithRoles[] = [
                'assignment' => $assignment,
                'branch' => $branch,
                'roles' => $roles,
                'effectivePermissions' => $effectivePermissions,
                'canManageAssignment' => ! $this->domainGuards->isSelfAction($actor, $staff)
                    && $branch->isActive()
                    && $this->authorization->hasBranchPermission($actor, 'branches.assign_staff', $branch)
                    && $this->authorization->hasBranchPermission($actor, 'roles.assign_branch', $branch),
                'canProposeActivation' => $actor->can('propose', [StaffActivationProposal::class, $staff, $branch]),
                'canSuspendInBranch' => $assignment->status === BranchAssignmentStatus::Active
                    && $branch->isActive()
                    && ! $this->domainGuards->isSelfAction($actor, $staff)
                    && $this->authorization->hasBranchPermission($actor, 'staff.suspend_branch', $branch),
            ];
        }

        $pendingProposal = $staff->staffActivationProposals
            ->first(fn (StaffActivationProposal $p): bool => $p->status === StaffApprovalStatus::Pending);

        $auditPage = $this->auditQueryService->cursorPaginateForStaff($staff, null, 10, $actor);

        $latestAssignmentUpdatedAt = $staff->branchAssignments
            ->map(fn (BranchAssignment $a) => $a->updated_at ?? $a->assigned_at)
            ->filter()
            ->sortByDesc(fn ($dt) => $dt->getTimestamp())
            ->first();

        $hasCentralWarehouseAssignment = collect($assignmentsWithRoles)
            ->contains(fn (array $item): bool => $item['branch']->type === BranchType::CentralWarehouse);

        $hasActiveBranchAndRole = collect($assignmentsWithRoles)
            ->contains(fn (array $item): bool => $item['assignment']->status === BranchAssignmentStatus::Active
                && $item['branch']->isActive()
                && $item['roles']->isNotEmpty());

        [$effectiveAccessSummary, $nextActionGuide] = $this->resolveStaffDiagnosticSummary(
            $staff,
            $pendingProposal,
            $hasActiveBranchAndRole,
            $hasCentralWarehouseAssignment,
        );

        return [
            'staff' => $staff,
            'assignmentsWithRoles' => $assignmentsWithRoles,
            'globalRoles' => $staff->userRoles,
            'proposals' => $staff->staffActivationProposals,
            'pendingProposal' => $pendingProposal,
            'recentAudits' => $auditPage['items'],
            'nextAuditCursor' => $auditPage['next_cursor'],
            'hasMoreAudits' => $auditPage['has_more'],
            'latestAssignmentUpdatedAt' => $latestAssignmentUpdatedAt,
            'hasCentralWarehouseAssignment' => $hasCentralWarehouseAssignment,
            'effectiveAccessSummary' => $effectiveAccessSummary,
            'nextActionGuide' => $nextActionGuide,
        ];
    }

    /**
     * Phân trang con trỏ nhật ký kiểm toán cho một nhân sự cụ thể (kèm kiểm tra phân quyền chặt chẽ).
     *
     * @return array{
     *     items: Collection<int, AuditLog>,
     *     formatted: array<int, array<string, mixed>>,
     *     next_cursor: ?string,
     *     has_more: bool
     * }
     *
     * @throws AuthorizationException
     */
    public function cursorPaginateStaffAudits(User $actor, User $staff, ?string $cursor = null, int $perPage = 10): array
    {
        if (! $this->canAccessStaffMember($actor, $staff)) {
            throw new AuthorizationException('Bạn không có quyền truy cập hồ sơ nhân sự này.');
        }

        return $this->auditQueryService->cursorPaginateForStaff($staff, $cursor, $perPage, $actor);
    }

    /**
     * Tổng hợp trạng thái hiệu lực thực tế và bước tiếp theo cần thực hiện cho hồ sơ nhân sự.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveStaffDiagnosticSummary(
        User $staff,
        ?StaffActivationProposal $pendingProposal,
        bool $hasActiveBranchAndRole,
        bool $hasCentralWarehouseAssignment,
    ): array {
        if ($staff->account_status !== AccountStatus::Active) {
            return [
                'Bị chặn hoàn toàn (Tài khoản chung đang bị khóa).',
                'Tài khoản người dùng đang ở trạng thái khóa nên mọi quyền nhân sự và khách hàng đều bị vô hiệu.',
            ];
        }

        if ($staff->staff_access_status === StaffAccessStatus::Inactive) {
            return [
                'Chưa có hiệu lực nội bộ (Quyền nhân sự đang Ngừng hoạt động).',
                'Bước tiếp theo: Cần khôi phục quyền nhân sự (nếu đủ điều kiện) và bảo đảm có phân công chi nhánh đang hoạt động.',
            ];
        }

        if ($staff->staff_access_status === StaffAccessStatus::Pending) {
            if ($staff->must_change_password) {
                return [
                    'Chưa có hiệu lực nội bộ (Chờ đổi mật khẩu tạm & Chờ kích hoạt).',
                    'Bước tiếp theo: Nhân sự cần đăng nhập bằng mật khẩu tạm còn hiệu lực để đổi mật khẩu cá nhân, sau đó gửi đề xuất và chờ Quản trị viên cấp cao phê duyệt.',
                ];
            }

            if ($pendingProposal !== null) {
                return [
                    'Chưa có hiệu lực nội bộ (Đã đổi mật khẩu, đang có đề xuất Chờ phê duyệt).',
                    'Bước tiếp theo: Quản trị viên cấp cao thẩm định và phê duyệt đề xuất kích hoạt để mở quyền truy cập nội bộ.',
                ];
            }

            if (! $hasActiveBranchAndRole) {
                return [
                    'Chưa có hiệu lực nội bộ (Chờ phân công chi nhánh & vai trò).',
                    'Bước tiếp theo: Phân công ít nhất 1 chi nhánh đang hoạt động kèm vai trò chi nhánh, sau đó gửi đề xuất kích hoạt.',
                ];
            }

            return [
                'Chưa có hiệu lực nội bộ (Đã hoàn tất đổi mật khẩu & đã lưu phân công, đang Chờ kích hoạt).',
                'Bước tiếp theo: Gửi đề xuất kích hoạt ở mục "Đề xuất & Kích hoạt Nhân sự" để chờ phê duyệt, hoặc Quản trị viên cấp cao có thể thực hiện Kích hoạt trực tiếp.',
            ];
        }

        if ($staff->must_change_password) {
            return [
                'Tạm hạn chế vào khu vực quản trị (Chưa hoàn tất đổi mật khẩu bắt buộc).',
                'Bước tiếp theo: Nhân sự phải hoàn tất bước đổi mật khẩu onboarding trước khi sử dụng quyền nội bộ.',
            ];
        }

        if ($hasCentralWarehouseAssignment) {
            return [
                'Đã kích hoạt quyền nhân sự nội bộ (Lưu ý riêng về Kho trung tâm).',
                'Phân công tại Kho trung tâm (CENTRAL_WH) đã được lưu, tuy nhiên vai trò Quản lý chi nhánh không tự động cấp quyền quản trị Kho trung tâm cho đến khi danh mục quyền Kho chuyên biệt được phê duyệt.',
            ];
        }

        return [
            'Đang hoạt động đầy đủ theo phạm vi chi nhánh và vai trò đã gán.',
            'Nhân sự đã đủ điều kiện đăng nhập và thực hiện tác nghiệp trong phạm vi vai trò chi nhánh được cấp.',
        ];
    }

    /**
     * Khởi tạo mới tài khoản nhân sự kèm mật khẩu tạm thời có hiệu lực 24 giờ và hồ sơ ban đầu ở trạng thái chờ phê duyệt.
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     temporary_password: string,
     *     staff_code?: string|null,
     *     job_title?: string|null,
     *     hired_at?: string|null,
     *     notes?: string|null,
     *     role_ids?: list<int|string>|null
     * }  $input
     *
     * @throws AuthorizationException|DomainInvariantException|ValidationException
     */
    public function createStaffAccount(
        User $actor,
        Branch $branch,
        array $input,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        if (! $actor->can('createStaff', [User::class, $branch])) {
            throw new AuthorizationException('Bạn không có quyền tạo tài khoản nhân sự mới tại chi nhánh này.');
        }

        $activeBranch = $this->domainGuards->ensureValidActiveBranchContext($branch);

        $name = trim((string) ($input['name'] ?? ''));
        $email = Str::lower(trim((string) ($input['email'] ?? '')));
        $plainTempPassword = (string) ($input['temporary_password'] ?? '');
        $jobTitle = $this->normalizeNullableString($input['job_title'] ?? null);
        $notes = $this->normalizeNullableString($input['notes'] ?? null);
        $hiredAt = $input['hired_at'] ?? now()->toDateString();

        $this->validatePasswordPolicy($plainTempPassword, 'temporary_password');

        $branchRoles = $this->resolveBranchRolesOrDefault($input['role_ids'] ?? []);
        $this->assertBranchRolesAllowedForBranch($activeBranch, $branchRoles);
        $ttlHours = max(1, (int) config('auth.staff_temporary_password_ttl_hours', 24));

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $activeBranch,
            $name,
            $email,
            $plainTempPassword,
            $input,
            $jobTitle,
            $notes,
            $hiredAt,
            $branchRoles,
            $ttlHours,
            $ipAddress,
            $userAgent,
        ): User {
            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Email này đã tồn tại trong hệ thống. Nếu đây là tài khoản khách hàng, vui lòng sử dụng luồng Liên kết tài khoản sẵn có.',
                ]);
            }

            $staffCode = $this->resolveOrGenerateStaffCode($input['staff_code'] ?? null);
            $issuedAt = now();
            $expiresAt = (clone $issuedAt)->addHours($ttlHours);

            $staff = new User;
            $staff->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $plainTempPassword,
                'email_verified_at' => $issuedAt,
                'account_status' => AccountStatus::Active,
                'staff_access_status' => StaffAccessStatus::Pending,
                'must_change_password' => true,
                'temp_password_issued_at' => $issuedAt,
                'temp_password_expires_at' => $expiresAt,
                'temp_password_issued_by' => $actor->id,
                'password_changed_at' => null,
            ])->save();

            $profile = new StaffProfile;
            $profile->forceFill([
                'user_id' => $staff->id,
                'staff_code' => $staffCode,
                'job_title' => $jobTitle,
                'hired_at' => $hiredAt,
                'activated_at' => null,
                'suspended_at' => null,
                'notes' => $notes,
            ])->save();

            BranchAssignment::query()->create([
                'user_id' => $staff->id,
                'branch_id' => $activeBranch->id,
                'status' => BranchAssignmentStatus::Active,
                'is_primary' => true,
                'assigned_by' => $actor->id,
                'assigned_at' => $issuedAt,
            ]);

            foreach ($branchRoles as $role) {
                BranchRoleAssignment::query()->create([
                    'user_id' => $staff->id,
                    'branch_id' => $activeBranch->id,
                    'role_id' => $role->id,
                    'role_scope' => RoleScope::Branch->value,
                    'assigned_by' => $actor->id,
                    'assigned_at' => $issuedAt,
                ]);
            }

            $this->writeAudit(
                actor: $actor,
                targetUser: $staff,
                branch: $activeBranch,
                action: AuditAction::StaffCreated,
                subjectType: User::class,
                subjectId: $staff->id,
                oldValues: null,
                newValues: [
                    'email' => $staff->email,
                    'staff_code' => $staffCode,
                    'staff_access_status' => StaffAccessStatus::Pending->value,
                    'branch_code' => $activeBranch->code,
                ],
                reason: 'Tạo tài khoản nhân sự mới ở trạng thái chờ kích hoạt.',
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            // Zero-Secret Rule: tuyệt đối không lưu mật khẩu tạm vào AuditLog.
            $this->writeAudit(
                actor: $actor,
                targetUser: $staff,
                branch: $activeBranch,
                action: AuditAction::TempPasswordIssued,
                subjectType: User::class,
                subjectId: $staff->id,
                oldValues: null,
                newValues: [
                    'must_change_password' => true,
                    'temp_password_expires_at' => $expiresAt->toIso8601String(),
                    'ttl_hours' => $ttlHours,
                ],
                reason: 'Khởi tạo mật khẩu tạm thời có hiệu lực '.$ttlHours.' giờ cho nhân sự mới.',
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $this->writeAudit(
                actor: $actor,
                targetUser: $staff,
                branch: $activeBranch,
                action: AuditAction::BranchAssigned,
                subjectType: BranchAssignment::class,
                subjectId: null,
                oldValues: null,
                newValues: [
                    'branch_id' => $activeBranch->id,
                    'branch_code' => $activeBranch->code,
                    'is_primary' => true,
                    'role_codes' => $branchRoles->pluck('code')->all(),
                ],
                reason: 'Phân công chi nhánh khởi tạo cho nhân sự mới.',
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $staff->fresh(['staffProfile', 'branchAssignments.branch']) ?? $staff;
        }, attempts: 5);
    }

    /**
     * Liên kết một tài khoản khách hàng sẵn có với hồ sơ nhân sự mà KHÔNG thay đổi mật khẩu khách hàng.
     *
     * @param  array{
     *     staff_code?: string|null,
     *     job_title?: string|null,
     *     hired_at?: string|null,
     *     notes?: string|null,
     *     role_ids?: list<int|string>|null
     * }  $input
     *
     * @throws AuthorizationException|DomainInvariantException|ValidationException
     */
    public function linkExistingCustomerToStaff(
        User $actor,
        User $customer,
        Branch $branch,
        array $input = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        if (! $actor->can('linkCustomerToStaff', [$customer, $branch])) {
            throw new AuthorizationException('Bạn không có quyền liên kết tài khoản khách hàng này thành nhân sự tại chi nhánh đã chọn.');
        }

        $activeBranch = $this->domainGuards->ensureValidActiveBranchContext($branch);
        $branchRoles = $this->resolveBranchRolesOrDefault($input['role_ids'] ?? []);
        $this->assertBranchRolesAllowedForBranch($activeBranch, $branchRoles);
        $jobTitle = $this->normalizeNullableString($input['job_title'] ?? null);
        $notes = $this->normalizeNullableString($input['notes'] ?? null);
        $hiredAt = $input['hired_at'] ?? now()->toDateString();

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $customer,
            $activeBranch,
            $branchRoles,
            $input,
            $jobTitle,
            $notes,
            $hiredAt,
            $ipAddress,
            $userAgent,
        ): User {
            /** @var User $lockedCustomer */
            $lockedCustomer = User::query()->findOrFail($customer->id);

            $this->domainGuards->ensureCustomerLinkingAllowed($actor, $lockedCustomer);

            if ($lockedCustomer->account_status !== AccountStatus::Active) {
                throw new DomainInvariantException(
                    'Không thể liên kết tài khoản khách hàng đang bị khóa sang hồ sơ nhân sự.',
                    'customer_account_suspended',
                    422,
                );
            }

            $staffCode = $this->resolveOrGenerateStaffCode($input['staff_code'] ?? null);
            $now = now();

            // Giữ nguyên hoàn toàn mật khẩu, remember_token và trạng thái must_change_password hiện có của khách hàng.
            $lockedCustomer->forceFill([
                'staff_access_status' => StaffAccessStatus::Pending,
            ])->save();

            $profile = new StaffProfile;
            $profile->forceFill([
                'user_id' => $lockedCustomer->id,
                'staff_code' => $staffCode,
                'job_title' => $jobTitle,
                'hired_at' => $hiredAt,
                'activated_at' => null,
                'suspended_at' => null,
                'notes' => $notes,
            ])->save();

            BranchAssignment::query()->create([
                'user_id' => $lockedCustomer->id,
                'branch_id' => $activeBranch->id,
                'status' => BranchAssignmentStatus::Active,
                'is_primary' => true,
                'assigned_by' => $actor->id,
                'assigned_at' => $now,
            ]);

            foreach ($branchRoles as $role) {
                BranchRoleAssignment::query()->create([
                    'user_id' => $lockedCustomer->id,
                    'branch_id' => $activeBranch->id,
                    'role_id' => $role->id,
                    'role_scope' => RoleScope::Branch->value,
                    'assigned_by' => $actor->id,
                    'assigned_at' => $now,
                ]);
            }

            $this->writeAudit(
                actor: $actor,
                targetUser: $lockedCustomer,
                branch: $activeBranch,
                action: AuditAction::StaffLinked,
                subjectType: User::class,
                subjectId: $lockedCustomer->id,
                oldValues: [
                    'staff_access_status' => StaffAccessStatus::NotApplicable->value,
                ],
                newValues: [
                    'staff_access_status' => StaffAccessStatus::Pending->value,
                    'staff_code' => $staffCode,
                    'branch_code' => $activeBranch->code,
                    'role_codes' => $branchRoles->pluck('code')->all(),
                ],
                reason: 'Liên kết tài khoản khách hàng sẵn có sang hồ sơ nhân sự (bảo toàn thông tin đăng nhập hiện tại).',
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $lockedCustomer->fresh(['staffProfile', 'branchAssignments.branch']) ?? $lockedCustomer;
        }, attempts: 5);
    }

    /**
     * Phân công hoặc cập nhật chi nhánh cùng danh sách vai trò phạm vi chi nhánh (hỗ trợ đa chi nhánh & đa vai trò).
     *
     * @param  list<int|string>  $roleIds
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function assignBranchAndRoles(
        User $actor,
        User $staff,
        Branch $branch,
        array $roleIds,
        bool $isPrimary = false,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): BranchAssignment {
        $this->domainGuards->ensureNotSelfAction(
            $actor,
            $staff,
            'Bạn không được phép tự thay đổi phân công chi nhánh hoặc vai trò của chính tài khoản mình.',
        );

        if (! $actor->can('assignStaff', $branch) || ! $actor->can('assignBranchRole', $branch)) {
            throw new AuthorizationException('Bạn không có quyền phân công nhân sự hoặc gán vai trò tại chi nhánh này.');
        }

        $activeBranch = $this->domainGuards->ensureValidActiveBranchContext($branch);

        $branchRoles = $this->resolveRequiredBranchRoles($roleIds);
        $this->assertBranchRolesAllowedForBranch($activeBranch, $branchRoles);

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $staff,
            $activeBranch,
            $branchRoles,
            $isPrimary,
            $ipAddress,
            $userAgent,
        ): BranchAssignment {
            /** @var User $lockedStaff */
            $lockedStaff = User::query()->findOrFail($staff->id);

            if (
                $lockedStaff->staff_access_status === StaffAccessStatus::NotApplicable
                || ! $lockedStaff->staffProfile()->exists()
            ) {
                throw new DomainInvariantException(
                    'Chỉ tài khoản đã có hồ sơ nhân sự mới được phân công chi nhánh.',
                    'staff_profile_missing',
                    422,
                );
            }

            $now = now();

            /** @var BranchAssignment|null $assignment */
            $assignment = BranchAssignment::query()
                ->where('user_id', $lockedStaff->id)
                ->where('branch_id', $activeBranch->id)
                ->first();

            $existingRoleIds = BranchRoleAssignment::query()
                ->where('user_id', $lockedStaff->id)
                ->where('branch_id', $activeBranch->id)
                ->pluck('role_id')
                ->all();

            $hasAnyOtherPrimary = BranchAssignment::query()
                ->where('user_id', $lockedStaff->id)
                ->where('branch_id', '!=', $activeBranch->id)
                ->where('status', BranchAssignmentStatus::Active->value)
                ->where('is_primary', true)
                ->exists();

            $shouldBePrimary = $isPrimary || ! $hasAnyOtherPrimary;

            if ($shouldBePrimary) {
                BranchAssignment::query()
                    ->where('user_id', $lockedStaff->id)
                    ->where('branch_id', '!=', $activeBranch->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            if ($assignment === null) {
                $assignment = BranchAssignment::query()->create([
                    'user_id' => $lockedStaff->id,
                    'branch_id' => $activeBranch->id,
                    'status' => BranchAssignmentStatus::Active,
                    'is_primary' => $shouldBePrimary,
                    'assigned_by' => $actor->id,
                    'assigned_at' => $now,
                ]);
            } else {
                $assignment->forceFill([
                    'status' => BranchAssignmentStatus::Active,
                    'is_primary' => $shouldBePrimary,
                    'assigned_by' => $actor->id,
                    'assigned_at' => $now,
                ])->save();
            }

            $targetRoleIds = $branchRoles->pluck('id')->all();

            $roleIdsToRemove = array_values(array_diff($existingRoleIds, $targetRoleIds));
            $roleIdsToAdd = array_values(array_diff($targetRoleIds, $existingRoleIds));

            if ($roleIdsToRemove !== []) {
                BranchRoleAssignment::query()
                    ->where('user_id', $lockedStaff->id)
                    ->where('branch_id', $activeBranch->id)
                    ->whereIn('role_id', $roleIdsToRemove)
                    ->delete();
            }

            foreach ($roleIdsToAdd as $roleId) {
                BranchRoleAssignment::query()->create([
                    'user_id' => $lockedStaff->id,
                    'branch_id' => $activeBranch->id,
                    'role_id' => $roleId,
                    'role_scope' => RoleScope::Branch->value,
                    'assigned_by' => $actor->id,
                    'assigned_at' => $now,
                ]);
            }

            $this->writeAudit(
                actor: $actor,
                targetUser: $lockedStaff,
                branch: $activeBranch,
                action: AuditAction::BranchAssigned,
                subjectType: BranchAssignment::class,
                subjectId: $assignment->id,
                oldValues: [
                    'role_ids' => $existingRoleIds,
                ],
                newValues: [
                    'branch_code' => $activeBranch->code,
                    'status' => BranchAssignmentStatus::Active->value,
                    'is_primary' => $shouldBePrimary,
                    'role_codes' => $branchRoles->pluck('code')->all(),
                ],
                reason: 'Cập nhật phân công chi nhánh và vai trò chi nhánh cho nhân sự.',
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $assignment->fresh(['branch']) ?? $assignment;
        }, attempts: 5);
    }

    /**
     * Vô hiệu hóa phân công chi nhánh của nhân sự và tự động tắt quyền nhân sự nếu không còn phân công hiệu lực
     * lẫn quyền toàn cục (Decision 2.7), đồng thời bảo vệ Super Administrator hiệu lực cuối cùng.
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function deactivateBranchAssignment(
        User $actor,
        User $staff,
        Branch $branch,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        $this->domainGuards->ensureNotSelfAction(
            $actor,
            $staff,
            'Bạn không được phép tự gỡ phân công chi nhánh của chính mình.',
        );

        $isSuperAdminActor = $this->authorization->isEffectiveSuperAdmin($actor);

        if (! $isSuperAdminActor) {
            if (
                ! $branch->isActive()
                || (
                    ! $this->authorization->hasBranchPermission($actor, 'branches.assign_staff', $branch)
                    && ! $this->authorization->hasBranchPermission($actor, 'staff.suspend_branch', $branch)
                )
            ) {
                throw new AuthorizationException('Bạn không có quyền gỡ phân công nhân sự tại chi nhánh này.');
            }
        }

        $mutation = function () use ($actor, $staff, $branch, $reason, $ipAddress, $userAgent): User {
            /** @var User $lockedStaff */
            $lockedStaff = User::query()->findOrFail($staff->id);

            /** @var BranchAssignment|null $assignment */
            $assignment = BranchAssignment::query()
                ->where('user_id', $lockedStaff->id)
                ->where('branch_id', $branch->id)
                ->where('status', BranchAssignmentStatus::Active->value)
                ->first();

            if ($assignment === null) {
                throw new DomainInvariantException(
                    'Nhân sự hiện không có phân công đang hoạt động tại chi nhánh này.',
                    'branch_assignment_not_active',
                    422,
                );
            }

            $assignment->forceFill([
                'status' => BranchAssignmentStatus::Inactive,
                'is_primary' => false,
            ])->save();

            // Thu hồi vai trò gắn với cặp phân công vừa gỡ để dữ liệu phân quyền sạch sẽ, giữ nguyên lịch sử assignment inactive.
            BranchRoleAssignment::query()
                ->where('user_id', $lockedStaff->id)
                ->where('branch_id', $branch->id)
                ->delete();

            $cleanedReason = $this->normalizeNullableString($reason) ?? 'Vô hiệu hóa phân công nhân sự tại chi nhánh.';

            $this->writeAudit(
                actor: $actor,
                targetUser: $lockedStaff,
                branch: $branch,
                action: AuditAction::BranchRemoved,
                subjectType: BranchAssignment::class,
                subjectId: $assignment->id,
                oldValues: [
                    'branch_code' => $branch->code,
                    'status' => BranchAssignmentStatus::Active->value,
                ],
                newValues: [
                    'branch_code' => $branch->code,
                    'status' => BranchAssignmentStatus::Inactive->value,
                ],
                reason: $cleanedReason,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $stillHasEffectiveBranch = $this->hasAnyActiveEffectiveBranchAssignment($lockedStaff);
            $hasGlobalAuthority = $this->hasValidGlobalAuthority($lockedStaff);

            if (! $stillHasEffectiveBranch && ! $hasGlobalAuthority && $lockedStaff->staff_access_status !== StaffAccessStatus::Inactive) {
                $previousStatus = $lockedStaff->staff_access_status->value;

                // Decision 2.7: Gỡ phân công chi nhánh cuối cùng tự động chuyển staff_access_status -> inactive;
                // TUYỆT ĐỐI giữ nguyên account_status để không khóa tài khoản khách hàng.
                $lockedStaff->forceFill([
                    'staff_access_status' => StaffAccessStatus::Inactive,
                ])->save();

                if ($lockedStaff->staffProfile !== null) {
                    $lockedStaff->staffProfile->forceFill([
                        'suspended_at' => now(),
                    ])->save();
                }

                $this->cancelPendingProposalsFor($lockedStaff, $actor, 'Tự động hủy đề xuất do phân công chi nhánh hiệu lực cuối cùng đã bị gỡ.');

                $this->writeAudit(
                    actor: $actor,
                    targetUser: $lockedStaff,
                    branch: $branch,
                    action: AuditAction::StaffSuspended,
                    subjectType: User::class,
                    subjectId: $lockedStaff->id,
                    oldValues: [
                        'staff_access_status' => $previousStatus,
                    ],
                    newValues: [
                        'staff_access_status' => StaffAccessStatus::Inactive->value,
                        'account_status' => $lockedStaff->account_status->value,
                    ],
                    reason: 'Không còn phân công chi nhánh hoạt động hiệu lực nào sau khi gỡ phân công cuối cùng.',
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return $lockedStaff->fresh(['staffProfile', 'branchAssignments.branch']) ?? $lockedStaff;
        };

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        if ($this->hasSuperAdminGlobalRole($staff)) {
            return $this->domainGuards->ensureEffectiveSuperAdminRemainsAfterMutation($staff, $mutation);
        }

        return $connection->transaction($mutation, attempts: 5);
    }

    /**
     * Gửi đề xuất kích hoạt quyền truy cập nhân sự (chỉ dành cho người có quyền `staff.propose_activation` tại chi nhánh).
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function submitActivationProposal(
        User $actor,
        User $staff,
        Branch $branch,
        string $proposalReason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): StaffActivationProposal {
        if (! $actor->can('propose', [StaffActivationProposal::class, $staff, $branch])) {
            throw new AuthorizationException('Bạn không có quyền đề xuất kích hoạt cho nhân sự này tại chi nhánh đã chọn.');
        }

        $reason = trim($proposalReason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'proposal_reason' => 'Vui lòng nhập lý do đề xuất kích hoạt nhân sự.',
            ]);
        }

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $staff,
            $branch,
            $reason,
            $ipAddress,
            $userAgent,
        ): StaffActivationProposal {
            /** @var User $lockedStaff */
            $lockedStaff = User::query()->findOrFail($staff->id);

            $this->domainGuards->ensureStaffActivationProposalAllowed($actor, $lockedStaff, $branch);

            $proposal = StaffActivationProposal::query()->create([
                'user_id' => $lockedStaff->id,
                'branch_id' => $branch->id,
                'proposed_by' => $actor->id,
                'proposal_reason' => $reason,
                'proposed_at' => now(),
            ]);

            $this->writeAudit(
                actor: $actor,
                targetUser: $lockedStaff,
                branch: $branch,
                action: AuditAction::StaffActivationProposed,
                subjectType: StaffActivationProposal::class,
                subjectId: $proposal->id,
                oldValues: null,
                newValues: [
                    'proposal_id' => $proposal->id,
                    'branch_code' => $branch->code,
                    'status' => StaffApprovalStatus::Pending->value,
                ],
                reason: $reason,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $proposal->fresh(['user', 'branch', 'proposedBy']) ?? $proposal;
        }, attempts: 5);
    }

    /**
     * Phân trang hàng đợi đề xuất kích hoạt cho người có quyền phê duyệt toàn hệ thống.
     *
     * @throws AuthorizationException
     */
    public function paginateActivationProposals(
        User $actor,
        ?StaffApprovalStatus $statusFilter = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        if (! $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation')) {
            throw new AuthorizationException('Chỉ Quản trị viên được cấp quyền phê duyệt mới có thể truy cập hàng đợi kích hoạt nhân sự.');
        }

        $query = StaffActivationProposal::query()
            ->with(['user.staffProfile', 'branch', 'proposedBy', 'reviewedBy']);

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter->value);
        }

        return $query
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")
            ->orderByDesc('proposed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Thống kê tổng quan hai danh sách riêng biệt trên màn hình Phê duyệt & Kích hoạt nhân sự:
     * 1. "Đề xuất chờ phê duyệt" (`staff_activation_proposals`)
     * 2. "Nhân sự chờ kích hoạt" (`users.staff_access_status = pending`, bao gồm cả tài khoản chưa có đề xuất).
     *
     * @return array{
     *     pendingProposals: int,
     *     totalProposals: int,
     *     pendingStaffTotal: int,
     *     pendingStaffWithoutProposal: int
     * }
     *
     * @throws AuthorizationException
     */
    public function approvalQueueSummary(User $actor): array
    {
        if (! $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation')) {
            throw new AuthorizationException('Chỉ Quản trị viên được cấp quyền phê duyệt mới có thể truy cập hàng đợi kích hoạt nhân sự.');
        }

        $pendingProposals = StaffActivationProposal::query()
            ->where('status', StaffApprovalStatus::Pending->value)
            ->count();

        $totalProposals = StaffActivationProposal::query()->count();

        $pendingStaffQuery = User::query()
            ->where('staff_access_status', StaffAccessStatus::Pending->value);

        $pendingStaffTotal = (clone $pendingStaffQuery)->count();

        $pendingStaffWithoutProposal = (clone $pendingStaffQuery)
            ->whereDoesntHave('staffActivationProposals', function ($proposalQuery): void {
                $proposalQuery->where('status', StaffApprovalStatus::Pending->value);
            })
            ->count();

        return [
            'pendingProposals' => $pendingProposals,
            'totalProposals' => $totalProposals,
            'pendingStaffTotal' => $pendingStaffTotal,
            'pendingStaffWithoutProposal' => $pendingStaffWithoutProposal,
        ];
    }

    /**
     * Phân trang danh sách "Nhân sự chờ kích hoạt" (`users.staff_access_status = pending`),
     * bao gồm cả những tài khoản chưa có bản ghi đề xuất nào trong `staff_activation_proposals`.
     *
     * @throws AuthorizationException
     */
    public function paginatePendingStaffForApprovalQueue(
        User $actor,
        int $perPage = 15,
    ): LengthAwarePaginator {
        if (! $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation')) {
            throw new AuthorizationException('Chỉ Quản trị viên được cấp quyền phê duyệt mới có thể truy cập hàng đợi kích hoạt nhân sự.');
        }

        return User::query()
            ->where('staff_access_status', StaffAccessStatus::Pending->value)
            ->with([
                'staffProfile',
                'branchAssignments.branch',
                'branchRoleAssignments.role',
                'userRoles.role',
                'staffActivationProposals' => fn ($q) => $q->with(['branch', 'proposedBy'])->latest('id'),
            ])
            ->orderBy('must_change_password')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'pending_staff_page')
            ->withQueryString();
    }

    /**
     * Kiểm tra một nhân sự đang ở trạng thái `pending` đã hội tụ đủ điều kiện nghiệp vụ để
     * kích hoạt trực tiếp hay chưa (và trả về thông điệp chẩn đoán nếu chưa đủ điều kiện).
     *
     * @return array{ready: bool, reason: string}
     */
    public function evaluateDirectActivationReadiness(User $actor, User $staff): array
    {
        if ($this->domainGuards->isSelfAction($actor, $staff)) {
            return [
                'ready' => false,
                'reason' => 'Không được phép tự kích hoạt quyền nhân sự của chính mình.',
            ];
        }

        if ($staff->account_status !== AccountStatus::Active) {
            return [
                'ready' => false,
                'reason' => 'Tài khoản người dùng đang bị khóa (suspended), không thể kích hoạt quyền nhân sự.',
            ];
        }

        if ($staff->staff_access_status !== StaffAccessStatus::Pending) {
            return [
                'ready' => false,
                'reason' => 'Nhân sự không ở trạng thái Chờ kích hoạt.',
            ];
        }

        if ($staff->staffProfile === null && ! $staff->staffProfile()->exists()) {
            return [
                'ready' => false,
                'reason' => 'Chưa có hồ sơ nhân sự (staff_profiles) hợp lệ.',
            ];
        }

        if ($staff->must_change_password) {
            return [
                'ready' => false,
                'reason' => 'Nhân sự chưa hoàn tất bước đổi mật khẩu tạm thời (onboarding) bắt buộc.',
            ];
        }

        if (! $this->hasAnyActiveEffectiveBranchAssignment($staff) && ! $this->hasValidGlobalAuthority($staff)) {
            return [
                'ready' => false,
                'reason' => 'Chưa có phân công tại chi nhánh đang hoạt động kèm ít nhất một vai trò chi nhánh hợp lệ.',
            ];
        }

        return [
            'ready' => true,
            'reason' => 'Đã hoàn tất đổi mật khẩu và có phân công chi nhánh kèm vai trò hợp lệ — sẵn sàng kích hoạt.',
        ];
    }

    /**
     * Luồng kích hoạt trực tiếp dành riêng cho Super Administrator hiệu lực đối với nhân sự
     * đang ở trạng thái `pending` (không bắt buộc phải qua bước tạo đề xuất trước đó).
     *
     * Bất biến bắt buộc:
     * - Chỉ Super Administrator hiệu lực có quyền `staff.approve_activation` mới được thực thi.
     * - Cấm tuyệt đối tự thao tác (`$actor->id !== $staff->id`).
     * - Không bao giờ kích hoạt tài khoản khách hàng đang bị khóa (`account_status !== active`).
     * - Bắt buộc nhân sự đã hoàn tất đổi mật khẩu tạm thời (`must_change_password === false`).
     * - Bắt buộc nhân sự đã có sẵn phân công chi nhánh hoạt động kèm vai trò hợp lệ (tuyệt đối không tự động cấp vai trò).
     * - Thực thi nguyên tử trong giao dịch SQLite và ghi nhật ký kiểm toán không chứa bí mật.
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function activateStaffDirectly(
        User $actor,
        User $staff,
        ?string $reviewNote = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        $this->domainGuards->ensureNotSelfAction(
            $actor,
            $staff,
            'Bạn không được phép tự kích hoạt quyền nhân sự của chính mình.',
        );

        if (
            ! $this->authorization->isEffectiveSuperAdmin($actor)
            || ! $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation')
            || ! $actor->can('directActivateStaff', $staff)
        ) {
            throw new AuthorizationException('Chỉ Quản trị viên cấp cao hiệu lực khác với nhân sự mục tiêu mới được phép kích hoạt trực tiếp.');
        }

        $cleanedNote = $this->normalizeNullableString($reviewNote)
            ?? 'Kích hoạt trực tiếp quyền truy cập nhân sự bởi Quản trị viên cấp cao sau khi kiểm tra phân công và trạng thái onboarding.';

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $staff,
            $cleanedNote,
            $ipAddress,
            $userAgent,
        ): User {
            if (! $this->authorization->isEffectiveSuperAdmin($actor)) {
                throw new AuthorizationException('Tài khoản thực thi không còn giữ quyền Quản trị viên cấp cao hiệu lực.');
            }

            /** @var User $lockedStaff */
            $lockedStaff = User::query()
                ->with(['staffProfile', 'branchAssignments.branch'])
                ->findOrFail($staff->id);

            $this->domainGuards->ensureNotSelfAction(
                $actor,
                $lockedStaff,
                'Bạn không được phép tự kích hoạt quyền nhân sự của chính mình.',
            );

            if ($lockedStaff->account_status !== AccountStatus::Active) {
                throw new DomainInvariantException(
                    'Không thể kích hoạt quyền nhân sự khi tài khoản người dùng đang bị khóa.',
                    'target_account_suspended',
                    422,
                );
            }

            if ($lockedStaff->staff_access_status !== StaffAccessStatus::Pending) {
                throw new DomainInvariantException(
                    'Chỉ có thể kích hoạt trực tiếp đối với nhân sự đang ở trạng thái Chờ kích hoạt.',
                    'target_staff_not_pending',
                    422,
                );
            }

            if ($lockedStaff->staffProfile === null) {
                throw new DomainInvariantException(
                    'Tài khoản mục tiêu chưa có hồ sơ nhân sự hợp lệ để kích hoạt.',
                    'missing_staff_profile',
                    422,
                );
            }

            if ($lockedStaff->must_change_password) {
                throw new DomainInvariantException(
                    'Nhân sự phải hoàn tất bước đổi mật khẩu tạm thời (onboarding) trước khi được kích hoạt trực tiếp.',
                    'onboarding_password_change_required',
                    422,
                );
            }

            if (! $this->hasAnyActiveEffectiveBranchAssignment($lockedStaff) && ! $this->hasValidGlobalAuthority($lockedStaff)) {
                throw new DomainInvariantException(
                    'Nhân sự chưa có phân công tại chi nhánh đang hoạt động kèm vai trò chi nhánh hợp lệ. Hệ thống không tự động cấp vai trò khi kích hoạt.',
                    'missing_active_assignment_or_role',
                    422,
                );
            }

            $now = now();
            $previousStaffStatus = $lockedStaff->staff_access_status->value;

            $primaryActiveAssignment = $lockedStaff->branchAssignments
                ->filter(fn (BranchAssignment $a): bool => $a->status === BranchAssignmentStatus::Active && $a->branch !== null && $a->branch->isActive())
                ->sortByDesc(fn (BranchAssignment $a): int => $a->is_primary ? 1 : 0)
                ->first();

            $auditBranch = $primaryActiveAssignment?->branch;

            /** @var StaffActivationProposal|null $existingPendingProposal */
            $existingPendingProposal = StaffActivationProposal::query()
                ->where('user_id', $lockedStaff->id)
                ->where('status', StaffApprovalStatus::Pending->value)
                ->latest('id')
                ->first();

            if ($existingPendingProposal !== null) {
                StaffActivationProposal::query()
                    ->where('id', $existingPendingProposal->id)
                    ->where('status', StaffApprovalStatus::Pending->value)
                    ->update([
                        'status' => StaffApprovalStatus::Approved->value,
                        'reviewed_by' => $actor->id,
                        'review_note' => $cleanedNote,
                        'reviewed_at' => $now,
                        'updated_at' => $now,
                    ]);

                $auditBranch = $existingPendingProposal->branch ?? $auditBranch;
            }

            $lockedStaff->forceFill([
                'staff_access_status' => StaffAccessStatus::Active,
            ])->save();

            $lockedStaff->staffProfile->forceFill([
                'activated_at' => $lockedStaff->staffProfile->activated_at ?? $now,
                'suspended_at' => null,
            ])->save();

            $this->writeAudit(
                actor: $actor,
                targetUser: $lockedStaff,
                branch: $auditBranch,
                action: AuditAction::StaffActivationApproved,
                subjectType: $existingPendingProposal !== null ? StaffActivationProposal::class : User::class,
                subjectId: $existingPendingProposal?->id ?? $lockedStaff->id,
                oldValues: [
                    'staff_access_status' => $previousStaffStatus,
                    'proposal_status' => $existingPendingProposal !== null ? StaffApprovalStatus::Pending->value : null,
                ],
                newValues: [
                    'staff_access_status' => StaffAccessStatus::Active->value,
                    'activation_mode' => 'super_admin_direct',
                    'proposal_status' => $existingPendingProposal !== null ? StaffApprovalStatus::Approved->value : null,
                ],
                reason: $cleanedNote,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $lockedStaff->fresh(['staffProfile', 'branchAssignments.branch']) ?? $lockedStaff;
        }, attempts: 5);
    }

    /**
     * Super Administrator phê duyệt đề xuất kích hoạt quyền truy cập nhân sự.
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function approveActivationProposal(
        User $actor,
        StaffActivationProposal $proposal,
        ?string $reviewNote = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): StaffActivationProposal {
        if (! $actor->can('review', $proposal)) {
            throw new AuthorizationException('Chỉ Quản trị viên cấp cao khác với nhân sự mục tiêu mới được phép phê duyệt kích hoạt.');
        }

        $cleanedNote = $this->normalizeNullableString($reviewNote) ?? 'Đã thẩm định đầy đủ hồ sơ và đồng ý kích hoạt quyền nhân sự.';

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $proposal,
            $cleanedNote,
            $ipAddress,
            $userAgent,
        ): StaffActivationProposal {
            /** @var StaffActivationProposal $lockedProposal */
            $lockedProposal = StaffActivationProposal::query()
                ->with(['user.staffProfile', 'branch'])
                ->findOrFail($proposal->id);

            $this->domainGuards->ensureActivationApprovalAllowed($actor, $lockedProposal, true);

            /** @var User $targetUser */
            $targetUser = $lockedProposal->user;

            $now = now();
            $affected = StaffActivationProposal::query()
                ->where('id', $lockedProposal->id)
                ->where('status', StaffApprovalStatus::Pending->value)
                ->update([
                    'status' => StaffApprovalStatus::Approved->value,
                    'reviewed_by' => $actor->id,
                    'review_note' => $cleanedNote,
                    'reviewed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                throw new DomainInvariantException(
                    'Đề xuất kích hoạt này vừa được xử lý ở một phiên làm việc khác.',
                    'proposal_not_pending',
                    422,
                );
            }

            $previousStaffStatus = $targetUser->staff_access_status->value;

            $targetUser->forceFill([
                'staff_access_status' => StaffAccessStatus::Active,
            ])->save();

            if ($targetUser->staffProfile !== null) {
                $targetUser->staffProfile->forceFill([
                    'activated_at' => $targetUser->staffProfile->activated_at ?? $now,
                    'suspended_at' => null,
                ])->save();
            }

            $this->writeAudit(
                actor: $actor,
                targetUser: $targetUser,
                branch: $lockedProposal->branch,
                action: AuditAction::StaffActivationApproved,
                subjectType: StaffActivationProposal::class,
                subjectId: $lockedProposal->id,
                oldValues: [
                    'proposal_status' => StaffApprovalStatus::Pending->value,
                    'staff_access_status' => $previousStaffStatus,
                ],
                newValues: [
                    'proposal_status' => StaffApprovalStatus::Approved->value,
                    'staff_access_status' => StaffAccessStatus::Active->value,
                ],
                reason: $cleanedNote,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $lockedProposal->fresh(['user.staffProfile', 'branch', 'proposedBy', 'reviewedBy']) ?? $lockedProposal;
        }, attempts: 5);
    }

    /**
     * Super Administrator từ chối đề xuất kích hoạt quyền truy cập nhân sự.
     *
     * @throws AuthorizationException|DomainInvariantException|ValidationException
     */
    public function rejectActivationProposal(
        User $actor,
        StaffActivationProposal $proposal,
        string $reviewNote,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): StaffActivationProposal {
        if (! $actor->can('review', $proposal)) {
            throw new AuthorizationException('Chỉ Quản trị viên cấp cao khác với nhân sự mục tiêu mới được phép từ chối đề xuất kích hoạt.');
        }

        $cleanedNote = trim($reviewNote);
        if ($cleanedNote === '') {
            throw ValidationException::withMessages([
                'review_note' => 'Vui lòng nhập lý do từ chối đề xuất kích hoạt nhân sự.',
            ]);
        }

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $proposal,
            $cleanedNote,
            $ipAddress,
            $userAgent,
        ): StaffActivationProposal {
            /** @var StaffActivationProposal $lockedProposal */
            $lockedProposal = StaffActivationProposal::query()
                ->with(['user', 'branch'])
                ->findOrFail($proposal->id);

            $this->domainGuards->ensureActivationApprovalAllowed($actor, $lockedProposal, false);

            /** @var User $targetUser */
            $targetUser = $lockedProposal->user;

            $now = now();
            $affected = StaffActivationProposal::query()
                ->where('id', $lockedProposal->id)
                ->where('status', StaffApprovalStatus::Pending->value)
                ->update([
                    'status' => StaffApprovalStatus::Rejected->value,
                    'reviewed_by' => $actor->id,
                    'review_note' => $cleanedNote,
                    'reviewed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                throw new DomainInvariantException(
                    'Đề xuất kích hoạt này vừa được xử lý ở một phiên làm việc khác.',
                    'proposal_not_pending',
                    422,
                );
            }

            $this->writeAudit(
                actor: $actor,
                targetUser: $targetUser,
                branch: $lockedProposal->branch,
                action: AuditAction::StaffActivationRejected,
                subjectType: StaffActivationProposal::class,
                subjectId: $lockedProposal->id,
                oldValues: [
                    'proposal_status' => StaffApprovalStatus::Pending->value,
                ],
                newValues: [
                    'proposal_status' => StaffApprovalStatus::Rejected->value,
                    'staff_access_status' => $targetUser->staff_access_status->value,
                ],
                reason: $cleanedNote,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $lockedProposal->fresh(['user.staffProfile', 'branch', 'proposedBy', 'reviewedBy']) ?? $lockedProposal;
        }, attempts: 5);
    }

    /**
     * Đình chỉ / vô hiệu hóa quyền nhân sự toàn cục (KHÔNG vô hiệu hóa `account_status` khách hàng)
     * kèm bảo vệ tự thao tác và bảo vệ Super Administrator hiệu lực cuối cùng.
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function suspendStaffAccess(
        User $actor,
        User $staff,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        $this->domainGuards->ensureNotSelfAction(
            $actor,
            $staff,
            'Bạn không được phép tự đình chỉ quyền nhân sự của chính mình.',
        );

        if (! $actor->can('suspendStaff', [$staff, null])) {
            throw new AuthorizationException('Bạn không có quyền đình chỉ quyền truy cập nhân sự toàn hệ thống hoặc thao tác sẽ làm mất Quản trị viên cấp cao cuối cùng.');
        }

        $cleanedReason = $this->normalizeNullableString($reason) ?? 'Đình chỉ quyền truy cập nhân sự theo quyết định quản trị.';

        $mutation = function () use ($actor, $staff, $cleanedReason, $ipAddress, $userAgent): User {
            /** @var User $lockedStaff */
            $lockedStaff = User::query()->findOrFail($staff->id);

            if ($lockedStaff->staff_access_status === StaffAccessStatus::NotApplicable) {
                throw new DomainInvariantException(
                    'Tài khoản khách hàng chưa có quan hệ nhân sự nên không thể đình chỉ quyền nhân sự.',
                    'not_a_staff_account',
                    422,
                );
            }

            if ($lockedStaff->staff_access_status === StaffAccessStatus::Inactive) {
                return $lockedStaff;
            }

            $previousStatus = $lockedStaff->staff_access_status->value;
            $now = now();

            // Bảo toàn account_status của tài khoản khách hàng; chỉ vô hiệu hóa staff_access_status.
            $lockedStaff->forceFill([
                'staff_access_status' => StaffAccessStatus::Inactive,
            ])->save();

            if ($lockedStaff->staffProfile !== null) {
                $lockedStaff->staffProfile->forceFill([
                    'suspended_at' => $now,
                ])->save();
            }

            $this->cancelPendingProposalsFor($lockedStaff, $actor, 'Tự động hủy đề xuất do quyền nhân sự bị đình chỉ.');

            $this->writeAudit(
                actor: $actor,
                targetUser: $lockedStaff,
                branch: null,
                action: AuditAction::StaffSuspended,
                subjectType: User::class,
                subjectId: $lockedStaff->id,
                oldValues: [
                    'staff_access_status' => $previousStatus,
                ],
                newValues: [
                    'staff_access_status' => StaffAccessStatus::Inactive->value,
                    'account_status' => $lockedStaff->account_status->value,
                ],
                reason: $cleanedReason,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $lockedStaff->fresh(['staffProfile', 'branchAssignments.branch']) ?? $lockedStaff;
        };

        return $this->domainGuards->ensureEffectiveSuperAdminRemainsAfterMutation($staff, $mutation);
    }

    /**
     * Khôi phục quyền truy cập nhân sự từ trạng thái `inactive` -> `active` bởi Super Administrator.
     *
     * @throws AuthorizationException|DomainInvariantException
     */
    public function reactivateStaffAccess(
        User $actor,
        User $staff,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        $this->domainGuards->ensureNotSelfAction(
            $actor,
            $staff,
            'Bạn không được phép tự khôi phục quyền nhân sự của chính mình.',
        );

        if (! $actor->can('reactivateStaff', $staff)) {
            throw new AuthorizationException('Bạn không có quyền khôi phục quyền truy cập nhân sự.');
        }

        $cleanedReason = $this->normalizeNullableString($reason) ?? 'Khôi phục quyền truy cập nhân sự.';

        $connection = DB::connection();
        $this->configureSqliteBusyTimeout($connection);

        return $connection->transaction(function () use (
            $actor,
            $staff,
            $cleanedReason,
            $ipAddress,
            $userAgent,
        ): User {
            /** @var User $lockedStaff */
            $lockedStaff = User::query()->findOrFail($staff->id);

            if ($lockedStaff->account_status !== AccountStatus::Active) {
                throw new DomainInvariantException(
                    'Không thể khôi phục quyền nhân sự khi tài khoản tổng thể đang bị khóa.',
                    'target_account_suspended',
                    422,
                );
            }

            if ($lockedStaff->staff_access_status !== StaffAccessStatus::Inactive) {
                throw new DomainInvariantException(
                    'Chỉ nhân sự đang ở trạng thái Ngừng hoạt động mới có thể khôi phục quyền truy cập.',
                    'staff_not_inactive',
                    422,
                );
            }

            if (! $this->hasAnyActiveEffectiveBranchAssignment($lockedStaff) && ! $this->hasValidGlobalAuthority($lockedStaff)) {
                throw new DomainInvariantException(
                    'Nhân sự phải có ít nhất một phân công chi nhánh đang hoạt động kèm vai trò chi nhánh hợp lệ trước khi khôi phục.',
                    'staff_branch_role_missing',
                    422,
                );
            }

            $now = now();

            $lockedStaff->forceFill([
                'staff_access_status' => StaffAccessStatus::Active,
            ])->save();

            if ($lockedStaff->staffProfile !== null) {
                $lockedStaff->staffProfile->forceFill([
                    'activated_at' => $lockedStaff->staffProfile->activated_at ?? $now,
                    'suspended_at' => null,
                ])->save();
            }

            $this->writeAudit(
                actor: $actor,
                targetUser: $lockedStaff,
                branch: null,
                action: AuditAction::StaffReactivated,
                subjectType: User::class,
                subjectId: $lockedStaff->id,
                oldValues: [
                    'staff_access_status' => StaffAccessStatus::Inactive->value,
                ],
                newValues: [
                    'staff_access_status' => StaffAccessStatus::Active->value,
                ],
                reason: $cleanedReason,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $lockedStaff->fresh(['staffProfile', 'branchAssignments.branch']) ?? $lockedStaff;
        }, attempts: 5);
    }

    /**
     * Xây dựng truy vấn nền danh sách nhân sự theo phạm vi quyền hạn.
     *
     * @param  list<int>  $scopedBranchIds
     * @return Builder<User>
     */
    private function scopedStaffBaseQuery(bool $isSuperAdmin, array $scopedBranchIds)
    {
        $query = User::query()
            ->whereIn('staff_access_status', [
                StaffAccessStatus::Pending->value,
                StaffAccessStatus::Active->value,
                StaffAccessStatus::Inactive->value,
            ]);

        if ($isSuperAdmin) {
            return $query;
        }

        return $query->where(function ($subQuery) use ($scopedBranchIds): void {
            $subQuery
                ->whereHas('branchAssignments', function ($assignmentQuery) use ($scopedBranchIds): void {
                    $assignmentQuery->whereIn('branch_id', $scopedBranchIds);
                })
                ->orWhereHas('staffActivationProposals', function ($proposalQuery) use ($scopedBranchIds): void {
                    $proposalQuery->whereIn('branch_id', $scopedBranchIds);
                });
        });
    }

    /**
     * @param  list<int|string>  $roleIds
     * @return Collection<int, Role>
     */
    private function resolveBranchRolesOrDefault(array $roleIds): Collection
    {
        $filteredIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), fn (int $id): bool => $id > 0)));

        if ($filteredIds === []) {
            $defaultRole = Role::query()
                ->where('code', 'staff')
                ->where('scope', RoleScope::Branch->value)
                ->first();

            if ($defaultRole === null) {
                throw new DomainInvariantException(
                    'Không tìm thấy vai trò nhân viên chi nhánh chuẩn trong danh mục RBAC.',
                    'default_staff_role_missing',
                    422,
                );
            }

            return new Collection([$defaultRole]);
        }

        return $this->resolveRequiredBranchRoles($filteredIds);
    }

    /**
     * @param  list<int|string>  $roleIds
     * @return Collection<int, Role>
     */
    private function resolveRequiredBranchRoles(array $roleIds): Collection
    {
        $filteredIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), fn (int $id): bool => $id > 0)));

        if ($filteredIds === []) {
            throw ValidationException::withMessages([
                'role_ids' => 'Vui lòng chọn ít nhất một vai trò phạm vi chi nhánh cho nhân sự.',
            ]);
        }

        $roles = Role::query()->whereIn('id', $filteredIds)->orderBy('id')->get();

        if ($roles->count() !== count($filteredIds)) {
            throw ValidationException::withMessages([
                'role_ids' => 'Có vai trò được chọn không tồn tại trong hệ thống.',
            ]);
        }

        foreach ($roles as $role) {
            $this->domainGuards->ensureRoleScopeMatchesTarget($role, RoleScope::Branch);
        }

        return $roles;
    }

    /**
     * Bảo đảm vai trò chuyên biệt Kho trung tâm (`warehouse_manager`) chỉ được gán cho `CENTRAL_WH`.
     *
     * @param  Collection<int, Role>  $roles
     */
    private function assertBranchRolesAllowedForBranch(Branch $branch, Collection $roles): void
    {
        if ($branch->type === BranchType::CentralWarehouse) {
            return;
        }

        if ($roles->contains(fn (Role $role): bool => $role->code === 'warehouse_manager')) {
            throw ValidationException::withMessages([
                'role_ids' => 'Vai trò Quản lý Kho trung tâm (warehouse_manager) chỉ được phép gán cho Kho trung tâm (CENTRAL_WH), không áp dụng cho chi nhánh bán lẻ.',
            ]);
        }
    }

    private function validatePasswordPolicy(string $plainPassword, string $fieldName): void
    {
        $validator = validator(
            [$fieldName => $plainPassword],
            [
                $fieldName => [
                    'required',
                    'string',
                    Password::min(8)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ],
            [],
            [$fieldName => 'mật khẩu tạm thời'],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function resolveOrGenerateStaffCode(?string $inputCode): string
    {
        $trimmed = trim((string) $inputCode);
        if ($trimmed !== '') {
            $normalized = Str::upper($trimmed);
            if (preg_match('/^[A-Z0-9_-]{3,32}$/', $normalized) !== 1) {
                throw ValidationException::withMessages([
                    'staff_code' => 'Mã nhân viên chỉ được gồm 3–32 ký tự chữ in hoa, số, gạch ngang (-) hoặc gạch dưới (_).',
                ]);
            }

            if (StaffProfile::query()->whereRaw('UPPER(staff_code) = ?', [$normalized])->exists()) {
                throw ValidationException::withMessages([
                    'staff_code' => 'Mã nhân viên này đã tồn tại trong hệ thống.',
                ]);
            }

            return $normalized;
        }

        do {
            $candidate = 'NV-'.Str::upper(Str::random(6));
        } while (StaffProfile::query()->where('staff_code', $candidate)->exists());

        return $candidate;
    }

    private function hasAnyActiveEffectiveBranchAssignment(User $user): bool
    {
        return DB::table('branch_assignments')
            ->join('branches', 'branches.id', '=', 'branch_assignments.branch_id')
            ->join('branch_role_assignments', function ($join): void {
                $join->on('branch_role_assignments.user_id', '=', 'branch_assignments.user_id')
                    ->on('branch_role_assignments.branch_id', '=', 'branch_assignments.branch_id');
            })
            ->join('roles', 'roles.id', '=', 'branch_role_assignments.role_id')
            ->where('branch_assignments.user_id', $user->id)
            ->where('branch_assignments.status', BranchAssignmentStatus::Active->value)
            ->where('branches.status', BranchStatus::Active->value)
            ->where('branch_role_assignments.role_scope', RoleScope::Branch->value)
            ->where('roles.scope', RoleScope::Branch->value)
            ->exists();
    }

    private function hasValidGlobalAuthority(User $user): bool
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.scope', RoleScope::Global->value)
            ->where('permissions.scope', RoleScope::Global->value)
            ->exists();
    }

    private function hasSuperAdminGlobalRole(User $user): bool
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.code', 'super_admin')
            ->where('roles.scope', RoleScope::Global->value)
            ->exists();
    }

    private function cancelPendingProposalsFor(User $staff, User $actor, string $reason): void
    {
        StaffActivationProposal::query()
            ->where('user_id', $staff->id)
            ->where('status', StaffApprovalStatus::Pending->value)
            ->update([
                'status' => StaffApprovalStatus::Cancelled->value,
                'reviewed_by' => $actor->id,
                'review_note' => $reason,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function writeAudit(
        User $actor,
        User $targetUser,
        ?Branch $branch,
        AuditAction $action,
        string $subjectType,
        ?int $subjectId,
        ?array $oldValues,
        ?array $newValues,
        string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'target_user_id' => $targetUser->id,
            'branch_id' => $branch?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId ?? $targetUser->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'ip_address' => $ipAddress ? mb_substr($ipAddress, 0, 45) : null,
            'user_agent' => $userAgent ? Str::limit(trim($userAgent), 500, '') : null,
            'occurred_at' => now(),
        ]);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function configureSqliteBusyTimeout(mixed $connection): void
    {
        if ($connection->transactionLevel() === 0 && $connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA busy_timeout = 5000');
        }
    }
}
