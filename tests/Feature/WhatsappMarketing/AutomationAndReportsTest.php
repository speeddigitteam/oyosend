<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Automation\AutomationRegistry;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportRegistry;
use App\Modules\WhatsappMarketing\Actions\CampaignManager;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Automation\Actions\AssignConversationAction;
use App\Modules\WhatsappMarketing\Automation\Actions\SendTemplateAction;
use App\Modules\WhatsappMarketing\Automation\Actions\TagConversationAction;
use App\Modules\WhatsappMarketing\Jobs\RunMaintenance;
use App\Modules\WhatsappMarketing\Models\AutomationActionReceipt;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Reporting\CampaignDeliveryReport;
use App\Modules\WhatsappMarketing\Reporting\ConversationActivityReport;
use App\Modules\WhatsappMarketing\Widgets\WhatsappOverviewWidget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-auto');
    $this->entitle($this->tenant, 'reports');
    $this->waConnectCloud($this->tenant);
    $this->contact = $this->waContact($this->tenant);
    $this->template = $this->waTemplate($this->tenant, ['name' => 'follow_up']);
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
});

it('registers its triggers and actions with the automation registry', function () {
    $registry = app(AutomationRegistry::class);

    foreach (['message.received', 'message.read', 'conversation.assigned', 'campaign.completed'] as $name) {
        expect($registry->trigger('whatsapp_marketing.'.$name))->not->toBeNull();
    }

    foreach (['template.send', 'conversation.assign', 'conversation.add_tag'] as $name) {
        expect($registry->action('whatsapp_marketing.'.$name))->not->toBeNull();
    }
});

it('sends a template from a workflow once, however often the step is retried', function () {
    $this->waOptIn($this->tenant, $this->contact);
    $input = ['contact_id' => $this->contact->id, 'template_id' => $this->template->id, 'parameters' => ['Rahim', 'Acme']];

    $first = $this->inTenant($this->tenant, fn () => app(SendTemplateAction::class)->execute($input, 'run-1:step-1'));
    $again = $this->inTenant($this->tenant, fn () => app(SendTemplateAction::class)->execute($input, 'run-1:step-1'));

    expect($first['state'])->not->toBe('refused')->and($again)->toBe($first);
    Http::assertSentCount(1);
    $this->inTenant($this->tenant, fn () => expect(AutomationActionReceipt::query()->count())->toBe(1));
});

it('stores a blocked output, not an exception, for a contact without an opt-in', function () {
    $output = $this->inTenant($this->tenant, fn () => app(SendTemplateAction::class)->execute(
        ['contact_id' => $this->contact->id, 'template_id' => $this->template->id, 'parameters' => ['Rahim', 'Acme']],
        'run-2:step-1',
    ));

    expect($output['state'])->toBe('blocked')->and($output['reason'])->toBe('no_consent');
    Http::assertNothingSent();
});

