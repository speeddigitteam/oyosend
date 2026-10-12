<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-access');
    $this->waConnectCloud($this->tenant);
    $this->mine = $this->waContact($this->tenant, ['first_name' => 'Mine', 'phone' => '+8801711000011']);
    $this->theirs = $this->waContact($this->tenant, ['first_name' => 'Theirs', 'phone' => '+8801711000012']);
    $this->free = $this->waContact($this->tenant, ['first_name' => 'Free', 'phone' => '+8801711000013']);

    [$this->agentUser, $this->agent] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply', 'whatsapp_marketing.conversations.assign'], [
        'whatsapp_marketing.conversations.view' => 'own',
        'whatsapp_marketing.conversations.reply' => 'own',
        'whatsapp_marketing.conversations.assign' => 'own',
    ]);
    [$this->otherUser, $this->other] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view'], ['whatsapp_marketing.conversations.view' => 'all']);
    $this->waAgent($this->tenant, $this->agent);
    $this->waAgent($this->tenant, $this->other);

    $this->inTenant($this->tenant, function (): void {
        $manager = app(ConversationManager::class);
        $this->convMine = $manager->forContact($this->mine);
        $this->convTheirs = $manager->forContact($this->theirs);
        $this->convFree = $manager->forContact($this->free);
        $manager->assign($this->convMine, $this->agent);
        $manager->assign($this->convTheirs, $this->other);
    });
    $this->inbox = fn () => $this->waUrl($this->tenant, '/inbox');
});

it('lists only the conversations an own-scoped agent owns, plus the unassigned ones they can pick up', function () {
    $this->actingAsMember($this->tenant, $this->agentUser)
        ->get(($this->inbox)().'?status=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/WhatsappMarketing/Pages/Inbox/Index')
            ->has('conversations.data', 2)
            ->where('counts.mine', 1)
            ->where('counts.unassigned', 1));
});

it('hides unassigned conversations from an own-scoped member who is not an enrolled agent', function () {
    [$plainUser] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view'], ['whatsapp_marketing.conversations.view' => 'own']);

    $this->actingAsMember($this->tenant, $plainUser)->get(($this->inbox)().'?status=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 0));

    $this->actingAsMember($this->tenant, $plainUser)->get(($this->inbox)()."/{$this->convFree->id}")->assertNotFound();
});

it('answers 404 for a conversation outside the member\'s scope and 200 inside it', function () {
    $this->actingAsMember($this->tenant, $this->agentUser)->get(($this->inbox)()."/{$this->convTheirs->id}")->assertNotFound();
    $this->actingAsMember($this->tenant, $this->agentUser)->get(($this->inbox)()."/{$this->convMine->id}")->assertOk();
    $this->actingAsMember($this->tenant, $this->agentUser)->get(($this->inbox)()."/{$this->convFree->id}")->assertOk();
    $this->actingAsMember($this->tenant, $this->otherUser)->get(($this->inbox)()."/{$this->convMine->id}")->assertOk();
});

it('refuses acting on a conversation outside the scope, whatever the route', function () {
    $this->actingAsMember($this->tenant, $this->agentUser)
        ->post(($this->inbox)()."/{$this->convTheirs->id}/messages", ['body' => 'hello'])->assertNotFound();
    $this->actingAsMember($this->tenant, $this->agentUser)
        ->post(($this->inbox)()."/{$this->convTheirs->id}/claim")->assertNotFound();
});

it('forbids a member without the permission', function () {
    [$nobody] = $this->waMember($this->tenant, ['whatsapp_marketing.templates.view']);

    $this->actingAsMember($this->tenant, $nobody)->get(($this->inbox)())->assertForbidden();
    $this->actingAsMember($this->tenant, $nobody)->post(($this->inbox)()."/{$this->convFree->id}/messages", ['body' => 'x'])->assertForbidden();
    $this->actingAsMember($this->tenant, $nobody)->get($this->waUrl($this->tenant, '/campaigns'))->assertForbidden();
    $this->actingAsMember($this->tenant, $nobody)->get($this->waUrl($this->tenant, '/settings'))->assertForbidden();
});

it('lets an agent claim an unassigned conversation but not hand one to a colleague without the all scope', function () {
    $this->actingAsMember($this->tenant, $this->agentUser)
        ->post(($this->inbox)()."/{$this->convFree->id}/claim")->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, fn () => expect($this->convFree->fresh()->owner_id)->toBe($this->agent->id));

    $this->actingAsMember($this->tenant, $this->agentUser)
        ->post(($this->inbox)()."/{$this->convMine->id}/assign", ['assignee_id' => $this->other->id])
        ->assertSessionHasErrors('assignee_id');
});

