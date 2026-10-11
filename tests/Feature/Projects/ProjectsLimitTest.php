<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Enums\ProjectStatus;
use Illuminate\Validation\ValidationException;

it('enforces the active-project plan gauge and frees capacity on cancellation', function () {
    $tenant = $this->createTenant('projects-limit');
    $plan = $this->createFreePlan([
        'key' => 'one-project',
        'modules' => ['projects'],
        'limits' => ['projects.active_projects' => 1],
    ]);
    $this->subscribe($tenant, $plan, withTrial: false);
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $manager = app(ProjectManager::class);
        $first = $manager->create(['name' => 'First project'], $owner, $owner);

        expect(app(UsageLimits::class)->used('projects.active_projects'))->toBe(1)
            ->and(app(UsageLimits::class)->remaining('projects.active_projects'))->toBe(0)
            ->and(fn () => $manager->create(['name' => 'Over the limit'], $owner, $owner))
            ->toThrow(ValidationException::class);

        $manager->changeStatus($first, ProjectStatus::Cancelled, $owner);

        expect(app(UsageLimits::class)->used('projects.active_projects'))->toBe(0)
            ->and($manager->create(['name' => 'Replacement'], $owner, $owner))->not->toBeNull()
            ->and(app(UsageLimits::class)->used('projects.active_projects'))->toBe(1);
    });
});
