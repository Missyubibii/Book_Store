<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bổ sung các cột vòng đời mật khẩu tạm cho luồng onboarding nhân sự trên bảng `users`.
     *
     * Với SQLite, sử dụng trực tiếp `ALTER TABLE ADD COLUMN` (không dùng `->change()` hay
     * tái tạo bảng tạm) nhằm bảo toàn toàn bộ bản ghi, ràng buộc và chỉ mục hiện có.
     */
    public function up(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        $usersTable = $connection->getTablePrefix().'users';

        Schema::table('users', function (Blueprint $table) use ($driver, $usersTable): void {
            if ($driver === 'sqlite') {
                $table->rawColumn('must_change_password', 'tinyint(1) check (must_change_password in (0, 1))')->default(false);
                $table->timestamp('temp_password_issued_at')->nullable();
                $table->timestamp('temp_password_expires_at')->nullable();
                $table->timestamp('password_changed_at')->nullable();
                $table->rawColumn('temp_password_issued_by', 'integer references "'.$usersTable.'"("id") on delete set null')->nullable();
            } else {
                $table->boolean('must_change_password')->default(false);
                $table->timestamp('temp_password_issued_at')->nullable();
                $table->timestamp('temp_password_expires_at')->nullable();
                $table->timestamp('password_changed_at')->nullable();
                $table->foreignId('temp_password_issued_by')->nullable()->constrained('users')->nullOnDelete();
            }

            $table->index('must_change_password');
        });
    }

    /**
     * Hoàn tác các cột vòng đời mật khẩu tạm trên bảng `users`.
     *
     * Trên SQLite phải xóa chỉ mục tham chiếu cột `must_change_password` trước khi
     * thực hiện `DROP COLUMN`, đồng thời tránh `dropForeign` để không kích hoạt tái tạo bảng.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('users', function (Blueprint $table) use ($driver): void {
            $table->dropIndex(['must_change_password']);

            if ($driver !== 'sqlite') {
                $table->dropForeign(['temp_password_issued_by']);
            }

            $table->dropColumn([
                'must_change_password',
                'temp_password_issued_at',
                'temp_password_expires_at',
                'password_changed_at',
                'temp_password_issued_by',
            ]);
        });
    }
};
