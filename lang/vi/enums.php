<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nhãn hiển thị tiếng Việt cho các Enum nghiệp vụ chuẩn hóa
    |--------------------------------------------------------------------------
    */

    'account_status' => [
        'active' => 'Đang hoạt động',
        'suspended' => 'Đã bị khóa',
    ],

    'staff_access_status' => [
        'not_applicable' => 'Khách hàng (không áp dụng)',
        'pending' => 'Chờ kích hoạt quyền nhân viên',
        'active' => 'Đang hoạt động (nhân viên)',
        'inactive' => 'Đã vô hiệu hóa quyền nhân viên',
    ],

    'login_event_type' => [
        'register_success' => 'Đăng ký thành công',
        'login_success' => 'Đăng nhập thành công',
        'login_failed' => 'Đăng nhập thất bại',
        'account_locked' => 'Tài khoản bị khóa khi đăng nhập',
        'logout' => 'Đăng xuất',
        'session_revoked' => 'Thu hồi phiên làm việc',
    ],

    'branch_type' => [
        'central_warehouse' => 'Kho trung tâm',
        'retail_branch' => 'Chi nhánh bán lẻ',
    ],

    'branch_status' => [
        'active' => 'Đang hoạt động',
        'inactive' => 'Ngừng hoạt động',
    ],

    'role_scope' => [
        'global' => 'Toàn hệ thống',
        'branch' => 'Theo chi nhánh',
    ],

    'branch_assignment_status' => [
        'active' => 'Đang phân công',
        'inactive' => 'Ngừng phân công',
    ],

    'staff_approval_status' => [
        'pending' => 'Chờ phê duyệt',
        'approved' => 'Đã phê duyệt',
        'rejected' => 'Đã từ chối',
        'cancelled' => 'Đã hủy',
    ],

    'audit_action' => [
        'staff_created' => 'Tạo hồ sơ nhân viên',
        'staff_linked' => 'Liên kết tài khoản thành nhân viên',
        'temp_password_issued' => 'Cấp mật khẩu tạm thời',
        'forced_password_changed' => 'Đổi mật khẩu bắt buộc lần đầu',
        'staff_activation_proposed' => 'Đề xuất kích hoạt nhân viên',
        'staff_activation_approved' => 'Phê duyệt kích hoạt nhân viên',
        'staff_activation_rejected' => 'Từ chối kích hoạt nhân viên',
        'staff_suspended' => 'Tạm đình chỉ quyền nhân viên',
        'staff_reactivated' => 'Khôi phục quyền nhân viên',
        'role_assigned' => 'Gán vai trò',
        'role_revoked' => 'Thu hồi vai trò',
        'branch_assigned' => 'Phân công chi nhánh',
        'branch_removed' => 'Gỡ phân công chi nhánh',
        'account_suspended' => 'Khóa tài khoản người dùng',
        'account_reactivated' => 'Mở khóa tài khoản người dùng',
        'super_admin_bootstrapped' => 'Khởi tạo Super Admin hệ thống',
        'branch_created' => 'Tạo chi nhánh mới',
        'branch_updated' => 'Cập nhật thông tin chi nhánh',
    ],

];
