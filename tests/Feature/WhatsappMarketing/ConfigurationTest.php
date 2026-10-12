<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\WhatsappMarketing\Actions\AgentManager;
use App\Modules\WhatsappMarketing\Actions\ListManager;
use App\Modules\WhatsappMarketing\Actions\WhatsappContactMergeReference;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Models\Setting;
use App\Modules\WhatsappMarketing\Models\Template;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-config');
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
    $this->as = fn () => $this->actingAsMember($this->tenant, $this->user);
});

it('saves a template as the provider describes it and only that', function () {
    ($this->as)()->post($this->waUrl($this->tenant, '/templates'), [
        'name' => 'order_shipped',
        'language' => 'en_US',
        'category' => 'utility',
        'header' => 'Your order',
        'body' => 'Hi {{1}}, order {{2}} has shipped.',
        'footer' => 'Acme',
        'buttons' => [
            ['type' => 'quick_reply', 'text' => 'Thanks'],
            ['type' => 'url', 'text' => 'Track', 'value' => 'https://acme.example/track'],
        ],
        'status' => 'approved',
    ])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        $template = Template::query()->where('name', 'order_shipped')->firstOrFail();

        expect($template->variable_count)->toBe(2)
            ->and($template->status)->toBe(TemplateStatus::Approved)
            ->and($template->status_source->value)->toBe('admin')
            ->and(array_column($template->components, 'type'))->toBe(['HEADER', 'BODY', 'FOOTER', 'BUTTONS'])
            ->and($template->components[3]['buttons'][1])->toEqual(['type' => 'URL', 'text' => 'Track', 'url' => 'https://acme.example/track']);
    });
});

it('rejects templates the provider would not accept', function () {
    $post = fn (array $payload) => ($this->as)()->post($this->waUrl($this->tenant, '/templates'), [
        'name' => 'bad_one', 'language' => 'en_US', 'category' => 'marketing', 'body' => 'Hello', ...$payload,
    ]);

    $post(['body' => 'Hi {{1}} and {{3}}'])->assertSessionHasErrors('body');
    $post(['header' => 'Hi {{1}}'])->assertSessionHasErrors('header');
    $post(['name' => 'Has Spaces'])->assertSessionHasErrors('name');
    $post(['language' => 'english'])->assertSessionHasErrors('language');
    $post(['buttons' => [['type' => 'url', 'text' => 'Go', 'value' => 'http://insecure.example/{{1}}']]])->assertSessionHasErrors();
    $post(['buttons' => [['type' => 'phone_number', 'text' => 'Call', 'value' => '01711000000']]])->assertSessionHasErrors();

    $this->inTenant($this->tenant, fn () => expect(Template::query()->count())->toBe(0));
});

it('refuses a duplicate name in the same language but allows another language', function () {
    $this->waTemplate($this->tenant, ['name' => 'welcome', 'language' => 'en_US']);

    ($this->as)()->post($this->waUrl($this->tenant, '/templates'), [
        'name' => 'welcome', 'language' => 'en_US', 'category' => 'marketing', 'body' => 'Hi',
    ])->assertSessionHasErrors('name');

    ($this->as)()->post($this->waUrl($this->tenant, '/templates'), [
        'name' => 'welcome', 'language' => 'bn', 'category' => 'marketing', 'body' => 'হ্যালো',
    ])->assertSessionHasNoErrors();
});

it('lets an administrator record a status and keeps a template that a live campaign uses', function () {
    $template = $this->waTemplate($this->tenant, ['status' => 'pending']);

    ($this->as)()->post($this->waUrl($this->tenant, "/templates/{$template->id}/status"), ['status' => 'approved', 'status_reason' => 'Approved in WhatsApp Manager'])
        ->assertSessionHasNoErrors();
    $this->inTenant($this->tenant, fn () => expect($template->fresh()->status)->toBe(TemplateStatus::Approved)
        ->and($template->fresh()->status_reason)->toBe('Approved in WhatsApp Manager'));

    $this->inTenant($this->tenant, fn () => Campaign::query()->create([
        'name' => 'Uses it', 'status' => 'draft', 'template_id' => $template->id, 'template_name' => $template->name,
        'template_language' => 'en_US', 'template_category' => 'marketing', 'template_body' => 'x', 'variable_map' => [], 'total_recipients' => 0,
    ]));

    ($this->as)()->delete($this->waUrl($this->tenant, "/templates/{$template->id}"))->assertSessionHasErrors('template');
    $this->inTenant($this->tenant, fn () => expect(Template::query()->count())->toBe(1));
});

