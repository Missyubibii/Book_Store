<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder gốc chỉ khởi tạo dữ liệu danh mục hệ thống chuẩn (RBAC & Kho Trung tâm).
 *
 * Lưu ý bảo mật: Tuyệt đối KHÔNG tạo tài khoản người dùng mẫu hoặc cấp quyền Super Admin
 * trong `DatabaseSeeder`; việc khởi tạo Super Admin đầu tiên chỉ thực hiện qua lệnh CLI an toàn.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Khởi tạo dữ liệu nền tảng cho ứng dụng.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);
    }
}
