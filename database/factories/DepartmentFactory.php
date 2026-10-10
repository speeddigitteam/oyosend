<?php

namespace Database\Factories;

use App\Core\Organization\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Created in the active tenant.
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()).' team',
        ];
    }
}
