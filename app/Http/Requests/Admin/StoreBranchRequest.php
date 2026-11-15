<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Models\Branch;
use App\Services\Branches\BranchAdministrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Branch::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::upper(trim((string) $this->input('code', ''))),
            'name' => trim((string) $this->input('name', '')),
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:32',
                'regex:'.BranchAdministrationService::RETAIL_CODE_REGEX,
                Rule::notIn([BranchAdministrationService::CENTRAL_WAREHOUSE_CODE]),
                Rule::unique('branches', 'code'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'type' => ['sometimes', 'nullable', Rule::in([BranchType::RetailBranch->value])],
            'status' => ['sometimes', 'nullable', Rule::enum(BranchStatus::class)],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{7,30}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Vui lòng nhập mã chi nhánh.',
            'code.max' => 'Mã chi nhánh không được vượt quá 32 ký tự.',
            'code.regex' => 'Mã chi nhánh chỉ được gồm 3–32 ký tự chữ in hoa, số, gạch ngang (-) hoặc gạch dưới (_).',
            'code.not_in' => 'Mã CENTRAL_WH được dành riêng cho Kho trung tâm Book_Store và không thể dùng cho chi nhánh bán lẻ.',
            'code.unique' => 'Mã chi nhánh này đã tồn tại trong hệ thống.',
            'name.required' => 'Vui lòng nhập tên chi nhánh.',
            'name.min' => 'Tên chi nhánh phải có ít nhất 2 ký tự.',
            'name.max' => 'Tên chi nhánh không được vượt quá 150 ký tự.',
            'type.in' => 'Chỉ được phép khởi tạo Chi nhánh bán lẻ trong phân hệ này.',
            'status.enum' => 'Trạng thái ban đầu của chi nhánh không hợp lệ.',
            'address.max' => 'Địa chỉ chi nhánh không được vượt quá 500 ký tự.',
            'phone.max' => 'Số điện thoại không được vượt quá 30 ký tự.',
            'phone.regex' => 'Số điện thoại chi nhánh không đúng định dạng hợp lệ (từ 7 đến 30 ký tự số và ký hiệu điện thoại).',
        ];
    }
}