it('keeps quick replies to the allow-listed placeholders and unique shortcuts', function () {
    $post = fn (array $payload) => ($this->as)()->post($this->waUrl($this->tenant, '/quick-replies'), ['title' => 'Greeting', ...$payload]);

    $post(['shortcut' => 'hello', 'body' => 'Hi {{contact.first_name}}, this is {{agent.name}} from {{workspace.name}}.'])->assertSessionHasNoErrors();
    $post(['shortcut' => 'hello', 'body' => 'again'])->assertSessionHasErrors('shortcut');
    $post(['shortcut' => 'secrets', 'body' => 'Token {{user.api_token}}'])->assertSessionHasErrors('body');
    $post(['shortcut' => 'Bad Shortcut!', 'body' => 'x'])->assertSessionHasErrors('shortcut');

    $this->inTenant($this->tenant, fn () => expect(QuickReply::query()->count())->toBe(1));
});

it('shows lists with each member\'s opt-in answer and number validity, from the platform', function () {
    $this->waConnectFake($this->tenant);
    $this->entitle($this->tenant, 'communication');
    $optedIn = $this->waContact($this->tenant, ['first_name' => 'Opted', 'phone' => '+8801711000021']);
    $this->waOptIn($this->tenant, $optedIn);
    $silent = $this->waContact($this->tenant, ['first_name' => 'Silent', 'phone' => '+8801711000022']);
    $local = $this->waContact($this->tenant, ['first_name' => 'Local', 'phone' => '01711000023']);
    $list = $this->waList($this->tenant, [$optedIn, $silent, $local]);

    ($this->as)()->get($this->waUrl($this->tenant, "/lists/{$list->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/WhatsappMarketing/Pages/Lists/Show')
            ->where('list.memberCount', 3)
            ->has('members.data', 3)
            ->where('members.data', function ($members): bool {
                $byName = collect($members)->keyBy(fn (array $member): string => explode(' ', (string) $member['name'])[0]);

                return $byName['Opted']['canReceive'] === true
                    && $byName['Silent']['canReceive'] === false && $byName['Silent']['reason'] === 'no_consent'
                    && $byName['Local']['validPhone'] === false;
            }));
});

it('adds only contacts the member may see, once each, within the list cap', function () {
    config(['whatsapp_marketing.max_list_members' => 3]);
    [$scoped, $scopedMembership] = $this->waMember($this->tenant, [
        'whatsapp_marketing.lists.view', 'whatsapp_marketing.lists.manage', 'core.contacts.view',
    ], ['core.contacts.view' => 'own']);
    $mine = $this->waContact($this->tenant, ['first_name' => 'Mine', 'phone' => '+8801711000031'], $scopedMembership);
    $theirs = $this->waContact($this->tenant, ['first_name' => 'Theirs', 'phone' => '+8801711000032']);
    $list = $this->waList($this->tenant, []);

    $this->actingAsMember($this->tenant, $scoped)
        ->post($this->waUrl($this->tenant, "/lists/{$list->id}/members"), ['contact_ids' => [$mine->id, $mine->id, $theirs->id]])
        ->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, fn () => expect(ListMember::query()->where('list_id', $list->id)->pluck('contact_id')->all())->toBe([$mine->id]));

    // Everything the admin sees, bounded by the cap of three.
    foreach (range(1, 5) as $n) {
        $this->waContact($this->tenant, ['first_name' => "Extra{$n}", 'phone' => '+880171100004'.$n]);
    }

    ($this->as)()->post($this->waUrl($this->tenant, "/lists/{$list->id}/members/all"))->assertSessionHasNoErrors();
    $this->inTenant($this->tenant, fn () => expect(app(ListManager::class)->count($list))->toBe(3));
});

it('rejects a name that another list already has', function () {
    ($this->as)()->post($this->waUrl($this->tenant, '/lists'), ['name' => 'VIP'])->assertSessionHasNoErrors();
    ($this->as)()->post($this->waUrl($this->tenant, '/lists'), ['name' => 'VIP'])->assertSessionHasErrors('name');
});

it('saves settings, never takes a credential and rejects a national number as the display number', function () {
    ($this->as)()->put($this->waUrl($this->tenant, '/settings'), [
        'business_name' => 'Acme Ltd', 'display_phone' => '+880 1700-000000', 'auto_assign' => true,
        'auto_create_contacts' => false, 'bots_enabled' => true,
    ])->assertSessionHasNoErrors();

    ($this->as)()->put($this->waUrl($this->tenant, '/settings'), [
        'business_name' => 'Acme Ltd', 'display_phone' => '01700000000', 'auto_assign' => true,
        'auto_create_contacts' => false, 'bots_enabled' => true,
    ])->assertSessionHasErrors('display_phone');

    $this->inTenant($this->tenant, function (): void {
        $setting = Setting::query()->firstOrFail();

        expect($setting->display_phone)->toBe('+8801700000000')
            ->and($setting->auto_assign)->toBeTrue()
            ->and(array_keys($setting->getAttributes()))->not->toContain('access_token', 'secret', 'token');
    });
});

