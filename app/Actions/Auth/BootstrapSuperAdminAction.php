<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountStatus;
use App\Enums\AuditAction;
use App\Enums\RoleScope;
use App\Enums\StaffAccessStatus;
use App\Exceptions\DomainInvariantException;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Khởi tạo an toàn Quản trị viên cấp cao (Super Administrator) đầu tiên qua Artisan CLI.
 *
 * Flow:
 * BootstrapSuperAdminCommand (CLI tương tác ẩn mật khẩu)
 * -> BootstrapSuperAdminAction::execute()
 * -> Giao dịch `BEGIN IMMEDIATE` (SQLite) kiểm tra tính duy nhất + danh mục quyền chuẩn
 * -> Tạo mới User (`must_change_password = true`), StaffProfile, UserRole(`super_admin`) & AuditLog(`super_admin_bootstrapped`)
 * -> Bắt buộc đổi mật khẩu onboarding khi đăng nhập lần đầu trước khi truy cập `/quan-tri`.
 */
class BootstrapSuperAdminAction
{
    public function execute(
        string $name,
        string $email,
        string $plainPassword,
        string $plainPasswordConfirmation,
    ): User {
        $normalizedName = trim($name);
        $normalizedEmail = Str::lower(trim($email));

        $this->validateInput($normalizedName, $normalizedEmail, $plainPassword, $plainPasswordConfirmation);

        $connection = DB::connection();

        if ($connection->transactionLevel() === 0 && $connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA busy_timeout = 5000');
        }

        return $connection->transaction(function () use ($normalizedName, $normalizedEmail, $plainPassword): User {
            $this->ensureCanBootstrap($normalizedEmail);

            $superAdminRole = $this->resolveCanonicalSuperAdminRole();

            $user = new User;
            $user->forceFill([
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'password' => $plainPassword,
                'email_verified_at' => now(),
                'account_status' => AccountStatus::Active,
                'staff_access_status' => StaffAccessStatus::Active,
                'must_change_password' => true,
                'temp_password_issued_at' => now(),
                'temp_password_expires_at' => null,
                'temp_password_issued_by' => null,
                'password_changed_at' => null,
            ])->save();

            $staffProfile = new StaffProfile;
            $staffProfile->forceFill([
                'user_id' => $user->id,
                'staff_code' => $this->generateUniqueStaffCode(),
                'job_title' => 'Quản trị viên cấp cao',
                'hired_at' => now()->toDateString(),
                'activated_at' => now(),
            ])->save();

            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $superAdminRole->id,
                'role_scope' => RoleScope::Global->value,
                'assigned_by' => null,
                'assigned_at' => now(),
            ]);

            // Zero-Secret Rule: tuyệt đối không lưu mật khẩu hay thông tin nhạy cảm vào AuditLog.
            AuditLog::query()->create([
                'actor_id' => null,
                'target_user_id' => $user->id,
                'branch_id' => null,
                'action' => AuditAction::SuperAdminBootstrapped,
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'old_values' => null,
                'new_values' => [
                    'email' => $user->email,
                    'account_status' => AccountStatus::Active->value,
                    'staff_access_status' => StaffAccessStatus::Active->value,
                    'must_change_password' => true,
                    'role_code' => 'super_admin',
                    'role_scope' => RoleScope::Global->value,
                ],
                'reason' => 'Khởi tạo Quản trị viên cấp cao đầu tiên qua lệnh Artisan CLI.',
                'ip_address' => null,
                'user_agent' => 'artisan-cli',
                'occurred_at' => now(),
            ]);

            return $user->fresh() ?? $user;
        }, attempts: 5);
    }

    /**
     * Kiểm tra định dạng đầu vào mà không bao giờ đưa mật khẩu gốc vào ngoại lệ.
     *
     * @throws ValidationException
     */
    private function validateInput(
        string $name,
        string $email,
        string $plainPassword,
        string $plainPasswordConfirmation,
    ): void {
        Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $plainPassword,
                'password_confirmation' => $plainPasswordConfirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
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
                'name' => 'họ và tên',
                'email' => 'địa chỉ email',
                'password' => 'mật khẩu',
                'password_confirmation' => 'xác nhận mật khẩu',
            ],
        )->validate();
    }

    /**
     * Bảo đảm hệ thống chưa có Super Administrator và email không xung đột tài khoản hiện hữu.
     */
    private function ensureCanBootstrap(string $normalizedEmail): void
    {
        $hasExistingSuperAdmin = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.code', 'super_admin')
            ->where('roles.scope', RoleScope::Global->value)
            ->exists();

        if ($hasExistingSuperAdmin) {
            throw new DomainInvariantException(
                'Hệ thống đã có Quản trị viên cấp cao. Không thể chạy bootstrap lần thứ hai.',
                'super_admin_already_bootstrapped',
                422,
            );
        }

        if (User::query()->where('email', $normalizedEmail)->exists()) {
            throw new DomainInvariantException(
                'Địa chỉ email này đã tồn tại trên hệ thống. Không được phép ghi đè hoặc tự động nâng quyền tài khoản sẵn có.',
                'bootstrap_email_conflict',
                422,
            );
        }
    }

    /**
     * Xác minh vai trò `super_admin` và đầy đủ danh mục quyền chuẩn đã được seed trong CSDL.
     */
    private function resolveCanonicalSuperAdminRole(): Role
    {
        $role = Role::query()
            ->where('code', 'super_admin')
            ->where('scope', RoleScope::Global->value)
            ->first();

        if ($role === null) {
            throw new DomainInvariantException(
                'Chưa tìm thấy vai trò chuẩn `super_admin` trong cơ sở dữ liệu. Vui lòng chạy `RoleAndPermissionSeeder` trước khi bootstrap.',
                'super_admin_role_missing',
                422,
            );
        }

        $expectedPermissionCodes = collect(PermissionCatalog::permissions())
            ->pluck('code')
            ->all();

        $existingCount = Permission::query()
            ->whereIn('code', $expectedPermissionCodes)
            ->count();

        if ($existingCount !== count($expectedPermissionCodes)) {
            throw new DomainInvariantException(
                'Danh mục quyền trong cơ sở dữ liệu chưa đồng bộ đầy đủ 20 quyền chuẩn. Vui lòng chạy lại `RoleAndPermissionSeeder` trước khi bootstrap.',
                'permission_catalog_incomplete',
                422,
            );
        }

        return $role;
    }

    private function generateUniqueStaffCode(): string
    {
        $sequence = 1;

        while (true) {
            $candidate = sprintf('SA-%04d', $sequence);

            if (! StaffProfile::query()->where('staff_code', $candidate)->exists()) {
                return $candidate;
            }

            $sequence++;
        }
    }
}
