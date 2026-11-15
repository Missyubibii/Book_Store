<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Throwable;

/**
 * Dịch vụ quản lý cấu hình chuyển giao chủ sở hữu cho Gmail SMTP và Google OAuth (`bookstore:setup-google`).
 *
 * Bảo mật & Toàn vẹn:
 * - Cho phép chủ sở hữu tương lai thiết lập Gmail (Mật khẩu ứng dụng 16 ký tự) và Google OAuth
 *   mà không cần chỉnh sửa thủ công mã nguồn hoặc tệp `.env`.
 * - Bảo toàn nguyên vẹn toàn bộ chú thích, dòng trống và các khóa cấu hình khác trong tệp `.env`.
 * - Hỗ trợ tạo bản sao lưu `.env.backup` trước khi ghi đè (cấu hình lại an toàn).
 * - Tuyệt đối không ghi lộ mật khẩu ứng dụng hoặc Client Secret ra nhật ký hay kết quả chẩn đoán.
 */
class GoogleServicesSetupManager
{
    /**
     * Chuẩn hóa và kiểm tra Mật khẩu ứng dụng Google (16 ký tự chữ/số, cho phép nhập kèm khoảng trắng).
     *
     * @throws InvalidArgumentException
     */
    public function normalizeAppPassword(string $rawInput): string
    {
        $cleaned = preg_replace('/[\s-]+/', '', trim($rawInput)) ?? '';

        if ($cleaned === '') {
            throw new InvalidArgumentException('Mật khẩu ứng dụng Google không được để trống.');
        }

        if (preg_match('/^[A-Za-z0-9]{16}$/', $cleaned) !== 1) {
            throw new InvalidArgumentException(
                'Mật khẩu ứng dụng Google phải gồm đúng 16 ký tự chữ/số (ví dụ: xxxx xxxx xxxx xxxx). Tuyệt đối không sử dụng mật khẩu đăng nhập Gmail thông thường.'
            );
        }

        return Str::lower($cleaned);
    }

