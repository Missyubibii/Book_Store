<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\StaffActivationProposal;
use Illuminate\Foundation\Http\FormRequest;

class ReviewStaffProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        /** @var StaffActivationProposal|null $proposal */
        $proposal = $this->route('proposal');

        return $user !== null
            && $proposal instanceof StaffActivationProposal
            && $user->can('review', $proposal);
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
        $isRejectRoute = $this->routeIs('admin.staff.approvals.reject');

        return [
            'confirmed' => ['required', 'accepted'],
            'review_note' => $isRejectRoute
                ? ['required', 'string', 'min:5', 'max:500']
                : ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmed.required' => 'Vui lòng đánh dấu xác nhận trước khi quyết định phê duyệt hoặc từ chối đề xuất.',
            'confirmed.accepted' => 'Vui lòng đánh dấu xác nhận trước khi quyết định phê duyệt hoặc từ chối đề xuất.',
            'review_note.required' => 'Vui lòng nhập lý do từ chối đề xuất kích hoạt nhân sự.',
            'review_note.min' => 'Lý do từ chối phải có ít nhất 5 ký tự.',
            'review_note.max' => 'Ghi chú thẩm định không được vượt quá 500 ký tự.',
        ];
    }
}
