<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccessStatusStandardizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_defaults_to_customer_with_not_applicable_staff_status(): void
    {
        $user = User::factory()->create();
        $user->refresh();

        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame(StaffAccessStatus::NotApplicable, $user->staff_access_status);
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->hasStaffAccess());
    }

    public function test_only_active_staff_access_on_active_account_is_eligible_for_staff_access(): void
    {
        $customer = User::factory()->create();
        $pending = User::factory()->staffPending()->create();
        $inactive = User::factory()->staffInactive()->create();
        $activeStaff = User::factory()->staffActive()->create();
        $suspendedStaff = User::factory()->staffActive()->suspended()->create();

        $this->assertFalse($customer->hasStaffAccess());
        $this->assertFalse($pending->hasStaffAccess());
        $this->assertFalse($inactive->hasStaffAccess());
        $this->assertTrue($activeStaff->hasStaffAccess());

        // Tài khoản nhân sự nhưng bị khóa tài khoản vẫn không được phép truy cập
        $this->assertFalse($suspendedStaff->hasStaffAccess());
    }

    public function test_eloquent_enum_casts_and_json_serialization_remain_compatible(): void
    {
        $user = User::factory()->staffPending()->create();

        $this->assertInstanceOf(AccountStatus::class, $user->account_status);
        $this->assertInstanceOf(StaffAccessStatus::class, $user->staff_access_status);

        $serialized = json_decode((string) json_encode($user), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('active', $serialized['account_status']);
        $this->assertSame('pending', $serialized['staff_access_status']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'account_status' => 'active',
            'staff_access_status' => 'pending',
        ]);
    }
}
