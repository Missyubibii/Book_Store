<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Actions\Auth\CompleteMandatoryPasswordChangeAction;
use App\Enums\StaffAccessStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ForceChangePasswordRequest;
use App\Services\Authorization\AuthorizationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class MandatoryPasswordChangeController extends Controller
{
    /**
     * Hiển thị màn hình đổi mật khẩu bắt buộc cho nhân sự / Super Administrator onboarding.
     *
     * Flow: GET /nhan-su/doi-mat-khau-bat-buoc (`staff.onboarding.password.edit`, middleware `staff.onboarding`)
     *       -> MandatoryPasswordChangeController@edit -> View `staff.onboarding.password`
     */
    public function edit(): View
    {
        return view('staff.onboarding.password');
    }

    /**
     * Xử lý hoàn tất đổi mật khẩu tạm thời bắt buộc.
     *
     * Flow: POST /nhan-su/doi-mat-khau-bat-buoc -> ForceChangePasswordRequest
     *       -> CompleteMandatoryPasswordChangeAction -> Regenerate Session
     *       -> Redirect `/quan-tri` (nếu là Effective Super Admin) hoặc `/` (kèm thông báo tiếng Việt)
     */
    public function update(
        ForceChangePasswordRequest $request,
        CompleteMandatoryPasswordChangeAction $action,
        AuthorizationService $authorization,
    ): RedirectResponse {
        $updatedUser = $action->execute(
            user: $request->user(),
            newPlainPassword: (string) $request->input('password'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        Auth::guard('web')->setUser($updatedUser);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        if ($authorization->isEffectiveSuperAdmin($updatedUser)) {
            return redirect()->route('admin.dashboard')->with(
                'success',
                'Đã cập nhật mật khẩu bảo mật thành công. Bạn hiện có thể truy cập khu vực quản trị hệ thống.',
            );
        }

        if ($updatedUser->staff_access_status === StaffAccessStatus::Pending) {
            return redirect()->route('home')->with(
                'info',
                'Đã hoàn tất đổi mật khẩu bắt buộc. Hồ sơ nhân sự của bạn đang chờ Quản trị viên phê duyệt kích hoạt.',
            );
        }

        return redirect()->route('home')->with(
            'success',
            'Đã hoàn tất đổi mật khẩu bắt buộc cho tài khoản nhân sự.',
        );
    }
}
