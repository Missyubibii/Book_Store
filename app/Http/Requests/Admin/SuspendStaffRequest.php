<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SuspendStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        /** @var User|null $staff */
        $staff = $this->route('staff');

        return $actor !== null
            && $staff instanceof User
            && $actor->can('suspendStaff', [$staff, null]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmed.required' => 'Vui lòng đánh dấu xác nhận trước khi đình chỉ quyền nhân sự.',
            'confirmed.accepted' => 'Vui lòng đánh dấu xác nhận trước khi đình chỉ quyền nhân sự.',
            'reason.required' => 'Vui lòng nhập lý do đình chỉ quyền truy cập nhân sự.',
            'reason.min' => 'Lý do đình chỉ phải có ít nhất 5 ký tự.',
            'reason.max' => 'Lý do đình chỉ không được vượt quá 500 ký tự.',
        ];
    }
}
