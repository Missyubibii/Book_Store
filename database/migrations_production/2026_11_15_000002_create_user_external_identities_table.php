<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration chuẩn bị cho môi trường Production (Stage 01B.6):
 * Tạo bảng `user_external_identities` lưu trữ liên kết định danh người dùng với các nhà cung cấp bên ngoài (Google OAuth 2.0).
 *
 * Chú ý:
 * - KHÔNG thực thi trực tiếp trên SQLite phát triển (theo chỉ thị an toàn CSDL Stage 01B.6).
 * - Được chuẩn bị để chạy qua lệnh:
 *   php artisan migrate --path=database/migrations_production
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_external_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_subject', 191);
            $table->string('provider_email', 191)->nullable();
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamps();

            // Ràng buộc duy nhất: Một định danh Subject của Provider chỉ được gắn với duy nhất 1 tài khoản Book_Store
            $table->unique(['provider', 'provider_subject'], 'user_external_identities_provider_subject_unique');

            // Chỉ mục tra cứu tài khoản người dùng theo nhà cung cấp
            $table->index(['user_id', 'provider'], 'user_external_identities_user_provider_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_external_identities');
    }
};
