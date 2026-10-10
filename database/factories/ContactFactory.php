<?php

namespace Database\Factories;

use App\Core\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Created in the active tenant.
 *
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
        ];
    }
}
