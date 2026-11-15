<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B3;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Authorization\DomainGuardService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kiểm thử đồng thời đa tiến trình thực sự trên tệp SQLite cô lập tạm thời
 * (không sử dụng `RefreshDatabase` và tuyệt đối không chạm vào `database/database.sqlite`).
 */
class SqliteMultiProcessConcurrencyTest extends TestCase
{
    public function test_independent_php_processes_cannot_leave_zero_effective_super_admins_even_with_array_cache(): void
    {
        $tempDbPath = storage_path('framework/testing/concurrent_super_admin_'.uniqid('', true).'.sqlite');
        $testingDir = dirname($tempDbPath);

        if (! is_dir($testingDir)) {
            mkdir($testingDir, 0777, true);
        }

        touch($tempDbPath);

        $originalConfig = Config::get('database.connections.sqlite');

        try {
            Config::set('database.connections.sqlite.database', $tempDbPath);
            DB::purge('sqlite');

            $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
            $this->seed(RoleAndPermissionSeeder::class);

            $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

            $sa1 = User::factory()->staffActive()->create(['email' => 'sa1.concurrent@example.com']);
            $sa2 = User::factory()->staffActive()->create(['email' => 'sa2.concurrent@example.com']);

            UserRole::query()->create(['user_id' => $sa1->id, 'role_id' => $superAdminRole->id]);
            UserRole::query()->create(['user_id' => $sa2->id, 'role_id' => $superAdminRole->id]);

            /** @var DomainGuardService $domainGuards */
            $domainGuards = app(DomainGuardService::class);
            $this->assertSame(2, $domainGuards->countEffectiveSuperAdmins());

            // Ngắt kết nối trước khi phóng hai tiến trình PHP CLI độc lập dùng `CACHE_STORE=array`
            // để chứng minh tuần tự hóa dựa hoàn toàn vào SQLite `BEGIN IMMEDIATE`.
            DB::disconnect('sqlite');

            $workerPhp = base64_encode(<<<'PHP'
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\Config::set('cache.default', 'array');
Illuminate\Support\Facades\Config::set('database.default', 'sqlite');
Illuminate\Support\Facades\Config::set('database.connections.sqlite.database', $argv[1]);
Illuminate\Support\Facades\DB::purge('sqlite');

$targetId = (int) $argv[2];
$sleepMs = (int) $argv[3];
$guards = $app->make(App\Services\Authorization\DomainGuardService::class);
$target = App\Models\User::query()->findOrFail($targetId);

try {
    $guards->ensureEffectiveSuperAdminRemainsAfterMutation($target, function () use ($target, $sleepMs): void {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $target->forceFill(['staff_access_status' => App\Enums\StaffAccessStatus::Inactive->value])->save();
    });

    echo "COMMITTED\n";
    exit(0);
} catch (App\Exceptions\DomainInvariantException $exception) {
    echo 'REJECTED:' . $exception->invariantCode . "\n";
    exit(2);
} catch (Throwable $exception) {
    echo 'ERROR:' . $exception->getMessage() . "\n";
    exit(3);
}
PHP);

            $inlineRunner = 'eval(base64_decode("'.$workerPhp.'"));';
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process1 = proc_open(
                [PHP_BINARY, '-r', $inlineRunner, '--', $tempDbPath, (string) $sa1->id, '250'],
                $descriptors,
                $pipes1,
                base_path(),
            );

            usleep(80000);

            $process2 = proc_open(
                [PHP_BINARY, '-r', $inlineRunner, '--', $tempDbPath, (string) $sa2->id, '0'],
                $descriptors,
                $pipes2,
                base_path(),
            );

            $this->assertIsResource($process1);
            $this->assertIsResource($process2);

            fclose($pipes1[0]);
            fclose($pipes2[0]);

            $stdout1 = trim((string) stream_get_contents($pipes1[1]));
            $stderr1 = trim((string) stream_get_contents($pipes1[2]));
            $stdout2 = trim((string) stream_get_contents($pipes2[1]));
            $stderr2 = trim((string) stream_get_contents($pipes2[2]));

            fclose($pipes1[1]);
            fclose($pipes1[2]);
            fclose($pipes2[1]);
            fclose($pipes2[2]);

            $code1 = proc_close($process1);
            $code2 = proc_close($process2);

            $outcomes = [$stdout1, $stdout2];

            $this->assertSame('', $stderr1, "Process 1 stderr unexpected: {$stderr1}");
            $this->assertSame('', $stderr2, "Process 2 stderr unexpected: {$stderr2}");
            $this->assertContains('COMMITTED', $outcomes, 'One concurrent Super Admin mutation must succeed.');
            $this->assertContains('REJECTED:last_effective_super_admin_required', $outcomes, 'Competing mutation must reject and preserve the last effective Super Admin.');
            $this->assertSame([0, 2], collect([$code1, $code2])->sort()->values()->all());

            DB::purge('sqlite');
            $this->assertSame(1, $domainGuards->countEffectiveSuperAdmins());
            $this->assertSame(
                1,
                User::query()->where('staff_access_status', StaffAccessStatus::Active->value)
                    ->where('account_status', AccountStatus::Active->value)
                    ->count(),
            );
        } finally {
            DB::disconnect('sqlite');
            Config::set('database.connections.sqlite', $originalConfig);
            DB::purge('sqlite');

            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                $candidate = $tempDbPath.$suffix;
                if (file_exists($candidate)) {
                    @unlink($candidate);
                }
            }
        }
    }
}
