<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\StaffAccessStatus;
use App\Enums\StaffApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\StaffActivationProposal;
use App\Models\User;
use App\Services\Authorization\AuthorizationService;
use App\Services\Branches\BranchAdministrationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminDashboardController extends Controller
{
    /**
     * Hiển thị điểm vào khu vực quản trị được bảo vệ cho Super Administrator.
     *
     * Flow: GET /quan-tri (`admin.dashboard`, middleware `auth`, `account.active`, `staff.active`, `super_admin.effective`)
     *       -> AdminDashboardController::__invoke -> View `admin.dashboard`
     */
    public function __invoke(
        Request $request,
        AuthorizationService $authorization,
        BranchAdministrationService $branchAdmin,
    ): View {
        $user = $request->user();

        if ($user === null || ! $authorization->isEffectiveSuperAdmin($user)) {
            abort(403, __('auth.super_admin_required'));
        }

        $tz = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $nowLocal = CarbonImmutable::now($tz);
        $allowedPeriods = ['7_days', '30_days', 'this_year', 'custom'];
        $period = (string) $request->query('period', '30_days');
        if (! in_array($period, $allowedPeriods, true)) {
            $period = '30_days';
        }

        $startDateInput = (string) $request->query('start_date', '');
        $endDateInput = (string) $request->query('end_date', '');
        $periodError = null;

        if ($period === 'custom') {
            $validator = Validator::make(
                ['start_date' => $startDateInput, 'end_date' => $endDateInput],
                [
                    'start_date' => ['required', 'date', 'before_or_equal:end_date', 'before_or_equal:'.$nowLocal->toDateString()],
                    'end_date' => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:'.$nowLocal->toDateString()],
                ],
                [
                    'start_date.required' => 'Vui lòng chọn ngày bắt đầu.',
                    'start_date.date' => 'Ngày bắt đầu không hợp lệ.',
                    'start_date.before_or_equal' => 'Ngày bắt đầu phải trước hoặc bằng ngày kết thúc và không vượt quá hôm nay.',
                    'end_date.required' => 'Vui lòng chọn ngày kết thúc.',
                    'end_date.date' => 'Ngày kết thúc không hợp lệ.',
                    'end_date.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.',
                    'end_date.before_or_equal' => 'Ngày kết thúc không được vượt quá hôm nay.',
                ]
            );

            if ($validator->fails()) {
                $periodError = $validator->errors()->first();
                $period = '30_days';
                $startDateLocal = $nowLocal->subDays(30)->startOfDay();
                $endDateLocal = $nowLocal->endOfDay();
                $periodLabel = '30 ngày qua';
            } else {
                $startDateLocal = CarbonImmutable::parse($startDateInput, $tz)->startOfDay();
                $endDateLocal = CarbonImmutable::parse($endDateInput, $tz)->endOfDay();
                $periodLabel = 'Từ '.$startDateLocal->format('d/m/Y').' đến '.$endDateLocal->format('d/m/Y');
            }
        } else {
            $endDateLocal = $nowLocal->endOfDay();
            [$startDateLocal, $periodLabel] = match ($period) {
                '7_days' => [$nowLocal->subDays(7)->startOfDay(), '7 ngày qua'],
                'this_year' => [$nowLocal->startOfYear(), 'Năm nay ('.$nowLocal->year.')'],
                default => [$nowLocal->subDays(30)->startOfDay(), '30 ngày qua'],
            };
        }

        $startDateUtc = $startDateLocal->utc();
        $endDateUtc = $endDateLocal->utc();

        // 1. KPI: Tổng số chi nhánh & Trạng thái chi nhánh
        $retailBranchesQuery = Branch::query()->where('type', BranchType::RetailBranch);
        $totalBranches = (clone $retailBranchesQuery)->count();
        $activeBranches = (clone $retailBranchesQuery)->where('status', BranchStatus::Active)->count();
        $inactiveBranches = (clone $retailBranchesQuery)->where('status', BranchStatus::Inactive)->count();

        // Chi nhánh cần chú ý: Chi nhánh đang active nhưng chưa có nhân sự active phân công
        $branchesNeedingAttention = Branch::query()
            ->where('type', BranchType::RetailBranch)
            ->where('status', BranchStatus::Active)
            ->whereDoesntHave('branchAssignments', fn ($q) => $q->where('status', 'active'))
            ->count();

        // 2. KPI: Nhân sự đang hoạt động
        $activeStaff = User::query()
            ->where('staff_access_status', StaffAccessStatus::Active)
            ->count();

        // 3. KPI: Tổng tài khoản khách hàng & đăng ký mới trong kỳ
        $customerQuery = User::query()->where('staff_access_status', StaffAccessStatus::NotApplicable);
        $totalCustomers = (clone $customerQuery)->count();
        $newCustomersPeriod = (clone $customerQuery)
            ->whereBetween('created_at', [$startDateUtc, $endDateUtc])
            ->count();

        // 4. KPI: Đề xuất chờ phê duyệt
        $pendingApprovalsCount = StaffActivationProposal::query()
            ->where('status', StaffApprovalStatus::Pending)
            ->count();

        // Danh sách đề xuất nhân sự chờ xử lý (tối đa 5 bản ghi mới nhất)
        $pendingProposals = StaffActivationProposal::query()
            ->where('status', StaffApprovalStatus::Pending)
            ->with(['user.staffProfile', 'branch'])
            ->latest('proposed_at')
            ->take(5)
            ->get();

        // 5. Kho trung tâm hệ thống
        $centralWarehouse = $branchAdmin->getCentralWarehouse();

        // 6. Tóm tắt trạng thái chi nhánh
        $branchSummary = [
            'total' => $totalBranches,
            'active' => $activeBranches,
            'inactive' => $inactiveBranches,
            'attention_needed' => $branchesNeedingAttention,
        ];

        // 7. Nhật ký hoạt động gần đây (5 bản ghi mới nhất)
        $recentActivities = AuditLog::query()
            ->with(['actor.staffProfile', 'targetUser.staffProfile', 'branch'])
            ->latest('occurred_at')
            ->take(5)
            ->get();

        $effectivePermissions = $authorization->effectiveGlobalPermissions($user);

        return view('admin.dashboard', [
            'adminUser' => $user,
            'period' => $period,
            'periodLabel' => $periodLabel,
            'startDateInput' => $startDateInput !== '' ? $startDateInput : $startDateLocal->toDateString(),
            'endDateInput' => $endDateInput !== '' ? $endDateInput : $endDateLocal->toDateString(),
            'periodError' => $periodError,
            'kpis' => [
                'total_branches' => $totalBranches,
                'active_branches' => $activeBranches,
                'active_staff' => $activeStaff,
                'total_customers' => $totalCustomers,
                'new_customers_period' => $newCustomersPeriod,
                'pending_approvals' => $pendingApprovalsCount,
            ],
            'pendingProposals' => $pendingProposals,
            'branchSummary' => $branchSummary,
            'centralWarehouse' => $centralWarehouse,
            'recentActivities' => $recentActivities,
            'effectiveGlobalPermissionCount' => count($effectivePermissions),
            'passwordChangedAtDisplay' => format_datetime($user->password_changed_at),
        ]);
    }
}
