<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B3;

use App\Database\BookStoreSqliteConnection;
use App\Enums\BranchType;
use App\Exceptions\DomainInvariantException;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StaffActivationProposal;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Authorization\DomainGuardService;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\TestCase;

class SecurityHardeningAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private DomainGuardService $domainGuards;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);

        $this->domainGuards = app(DomainGuardService::class);
    }

    public function test_hardened_domain_guards_and_policies_reject_unsafe_inputs(): void
    {
        $superAdmin1 = $this->createSuperAdmin();
        $superAdmin2 = $this->createSuperAdmin();
        $unsavedUser = new User(['name' => 'Chưa lưu']);
        $branch = Branch::factory()->retailBranch()->create();

        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
        $staffRole = Role::query()->where('code', 'staff')->firstOrFail();

        // 1. ensureNotSelfAction từ chối bản ghi chưa tồn tại
        try {
            $this->domainGuards->ensureNotSelfAction($superAdmin1, $unsavedUser);
            $this->fail('Unsaved user must fail closed in ensureNotSelfAction.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('invalid_actor_or_target', $exception->invariantCode);
        }

        // 2. Policy thu hồi vai trò toàn cục
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('revokeGlobalRole', [$superAdmin1, $superAdminRole]));
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('revokeGlobalRole', [$superAdmin2, $staffRole]));
        $this->assertTrue(Gate::forUser($superAdmin1)->allows('revokeGlobalRole', [$superAdmin2, $superAdminRole]));

        // 3. Policy đề xuất kích hoạt từ chối tài khoản đang active hoặc bị khóa
        $activeStaff = User::factory()->staffActive()->create();
        $suspendedPending = User::factory()->staffPending()->suspended()->create();
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('propose', [StaffActivationProposal::class, $activeStaff, $branch]));
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('propose', [StaffActivationProposal::class, $suspendedPending, $branch]));

        // 4. ensureGlobalRoleRevocationAllowed chặn tự thu hồi & bảo vệ Super Admin cuối cùng
        try {
            $this->domainGuards->ensureGlobalRoleRevocationAllowed($superAdmin1, $superAdmin1, $superAdminRole, fn () => null);
            $this->fail('Self global role revocation must be rejected.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('self_action_forbidden', $exception->invariantCode);
        }

        // Thu hồi superAdmin2 hợp lệ vì còn superAdmin1
        $this->domainGuards->ensureGlobalRoleRevocationAllowed($superAdmin1, $superAdmin2, $superAdminRole, function () use ($superAdmin2, $superAdminRole): void {
            UserRole::query()
                ->where('user_id', $superAdmin2->id)
                ->where('role_id', $superAdminRole->id)
                ->delete();
        });
        $this->assertSame(1, $this->domainGuards->countEffectiveSuperAdmins());

        // Thu hồi người cuối cùng (superAdmin1 bởi actor khác) phải thất bại và rollback
        $otherActor = User::factory()->staffActive()->create();

        try {
            $this->domainGuards->ensureGlobalRoleRevocationAllowed($otherActor, $superAdmin1, $superAdminRole, function () use ($superAdmin1, $superAdminRole): void {
                UserRole::query()
                    ->where('user_id', $superAdmin1->id)
                    ->where('role_id', $superAdminRole->id)
                    ->delete();
            });
            $this->fail('Last effective Super Admin global role revocation must roll back.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('last_effective_super_admin_required', $exception->invariantCode);
        }

        $this->assertSame(1, $this->domainGuards->countEffectiveSuperAdmins());

        // 5. Kho trung tâm vẫn bất biến
        $centralWh = Branch::query()->where('code', 'CENTRAL_WH')->firstOrFail();
        $this->assertSame(BranchType::CentralWarehouse, $centralWh->type);
        $this->assertFalse(Gate::forUser($superAdmin1)->allows('delete', $centralWh));
    }

    public function test_sqlite_connection_uses_immediate_transaction_and_rolls_back_on_exception(): void
    {
        $connection = DB::connection();
        $this->assertInstanceOf(BookStoreSqliteConnection::class, $connection);

        $superAdmin = $this->createSuperAdmin();

        // Ném ngoại lệ bất ngờ bên trong closure -> phải rollback hoàn toàn savepoint/transaction
        try {
            $this->domainGuards->ensureEffectiveSuperAdminRemainsAfterMutation($superAdmin, function () use ($superAdmin): void {
                $superAdmin->forceFill(['name' => 'Tên Bị Rollback'])->save();

                throw new RuntimeException('Simulated failure inside critical mutation');
            });
            $this->fail('Unexpected exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure inside critical mutation', $exception->getMessage());
        }

        $this->assertNotSame('Tên Bị Rollback', $superAdmin->fresh()->name);
    }

    private function createSuperAdmin(): User
    {
        $superAdmin = User::factory()->staffActive()->create();
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

        UserRole::query()->create([
            'user_id' => $superAdmin->id,
            'role_id' => $superAdminRole->id,
        ]);

        return $superAdmin;
    }
}