it('assigns only to enrolled, active agents', function () {
    [$adminUser] = $this->waAdmin($this->tenant);
    [, $notAgent] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    $this->actingAsMember($this->tenant, $adminUser)
        ->post(($this->inbox)()."/{$this->convFree->id}/assign", ['assignee_id' => $notAgent->id])
        ->assertSessionHasErrors('assignee_id');

    $this->actingAsMember($this->tenant, $adminUser)
        ->post(($this->inbox)()."/{$this->convFree->id}/assign", ['assignee_id' => $this->agent->id])
        ->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, fn () => expect($this->convFree->fresh()->owner_id)->toBe($this->agent->id));
});

it('closes, reopens and tags with the manage permission only', function () {
    [$adminUser] = $this->waAdmin($this->tenant);
    $post = fn (string $path, array $data = []) => $this->actingAsMember($this->tenant, $adminUser)->post(($this->inbox)()."/{$this->convFree->id}/".$path, $data);

    $post('status', ['status' => 'closed'])->assertSessionHasNoErrors();
    $this->inTenant($this->tenant, fn () => expect($this->convFree->fresh()->status)->toBe(ConversationStatus::Closed));

    $post('status', ['status' => 'open'])->assertSessionHasNoErrors();
    $post('tags', ['tags' => ['vip', 'bn-speaker']])->assertSessionHasNoErrors();
    $post('tags', ['tags' => ['Not Valid!']])->assertSessionHasErrors('tags');
    $this->inTenant($this->tenant, fn () => expect($this->convFree->fresh()->tags)->toBe(['vip', 'bn-speaker']));

    $this->actingAsMember($this->tenant, $this->agentUser)
        ->post(($this->inbox)()."/{$this->convFree->id}/status", ['status' => 'closed'])
        ->assertForbidden();
});

it('creates a contact from an unknown number and links it, needing the contacts permission', function () {
    $this->waInbound($this->tenant, '+8801999000088', 'Hi', name: 'Walk In')->assertOk();
    $conversation = $this->inTenant($this->tenant, fn () => Conversation::query()->where('phone', '+8801999000088')->firstOrFail());

    [$noContacts] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.manage'], [
        'whatsapp_marketing.conversations.view' => 'all', 'whatsapp_marketing.conversations.manage' => 'all',
    ]);
    $this->actingAsMember($this->tenant, $noContacts)
        ->post(($this->inbox)()."/{$conversation->id}/contact", ['create' => true])->assertForbidden();

    [$adminUser] = $this->waAdmin($this->tenant);
    $this->actingAsMember($this->tenant, $adminUser)
        ->post(($this->inbox)()."/{$conversation->id}/contact", ['create' => true, 'name' => 'Walk In'])
        ->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function () use ($conversation): void {
        $contact = Contact::query()->findOrFail($conversation->fresh()->contact_id);

        expect($contact->phone)->toBe('+8801999000088')->and($contact->first_name)->toBe('Walk In');
    });
});

it('refuses to link a contact whose number does not match the conversation', function () {
    $this->waInbound($this->tenant, '+8801999000089', 'Hi')->assertOk();
    $conversation = $this->inTenant($this->tenant, fn () => Conversation::query()->where('phone', '+8801999000089')->firstOrFail());
    [$adminUser] = $this->waAdmin($this->tenant);

    $this->actingAsMember($this->tenant, $adminUser)
        ->post(($this->inbox)()."/{$conversation->id}/contact", ['contact_id' => $this->free->id])
        ->assertSessionHasErrors('contact_id');
});

