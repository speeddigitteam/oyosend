<?php

declare(strict_types=1);

namespace App\Modules\Ai;

use App\Core\Ai\Contracts\AiTool;
use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Modules\Ai\Actions\AiGateway;
use App\Modules\Ai\Actions\ToolRegistry;
use App\Modules\Ai\Automation\Actions\GenerateTextAction;
use App\Modules\Ai\Automation\Triggers\ProposalConfirmedTrigger;
use App\Modules\Ai\Jobs\EnforceRetention;
use App\Modules\Ai\Models\AutomationActionReceipt;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Models\Message;
use App\Modules\Ai\Models\Proposal;
use App\Modules\Ai\Models\Setting;
use App\Modules\Ai\Providers\FakeAiProvider;
use App\Modules\Ai\Tools\CompaniesSearchTool;
use App\Modules\Ai\Tools\CompanyGetTool;
use App\Modules\Ai\Tools\ContactGetTool;
use App\Modules\Ai\Tools\ContactProposeCreateTool;
use App\Modules\Ai\Tools\ContactProposeUpdateTool;
use App\Modules\Ai\Tools\ContactsSearchTool;
use App\Modules\Ai\Widgets\AiUsageWidget;

/**
 * AI Assistant and AI text automation (category AI Tools), a paid optional app.
 *
 * It depends on `core` only. It reads other modules through `App\Core\Ai\Contracts\AiTool`
 * implementations tagged in the container; the built-in tools wrap the shared contacts and companies
 * directory. It imports no model of another module.
 */
final class AiServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->scoped(ToolRegistry::class);

        // The scripted provider exists only in local development and tests.
        if ($this->app->environment(['local', 'testing'])) {
            $this->app->singleton(FakeAiProvider::class);
        }

        $this->app->tag([
            ContactsSearchTool::class,
            ContactGetTool::class,
            CompaniesSearchTool::class,
            CompanyGetTool::class,
            ContactProposeCreateTool::class,
            ContactProposeUpdateTool::class,
        ], AiTool::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'ai',
            name: 'AI Assistant',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('ai.assistant.use', 'ai_assistant'),
                new PermissionDefinition('ai.drafts.generate', 'ai_assistant'),
                new PermissionDefinition('ai.proposals.confirm', 'ai_assistant', sensitive: true),
                new PermissionDefinition('ai.usage.view', 'ai_usage'),
                new PermissionDefinition('ai.settings.manage', 'ai_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('aiAssistant', 'ai.assistant.index', 'sparkles', 'ai.assistant.use', 'apps', 60, 'ai.assistant.*'),
                new NavigationItem('aiUsage', 'ai.usage.index', 'gauge', 'ai.usage.view', 'apps', 61, 'ai.usage.*'),
                new NavigationItem('aiSettings', 'ai.settings.index', 'settings', 'ai.settings.manage', 'manage', 50, 'ai.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('ai.usage', 'AiUsageWidget', AiUsageWidget::class, 'ai.usage.view', span: 4, order: 19),
            ],
            triggers: [ProposalConfirmedTrigger::class],
            actions: [GenerateTextAction::class],
            morphMap: [
                'ai_setting' => Setting::class,
                'ai_conversation' => Conversation::class,
                'ai_message' => Message::class,
                'ai_proposal' => Proposal::class,
                'ai_automation_receipt' => AutomationActionReceipt::class,
            ],
            limits: [new LimitDefinition(AiGateway::TOKENS)],
            description: 'A private assistant that answers from the records you may see, drafts and summarises text, and proposes changes for you to confirm.',
            category: AppCategory::AiTools,
            icon: 'sparkles',
            featured: false,
            features: [
                'Private assistant chat that only sees what you are allowed to see',
                'Summaries and reply drafts for contacts and companies',
                'Changes are proposals: nothing is applied until you confirm',
                'Monthly token allowance with usage reporting and cost guardrails',
                'Per-workspace switches, data scopes, retention and PII redaction',
            ],
            // Owner decision: placeholder BDT prices (minor units), to be replaced by the product owner.
            offers: [
                new AppOffer('monthly', 'Monthly', AppBillingType::Monthly, 'BDT', 199000),
                new AppOffer('yearly', 'Yearly', AppBillingType::Yearly, 'BDT', 1990000),
                new AppOffer('trial', '14-day trial', AppBillingType::Monthly, 'BDT', 199000, trialDays: 14),
            ],
            releaseNotes: 'Initial AI Assistant release.',
            routes: [
                'ai.assistant.index',
                'ai.assistant.conversations.store',
                'ai.assistant.conversations.clear',
                'ai.assistant.conversations.show',
                'ai.assistant.conversations.messages.store',
                'ai.assistant.conversations.destroy',
                'ai.proposals.confirm',
                'ai.proposals.reject',
                'ai.status',
                'ai.drafts.store',
                'ai.usage.index',
                'ai.settings.index',
                'ai.settings.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('ai.retention', EnforceRetention::class, ScheduledJobFrequency::Daily),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
