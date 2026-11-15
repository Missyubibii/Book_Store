<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Configuration\GoogleServicesSetupManager;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Trình thuật sĩ CLI tương tác tiếng Việt giúp chủ sở hữu hiện tại hoặc chủ sở hữu nhận chuyển giao
 * tự cấu hình dịch vụ Gmail SMTP (xác thực email thật) và Google OAuth mà không cần sửa mã nguồn hay `.env`.
 */
class SetupGoogleServicesCommand extends Command
{
    protected $signature = 'bookstore:setup-google
                            {--env-file= : Đường dẫn tệp môi trường tùy chọn (chỉ phục vụ kiểm thử cô lập)}';

    protected $description = 'Thiết lập tương tác Gmail SMTP (xác thực email thật) và Google OAuth dành cho chủ sở hữu hệ thống Book_Store.';

    public function handle(GoogleServicesSetupManager $manager): int
    {
        if (! $this->laravel->runningInConsole()) {
            $this->error('Lệnh này chỉ được phép thực thi trong môi trường dòng lệnh (CLI).');

            return self::FAILURE;
        }

        $envPathOption = $this->option('env-file');
        $envPath = is_string($envPathOption) && trim($envPathOption) !== ''
            ? trim($envPathOption)
            : null;

        $this->line('======================================================================');
        $this->info('  TRÌNH THIẾT LẬP CHUYỂN GIAO DỊCH VỤ GOOGLE & EMAIL — BOOK_STORE');
        $this->line('======================================================================');
        $this->line('Công cụ này giúp chủ sở hữu cấu hình Gmail gửi thư xác thực và Google OAuth');
        $this->line('mà không cần chỉnh sửa thủ công mã nguồn hay tệp .env.');
        $this->newLine();

        $mode = (string) $this->choice(
            'Vui lòng chọn tác vụ bạn muốn thực hiện',
            [
                '1' => '1. Cấu hình gửi email qua Gmail (SMTP với Mật khẩu ứng dụng 16 ký tự)',
                '2' => '2. Cấu hình đăng nhập Google OAuth (Client ID & Client Secret)',
                '3' => '3. Cấu hình đồng thời cả hai dịch vụ (Gmail SMTP & Google OAuth)',
                '4' => '4. Kiểm tra chẩn đoán kết nối và trạng thái cấu hình hiện tại',
                '5' => '5. Cấu hình lại an toàn (Sao lưu tệp .env.backup trước khi thay đổi)',
                '6' => '6. Tắt dịch vụ (Chuyển gửi thư về chế độ cục bộ hoặc tắt Google OAuth)',
            ],
            '1',
        );

        $modeKey = substr(trim($mode), 0, 1);

        try {
            return match ($modeKey) {
                '1' => $this->runGmailSetup($manager, $envPath, true),
                '2' => $this->runOAuthSetup($manager, $envPath, true),
                '3' => $this->runBothSetup($manager, $envPath),
                '4' => $this->runDiagnostics($manager, $envPath),
                '5' => $this->runSafeReconfiguration($manager, $envPath),
                '6' => $this->runDisableService($manager, $envPath),
                default => self::FAILURE,
            };
        } catch (InvalidArgumentException $exception) {
            $this->error('Lỗi dữ liệu đầu vào: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Không thể hoàn tất thiết lập dịch vụ Google do lỗi hệ thống. Vui lòng kiểm tra quyền ghi tệp .env.');

            return self::FAILURE;
        }
    }

    private function runGmailSetup(
        GoogleServicesSetupManager $manager,
        ?string $envPath,
        bool $clearCacheAfter,
    ): int {
        $currentStatus = $manager->diagnose($envPath);
        if ($currentStatus['smtp_configured']) {
            $this->warn("Cảnh báo: Gmail SMTP hiện đang hoạt động với tài khoản '{$currentStatus['mail_username']}'.");
            $this->line('Nếu bạn hủy giữa chừng hoặc nhập thông tin không hợp lệ, cấu hình đang hoạt động sẽ được giữ nguyên.');
        }

        $gmailInput = $this->promptGmailInputs($manager, $envPath);

        if (! $this->confirm("Xác nhận lưu cấu hình Gmail SMTP cho địa chỉ '{$gmailInput['email']}'?", true)) {
            $this->warn('Đã hủy thiết lập Gmail SMTP. Không có thay đổi nào được ghi.');

            return self::FAILURE;
        }

        $manager->configureGmailSmtp($gmailInput, $envPath, true);

        if ($clearCacheAfter && $envPath === null) {
            $this->clearConfigCacheSafely();
        }

        $this->info("Đã lưu cấu hình Gmail SMTP thành công cho '{$gmailInput['email']}' (cập nhật nguyên tử và đã dọn bản sao lưu tạm an toàn).");

        return self::SUCCESS;
    }

