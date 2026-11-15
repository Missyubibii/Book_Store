<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo bảng `audit_logs` ghi nhận nhật ký kiểm toán cho các thao tác quản trị,
     * phân quyền, phân công chi nhánh, cấp mật khẩu tạm và phê duyệt nhân sự.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['target_user_id', 'occurred_at']);
            $table->index(['branch_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });
    }

    /**
     * Xóa bảng `audit_logs`.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
