<?php

declare(strict_types=1);

namespace App\Modules\Automation;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Automation\AutomationRegistry;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Automation\Automation\Triggers\ManualTrigger;
use App\Modules\Automation\Automation\Triggers\ScheduleTrigger;
use App\Modules\Automation\Automation\Triggers\WebhookTrigger;
use App\Modules\Automation\Engine\Outbound\HostResolver;
use App\Modules\Automation\Engine\RunScope;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Jobs\SweepRuns;
use App\Modules\Automation\Listeners\TriggerMap;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Reporting\ReportProviders;

/**
 * Workflow Automation (category Workflow Automation): the central engine that runs versioned JSON workflows
 * from any module's registered triggers and actions. Depends only on `core`; it learns about other modules
 * solely through the automation registry, never by importing their classes.
 *
 * @see docs/WORKFLOWS.md
 */
final class AutomationServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        $this->app->scoped(RunScope::class);
        $this->app->singleton(TriggerMap::class);
        $this->app->bind(HostResolver::class);

        // Follow every trigger's domain event, those registered by modules loaded after this one too.
        $this->callAfterResolving(AutomationRegistry::class, function (AutomationRegistry $registry): void {
            $this->app->make(TriggerMap::class)->follow($registry);
        });
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'automation',
            name: 'Workflow Automation',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('automation.workflows.view', 'automation'),
                new PermissionDefinition('automation.workflows.manage', 'automation', sensitive: true),
                new PermissionDefinition('automation.workflows.publish', 'automation', sensitive: true),
                new PermissionDefinition('automation.runs.view', 'automation'),
                new PermissionDefinition('automation.runs.start', 'automation'),
                new PermissionDefinition('automation.runs.manage', 'automation'),
                new PermissionDefinition('automation.approvals.decide', 'automation_approvals'),
                new PermissionDefinition('automation.webhooks.manage', 'automation', sensitive: true),
            ],
            navigation: [
                new NavigationItem('automationWorkflows', 'automation.workflows.index', 'workflow', 'automation.workflows.view', 'apps', 10, 'automation.workflows.*'),
                new NavigationItem('automationRuns', 'automation.runs.index', 'clock', 'automation.runs.view', 'apps', 11, 'automation.runs.*'),
                new NavigationItem('automationApprovals', 'automation.approvals.index', 'badge-check', 'automation.approvals.decide', 'apps', 12, 'automation.approvals.*'),
                new NavigationItem('automationWebhooks', 'automation.webhooks.index', 'network', 'automation.webhooks.manage', 'apps', 900, 'automation.webhooks.*'),
            ],
            triggers: [ManualTrigger::class, WebhookTrigger::class, ScheduleTrigger::class],
            morphMap: [
                'automation_workflow' => Workflow::class,
                'automation_workflow_version' => WorkflowVersion::class,
                'automation_run' => Run::class,
                'automation_run_step' => RunStep::class,
                'automation_approval' => Approval::class,
                'automation_webhook_endpoint' => WebhookEndpoint::class,
            ],
            limits: [
                new LimitDefinition('automation.runs'),
                new LimitDefinition(
                    'automation.workflows',
                    LimitKind::Gauge,
                    static fn (): int => Workflow::query()->where('status', WorkflowStatus::Active->value)->count(),
                ),
            ],
            description: 'Build automations on a visual canvas: react to what happens in your apps, wait, branch, ask for approval and call your own systems.',
            category: AppCategory::WorkflowAutomation,
            icon: 'workflow',
            featured: false,
            popular: false,
            features: [
                'Versioned workflows: draft, publish, archive; published versions are immutable',
                'Triggers from every installed app, plus schedules, signed webhooks and manual runs',
                'Conditions, branches, loops, parallel steps, delays and declarative data mapping',
                'Human approvals, retries with backoff, error handlers and a test run that changes nothing',
                'Run history with step-level logs, cancel and retry',
            ],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            releaseNotes: 'Initial Workflow Automation release.',
            routes: [
                'automation.workflows.index',
                'automation.workflows.store',
                'automation.workflows.show',
                'automation.workflows.update',
                'automation.workflows.editor',
                'automation.workflows.draft',
                'automation.workflows.validate',
                'automation.workflows.publish',
                'automation.workflows.pause',
                'automation.workflows.resume',
                'automation.workflows.archive',
                'automation.workflows.duplicate',
                'automation.workflows.versions.restore',
                'automation.workflows.webhook.rotate',
                'automation.workflows.test',
                'automation.workflows.run',
                'automation.runs.index',
                'automation.runs.show',
                'automation.runs.cancel',
                'automation.runs.retry',
                'automation.approvals.index',
                'automation.approvals.decide',
                'automation.webhooks.index',
                'automation.webhooks.store',
                'automation.webhooks.update',
                'automation.webhooks.destroy',
                'automation.hooks.receive',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('automation.sweep', SweepRuns::class, ScheduledJobFrequency::EveryMinute),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');

        // Inbound webhooks: tenant resolution, a throttle and the entitlement; nothing stateful.
        $this->loadTenantCallbackRoutes(__DIR__.'/routes-callbacks.php');
    }
}