    private function runOAuthSetup(
        GoogleServicesSetupManager $manager,
        ?string $envPath,
        bool $clearCacheAfter,
    ): int {
        $oauthInput = $this->promptOAuthInputs($manager, $envPath);

        if (! $manager->isGoogleOAuthSignInImplemented()) {
            $this->warn('Lưu ý: Luồng đăng nhập Google OAuth (route, callback, state validation, xác minh danh tính và liên kết tài khoản) chưa được triển khai trong mã nguồn.');
            $this->line('Hệ thống chỉ lưu thông tin xác thực để chuẩn bị sẵn và GIỮ NGUYÊN BOOKSTORE_GOOGLE_OAUTH_ENABLED=false.');
        }

        if (! $this->confirm('Xác nhận lưu thông tin xác thực Google OAuth vào hệ thống?', true)) {
            $this->warn('Đã hủy thiết lập Google OAuth. Không có thay đổi nào được ghi.');

            return self::FAILURE;
        }

        $manager->configureGoogleOAuth($oauthInput, $envPath, true);

        if ($clearCacheAfter && $envPath === null) {
            $this->clearConfigCacheSafely();
        }

        $this->info('Đã lưu thông tin xác thực Google OAuth thành công (chưa kích hoạt đăng nhập Google cho đến khi luồng OAuth được triển khai).');

        return self::SUCCESS;
    }

