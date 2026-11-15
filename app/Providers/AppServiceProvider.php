<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\BookStoreSqliteConnection;
use App\Enums\BranchStatus;
use App\Enums\RoleScope;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\StaffActivationProposal;
use App\Models\User;
use App\Policies\BranchPolicy;
use App\Policies\StaffActivationProposalPolicy;
use App\Policies\UserGovernancePolicy;
use App\Services\Authorization\AuthorizationService;
use App\Services\Authorization\BranchContextResolver;
use App\Services\Authorization\DomainGuardService;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Connection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Support/helpers.php');

        Connection::resolverFor('sqlite', static function ($connection, $database, $prefix, $config): BookStoreSqliteConnection {
            return new BookStoreSqliteConnection($connection, $database, $prefix, $config);
        });

        $this->app->singleton(AuthorizationService::class);
        $this->app->singleton(DomainGuardService::class);
        $this->app->singleton(BranchContextResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Cố định ngôn ngữ Tiếng Việt (vi) toàn hệ thống theo Hiến pháp dự án (Principle IX),
        // ngăn chặn biến môi trường APP_LOCALE=en mặc định trong .env ghi đè sang tiếng Anh.
        config([
            'app.locale' => 'vi',
            'app.fallback_locale' => 'vi',
        ]);
        app()->setLocale('vi');

        // Cấu hình email xác thực tài khoản 100% tiếng Việt tự nhiên
        VerifyEmail::toMailUsing(function (object $notifiable, string $url) {
            return (new MailMessage)
                ->subject('Xác nhận địa chỉ email của bạn - Book_Store')
                ->greeting('Xin chào '.$notifiable->name.'!')
                ->line('Cảm ơn bạn đã đăng ký tài khoản tại Book_Store. Vui lòng nhấp vào nút bên dưới để xác thực địa chỉ email của bạn:')
                ->action('Xác nhận tài khoản', $url)
                ->line('Liên kết xác nhận này sẽ hết hạn sau 60 phút.')
                ->line('Nếu bạn không tạo tài khoản tại Book_Store, bạn có thể yên tâm bỏ qua email này.')
                ->salutation('Trân trọng,'."\n".'Đội ngũ Book_Store');
        });

        // Cấu hình email khôi phục mật khẩu 100% tiếng Việt tự nhiên, tương thích cùng kênh SMTP
        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
            $resetUrl = url('/dat-lai-mat-khau/'.$token.'?email='.urlencode((string) $notifiable->getEmailForPasswordReset()));

            return (new MailMessage)
                ->subject('Yêu cầu đặt lại mật khẩu tài khoản - Book_Store')
                ->greeting('Xin chào '.($notifiable->name ?? 'bạn').'!')
                ->line('Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản Book_Store gắn với địa chỉ email này.')
                ->action('Đặt lại mật khẩu', $resetUrl)
                ->line("Liên kết đặt lại mật khẩu này sẽ hết hạn sau {$expireMinutes} phút.")
                ->line('Nếu bạn không yêu cầu đặt lại mật khẩu, vui lòng bỏ qua email này; mật khẩu hiện tại của bạn vẫn an toàn.')
                ->salutation('Trân trọng,'."\n".'Đội ngũ Book_Store');
        });

        $this->registerAuthorization();
    }

    /**
     * Đăng ký Laravel Gates, Policies và cơ chế bỏ qua có kiểm soát cho Super Administrator.
     *
     * Flow:
     * Gate::allows($ability, $args)
     * -> Gate::before() chỉ xử lý mã quyền RBAC chuẩn hóa và KHÔNG bao giờ bỏ qua
     *    Policy hoặc bất biến chi nhánh/Self-Action
     * -> Gate::define() / Policy -> AuthorizationService + DomainGuardService.
     */
    private function registerAuthorization(): void
    {
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(StaffActivationProposal::class, StaffActivationProposalPolicy::class);
        Gate::policy(User::class, UserGovernancePolicy::class);

        $permissionScopes = PermissionCatalog::scopeByCode();

        Gate::before(function (User $user, string $ability, array $arguments = []) use ($permissionScopes): ?bool {
            $scope = $permissionScopes[$ability] ?? null;

            // Chỉ can thiệp đối với các mã quyền RBAC chuẩn hóa; các phương thức Policy và
            // ability không thuộc danh mục luôn đi qua Policy/từ chối mặc định.
            if ($scope === null) {
                return null;
            }

            /** @var AuthorizationService $authorization */
            $authorization = app(AuthorizationService::class);

            if (! $authorization->isEffectiveSuperAdmin($user)) {
                return null;
            }

            // Đảm bảo fail-closed nếu mã quyền chưa tồn tại hoặc không khớp phạm vi trong bảng `permissions`.
            $permissionExistsInDb = Permission::query()
                ->where('code', $ability)
                ->where('scope', $scope->value)
                ->exists();

            if (! $permissionExistsInDb) {
                return false;
            }

            if ($scope === RoleScope::Branch) {
                $branch = $arguments[0] ?? null;

                return $branch instanceof Branch
                    && $branch->exists
                    && $branch->status === BranchStatus::Active;
            }

            return true;
        });

        foreach ($permissionScopes as $code => $scope) {
            Gate::define($code, function (User $user, mixed $context = null) use ($code, $scope): bool {
                /** @var AuthorizationService $authorization */
                $authorization = app(AuthorizationService::class);

                if ($scope === RoleScope::Global) {
                    return $authorization->hasGlobalPermission($user, $code);
                }

                return $context instanceof Branch
                    && $authorization->hasBranchPermission($user, $code, $context);
            });
        }
    }
}
