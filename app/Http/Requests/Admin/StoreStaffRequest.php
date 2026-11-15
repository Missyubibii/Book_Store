<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Staff\StaffAdministrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(StaffAdministrationService::class)->manageableBranchesFor($user, 'staff.create')->isNotEmpty();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'email' => Str::lower(trim((string) $this->input('email', ''))),
            'staff_code' => $this->filled('staff_code') ? Str::upper(trim((string) $this->input('staff_code'))) : null,
            'job_title' => $this->filled('job_title') ? trim((string) $this->input('job_title')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'staff_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9_-]{3,32}$/', 'unique:staff_profiles,staff_code'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'temporary_password' => [
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ và tên nhân sự.',
            'name.min' => 'Họ và tên nhân sự phải có ít nhất 2 ký tự.',
            'email.required' => 'Vui lòng nhập địa chỉ email nhân sự.',
            'email.email' => 'Địa chỉ email không đúng định dạng hợp lệ.',
            'email.unique' => 'Email này đã tồn tại trong hệ thống. Vui lòng dùng chức năng Liên kết tài khoản khách hàng sẵn có nếu muốn bổ nhiệm làm nhân sự.',
            'branch_id.required' => 'Vui lòng chọn chi nhánh làm việc ban đầu.',
            'branch_id.exists' => 'Chi nhánh được chọn không tồn tại.',
            'staff_code.regex' => 'Mã nhân viên chỉ gồm 3–32 ký tự chữ in hoa, số, gạch ngang (-) hoặc gạch dưới (_).',
            'staff_code.unique' => 'Mã nhân viên này đã tồn tại trong hệ thống.',
            'role_ids.required' => 'Vui lòng chọn ít nhất một vai trò phạm vi chi nhánh.',
            'role_ids.min' => 'Vui lòng chọn ít nhất một vai trò phạm vi chi nhánh.',
            'temporary_password.required' => 'Vui lòng nhập mật khẩu tạm thời khởi tạo cho nhân sự.',
            'temporary_password.confirmed' => 'Mật khẩu tạm thời xác nhận không khớp.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'temporary_password' => 'mật khẩu tạm thời',
            'temporary_password_confirmation' => 'xác nhận mật khẩu tạm thời',
        ];
    }
}