    private function runBothSetup(GoogleServicesSetupManager $manager, ?string $envPath): int
    {
        $gmailInput = $this->promptGmailInputs($manager, $envPath);
        $oauthInput = $this->promptOAuthInputs($manager, $envPath);

        if (! $manager->isGoogleOAuthSignInImplemented()) {
            $this->warn('Lưu ý: Đăng nhập Google OAuth chưa triển khai trong mã nguồn nên hệ thống vẫn giữ BOOKSTORE_GOOGLE_OAUTH_ENABLED=false.');
        }

        if (! $this->confirm("Xác nhận lưu đồng thời cấu hình Gmail SMTP ('{$gmailInput['email']}') và thông tin xác thực Google OAuth?", true)) {
            $this->warn('Đã hủy thiết lập. Không có thay đổi nào được ghi vào tệp .env.');

            return self::FAILURE;
        }

        $manager->configureBoth($gmailInput, $oauthInput, $envPath, true);

        if ($envPath === null) {
            $this->clearConfigCacheSafely();
        }

        $this->info('Đã hoàn tất lưu đồng thời Gmail SMTP và thông tin xác thực Google OAuth (nguyên tử, giữ nguyên APP_KEY).');

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     email: string,
     *     app_password: string,
     *     from_name: string,
     *     port: int,
     *     disable_demo_verification: bool
     * }
     */
    private function promptGmailInputs(GoogleServicesSetupManager $manager, ?string $envPath): array
    {
        $this->newLine();
        $this->info('--- Hướng dẫn tạo Mật khẩu ứng dụng Gmail (Google App Password) ---');
        $this->line('1. Truy cập https://myaccount.google.com/security bằng tài khoản Gmail của chủ sở hữu.');
        $this->line('2. Bật "Xác minh 2 bước" (2-Step Verification).');
        $this->line('3. Tìm mục "Mật khẩu ứng dụng" (App passwords) và tạo mã 16 ký tự cho ứng dụng "Book_Store".');
        $this->line('Lưu ý bảo mật: Tuyệt đối KHÔNG nhập mật khẩu đăng nhập Gmail thông thường.');
        $this->newLine();

        $currentPairs = $manager->readEnvironmentPairs($envPath);
        $defaultEmail = $currentPairs['MAIL_USERNAME'] ?? 'owner@gmail.com';
        $defaultFromName = $currentPairs['MAIL_FROM_NAME'] ?? 'Book_Store';

        $email = (string) $this->ask('Nhập địa chỉ Gmail gửi thư hệ thống', $defaultEmail);
        $normalizedEmail = $manager->normalizeSenderEmail($email);

        $rawAppPassword = (string) $this->secret('Nhập Mật khẩu ứng dụng Google (16 ký tự, có thể kèm khoảng trắng)');
        $normalizedAppPassword = $manager->normalizeAppPassword($rawAppPassword);

        $fromName = (string) $this->ask('Nhập tên hiển thị người gửi (MAIL_FROM_NAME)', $defaultFromName);
        $portChoice = (string) $this->choice(
            'Chọn cổng kết nối Gmail SMTP',
            [
                '587' => 'Cổng 587 (STARTTLS — Khuyên dùng)',
                '465' => 'Cổng 465 (Implicit SSL/TLS)',
            ],
            '587',
        );
        $port = str_starts_with($portChoice, '465') ? 465 : 587;

        $disableDemo = $this->confirm(
            'Bạn có muốn tắt chế độ xác thực email Demo cục bộ (BOOKSTORE_DEMO_EMAIL_VERIFICATION=false) để sử dụng hoàn toàn email xác thực thật?',
            true,
        );

        return [
            'email' => $normalizedEmail,
            'app_password' => $normalizedAppPassword,
            'from_name' => $fromName,
            'port' => $port,
            'disable_demo_verification' => $disableDemo,
        ];
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}
     */
    private function promptOAuthInputs(GoogleServicesSetupManager $manager, ?string $envPath): array
    {
        $this->newLine();
        $this->info('--- Hướng dẫn khởi tạo Google OAuth 2.0 trên Google Cloud Console ---');
        $this->line('1. Truy cập https://console.cloud.google.com/apis/credentials');
        $this->line('2. Cấu hình "OAuth consent screen" (External/Internal) với tên ứng dụng Book_Store.');
        $this->line('3. Nhấn "Create Credentials" -> "OAuth client ID" -> chọn "Web application".');
        $this->line('4. Thêm URI chuyển hướng hợp lệ (Authorized redirect URI) bên dưới vào Google Cloud.');
        $this->newLine();

        $currentPairs = $manager->readEnvironmentPairs($envPath);
        $appUrl = rtrim($currentPairs['APP_URL'] ?? (string) config('app.url', 'http://127.0.0.1:8000'), '/');
        $defaultRedirectUri = $currentPairs['GOOGLE_REDIRECT_URI'] ?? ($appUrl.'/xac-thuc/google/callback');

        $rawClientId = (string) $this->ask('Nhập Google OAuth Client ID (kết thúc bằng .apps.googleusercontent.com)');
        $clientId = $manager->normalizeClientId($rawClientId);

        $rawClientSecret = (string) $this->secret('Nhập Google OAuth Client Secret (nhập ẩn)');
        $clientSecret = $manager->normalizeClientSecret($rawClientSecret);

        $rawRedirectUri = (string) $this->ask('Nhập Authorized Redirect URI', $defaultRedirectUri);
        $redirectUri = $manager->normalizeRedirectUri($rawRedirectUri);

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ];
    }

    private function runDiagnostics(GoogleServicesSetupManager $manager, ?string $envPath): int
    {
        $status = $manager->diagnose($envPath);
        $smtpTested = null;

        if ($status['smtp_configured'] && $this->confirm('Bạn có muốn kiểm tra bắt tay kết nối thực tế tới máy chủ Gmail SMTP ngay bây giờ (không gửi email)?', false)) {
            $probe = $manager->testSmtpConnection($envPath);
            $smtpTested = $probe['success'];
            if ($probe['success']) {
                $this->info($probe['message']);
            } else {
                $this->error($probe['message']);
            }
            $status = $manager->diagnose($envPath, $smtpTested);
        }

        $smtpTestedLabel = match ($status['smtp_connection_tested']) {
            true => 'Đã kiểm tra thành công (Kết nối SMTP hợp lệ)',
            false => 'Kiểm tra thất bại (Cần xem lại thông tin hoặc mạng)',
            default => 'Chưa kiểm tra kết nối thực tế',
        };

        $this->newLine();
        $this->info('=== KẾT QUẢ CHẨN ĐOÁN DỊCH VỤ GOOGLE & EMAIL (BOOK_STORE) ===');
        $this->table(
            ['Hạng mục kiểm tra', 'Giá trị / Trạng thái hiện tại'],
            [
                ['Kênh gửi thư (MAIL_MAILER)', $status['mail_mailer']],
                ['Máy chủ SMTP (MAIL_HOST : MAIL_PORT)', $status['mail_host'].':'.$status['mail_port']],
                ['Tài khoản gửi thư (MAIL_USERNAME)', $status['mail_username']],
                ['Địa chỉ người gửi (MAIL_FROM_ADDRESS)', $status['mail_from_address']],
                ['Mật khẩu ứng dụng 16 ký tự', $status['mail_password_configured'] ? 'Hợp lệ (Đã ẩn bảo mật)' : 'Chưa cấu hình hoặc sai định dạng'],
                ['1. Gmail SMTP đã cấu hình', $status['smtp_configured'] ? 'Đã cấu hình đầy đủ' : 'Chưa cấu hình'],
                ['2. Kiểm tra kết nối SMTP', $smtpTestedLabel],
                ['Xác thực email Demo cục bộ', $status['demo_verification_enabled'] ? 'Đang bật (Local Demo)' : 'Đang tắt (Khuyên dùng)'],
                ['3. Thông tin xác thực Google OAuth', $status['oauth_credentials_configured'] ? 'Đã lưu Client ID & Secret ('.$status['oauth_client_id_masked'].')' : 'Chưa cấu hình'],
                ['4. Luồng đăng nhập Google OAuth', $status['oauth_signin_implemented'] ? 'Đã triển khai trong mã nguồn' : 'Chưa triển khai trong mã nguồn (thiếu route/callback/state/linking)'],
                ['5. Kiểm thử đăng nhập Google OAuth', $status['oauth_signin_tested'] ? 'Đã kiểm thử thành công' : 'Chưa thể kiểm thử (chưa triển khai)'],
                ['Trạng thái kích hoạt Google OAuth', $status['oauth_enabled'] ? 'Đang bật' : 'Đang tắt an toàn'],
            ],
        );

        return self::SUCCESS;
    }

    private function clearConfigCacheSafely(): void
    {
        $wasCached = $this->laravel->configurationIsCached();
        $this->callSilent('config:clear');

        if ($wasCached) {
            $this->line('Đã xóa bộ nhớ đệm cấu hình (config:clear) để áp dụng thông số mới.');
        }
    }

    private function runSafeReconfiguration(GoogleServicesSetupManager $manager, ?string $envPath): int
    {
        $this->newLine();
        $this->info('--- Cấu hình lại an toàn dành cho chủ sở hữu mới ---');
        $this->line('Hệ thống sẽ tự động tạo bản sao lưu .env.backup trước khi áp dụng thông tin mới.');

        $target = (string) $this->choice(
            'Chọn dịch vụ cần thay thế thông tin chủ sở hữu',
            [
                'gmail' => 'Thay đổi tài khoản Gmail SMTP gửi thư',
                'oauth' => 'Thay đổi khóa kết nối Google OAuth',
                'both' => 'Thay đổi cả Gmail SMTP và Google OAuth',
            ],
            'gmail',
        );

        return match ($target) {
            'oauth' => $this->runOAuthSetup($manager, $envPath, true),
            'both' => $this->runBothSetup($manager, $envPath),
            default => $this->runGmailSetup($manager, $envPath, true),
        };
    }

    private function runDisableService(GoogleServicesSetupManager $manager, ?string $envPath): int
    {
        $target = (string) $this->choice(
            'Chọn dịch vụ bạn muốn tắt',
            [
                'gmail' => 'Tắt Gmail SMTP (Chuyển MAIL_MAILER về chế độ ghi log cục bộ và xóa mật khẩu ứng dụng)',
                'oauth' => 'Tắt đăng nhập Google OAuth (Đặt BOOKSTORE_GOOGLE_OAUTH_ENABLED=false và xóa khóa bí mật)',
                'both' => 'Tắt đồng thời cả hai dịch vụ',
            ],
            'gmail',
        );

        if (! $this->confirm('Xác nhận tắt dịch vụ đã chọn và xóa thông tin bí mật tương ứng khỏi tệp môi trường?', false)) {
            $this->warn('Đã hủy thao tác tắt dịch vụ.');

            return self::FAILURE;
        }

        if ($target === 'gmail' || $target === 'both') {
            $manager->disableGmailSmtp($envPath, true);
            $this->info('Đã tắt Gmail SMTP và chuyển kênh gửi thư về chế độ ghi log an toàn.');
        }

        if ($target === 'oauth' || $target === 'both') {
            $manager->disableGoogleOAuth($envPath, true);
            $this->info('Đã tắt dịch vụ đăng nhập Google OAuth.');
        }

        if ($envPath === null) {
            $this->clearConfigCacheSafely();
        }

        return self::SUCCESS;
    }
}
