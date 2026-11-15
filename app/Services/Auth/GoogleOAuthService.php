<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use App\Support\LoginEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Dịch vụ xử lý đăng nhập an toàn qua Google OAuth 2.0 (Stage 01B.5.8).
 *
 * Bảo đảm:
 * - Tuân thủ chuẩn OAuth 2.0 Authorization Code Flow có xác thực `state` chống CSRF.
 * - Xác thực định danh bất biến `sub` (Google Subject ID) và cờ `email_verified === true` từ Google.
 * - Phòng chống Account Takeover: từ chối các tài khoản Google chưa xác minh email.
 * - Liên kết an toàn (Safe Account Linking): bảo toàn toàn bộ vai trò nhân sự/quản trị,
 *   tuyệt đối KHÔNG tự động nâng quyền nhân sự cho tài khoản đăng ký mới qua Google.
 * - Tự động ghi nhật ký `login_events` phục vụ kiểm toán an ninh.
 */
class GoogleOAuthService
{
    public const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const GOOGLE_USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /**
     * Tạo URL chuyển hướng xác thực tài khoản Google kèm mã `state` bảo vệ phiên.
     */
    public function generateAuthorizationUrl(): string
    {
        $clientId = (string) config('services.google.client_id', '');
        $redirectUri = (string) config('services.google.redirect', '');

        if ($clientId === '' || $redirectUri === '') {
            throw new RuntimeException('Thông tin cấu hình Google OAuth (Client ID hoặc Redirect URI) chưa được thiết lập.');
        }

        $state = Str::random(40);
        session(['google_oauth_state' => $state]);

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ];