    /**
     * Kiểm tra địa chỉ email người gửi Gmail / Google Workspace.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeSenderEmail(string $rawEmail): string
    {
        $normalized = Str::lower(trim($rawEmail));

        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Địa chỉ email gửi thư không hợp lệ.');
        }

        return $normalized;
    }

    /**
     * Kiểm tra Google OAuth Client ID.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeClientId(string $rawClientId): string
    {
        $trimmed = trim($rawClientId);

        if ($trimmed === '' || ! str_ends_with(Str::lower($trimmed), '.apps.googleusercontent.com')) {
            throw new InvalidArgumentException(
                'Google Client ID không hợp lệ (phải kết thúc bằng .apps.googleusercontent.com).'
            );
        }

        return $trimmed;
    }

    /**
     * Kiểm tra Google OAuth Client Secret.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeClientSecret(string $rawSecret): string
    {
        $trimmed = trim($rawSecret);

        if (strlen($trimmed) < 12 || preg_match('/\s/', $trimmed) === 1) {
            throw new InvalidArgumentException(
                'Google Client Secret không hợp lệ (tối thiểu 12 ký tự và không chứa khoảng trắng).'
            );
        }

        return $trimmed;
    }

    /**
     * Kiểm tra URI chuyển hướng (Authorized Redirect URI) cho Google OAuth.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeRedirectUri(string $rawUri): string
    {
        $trimmed = trim($rawUri);

        if ($trimmed === '' || ! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Địa chỉ Redirect URI không phải là một URL hợp lệ.');
        }

        $scheme = Str::lower((string) parse_url($trimmed, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Redirect URI chỉ hỗ trợ giao thức http hoặc https.');
        }

        return $trimmed;
    }

    /**
     * Ghi cấu hình Gmail SMTP vào tệp môi trường (`.env`) và cập nhật runtime config.
     *
     * @param  array{
     *     email: string,
     *     app_password: string,
     *     from_name?: string|null,
     *     port?: int|string|null,
     *     disable_demo_verification?: bool
     * }  $input
     */
    public function configureGmailSmtp(
        array $input,
        ?string $envPath = null,
        bool $createBackup = true,
    ): void {
        $email = $this->normalizeSenderEmail($input['email'] ?? '');
        $appPassword = $this->normalizeAppPassword($input['app_password'] ?? '');
        $fromName = trim((string) ($input['from_name'] ?? 'Book_Store'));
        if ($fromName === '') {
            $fromName = 'Book_Store';
        }

        $port = (int) ($input['port'] ?? 587);
        if (! in_array($port, [587, 465], true)) {
            $port = 587;
        }

        $scheme = $port === 465 ? 'smtps' : 'smtp';
        $encryption = $port === 465 ? 'ssl' : 'tls';
        $disableDemo = (bool) ($input['disable_demo_verification'] ?? true);

        $updates = [
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => $scheme,
            'MAIL_HOST' => 'smtp.gmail.com',
            'MAIL_PORT' => (string) $port,
            'MAIL_USERNAME' => $email,
            'MAIL_PASSWORD' => $appPassword,
            'MAIL_ENCRYPTION' => $encryption,
            'MAIL_FROM_ADDRESS' => $email,
            'MAIL_FROM_NAME' => $fromName,
        ];

        if ($disableDemo) {
            $updates['BOOKSTORE_DEMO_EMAIL_VERIFICATION'] = 'false';
        }

        $this->updateEnvironmentFile($updates, $envPath, $createBackup);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $email,
            'mail.mailers.smtp.password' => $appPassword,
            'mail.from.address' => $email,
            'mail.from.name' => $fromName,
        ]);

        if ($disableDemo) {
            config(['bookstore.demo_email_verification' => false]);
        }
    }

    /**
     * Ghi thông tin xác thực Google OAuth vào tệp môi trường (`.env`) và cập nhật runtime config.
     *
     * Bảo mật: Chỉ lưu `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` và
     * tuyệt đối KHÔNG tự động bật `BOOKSTORE_GOOGLE_OAUTH_ENABLED=true` khi luồng đăng nhập
     * Google OAuth chưa được triển khai đầy đủ trong mã nguồn ứng dụng.
     *
     * @param  array{
     *     client_id: string,
     *     client_secret: string,
     *     redirect_uri: string
     * }  $input
     */
    public function configureGoogleOAuth(
        array $input,
        ?string $envPath = null,
        bool $createBackup = true,
    ): void {
        $clientId = $this->normalizeClientId($input['client_id'] ?? '');
        $clientSecret = $this->normalizeClientSecret($input['client_secret'] ?? '');
        $redirectUri = $this->normalizeRedirectUri($input['redirect_uri'] ?? '');

        $implemented = $this->isGoogleOAuthSignInImplemented();

        $updates = [
            'BOOKSTORE_GOOGLE_OAUTH_ENABLED' => $implemented ? 'true' : 'false',
            'GOOGLE_CLIENT_ID' => $clientId,
            'GOOGLE_CLIENT_SECRET' => $clientSecret,
            'GOOGLE_REDIRECT_URI' => $redirectUri,
        ];

        $this->updateEnvironmentFile($updates, $envPath, $createBackup);

        config([
            'services.google.enabled' => $implemented,
            'services.google.client_id' => $clientId,
            'services.google.client_secret' => $clientSecret,
            'services.google.redirect' => $redirectUri,
        ]);
    }

    /**
     * Kiểm tra thực tế xem ứng dụng đã triển khai đầy đủ luồng đăng nhập Google OAuth hay chưa.
     */
    public function isGoogleOAuthSignInImplemented(): bool
    {
        return Route::has('auth.google.redirect') && Route::has('auth.google.callback');
    }

    /**
     * Tắt gửi thư qua Gmail SMTP (chuyển về `log` an toàn và xóa mật khẩu ứng dụng khỏi `.env`).
     */
    public function disableGmailSmtp(?string $envPath = null, bool $createBackup = true): void
    {
        $this->updateEnvironmentFile([
            'MAIL_MAILER' => 'log',
            'MAIL_PASSWORD' => '',
        ], $envPath, $createBackup);

        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.password' => '',
        ]);
    }

    /**
     * Tắt tích hợp đăng nhập Google OAuth và xóa thông tin bí mật khỏi `.env`.
     */
    public function disableGoogleOAuth(?string $envPath = null, bool $createBackup = true): void
    {
        $this->updateEnvironmentFile([
            'BOOKSTORE_GOOGLE_OAUTH_ENABLED' => 'false',
            'GOOGLE_CLIENT_ID' => '',
            'GOOGLE_CLIENT_SECRET' => '',
        ], $envPath, $createBackup);

        config([
            'services.google.enabled' => false,
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
        ]);
    }

    /**
     * Chẩn đoán tách biệt trạng thái Gmail SMTP và Google OAuth (che giấu hoàn toàn bí mật).
     *
     * @return array{
     *     mail_mailer: string,
     *     mail_host: string,
     *     mail_port: string,
     *     mail_username: string,
     *     mail_from_address: string,
     *     mail_password_configured: bool,
     *     smtp_configured: bool,
     *     smtp_connection_tested: bool|null,
     *     demo_verification_enabled: bool,
     *     oauth_enabled: bool,
     *     oauth_client_id_masked: string,
     *     oauth_secret_configured: bool,
     *     oauth_redirect_uri: string,
     *     oauth_credentials_configured: bool,
     *     oauth_signin_implemented: bool,
     *     oauth_signin_tested: bool
     * }
     */
    public function diagnose(?string $envPath = null, ?bool $smtpConnectionTested = null): array
    {
        $pairs = $this->readEnvironmentPairs($envPath);

        $mailer = $pairs['MAIL_MAILER'] ?? (string) config('mail.default', 'log');
        $host = $pairs['MAIL_HOST'] ?? (string) config('mail.mailers.smtp.host', '127.0.0.1');
        $port = (string) ($pairs['MAIL_PORT'] ?? config('mail.mailers.smtp.port', 2525));
        $username = $pairs['MAIL_USERNAME'] ?? (string) config('mail.mailers.smtp.username', '');
        $password = $pairs['MAIL_PASSWORD'] ?? (string) config('mail.mailers.smtp.password', '');
        $fromAddress = $pairs['MAIL_FROM_ADDRESS'] ?? (string) config('mail.from.address', '');
        $demoFlag = filter_var($pairs['BOOKSTORE_DEMO_EMAIL_VERIFICATION'] ?? config('bookstore.demo_email_verification', false), FILTER_VALIDATE_BOOL);

        $passwordClean = preg_replace('/[\s-]+/', '', $password) ?? '';
        $hasValidAppPassword = preg_match('/^[A-Za-z0-9]{16}$/', $passwordClean) === 1;
        $smtpConfigured = $mailer === 'smtp'
            && Str::lower($host) === 'smtp.gmail.com'
            && filter_var($username, FILTER_VALIDATE_EMAIL) !== false
            && $hasValidAppPassword;

        $oauthImplemented = $this->isGoogleOAuthSignInImplemented();
        $oauthEnabled = $oauthImplemented
            && filter_var($pairs['BOOKSTORE_GOOGLE_OAUTH_ENABLED'] ?? config('services.google.enabled', false), FILTER_VALIDATE_BOOL);
        $clientId = trim((string) ($pairs['GOOGLE_CLIENT_ID'] ?? config('services.google.client_id', '')));
        $clientSecret = trim((string) ($pairs['GOOGLE_CLIENT_SECRET'] ?? config('services.google.client_secret', '')));
        $redirectUri = trim((string) ($pairs['GOOGLE_REDIRECT_URI'] ?? config('services.google.redirect', '')));

        $oauthCredentialsConfigured = $clientId !== ''
            && str_ends_with(Str::lower($clientId), '.apps.googleusercontent.com')
            && strlen($clientSecret) >= 12
            && filter_var($redirectUri, FILTER_VALIDATE_URL) !== false;

        return [
            'mail_mailer' => $mailer,
            'mail_host' => $host,
            'mail_port' => $port,
            'mail_username' => $username !== '' ? $username : 'Chưa cấu hình',
            'mail_from_address' => $fromAddress !== '' ? $fromAddress : 'Chưa cấu hình',
            'mail_password_configured' => $hasValidAppPassword,
            'smtp_configured' => $smtpConfigured,
            'smtp_connection_tested' => $smtpConnectionTested,
            'demo_verification_enabled' => $demoFlag,
            'oauth_enabled' => $oauthEnabled,
            'oauth_client_id_masked' => $this->maskClientId($clientId),
            'oauth_secret_configured' => strlen($clientSecret) >= 12,
            'oauth_redirect_uri' => $redirectUri !== '' ? $redirectUri : 'Chưa cấu hình',
            'oauth_credentials_configured' => $oauthCredentialsConfigured,
            'oauth_signin_implemented' => $oauthImplemented,
            'oauth_signin_tested' => false,
        ];
    }

    /**
     * Kiểm tra kết nối bắt tay SMTP tới Gmail (KHÔNG gửi email thật, KHÔNG làm lộ mật khẩu).
     *
     * @return array{success: bool, message: string}
     */
    public function testSmtpConnection(?string $envPath = null): array
    {
        $pairs = $this->readEnvironmentPairs($envPath);
        $mailer = $pairs['MAIL_MAILER'] ?? (string) config('mail.default', 'log');
        $host = $pairs['MAIL_HOST'] ?? (string) config('mail.mailers.smtp.host', '127.0.0.1');
        $port = (int) ($pairs['MAIL_PORT'] ?? config('mail.mailers.smtp.port', 587));
        $scheme = $pairs['MAIL_SCHEME'] ?? (($port === 465) ? 'smtps' : 'smtp');
        $username = $pairs['MAIL_USERNAME'] ?? (string) config('mail.mailers.smtp.username', '');
        $password = $pairs['MAIL_PASSWORD'] ?? (string) config('mail.mailers.smtp.password', '');

        if ($mailer !== 'smtp' || trim($host) === '' || trim($username) === '' || trim($password) === '') {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình đầy đủ thông tin Gmail SMTP để kiểm tra kết nối.',
            ];
        }

        try {
            $factory = new EsmtpTransportFactory;
            $transport = $factory->create(new Dsn(
                $scheme,
                $host,
                $username,
                $password,
                $port,
            ));
            $transport->start();
            $transport->stop();

            return [
                'success' => true,
                'message' => "Kết nối xác thực SMTP tới {$host}:{$port} thành công.",
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Không thể xác thực kết nối SMTP (mã lỗi: '.$exception::class.'). Vui lòng kiểm tra lại địa chỉ Gmail, Mật khẩu ứng dụng 16 ký tự và kết nối mạng.',
            ];
        }
    }

    /**
     * Đọc danh sách cặp khóa=giá trị từ tệp `.env` chỉ định.
     *
     * @return array<string, string>
     */
    public function readEnvironmentPairs(?string $envPath = null): array
    {
        $targetPath = $this->resolveEnvPath($envPath);
        if (! is_file($targetPath)) {
            return [];
        }

        $content = (string) file_get_contents($targetPath);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $pairs = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $matches) === 1) {
                $key = $matches[1];
                $rawVal = trim($matches[2]);
                $pairs[$key] = $this->unquoteEnvValue($rawVal);
            }
        }

        return $pairs;
    }

    /**
     * Ghi đồng thời cấu hình Gmail SMTP và thông tin xác thực Google OAuth trong một lần cập nhật nguyên tử.
     *
     * @param  array{
     *     email: string,
     *     app_password: string,
     *     from_name?: string|null,
     *     port?: int|string|null,
     *     disable_demo_verification?: bool
     * }  $gmailInput
     * @param  array{
     *     client_id: string,
     *     client_secret: string,
     *     redirect_uri: string
     * }  $oauthInput
     */
    public function configureBoth(
        array $gmailInput,
        array $oauthInput,
        ?string $envPath = null,
        bool $createBackup = true,
    ): void {
        $email = $this->normalizeSenderEmail($gmailInput['email'] ?? '');
        $appPassword = $this->normalizeAppPassword($gmailInput['app_password'] ?? '');
        $fromName = trim((string) ($gmailInput['from_name'] ?? 'Book_Store'));
        if ($fromName === '') {
            $fromName = 'Book_Store';
        }
        $port = (int) ($gmailInput['port'] ?? 587);
        if (! in_array($port, [587, 465], true)) {
            $port = 587;
        }
        $scheme = $port === 465 ? 'smtps' : 'smtp';
        $encryption = $port === 465 ? 'ssl' : 'tls';
        $disableDemo = (bool) ($gmailInput['disable_demo_verification'] ?? true);

        $clientId = $this->normalizeClientId($oauthInput['client_id'] ?? '');
        $clientSecret = $this->normalizeClientSecret($oauthInput['client_secret'] ?? '');
        $redirectUri = $this->normalizeRedirectUri($oauthInput['redirect_uri'] ?? '');
        $implemented = $this->isGoogleOAuthSignInImplemented();

        $updates = [
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => $scheme,
            'MAIL_HOST' => 'smtp.gmail.com',
            'MAIL_PORT' => (string) $port,
            'MAIL_USERNAME' => $email,
            'MAIL_PASSWORD' => $appPassword,
            'MAIL_ENCRYPTION' => $encryption,
            'MAIL_FROM_ADDRESS' => $email,
            'MAIL_FROM_NAME' => $fromName,
            'BOOKSTORE_GOOGLE_OAUTH_ENABLED' => $implemented ? 'true' : 'false',
            'GOOGLE_CLIENT_ID' => $clientId,
            'GOOGLE_CLIENT_SECRET' => $clientSecret,
            'GOOGLE_REDIRECT_URI' => $redirectUri,
        ];

        if ($disableDemo) {
            $updates['BOOKSTORE_DEMO_EMAIL_VERIFICATION'] = 'false';
        }

        $this->updateEnvironmentFile($updates, $envPath, $createBackup);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $email,
            'mail.mailers.smtp.password' => $appPassword,
            'mail.from.address' => $email,
            'mail.from.name' => $fromName,
            'services.google.enabled' => $implemented,
            'services.google.client_id' => $clientId,
            'services.google.client_secret' => $clientSecret,
            'services.google.redirect' => $redirectUri,
        ]);

        if ($disableDemo) {
            config(['bookstore.demo_email_verification' => false]);
        }
    }

    /**
     * Cập nhật nguyên tử các khóa trong tệp `.env` mà vẫn giữ nguyên cấu trúc, `APP_KEY` và các khóa khác.
     *
     * Bảo mật bản sao lưu:
     * - Tạo bản sao lưu tạm thời với quyền hạn chế (`0600`) để khôi phục nếu quá trình ghi gặp sự cố.
     * - Ghi qua tệp tạm (`0600`) rồi thay thế nguyên tử; kiểm tra bảo toàn `APP_KEY`.
     * - Xóa ngay bản sao lưu tạm sau khi thay thế thành công để bí mật không tồn tại vô thời hạn trên đĩa.
     *
     * @param  array<string, string>  $updates
     */
    public function updateEnvironmentFile(
        array $updates,
        ?string $envPath = null,
        bool $createBackup = true,
    ): void {
        if (array_key_exists('APP_KEY', $updates)) {
            throw new InvalidArgumentException('Tuyệt đối không được phép thay đổi hoặc ghi đè khóa bảo mật APP_KEY.');
        }

        $targetPath = $this->resolveEnvPath($envPath);

        if (! is_file($targetPath)) {
            $examplePath = base_path('.env.example');
            if (is_file($examplePath)) {
                @copy($examplePath, $targetPath);
            } else {
                file_put_contents($targetPath, '');
            }
        }

        $originalContent = (string) file_get_contents($targetPath);
        $originalAppKey = $this->extractKeyFromContent($originalContent, 'APP_KEY');
        $backupPath = $targetPath.'.backup';

        // Dọn dẹp bản sao lưu cũ nếu còn sót từ phiên bị gián đoạn trước đó
        if (is_file($backupPath)) {
            @unlink($backupPath);
        }

        if ($createBackup) {
            if (@file_put_contents($backupPath, $originalContent, LOCK_EX) === false) {
                throw new RuntimeException('Không thể tạo bản sao lưu tạm thời cho tệp cấu hình môi trường.');
            }
            @chmod($backupPath, 0600);
        }

        $lineEnding = str_contains($originalContent, "\r\n") ? "\r\n" : "\n";
        $lines = $originalContent === '' ? [] : (preg_split('/\r\n|\r|\n/', $originalContent) ?: []);
        $remainingKeys = $updates;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];
            if (! array_key_exists($key, $remainingKeys)) {
                continue;
            }

            $lines[$index] = $key.'='.$this->formatEnvValue($remainingKeys[$key]);
            unset($remainingKeys[$key]);
        }

        if ($remainingKeys !== []) {
            if ($lines !== [] && trim((string) end($lines)) !== '') {
                $lines[] = '';
            }

            foreach ($remainingKeys as $key => $value) {
                $lines[] = $key.'='.$this->formatEnvValue($value);
            }
        }

        $newContent = implode($lineEnding, $lines);
        if (! str_ends_with($newContent, $lineEnding)) {
            $newContent .= $lineEnding;
        }

        if ($originalAppKey !== null && $this->extractKeyFromContent($newContent, 'APP_KEY') !== $originalAppKey) {
            if ($createBackup && is_file($backupPath)) {
                @unlink($backupPath);
            }

            throw new RuntimeException('Phát hiện thay đổi bất thường đối với APP_KEY; đã hủy cập nhật để bảo vệ dữ liệu mã hóa.');
        }

        $tmpPath = $targetPath.'.tmp.'.bin2hex(random_bytes(4));

        try {
            if (@file_put_contents($tmpPath, $newContent, LOCK_EX) === false) {
                throw new RuntimeException('Không thể tạo tệp cấu hình tạm thời.');
            }
            @chmod($tmpPath, 0600);

            if (! @rename($tmpPath, $targetPath)) {
                if (@file_put_contents($targetPath, $newContent, LOCK_EX) === false) {
                    throw new RuntimeException('Không thể ghi cập nhật vào tệp cấu hình môi trường.');
                }
                @unlink($tmpPath);
            }

            @chmod($targetPath, 0600);
        } catch (Throwable $exception) {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }

            if ($createBackup && is_file($backupPath)) {
                @copy($backupPath, $targetPath);
                @chmod($targetPath, 0600);
                @unlink($backupPath);
            }

            throw new RuntimeException('Không thể ghi cập nhật vào tệp cấu hình môi trường; cấu hình cũ đã được khôi phục an toàn.', 0, $exception);
        }

        if ($createBackup && is_file($backupPath)) {
            @unlink($backupPath);
        }
    }

    private function extractKeyFromContent(string $content, string $targetKey): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($targetKey, '/').'\s*=\s*(.*)$/', $line, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function resolveEnvPath(?string $envPath): string
    {
        if ($envPath !== null && trim($envPath) !== '') {
            return $envPath;
        }

        return base_path('.env');
    }

    private function formatEnvValue(string $value): string
    {
        $sanitized = str_replace(["\r", "\n", "\0"], '', $value);

        if ($sanitized === '') {
            return '';
        }

        if (preg_match('/[\s#"\\\']/', $sanitized) === 1) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $sanitized);

            return '"'.$escaped.'"';
        }

        return $sanitized;
    }

    private function unquoteEnvValue(string $raw): string
    {
        if ($raw === '' || $raw === 'null') {
            return '';
        }

        if (
            (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
            || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
        ) {
            $unquoted = substr($raw, 1, -1);

            return str_replace(['\\"', '\\\\'], ['"', '\\'], $unquoted);
        }

        return $raw;
    }

    private function maskClientId(string $clientId): string
    {
        if ($clientId === '') {
            return 'Chưa cấu hình';
        }

        if (strlen($clientId) <= 18) {
            return 'Đã cấu hình (đã ẩn)';
        }

        return substr($clientId, 0, 8).'...'.substr($clientId, -26);
    }
}
