<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Comments\Actions\CommentSources;
use App\Modules\Social\Comments\Models\PageClaim;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Messages\Actions\MessagingSetup;
use App\Modules\Social\Messages\Inbox\InstagramInboxSource;
use App\Modules\Social\Messages\Inbox\MessengerInboxSource;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Social\Messages\Models\DirectMessage;
use App\Modules\Social\Messages\Models\MessagingSetting;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Adapters\FacebookPagesPlatform;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;

/*
| Facebook Messenger and Instagram direct messages (ADR 0031): the one signed Facebook webhook routes `messaging`
| events by the Page claim, the Social Messages page and the unified inbox read and answer them through one action,
| inside the 24-hour window. Every Meta call is faked; nothing here has run against Meta's live API.
*/

uses(InteractsWithSocial::class);

const DM_SECRET = 'app-secret-for-dm-tests';

const DM_SCOPES = ['pages_show_list', 'pages_messaging', 'pages_manage_metadata', 'instagram_basic', 'instagram_manage_messages'];

beforeEach(function () {
    config([
        'social.platforms.facebook.client_id' => 'app-id',
        'social.platforms.facebook.client_secret' => DM_SECRET,
        'social_comments.enabled' => true,
        'social_comments.facebook.verify_token' => 'verify-me',
        'social.messaging.enabled' => true,
        'social.messaging.instagram' => true,
    ]);

    $this->tenant = $this->socialTenant('dm-one');
    $this->account = $this->socialAccount($this->tenant, [
        'platform' => 'facebook', 'external_id' => '1000', 'name' => 'Brand Page',
        'access_token' => 'tok-page-1000', 'refresh_token' => null, 'token_expires_at' => null,
    ]);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->central = 'http://'.config('platform.domain').'/social/webhooks/facebook';

    // Meta as the documented calls answer it, registered once; `meta()` changes the answers and clears the recorded
    // requests. `subscribed` is what the app already receives for the Page.
    $this->metaState = ['scopes' => DM_SCOPES, 'subscribed' => [], 'instagram' => '17841400000001', 'send' => null, 'status' => 200];
    Http::fake(function (Request $request) {
        $state = $this->metaState;
        $url = $request->url();

        return match (true) {
            str_contains($url, '/debug_token') => Http::response(['data' => ['is_valid' => true, 'scopes' => $state['scopes']]]),
            str_contains($url, '/subscribed_apps') && $request->method() === 'GET' => Http::response(['data' => [['id' => 'app-id', 'name' => 'Oyosend', 'subscribed_fields' => $state['subscribed']]]]),
            str_contains($url, '/subscribed_apps') => Http::response(['success' => true]),
            str_contains($url, 'fields=instagram_business_account') => Http::response($state['instagram'] === null ? ['id' => '1000'] : ['instagram_business_account' => ['id' => $state['instagram']], 'id' => '1000']),
            str_contains($url, '/messages') => Http::response($state['send'] ?? ['recipient_id' => '5551234', 'message_id' => 'm_sent_1'], $state['status']),
            default => Http::response(['error' => ['code' => 100, 'message' => 'Unexpected call']], 400),
        };
    });
    $this->meta = function (array $scopes = DM_SCOPES, array $subscribed = [], ?string $instagram = '17841400000001', ?array $send = null, int $sendStatus = 200): void {
        $this->metaState = ['scopes' => $scopes, 'subscribed' => $subscribed, 'instagram' => $instagram, 'send' => $send, 'status' => $sendStatus];
        Http::fake([]);
    };
    $this->enable = function (?SocialAccount $account = null, array $scopes = DM_SCOPES, array $subscribed = [], ?string $instagram = '17841400000001'): MessagingSetting {
        ($this->meta)($scopes, $subscribed, $instagram);

        return $this->inTenant($this->tenant, fn (): MessagingSetting => app(MessagingSetup::class)->enable($account ?? $this->account));
    };
    $this->deliver = function (array $messaging, string $object = 'page', string $entry = '1000', ?string $secret = DM_SECRET, ?string $url = null) {
        $body = json_encode(['object' => $object, 'entry' => [['id' => $entry, 'time' => 1, 'messaging' => $messaging]]], JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', $url ?? ($this->url)('/webhooks/facebook'), [], [], [], $server, $body);
    };
    $this->inbound = fn (string $mid, string $text = 'Is the blue one in stock?', string $from = '5551234', string $to = '1000', ?int $at = null): array => [
        'sender' => ['id' => $from], 'recipient' => ['id' => $to], 'timestamp' => $at ?? now()->getTimestampMs(),
        'message' => ['mid' => $mid, 'text' => $text],
    ];
    $this->conversations = fn () => $this->inTenant($this->tenant, fn () => DirectConversation::query()->orderBy('platform')->get());
    $this->messages = fn () => $this->inTenant($this->tenant, fn () => DirectMessage::query()->orderBy('sent_at')->orderBy('id')->get());
    $this->member = fn (array $permissions, array $scopes = []): array => $this->socialMember($this->tenant, $permissions, $scopes);
    $this->conversation = function (array $attributes = []): DirectConversation {
        return $this->inTenant($this->tenant, function () use ($attributes): DirectConversation {
            $conversation = new DirectConversation([
                'social_account_id' => $this->account->id, 'platform' => 'messenger', 'participant_id' => '5551234',
                'last_message_preview' => 'Is the blue one in stock?', 'last_message_at' => now()->subMinutes(10),
                'last_inbound_at' => now()->subMinutes(10), 'unread_count' => 1, 'status' => 'open', ...$attributes,
            ]);
            $conversation->keepUnowned()->save();
            DirectMessage::query()->create([
                'conversation_id' => $conversation->id, 'direction' => 'in', 'provider_message_id' => 'm_in_'.$conversation->participant_id,
                'text' => 'Is the blue one in stock?', 'status' => 'received', 'sent_at' => $conversation->last_inbound_at ?? now()->subMinutes(10),
            ]);

            return $conversation;
        });
    };
});

// ---- webhook -----------------------------------------------------------------------------------------------------

it('rejects an unsigned or wrongly signed messaging delivery and stores nothing', function () {
    ($this->enable)();

    ($this->deliver)([($this->inbound)('m_1')], secret: null)->assertStatus(401);
    ($this->deliver)([($this->inbound)('m_1')], secret: 'another-secret')->assertStatus(401);
    ($this->deliver)([($this->inbound)('m_1')], secret: 'another-secret', url: $this->central)->assertStatus(401);

    expect(($this->conversations)())->toHaveCount(0)->and(($this->messages)())->toHaveCount(0);
});

it('ignores a Page nobody claimed and a Page another workspace claimed', function () {
    // Nobody switched capture on: nothing is claimed.
    ($this->deliver)([($this->inbound)('m_1')])->assertOk();
    ($this->deliver)([($this->inbound)('m_2')], url: $this->central)->assertOk();
    expect(($this->conversations)())->toHaveCount(0);

    // Another workspace switches capture on for its own Page.
    $other = $this->socialTenant('dm-two');
    $theirs = $this->socialAccount($other, ['platform' => 'facebook', 'external_id' => '2000', 'name' => 'Their Page', 'access_token' => 'tok-2000', 'refresh_token' => null, 'token_expires_at' => null]);
    ($this->meta)();
    $this->inTenant($other, fn () => app(MessagingSetup::class)->enable($theirs));

    // Their Page's message sent to this workspace's host is ignored, without a hint.
    ($this->deliver)([($this->inbound)('m_3', to: '2000')], entry: '2000')->assertOk()->assertSee('OK');
    expect(($this->conversations)())->toHaveCount(0);
    $this->inTenant($other, fn () => expect(DirectConversation::query()->count())->toBe(0));

    // The central host routes it to the workspace that claimed the Page.
    ($this->deliver)([($this->inbound)('m_4', to: '2000')], entry: '2000', url: $this->central)->assertOk();
    $this->inTenant($other, fn () => expect(DirectConversation::query()->sole()->social_account_id)->toBe($theirs->id));

    // A second workspace cannot take a Page that is claimed.
    $third = $this->socialTenant('dm-three');
    $same = $this->socialAccount($third, ['platform' => 'facebook', 'external_id' => '2000', 'name' => 'Same Page', 'access_token' => 'tok-x', 'refresh_token' => null]);
    $this->inTenant($third, fn () => expect(fn () => app(MessagingSetup::class)->enable($same))->toThrow(ValidationException::class));
    expect(PageClaim::query()->where('external_id', '2000')->sole()->tenant_id)->toBe($other->id);
});

it('stores a Messenger message as a conversation once, and an echo from the Page as outgoing', function () {
    ($this->enable)();
    $at = now()->subMinutes(5)->startOfSecond();

    ($this->deliver)([($this->inbound)('m_1', "Price please\x00 <b>now</b>", at: $at->getTimestampMs())])->assertOk();
    ($this->deliver)([($this->inbound)('m_1', "Price please\x00 <b>now</b>", at: $at->getTimestampMs())])->assertOk();
    ($this->deliver)([[
        'sender' => ['id' => '5551234'], 'recipient' => ['id' => '1000'], 'timestamp' => now()->getTimestampMs(),
        'message' => ['mid' => 'm_2', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://example.test/secret.jpg']]]],
    ]])->assertOk();
    // Sent by the Page from Meta Business Suite: `is_echo`, from the Page to the person.
    ($this->deliver)([[
        'sender' => ['id' => '1000'], 'recipient' => ['id' => '5551234'], 'timestamp' => now()->addSecond()->getTimestampMs(),
        'message' => ['mid' => 'm_3', 'is_echo' => true, 'app_id' => 99, 'text' => 'Yes, in stock.'],
    ]])->assertOk();

    $conversation = ($this->conversations)()->sole();
    $messages = ($this->messages)();

    expect($conversation->platform)->toBe('messenger')
        ->and($conversation->participant_id)->toBe('5551234')
        ->and($conversation->participant_name)->toBeNull()
        ->and($conversation->displayName())->toBe('Messenger user ••1234')
        ->and($conversation->unread_count)->toBe(0)
        ->and($conversation->last_inbound_at)->not->toBeNull()
        ->and($conversation->last_message_preview)->toBe('Yes, in stock.')
        ->and($conversation->owner_id)->toBeNull()
        ->and($messages)->toHaveCount(3)
        ->and($messages[0]->direction)->toBe('in')->and($messages[0]->text)->toBe('Price please <b>now</b>')
        ->and($messages[1]->attachment_type)->toBe('image')->and($messages[1]->text)->toBeNull()
        ->and($messages[2]->direction)->toBe('out')->and($messages[2]->status)->toBe('sent')->and($messages[2]->provider_message_id)->toBe('m_3');

    // The attachment's file was never fetched.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'example.test'));
});

it('counts unread messages and updates delivery and read receipts of the Page\'s messages', function () {
    ($this->enable)();
    $start = now()->subMinutes(30);

    ($this->deliver)([($this->inbound)('m_1', at: $start->getTimestampMs()), ($this->inbound)('m_2', 'Hello?', at: $start->copy()->addMinute()->getTimestampMs())])->assertOk();
    expect(($this->conversations)()->sole()->unread_count)->toBe(2);

    ($this->deliver)([[
        'sender' => ['id' => '1000'], 'recipient' => ['id' => '5551234'], 'timestamp' => $start->copy()->addMinutes(2)->getTimestampMs(),
        'message' => ['mid' => 'm_3', 'is_echo' => true, 'text' => 'Hi!'],
    ]]);
    ($this->deliver)([['sender' => ['id' => '5551234'], 'recipient' => ['id' => '1000'], 'timestamp' => now()->getTimestampMs(), 'delivery' => ['mids' => ['m_3'], 'watermark' => now()->getTimestampMs()]]]);
    expect(($this->messages)()->firstWhere('provider_message_id', 'm_3')->status)->toBe('delivered');

    ($this->deliver)([['sender' => ['id' => '5551234'], 'recipient' => ['id' => '1000'], 'timestamp' => now()->getTimestampMs(), 'read' => ['watermark' => now()->getTimestampMs()]]]);
    expect(($this->messages)()->firstWhere('provider_message_id', 'm_3')->status)->toBe('read');
});

it('routes an Instagram message through the Instagram account linked to the claimed Page', function () {
    $setting = ($this->enable)();
    expect($setting->instagram_state)->toBe('on')->and($setting->instagram_account_id)->toBe('17841400000001')
        ->and(PageClaim::query()->where('account_id', $this->account->id)->sole()->instagram_id)->toBe('17841400000001');

    $igMessage = ($this->inbound)('aWdfZAG1faXRlbToxOklHTWVzc2FnZA==', 'Do you ship to Sylhet?', '9990001', '17841400000001');

    ($this->deliver)([$igMessage], 'instagram', '17841400000001', url: $this->central)->assertOk();
    // An Instagram account nobody linked is ignored without a hint.
    ($this->deliver)([($this->inbound)('ig_other', 'Hi', '9990002', '17841499999999')], 'instagram', '17841499999999', url: $this->central)->assertOk();
    // A Page-object event naming the Instagram id is not an Instagram event.
    ($this->deliver)([($this->inbound)('ig_wrong', 'Hi', '9990003', '17841400000001')], 'page', '17841400000001')->assertOk();

    $conversation = ($this->conversations)()->sole();
    expect($conversation->platform)->toBe('instagram')
        ->and($conversation->participant_id)->toBe('9990001')
        ->and($conversation->displayName())->toBe('Instagram user ••0001')
        ->and(($this->messages)()->sole()->text)->toBe('Do you ship to Sylhet?');
});

it('explains missing permissions and subscribes nothing', function () {
    ($this->meta)(['pages_show_list']);

    $this->inTenant($this->tenant, fn () => expect(fn () => app(MessagingSetup::class)->enable($this->account))
        ->toThrow(ValidationException::class, 'pages_messaging, pages_manage_metadata'));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'subscribed_apps'));
    $this->inTenant($this->tenant, fn () => expect(MessagingSetting::query()->sole())
        ->state->toBe('failed')->missing_scopes->toBe(['pages_messaging', 'pages_manage_metadata']));
    expect(PageClaim::query()->count())->toBe(0);
});

