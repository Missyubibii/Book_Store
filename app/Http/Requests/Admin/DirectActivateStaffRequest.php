<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DirectActivateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        /** @var User|null $staff */
        $staff = $this->route('staff');

        return $actor !== null
            && $staff instanceof User
            && $actor->can('directActivateStaff', $staff);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('review_note')) {
            $trimmed = trim((string) $this->input('review_note'));
            $this->merge(['review_note' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmed.required' => 'Vui lòng đánh dấu xác nhận trước khi kích hoạt trực tiếp quyền nhân sự.',
            'confirmed.accepted' => 'Vui lòng đánh dấu xác nhận trước khi kích hoạt trực tiếp quyền nhân sự.',
            'review_note.max' => 'Ghi chú kích hoạt không được vượt quá 500 ký tự.',
        ];
    }
}
