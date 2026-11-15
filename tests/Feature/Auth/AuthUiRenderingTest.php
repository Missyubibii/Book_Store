<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthUiRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_view_renders_vietnamese_ui_and_no_forgot_password_link(): void
    {
        $response = $this->get('/dang-nhap');

        $response->assertStatus(200);
        $response->assertViewIs('auth.login');
        $response->assertSeeText('Đăng nhập tài khoản');
        $response->assertSeeText('Địa chỉ email');
        $response->assertSeeText('Mật khẩu');
        $response->assertSeeText('Ghi nhớ đăng nhập trên thiết bị này');
        $response->assertSeeText('Đăng nhập vào hiệu sách');
        $response->assertSeeText('Mở tài khoản mới');

        // Kiểm tra cấu trúc HTML & Accessibility
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="remember"', false);
        $response->assertSee('aria-label="Hiện mật khẩu"', false);
        $response->assertSee('href="'.route('register').'"', false);

        // Tuyệt đối không có liên kết quên mật khẩu công khai
        $response->assertDontSeeText('Quên mật khẩu');
        $response->assertDontSee('forgot-password', false);
        $response->assertDontSee('password.request', false);
    }

    public function test_login_view_displays_vietnamese_validation_errors_and_preserves_email(): void
    {
        $response = $this->from('/dang-nhap')
            ->followingRedirects()
            ->post('/dang-nhap', [
                'email' => 'docgia.thu@example.com',
                'password' => 'SaiMatKhau123!',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('Không thể hoàn tất đăng nhập:');
        $response->assertSeeText('Thông tin đăng nhập không chính xác.');
        $response->assertSee('value="docgia.thu@example.com"', false);
        $response->assertSee('aria-invalid="true"', false);
        $response->assertDontSee('SaiMatKhau123!', false);
    }

    public function test_register_view_renders_vietnamese_ui_and_excludes_privileged_status_fields(): void
    {
        $response = $this->get('/dang-ky');

        $response->assertStatus(200);
        $response->assertViewIs('auth.register');
        $response->assertSeeText('Mở tài khoản độc giả');
        $response->assertSeeText('Họ và tên độc giả');
        $response->assertSeeText('Địa chỉ email');
        $response->assertSeeText('Mật khẩu thiết lập');
        $response->assertSeeText('Xác nhận lại mật khẩu');
        $response->assertSeeText('Tiêu chuẩn bảo mật mật khẩu:');
        $response->assertSeeText('Độ dài từ 8 ký tự trở lên.');
        $response->assertSeeText('Tạo tài khoản độc giả');
        $response->assertSeeText('Đăng nhập ngay');

        $response->assertSee('name="name"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="password_confirmation"', false);
        $response->assertSee('href="'.route('login').'"', false);

        // Đảm bảo không có trường trạng thái đặc quyền trong biểu mẫu đăng ký
        $response->assertDontSee('name="account_status"', false);
        $response->assertDontSee('name="staff_access_status"', false);
    }

    public function test_register_view_displays_validation_errors_and_preserves_old_inputs(): void
    {
        $response = $this->from('/dang-ky')
            ->followingRedirects()
            ->post('/dang-ky', [
                'name' => 'Lê Minh Khuê',
                'email' => 'leminhkhue@example.com',
                'password' => 'ngan',
                'password_confirmation' => 'khong-khop',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('Vui lòng kiểm tra lại thông tin đăng ký:');
        $response->assertSee('value="Lê Minh Khuê"', false);
        $response->assertSee('value="leminhkhue@example.com"', false);
        $response->assertSee('aria-invalid="true"', false);
    }

    public function test_verify_email_view_renders_instructions_actions_and_resend_status(): void
    {
        $user = User::create([
            'name' => 'Nguyễn Tuân',
            'email' => 'nguyentuan@example.com',
            'password' => Hash::make('MatKhauAnToan@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        // 1. Hiển thị màn hình thông báo xác thực mặc định
        $response = $this->actingAs($user)->get('/xac-thuc-email');

        $response->assertStatus(200);
        $response->assertViewIs('auth.verify-email');
        $response->assertSeeText('Xác thực địa chỉ email');
        $response->assertSeeText('nguyentuan@example.com');
        $response->assertSeeText('Gửi lại email xác nhận');
        $response->assertSeeText('Đăng xuất tài khoản');
        $response->assertSee('action="'.route('verification.send').'"', false);
        $response->assertSee('action="'.route('logout').'"', false);

        // 2. Hiển thị thông báo trạng thái khi đã gửi lại liên kết xác nhận
        $statusResponse = $this->actingAs($user)
            ->withSession(['status' => 'verification-link-sent'])
            ->get('/xac-thuc-email');

        $statusResponse->assertStatus(200);
        $statusResponse->assertSeeText('Đã gửi liên kết mới:');
        $statusResponse->assertSeeText('Một liên kết xác nhận mới đã được gửi đến địa chỉ email bạn đã đăng ký.');
    }

    public function test_auth_views_load_vite_assets_and_contain_no_inline_style_or_script_blocks(): void
    {
        $response = $this->get('/dang-nhap');

        $response->assertStatus(200);
        $this->assertRendersViteAssets($response);
        $response->assertDontSee('<style>', false);
        $response->assertDontSee('document.addEventListener(\'DOMContentLoaded\'', false);

        $this->withoutViteHotFile(function (): void {
            $manifestResponse = $this->get('/dang-nhap');
            $manifestResponse->assertStatus(200);
            $manifestResponse->assertSee('build/assets/app-', false);
        });
    }

    public function test_register_http_submission_returns_exact_vietnamese_mixed_case_password_message(): void
    {
        $response = $this->from('/dang-ky')
            ->followingRedirects()
            ->post('/dang-ky', [
                'name' => 'Trần Minh Đức',
                'email' => 'tranminhduc@example.com',
                'password' => 'matkhau@123',
                'password_confirmation' => 'matkhau@123',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('Mật khẩu phải chứa ít nhất một chữ cái viết hoa và một chữ cái viết thường.');
        $response->assertDontSeeText('The password field must contain at least one uppercase and one lowercase letter.');
    }

    public function test_dynamic_notification_component_renders_flash_types_and_escapes_html_safely(): void
    {
        // 1. Thông báo thành công (success -> role="status", aria-live="polite")
        $successResponse = $this->withSession([
            'success' => 'Đã cập nhật thông tin tài khoản độc giả.',
        ])->get('/dang-nhap');

        $successResponse->assertStatus(200);
        $successResponse->assertSee('data-notification-center', false);
        $successResponse->assertSee('data-notify-type="success"', false);
        $successResponse->assertSee('role="status"', false);
        $successResponse->assertSee('aria-live="polite"', false);
        $successResponse->assertSee('aria-label="Đóng thông báo"', false);
        $successResponse->assertSeeText('Đã cập nhật thông tin tài khoản độc giả.');

        // 2. Thông báo cảnh báo / lỗi & chống XSS (escaping HTML/script)
        $xssPayload = '<script>window.__xss = true;</script>Cảnh báo phiên truy cập.';
        $warningResponse = $this->withSession([
            'warning' => $xssPayload,
        ])->get('/dang-nhap');

        $warningResponse->assertStatus(200);
        $warningResponse->assertSee('data-notify-type="warning"', false);
        $warningResponse->assertSee('role="alert"', false);
        $warningResponse->assertSee('aria-live="assertive"', false);
        $warningResponse->assertDontSee('<script>window.__xss = true;</script>', false);
        $warningResponse->assertSee('&lt;script&gt;window.__xss = true;&lt;/script&gt;', false);
    }

    public function test_dynamic_notification_component_includes_programmatic_dismiss_event_and_keyboard_accessible_toggle(): void
    {
        $response = $this->withSession([
            'info' => 'Thông báo kiểm thử điều khiển bàn phím.',
        ])->get('/dang-nhap');

        $response->assertStatus(200);
        // Sự kiện đóng thông báo từ window.BookStoreNotify.dismiss()
        $response->assertSee('@bs:notify-dismiss.window="dismiss()"', false);

        // Thuộc tính trợ năng bàn phím trên vùng thu gọn / mở rộng nội dung thông báo
        $response->assertSee('data-notify-toggle', false);
        $response->assertSee('role="button"', false);
        $response->assertSee('tabindex="0"', false);
        $response->assertSee('aria-expanded="true"', false);
        $response->assertSee(':aria-expanded="expanded ? \'true\' : \'false\'"', false);
        $response->assertSee('aria-label="Mở rộng hoặc thu gọn nội dung thông báo"', false);
        $response->assertSee('@keydown.enter.prevent="toggleExpand()"', false);
        $response->assertSee('@keydown.space.prevent="toggleExpand()"', false);
    }

    public function test_public_home_page_renders_100_percent_vietnamese_for_guests_and_authenticated_users(): void
    {
        // 1. Khách chưa đăng nhập (Guest)
        $guestResponse = $this->get('/');

        $guestResponse->assertStatus(200);
        $guestResponse->assertSeeText('Book_Store');
        $guestResponse->assertSeeText('Hiệu sách Book_Store — Không gian Sách in & Ấn phẩm Số');
        $guestResponse->assertSeeText('Đăng nhập tài khoản');
        $guestResponse->assertSeeText('Mở tài khoản độc giả');
        $guestResponse->assertSee('href="'.route('login').'"', false);
        $guestResponse->assertSee('href="'.route('register').'"', false);
        $this->assertRendersViteAssets($guestResponse);
        $guestResponse->assertDontSee('<style>', false);
        $guestResponse->assertDontSeeText("Let's get started");
        $guestResponse->assertDontSeeText('Deploy now');
        $guestResponse->assertDontSeeText('Log in');
        $guestResponse->assertDontSeeText('Register');

        // 2. Độc giả đã đăng nhập (Authenticated)
        $user = User::create([
            'name' => 'Phạm Duy Tốn',
            'email' => 'phamduyton@example.com',
            'password' => Hash::make('MatKhauAnToan@123'),
        ]);
        $user->account_status = 'active';
        $user->save();

        $authResponse = $this->actingAs($user)->get('/');

        $authResponse->assertStatus(200);
        $authResponse->assertSeeText('Xin chào, Phạm Duy Tốn');
        $authResponse->assertSeeText('phamduyton@example.com');
        $authResponse->assertSeeText('Xác thực địa chỉ email');
        $authResponse->assertSeeText('Đăng xuất tài khoản');
        $authResponse->assertSee('action="'.route('logout').'"', false);
        $authResponse->assertDontSeeText('Dashboard');
    }
}
