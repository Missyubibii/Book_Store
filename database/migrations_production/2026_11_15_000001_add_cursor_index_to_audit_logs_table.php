<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration chuẩn bị cho môi trường Production (Stage 01B.5.7):
 * Bổ sung chỉ mục phức hợp (occurred_at, id) trên bảng audit_logs phục vụ phân trang con trỏ (cursor pagination).
 *
 * Chú ý:
 * - KHÔNG thực thi trực tiếp trên SQLite phát triển (theo chỉ thị bất biến nghiệp vụ Stage 01B.5.7).
 * - Được chuẩn bị để chạy trên Production qua lệnh:
 *   php artisan migrate --path=database/migrations_production
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['occurred_at', 'id'], 'audit_logs_occurred_at_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_occurred_at_id_index');
        });
    }
};
