<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Message as OutboxMessage;
use App\Modules\WhatsappMarketing\Actions\WindowTracker;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\Window;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-window');
    $this->waConnectCloud($this->tenant, wamid: 'wamid.OUT-W');
    $this->contact = $this->waContact($this->tenant);
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
    $this->waInbound($this->tenant, '+8801711000001', 'Hello, I have a question')->assertOk();
    $this->conversation = $this->inTenant($this->tenant, fn () => Conversation::query()->firstOrFail());
    $this->reply = fn (string $body) => $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/messages"), ['body' => $body]);
});

it('sends a free-form reply inside the 24-hour window, as a service message', function () {
    ($this->reply)('Happy to help')->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'text'
        && $request['text']['body'] === 'Happy to help'
        && $request['to'] === '8801711000001');

    $this->inTenant($this->tenant, function (): void {
        $message = Message::query()->where('direction', Direction::Outbound->value)->firstOrFail();
        $outbox = OutboxMessage::query()->firstOrFail();

        expect($message->body)->toBe('Happy to help')
            ->and($message->status)->toBe(MessageStatus::Sent)
            ->and($outbox->purpose->value)->toBe('transactional')
            ->and($outbox->meta['whatsapp_window_until'])->toBeString()
            ->and($outbox->meta['marketing_module'])->toBe('whatsapp_marketing');
    });
});

it('refuses a free-form reply once the window has closed and sends nothing', function () {
    $this->travel(24)->hours();
    $this->travel(5)->minutes();

    ($this->reply)('Are you still there?')->assertSessionHasErrors('body');

    Http::assertNothingSent();

    $this->inTenant($this->tenant, function (): void {
        expect(Message::query()->where('direction', Direction::Outbound->value)->count())->toBe(0)
            ->and(OutboxMessage::query()->count())->toBe(0);
    });
});

it('still allows a reply just before the window closes', function () {
    $this->travel(23)->hours();
    $this->travel(55)->minutes();

    ($this->reply)('Last minute answer')->assertSessionHasNoErrors();

    Http::assertSentCount(1);
});

it('sends an approved template outside the window to an opted-in customer', function () {
    $this->waOptIn($this->tenant, $this->contact);
    $template = $this->waTemplate($this->tenant, ['name' => 'follow_up', 'category' => 'marketing']);
    $this->travel(30)->hours();

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['Rahim', 'Acme'],
        ])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
        && $request['template']['name'] === 'follow_up'
        && $request['template']['language']['code'] === 'en_US'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'Rahim'
        && $request['template']['components'][0]['parameters'][1]['text'] === 'Acme');

    $this->inTenant($this->tenant, function (): void {
        $outbox = OutboxMessage::query()->firstOrFail();

        expect($outbox->purpose->value)->toBe('marketing')
            ->and(Window::query()->where('origin', 'business')->count())->toBe(1);
    });
});

it('never sends a marketing template without a recorded opt-in', function () {
    $template = $this->waTemplate($this->tenant, ['category' => 'marketing']);
    $this->travel(30)->hours();

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['Rahim', 'Acme'],
        ]);

    Http::assertNothingSent();

    $this->inTenant($this->tenant, function (): void {
        $row = Message::query()->where('direction', Direction::Outbound->value)->firstOrFail();

        expect($row->status)->toBe(MessageStatus::Blocked)
            ->and($row->failure_code)->toBe('no_consent')
            ->and(Window::query()->where('origin', 'business')->count())->toBe(0);
    });
});

it('lets a utility template reach a customer whose service window is open without a marketing opt-in', function () {
    $template = $this->waTemplate($this->tenant, ['category' => 'utility', 'body' => 'Your request {{1}} was received.']);
    $this->travel(2)->hours();

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['#42'],
        ])->assertSessionHasNoErrors();

    Http::assertSentCount(1);

    $this->inTenant($this->tenant, function (): void {
        $outbox = OutboxMessage::query()->firstOrFail();

        expect($outbox->purpose->value)->toBe('transactional')
            ->and($outbox->meta['whatsapp_window_until'])->toBeString();
    });
});

