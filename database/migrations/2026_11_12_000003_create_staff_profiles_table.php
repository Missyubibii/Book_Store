<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo bảng `staff_profiles` tách riêng hồ sơ nhân sự khỏi bảng `users`.
     *
     * Khóa ngoại `user_id` dùng `ON DELETE RESTRICT` để bảo toàn lịch sử nhân sự
     * và dữ liệu vận hành/kiểm toán liên quan.
     */
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('staff_code', 32)->unique();
            $table->string('job_title', 120)->nullable();
            $table->date('hired_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Xóa bảng `staff_profiles`.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
