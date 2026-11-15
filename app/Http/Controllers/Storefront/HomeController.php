<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Hiển thị trang chủ công khai của hiệu sách trực tuyến Book_Store.
     *
     * Flow: GET / (route 'home', không yêu cầu đăng nhập)
     *       -> HomeController::__invoke()
     *       -> View 'welcome' (kế thừa layouts.storefront, hiển thị đúng ngữ cảnh Khách hoặc Độc giả đã đăng nhập)
     *
     * Business rules:
     * - Mọi khách vãng lai, khách hàng đã đăng nhập và nhân sự khi truy cập '/' đều xem giao diện hiệu sách công khai.
     * - Không tự động chuyển hướng sang trang đăng nhập hoặc bảng điều khiển nội bộ.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('welcome', [
            'currentUser' => $user,
            'needsEmailVerification' => $user !== null && ! $user->hasVerifiedEmail(),
        ]);
    }
}
