<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Staff\StaffAdministrationService;
use Illuminate\Foundation\Http\FormRequest;

class DeactivateStaffBranchAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(StaffAdministrationService::class)->canViewStaffDirectory($user);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $trimmed = trim((string) $this->input('reason'));
            $this->merge(['reason' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmed.required' => 'Vui lòng đánh dấu xác nhận trước khi gỡ phân công chi nhánh của nhân sự.',
            'confirmed.accepted' => 'Vui lòng đánh dấu xác nhận trước khi gỡ phân công chi nhánh của nhân sự.',
            'reason.max' => 'Lý do gỡ phân công không được vượt quá 255 ký tự.',
        ];
    }
}
