<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Staff\StaffAdministrationService;
use Illuminate\Foundation\Http\FormRequest;

class AssignStaffBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(StaffAdministrationService::class)->canViewStaffDirectory($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Vui lòng chọn chi nhánh cần phân công.',
            'branch_id.exists' => 'Chi nhánh được chọn không tồn tại.',
            'role_ids.required' => 'Vui lòng chọn ít nhất một vai trò chi nhánh.',
            'role_ids.min' => 'Vui lòng chọn ít nhất một vai trò chi nhánh.',
        ];
    }
}
