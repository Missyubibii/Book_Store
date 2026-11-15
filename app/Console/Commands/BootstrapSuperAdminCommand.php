<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\BootstrapSuperAdminAction;
use App\Enums\RoleScope;
use App\Exceptions\DomainInvariantException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lệnh CLI duy nhất để khởi tạo Quản trị viên cấp cao đầu tiên (Correction D).
 *
 * Bảo mật:
 * - Tuyệt đối không nhận mật khẩu qua tham số hay tùy chọn dòng lệnh.
 * - Chỉ nhập mật khẩu qua dấu nhắc ẩn `secret()` và xác nhận tường minh trước khi ghi CSDL.
 * - Không bao giờ hiển thị, ghi log hoặc trả về mật khẩu trong thông báo lỗi.
 */
class BootstrapSuperAdminCommand extends Command
{
    protected $signature = 'bookstore:bootstrap-super-admin';

    protected $description = 'Khởi tạo an toàn Quản trị viên cấp cao (Super Administrator) đầu tiên qua CLI tương tác.';

    public function handle(BootstrapSuperAdminAction $action): int
    {
        if (! $this->laravel->runningInConsole()) {
            $this->error('Lệnh này chỉ được phép thực thi trong môi trường dòng lệnh (CLI).');

            return self::FAILURE;
        }

        if ($this->hasExistingSuperAdmin()) {
            $this->error('Hệ thống đã tồn tại Quản trị viên cấp cao. Từ chối khởi tạo trùng lặp.');

            return self::FAILURE;
        }

        if (! $this->isRbacCatalogSeeded()) {
            $this->error('Danh mục vai trò hoặc quyền RBAC chưa đầy đủ 20 quyền chuẩn. Vui lòng đồng bộ `RoleAndPermissionSeeder` trước.');

            return self::FAILURE;
        }

        $this->line('=== Khởi tạo Quản trị viên cấp cao đầu tiên (Book_Store) ===');

        $name = (string) $this->ask('Họ và tên Quản trị viên cấp cao', 'Quản trị viên cấp cao');
        $rawEmail = (string) $this->ask('Địa chỉ email Quản trị viên cấp cao');
        $normalizedEmail = Str::lower(trim($rawEmail));

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('Địa chỉ email không hợp lệ.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $normalizedEmail)->exists()) {
            $this->error('Địa chỉ email này đã tồn tại trên hệ thống. Không thể tạo trùng hoặc nâng quyền tài khoản khách hàng sẵn có.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Nhập mật khẩu khởi tạo (tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt)');
        $passwordConfirmation = (string) $this->secret('Xác nhận lại mật khẩu khởi tạo');

        if ($password === '' || $password !== $passwordConfirmation) {
            $this->error('Mật khẩu và xác nhận mật khẩu không khớp hoặc đang để trống.');

            return self::FAILURE;
        }

        $confirmed = $this->confirm(
            sprintf("Xác nhận khởi tạo Quản trị viên cấp cao với email '%s'?", $normalizedEmail),
            false,
        );

        if (! $confirmed) {
            $this->warn('Đã hủy thao tác khởi tạo Quản trị viên cấp cao.');

            return self::FAILURE;
        }

        try {
            $user = $action->execute($name, $normalizedEmail, $password, $passwordConfirmation);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (DomainInvariantException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Không thể khởi tạo Quản trị viên cấp cao do lỗi hệ thống nội bộ. Mọi thay đổi đã được hoàn tác.');

            return self::FAILURE;
        } finally {
            $password = '';
            $passwordConfirmation = '';
        }

        $this->info(sprintf(
            'Đã khởi tạo thành công Quản trị viên cấp cao #%d (%s). Tài khoản cần đổi mật khẩu bắt buộc ở lần đăng nhập đầu tiên.',
            $user->id,
            $user->email,
        ));

        return self::SUCCESS;
    }

    private function hasExistingSuperAdmin(): bool
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.role_scope', RoleScope::Global->value)
            ->where('roles.code', 'super_admin')
            ->where('roles.scope', RoleScope::Global->value)
            ->exists();
    }

    private function isRbacCatalogSeeded(): bool
    {
        $hasRole = Role::query()
            ->where('code', 'super_admin')
            ->where('scope', RoleScope::Global->value)
            ->exists();

        if (! $hasRole) {
            return false;
        }

        $expectedCodes = collect(PermissionCatalog::permissions())->pluck('code')->all();

        return Permission::query()->whereIn('code', $expectedCodes)->count() === count($expectedCodes);
    }
}
