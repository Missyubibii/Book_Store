<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state (luôn tạo tài khoản khách hàng an toàn, không có quyền nhân sự).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'account_status' => AccountStatus::Active->value,
            'staff_access_status' => StaffAccessStatus::NotApplicable->value,
            'must_change_password' => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Đánh dấu tài khoản đang bị khóa (`AccountStatus::Suspended`).
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_status' => AccountStatus::Suspended->value,
        ]);
    }

    /**
     * Đánh dấu tài khoản đang giữ mật khẩu tạm thời và bắt buộc đổi mật khẩu ở lần đăng nhập đầu.
     */
    public function mustChangePassword(?DateTimeInterface $expiresAt = null, ?int $issuedBy = null): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
            'temp_password_issued_at' => now(),
            'temp_password_expires_at' => $expiresAt ?? now()->addHours((int) config('auth.staff_temporary_password_ttl_hours', 24)),
            'temp_password_issued_by' => $issuedBy,
        ]);
    }

    /**
     * Đánh dấu tài khoản nhân sự đang chờ kích hoạt (`StaffAccessStatus::Pending`).
     */
    public function staffPending(): static
    {
        return $this->state(fn (array $attributes) => [
            'staff_access_status' => StaffAccessStatus::Pending->value,
        ]);
    }

    /**
     * Đánh dấu tài khoản nhân sự đã kích hoạt (`StaffAccessStatus::Active`).
     * Lưu ý: Chỉ xác định trạng thái truy cập nhân sự, không cấp vai trò hay phạm vi chi nhánh.
     */
    public function staffActive(): static
    {
        return $this->state(fn (array $attributes) => [
            'staff_access_status' => StaffAccessStatus::Active->value,
        ]);
    }

    /**
     * Đánh dấu tài khoản nhân sự đã bị vô hiệu hóa (`StaffAccessStatus::Inactive`).
     */
    public function staffInactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'staff_access_status' => StaffAccessStatus::Inactive->value,
        ]);
    }
}