it('merges the Page subscription and never drops feed or leadgen', function () {
    ($this->enable)(subscribed: ['feed', 'leadgen']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/1000/subscribed_apps')
        && $request['subscribed_fields'] === 'feed,leadgen,messages,message_echoes'
        && $request->hasHeader('Authorization', 'Bearer tok-page-1000'));

    // Switching off removes only the messaging fields; the Page keeps feeding comments and leads.
    ($this->meta)(subscribed: ['feed', 'leadgen', 'messages', 'message_echoes']);
    $this->inTenant($this->tenant, fn () => app(MessagingSetup::class)->disable($this->account));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['subscribed_fields'] === 'feed,leadgen');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

    // Comments switched on later keep the messaging fields too.
    ($this->enable)();
    ($this->meta)(subscribed: ['messages', 'message_echoes']);
    $this->inTenant($this->tenant, fn () => app(CommentSources::class)->enable($this->account));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['subscribed_fields'] === 'messages,message_echoes,feed');
});

it('keeps the shared Page claim while comments or messages still use it', function () {
    ($this->enable)();
    ($this->meta)();
    $this->inTenant($this->tenant, fn () => app(CommentSources::class)->enable($this->account));

    $this->inTenant($this->tenant, fn () => app(CommentSources::class)->disable($this->account));
    expect(PageClaim::query()->where('account_id', $this->account->id)->exists())->toBeTrue();

    ($this->meta)(subscribed: ['messages', 'message_echoes']);
    $this->inTenant($this->tenant, fn () => app(MessagingSetup::class)->disable($this->account));
    expect(PageClaim::query()->where('account_id', $this->account->id)->exists())->toBeFalse();

    // Received messages are kept, but not shown while capture is off.
    ($this->conversation)();
    $this->actingAsOwner($this->tenant)->get(($this->url)('/messages'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('conversations.data', [])->where('pages.0.enabled', false));
    $this->inTenant($this->tenant, fn () => expect(DirectConversation::query()->count())->toBe(1));
});

// ---- reading -----------------------------------------------------------------------------------------------------

it('shows a conversation on the Messages page and in the unified inbox to a permitted member, and clears unread', function () {
    ($this->enable)();
    $this->entitle($this->tenant, 'communication');
    ($this->deliver)([($this->inbound)('m_1')])->assertOk();
    $conversation = ($this->conversations)()->sole();
    [$agent] = ($this->member)(['communication.inbox.view', 'social.messages.view', 'social.messages.reply']);

    $this->actingAsMember($this->tenant, $agent)
        ->get($this->tenantUrl($this->tenant, '/communication/inbox?state=all'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('list.items', fn (Collection $items) => $items->contains(fn (array $item) => $item['key'] === 'social.messenger:'.$conversation->id
                && $item['title'] === 'Messenger user ••1234' && $item['unread'] === 1 && $item['chat'] === true)));

    $this->actingAsMember($this->tenant, $agent)
        ->get($this->tenantUrl($this->tenant, '/communication/inbox?open=social.messenger:'.$conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.channel', 'messenger')
            ->where('thread.subtitle', 'Brand Page')
            ->where('thread.canReply', true)
            ->where('thread.messages.0.text', 'Is the blue one in stock?')
            ->where('thread.messages.0.direction', 'in')
            ->where('thread.url', fn (string $url) => str_contains($url, '/social/messages?conversation='.$conversation->id)));

    // Opening changes nothing; the page then posts that the member read it.
    expect(($this->conversations)()->sole()->unread_count)->toBe(1);
    $this->actingAsMember($this->tenant, $agent)
        ->post($this->tenantUrl($this->tenant, '/communication/inbox/social.messenger/'.$conversation->id.'/read'))
        ->assertRedirect();
    expect(($this->conversations)()->sole()->unread_count)->toBe(0);

    $this->inTenant($this->tenant, fn () => $conversation->forceFill(['unread_count' => 3])->save());
    $this->actingAsMember($this->tenant, $agent)->get(($this->url)('/messages?conversation='.$conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Social/Pages/Messages/Index')
            ->where('thread.id', $conversation->id)
            ->where('thread.canReply', true)
            ->where('thread.messages.0.text', 'Is the blue one in stock?')
            ->where('conversations.data.0.unread', 3));
    $this->actingAsMember($this->tenant, $agent)->post(($this->url)('/messages/'.$conversation->id.'/read'))->assertRedirect();
    expect(($this->conversations)()->sole()->unread_count)->toBe(0);
});

it('marks a conversation read through the inbox source', function () {
    ($this->enable)();
    $conversation = ($this->conversation)(['unread_count' => 4]);
    $owner = $this->ownerOf($this->tenant);

    $this->inTenant($this->tenant, fn () => app(MessengerInboxSource::class)->markRead($conversation->id, $owner));

    expect(($this->conversations)()->sole()->unread_count)->toBe(0);
});

it('narrows conversations to the member\'s scope and to the Page allow-list', function () {
    ($this->enable)();
    [$agent, $membership] = ($this->member)(['social.messages.view', 'social.messages.reply'], ['social.messages.view' => 'own', 'social.messages.reply' => 'own']);
    $other = ($this->conversation)();
    $mine = ($this->conversation)(['participant_id' => '5559999']);
    $this->inTenant($this->tenant, fn () => $mine->assignOwner($membership)->save());

    $this->actingAsMember($this->tenant, $agent)->get(($this->url)('/messages'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1)->where('conversations.data.0.id', $mine->id));
    $this->actingAsMember($this->tenant, $agent)->get(($this->url)('/messages?conversation='.$other->id))->assertNotFound();
    $this->actingAsMember($this->tenant, $agent)->post(($this->url)('/messages/'.$other->id.'/reply'), ['text' => 'Hi'])->assertNotFound();

    // The Page restricted to other members: its conversations disappear for this member.
    $this->inTenant($this->tenant, fn () => app(AccountAllowList::class)->replace($this->account, [
        ['subject_type' => 'membership', 'subject_id' => $this->membershipOf($this->tenant, $this->ownerOf($this->tenant))->id, 'can_publish' => true],
    ]));
    $this->actingAsMember($this->tenant, $agent)->get(($this->url)('/messages'))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 0));
});

// ---- replying ----------------------------------------------------------------------------------------------------

it('replies inside the 24-hour window with exactly one Send API call and stores it as outgoing', function () {
    ($this->enable)();
    $conversation = ($this->conversation)();
    [$agent] = ($this->member)(['social.messages.view', 'social.messages.reply']);
    ($this->meta)();

    $this->actingAsMember($this->tenant, $agent)
        ->post(($this->url)('/messages/'.$conversation->id.'/reply'), ['text' => '  Yes, it is in stock.  '])
        ->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/1000/messages')
        && $request->hasHeader('Authorization', 'Bearer tok-page-1000')
        && $request['recipient'] === ['id' => '5551234']
        && $request['message'] === ['text' => 'Yes, it is in stock.']
        && $request['messaging_type'] === 'RESPONSE'
        && ! str_contains($request->url(), 'tok-page-1000'));

    $out = ($this->messages)()->where('direction', 'out')->sole();
    expect($out->text)->toBe('Yes, it is in stock.')
        ->and($out->provider_message_id)->toBe('m_sent_1')
        ->and($out->status)->toBe('sent')
        ->and($out->sent_by_membership_id)->toBe($this->membershipOf($this->tenant, $agent)->id);
    $this->inTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'social.message.sent')->count())->toBe(1));

    // Its echo arriving later does not add a second copy.
    ($this->deliver)([[
        'sender' => ['id' => '1000'], 'recipient' => ['id' => '5551234'], 'timestamp' => now()->getTimestampMs(),
        'message' => ['mid' => 'm_sent_1', 'is_echo' => true, 'text' => 'Yes, it is in stock.'],
    ]])->assertOk();
    expect(($this->messages)()->where('direction', 'out'))->toHaveCount(1);
});

