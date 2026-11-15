<?php

use App\Enums\StaffAccessStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chuẩn hóa giá trị mặc định cột `users.staff_access_status` sang `not_applicable`.
     *
     * Chỉ sửa đổi ràng buộc mặc định ở tầng lược đồ cho bản ghi tạo mới; không tự động
     * thay đổi các bản ghi đã tồn tại để đảm bảo an toàn đánh giá dữ liệu cũ.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_access_status')
                ->default(StaffAccessStatus::NotApplicable->value)
                ->change();
        });
    }

    /**
     * Hoàn tác giá trị mặc định của cột `users.staff_access_status`.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_access_status')
                ->default('active')
                ->change();
        });
    }
};
