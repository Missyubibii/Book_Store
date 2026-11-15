<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Branch;
use App\Models\User;

/**
 * Ngữ cảnh chi nhánh bất biến gắn riêng cho từng HTTP request (Request-Scoped).
 *
 * Tuyệt đối không lưu trữ đối tượng này trong singleton service hoặc biến tĩnh toàn cục.
 */
final readonly class BranchContext
{
    public const string REQUEST_ATTRIBUTE = 'bookstore.branch_context';

    /**
     * @param  list<string>  $roleCodes
     * @param  list<string>  $permissions
     */
    public function __construct(
        public Branch $branch,
        public User $user,
        public bool $viaSuperAdmin,
        public array $roleCodes,
        public array $permissions,
    ) {}

    public function branchId(): int
    {
        return (int) $this->branch->id;
    }

    public function userId(): int
    {
        return (int) $this->user->id;
    }

    public function hasRole(string $roleCode): bool
    {
        return in_array($roleCode, $this->roleCodes, true);
    }

    public function hasPermission(string $permissionCode): bool
    {
        return in_array($permissionCode, $this->permissions, true);
    }
}
