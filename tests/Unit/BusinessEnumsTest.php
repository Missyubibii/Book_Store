<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Enums\StaffAccessStatus;
use Tests\TestCase;

class BusinessEnumsTest extends TestCase
{
    public function test_staff_access_status_enum_matches_canonical_contract(): void
    {
        $this->assertSame(
            ['not_applicable', 'pending', 'active', 'inactive'],
            StaffAccessStatus::values()
        );

        // Chỉ duy nhất Active đủ điều kiện trạng thái nhân sự
        $this->assertFalse(StaffAccessStatus::NotApplicable->isEligibleForStaffAccess());
        $this->assertFalse(StaffAccessStatus::Pending->isEligibleForStaffAccess());
        $this->assertTrue(StaffAccessStatus::Active->isEligibleForStaffAccess());
        $this->assertFalse(StaffAccessStatus::Inactive->isEligibleForStaffAccess());

        // Kiểm tra quy tắc chuyển đổi trạng thái hợp lệ
        $this->assertTrue(StaffAccessStatus::NotApplicable->canTransitionTo(StaffAccessStatus::Pending));
        $this->assertFalse(StaffAccessStatus::NotApplicable->canTransitionTo(StaffAccessStatus::Active));
        $this->assertTrue(StaffAccessStatus::Pending->canTransitionTo(StaffAccessStatus::Active));
        $this->assertTrue(StaffAccessStatus::Active->canTransitionTo(StaffAccessStatus::Inactive));
        $this->assertTrue(StaffAccessStatus::Inactive->canTransitionTo(StaffAccessStatus::Active));

        // JSON serialization phải giữ nguyên giá trị chuỗi chuẩn CSDL
        $this->assertSame('"not_applicable"', json_encode(StaffAccessStatus::NotApplicable));
    }

    public function test_account_status_enum_matches_canonical_contract(): void
    {
        $this->assertSame(['active', 'suspended'], AccountStatus::values());

        $this->assertTrue(AccountStatus::Active->canAuthenticate());
        $this->assertFalse(AccountStatus::Suspended->canAuthenticate());

        $this->assertTrue(AccountStatus::Active->canTransitionTo(AccountStatus::Suspended));
        $this->assertFalse(AccountStatus::Active->canTransitionTo(AccountStatus::Active));
        $this->assertTrue(AccountStatus::Suspended->canTransitionTo(AccountStatus::Active));
        $this->assertSame('"suspended"', json_encode(AccountStatus::Suspended));
    }

    public function test_login_event_type_enum_contains_all_canonical_auth_events(): void
    {
        $this->assertSame([
            'register_success',
            'login_success',
            'login_failed',
            'account_locked',
            'logout',
            'session_revoked',
        ], LoginEventType::values());
    }

    public function test_all_business_enums_provide_non_empty_vietnamese_labels(): void
    {
        foreach (StaffAccessStatus::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('enums.staff_access_status.', $label);
        }

        foreach (AccountStatus::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('enums.account_status.', $label);
        }

        foreach (LoginEventType::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('enums.login_event_type.', $label);
        }
    }
}
