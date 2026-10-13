<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelNumberDirectory;
use App\Core\Communication\Contracts\ChannelNumberRegistry;
use App\Core\Communication\Data\MarketingChannel;
use App\Modules\Communication\Models\ChannelPhoneNumber;
use App\Modules\Communication\Models\Message as CommunicationMessage;
use App\Modules\WhatsappMarketing\Actions\CampaignManager;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Jobs\SendCampaignChunk;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

const RM_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function rmPng(): string
{
    return (string) base64_decode(RM_PNG, true);
}

/** Fake the Graph API for sends (and media uploads). */
function rmFakeGraph(): void
{
    Http::swap(new Factory);
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'PROVIDER-MEDIA-1'], 200),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::upper(Str::random(8))]]], 200),
    ]);
}

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $this->tenant = $this->waTenant('wa-rich');
    $this->waConnectCloud($this->tenant, '109876543210');
    $this->contact = $this->waContact($this->tenant);
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
    $this->waAgent($this->tenant, $this->membership);
    // A customer wrote: the 24-hour service window is open.
    $this->waInbound($this->tenant, '+8801711000001', 'Hello')->assertOk();
    $this->conversation = $this->inTenant($this->tenant, fn () => Conversation::query()->firstOrFail());
    $this->registry = fn () => app(ChannelNumberRegistry::class);
    $this->post = fn (string $path, array $data = []) => $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}".$path), $data);
});

describe('number management', function () {
    it('lists the single default number and registers, labels and re-defaults more', function () {
        $this->actingAsMember($this->tenant, $this->user)->get($this->waUrl($this->tenant, '/settings/numbers'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Modules/WhatsappMarketing/Pages/Settings/Numbers')
                ->where('numbers.0.isDefault', true)
                ->where('numbers.0.accountId', '109876543210'));

        $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, '/settings/numbers'), ['account_id' => '209876543210', 'label' => 'Support line', 'display_number' => '+8801700000002'])
            ->assertSessionHasNoErrors();

        $support = $this->inTenant($this->tenant, fn () => ChannelPhoneNumber::query()->where('phone_number_id', '209876543210')->firstOrFail());

        $this->actingAsMember($this->tenant, $this->user)->put($this->waUrl($this->tenant, "/settings/numbers/{$support->id}"), ['label' => 'Help desk'])->assertSessionHasNoErrors();
        $this->actingAsMember($this->tenant, $this->user)->post($this->waUrl($this->tenant, "/settings/numbers/{$support->id}/default"))->assertSessionHasNoErrors();

        $this->inTenant($this->tenant, function () use ($support): void {
            expect(ChannelPhoneNumber::query()->findOrFail($support->id)->label)->toBe('Help desk')
                ->and(ChannelPhoneNumber::query()->findOrFail($support->id)->is_default)->toBeTrue();
        });
    });

    it('refuses a number another workspace already holds (claim conflict)', function () {
        $other = $this->waTenant('wa-rich-other');
        $this->waConnectCloud($other, '777000111222');
        // Claim the first workspace's default by reading its numbers.
        $this->actingAsMember($this->tenant, $this->user)->get($this->waUrl($this->tenant, '/settings/numbers'))->assertOk();

        [$otherUser] = $this->waAdmin($other);
        $this->actingAsMember($other, $otherUser)
            ->post($this->waUrl($other, '/settings/numbers'), ['account_id' => '109876543210', 'label' => 'Stolen'])
            ->assertSessionHasErrors('number');

        $this->inTenant($other, fn () => expect(ChannelPhoneNumber::query()->where('phone_number_id', '109876543210')->exists())->toBeFalse());
    });

    it('cannot disable or remove the default while others exist, and never touches another workspace', function () {
        $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
        $main = $this->inTenant($this->tenant, fn () => app(ChannelNumberDirectory::class)->default(MarketingChannel::Whatsapp));

        $this->actingAsMember($this->tenant, $this->user)->put($this->waUrl($this->tenant, "/settings/numbers/{$main->id}"), ['is_active' => false])->assertSessionHasErrors('number');
        $this->actingAsMember($this->tenant, $this->user)->delete($this->waUrl($this->tenant, "/settings/numbers/{$main->id}"))->assertSessionHasErrors('number');
        $this->actingAsMember($this->tenant, $this->user)->delete($this->waUrl($this->tenant, "/settings/numbers/{$support->id}"))->assertSessionHasNoErrors();

        $foreign = $this->waTenant('wa-rich-foreign');
        $this->waConnectCloud($foreign, '888000111222');
        $foreignNumber = $this->inTenant($foreign, fn () => app(ChannelNumberDirectory::class)->default(MarketingChannel::Whatsapp));
        $this->actingAsMember($this->tenant, $this->user)->put($this->waUrl($this->tenant, "/settings/numbers/{$foreignNumber->id}"), ['label' => 'Hijack'])->assertSessionHasErrors('number');
    });
});

