<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Giá trị mặc định an toàn khớp với ràng buộc CSDL (khách hàng không có quan hệ nhân sự
     * và không bị ép đổi mật khẩu tạm).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'account_status' => AccountStatus::Active->value,
        'staff_access_status' => StaffAccessStatus::NotApplicable->value,
        'must_change_password' => false,
    ];

    /**
     * Các trường thông thường được phép gán hàng loạt từ biểu mẫu người dùng.
     * Tuyệt đối KHÔNG thêm `account_status`, `staff_access_status` hay các cột vòng đời
     * mật khẩu tạm (`must_change_password`, `temp_password_*`, `password_changed_at`).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Kiểm tra tài khoản có đang hoạt động hay không.
     */
    public function isActive(): bool
    {
        return $this->account_status?->canAuthenticate() ?? false;
    }

    /**
     * Kiểm tra tài khoản có bị khóa hay không.
     */
    public function isSuspended(): bool
    {
        return $this->account_status === AccountStatus::Suspended;
    }

    /**
     * Kiểm tra tài khoản có đủ điều kiện trạng thái cơ sở cho luồng nhân sự hay không.
     *
     * Lưu ý bảo mật: Giá trị `StaffAccessStatus::Active` KHÔNG bao giờ tự cấp quyền
     * quản trị hay quyền chi nhánh; phân quyền luôn yêu cầu kiểm tra vai trò & phạm vi chi nhánh.
     */
    public function hasStaffAccess(): bool
    {
        return $this->isActive()
            && ($this->staff_access_status?->isEligibleForStaffAccess() ?? false);
    }

    /**
     * Kiểm tra mật khẩu tạm thời onboarding có đang hết hạn hay chưa.
     */
    public function isTemporaryPasswordExpired(): bool
    {
        return (bool) $this->must_change_password
            && $this->temp_password_expires_at !== null
            && $this->temp_password_expires_at->isPast();
    }

    /**
     * Người quản trị đã cấp mật khẩu tạm thời cho tài khoản nhân sự này.
     *
     * Flow: `users.temp_password_issued_by` -> `User` (nhánh onboarding đổi mật khẩu lần đầu).
     *
     * @return BelongsTo<User, $this>
     */
    public function tempPasswordIssuer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'temp_password_issued_by');
    }

    /**
     * Hồ sơ nhân sự gắn với tài khoản (nếu là nhân viên Book_Store).
     *
     * Flow: `User` -> `StaffProfile` (`staff_profiles.user_id`).
     *
     * @return HasOne<StaffProfile, $this>
     */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /**
     * Danh sách bản ghi gán vai trò phạm vi toàn hệ thống (`global`) của tài khoản.
     *
     * Flow: `User` -> `UserRole` (`user_roles.user_id`).
     *
     * @return HasMany<UserRole, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Các vai trò phạm vi toàn hệ thống (`global`) được gán trực tiếp qua `user_roles`.
     *
     * Flow: `User` -> `user_roles` -> `Role`.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['role_scope', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Danh sách phân công nhân sự tại các chi nhánh/kho (`branch_assignments`).
     *
     * Flow: `User` -> `BranchAssignment` (`branch_assignments.user_id`).
     *
     * @return HasMany<BranchAssignment, $this>
     */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(BranchAssignment::class);
    }

    /**
     * Các chi nhánh/kho mà nhân sự được phân công thông qua `branch_assignments`.
     *
     * Flow: `User` -> `branch_assignments` -> `Branch`.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_assignments')
            ->withPivot(['status', 'is_primary', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Danh sách phân quyền vai trò theo từng chi nhánh (`branch_role_assignments`).
     *
     * Flow: `User` -> `BranchRoleAssignment` (`branch_role_assignments.user_id`).
     *
     * @return HasMany<BranchRoleAssignment, $this>
     */
    public function branchRoleAssignments(): HasMany
    {
        return $this->hasMany(BranchRoleAssignment::class);
    }

    /**
     * Các đề xuất kích hoạt quyền nhân sự dành cho tài khoản này (`user_id`).
     *
     * Flow: `User` (nhân sự mục tiêu) -> `StaffActivationProposal`.
     *
     * @return HasMany<StaffActivationProposal, $this>
     */
    public function staffActivationProposals(): HasMany
    {
        return $this->hasMany(StaffActivationProposal::class, 'user_id');
    }

    /**
     * Các đề xuất kích hoạt nhân sự do tài khoản này khởi tạo (`proposed_by`).
     *
     * Flow: `User` (người đề xuất) -> `StaffActivationProposal`.
     *
     * @return HasMany<StaffActivationProposal, $this>
     */
    public function proposedStaffActivations(): HasMany
    {
        return $this->hasMany(StaffActivationProposal::class, 'proposed_by');
    }

    /**
     * Các đề xuất kích hoạt nhân sự do tài khoản này thẩm định (`reviewed_by`).
     *
     * Flow: `User` (người duyệt, tách biệt với `user_id`) -> `StaffActivationProposal`.
     *
     * @return HasMany<StaffActivationProposal, $this>
     */
    public function reviewedStaffActivations(): HasMany
    {
        return $this->hasMany(StaffActivationProposal::class, 'reviewed_by');
    }

    /**
     * Các bản ghi nhật ký kiểm toán do tài khoản này thực hiện (`actor_id`).
     *
     * Flow: `User` (tác nhân) -> `AuditLog`.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function actedAuditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    /**
     * Các bản ghi nhật ký kiểm toán tác động lên tài khoản này (`target_user_id`).
     *
     * Flow: `User` (đối tượng chịu tác động) -> `AuditLog`.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function targetedAuditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'target_user_id');
    }

    /**
     * Danh sách định danh ngoài liên kết với tài khoản (Google OAuth).
     *
     * Flow: `User` -> `UserExternalIdentity`.
     *
     * @return HasMany<UserExternalIdentity, $this>
     */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(UserExternalIdentity::class);
    }

    /**
     * Định danh Google OAuth liên kết (nếu có).
     *
     * @return HasOne<UserExternalIdentity, $this>
     */
    public function googleIdentity(): HasOne
    {
        return $this->hasOne(UserExternalIdentity::class)->where('provider', 'google');
    }

    /**
     * Lịch sử các sự kiện đăng nhập của tài khoản.
     *
     * Flow: `User` -> `LoginEvent`.
     *
     * @return HasMany<LoginEvent, $this>
     */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    /**
     * Kiểm tra tài khoản đã có mật khẩu truyền thống hay chưa.
     */
    public function hasPassword(): bool
    {
        return is_string($this->password) && $this->password !== '';
    }

    /**
     * Kiểm tra tài khoản có thể an toàn hủy liên kết một nhà cung cấp đăng nhập hay không
     * (Không cho phép hủy nếu đây là phương thức đăng nhập duy nhất).
     */
    public function canUnlinkProvider(string $provider): bool
    {
        if ($this->hasPassword()) {
            return true;
        }

        return $this->externalIdentities()
            ->where('provider', '!=', $provider)
            ->exists();
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_status' => AccountStatus::class,
            'staff_access_status' => StaffAccessStatus::class,
            'must_change_password' => 'boolean',
            'temp_password_issued_at' => 'datetime',
            'temp_password_expires_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }
}
