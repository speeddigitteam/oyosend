<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\InteractionEntry;
use App\Core\Communication\Data\NotificationMessage;
use App\Core\Communication\NullInteractionRecorder;
use App\Core\Communication\NullNotifier;
use App\Core\Identity\Models\User;
use App\Modules\Communication\Actions\NotificationCenter;
use App\Modules\Communication\Actions\TimelineRecorder;
use App\Modules\Communication\Automation\Actions\LogInteractionAction;
use App\Modules\Communication\Automation\Actions\SendTemplateMessageAction;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Models\AutomationActionReceipt;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\MemberNotification;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-auto');
    $this->contact = $this->commContact($this->tenant, ['email' => 'buyer@example.test']);
    $this->template = fn (string $purpose = 'transactional', string $slug = 'order-update') => $this->inTenant($this->tenant, fn () => MessageTemplate::query()->create([
        'slug' => $slug, 'name' => 'Order update', 'channel' => Channel::Email, 'locale' => 'en',
        'purpose' => MessagePurpose::from($purpose), 'subject' => 'Hello {{ contact.first_name }}', 'body' => 'Your order moved.',
    ]));
});

it('registers its triggers and actions with the automation registry', function () {
    $registry = app(AutomationRegistry::class);

    foreach ([
        'communication.message.delivered', 'communication.message.failed',
        'communication.message.bounced', 'communication.contact.unsubscribed',
    ] as $key) {
        expect($registry->trigger($key))->not->toBeNull();
    }

    foreach (['communication.message.send', 'communication.interaction.log'] as $key) {
        expect($registry->action($key))->not->toBeNull();
    }
});

it('binds the core contracts to the module and keeps honest null objects for builds without it', function () {
    expect(app(Notifier::class))->toBeInstanceOf(NotificationCenter::class)
        ->and(app(InteractionRecorder::class))->toBeInstanceOf(TimelineRecorder::class)
        ->and((new NullNotifier)->notify(['x'], new NotificationMessage('system', 'Hi')))->toBe(0)
        ->and((new NullInteractionRecorder)->record(new InteractionEntry('note', 'x', contactId: 'c')))->toBeFalse();
});

