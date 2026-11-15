<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Staff\StaffAdministrationService;
use Illuminate\Foundation\Http\FormRequest;

class ProposeStaffActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(StaffAdministrationService::class)->manageableBranchesFor($user, 'staff.propose_activation')->isNotEmpty();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'proposal_reason' => trim((string) $this->input('proposal_reason', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'proposal_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Vui lòng chọn chi nhánh đề xuất kích hoạt.',
            'branch_id.exists' => 'Chi nhánh được chọn không tồn tại.',
            'proposal_reason.required' => 'Vui lòng nhập lý do đề xuất kích hoạt nhân sự.',
            'proposal_reason.min' => 'Lý do đề xuất kích hoạt phải có ít nhất 5 ký tự.',
            'proposal_reason.max' => 'Lý do đề xuất kích hoạt không được vượt quá 500 ký tự.',
        ];
    }
}
