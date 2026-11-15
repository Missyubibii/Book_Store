<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_implements_must_verify_email_contract(): void
    {
        $user = new User();
        $this->assertInstanceOf(\Illuminate\Contracts\Auth\MustVerifyEmail::class, $user);
    }

    public function test_verify_email_notification_uses_100_percent_vietnamese_content(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Nguyễn Thị Mai',
            'email' => 'mainguyen@gmail.com',
            'password' => bcrypt('MatKhau@123'),
        ]);

        $notification = new VerifyEmail();
        VerifyEmail::createUrlUsing(function ($notifiable) {
            return 'http://localhost/xac-thuc-email/1/test-hash';
        });

        $mail = $notification->toMail($user);

        // Kiểm tra tiêu đề và nội dung tiếng Việt
        $this->assertSame('Xác nhận địa chỉ email của bạn - Book_Store', $mail->subject);
        $this->assertSame('Xin chào Nguyễn Thị Mai!', $mail->greeting);
        $this->assertSame('Xác nhận tài khoản', $mail->actionText);

        $renderedIntro = implode(' ', $mail->introLines);
        $this->assertStringContainsString('Cảm ơn bạn đã đăng ký tài khoản tại Book_Store', $renderedIntro);

        $renderedOutro = implode(' ', $mail->outroLines);
        $this->assertStringContainsString('Liên kết xác nhận này sẽ hết hạn sau 60 phút', $renderedOutro);
    }
}
