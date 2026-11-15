<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AuditAction;
use App\Services\Authorization\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(AuthorizationService::class)->isEffectiveSuperAdmin($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', Rule::in(AuditAction::values())],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'target_user_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'in:10,20,30,50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in' => 'Loại hành động kiểm toán được chọn không hợp lệ.',
            'date_from.date_format' => 'Ngày bắt đầu phải đúng định dạng YYYY-MM-DD.',
            'date_to.date_format' => 'Ngày kết thúc phải đúng định dạng YYYY-MM-DD.',
            'date_to.after_or_equal' => 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.',
        ];
    }

    /**
     * @return array{
     *     search: ?string,
     *     action: ?string,
     *     actor_id: ?int,
     *     target_user_id: ?int,
     *     branch_id: ?int,
     *     date_from: ?string,
     *     date_to: ?string,
     *     per_page: int
     * }
     */
    public function filters(): array
    {
        $validated = $this->validated();

        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $action = isset($validated['action']) ? trim((string) $validated['action']) : null;

        return [
            'search' => $search !== '' ? $search : null,
            'action' => $action !== '' ? $action : null,
            'actor_id' => isset($validated['actor_id']) ? (int) $validated['actor_id'] : null,
            'target_user_id' => isset($validated['target_user_id']) ? (int) $validated['target_user_id'] : null,
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            'date_from' => isset($validated['date_from']) && $validated['date_from'] !== '' ? (string) $validated['date_from'] : null,
            'date_to' => isset($validated['date_to']) && $validated['date_to'] !== '' ? (string) $validated['date_to'] : null,
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : 20,
        ];
    }
}