        return self::GOOGLE_AUTH_URL.'?'.http_build_query($params);
    }

    /**
     * Xử lý mã ủy quyền từ Google Callback, xác minh danh tính và đăng nhập người dùng.
     *
     * @return array{user: User, is_new: bool}
     *
     * @throws ValidationException
     */
    public function handleCallback(Request $request): array
    {
        // 1. Kiểm tra lỗi do Google trả về (ví dụ người dùng nhấn Hủy ủy quyền)
        if ($request->has('error')) {
            session()->forget('google_oauth_state');
            $error = (string) $request->query('error');

            if ($error === 'access_denied') {
                throw ValidationException::withMessages([
                    'google' => 'Bạn đã hủy quá trình đăng nhập bằng Google.',
                ]);
            }

            throw ValidationException::withMessages([
                'google' => 'Quá trình xác thực Google bị gián đoạn (mã lỗi: '.$error.'). Vui lòng thử lại.',
            ]);
        }

        // 2. Xác thực chuỗi `state` chống tấn công CSRF (State Validation)
        $sessionState = session()->pull('google_oauth_state');
        $requestState = $request->query('state');

        if (
            ! is_string($sessionState)
            || $sessionState === ''
            || ! is_string($requestState)
            || ! hash_equals($sessionState, $requestState)
        ) {
            throw ValidationException::withMessages([
                'google' => 'Phiên xác thực Google OAuth không hợp lệ hoặc đã hết hạn. Vui lòng bấm thử lại.',
            ]);
        }

        // 3. Kiểm tra mã ủy quyền `code`
        $code = (string) $request->query('code');
        if (trim($code) === '') {
            throw ValidationException::withMessages([
                'google' => 'Không nhận được mã ủy quyền xác thực từ Google.',
            ]);
        }

        // 4. Đổi mã ủy quyền lấy Access Token (Token Exchange)
        $tokenPayload = $this->exchangeCodeForToken($code);
        $accessToken = $tokenPayload['access_token'] ?? null;

        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw ValidationException::withMessages([
                'google' => 'Không thể nhận mã truy cập (Access Token) từ Google. Vui lòng thử lại.',
            ]);
        }

        // 5. Truy vấn thông tin người dùng từ Google OpenID Connect UserInfo
        $userInfo = $this->fetchUserInfo($accessToken);

        // 6. Kiểm tra tính toàn vẹn và phòng chống chiếm đoạt tài khoản (Account Takeover Defense)
        $sub = trim((string) ($userInfo['sub'] ?? ''));
        $rawEmail = trim((string) ($userInfo['email'] ?? ''));
        $emailVerified = filter_var($userInfo['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
        $name = trim((string) ($userInfo['name'] ?? ''));

        if ($sub === '') {
            throw ValidationException::withMessages([
                'google' => 'Không tìm thấy định danh người dùng duy nhất (Subject ID) từ tài khoản Google.',
            ]);
        }

        if ($rawEmail === '' || ! filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'google' => 'Tài khoản Google không cung cấp địa chỉ email hợp lệ.',
            ]);
        }

        // BẮT BUỘC: Google phải xác nhận email này đã được xác minh chính chủ
        if (! $emailVerified) {
            throw ValidationException::withMessages([
                'google' => 'Địa chỉ email tài khoản Google chưa được xác thực chính chủ. Vì lý do an toàn, Book_Store từ chối liên kết tài khoản này.',
            ]);
        }

        $email = Str::lower($rawEmail);

        // 7. Xác định mục đích ủy quyền (Intent): 'login' (mặc định), 'link' (tự liên kết tài khoản) hoặc 'staff_onboard'
        $intent = (string) session()->pull('google_oauth_intent', 'login');
        $linkingUserId = session()->pull('google_oauth_user_id');
        $onboardUserId = session()->pull('staff_onboard_user_id');

        $hasExternalIdentitiesTable = Schema::hasTable('user_external_identities');
        /** @var UserExternalIdentity|null $existingIdentity */
        $existingIdentity = $hasExternalIdentitiesTable
            ? UserExternalIdentity::query()->where('provider', 'google')->where('provider_subject', $sub)->first()
            : null;

        $user = null;
        $isNewUser = false;

        if ($intent === 'link') {
            // LUỒNG 1: Liên kết có chủ đích bởi người dùng đang đăng nhập (Deliberate Account Linking)
            $targetUserId = $linkingUserId ?? Auth::id();
            $targetUser = $targetUserId ? User::query()->find($targetUserId) : null;

            if ($targetUser === null) {
                throw ValidationException::withMessages([
                    'google' => 'Không tìm thấy phiên đăng nhập tài khoản cần liên kết. Vui lòng thử lại.',
                ]);
            }

            if ($existingIdentity !== null && $existingIdentity->user_id !== $targetUser->id) {
                throw ValidationException::withMessages([
                    'google' => 'Tài khoản Google này đã được liên kết với một tài khoản Book_Store khác.',
                ]);
            }

            if ($existingIdentity === null && $hasExternalIdentitiesTable) {
                UserExternalIdentity::query()->create([
                    'user_id' => $targetUser->id,
                    'provider' => 'google',
                    'provider_subject' => $sub,
                    'provider_email' => $email,
                    'linked_at' => now(),
                ]);
            }

            if ($targetUser->email_verified_at === null) {
                $targetUser->email_verified_at = now();
                $targetUser->save();
            }

            $user = $targetUser;
        } elseif ($intent === 'staff_onboard') {
            // LUỒNG 2: Nhân sự kích hoạt định danh Google công ty qua mã mời (Corporate Google Staff Onboarding)
            $staffUser = $onboardUserId ? User::query()->find($onboardUserId) : null;

            if ($staffUser === null) {
                throw ValidationException::withMessages([
                    'google' => 'Yêu cầu liên kết tài khoản nhân sự không hợp lệ hoặc đã hết hạn.',
                ]);
            }

            if ($existingIdentity !== null && $existingIdentity->user_id !== $staffUser->id) {
                throw ValidationException::withMessages([
                    'google' => 'Tài khoản Google này đã được liên kết với một tài khoản nhân sự khác.',
                ]);
            }

            if ($existingIdentity === null && $hasExternalIdentitiesTable) {
                UserExternalIdentity::query()->create([
                    'user_id' => $staffUser->id,
                    'provider' => 'google',
                    'provider_subject' => $sub,
                    'provider_email' => $email,
                    'linked_at' => now(),
                ]);
            }

            if ($staffUser->email_verified_at === null) {
                $staffUser->email_verified_at = now();
                $staffUser->save();
            }

            $user = $staffUser;
        } else {
            // LUỒNG 3: Đăng nhập chuẩn bằng Google (Google OAuth Login)
            if ($existingIdentity !== null) {
                // ĐÃ LIÊN KẾT BẰNG SUBJECT ID DUY NHẤT: Xác thực tài khoản chính chủ an toàn
                $user = $existingIdentity->user;

                if ($user === null) {
                    throw ValidationException::withMessages([
                        'google' => 'Tài khoản liên kết không tồn tại trong hệ thống Book_Store.',
                    ]);
                }

                if (! $user->isActive()) {
                    LoginEventLogger::log(
                        eventType: LoginEventType::AccountLocked,
                        userId: $user->id,
                        email: $email,
                        ipAddress: $request->ip(),
                        userAgent: $request->userAgent(),
                    );

                    throw ValidationException::withMessages([
                        'google' => 'Tài khoản Book_Store của bạn đang bị khóa hoặc ngừng hoạt động. Vui lòng liên hệ bộ phận hỗ trợ.',
                    ]);
                }
            } else {
                // ĐỊNH DANH GOOGLE NÀY CHƯA ĐƯỢC LIÊN KẾT TRƯỚC ĐÓ:
                // Kiểm tra xem email này đã tồn tại trong bảng users chưa
                $userByEmail = User::query()->where('email', $email)->first();

                if ($userByEmail !== null) {
                    // CHẶN TỰ ĐỘNG LIÊN KẾT THEO EMAIL (Phòng chống Account Takeover & Bảo vệ nhân sự/Super Admin)
                    throw ValidationException::withMessages([
                        'google' => 'Tài khoản với email này đã tồn tại trong hệ thống. Để bảo đảm an toàn, vui lòng đăng nhập bằng mật khẩu và thực hiện liên kết Google trong mục Cài đặt tài khoản (hoặc sử dụng liên kết mời nhân sự).',
                    ]);
                }

                // Nếu hoàn toàn chưa có email trong hệ thống: Tạo tài khoản khách hàng mới an toàn
                $displayName = $name !== '' ? $name : explode('@', $email)[0];

                $user = new User();
                $user->name = $displayName;
                $user->email = $email;
                $user->password = Hash::make(Str::random(32)); // Mật khẩu ngẫu nhiên an toàn (vẫn có thể đặt lại mật khẩu sau)
                $user->account_status = AccountStatus::Active;
                $user->staff_access_status = StaffAccessStatus::NotApplicable;
                $user->email_verified_at = now(); // Đã xác thực qua Google

                if (Schema::hasColumn('users', 'google_id')) {
                    $user->google_id = $sub;
                }

                $user->save();
                $isNewUser = true;

                if ($hasExternalIdentitiesTable) {
                    UserExternalIdentity::query()->create([
                        'user_id' => $user->id,
                        'provider' => 'google',
                        'provider_subject' => $sub,
                        'provider_email' => $email,
                        'linked_at' => now(),
                    ]);
                }

                LoginEventLogger::log(
                    eventType: LoginEventType::RegisterSuccess,
                    userId: $user->id,
                    email: $email,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }
        }

        // 8. Ghi nhận sự kiện đăng nhập thành công
        LoginEventLogger::log(
            eventType: LoginEventType::LoginSuccess,
            userId: $user->id,
            email: $email,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return [
            'user' => $user,
            'is_new' => $isNewUser,
        ];
    }

    /**
     * Gửi yêu cầu HTTP POST đổi mã ủy quyền lấy Access Token từ Google.
     *
     * @return array<string, mixed>
     */
    protected function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()
            ->timeout(10)
            ->post(self::GOOGLE_TOKEN_URL, [
                'code' => $code,
                'client_id' => (string) config('services.google.client_id', ''),
                'client_secret' => (string) config('services.google.client_secret', ''),
                'redirect_uri' => (string) config('services.google.redirect', ''),
                'grant_type' => 'authorization_code',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Lỗi kết nối máy chủ Google OAuth khi đổi mã truy cập.');
        }

        return $response->json() ?? [];
    }

    /**
     * Gửi yêu cầu HTTP GET lấy thông tin tài khoản người dùng từ Google OpenID Connect.
     *
     * @return array<string, mixed>
     */
    protected function fetchUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->get(self::GOOGLE_USERINFO_URL);

        if (! $response->successful()) {
            throw new RuntimeException('Không thể truy vấn thông tin định danh người dùng từ Google.');
        }

        return $response->json() ?? [];
    }
}
