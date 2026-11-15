<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo bảng `branches` lưu thông tin kho trung tâm và các chi nhánh bán lẻ.
     *
     * Áp dụng ràng buộc kiểm tra cho `type` / `status` và chỉ mục duy nhất từng phần
     * để bảo đảm tối đa một kho trung tâm (`central_warehouse`) trong toàn hệ thống.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 150);
            $table->rawColumn('type', "varchar(32) check (type in ('central_warehouse', 'retail_branch'))")->default('retail_branch');
            $table->rawColumn('status', "varchar(20) check (status in ('active', 'inactive'))")->default('active');
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            $tableName = Schema::getConnection()->getTablePrefix().'branches';
            DB::statement("CREATE UNIQUE INDEX branches_single_central_warehouse_unique ON {$tableName} (type) WHERE type = 'central_warehouse'");
        }
    }

    /**
     * Xóa chỉ mục duy nhất từng phần (nếu có) và bảng `branches`.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS branches_single_central_warehouse_unique');
        }

        Schema::dropIfExists('branches');
    }
};
