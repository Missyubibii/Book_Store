<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chuẩn hóa cột xác thực cho Stage UI-03:
 * - Cho phép `users.password` nhận giá trị NULL đối với tài khoản chỉ đăng nhập bằng Google OAuth
 *   (chấm dứt việc sinh mật khẩu giả ngẫu nhiên gây hiển thị sai "Mật khẩu").
 * - Bổ sung `login_events.auth_method` để ghi nhận chính xác phương thức đăng nhập gần nhất (`password` / `google`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('password')->nullable()->change();
            });
        }

        if (Schema::hasTable('login_events') && ! Schema::hasColumn('login_events', 'auth_method')) {
            Schema::table('login_events', function (Blueprint $table): void {
                $table->string('auth_method', 32)->nullable()->after('event_type');
                $table->index(['user_id', 'event_type', 'occurred_at'], 'login_events_user_event_occurred_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('login_events') && Schema::hasColumn('login_events', 'auth_method')) {
            Schema::table('login_events', function (Blueprint $table): void {
                $table->dropIndex('login_events_user_event_occurred_idx');
                $table->dropColumn('auth_method');
            });
        }
    }
};
