<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        /** @var Branch|null $branch */
        $branch = $this->route('branch');

        return $user !== null
            && $branch instanceof Branch
            && $user->can('update', $branch);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $trimmed = trim((string) $this->input('reason'));
            $this->merge([
                'reason' => $trimmed === '' ? null : $trimmed,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(BranchStatus::class)],
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
            'status.required' => 'Vui lòng xác định trạng thái mới của chi nhánh.',
            'status.enum' => 'Trạng thái chi nhánh không hợp lệ.',
            'confirmed.required' => 'Vui lòng đánh dấu xác nhận trước khi thay đổi trạng thái hoạt động của chi nhánh.',
            'confirmed.accepted' => 'Vui lòng đánh dấu xác nhận trước khi thay đổi trạng thái hoạt động của chi nhánh.',
            'reason.max' => 'Lý do thay đổi trạng thái không được vượt quá 255 ký tự.',
        ];
    }
}
