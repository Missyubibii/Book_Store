<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\LoginEventType;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\LoginEventLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Khôi phục an toàn mật khẩu cho tài khoản Quản trị viên cấp cao (Super Administrator) duy nhất hiện có.
 *
 * Flow:
 * RecoverSuperAdminPasswordCommand (xác minh quyền sở hữu cục bộ + nhập mật khẩu ẩn + xác nhận tương tác)
 * -> RecoverSuperAdminPasswordAction::execute()
 * -> Giao dịch nguyên tử (`BEGIN IMMEDIATE` trên SQLite): kiểm tra duy nhất 1 Super Admin & trạng thái hoạt động
 * -> Cập nhật hash mật khẩu mới, xoay `remember_token`, xóa toàn bộ phiên đăng nhập trong `sessions`
 * -> Ghi AuditLog (`forced_password_changed`, Zero-Secret) + LoginEvent (`session_revoked`).
 */
class RecoverSuperAdminPasswordAction
{
    /**
     * @return array{user: User, revoked_sessions: int}
     *
     * @throws AuthorizationException|DomainInvariantException|ValidationException
     */
    public function execute(
        string $email,
        string $newPlainPassword,
        string $newPlainPasswordConfirmation,
        bool $ownershipVerified = false,
    ): array {
        if (! $ownershipVerified) {
            throw new AuthorizationException('Chưa hoàn tất bước xác minh quyền sở hữu hệ thống cục bộ.');
        }

        $normalizedEmail = Str::lower(trim($email));
        $this->validateInput($normalizedEmail, $newPlainPassword, $newPlainPasswordConfirmation);

        $connection = DB::connection();

        if ($connection->transactionLevel() === 0 && $connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA busy_timeout = 5000');
        }

        $result = $connection->transaction(function () use ($normalizedEmail, $newPlainPassword): array {
            $superAdminUserIds = DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.role_scope', RoleScope::Global->value)
                ->where('roles.code', 'super_admin')
                ->where('roles.scope', RoleScope::Global->value)
                ->distinct()
                ->pluck('user_roles.user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            if ($superAdminUserIds === []) {
                throw new DomainInvariantException(
                    'Không tìm thấy tài khoản Quản trị viên cấp cao nào trong hệ thống.',
                    'super_admin_missing',
                    422,
                );
            }

            if (count($superAdminUserIds) > 1) {
                throw new DomainInvariantException(
                    'Phát hiện nhiều hơn một tài khoản Quản trị viên cấp cao trong hệ thống. Từ chối khôi phục tự động để bảo đảm an toàn.',
                    'duplicate_super_admin_detected',
                    422,
                );
            }

            /** @var User|null $targetUser */
            $targetUser = User::query()->where('email', $normalizedEmail)->first();

            if ($targetUser === null) {
                throw new DomainInvariantException(
                    'Không tìm thấy tài khoản với địa chỉ email đã chỉ định.',
                    'target_user_missing',
                    422,
                );
            }

            if ((int) $targetUser->id !== $superAdminUserIds[0]) {
                throw new DomainInvariantException(
                    'Tài khoản được chỉ định không phải là Quản trị viên cấp cao hợp lệ.',
                    'target_not_super_admin',
                    403,
                );
            }

            if (
                $targetUser->account_status !== AccountStatus::Active
                || $targetUser->staff_access_status !== StaffAccessStatus::Active
            ) {
                throw new DomainInvariantException(
                    'Tài khoản Quản trị viên cấp cao hiện không ở trạng thái đang hoạt động hợp lệ để khôi phục mật khẩu.',
                    'super_admin_not_active',
                    403,
                );
            }

            $now = now();
            $previousPasswordChangedAt = $targetUser->password_changed_at;
            $previousMustChangePassword = (bool) $targetUser->must_change_password;

            $targetUser->forceFill([
                'password' => $newPlainPassword,
                'must_change_password' => false,
                'temp_password_expires_at' => null,
                'password_changed_at' => $now,
                'remember_token' => Str::random(60),
            ])->save();

            $sessionTable = (string) config('session.table', 'sessions');
            $revokedSessionsCount = Schema::hasTable($sessionTable)
                ? (int) DB::table($sessionTable)->where('user_id', $targetUser->id)->delete()
                : 0;

            // Zero-Secret Rule: tuyệt đối không lưu mật khẩu mới, hash hay mã xác minh vào AuditLog.
            AuditLog::query()->create([
                'actor_id' => null,
                'target_user_id' => $targetUser->id,
                'branch_id' => null,
                'action' => AuditAction::ForcedPasswordChanged,
                'subject_type' => User::class,
                'subject_id' => $targetUser->id,
                'old_values' => [
                    'must_change_password' => $previousMustChangePassword,
                    'password_changed_at' => $previousPasswordChangedAt?->toIso8601String(),
                ],
                'new_values' => [
                    'recovery_channel' => 'artisan_cli',
                    'ownership_verified' => true,
                    'sessions_revoked_count' => $revokedSessionsCount,
                    'password_changed_at' => $now->toIso8601String(),
                ],
                'reason' => 'Khôi phục mật khẩu Quản trị viên cấp cao qua lệnh Artisan CLI cục bộ sau khi xác minh quyền sở hữu hệ thống.',
                'ip_address' => null,
                'user_agent' => 'artisan-cli',
                'occurred_at' => $now,
            ]);

            return [
                'user' => $targetUser->fresh() ?? $targetUser,
                'revoked_sessions' => $revokedSessionsCount,
            ];
        }, attempts: 5);

        LoginEventLogger::log(
            eventType: LoginEventType::SessionRevoked,
            userId: $result['user']->id,
            email: $result['user']->email,
            ipAddress: null,
            userAgent: 'artisan-cli',
        );

        return $result;
    }

    /**
     * Kiểm tra định dạng email và chính sách mật khẩu mạnh chuẩn của hệ thống.
     *
     * @throws ValidationException
     */
    private function validateInput(
        string $normalizedEmail,
        string $plainPassword,
        string $plainPasswordConfirmation,
    ): void {
        Validator::make(
            [
                'email' => $normalizedEmail,
                'password' => $plainPassword,
                'password_confirmation' => $plainPasswordConfirmation,
            ],
            [
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
                'password' => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min(8)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ],
            [],
            [
                'email' => 'địa chỉ email',
                'password' => 'mật khẩu mới',
                'password_confirmation' => 'xác nhận mật khẩu mới',
            ],
        )->validate();
    }
}