it('treats a utility template as marketing once the service window has closed', function () {
    $template = $this->waTemplate($this->tenant, ['category' => 'utility', 'body' => 'Your request {{1}} was received.']);
    $this->travel(30)->hours();

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['#42'],
        ]);

    // Having written to us once is not consent to marketing: no opt-in, nothing sent.
    Http::assertNothingSent();

    $this->inTenant($this->tenant, function (): void {
        expect(OutboxMessage::query()->firstOrFail()->purpose->value)->toBe('marketing');
    });
});

it('treats a utility template to someone who never wrote to us as marketing', function () {
    $stranger = $this->waContact($this->tenant, ['phone' => '+8801711000555', 'first_name' => 'Karim']);
    $conversation = $this->waConversation($this->tenant, $stranger);
    $template = $this->waTemplate($this->tenant, ['category' => 'utility', 'body' => 'Hello {{1}}']);

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['Karim'],
        ]);

    Http::assertNothingSent();
});

it('refuses a template that is not approved', function () {
    $template = $this->waTemplate($this->tenant, ['status' => 'pending']);

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['A', 'B'],
        ])->assertSessionHasErrors('template_id');

    Http::assertNothingSent();
});

it('refuses a template with the wrong number of variables', function () {
    $this->waOptIn($this->tenant, $this->contact);
    $template = $this->waTemplate($this->tenant);

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['only one'],
        ])->assertSessionHasErrors('variables');

    Http::assertNothingSent();
});

it('says so honestly when the WhatsApp channel is not connected', function () {
    $this->inTenant($this->tenant, fn () => ChannelConnection::query()->update(['is_enabled' => false]));

    ($this->reply)('Hello?')->assertSessionHasErrors('body');

    Http::assertNothingSent();
    $this->inTenant($this->tenant, fn () => expect(Message::query()->where('direction', Direction::Outbound->value)->count())->toBe(0));
});

it('counts conversation windows against the plan limit and stops a new business window at the limit', function () {
    // One window is already used by the customer's own message (set up above); the plan allows two.
    $plan = $this->createFreePlan(['key' => 'two-windows', 'modules' => ['whatsapp_marketing', 'communication'], 'limits' => [WindowTracker::LIMIT => 2]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    $this->waOptIn($this->tenant, $this->contact);
    $template = $this->waTemplate($this->tenant);
    $this->travel(30)->hours();

    $send = fn () => $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/templates"), [
            'template_id' => $template->id,
            'variables' => ['A', 'B'],
        ]);

    $send()->assertSessionHasNoErrors();
    $this->inTenant($this->tenant, fn () => expect(app(UsageLimits::class)->used(WindowTracker::LIMIT))->toBe(2));

    // The window the first template opened is still running: a second template inside it is free.
    $send()->assertSessionHasNoErrors();
    $this->inTenant($this->tenant, fn () => expect(app(UsageLimits::class)->used(WindowTracker::LIMIT))->toBe(2));

    // After that window ended, a new one would exceed the plan.
    $this->travel(25)->hours();
    $send()->assertSessionHasErrors('template_id');
});

it('starts a conversation with an approved template for an opted-in contact', function () {
    $lead = $this->waContact($this->tenant, ['first_name' => 'Lead', 'phone' => '+8801711000777']);
    $this->waOptIn($this->tenant, $lead);
    $template = $this->waTemplate($this->tenant);

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, '/inbox'), ['contact_id' => $lead->id, 'template_id' => $template->id, 'variables' => ['Lead', 'Acme']])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request['to'] === '8801711000777' && $request['type'] === 'template');

    $this->inTenant($this->tenant, function () use ($lead): void {
        $conversation = Conversation::query()->where('phone', '+8801711000777')->firstOrFail();

        expect($conversation->contact_id)->toBe($lead->id)
            ->and($conversation->last_inbound_at)->toBeNull()
            ->and($conversation->messages()->count())->toBe(1);
    });
});

it('leaves no empty conversation behind when the start is refused', function () {
    $lead = $this->waContact($this->tenant, ['first_name' => 'Lead', 'phone' => '+8801711000778']);
    $pending = $this->waTemplate($this->tenant, ['status' => 'pending']);

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, '/inbox'), ['contact_id' => $lead->id, 'template_id' => $pending->id, 'variables' => ['Lead', 'Acme']])
        ->assertSessionHasErrors('template_id');

    $this->inTenant($this->tenant, fn () => expect(Conversation::query()->where('phone', '+8801711000778')->count())->toBe(0));
});
