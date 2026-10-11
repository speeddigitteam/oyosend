<?php

declare(strict_types=1);

use App\Modules\Projects\Models\ProjectTemplate;
use App\Modules\Projects\Models\Task;

it('normalizes string task estimates before templates copy them to project tasks', function () {
    $tenant = $this->createTenant('projects-settings-http');
    $plan = $this->createFreePlan([
        'key' => 'projects-settings-http',
        'modules' => ['projects'],
    ]);
    $this->subscribe($tenant, $plan, withTrial: false);

    $this->actingAsOwner($tenant)
        ->post($this->tenantUrl($tenant, '/project-settings/templates'), [
            'name' => 'Estimated delivery',
            'task_blueprints' => [[
                'title' => 'Planned task',
                'priority' => 'normal',
                'estimated_minutes' => '135',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template = $this->inTenant(
        $tenant,
        fn (): ProjectTemplate => ProjectTemplate::query()->where('name', 'Estimated delivery')->sole(),
    );

    expect($template->task_blueprints[0]['estimated_minutes'])->toBe(135);

    $this->post($this->tenantUrl($tenant, '/projects'), [
        'name' => 'Template project',
        'template_id' => $template->id,
        'priority' => 'normal',
        'budget_amount' => '0',
    ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task = $this->inTenant(
        $tenant,
        fn (): Task => Task::query()->where('title', 'Planned task')->sole(),
    );

    expect($task->estimated_minutes)->toBe(135);
});
