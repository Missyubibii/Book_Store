<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Enums\BranchType;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Định nghĩa dữ liệu mặc định cho một chi nhánh bán lẻ đang hoạt động.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'CN-'.strtoupper(fake()->unique()->bothify('???##')),
            'name' => 'Chi nhánh '.fake()->city(),
            'type' => BranchType::RetailBranch->value,
            'status' => BranchStatus::Active->value,
            'address' => fake()->address(),
            'phone' => fake()->numerify('09########'),
        ];
    }

    /**
     * Chi nhánh bán lẻ (`BranchType::RetailBranch`).
     */
    public function retailBranch(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BranchType::RetailBranch->value,
        ]);
    }

    /**
     * Kho trung tâm duy nhất (`BranchType::CentralWarehouse`, luôn `Active`).
     */
    public function centralWarehouse(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'CENTRAL_WH',
            'name' => 'Kho Trung tâm Book_Store',
            'type' => BranchType::CentralWarehouse->value,
            'status' => BranchStatus::Active->value,
        ]);
    }

    /**
     * Chi nhánh tạm ngừng hoạt động (`BranchStatus::Inactive`).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BranchStatus::Inactive->value,
        ]);
    }
}
