<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mã hành động kiểm toán quản trị chuẩn hóa cho phân hệ nhân sự, phân quyền và chi nhánh (cột `audit_logs.action`).
 *
 * Phạm vi: Chỉ chứa các mã kiểm toán hành chính thuộc Stage 01B; không bao gồm
 * nghiệp vụ tồn kho, đơn hàng, thanh toán hoặc eBook.
 */
enum AuditAction: string
{
    case StaffCreated = 'staff_created';
    case StaffLinked = 'staff_linked';
    case TempPasswordIssued = 'temp_password_issued';
    case ForcedPasswordChanged = 'forced_password_changed';
    case StaffActivationProposed = 'staff_activation_proposed';
    case StaffActivationApproved = 'staff_activation_approved';
    case StaffActivationRejected = 'staff_activation_rejected';
    case StaffSuspended = 'staff_suspended';
    case StaffReactivated = 'staff_reactivated';
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';
    case BranchAssigned = 'branch_assigned';
    case BranchRemoved = 'branch_removed';
    case AccountSuspended = 'account_suspended';
    case AccountReactivated = 'account_reactivated';
    case SuperAdminBootstrapped = 'super_admin_bootstrapped';
    case BranchCreated = 'branch_created';
    case BranchUpdated = 'branch_updated';

    /**
     * Danh sách giá trị chuỗi hợp lệ lưu trong cơ sở dữ liệu.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Nhãn hiển thị tiếng Việt chuẩn hóa.
     */
    public function label(): string
    {
        return __('enums.audit_action.'.$this->value);
    }
}