it('replies to an Instagram conversation from the unified inbox through the same action', function () {
    ($this->enable)();
    $this->entitle($this->tenant, 'communication');
    $conversation = ($this->conversation)(['platform' => 'instagram', 'participant_id' => '9990001']);
    [$agent] = ($this->member)(['communication.inbox.view', 'social.messages.view', 'social.messages.reply']);
    ($this->meta)(send: ['recipient_id' => '9990001', 'message_id' => 'ig_sent_1']);

    $this->actingAsMember($this->tenant, $agent)
        ->post($this->tenantUrl($this->tenant, '/communication/inbox/social.instagram/'.$conversation->id.'/reply'), ['text' => 'Yes, we do.'])
        ->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1000/messages')
        && $request['recipient'] === ['id' => '9990001'] && $request['message'] === ['text' => 'Yes, we do.']
        && ! isset($request['messaging_type']));
    expect(($this->messages)()->firstWhere('provider_message_id', 'ig_sent_1')?->direction)->toBe('out');
});

it('refuses a reply outside the 24-hour window, sends nothing and says why', function () {
    ($this->enable)();
    $conversation = ($this->conversation)(['last_inbound_at' => now()->subHours(25), 'last_message_at' => now()->subHours(25)]);
    ($this->meta)();

    $this->actingAsOwner($this->tenant)->get(($this->url)('/messages?conversation='.$conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.canReply', false)
            ->where('thread.blockedReason', fn (string $reason) => str_contains($reason, '24-hour')));
    $this->actingAsOwner($this->tenant)->post(($this->url)('/messages/'.$conversation->id.'/reply'), ['text' => 'Hello again'])
        ->assertSessionHasErrors('text');

    $thread = $this->inTenant($this->tenant, fn () => app(MessengerInboxSource::class)->thread($conversation->id, $this->ownerOf($this->tenant)));
    expect($thread?->canReply)->toBeFalse()->and($thread?->replyBlockedReason)->toContain('24-hour');

    Http::assertNothingSent();
    expect(($this->messages)()->where('direction', 'out'))->toHaveCount(0);
});

it('moves the account to reconnect on a rejected token and asks to retry later when rate limited', function () {
    ($this->enable)();
    $conversation = ($this->conversation)();

    ($this->meta)(send: ['error' => ['code' => 4, 'message' => 'Application request limit reached']], sendStatus: 400);
    $this->actingAsOwner($this->tenant)->post(($this->url)('/messages/'.$conversation->id.'/reply'), ['text' => 'Hi'])
        ->assertSessionHasErrors(['text' => __('Facebook is limiting requests right now. Nothing was sent. Try again in a few minutes.')]);
    expect(($this->messages)()->where('direction', 'out'))->toHaveCount(0);

    ($this->meta)(send: ['error' => ['code' => 190, 'message' => 'Error validating access token: tok-page-1000']], sendStatus: 400);
    $this->actingAsOwner($this->tenant)->post(($this->url)('/messages/'.$conversation->id.'/reply'), ['text' => 'Hi'])
        ->assertSessionHasErrors('text');

    $this->inTenant($this->tenant, function (): void {
        $account = SocialAccount::query()->findOrFail($this->account->id);
        expect($account->status)->toBe(AccountStatus::NeedsReauthorization)
            ->and((string) $account->status_reason)->not->toContain('tok-page-1000')
            ->and(DirectMessage::query()->where('direction', 'out')->count())->toBe(0);
    });
});

it('forbids a reply without the reply permission', function () {
    ($this->enable)();
    $conversation = ($this->conversation)();
    [$viewer] = ($this->member)(['social.messages.view']);
    ($this->meta)();

    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/messages?conversation='.$conversation->id))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canReply', false)->where('can.reply', false));
    $this->actingAsMember($this->tenant, $viewer)->post(($this->url)('/messages/'.$conversation->id.'/reply'), ['text' => 'Hi'])->assertForbidden();
    $this->actingAsMember($this->tenant, $viewer)->put(($this->url)('/messages/'.$conversation->id.'/status'), ['status' => 'closed'])->assertForbidden();
    $this->actingAsMember($this->tenant, $viewer)->post(($this->url)('/messages/accounts/'.$this->account->id.'/disable'))->assertForbidden();

    [$stranger] = ($this->member)(['social.posts.view']);
    $this->actingAsMember($this->tenant, $stranger)->get(($this->url)('/messages'))->assertForbidden();

    Http::assertNothingSent();
});

