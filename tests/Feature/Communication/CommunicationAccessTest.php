<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\NotificationMessage;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\InteractionType;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\MemberNotification;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Models\Suppression;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-acc');
    $this->owner = $this->ownerOf($this->tenant);
});

it('answers 404 for every communication page when the workspace is not entitled', function () {
    $plain = $this->createTenant('no-comm');

    foreach (['/notifications', '/messages', '/templates', '/audience', '/settings', '/preferences', '/messages/compose'] as $path) {
        $this->actingAsOwner($plain)->get($this->commUrl($plain, $path))->assertNotFound();
    }

    // The stateless webhooks answer the same way. The unsubscribe page does not: a recipient can always opt
    // out, and it answers "invalid" for any token the workspace did not issue.
    $this->post($this->commUrl($plain, '/webhooks/fake_sms'), [])->assertNotFound();
    $this->get($this->commUrl($plain, '/unsubscribe/'.str_repeat('a', 40)))->assertOk()->assertSee('This link is not valid');
});

it('refuses a member who lacks the permission and serves the owner', function () {
    [$user] = $this->commMember($this->tenant, ['communication.notifications.view']);

    $this->actingAsMember($this->tenant, $user)->get($this->commUrl($this->tenant, '/notifications'))->assertOk();

    foreach (['/messages', '/templates', '/audience', '/settings', '/messages/compose'] as $path) {
        $this->get($this->commUrl($this->tenant, $path))->assertForbidden();
    }

    $this->actingAsOwner($this->tenant)->get($this->commUrl($this->tenant, '/messages'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Communication/Pages/Messages/Index', false) // pages are built by the frontend phase; drop `false` then
            ->has('messages.data', 0)
            ->has('channels', 3));
});

it('treats a signed-out visitor as unauthenticated on every workspace page', function () {
    $this->get($this->commUrl($this->tenant, '/notifications'))->assertRedirect();
});

it('shows a member only their own notifications and 404s anyone else', function () {
    [$alice, $aliceMembership] = $this->commMember($this->tenant, ['communication.notifications.view']);
    [$bob, $bobMembership] = $this->commMember($this->tenant, ['communication.notifications.view']);

    $this->inTenant($this->tenant, function () use ($aliceMembership, $bobMembership): void {
        foreach ([$aliceMembership, $bobMembership] as $membership) {
            MemberNotification::query()->create([
                'membership_id' => $membership->id, 'category' => 'system', 'title_key' => 'Hello :name', 'params' => ['name' => 'X'],
            ]);
        }
    });
    $bobs = $this->inTenant($this->tenant, fn () => MemberNotification::query()->where('membership_id', $bobMembership->id)->sole());

    $this->actingAsMember($this->tenant, $alice)
        ->get($this->commUrl($this->tenant, '/notifications'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.title', 'Hello X')
            ->where('unreadCount', 1));

    $this->post($this->commUrl($this->tenant, "/notifications/{$bobs->id}/read"))->assertNotFound();
    $this->post($this->commUrl($this->tenant, "/notifications/{$bobs->id}/archive"))->assertNotFound();
    expect($this->inTenant($this->tenant, fn () => $bobs->fresh()->read_at))->toBeNull();
});

it('returns a cheap summary for the bell and marks notifications read', function () {
    [$user, $membership] = $this->commMember($this->tenant, ['communication.notifications.view']);
    $this->inTenant($this->tenant, fn () => MemberNotification::query()->create([
        'membership_id' => $membership->id, 'category' => 'approvals', 'title_key' => 'Leave approved', 'link_route' => 'communication.messages.index',
    ]));

    $this->actingAsMember($this->tenant, $user)
        ->getJson($this->commUrl($this->tenant, '/notifications/summary'))
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('items.0.title', 'Leave approved')
        ->assertJsonPath('items.0.url', '/communication/messages');

    $this->post($this->commUrl($this->tenant, '/notifications/read-all'))->assertRedirect();
    $this->getJson($this->commUrl($this->tenant, '/notifications/summary'))->assertJsonPath('unread', 0);
});

it('returns 404 from another workspace for every route that takes an id', function () {
    $contact = $this->commContact($this->tenant);
    $ids = $this->inTenant($this->tenant, function () use ($contact): array {
        $message = Message::query()->create([
            'channel' => Channel::Email, 'purpose' => MessagePurpose::Transactional, 'status' => MessageStatus::Failed,
            'recipient' => 'a@example.test', 'subject' => 'Hi', 'body' => 'b', 'locale' => 'en', 'idempotency_key' => 'k1',
        ]);
        $template = MessageTemplate::query()->create([
            'slug' => 'welcome', 'name' => 'Welcome', 'channel' => Channel::Email, 'locale' => 'en',
            'purpose' => MessagePurpose::Transactional, 'subject' => 'Hi', 'body' => 'Hello',
        ]);
        $interaction = Interaction::query()->create([
            'type' => InteractionType::Note, 'summary' => 'Called', 'occurred_at' => now(), 'contact_id' => $contact->id,
        ]);
        $suppression = Suppression::query()->create([
            'channel' => Channel::Email, 'address' => 'x@example.test', 'scope' => SuppressionScope::All, 'reason' => SuppressionReason::Manual,
        ]);

        return [$message->id, $template->id, $interaction->id, $suppression->id];
    });
    [$message, $template, $interaction, $suppression] = $ids;

    $this->assertNotFoundFromOtherTenant('GET', "/communication/messages/{$message}", $this->tenant);
    $this->assertNotFoundFromOtherTenant('POST', "/communication/messages/{$message}/cancel", $this->tenant);
    $this->assertNotFoundFromOtherTenant('GET', "/communication/contacts/{$contact->id}/timeline", $this->tenant);
    $this->assertNotFoundFromOtherTenant('PUT', "/communication/templates/{$template}", $this->tenant, [
        'slug' => 'welcome', 'name' => 'Welcome', 'channel' => 'email', 'locale' => 'en', 'purpose' => 'transactional',
        'subject' => 'Hi', 'body' => 'Hello', 'is_active' => true,
    ]);
    $this->assertNotFoundFromOtherTenant('DELETE', "/communication/timeline/{$interaction}", $this->tenant);
    $this->assertNotFoundFromOtherTenant('DELETE', "/communication/suppressions/{$suppression}", $this->tenant);
});

it('rejects an in-app link to a route that does not exist', function () {
    [$user, $membership] = $this->commMember($this->tenant, ['communication.notifications.view']);

    $reached = $this->inTenant($this->tenant, fn () => app(Notifier::class)->notify(
        [$membership->id],
        new NotificationMessage('system', 'Look here', linkRoute: 'no.such.route'),
    ));

    expect($reached)->toBe(1)
        ->and($this->inTenant($this->tenant, fn () => MemberNotification::query()->sole()->link_route))->toBeNull();
});
