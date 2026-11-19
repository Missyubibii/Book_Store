<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\StaffAccessStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use App\Services\Staff\StaffAdministrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Controller HTTP quản lý danh sách và chi tiết tài khoản hệ thống Book_Store.
 *
 * Flow:
 * - GET /quan-tri/tai-khoan (`admin.accounts.index`) -> index() -> View `admin.accounts.index`
 * - GET /quan-tri/tai-khoan/khach-hang/{user} (`admin.accounts.customer.show`) -> showCustomer() -> View `admin.accounts.customer-show`
 * - PATCH /quan-tri/tai-khoan/khach-hang/{user}/trang-thai (`admin.accounts.customer.status`) -> updateCustomerStatus() -> Redirect
 *
 * Quy tắc nghiệp vụ & phân quyền:
 * - Bảo vệ bởi middleware `auth`, `account.active`, `staff.active`, `super_admin.effective`.
 * - Tách bạch 2 nhóm tài khoản trên cùng bảng `users`: Nhân sự (`staff_access_status != not_applicable`)
 *   và Khách hàng (`staff_access_status == not_applicable`), không nhân bản tài khoản.
 */
class AccountManagementController extends Controller
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly StaffAdministrationService $staffAdmin,
    ) {}

    /**
     * Hiển thị màn hình Quản lý tài khoản với hai tab: Tài khoản nhân sự và Tài khoản khách hàng.
     *
     * Entry point: GET /quan-tri/tai-khoan (`admin.accounts.index`).
     * Output: View `admin.accounts.index` kèm bộ lọc, phân trang và các hành động nhân sự theo quyền.
     */
    public function index(Request $request): View
    {
        $actor = $request->user();
        $tab = $request->query('tab', 'staff') === 'customer' ? 'customer' : 'staff';
        $search = trim((string) $request->query('q', ''));
        $statusFilter = $request->query('status');
        $providerFilter = $request->query('provider');

        $branches = Branch::query()->orderBy('name')->get();
        $staffCount = User::query()->where('staff_access_status', '!=', StaffAccessStatus::NotApplicable)->count();
        $customerCount = User::query()->where('staff_access_status', StaffAccessStatus::NotApplicable)->count();
        $staffSummary = $actor !== null ? $this->staffAdmin->staffSummary($actor) : ['pendingProposals' => 0];
        $canCreateStaff = $actor !== null && $this->staffAdmin->manageableBranchesFor($actor, 'staff.create')->isNotEmpty();
        $canLinkStaff = $actor !== null && $this->staffAdmin->manageableBranchesFor($actor, 'staff.link')->isNotEmpty();
        $canApproveProposals = $actor !== null && $this->authorization->hasGlobalPermission($actor, 'staff.approve_activation');

        if ($tab === 'customer') {
            $query = User::query()->where('staff_access_status', StaffAccessStatus::NotApplicable);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            }

            if ($statusFilter && in_array($statusFilter, [AccountStatus::Active->value, AccountStatus::Suspended->value], true)) {
                $query->where('account_status', $statusFilter);
            }

            if ($providerFilter === 'google') {
                if (Schema::hasTable('user_external_identities')) {
                    $query->whereHas('externalIdentities', fn ($q) => $q->where('provider', 'google'));
                }
            } elseif ($providerFilter === 'password') {
                $query->whereNotNull('password')->where('password', '!=', '');
            } elseif ($providerFilter === 'both') {
                $query->whereNotNull('password')->where('password', '!=', '');
                if (Schema::hasTable('user_external_identities')) {
                    $query->whereHas('externalIdentities', fn ($q) => $q->where('provider', 'google'));
                }
            }

            $withRelations = ['externalIdentities'];
            if (Schema::hasTable('login_events')) {
                $withRelations[] = 'loginEvents';
            }

            $users = $query->with($withRelations)
                ->latest('id')
                ->paginate(15)
                ->withQueryString();

            return view('admin.accounts.index', [
                'tab' => 'customer',
                'users' => $users,
                'search' => $search,
                'statusFilter' => $statusFilter,
                'providerFilter' => $providerFilter,
                'branches' => $branches,
                'staffCount' => $staffCount,
                'customerCount' => $customerCount,
                'canCreateStaff' => $canCreateStaff,
                'canLinkStaff' => $canLinkStaff,
                'canApproveProposals' => $canApproveProposals,
                'pendingProposalsCount' => (int) ($staffSummary['pendingProposals'] ?? 0),
            ]);
        }

        // Tab: Staff
        $query = User::query()->where('staff_access_status', '!=', StaffAccessStatus::NotApplicable);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('staffProfile', fn ($sq) => $sq->where('staff_code', 'like', "%{$search}%"));
            });
        }

        if ($statusFilter && in_array($statusFilter, [AccountStatus::Active->value, AccountStatus::Suspended->value], true)) {
            $query->where('account_status', $statusFilter);
        }

        $staffStatusFilter = $request->query('staff_status');
        if ($staffStatusFilter && in_array($staffStatusFilter, [
            StaffAccessStatus::Pending->value,
            StaffAccessStatus::Active->value,
            StaffAccessStatus::Inactive->value,
        ], true)) {
            $query->where('staff_access_status', $staffStatusFilter);
        }

        $branchFilter = $request->query('branch_id');
        if ($branchFilter && is_numeric($branchFilter)) {
            $query->whereHas('branchAssignments', fn ($bq) => $bq->where('branch_id', (int) $branchFilter)->where('status', 'active'));
        }

        if ($providerFilter === 'google') {
            if (Schema::hasTable('user_external_identities')) {
                $query->whereHas('externalIdentities', fn ($q) => $q->where('provider', 'google'));
            }
        } elseif ($providerFilter === 'password') {
            $query->whereNotNull('password')->where('password', '!=', '');
        } elseif ($providerFilter === 'both') {
            $query->whereNotNull('password')->where('password', '!=', '');
            if (Schema::hasTable('user_external_identities')) {
                $query->whereHas('externalIdentities', fn ($q) => $q->where('provider', 'google'));
            }
        }

        $withRelations = [
            'staffProfile',
            'branchAssignments.branch',
            'userRoles.role',
            'externalIdentities',
        ];
        if (Schema::hasTable('login_events')) {
            $withRelations[] = 'loginEvents';
        }

        $users = $query->with($withRelations)
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.accounts.index', [
            'tab' => 'staff',
            'users' => $users,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'staffStatusFilter' => $staffStatusFilter,
            'branchFilter' => $branchFilter,
            'providerFilter' => $providerFilter,
            'branches' => $branches,
            'staffCount' => $staffCount,
            'customerCount' => $customerCount,
            'canCreateStaff' => $canCreateStaff,
            'canLinkStaff' => $canLinkStaff,
            'canApproveProposals' => $canApproveProposals,
            'pendingProposalsCount' => (int) ($staffSummary['pendingProposals'] ?? 0),
        ]);
    }

    /**
     * Xem thông tin chi tiết tài khoản độc giả (Customer Account Detail).
     */
    public function showCustomer(User $user): View|RedirectResponse
    {
        // Nếu là tài khoản nhân sự, điều hướng sang không gian nhân sự
        if ($user->staff_access_status !== StaffAccessStatus::NotApplicable) {
            return redirect()->route('admin.staff.show', $user);
        }

        $googleIdentity = null;
        if (Schema::hasTable('user_external_identities')) {
            $googleIdentity = $user->externalIdentities()
                ->where('provider', 'google')
                ->first();
        }

        $recentLogins = Schema::hasTable('login_events')
            ? $user->loginEvents()->latest('occurred_at')->limit(10)->get()
            : collect();

        return view('admin.accounts.customer-show', [
            'customer' => $user,
            'googleIdentity' => $googleIdentity,
            'hasPassword' => $user->hasPassword(),
            'recentLogins' => $recentLogins,
        ]);
    }

    /**
     * Cập nhật trạng thái khóa / khôi phục tài khoản độc giả.
     */
    public function updateCustomerStatus(Request $request, User $user): RedirectResponse
    {
        // Nếu tài khoản mục tiêu là nhân sự, bắt buộc xử lý qua quy trình nhân sự riêng
        if ($user->staff_access_status !== StaffAccessStatus::NotApplicable) {
            return redirect()
                ->route('admin.staff.show', $user)
                ->with('warning', 'Tài khoản này là nhân sự, vui lòng điều chỉnh trạng thái trong phân hệ nhân sự.');
        }

        $actor = Auth::user();

        // Chốt bảo vệ bất biến miền: Không tự khóa tài khoản của chính mình
        if ($user->id === $actor->id) {
            return redirect()
                ->back()
                ->with('error', 'Bạn không thể tự khóa hoặc thay đổi trạng thái tài khoản của chính mình.');
        }

        $request->validate([
            'action' => ['required', 'in:suspend,reactivate'],
            'confirmed' => ['required', 'accepted'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'confirmed.accepted' => 'Vui lòng xác nhận trước khi thực hiện thay đổi trạng thái tài khoản.',
            'confirmed.required' => 'Vui lòng xác nhận trước khi thực hiện thay đổi trạng thái tài khoản.',
        ]);

        $targetAction = (string) $request->input('action');
        $reason = trim((string) $request->input('reason', ''));
        $oldStatus = $user->account_status;

        if ($targetAction === 'suspend') {
            $newStatus = AccountStatus::Suspended;
            $auditAction = AuditAction::AccountSuspended;
            $successMsg = 'Đã khóa tài khoản khách hàng thành công.';
        } else {
            $newStatus = AccountStatus::Active;
            $auditAction = AuditAction::AccountReactivated;
            $successMsg = 'Đã khôi phục hoạt động tài khoản khách hàng thành công.';
        }

        DB::transaction(function () use ($user, $actor, $newStatus, $oldStatus, $auditAction, $reason, $request) {
            $user->account_status = $newStatus;
            $user->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'target_user_id' => $user->id,
                'branch_id' => null,
                'action' => $auditAction,
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'old_values' => ['account_status' => $oldStatus->value],
                'new_values' => ['account_status' => $newStatus->value],
                'reason' => $reason !== '' ? $reason : null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('admin.accounts.customer.show', $user)
            ->with('success', $successMsg);
    }
}
