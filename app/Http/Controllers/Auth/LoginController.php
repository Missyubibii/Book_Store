<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Enums\StaffAccessStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class LoginController extends Controller
{
    /**
     * Hiển thị giao diện đăng nhập độc giả.
     *
     * Flow: GET /dang-nhap (route 'login') -> LoginController@create -> View 'auth.login'
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Xử lý đăng nhập tài khoản.
     *
     * Flow: POST /dang-nhap -> LoginRequest (rate limit + validation)
     *       -> AuthenticateUserAction (verify credentials + active status + login_events)
     *       -> Nếu nhân sự cần đổi mật khẩu tạm bắt buộc -> Redirect `staff.onboarding.password.edit`
     *       -> Ngược lại -> Redirect intended('/')
     */
    public function store(LoginRequest $request, AuthenticateUserAction $action): RedirectResponse
    {
        $user = $action->execute($request);

        if (
            $user->must_change_password
            && in_array($user->staff_access_status, [StaffAccessStatus::Pending, StaffAccessStatus::Active], true)
        ) {
            return redirect()->route('staff.onboarding.password.edit')->with(
                'warning',
                'Vui lòng đổi mật khẩu tạm thời bắt buộc trước khi tiếp tục sử dụng tài khoản nhân sự.',
            );
        }

        return redirect()->intended('/');
    }
}