describe('number picker drives the sending number', function () {
    it('sends a reply from the chosen business number', function () {
        $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
        rmFakeGraph();

        ($this->post)('/messages', ['body' => 'From support', 'sender_number_id' => $support->id])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/209876543210/messages'));

        $this->inTenant($this->tenant, function () use ($support): void {
            $message = Message::query()->where('direction', Direction::Outbound->value)->latest('id')->firstOrFail();
            expect(CommunicationMessage::query()->findOrFail($message->communication_message_id)->sender_number_id)->toBe($support->id);
        });
    });
});

describe('interactive, cta and media in the window', function () {
    it('sends reply buttons, a list and a call-to-action URL inside the window', function () {
        rmFakeGraph();

        ($this->post)('/interactive', ['body' => 'Confirm your order?', 'kind' => 'buttons', 'options' => ['Yes', 'No']])->assertSessionHasNoErrors();
        ($this->post)('/interactive', ['body' => 'Pick a branch', 'kind' => 'list', 'button' => 'Branches', 'options' => ['Dhaka', 'Sylhet']])->assertSessionHasNoErrors();
        ($this->post)('/cta', ['body' => 'Open our shop', 'label' => 'Shop', 'url' => 'https://example.com/shop'])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => ($request['interactive']['type'] ?? null) === 'button'
            && $request['interactive']['action']['buttons'][0]['reply']['title'] === 'Yes');
        Http::assertSent(fn (Request $request): bool => ($request['interactive']['type'] ?? null) === 'list'
            && $request['interactive']['action']['button'] === 'Branches');
        Http::assertSent(fn (Request $request): bool => ($request['interactive']['type'] ?? null) === 'cta_url'
            && $request['interactive']['action']['parameters']['url'] === 'https://example.com/shop');

        $this->inTenant($this->tenant, fn () => expect(Message::query()->where('type', MessageType::Interactive->value)->count())->toBe(3));
    });

    it('sends an uploaded image with its caption inside the window', function () {
        rmFakeGraph();
        $file = UploadedFile::fake()->createWithContent('logo.png', rmPng());

        ($this->post)('/media', ['type' => 'image', 'caption' => 'Our new logo', 'file' => $file])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/media') && $request->isMultipart());
        Http::assertSent(fn (Request $request): bool => ($request['type'] ?? null) === 'image'
            && ($request['image']['id'] ?? null) === 'PROVIDER-MEDIA-1'
            && ($request['image']['caption'] ?? null) === 'Our new logo');

        $this->inTenant($this->tenant, fn () => expect(Message::query()->where('type', MessageType::Image->value)->where('direction', Direction::Outbound->value)->count())->toBe(1));
    });

    it('blocks every rich kind once the window has closed and records nothing', function () {
        // Age the last inbound beyond 24 hours: the window is now closed.
        $this->inTenant($this->tenant, fn () => Conversation::query()->whereKey($this->conversation->id)->update(['last_inbound_at' => now()->subHours(25)]));
        rmFakeGraph();

        ($this->post)('/interactive', ['body' => 'x', 'kind' => 'buttons', 'options' => ['A']])->assertSessionHasErrors('body');
        ($this->post)('/cta', ['body' => 'x', 'label' => 'Shop', 'url' => 'https://example.com'])->assertSessionHasErrors('body');
        ($this->post)('/media', ['type' => 'image', 'file' => UploadedFile::fake()->createWithContent('logo.png', rmPng())])->assertSessionHasErrors('body');

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
        $this->inTenant($this->tenant, fn () => expect(Message::query()->where('direction', Direction::Outbound->value)->count())->toBe(0));
    });
});

