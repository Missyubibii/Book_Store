<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\StaffAccessStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hoàn tất bước đổi mật khẩu tạm thời bắt buộc cho nhân sự / Super Administrator onboarding.
 *
 * Flow:
 * POST /nhan-su/doi-mat-khau-bat-buoc (`staff.onboarding`)
 * -> ForceChangePasswordRequest -> MandatoryPasswordChangeController@update
 * -> CompleteMandatoryPasswordChangeAction::execute()
 * -> Giao dịch nguyên tử (`BEGIN IMMEDIATE` trên SQLite): cập nhật password mới, tắt `must_change_password`,
 *    ghi `password_changed_at` và ghi AuditLog (`forced_password_changed`, Zero-Secret).
 */
class CompleteMandatoryPasswordChangeAction
{
    public function execute(
        User $user,
        string $newPlainPassword,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0 && $connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA busy_timeout = 5000');
        }

        return $connection->transaction(function () use ($user, $newPlainPassword, $ipAddress, $userAgent): User {
            /** @var User|null $freshUser */
            $freshUser = User::query()->find($user->id);

            if ($freshUser === null || $freshUser->account_status !== AccountStatus::Active) {
                throw new DomainInvariantException(
                    __('auth.account_suspended'),
                    'account_not_active',
                    403,
                );
            }

            if (! in_array($freshUser->staff_access_status, [StaffAccessStatus::Pending, StaffAccessStatus::Active], true)) {
                throw new DomainInvariantException(
                    __('auth.staff_access_denied'),
                    'staff_not_eligible_for_onboarding',
                    403,
                );
            }

            if (! $freshUser->must_change_password) {
                throw new DomainInvariantException(
                    __('auth.staff_onboarding_not_required'),
                    'onboarding_not_required',
                    403,
                );
            }

            if ($freshUser->isTemporaryPasswordExpired()) {
                throw new DomainInvariantException(
                    __('auth.temp_password_expired'),
                    'temp_password_expired',
                    422,
                );
            }

            $previousStaffStatus = $freshUser->staff_access_status->value;

            $freshUser->forceFill([
                'password' => $newPlainPassword,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            // Zero-Secret Rule: tuyệt đối không lưu mật khẩu cũ, mới hay hash vào AuditLog.
            AuditLog::query()->create([
                'actor_id' => $freshUser->id,
                'target_user_id' => $freshUser->id,
                'branch_id' => null,
                'action' => AuditAction::ForcedPasswordChanged,
                'subject_type' => User::class,
                'subject_id' => $freshUser->id,
                'old_values' => [
                    'must_change_password' => true,
                    'staff_access_status' => $previousStaffStatus,
                ],
                'new_values' => [
                    'must_change_password' => false,
                    'staff_access_status' => $freshUser->staff_access_status->value,
                    'password_changed_at' => $freshUser->password_changed_at?->toIso8601String(),
                ],
                'reason' => 'Hoàn tất đổi mật khẩu tạm thời bắt buộc khi onboarding.',
                'ip_address' => $ipAddress ? mb_substr($ipAddress, 0, 45) : null,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 1000) : null,
                'occurred_at' => now(),
            ]);

            return $freshUser->fresh() ?? $freshUser;
        }, attempts: 5);
    }
}
