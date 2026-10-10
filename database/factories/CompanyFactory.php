<?php

namespace Database\Factories;

use App\Core\Contacts\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Created in the active tenant.
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'city' => fake()->city(),
            'country' => 'BD',
        ];
    }
}
