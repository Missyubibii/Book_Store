<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo bảng `branch_assignments` và `branch_role_assignments` cho phân công nhân sự
     * theo chi nhánh và gán vai trò giới hạn trong phạm vi chi nhánh (`branch`).
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('branch_assignments', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->rawColumn('status', "varchar(20) check (status in ('active', 'inactive'))")->default('active');
            if ($driver === 'pgsql') {
                $table->boolean('is_primary')->default(false);
            } else {
                $table->rawColumn('is_primary', 'tinyint(1) check (is_primary in (0, 1))')->default(false);
            }
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('branch_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('role_id');
            $table->rawColumn('role_scope', "varchar(20) check (role_scope = 'branch')")->default('branch');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id', 'role_id']);
            $table->index(['branch_id', 'role_id']);
            $table->foreign(['user_id', 'branch_id'])->references(['user_id', 'branch_id'])->on('branch_assignments')->cascadeOnDelete();
            $table->foreign(['role_id', 'role_scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
        });
    }

    /**
     * Xóa `branch_role_assignments` trước rồi mới xóa `branch_assignments`.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_role_assignments');
        Schema::dropIfExists('branch_assignments');
    }
};
