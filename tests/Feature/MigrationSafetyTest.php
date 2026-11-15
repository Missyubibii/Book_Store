<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_can_run_safely_on_in_memory_database(): void
    {
        // Kiểm tra hai cột mới trong users
        $this->assertTrue(Schema::hasColumn('users', 'account_status'));
        $this->assertTrue(Schema::hasColumn('users', 'staff_access_status'));

        // Kiểm tra bảng login_events
        $this->assertTrue(Schema::hasTable('login_events'));
        $this->assertTrue(Schema::hasColumns('login_events', [
            'id',
            'user_id',
            'email',
            'event_type',
            'ip_address',
            'user_agent',
            'occurred_at',
        ]));
    }

    public function test_privileged_status_fields_are_guarded_against_mass_assignment(): void
    {
        // Cố gắng gán hàng loạt các trường nhạy cảm (bao gồm nâng quyền staff = 'active')
        $user = new User([
            'name' => 'Nguyễn Văn A',
            'email' => 'nguyenvana@example.com',
            'password' => 'secret123',
            'account_status' => AccountStatus::Suspended->value,
            'staff_access_status' => StaffAccessStatus::Active->value,
        ]);

        // Xác nhận các trường này KHÔNG bị ghi đè qua mass-assignment và giữ giá trị an toàn mặc định
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);

        // Khi lưu vào DB, các cột này vẫn giữ nguyên giá trị an toàn chuẩn hóa
        $user->save();
        $user->refresh();

        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);

        // Thử cập nhật hàng loạt qua fill()
        $user->fill([
            'account_status' => AccountStatus::Suspended->value,
            'staff_access_status' => StaffAccessStatus::Active->value,
        ]);

        // Giá trị vẫn không thay đổi
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);
    }

    public function test_database_schema_default_for_staff_access_status_is_not_applicable_and_preserves_indexes(): void
    {
        // Chèn trực tiếp ở tầng CSDL bỏ qua Eloquent Model để kiểm chứng schema default thực tế
        $rawUserId = DB::table('users')->insertGetId([
            'name' => 'Khách Hàng Raw DB',
            'email' => 'rawdb@example.com',
            'password' => bcrypt('secret123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $rawUserId,
            'account_status' => AccountStatus::Active->value,
            'staff_access_status' => StaffAccessStatus::NotApplicable->value,
        ]);

        // Kiểm tra các chỉ mục trên bảng users vẫn được bảo toàn sau migration chuẩn hóa
        $indexes = collect(Schema::getIndexes('users'))->pluck('name')->all();
        $this->assertContains('users_account_status_index', $indexes);
        $this->assertContains('users_staff_access_status_index', $indexes);

        // Kiểm tra khả năng rollback và migrate lại an toàn của migration mới trên DB cô lập
        Artisan::call('migrate:rollback', ['--step' => 1]);
        Artisan::call('migrate');

        $afterRemigrateId = DB::table('users')->insertGetId([
            'name' => 'Khách Hàng Sau Remigrate',
            'email' => 'remigrate@example.com',
            'password' => bcrypt('secret123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $afterRemigrateId,
            'staff_access_status' => StaffAccessStatus::NotApplicable->value,
        ]);
    }

    public function test_sqlite_foreign_key_null_on_delete_behavior_for_login_events(): void
    {
        // Đảm bảo SQLite đang bật foreign key constraints
        $fk = DB::select('PRAGMA foreign_keys;');
        $this->assertSame(1, (int) ($fk[0]->foreign_keys ?? 0), 'PRAGMA foreign_keys phải được bật trên kết nối SQLite kiểm thử.');

        // Tạo user
        $user = User::create([
            'name' => 'Trần Văn B',
            'email' => 'tranvanb@example.com',
            'password' => bcrypt('password123'),
        ]);

        // Tạo bản ghi login_events gắn với user_id
        $eventId = DB::table('login_events')->insertGetId([
            'user_id' => $user->id,
            'email' => $user->email,
            'event_type' => LoginEventType::LoginSuccess->value,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 Testing',
            'occurred_at' => now(),
        ]);

        $this->assertDatabaseHas('login_events', [
            'id' => $eventId,
            'user_id' => $user->id,
        ]);

        // Xóa user
        $user->delete();

        // Xác nhận bản ghi login_events vẫn tồn tại, nhưng user_id đã tự động chuyển thành NULL
        $this->assertDatabaseHas('login_events', [
            'id' => $eventId,
            'user_id' => null,
            'email' => 'tranvanb@example.com',
            'event_type' => LoginEventType::LoginSuccess->value,
        ]);
    }
}
