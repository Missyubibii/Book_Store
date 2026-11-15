<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo bảng `user_roles` cho việc gán vai trò phạm vi toàn hệ thống (`global`).
     *
     * Khóa ngoại tổng hợp `(role_id, role_scope)` kết hợp `CHECK (role_scope = 'global')`
     * bảo đảm ở tầng CSDL rằng không thể gán nhầm vai trò phạm vi `branch` vào bảng này.
     */
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->rawColumn('role_scope', "varchar(20) check (role_scope = 'global')")->default('global');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
            $table->foreign(['role_id', 'role_scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
        });
    }

    /**
     * Xóa bảng `user_roles`.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
