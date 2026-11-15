<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\RoleScope;

/**
 * Định nghĩa chuẩn hóa tập vai trò hệ thống và 20 quyền hạn RBAC của Stage 01B.
 *
 * Nguyên tắc "một khái niệm nghiệp vụ = một định nghĩa chuẩn":
 * cả `RoleAndPermissionSeeder` lẫn việc đăng ký Laravel `Gate` trong `AppServiceProvider`
 * đều đọc trực tiếp từ danh mục bất biến này, không lưu trạng thái truy vấn CSDL khi khởi động.
 */
final class PermissionCatalog
{
    /**
     * Danh sách đầy đủ 20 quyền hạn chuẩn (10 `global` + 10 `branch`).
     *
     * @return list<array{
     *     code: string,
     *     module: string,
     *     name: string,
     *     scope: RoleScope,
     *     description: string
     * }>
     */
    public static function permissions(): array
    {
        return [
            // Phạm vi toàn hệ thống (Global — 10 quyền)
            [
                'code' => 'branches.view',
                'module' => 'branches',
                'name' => 'Xem danh sách chi nhánh',
                'scope' => RoleScope::Global,
                'description' => 'Xem thông tin kho trung tâm và toàn bộ chi nhánh trong hệ thống.',
            ],
            [
                'code' => 'branches.create',
                'module' => 'branches',
                'name' => 'Tạo chi nhánh mới',
                'scope' => RoleScope::Global,
                'description' => 'Khởi tạo chi nhánh bán lẻ mới trong hệ thống Book_Store.',
            ],
            [
                'code' => 'branches.update',
                'module' => 'branches',
                'name' => 'Cập nhật thông tin chi nhánh',
                'scope' => RoleScope::Global,
                'description' => 'Chỉnh sửa thông tin hành chính và trạng thái hoạt động của chi nhánh bán lẻ.',
            ],
            [
                'code' => 'staff.approve_activation',
                'module' => 'staff',
                'name' => 'Phê duyệt hoặc từ chối kích hoạt nhân sự',
                'scope' => RoleScope::Global,
                'description' => 'Thẩm định đề xuất kích hoạt quyền truy cập cho nhân sự mới.',
            ],
            [
                'code' => 'staff.reactivate',
                'module' => 'staff',
                'name' => 'Khôi phục quyền nhân sự',
                'scope' => RoleScope::Global,
                'description' => 'Kích hoạt lại quyền truy cập nhân sự cho tài khoản nhân viên đang bị đình chỉ.',
            ],
            [
                'code' => 'staff.suspend',
                'module' => 'staff',
                'name' => 'Đình chỉ quyền truy cập nhân sự toàn cục',
                'scope' => RoleScope::Global,
                'description' => 'Vô hiệu hóa trạng thái quyền truy cập nhân sự (staff_access_status = inactive) trên toàn hệ thống.',
            ],
            [
                'code' => 'staff.reset_temp_password',
                'module' => 'staff',
                'name' => 'Cấp lại mật khẩu tạm thời cho nhân sự',
                'scope' => RoleScope::Global,
                'description' => 'Phát hành mật khẩu tạm thời mới và bắt buộc nhân sự đổi mật khẩu khi đăng nhập lại.',
            ],
            [
                'code' => 'users.suspend_account',
                'module' => 'users',
                'name' => 'Khóa hoặc mở khóa tài khoản người dùng',
                'scope' => RoleScope::Global,
                'description' => 'Thay đổi trạng thái tài khoản tổng thể của người dùng trong hệ thống.',
            ],
            [
                'code' => 'roles.assign_global',
                'module' => 'rbac',
                'name' => 'Gán hoặc thu hồi vai trò toàn hệ thống',
                'scope' => RoleScope::Global,
                'description' => 'Quản lý việc gán và thu hồi các vai trò có phạm vi toàn hệ thống.',
            ],
            [
                'code' => 'audit.view_global',
                'module' => 'audit',
                'name' => 'Xem nhật ký kiểm toán toàn hệ thống',
                'scope' => RoleScope::Global,
                'description' => 'Tra cứu toàn bộ lịch sử thao tác quản trị trên tất cả chi nhánh.',
            ],

            // Phạm vi theo chi nhánh (Branch — 10 quyền)
            [
                'code' => 'staff.view',
                'module' => 'staff',
                'name' => 'Xem danh sách nhân sự chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Xem hồ sơ và trạng thái phân công nhân sự thuộc chi nhánh được giao.',
            ],
            [
                'code' => 'staff.create',
                'module' => 'staff',
                'name' => 'Tạo tài khoản nhân sự mới',
                'scope' => RoleScope::Branch,
                'description' => 'Khởi tạo tài khoản nhân sự mới kèm mật khẩu tạm thời cho chi nhánh.',
            ],
            [
                'code' => 'staff.link',
                'module' => 'staff',
                'name' => 'Liên kết tài khoản thành nhân sự',
                'scope' => RoleScope::Branch,
                'description' => 'Liên kết tài khoản người dùng sẵn có vào hồ sơ nhân sự của chi nhánh.',
            ],
            [
                'code' => 'staff.propose_activation',
                'module' => 'staff',
                'name' => 'Đề xuất kích hoạt nhân sự',
                'scope' => RoleScope::Branch,
                'description' => 'Gửi đề xuất kích hoạt quyền nhân sự để quản trị viên cấp cao phê duyệt.',
            ],
            [
                'code' => 'staff.suspend_branch',
                'module' => 'staff',
                'name' => 'Tạm đình chỉ nhân sự chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Tạm đình chỉ quyền truy cập nhân sự trong phạm vi chi nhánh được giao.',
            ],
            [
                'code' => 'branches.assign_staff',
                'module' => 'branches',
                'name' => 'Phân công nhân sự vào chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Thêm, cập nhật hoặc gỡ phân công nhân sự trong chi nhánh quản lý.',
            ],
            [
                'code' => 'roles.view',
                'module' => 'rbac',
                'name' => 'Xem danh mục vai trò phạm vi chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Xem danh sách các vai trò phạm vi chi nhánh được phép sử dụng trong chi nhánh quản lý.',
            ],
            [
                'code' => 'roles.assign_branch',
                'module' => 'rbac',
                'name' => 'Gán hoặc thu hồi vai trò chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Gán hoặc thu hồi các vai trò phạm vi chi nhánh cho nhân sự thuộc chi nhánh.',
            ],
            [
                'code' => 'audit.view_branch',
                'module' => 'audit',
                'name' => 'Xem nhật ký kiểm toán chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Tra cứu lịch sử thao tác quản trị phát sinh trong phạm vi chi nhánh.',
            ],
            [
                'code' => 'branch_ops.access',
                'module' => 'branch_ops',
                'name' => 'Truy cập khu vực tác nghiệp chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Quyền cơ sở để nhân viên truy cập không gian làm việc của chi nhánh được phân công.',
            ],
            [
                'code' => 'warehouse.dashboard.access',
                'module' => 'warehouse',
                'name' => 'Truy cập bảng điều khiển Kho trung tâm',
                'scope' => RoleScope::Branch,
                'description' => 'Truy cập giao diện quản lý chỉ đọc của Kho trung tâm Book_Store (CENTRAL_WH).',
            ],
            [
                'code' => 'warehouse.view',
                'module' => 'warehouse',
                'name' => 'Xem thông tin Kho trung tâm',
                'scope' => RoleScope::Branch,
                'description' => 'Xem hồ sơ chi tiết và trạng thái vận hành của Kho trung tâm Book_Store (CENTRAL_WH).',
            ],
        ];
    }

