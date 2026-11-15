<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\Auth\GoogleOAuthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Controller quản lý Cài đặt bảo mật & Phương thức đăng nhập của người dùng (Stage 01B.6).
 *
 * Flow:
 * - GET /tai-khoan/bao-mat -> Xem các phương thức đăng nhập đã liên kết.
 * - POST /tai-khoan/lien-ket-google -> Khởi tạo luồng liên kết tài khoản Google có chủ đích.
 * - POST /tai-khoan/huy-lien-ket-google -> Hủy liên kết Google an toàn (chống khóa tài khoản nếu không còn phương thức nào).
 */
class AccountSecurityController extends Controller
{
    /**
     * Hiển thị giao diện cài đặt bảo mật và quản lý phương thức đăng nhập.
     */
    public function show(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $googleIdentity = null;
        if (Schema::hasTable('user_external_identities')) {
            $googleIdentity = $user->externalIdentities()
                ->where('provider', 'google')
                ->first();
        }

        return view('auth.security', [
            'user' => $user,
            'googleIdentity' => $googleIdentity,
            'hasPassword' => $user->hasPassword(),
            'canUnlinkGoogle' => $user->canUnlinkProvider('google'),
        ]);
    }

    /**
     * Khởi tạo quá trình liên kết tài khoản Google có chủ đích (Deliberate Linking).
     */
    public function linkGoogle(GoogleOAuthService $service): RedirectResponse
    {
        if (! (bool) config('services.google.enabled', false)) {
            return redirect()
                ->route('account.security')
                ->with('warning', 'Tính năng đăng nhập bằng Google hiện chưa được kích hoạt trên hệ thống.');
        }

        /** @var User $user */
        $user = Auth::user();

        if (Schema::hasTable('user_external_identities')) {
            $existing = $user->externalIdentities()->where('provider', 'google')->exists();
            if ($existing) {
                return redirect()
                    ->route('account.security')
                    ->with('warning', 'Tài khoản của bạn đã được liên kết với một tài khoản Google.');
            }
        }

        try {
            // Đặt Intent và ID tài khoản đang đăng nhập vào phiên làm việc
            session([
                'google_oauth_intent' => 'link',
                'google_oauth_user_id' => $user->id,
            ]);

            $authUrl = $service->generateAuthorizationUrl();

            return redirect()->away($authUrl);
        } catch (Throwable $e) {
            return redirect()
                ->route('account.security')
                ->with('error', 'Không thể khởi tạo phiên xác thực Google. Vui lòng kiểm tra lại cấu hình hệ thống.');
        }
    }

    /**
     * Hủy liên kết tài khoản Google an toàn.
     */
    public function unlinkGoogle(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmed' => ['required', 'accepted'],
        ], [
            'confirmed.accepted' => 'Vui lòng xác nhận trước khi hủy liên kết tài khoản Google.',
            'confirmed.required' => 'Vui lòng xác nhận trước khi hủy liên kết tài khoản Google.',
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (! $user->canUnlinkProvider('google')) {
            return redirect()
                ->route('account.security')
                ->with('error', 'Không thể hủy phương thức đăng nhập duy nhất. Bạn cần thiết lập mật khẩu trước khi hủy liên kết tài khoản Google.');
        }

        if (Schema::hasTable('user_external_identities')) {
            $user->externalIdentities()
                ->where('provider', 'google')
                ->delete();
        }

        return redirect()
            ->route('account.security')
            ->with('success', 'Đã hủy liên kết tài khoản Google thành công.');
    }
}
