<?php

namespace Database\Factories;

use App\Core\Billing\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A paid, public plan sold monthly and yearly, without a trial.
 *
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'key' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'currency' => 'BDT',
            'monthly_amount' => 150000,
            'yearly_amount' => 1500000,
            'trial_days' => 0,
            'modules' => [],
            'limits' => [],
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (): array => ['monthly_amount' => 0, 'yearly_amount' => null]);
    }

    public function withTrial(int $days = 14): static
    {
        return $this->state(fn (): array => ['trial_days' => $days]);
    }

    /**
     * Not offered to workspaces; assigned from the Super Admin panel only.
     */
    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_public' => false]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