it('closes, reopens and assigns a conversation', function () {
    ($this->enable)();
    $conversation = ($this->conversation)();
    $membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->actingAsOwner($this->tenant);

    $this->put(($this->url)('/messages/'.$conversation->id.'/status'), ['status' => 'closed'])->assertRedirect();
    expect(($this->conversations)()->sole()->status)->toBe('closed');

    $this->put(($this->url)('/messages/'.$conversation->id.'/assignee'), ['membership_id' => $membership->id])->assertRedirect();
    expect(($this->conversations)()->sole()->owner_id)->toBe($membership->id);

    // A new message from the person reopens it.
    ($this->deliver)([($this->inbound)('m_new', 'One more question')])->assertOk();
    expect(($this->conversations)()->sole()->status)->toBe('open');
});

// ---- off by default --------------------------------------------------------------------------------------------

it('requests no messaging permission and lists nothing while the operator switch is off', function () {
    ($this->enable)();
    $conversation = ($this->conversation)();
    $owner = $this->ownerOf($this->tenant);
    $scopes = fn (): string => (string) parse_url(app(FacebookPagesPlatform::class)->authorizationRequest('https://x.test/cb', 'state')->url, PHP_URL_QUERY);

    expect($scopes())->toContain('pages_messaging')->toContain('instagram_manage_messages');

    config(['social.messaging.enabled' => false, 'social.messaging.instagram' => false]);

    expect($scopes())->not->toContain('pages_messaging')->not->toContain('instagram_manage_messages');

    $query = new InboxQuery($owner, $this->membershipOf($this->tenant, $owner)->id, InboxState::cases());
    $this->inTenant($this->tenant, function () use ($query, $conversation, $owner): void {
        expect(app(MessengerInboxSource::class)->items($query))->toBe([])
            ->and(app(InstagramInboxSource::class)->items($query))->toBe([])
            ->and(array_sum(app(MessengerInboxSource::class)->counts($query)))->toBe(0)
            ->and(app(MessengerInboxSource::class)->thread($conversation->id, $owner))->toBeNull();
    });

    $this->actingAsOwner($this->tenant)->get(($this->url)('/messages'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('availability.problem', 'not_enabled')->where('conversations.data', []));
    $this->actingAsOwner($this->tenant)->post(($this->url)('/messages/accounts/'.$this->account->id.'/enable'))->assertSessionHasErrors('account');

    // Deliveries are not read either (comments still are).
    ($this->deliver)([($this->inbound)('m_off')])->assertOk();
    expect(($this->messages)()->where('provider_message_id', 'm_off'))->toHaveCount(0);
});

it('lists conversations in the inbox source when switched on', function () {
    ($this->enable)();
    $conversation = ($this->conversation)();
    $owner = $this->ownerOf($this->tenant);
    $query = new InboxQuery($owner, $this->membershipOf($this->tenant, $owner)->id, InboxState::cases());

    $items = $this->inTenant($this->tenant, fn () => app(MessengerInboxSource::class)->items($query));

    expect($items)->toHaveCount(1)
        ->and($items[0]->id)->toBe($conversation->id)
        ->and($items[0]->state)->toBe(InboxState::Unassigned)
        ->and($items[0]->unread)->toBe(1)
        ->and($items[0]->preview)->toBe('Is the blue one in stock?')
        ->and(app(MessengerInboxSource::class)->replyPermission())->toBe('social.messages.reply')
        ->and(app(InstagramInboxSource::class)->definition()->permission)->toBe('social.messages.view')
        ->and(app(InstagramInboxSource::class)->definition()->key)->toBe('social.instagram');
});

it('narrows the inbox source to conversations with unread messages when asked', function () {
    ($this->enable)();
    $unread = ($this->conversation)(['unread_count' => 3]);
    ($this->conversation)(['participant_id' => '5559999', 'unread_count' => 0, 'last_message_at' => now()->subMinutes(5)]);
    $closed = ($this->conversation)(['participant_id' => '5558888', 'unread_count' => 1, 'status' => 'closed', 'last_message_at' => now()->subMinutes(20)]);
    $owner = $this->ownerOf($this->tenant);
    $membership = $this->membershipOf($this->tenant, $owner);
    $query = fn (bool $unread): InboxQuery => new InboxQuery($owner, $membership->id, InboxState::cases(), unread: $unread);

    $this->inTenant($this->tenant, function () use ($query, $unread, $closed): void {
        $source = app(MessengerInboxSource::class);
        $ids = fn (array $items): array => array_map(fn ($item): string => $item->id, $items);

        expect($ids($source->items($query(true))))->toBe([$unread->id, $closed->id])
            ->and($source->items($query(false)))->toHaveCount(3)
            ->and($source->counts($query(true)))->toBe(['unassigned' => 1, 'active' => 0, 'closed' => 1])
            ->and($source->counts($query(false)))->toBe(['unassigned' => 2, 'active' => 0, 'closed' => 1])
            ->and(app(InstagramInboxSource::class)->items($query(true)))->toBe([]);
    });
});

// ---- isolation ---------------------------------------------------------------------------------------------------

it('isolates direct-message conversations, messages and settings by workspace', function () {
    $account = fn () => SocialAccount::query()->create([
        'platform' => 'facebook', 'external_id' => 'iso-'.bin2hex(random_bytes(4)), 'name' => 'Iso Page', 'account_type' => 'page',
        'status' => AccountStatus::Connected, 'access_token' => 'tok-iso',
    ]);
    $conversation = function () use ($account): DirectConversation {
        $row = new DirectConversation(['social_account_id' => $account()->id, 'platform' => 'messenger', 'participant_id' => '1'.random_int(1000, 9999), 'unread_count' => 0, 'status' => 'open']);
        $row->keepUnowned()->save();

        return $row;
    };

    $this->assertTenantIsolated(DirectConversation::class, fn () => $conversation());
    $this->assertTenantIsolated(DirectMessage::class, fn () => DirectMessage::query()->create([
        'conversation_id' => $conversation()->id, 'direction' => 'in', 'provider_message_id' => 'iso_'.bin2hex(random_bytes(6)),
        'text' => 'Hi', 'status' => 'received', 'sent_at' => now(),
    ]));
    $this->assertTenantIsolated(MessagingSetting::class, fn () => MessagingSetting::query()->create(['social_account_id' => $account()->id, 'state' => 'off', 'instagram_state' => 'off']));
});

it('answers 404 to another workspace for every Messages route that takes an id', function () {
    ($this->enable)();
    $conversation = ($this->conversation)(['last_inbound_at' => now()->subHours(30)]);
    $intruder = $this->socialTenant('dm-intruder');
    ($this->meta)();

    $this->assertNotFoundFromOtherTenant('GET', '/social/messages?conversation='.$conversation->id, $this->tenant, [], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', '/social/messages/'.$conversation->id.'/reply', $this->tenant, ['text' => 'Hi'], $intruder);
    $this->assertNotFoundFromOtherTenant('PUT', '/social/messages/'.$conversation->id.'/assignee', $this->tenant, ['membership_id' => null], $intruder);
    $this->assertNotFoundFromOtherTenant('PUT', '/social/messages/'.$conversation->id.'/status', $this->tenant, ['status' => 'closed'], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', '/social/messages/'.$conversation->id.'/read', $this->tenant, [], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', '/social/messages/accounts/'.$this->account->id.'/disable', $this->tenant, [], $intruder);

    // The window had closed, so the control request in the home workspace sent nothing either.
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/messages'));
});
