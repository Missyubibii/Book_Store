<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffProfile>
 */
class StaffProfileFactory extends Factory
{
    /**
     * Định nghĩa hồ sơ nhân sự mặc định gắn với tài khoản nhân sự đang chờ kích hoạt.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->staffPending(),
            'staff_code' => 'NV-'.strtoupper(fake()->unique()->bothify('####??')),
            'job_title' => 'Nhân viên bán hàng',
            'hired_at' => now()->toDateString(),
            'activated_at' => null,
            'suspended_at' => null,
            'notes' => null,
        ];
    }

    /**
     * Đánh dấu hồ sơ nhân sự đã có thời điểm kích hoạt.
     */
    public function activated(): static
    {
        return $this->state(fn (array $attributes) => [
            'activated_at' => now(),
            'suspended_at' => null,
        ]);
    }
}
