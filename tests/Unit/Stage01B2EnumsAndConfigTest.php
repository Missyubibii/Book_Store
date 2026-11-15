<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AuditAction;
use App\Enums\BranchAssignmentStatus;
use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Enums\RoleScope;
use App\Enums\StaffApprovalStatus;
use Tests\TestCase;

class Stage01B2EnumsAndConfigTest extends TestCase
{
    public function test_branch_type_enum_matches_canonical_contract_and_labels(): void
    {
        $this->assertSame(
            ['central_warehouse', 'retail_branch'],
            BranchType::values()
        );

        $this->assertTrue(BranchType::CentralWarehouse->isCentralWarehouse());
        $this->assertFalse(BranchType::CentralWarehouse->isRetailBranch());

        $this->assertFalse(BranchType::RetailBranch->isCentralWarehouse());
        $this->assertTrue(BranchType::RetailBranch->isRetailBranch());

        $this->assertSame('"central_warehouse"', json_encode(BranchType::CentralWarehouse));

        foreach (BranchType::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertNotSame($case->value, $label);
            $this->assertStringNotContainsString('enums.branch_type.', $label);
            $this->assertSame(__('enums.branch_type.'.$case->value), $label);
        }
    }

    public function test_branch_status_enum_matches_canonical_contract_and_transitions(): void
    {
        $this->assertSame(['active', 'inactive'], BranchStatus::values());

        $this->assertTrue(BranchStatus::Active->isActive());
        $this->assertFalse(BranchStatus::Inactive->isActive());

        $this->assertSame([BranchStatus::Inactive], BranchStatus::Active->allowedTransitions());
        $this->assertSame([BranchStatus::Active], BranchStatus::Inactive->allowedTransitions());

        $this->assertTrue(BranchStatus::Active->canTransitionTo(BranchStatus::Inactive));
        $this->assertFalse(BranchStatus::Active->canTransitionTo(BranchStatus::Active));
        $this->assertTrue(BranchStatus::Inactive->canTransitionTo(BranchStatus::Active));
        $this->assertFalse(BranchStatus::Inactive->canTransitionTo(BranchStatus::Inactive));

        foreach (BranchStatus::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertNotSame($case->value, $label);
            $this->assertStringNotContainsString('enums.branch_status.', $label);
            $this->assertSame(__('enums.branch_status.'.$case->value), $label);
        }
    }

    public function test_role_scope_enum_matches_canonical_contract_and_labels(): void
    {
        $this->assertSame(['global', 'branch'], RoleScope::values());

        $this->assertTrue(RoleScope::Global->isGlobal());
        $this->assertFalse(RoleScope::Global->isBranch());

        $this->assertFalse(RoleScope::Branch->isGlobal());
        $this->assertTrue(RoleScope::Branch->isBranch());

        foreach (RoleScope::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertNotSame($case->value, $label);
            $this->assertStringNotContainsString('enums.role_scope.', $label);
            $this->assertSame(__('enums.role_scope.'.$case->value), $label);
        }
    }

    public function test_branch_assignment_status_enum_matches_canonical_contract_and_transitions(): void
    {
        $this->assertSame(['active', 'inactive'], BranchAssignmentStatus::values());

        $this->assertTrue(BranchAssignmentStatus::Active->isActive());
        $this->assertFalse(BranchAssignmentStatus::Inactive->isActive());

        $this->assertSame([BranchAssignmentStatus::Inactive], BranchAssignmentStatus::Active->allowedTransitions());
        $this->assertSame([BranchAssignmentStatus::Active], BranchAssignmentStatus::Inactive->allowedTransitions());

        $this->assertTrue(BranchAssignmentStatus::Active->canTransitionTo(BranchAssignmentStatus::Inactive));
        $this->assertFalse(BranchAssignmentStatus::Active->canTransitionTo(BranchAssignmentStatus::Active));
        $this->assertTrue(BranchAssignmentStatus::Inactive->canTransitionTo(BranchAssignmentStatus::Active));
        $this->assertFalse(BranchAssignmentStatus::Inactive->canTransitionTo(BranchAssignmentStatus::Inactive));

        foreach (BranchAssignmentStatus::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertNotSame($case->value, $label);
            $this->assertStringNotContainsString('enums.branch_assignment_status.', $label);
            $this->assertSame(__('enums.branch_assignment_status.'.$case->value), $label);
        }
    }

    public function test_staff_approval_status_enum_matches_canonical_contract_and_transitions(): void
    {
        $this->assertSame(
            ['pending', 'approved', 'rejected', 'cancelled'],
            StaffApprovalStatus::values()
        );

        $this->assertTrue(StaffApprovalStatus::Pending->isPending());
        $this->assertFalse(StaffApprovalStatus::Pending->isTerminal());

        $terminalStatuses = [
            StaffApprovalStatus::Approved,
            StaffApprovalStatus::Rejected,
            StaffApprovalStatus::Cancelled,
        ];

        $this->assertSame($terminalStatuses, StaffApprovalStatus::Pending->allowedTransitions());

        foreach ($terminalStatuses as $terminalStatus) {
            $this->assertFalse($terminalStatus->isPending());
            $this->assertTrue($terminalStatus->isTerminal());
            $this->assertSame([], $terminalStatus->allowedTransitions());

            $this->assertTrue(StaffApprovalStatus::Pending->canTransitionTo($terminalStatus));
            foreach (StaffApprovalStatus::cases() as $target) {
                $this->assertFalse($terminalStatus->canTransitionTo($target));
            }
        }

        $this->assertFalse(StaffApprovalStatus::Pending->canTransitionTo(StaffApprovalStatus::Pending));

        foreach (StaffApprovalStatus::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertNotSame($case->value, $label);
            $this->assertStringNotContainsString('enums.staff_approval_status.', $label);
            $this->assertSame(__('enums.staff_approval_status.'.$case->value), $label);
        }
    }

    public function test_audit_action_enum_matches_canonical_contract_and_labels(): void
    {
        $expectedValues = [
            'staff_created',
            'staff_linked',
            'temp_password_issued',
            'forced_password_changed',
            'staff_activation_proposed',
            'staff_activation_approved',
            'staff_activation_rejected',
            'staff_suspended',
            'staff_reactivated',
            'role_assigned',
            'role_revoked',
            'branch_assigned',
            'branch_removed',
            'account_suspended',
            'account_reactivated',
            'super_admin_bootstrapped',
            'branch_created',
            'branch_updated',
        ];

        $this->assertSame($expectedValues, AuditAction::values());
        $this->assertCount(18, AuditAction::cases());

        foreach (AuditAction::cases() as $case) {
            $label = $case->label();
            $this->assertNotSame('', $label);
            $this->assertNotSame($case->value, $label);
            $this->assertStringNotContainsString('enums.audit_action.', $label);
            $this->assertSame(__('enums.audit_action.'.$case->value), $label);
        }
    }

    public function test_auth_config_defines_24_hour_staff_temporary_password_ttl(): void
    {
        $this->assertSame(24, config('auth.staff_temporary_password_ttl_hours'));
    }
}
