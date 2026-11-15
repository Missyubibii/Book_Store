<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ReactivateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        /** @var User|null $staff */
        $staff = $this->route('staff');

        return $actor !== null
            && $staff instanceof User
            && $actor->can('reactivateStaff', $staff);
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmed.required' => 'Vui lòng đánh dấu xác nhận trước khi khôi phục quyền nhân sự.',
            'confirmed.accepted' => 'Vui lòng đánh dấu xác nhận trước khi khôi phục quyền nhân sự.',
            'reason.max' => 'Lý do khôi phục không được vượt quá 500 ký tự.',
        ];
    }
}
