<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
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
        $normalized = [
            'name' => trim((string) $this->input('name', '')),
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
        ];

        if ($this->has('code')) {
            $normalized['code'] = Str::upper(trim((string) $this->input('code')));
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Branch|null $branch */
        $branch = $this->route('branch');
        $immutableCode = $branch?->code;

        return [
            'code' => array_filter([
                'sometimes',
                'string',
                $immutableCode !== null ? Rule::in([$immutableCode]) : null,
            ]),
            'type' => ['sometimes', 'string', Rule::in([BranchType::RetailBranch->value])],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'status' => ['required', Rule::enum(BranchStatus::class)],
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
            'code.in' => 'Mã chi nhánh là định danh bất biến sau khi tạo và không thể chỉnh sửa.',
            'type.in' => 'Loại chi nhánh bán lẻ là bất biến và không thể chuyển đổi.',
            'name.required' => 'Vui lòng nhập tên chi nhánh.',
            'name.min' => 'Tên chi nhánh phải có ít nhất 2 ký tự.',
            'name.max' => 'Tên chi nhánh không được vượt quá 150 ký tự.',
            'status.required' => 'Vui lòng chọn trạng thái vận hành của chi nhánh.',
            'status.enum' => 'Trạng thái chi nhánh không hợp lệ.',
            'address.max' => 'Địa chỉ chi nhánh không được vượt quá 500 ký tự.',
            'phone.max' => 'Số điện thoại không được vượt quá 30 ký tự.',
            'phone.regex' => 'Số điện thoại chi nhánh không đúng định dạng hợp lệ (từ 7 đến 30 ký tự số và ký hiệu điện thoại).',
        ];
    }
}
