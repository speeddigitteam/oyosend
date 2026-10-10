<?php

namespace Database\Factories;

use App\Core\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Created in the active tenant.
 *
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' branch',
        ];
    }
}
