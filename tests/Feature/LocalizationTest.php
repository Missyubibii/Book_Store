<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_application_locale_is_vietnamese(): void
    {
        $this->assertSame('vi', app()->getLocale());
        $this->assertSame('vi', config('app.locale'));
        $this->assertSame('vi', config('app.fallback_locale'));
    }

    public function test_validation_messages_are_translated_to_vietnamese(): void
    {
        $validator = Validator::make([], [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $this->assertTrue($validator->fails());

        $errors = $validator->errors();

        $this->assertSame(
            'Vui lòng nhập trường địa chỉ email.',
            $errors->first('email')
        );

        $this->assertSame(
            'Vui lòng nhập trường mật khẩu.',
            $errors->first('password')
        );
    }

    public function test_password_complexity_validation_messages_are_100_percent_vietnamese(): void
    {
        $rules = [
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];

        // 1. Thiếu chữ hoa (chỉ có chữ thường)
        $mixedValidator = Validator::make(['password' => 'matkhau@123'], $rules);
        $this->assertTrue($mixedValidator->fails());
        $this->assertSame(
            'Mật khẩu phải chứa ít nhất một chữ cái viết hoa và một chữ cái viết thường.',
            $mixedValidator->errors()->first('password')
        );

        // 2. Thiếu chữ số
        $numberValidator = Validator::make(['password' => 'MatKhau@abc'], $rules);
        $this->assertTrue($numberValidator->fails());
        $this->assertSame(
            'Mật khẩu phải chứa ít nhất một chữ số.',
            $numberValidator->errors()->first('password')
        );

        // 3. Thiếu ký tự đặc biệt
        $symbolValidator = Validator::make(['password' => 'MatKhau1234'], $rules);
        $this->assertTrue($symbolValidator->fails());
        $this->assertSame(
            'Mật khẩu phải chứa ít nhất một ký tự đặc biệt.',
            $symbolValidator->errors()->first('password')
        );
    }

    public function test_auth_and_ui_language_lines_exist(): void
    {
        $this->assertSame(
            'Thông tin đăng nhập không chính xác.',
            Lang::get('auth.failed')
        );

        $this->assertSame(
            'Quá nhiều lần đăng nhập không thành công. Vui lòng thử lại sau 60 giây.',
            Lang::get('auth.throttle', ['seconds' => 60])
        );

        $this->assertSame(
            'Đăng nhập',
            Lang::get('ui.actions.login')
        );

        $this->assertSame(
            'Đang hoạt động',
            Lang::get('ui.status.active')
        );

        $this->assertSame(
            'Khách hàng (không áp dụng)',
            StaffAccessStatus::NotApplicable->label()
        );
        $this->assertSame(
            'Đã bị khóa',
            AccountStatus::Suspended->label()
        );
    }

    public function test_http_error_json_translations_and_419_view_are_100_percent_vietnamese(): void
    {
        $this->assertSame('Truy cập bị từ chối', __('Forbidden'));
        $this->assertSame('Liên kết xác thực không hợp lệ hoặc đã hết hạn.', __('Invalid signature.'));
        $this->assertSame(
            'Bạn không có quyền thực hiện thao tác này hoặc liên kết xác thực không hợp lệ.',
            __('This action is unauthorized.')
        );
        $this->assertSame('Quá nhiều yêu cầu', __('Too Many Requests'));
        $this->assertSame(
            'Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau ít phút.',
            __('Too Many Attempts.')
        );
        $this->assertSame('Phiên làm việc đã hết hạn', __('Page Expired'));

        $rendered419 = view('errors.419')->render();
        $this->assertStringContainsString('Phiên làm việc đã hết hạn', $rendered419);
        $this->assertStringContainsString('Quay lại trang đăng nhập', $rendered419);
        $this->assertStringNotContainsString('Page Expired', $rendered419);
    }

    public function test_database_is_in_memory_and_isolated(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        $this->assertSame('sqlite', $connection);
        $this->assertSame(':memory:', $database);
    }
}
