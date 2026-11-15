<?php

declare(strict_types=1);

namespace Tests\Feature\Stage01B5;

use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\CentralWarehouseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LocalDemoEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleAndPermissionSeeder::class,
            CentralWarehouseSeeder::class,
        ]);
    }

    public function test_demo_feature_is_disabled_by_default_and_unavailable_to_guests(): void
    {
        $this->assertFalse((bool) config('bookstore.demo_email_verification'));

        $this->post(route('verification.demo'))
            ->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertDontSeeText('Xác thực email (Demo)');

        $this->actingAs($unverified)
            ->post(route('verification.demo'))
            ->assertNotFound();

        $this->assertNull($unverified->fresh()->email_verified_at);
    }

    public function test_demo_endpoint_is_blocked_outside_local_even_if_config_flag_is_true(): void
    {
        config(['bookstore.demo_email_verification' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertDontSeeText('Xác thực email (Demo)');

        $this->actingAs($unverified)
            ->withSession(['_token' => 'demo-csrf'])
            ->post(route('verification.demo'), ['_token' => 'demo-csrf'])
            ->assertNotFound();

        $this->assertNull($unverified->fresh()->email_verified_at);
    }

    public function test_demo_verification_requires_valid_csrf_token_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['bookstore.demo_email_verification' => true]);

        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->post(route('verification.demo'))
            ->assertStatus(419);

        $this->assertNull($unverified->fresh()->email_verified_at);
    }

    public function test_demo_verification_verifies_current_user_only_is_idempotent_and_shows_unified_toast(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['bookstore.demo_email_verification' => true]);

        Event::fake([Verified::class]);

        $currentUser = User::factory()->unverified()->create();
        $otherUser = User::factory()->unverified()->create();

        $this->actingAs($currentUser)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSeeText('Xác thực email (Demo)')
            ->assertSeeText('Gửi lại email xác nhận');

        $response = $this->actingAs($currentUser)
            ->withSession(['_token' => 'demo-csrf'])
            ->followingRedirects()
            ->post(route('verification.demo'), [
                '_token' => 'demo-csrf',
                'user_id' => $otherUser->id,
                'email' => $otherUser->email,
            ])
            ->assertOk();

        $this->assertNotNull($currentUser->fresh()->email_verified_at);
        $this->assertNull($otherUser->fresh()->email_verified_at);

        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($currentUser));

        $html = $response->getContent();
        $this->assertStringContainsString('data-initial-notification=', $html);
        $this->assertStringContainsString('Đã xác thực email (Demo cục bộ) cho tài khoản hiện tại', $html);

        // Idempotent khi gọi lại lần 2
        $this->actingAs($currentUser->fresh())
            ->withSession(['_token' => 'demo-csrf'])
            ->post(route('verification.demo'), ['_token' => 'demo-csrf'])
            ->assertRedirect();

        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_demo_verification_never_activates_staff_or_escalates_warehouse_access(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['bookstore.demo_email_verification' => true]);

        $warehouse = Branch::query()->where('type', BranchType::CentralWarehouse->value)->firstOrFail();
        $warehouseManagerRole = Role::query()->where('code', 'warehouse_manager')->firstOrFail();

        $pendingStaff = User::factory()->staffPending()->unverified()->create([
            'must_change_password' => false,
        ]);
        StaffProfile::factory()->create(['user_id' => $pendingStaff->id]);
        BranchAssignment::query()->create([
            'user_id' => $pendingStaff->id,
            'branch_id' => $warehouse->id,
            'is_primary' => true,
            'status' => BranchAssignmentStatus::Active,
            'assigned_at' => now(),
        ]);
        BranchRoleAssignment::query()->create([
            'user_id' => $pendingStaff->id,
            'branch_id' => $warehouse->id,
            'role_id' => $warehouseManagerRole->id,
            'role_scope' => RoleScope::Branch->value,
            'assigned_at' => now(),
        ]);

        $this->actingAs($pendingStaff)
            ->withSession(['_token' => 'demo-csrf'])
            ->post(route('verification.demo'), ['_token' => 'demo-csrf'])
            ->assertRedirect();

        $reloaded = $pendingStaff->fresh();
        $this->assertNotNull($reloaded->email_verified_at);
        $this->assertSame(StaffAccessStatus::Pending, $reloaded->staff_access_status);
        $this->assertCount(0, $reloaded->roles()->get());

        // Nhân sự pending vẫn bị chặn khỏi Kho trung tâm dù đã xác thực email demo
        $this->actingAs($reloaded)
            ->get(route('admin.central-warehouse.show'))
            ->assertForbidden();
    }

    public function test_normal_email_verification_routes_remain_functional(): void
    {
        Notification::fake();

        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->post(route('verification.send'))
            ->assertRedirect();

        Notification::assertCount(1);

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => $unverified->id,
                'hash' => sha1($unverified->email),
            ],
        );

        $this->actingAs($unverified)
            ->get($signedUrl)
            ->assertRedirect('/?verified=1');

        $this->assertNotNull($unverified->fresh()->email_verified_at);
    }
}
