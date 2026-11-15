<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Staff\StaffAdministrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LinkCustomerStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(StaffAdministrationService::class)->manageableBranchesFor($user, 'staff.link')->isNotEmpty();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_email' => Str::lower(trim((string) $this->input('customer_email', ''))),
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
            'customer_email' => ['required', 'string', 'email', 'exists:users,email'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'staff_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9_-]{3,32}$/', 'unique:staff_profiles,staff_code'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_email.required' => 'Vui lòng nhập email tài khoản khách hàng cần liên kết.',
            'customer_email.email' => 'Địa chỉ email khách hàng không đúng định dạng.',
            'customer_email.exists' => 'Không tìm thấy tài khoản nào khớp với email đã nhập.',
            'branch_id.required' => 'Vui lòng chọn chi nhánh làm việc ban đầu.',
            'branch_id.exists' => 'Chi nhánh được chọn không tồn tại.',
            'staff_code.regex' => 'Mã nhân viên chỉ gồm 3–32 ký tự chữ in hoa, số, gạch ngang (-) hoặc gạch dưới (_).',
            'staff_code.unique' => 'Mã nhân viên này đã tồn tại trong hệ thống.',
            'role_ids.required' => 'Vui lòng chọn ít nhất một vai trò phạm vi chi nhánh.',
            'role_ids.min' => 'Vui lòng chọn ít nhất một vai trò phạm vi chi nhánh.',
        ];
    }
}
