<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Controller xử lý liên kết tài khoản Google Doanh nghiệp dành cho Nhân sự (Corporate Google Staff Onboarding).
 *
 * Flow:
 * 1. Quản trị viên tạo liên kết có chữ ký số (Signed URL) thời hạn 24 giờ.
 * 2. Nhân sự nhấp vào liên kết: GET /nhan-su/onboarding/google/{user}?signature=...
 * 3. Kiểm tra chữ ký hợp lệ -> Lưu intent vào session -> Chuyển hướng tới Google OAuth.
 * 4. Google Callback -> Xác thực token, liên kết `user_external_identities` với tài khoản nhân sự.
 */
class StaffGoogleOnboardingController extends Controller
{
    /**
     * Tiếp nhận liên kết mời có chữ ký số từ nhân sự và chuyển hướng ủy quyền Google.
     */
    public function claim(Request $request, User $user, GoogleOAuthService $service): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()
                ->route('login')
                ->with('error', 'Liên kết mời liên kết Google đã hết hạn hoặc không hợp lệ. Vui lòng liên hệ Quản trị viên để nhận mã mời mới.');
        }

        if (! (bool) config('services.google.enabled', false)) {
            return redirect()
                ->route('login')
                ->with('warning', 'Tính năng đăng nhập bằng Google hiện chưa được kích hoạt trên hệ thống.');
        }

        try {
            session([
                'google_oauth_intent' => 'staff_onboard',
                'staff_onboard_user_id' => $user->id,
            ]);

            $authUrl = $service->generateAuthorizationUrl();

            return redirect()->away($authUrl);
        } catch (Throwable $e) {
            return redirect()
                ->route('login')
                ->with('error', 'Không thể khởi tạo phiên xác thực Google. Vui lòng thử lại sau.');
        }
    }

    /**
     * Tạo liên kết mời liên kết Google Doanh nghiệp (dành cho Quản trị viên tại trang chi tiết nhân sự).
     */
    public function generateInviteLink(Request $request, User $staff): RedirectResponse
    {
        $inviteUrl = URL::temporarySignedRoute(
            'staff.onboarding.google.claim',
            now()->addHours(24),
            ['user' => $staff->id],
        );

        return redirect()
            ->route('admin.staff.show', $staff)
            ->with('success', 'Đã tạo liên kết mời liên kết Google doanh nghiệp (hiệu lực 24 giờ).')
            ->with('generated_google_invite_url', $inviteUrl);
    }
}
