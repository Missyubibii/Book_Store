<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\StaffAccessStatus;
use App\Services\Staff\StaffAdministrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(StaffAdministrationService::class)->canViewStaffDirectory($user);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search')) {
            $this->merge(['search' => trim((string) $this->input('search'))]);
        }

        foreach (['status', 'branch_id'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => [
                'nullable',
                Rule::in([
                    StaffAccessStatus::Pending->value,
                    StaffAccessStatus::Active->value,
                    StaffAccessStatus::Inactive->value,
                ]),
            ],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.max' => 'Từ khóa tìm kiếm nhân sự không được vượt quá 100 ký tự.',
            'status.in' => 'Trạng thái nhân sự lọc không hợp lệ.',
            'branch_id.integer' => 'Chi nhánh lọc không hợp lệ.',
            'branch_id.exists' => 'Chi nhánh lọc không tồn tại trong hệ thống.',
        ];
    }

    public function statusFilter(): ?StaffAccessStatus
    {
        $value = $this->validated('status');

        return is_string($value) && $value !== '' ? StaffAccessStatus::tryFrom($value) : null;
    }

    public function searchTerm(): ?string
    {
        $value = $this->validated('search');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function branchIdFilter(): ?int
    {
        $value = $this->validated('branch_id');

        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