it('is not found at all for a workspace without the app', function () {
    $plain = $this->commTenant('wa-access-plain');
    $this->entitle($plain, 'communication');

    // Even the owner: the app is not part of this workspace, so the route does not exist for it.
    $this->actingAsOwner($plain)->get($this->waUrl($plain, '/inbox'))->assertNotFound();
    $this->actingAsOwner($plain)->get($this->waUrl($plain, '/campaigns'))->assertNotFound();
    $this->actingAsOwner($plain)->get($this->waUrl($plain, '/settings'))->assertNotFound();
});

it('is not found from another workspace for every route that takes an id', function () {
    $home = $this->tenant;
    $intruder = $this->waTenant('wa-access-intruder');
    $this->waConnectCloud($intruder);

    $ids = $this->inTenant($home, function () {
        $template = $this->waTemplate($this->tenant);
        $list = $this->waList($this->tenant, [$this->mine]);
        $quick = QuickReply::query()->create(['shortcut' => 'hello', 'title' => 'Hello', 'body' => 'Hi']);
        $flow = Flow::query()->create(['name' => 'F', 'trigger' => 'welcome', 'actions' => [['type' => 'handoff']]]);
        $campaign = Campaign::query()->create([
            'name' => 'C', 'status' => 'draft', 'template_name' => 't', 'template_language' => 'en_US', 'template_category' => 'marketing',
            'template_body' => 'x', 'variable_map' => [], 'total_recipients' => 0,
        ]);

        return [
            'conversation' => $this->convFree->id,
            'template' => $template->id,
            'list' => $list->id,
            'member' => ListMember::query()->where('list_id', $list->id)->firstOrFail()->id,
            'quick' => $quick->id,
            'flow' => $flow->id,
            'campaign' => $campaign->id,
            'agent' => Agent::query()->firstOrFail()->id,
        ];
    });

    $routes = [
        ['GET', "/inbox/{$ids['conversation']}"],
        ['POST', "/inbox/{$ids['conversation']}/read"],
        ['POST', "/inbox/{$ids['conversation']}/messages", ['body' => 'hello']],
        ['POST', "/inbox/{$ids['conversation']}/templates", ['template_id' => $ids['template']]],
        ['POST', "/inbox/{$ids['conversation']}/assign", ['assignee_id' => null]],
        ['POST', "/inbox/{$ids['conversation']}/claim"],
        ['POST', "/inbox/{$ids['conversation']}/status", ['status' => 'closed']],
        ['POST', "/inbox/{$ids['conversation']}/tags", ['tags' => []]],
        ['POST', "/inbox/{$ids['conversation']}/contact", ['create' => false]],
        ['PUT', "/templates/{$ids['template']}", ['category' => 'utility', 'body' => 'Hi {{1}}']],
        ['POST', "/templates/{$ids['template']}/status", ['status' => 'paused']],
        ['DELETE', "/templates/{$ids['template']}"],
        ['PUT', "/quick-replies/{$ids['quick']}", ['shortcut' => 'hello', 'title' => 'Hello', 'body' => 'Hi']],
        ['DELETE', "/quick-replies/{$ids['quick']}"],
        ['GET', "/lists/{$ids['list']}"],
        ['PUT', "/lists/{$ids['list']}", ['name' => 'Renamed']],
        ['POST', "/lists/{$ids['list']}/members", ['contact_ids' => []]],
        ['DELETE', "/lists/{$ids['list']}/members/{$ids['member']}"],
        ['DELETE', "/lists/{$ids['list']}"],
        ['GET', "/campaigns/{$ids['campaign']}"],
        ['GET', "/campaigns/{$ids['campaign']}/edit"],
        ['POST', "/campaigns/{$ids['campaign']}/send"],
        ['POST', "/campaigns/{$ids['campaign']}/cancel"],
        ['DELETE', "/campaigns/{$ids['campaign']}"],
        ['PUT', "/flows/{$ids['flow']}", ['name' => 'F', 'trigger' => 'welcome', 'actions' => [['type' => 'handoff']]]],
        ['DELETE', "/flows/{$ids['flow']}"],
        ['PUT', "/settings/agents/{$ids['agent']}", ['is_available' => false]],
    ];

    foreach ($routes as $route) {
        $this->assertNotFoundFromOtherTenant($route[0], '/whatsapp'.$route[1], $home, $route[2] ?? [], $intruder);
    }
});