it('refuses with a stored output for an unknown contact, a bad template or a number without a country code', function () {
    $run = fn (array $input, string $key) => $this->inTenant($this->tenant, fn () => app(SendTemplateAction::class)->execute($input, $key));
    $local = $this->waContact($this->tenant, ['phone' => '0212345678']);

    expect($run(['contact_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'template_id' => $this->template->id], 'k1')['reason'])->toBe('unknown_contact')
        ->and($run(['contact_id' => $this->contact->id, 'template_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'], 'k2')['reason'])->toBe('unknown_template')
        ->and($run(['contact_id' => $local->id, 'template_id' => $this->template->id], 'k3')['reason'])->toBe('invalid_phone');

    Http::assertNothingSent();
});

it('assigns and tags a conversation from a workflow, once per step', function () {
    [, $agent] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    $this->waAgent($this->tenant, $agent);
    $conversation = $this->waConversation($this->tenant, $this->contact);

    $assign = fn () => $this->inTenant($this->tenant, fn () => app(AssignConversationAction::class)->execute(
        ['conversation_id' => $conversation->id, 'assignee_id' => $agent->id],
        'run-3:assign',
    ));
    $tag = fn () => $this->inTenant($this->tenant, fn () => app(TagConversationAction::class)->execute(
        ['conversation_id' => $conversation->id, 'tag' => 'vip'],
        'run-3:tag',
    ));

    expect($assign()['owner_id'])->toBe($agent->id)->and($assign())->toBe($assign())
        ->and($tag()['tags'])->toBe(['vip'])->and($tag())->toBe($tag());

    $this->inTenant($this->tenant, fn () => expect(AutomationActionReceipt::query()->count())->toBe(2));
});

it('picks the available agent with the fewest open conversations when none is named', function () {
    [, $busy] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    [, $idle] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    $this->waAgent($this->tenant, $busy);
    $this->waAgent($this->tenant, $idle);
    $other = $this->waContact($this->tenant, ['phone' => '+8801711000088']);

    $this->inTenant($this->tenant, function () use ($busy, $idle, $other): void {
        app(ConversationManager::class)->assign($this->waConversation($this->tenant, $other), $busy);
        $conversation = $this->waConversation($this->tenant, $this->contact);

        $output = app(AssignConversationAction::class)->execute(['conversation_id' => $conversation->id], 'run-4:assign');

        expect($output['owner_id'])->toBe($idle->id);
    });
});

it('is offered to Reports & Analytics with its permission', function () {
    $registry = fn ($user) => $this->inTenant($this->tenant, fn () => collect(app(ReportRegistry::class)->availableFor($user))
        ->map(fn ($provider) => $provider->definition()->key)->all());
    [$viewer] = $this->waMember($this->tenant, ['whatsapp_marketing.analytics.view', 'reports.reports.view']);
    [$none] = $this->waMember($this->tenant, ['reports.reports.view']);

    expect($registry($viewer))->toContain('whatsapp_marketing.campaign_delivery', 'whatsapp_marketing.conversation_activity')
        ->and($registry($none))->not->toContain('whatsapp_marketing.campaign_delivery');
});

it('reports campaign delivery and daily activity from this app\'s own records', function () {
    $this->waOptIn($this->tenant, $this->contact);
    $list = $this->waList($this->tenant, [$this->contact]);
    $this->waInbound($this->tenant, '+8801711000001', 'Hello')->assertOk();

    $this->inTenant($this->tenant, function () use ($list): void {
        $manager = app(CampaignManager::class);
        $campaign = $manager->save(null, [
            'name' => 'Report me', 'template_id' => $this->template->id, 'list_id' => $list->id,
            'variables' => [1 => ['type' => 'text', 'value' => 'A'], 2 => ['type' => 'text', 'value' => 'B']],
        ]);
        $manager->schedule($campaign);

        $user = $this->ownerOf($this->tenant);
        $context = new ReportContext(
            user: $user,
            scope: AccessScope::All,
            from: now()->subDay()->toDateString(),
            to: now()->toDateString(),
            fromAt: CarbonImmutable::now()->subDay(),
            toAt: CarbonImmutable::now()->addMinute(),
            timezone: 'Asia/Dhaka',
            filters: [],
            rowLimit: 100,
        );

        $rows = app(CampaignDeliveryReport::class)->run($context)->rows;
        $days = app(ConversationActivityReport::class)->run($context)->rows;

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['name'])->toBe('Report me')
            ->and($rows[0]['recipients'])->toBe(1)
            ->and($rows[0]['sent'])->toBe(1)
            // One sent, none delivered yet: 0%, and the read rate has nothing to divide.
            ->and($rows[0]['delivered_rate'])->toBe(0)
            ->and($rows[0]['read_rate'])->toBeNull()
            ->and(array_sum(array_column($days, 'inbound')))->toBe(1)
            ->and(array_sum(array_column($days, 'campaign')))->toBe(1)
            ->and(array_sum(array_column($days, 'new_conversations')))->toBe(1);
    });
});

it('shows cost as not reported and counts only what the provider reported as billable', function () {
    $this->waOptIn($this->tenant, $this->contact);
    $list = $this->waList($this->tenant, [$this->contact]);
    $this->inTenant($this->tenant, function () use ($list): void {
        $manager = app(CampaignManager::class);
        $manager->schedule($manager->save(null, [
            'name' => 'Cost', 'template_id' => $this->template->id, 'list_id' => $list->id,
            'variables' => [1 => ['type' => 'text', 'value' => 'A'], 2 => ['type' => 'text', 'value' => 'B']],
        ]));
    });

    $analytics = fn () => $this->actingAsMember($this->tenant, $this->user)
        ->get($this->waUrl($this->tenant, '/analytics?days=7'));

    // Before the provider says anything about billing: not reported, and no cost figure at all.
    $analytics()->assertOk()->assertInertia(fn ($page) => $page
        ->where('overview.cost', null)
        ->where('overview.providerReported.billable', 0)
        ->where('overview.providerReported.notReported', 1));

    $wamid = (string) $this->inTenant($this->tenant, fn () => App\Modules\Communication\Models\Message::query()->value('provider_message_id'));
    $this->waStatus($this->tenant, $wamid, 'delivered', ['pricing' => ['billable' => true, 'category' => 'marketing']])->assertOk();

    $analytics()->assertInertia(fn ($page) => $page
        ->where('overview.cost', null)
        ->where('overview.providerReported.billable', 1)
        ->where('overview.providerReported.notReported', 0)
        ->where('overview.providerReported.byCategory.marketing', 1));
});

it('summarises the inbox for the dashboard within the member\'s scope', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Hello')->assertOk();

    $payload = $this->inTenant($this->tenant, fn () => app(WhatsappOverviewWidget::class)($this->user));

    expect($payload)->toMatchArray(['open' => 1, 'unassigned' => 1, 'mine' => 0, 'unread' => 1, 'connected' => true]);
});

it('forgets a menu answer nobody gave in the daily maintenance', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Hello')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['bot_state' => ['flow_id' => 'x'], 'bot_state_expires_at' => now()->subHour()])->save();

        app()->call([new RunMaintenance, 'handle']);

        expect($conversation->fresh()->bot_state)->toBeNull();
        // Messages and conversations are never deleted by maintenance.
        expect(Message::query()->count())->toBe(1)->and(Campaign::query()->count())->toBe(0);
    });
});
