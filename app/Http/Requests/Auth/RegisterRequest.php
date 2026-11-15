<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Xác định người dùng có quyền gửi request này hay không.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Chuẩn hóa dữ liệu đầu vào trước khi kiểm tra tính hợp lệ.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge([
                'email' => Str::lower(trim($this->email)),
            ]);
        }

        if (is_string($this->name)) {
            $this->merge([
                'name' => trim($this->name),
            ]);
        }
    }

    /**
     * Các quy tắc kiểm tra tính hợp lệ của dữ liệu đăng ký.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    /**
     * Tên thuộc tính tiếng Việt dùng cho thông báo lỗi.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'họ và tên',
            'email' => 'địa chỉ email',
            'password' => 'mật khẩu',
            'password_confirmation' => 'xác nhận mật khẩu',
        ];
    }
}

