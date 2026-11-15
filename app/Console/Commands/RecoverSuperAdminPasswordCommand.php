<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\RecoverSuperAdminPasswordAction;
use App\Exceptions\DomainInvariantException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lệnh CLI cục bộ để khôi phục an toàn mật khẩu Quản trị viên cấp cao hiện có.
 *
 * Bảo mật:
 * - Không bao giờ coi `APP_ENV=local` là đủ điều kiện ủy quyền.
 * - Bắt buộc xác minh quyền sở hữu hệ thống qua tệp mã một lần `storage/framework/super_admin_recovery.token`
 *   cùng dấu nhắc nhập ẩn và xóa ngay tệp mã sau khi kiểm tra.
 * - Tuyệt đối không nhận mật khẩu hoặc mã xác minh qua tham số dòng lệnh, biến môi trường hay log.
 */
class RecoverSuperAdminPasswordCommand extends Command
{
    public const TOKEN_RELATIVE_PATH = 'framework/super_admin_recovery.token';

    public const MIN_TOKEN_LENGTH = 16;

    protected $signature = 'bookstore:recover-super-admin';

    protected $description = 'Khôi phục an toàn mật khẩu Quản trị viên cấp cao (Super Administrator) trong môi trường cục bộ.';

    public function handle(RecoverSuperAdminPasswordAction $action): int
    {
        if (! $this->laravel->runningInConsole()) {
            $this->error('Lệnh này chỉ được phép thực thi trong môi trường dòng lệnh (CLI).');

            return self::FAILURE;
        }

        if (! ($this->laravel->isLocal() || $this->laravel->runningUnitTests())) {
            $this->error('Từ chối thực thi: lệnh khôi phục mật khẩu Super Administrator chỉ được phép chạy trong môi trường cục bộ.');

            return self::FAILURE;
        }

        $tokenPath = storage_path(self::TOKEN_RELATIVE_PATH);

        if (! is_file($tokenPath) || is_link($tokenPath)) {
            $this->error('Chưa tìm thấy tệp xác minh quyền sở hữu cục bộ tại storage/framework/super_admin_recovery.token.');
            $this->line('Vui lòng tạo mã xác minh một lần (tối thiểu 16 ký tự) vào tệp trên trước khi chạy lại lệnh.');

            return self::FAILURE;
        }

        $expectedToken = trim((string) @file_get_contents($tokenPath));
        $enteredToken = '';

        try {
            if (strlen($expectedToken) < self::MIN_TOKEN_LENGTH) {
                $this->error('Mã xác minh trong tệp cục bộ không hợp lệ (yêu cầu tối thiểu 16 ký tự).');

                return self::FAILURE;
            }

            $enteredToken = trim((string) $this->secret('Nhập mã xác minh quyền sở hữu cục bộ (từ tệp storage/framework/super_admin_recovery.token)'));
        } finally {
            if (is_file($tokenPath) && ! is_link($tokenPath)) {
                @unlink($tokenPath);
            }
        }

        if ($enteredToken === '' || ! hash_equals($expectedToken, $enteredToken)) {
            $this->error('Xác minh quyền sở hữu cục bộ thất bại: mã xác minh không khớp.');

            return self::FAILURE;
        }

        $this->line('=== Khôi phục Mật khẩu Quản trị viên Cấp cao (Book_Store) ===');

        $rawEmail = (string) $this->ask('Nhập địa chỉ email tài khoản Quản trị viên cấp cao cần khôi phục', 'superadmin@gmail.com');
        $normalizedEmail = Str::lower(trim($rawEmail));

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('Địa chỉ email không hợp lệ.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Nhập mật khẩu mới (tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, chữ số và ký tự đặc biệt)');
        $passwordConfirmation = (string) $this->secret('Xác nhận lại mật khẩu mới');

        if ($password === '' || $password !== $passwordConfirmation) {
            $this->error('Mật khẩu mới và xác nhận mật khẩu không khớp hoặc đang để trống.');

            return self::FAILURE;
        }

        $confirmed = $this->confirm(
            sprintf("Xác nhận đặt lại mật khẩu và thu hồi toàn bộ phiên đăng nhập hiện tại của Quản trị viên cấp cao '%s'?", $normalizedEmail),
            false,
        );

        if (! $confirmed) {
            $this->warn('Đã hủy thao tác khôi phục mật khẩu Quản trị viên cấp cao.');

            return self::FAILURE;
        }

        try {
            $result = $action->execute(
                email: $normalizedEmail,
                newPlainPassword: $password,
                newPlainPasswordConfirmation: $passwordConfirmation,
                ownershipVerified: true,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (DomainInvariantException|AuthorizationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Không thể khôi phục mật khẩu Quản trị viên cấp cao do lỗi hệ thống. Mọi thay đổi đã được hoàn tác an toàn.');

            return self::FAILURE;
        }

        $user = $result['user'];
        $revokedSessions = $result['revoked_sessions'];

        $this->info(sprintf('Đã khôi phục mật khẩu thành công cho Quản trị viên cấp cao: %s.', $user->email));
        $this->line(sprintf('Đã thu hồi %d phiên đăng nhập đang hoạt động, xoay remember_token và ghi nhật ký kiểm toán an toàn.', $revokedSessions));

        return self::SUCCESS;
    }
}
