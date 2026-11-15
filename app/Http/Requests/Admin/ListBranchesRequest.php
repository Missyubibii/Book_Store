<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('viewAny', Branch::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search')) {
            $this->merge([
                'search' => trim((string) $this->input('search')),
            ]);
        }

        if ($this->input('status') === '') {
            $this->merge(['status' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(BranchStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.max' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.',
            'status.enum' => 'Bộ lọc trạng thái chi nhánh không hợp lệ.',
        ];
    }

    public function statusFilter(): ?BranchStatus
    {
        $value = $this->validated('status');

        return is_string($value) && $value !== '' ? BranchStatus::tryFrom($value) : null;
    }

    public function searchTerm(): ?string
    {
        $value = $this->validated('search');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
