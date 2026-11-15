<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RegisterController extends Controller
{
    /**
     * Hiển thị giao diện đăng ký tài khoản độc giả.
     *
     * Flow: GET /dang-ky (route 'register') -> RegisterController@create -> View 'auth.register'
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Xử lý đăng ký tài khoản khách hàng mới.
     *
     * Flow: POST /dang-ky -> RegisterRequest -> RegisterCustomerAction
     *       -> User created (AccountStatus::Active / StaffAccessStatus::NotApplicable) + Registered event + Auth::login -> Redirect intended('/')
     */
    public function store(RegisterRequest $request, RegisterCustomerAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request);

        return redirect()->intended('/');
    }
}
