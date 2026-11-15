<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\StaffAccessStatus;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Controller xử lý luồng ủy quyền và phản hồi (Callback) của Google OAuth 2.0 (Stage 01B.5.8).
 *
 * Flow:
 * 1. User clicks "Tiếp tục với Google" -> GET /auth/google/redirect (`auth.google.redirect`)
 *    -> Sinh state bảo mật, lưu session -> Chuyển hướng tới Google Authorization Server.
 * 2. Google redirects back -> GET /auth/google/callback (`auth.google.callback`)
 *    -> Xác thực state, đổi code lấy Access Token, truy vấn UserInfo, xác minh cờ email_verified.
 *    -> Khởi tạo tài khoản khách hàng mới hoặc liên kết tài khoản hiện có an toàn.
 *    -> Tái tạo phiên làm việc (Session Regeneration), ghi login_events, điều hướng intended.
 */
class GoogleAuthController extends Controller
{
    /**
     * Chuyển hướng người dùng tới trang cấp quyền xác thực tài khoản của Google.
     */
    public function redirect(GoogleOAuthService $service): RedirectResponse
    {
        if (! (bool) config('services.google.enabled', false)) {
            return redirect()
                ->route('login')
                ->with('warning', 'Tính năng đăng nhập bằng Google hiện chưa được kích hoạt trên hệ thống.');
        }

        try {
            $authUrl = $service->generateAuthorizationUrl();

            return redirect()->away($authUrl);
        } catch (Throwable $exception) {
            return redirect()
                ->route('login')
                ->with('error', 'Không thể khởi tạo phiên xác thực Google. Vui lòng kiểm tra lại cấu hình hệ thống.');
        }
    }

    /**
     * Tiếp nhận phản hồi ủy quyền từ Google, kiểm chứng định danh và đăng nhập người dùng.
     */
    public function callback(Request $request, GoogleOAuthService $service): RedirectResponse
    {
        if (! (bool) config('services.google.enabled', false)) {
            return redirect()
                ->route('login')
                ->with('warning', 'Tính năng đăng nhập bằng Google hiện chưa được kích hoạt trên hệ thống.');
        }

        try {
            $result = $service->handleCallback($request);
            $user = $result['user'];
            $isNew = $result['is_new'];
            $intent = $result['intent'] ?? 'login';

            // Thiết lập phiên đăng nhập an toàn
            Auth::login($user, true);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            if ($intent === 'link') {
                return redirect()
                    ->route('account.security')
                    ->with('success', 'Đã liên kết tài khoản Google thành công.');
            }

            if ($intent === 'staff_onboard') {
                if (
                    $user->must_change_password
                    && in_array($user->staff_access_status, [StaffAccessStatus::Pending, StaffAccessStatus::Active], true)
                ) {
                    return redirect()
                        ->route('staff.onboarding.password.edit')
                        ->with('warning', 'Đã liên kết tài khoản Google doanh nghiệp thành công. Vui lòng hoàn tất đổi mật khẩu bắt buộc.');
                }

                return redirect()
                    ->intended('/')
                    ->with('success', 'Đã liên kết tài khoản Google doanh nghiệp thành công.');
            }

            // Nếu tài khoản nhân sự đang có mật khẩu tạm thời cần bắt buộc đổi trước
            if (
                $user->must_change_password
                && in_array($user->staff_access_status, [StaffAccessStatus::Pending, StaffAccessStatus::Active], true)
            ) {
                return redirect()
                    ->route('staff.onboarding.password.edit')
                    ->with('warning', 'Vui lòng đổi mật khẩu tạm thời bắt buộc trước khi tiếp tục sử dụng tài khoản nhân sự.');
            }

            $successMessage = $isNew
                ? 'Chào mừng bạn đến với Book_Store! Tài khoản đã được tạo và đăng nhập thành công qua Google.'
                : 'Đăng nhập thành công bằng tài khoản Google.';

            return redirect()
                ->intended('/')
                ->with('success', $successMessage);
        } catch (ValidationException $exception) {
            $firstMessage = collect($exception->errors())->flatten()->first();
            $errorMessage = is_string($firstMessage) && $firstMessage !== ''
                ? $firstMessage
                : 'Đăng nhập bằng Google không thành công. Vui lòng thử lại.';

            $targetRoute = Auth::check() ? route('account.security') : route('login');

            return redirect($targetRoute)
                ->with('error', $errorMessage);
        } catch (Throwable $exception) {
            $targetRoute = Auth::check() ? route('account.security') : route('login');

            return redirect($targetRoute)
                ->with('error', 'Có lỗi xảy ra trong quá trình xác thực với Google. Vui lòng thử lại sau.');
        }
            return redirect()
                ->route('login')
                ->with('error', 'Có lỗi xảy ra trong quá trình xác thực với Google. Vui lòng thử lại.');
        }
    }
}
