<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerificationController extends Controller
{
    /**
     * Hiển thị thông báo yêu cầu xác thực email.
     *
     * Flow: GET /xac-thuc-email (route 'verification.notice')
     *       -> EmailVerificationController@notice -> View 'auth.verify-email' (hoặc redirect nếu đã xác thực)
     */
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user() && $request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/');
        }

        return view('auth.verify-email', [
            'demoVerificationEnabled' => $this->isDemoVerificationEnabled(),
        ]);
    }

    /**
     * Xử lý xác thực email qua signed URL.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()
                ->intended('/?verified=1')
                ->with('info', 'Địa chỉ email của bạn đã được xác thực trước đó.');
        }

        if ($user->markEmailAsVerified()) {
            $user->refresh();
            event(new Verified($user));
        }

        return redirect()
            ->intended('/?verified=1')
            ->with('success', 'Địa chỉ email của bạn đã được xác thực thành công.');
    }

    /**
     * Gửi lại liên kết xác thực email.
     */
    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/');
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            Log::warning('email_verification_send_failed', [
                'user_id' => $request->user()?->id,
                'mailer' => (string) config('mail.default', 'log'),
                'error_class' => $exception::class,
            ]);

            return back()->with(
                'error',
                'Không thể gửi email xác nhận lúc này do dịch vụ gửi thư chưa sẵn sàng hoặc gặp sự cố kết nối. Quản trị viên có thể kiểm tra bằng lệnh: php artisan bookstore:setup-google.',
            );
        }

        return back()->with('status', 'verification-link-sent');
    }

    /**
     * Xác thực email Demo chỉ dành cho môi trường phát triển cục bộ (local).
     *
     * Chỉ xác thực email cho chính tài khoản đang đăng nhập; không tự động kích hoạt
     * quyền nhân sự, không thay đổi RBAC hay phân công chi nhánh.
     */
    public function verifyDemo(Request $request): RedirectResponse
    {
        if (! $this->isDemoVerificationEnabled()) {
            abort(404);
        }

        $user = $request->user();

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));

            Log::info('demo_email_verification_completed', [
                'user_id' => $user->id,
                'environment' => app()->environment(),
            ]);
        }

        return redirect()
            ->intended('/')
            ->with('success', 'Đã xác thực email (Demo cục bộ) cho tài khoản hiện tại. Quy trình phê duyệt nhân sự vẫn độc lập.');
    }

    private function isDemoVerificationEnabled(): bool
    {
        return app()->environment('local')
            && ! app()->isProduction()
            && (bool) config('bookstore.demo_email_verification', false);
    }
}
