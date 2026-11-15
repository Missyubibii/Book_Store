<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LogoutUserAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Xử lý đăng xuất an toàn khỏi hệ thống.
     */
    public function destroy(Request $request, LogoutUserAction $action): RedirectResponse
    {
        $action->execute($request);

        return redirect('/');
    }
}

