<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo danh mục phân quyền RBAC gồm `roles`, `permissions` và `permission_role`.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('roles', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->rawColumn('scope', "varchar(20) check (scope in ('global', 'branch'))");
            $table->text('description')->nullable();
            if ($driver === 'pgsql') {
                $table->boolean('is_system')->default(false);
            } else {
                $table->rawColumn('is_system', 'tinyint(1) check (is_system in (0, 1))')->default(false);
            }
            $table->timestamps();

            $table->unique(['id', 'scope']);
            $table->index(['scope', 'is_system']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('module', 50);
            $table->string('name', 150);
            $table->rawColumn('scope', "varchar(20) check (scope in ('global', 'branch'))");
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['module', 'scope']);
        });

        // Tính tương thích phạm vi giữa vai trò (roles.scope) và quyền (permissions.scope)
        // được kiểm tra chặt chẽ trong seeder chuẩn và tầng nghiệp vụ Stage 01B.3 (AuthorizationService),
        // bảo đảm vai trò `branch` không thể được gán các quyền chỉ dành cho phạm vi `global`.
        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });
    }

    /**
     * Xóa các bảng danh mục RBAC theo thứ tự phụ thuộc ngược.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