it('sends a transactional template once per idempotency key and replays the stored output', function () {
    Mail::fake();
    ($this->template)();
    $run = fn () => $this->inTenant($this->tenant, fn () => app(SendTemplateMessageAction::class)->execute(
        ['template' => 'order-update', 'contact_id' => $this->contact->id], 'run-1:step-2',
    ));

    $first = $run();
    $second = $run();

    expect($first['status'])->toBe('queued')
        ->and($second)->toBe($first)
        ->and($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(1)
        ->and($this->inTenant($this->tenant, fn () => AutomationActionReceipt::query()->count()))->toBe(1);
    Mail::assertSentCount(1);
});

it('refuses to send a marketing template from a workflow', function () {
    Mail::fake();
    ($this->template)('marketing', 'promo');

    $output = $this->inTenant($this->tenant, fn () => app(SendTemplateMessageAction::class)->execute(
        ['template' => 'promo', 'contact_id' => $this->contact->id], 'run-2:step-1',
    ));

    expect($output)->toMatchArray(['status' => 'rejected', 'message_id' => null, 'reason' => 'template_not_transactional']);
    Mail::assertNothingSent();
});

it('reports a missing template, contact or unconfigured channel instead of throwing', function () {
    $send = fn (array $input, string $key) => $this->inTenant($this->tenant, fn () => app(SendTemplateMessageAction::class)->execute($input, $key));
    ($this->template)();

    expect($send(['template' => 'missing', 'contact_id' => $this->contact->id], 'k1')['reason'])->toBe('template_not_found')
        ->and($send(['template' => 'order-update', 'contact_id' => 'nope'], 'k2')['reason'])->toBe('contact_not_found')
        ->and($send(['template' => 'order-update', 'contact_id' => $this->contact->id, 'channel' => 'sms'], 'k3')['reason'])->toBe('template_not_found');
});

it('logs an interaction from a workflow once per idempotency key', function () {
    $run = fn () => $this->inTenant($this->tenant, fn () => app(LogInteractionAction::class)->execute(
        ['contact_id' => $this->contact->id, 'type' => 'note', 'summary' => 'Workflow touched this contact'], 'run-3:step-1',
    ));

    expect($run()['status'])->toBe('logged')
        ->and($run()['status'])->toBe('logged')
        ->and($this->inTenant($this->tenant, fn () => Interaction::query()->count()))->toBe(1);
});

it('notifies members through the core contract, honouring preferences and deduplication', function () {
    [$ann, $annMembership] = $this->commMember($this->tenant, ['communication.notifications.view']);
    [$bo, $boMembership] = $this->commMember($this->tenant, ['communication.notifications.view']);
    $notifier = fn (array $ids, NotificationMessage $message) => $this->inTenant($this->tenant, fn () => app(Notifier::class)->notify($ids, $message));

    // Bo turns the "approvals" category off in the center.
    $this->actingAsMember($this->tenant, $bo)->put($this->commUrl($this->tenant, '/preferences'), [
        'preferences' => [['category' => 'approvals', 'in_app' => false, 'email' => false]],
    ])->assertRedirect();

    $message = new NotificationMessage('approvals', 'Leave request from :name', ['name' => 'Karim'], dedupeKey: 'leave:42');
    $reached = $notifier([$annMembership->id, $boMembership->id], $message);
    $again = $notifier([$annMembership->id], $message);

    expect($reached)->toBe(1)
        ->and($again)->toBe(0)
        ->and($this->inTenant($this->tenant, fn () => MemberNotification::query()->where('membership_id', $annMembership->id)->count()))->toBe(1)
        ->and($this->inTenant($this->tenant, fn () => MemberNotification::query()->where('membership_id', $boMembership->id)->count()))->toBe(0);

    // A critical notification cannot be muted.
    $critical = new NotificationMessage('approvals', 'Account locked', severity: 'critical');
    expect($notifier([$boMembership->id], $critical))->toBe(1);
});

it('emails a member who asked for it, through the outbox in their language', function () {
    Mail::fake();
    [$user, $membership] = $this->commMember($this->tenant, ['communication.notifications.view']);
    $this->inTenant($this->tenant, fn () => User::query()->whereKey($user->id)->update(['locale' => 'bn']));
    $this->actingAsMember($this->tenant, $user)->put($this->commUrl($this->tenant, '/preferences'), [
        'preferences' => [['category' => 'reminders', 'in_app' => true, 'email' => true]],
    ]);

    $reached = $this->inTenant($this->tenant, fn () => app(Notifier::class)->notify(
        [$membership->id], new NotificationMessage('reminders', 'Your task is due', dedupeKey: 'task:1'),
    ));

    $email = $this->inTenant($this->tenant, fn () => Message::query()->where('membership_id', $membership->id)->sole());
    expect($reached)->toBe(1)
        ->and($email->source)->toBe('notification')
        ->and($email->recipient)->toBe($user->email)
        ->and($email->locale)->toBe('bn')
        ->and($email->status->value)->toBe('sent');
});

it('ignores memberships of another workspace and workspaces without the app', function () {
    $other = $this->commTenant('comm-auto-b');
    $foreign = $this->membershipOf($other, $this->ownerOf($other));
    $plain = $this->createTenant('comm-plain');
    $plainOwner = $this->membershipOf($plain, $this->ownerOf($plain));

    $here = $this->inTenant($this->tenant, fn () => app(Notifier::class)->notify([$foreign->id], new NotificationMessage('system', 'Hi')));
    $without = $this->inTenant($plain, fn () => app(Notifier::class)->notify([$plainOwner->id], new NotificationMessage('system', 'Hi')));

    expect($here)->toBe(0)->and($without)->toBe(0);
});