    /**
     * Danh sách 4 vai trò hệ thống chuẩn và ánh xạ quyền tương thích phạm vi (23 liên kết).
     *
     * @return list<array{
     *     code: string,
     *     name: string,
     *     scope: RoleScope,
     *     description: string,
     *     permissions: list<string>
     * }>
     */
    public static function roles(): array
    {
        return [
            [
                'code' => 'super_admin',
                'name' => 'Quản trị viên cấp cao',
                'scope' => RoleScope::Global,
                'description' => 'Quản trị toàn hệ thống Book_Store, quản lý chi nhánh, phê duyệt kích hoạt nhân sự và phân quyền cấp hệ thống.',
                'permissions' => [
                    'branches.view',
                    'branches.create',
                    'branches.update',
                    'staff.approve_activation',
                    'staff.reactivate',
                    'staff.suspend',
                    'staff.reset_temp_password',
                    'users.suspend_account',
                    'roles.assign_global',
                    'audit.view_global',
                ],
            ],
            [
                'code' => 'branch_admin',
                'name' => 'Quản lý chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Quản lý hồ sơ nhân sự, phân công và đề xuất kích hoạt nhân viên trong phạm vi chi nhánh được giao.',
                'permissions' => [
                    'staff.view',
                    'staff.create',
                    'staff.link',
                    'staff.propose_activation',
                    'staff.suspend_branch',
                    'branches.assign_staff',
                    'roles.view',
                    'roles.assign_branch',
                    'audit.view_branch',
                    'branch_ops.access',
                ],
            ],
            [
                'code' => 'staff',
                'name' => 'Nhân viên chi nhánh',
                'scope' => RoleScope::Branch,
                'description' => 'Nhân viên vận hành tác nghiệp trong phạm vi chi nhánh được phân công.',
                'permissions' => [
                    'branch_ops.access',
                ],
            ],
            [
                'code' => 'warehouse_manager',
                'name' => 'Quản lý Kho trung tâm',
                'scope' => RoleScope::Branch,
                'description' => 'Quản lý vận hành chỉ đọc tại Kho trung tâm Book_Store (CENTRAL_WH); không áp dụng cho chi nhánh bán lẻ.',
                'permissions' => [
                    'warehouse.dashboard.access',
                    'warehouse.view',
                ],
            ],
        ];
    }

    /**
     * Ánh xạ nhanh mã quyền -> phạm vi chuẩn hóa (`global` hoặc `branch`).
     *
     * @return array<string, RoleScope>
     */
    public static function scopeByCode(): array
    {
        $map = [];

        foreach (self::permissions() as $permission) {
            $map[$permission['code']] = $permission['scope'];
        }

        return $map;
    }
}