it('shows the connection state honestly and never a secret', function () {
    // No channel connected: not configured.
    ($this->as)()->get($this->waUrl($this->tenant, '/settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/WhatsappMarketing/Pages/Settings/Index')
            ->where('channel.state', 'not_configured')
            ->where('channel.testDouble', false));

    $this->waConnectCloud($this->tenant);

    $response = ($this->as)()->get($this->waUrl($this->tenant, '/settings'))->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('channel.state', 'available'));

    expect($response->getContent())->not->toContain('EAAtest-access-token')->not->toContain('app-secret-test')->not->toContain('verify-me');
});

it('counts active agents against the plan limit and frees a seat when one is deactivated', function () {
    $plan = $this->createFreePlan(['key' => 'two-agents', 'modules' => ['whatsapp_marketing', 'communication'], 'limits' => [AgentManager::LIMIT => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    [, $first] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    [, $second] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);

    $agent = $this->waAgent($this->tenant, $first);
    expect(fn () => $this->waAgent($this->tenant, $second))->toThrow(ValidationException::class);

    $this->inTenant($this->tenant, fn () => app(AgentManager::class)->update($agent, ['is_active' => false]));
    $this->waAgent($this->tenant, $second);

    // Reactivating the first now would exceed the plan.
    expect(fn () => $this->inTenant($this->tenant, fn () => app(AgentManager::class)->update($agent, ['is_active' => true])))
        ->toThrow(ValidationException::class);
});

it('moves this app\'s references to the canonical contact when contacts are merged', function () {
    $canonical = $this->waContact($this->tenant, ['first_name' => 'Keep', 'phone' => '+8801711000061']);
    $duplicate = $this->waContact($this->tenant, ['first_name' => 'Dup', 'phone' => '+8801711000062']);
    $list = $this->waList($this->tenant, [$canonical, $duplicate]);
    $other = $this->waList($this->tenant, [$duplicate], 'Other');
    $conversation = $this->waConversation($this->tenant, $duplicate);

    $moved = $this->inTenant($this->tenant, fn () => app(WhatsappContactMergeReference::class)
        ->merge($this->tenant->getTenantKey(), $canonical->id, $duplicate->id));

    $this->inTenant($this->tenant, function () use ($canonical, $duplicate, $list, $other, $conversation): void {
        expect(ListMember::query()->where('list_id', $list->id)->pluck('contact_id')->all())->toBe([$canonical->id])
            ->and(ListMember::query()->where('list_id', $other->id)->pluck('contact_id')->all())->toBe([$canonical->id])
            ->and($conversation->fresh()->contact_id)->toBe($canonical->id)
            ->and(Contact::query()->find($duplicate->id))->not->toBeNull();
    });

    expect($moved)->toHaveKey('whatsapp_conversations');
});

it('renders every page of the app', function () {
    $template = $this->waTemplate($this->tenant);
    $list = $this->waList($this->tenant, [$this->waContact($this->tenant)]);
    $conversation = $this->waConversation($this->tenant, $this->waContact($this->tenant, ['phone' => '+8801711000071']));
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->create([
        'name' => 'Page', 'status' => 'draft', 'template_id' => $template->id, 'template_name' => $template->name, 'template_language' => 'en_US',
        'template_category' => 'marketing', 'template_body' => 'x', 'variable_map' => [], 'total_recipients' => 0,
    ]));

    $pages = [
        '/inbox' => 'Inbox/Index',
        "/inbox/{$conversation->id}" => 'Inbox/Show',
        '/templates' => 'Templates/Index',
        '/quick-replies' => 'QuickReplies/Index',
        '/lists' => 'Lists/Index',
        "/lists/{$list->id}" => 'Lists/Show',
        '/campaigns' => 'Campaigns/Index',
        '/campaigns/create' => 'Campaigns/Form',
        "/campaigns/{$campaign->id}" => 'Campaigns/Show',
        "/campaigns/{$campaign->id}/edit" => 'Campaigns/Form',
        '/flows' => 'Flows/Index',
        '/analytics' => 'Analytics/Index',
        '/settings' => 'Settings/Index',
    ];

    foreach ($pages as $path => $component) {
        ($this->as)()->get($this->waUrl($this->tenant, $path))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Modules/WhatsappMarketing/Pages/'.$component));
    }
});