describe('inbound media serving', function () {
    it('serves an inbound image with safe headers, access-checked and tenant-isolated', function () {
        Http::swap(new Factory);
        Http::fake([
            'graph.facebook.com/v21.0/WAMEDIA9*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/png', 'file_size' => strlen(rmPng()), 'id' => 'WAMEDIA9'], 200),
            'lookaside.fbsbx.com/*' => Http::response(rmPng(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->waWebhook($this->tenant, $this->waMessagePayload('109876543210', [[
            'from' => '8801711000001', 'id' => 'wamid.IN-IMG', 'timestamp' => (string) now()->timestamp, 'type' => 'image',
            'image' => ['id' => 'WAMEDIA9', 'mime_type' => 'image/png', 'caption' => 'Receipt'],
        ]], '8801711000001'))->assertOk();

        $message = $this->inTenant($this->tenant, fn () => Message::query()->where('type', MessageType::Image->value)->where('direction', Direction::Inbound->value)->firstOrFail());

        $response = $this->actingAsMember($this->tenant, $this->user)->get($this->waUrl($this->tenant, "/messages/{$message->id}/media"));
        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        expect($response->getContent())->toBe(rmPng());

        // A member without the view permission is refused.
        [$viewless] = $this->waMember($this->tenant, ['whatsapp_marketing.campaigns.view']);
        $this->actingAsMember($this->tenant, $viewless)->get($this->waUrl($this->tenant, "/messages/{$message->id}/media"))->assertForbidden();

        // Another workspace cannot reach the message at all.
        $other = $this->waTenant('wa-rich-peer');
        [$otherUser] = $this->waAdmin($other);
        $this->actingAsMember($other, $otherUser)->get($this->waUrl($other, "/messages/{$message->id}/media"))->assertNotFound();
    });
});

describe('campaigns with a chosen number and rich template', function () {
    it('sends a campaign from the chosen business number with a media header', function () {
        $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
        $this->waOptIn($this->tenant, $this->contact);
        $template = $this->waTemplate($this->tenant, ['body' => 'Hi {{1}}']);
        $list = $this->waList($this->tenant, [$this->contact]);
        rmFakeGraph();

        $this->actingAsMember($this->tenant, $this->user)->post($this->waUrl($this->tenant, '/campaigns'), [
            'name' => 'Promo '.Str::random(4),
            'template_id' => $template->id,
            'list_id' => $list->id,
            'variables' => [1 => ['type' => 'field', 'field' => 'contact.first_name']],
            'sender_number_id' => $support->id,
            'header_type' => 'image',
            'header_file' => UploadedFile::fake()->createWithContent('banner.png', rmPng()),
        ])->assertSessionHasNoErrors();

        $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->latest('id')->firstOrFail());
        expect($campaign->sender_number_id)->toBe($support->id)->and($campaign->header_media_type)->toBe('image')->and($campaign->header_media_id)->not->toBeNull();

        $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->start($campaign));
        $this->inTenant($this->tenant, fn () => SendCampaignChunk::dispatchSync($campaign->id));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/209876543210/messages') && ($request['type'] ?? null) === 'template');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/209876543210/media'));

        $this->inTenant($this->tenant, fn () => expect(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Completed));
    });
});
