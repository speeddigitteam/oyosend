<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\MediaGateway;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\MessagingPurpose;
use App\Core\Communication\Data\Whatsapp\MediaReference;
use App\Core\Communication\Data\Whatsapp\WhatsappContent;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;
use App\Modules\Communication\Models\MediaAsset;
use App\Modules\Communication\Models\MediaUpload;
use App\Modules\Communication\Models\Message;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

const RICH_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** @param array<string, mixed> $overrides */
function richGatewayMessage(string $contactId, string $key, ?WhatsappContent $content, array $overrides = []): MarketingMessage
{
    return new MarketingMessage(...[
        'module' => 'whatsapp_marketing',
        'idempotencyKey' => $key,
        'channel' => MarketingChannel::Whatsapp,
        'contactId' => $contactId,
        'subject' => null,
        'body' => 'Pick one',
        'whatsapp' => $content,
        ...$overrides,
    ]);
}

function richOpenWindow(): array
{
    return ['whatsapp_window_until' => now()->addHours(3)->toIso8601String()];
}

function richFakeGraph(): void
{
    Http::swap(new Factory);
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'UPLOADED-1'], 200),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.RICH-OUT']]], 200),
    ]);
}

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $this->tenant = $this->waTenant('rich-ws');
    $this->waConnectCloud($this->tenant);
    $this->contact = $this->waContact($this->tenant);
    $this->send = fn (MarketingMessage $message) => $this->inTenant($this->tenant, fn () => app(ChannelGateway::class)->send($message));
    richFakeGraph();
});

it('sends reply buttons inside the open window as a service message and stores the content in the outbox', function () {
    $content = WhatsappContent::buttons([['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']]);

    $receipt = ($this->send)(richGatewayMessage($this->contact->id, 'btn:1', $content, [
        'purpose' => MessagingPurpose::Transactional,
        'providerHints' => richOpenWindow(),
        'body' => 'Confirm your order?',
    ]));

    expect($receipt->isRecorded())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/109876543210/messages')
        && $request['type'] === 'interactive'
        && $request['interactive']['type'] === 'button'
        && $request['interactive']['body']['text'] === 'Confirm your order?'
        && $request['interactive']['action']['buttons'][0]['reply']['id'] === 'yes');

    $this->inTenant($this->tenant, function () use ($receipt, $content): void {
        $message = Message::query()->findOrFail($receipt->messageId);

        expect($message->status->value)->toBe('sent')
            ->and($message->purpose->value)->toBe('transactional')
            ->and($message->meta['whatsapp_content'])->toBe($content->toJson())
            ->and($message->meta['marketing_module'])->toBe('whatsapp_marketing');
    });
});

it('refuses every free-form kind without an open window, exactly like a reply, and records nothing', function () {
    $kinds = [
        WhatsappContent::buttons([['id' => 'a', 'title' => 'A']]),
        WhatsappContent::list('Go', [['rows' => [['id' => 'r', 'title' => 'R']]]]),
        WhatsappContent::ctaUrl('Open', 'https://example.com'),
        WhatsappContent::media(new MediaReference(WhatsappMediaType::Image, providerMediaId: '1')),
        WhatsappContent::product('123456', 'sku'),
        WhatsappContent::productList('123456', 'Picks', [['title' => 'New', 'product_retailer_ids' => ['a']]]),
        WhatsappContent::flow('123456', 'Go', 'tok'),
    ];

    foreach ($kinds as $index => $content) {
        $none = ($this->send)(richGatewayMessage($this->contact->id, "none:{$index}", $content));
        $closed = ($this->send)(richGatewayMessage($this->contact->id, "closed:{$index}", $content, [
            'purpose' => MessagingPurpose::Transactional,
            'providerHints' => ['whatsapp_window_until' => now()->subMinute()->toIso8601String()],
        ]));
        $garbage = ($this->send)(richGatewayMessage($this->contact->id, "garbage:{$index}", $content, ['providerHints' => ['whatsapp_window_until' => 'tomorrow-ish']]));

        foreach ([$none, $closed, $garbage] as $receipt) {
            expect($receipt->state)->toBe(DeliveryState::Unavailable)
                ->and($receipt->reason)->toBe('outside_customer_service_window')
                ->and($receipt->isRecorded())->toBeFalse();
        }
    }

    $this->inTenant($this->tenant, fn () => expect(Message::query()->count())->toBe(0));
    Http::assertNothingSent();
});

it('sends a template without any window, but only to a contact who opted in', function () {
    $content = WhatsappContent::template('order_update', 'en_US', ['Rahim']);

    $blocked = ($this->send)(richGatewayMessage($this->contact->id, 'tpl:1', $content, ['body' => 'Order update']));
    expect($blocked->state)->toBe(DeliveryState::Blocked)->and($blocked->reason)->toBe('no_consent');

    $this->waOptIn($this->tenant, $this->contact);
    $sent = ($this->send)(richGatewayMessage($this->contact->id, 'tpl:2', $content, ['body' => 'Order update']));
    expect($sent->isRecorded())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'template' && $request['template']['name'] === 'order_update');
    Http::assertSentCount(1);
});

it('treats a free-form message in an open window as marketing unless the caller says it is a reply', function () {
    $content = WhatsappContent::ctaUrl('Open', 'https://example.com');

    $marketing = ($this->send)(richGatewayMessage($this->contact->id, 'cta:m', $content, ['providerHints' => richOpenWindow()]));
    expect($marketing->state)->toBe(DeliveryState::Blocked)->and($marketing->reason)->toBe('no_consent');

    $reply = ($this->send)(richGatewayMessage($this->contact->id, 'cta:r', $content, ['providerHints' => richOpenWindow(), 'purpose' => MessagingPurpose::Transactional]));
    expect($reply->isRecorded())->toBeTrue();
});

it('never lets a provider hint smuggle rich content past the checks', function () {
    $smuggled = WhatsappContent::buttons([['id' => 'a', 'title' => 'A']])->toJson();

    // A hint under the reserved key is dropped; the message goes out as the plain text it is.
    $this->waOptIn($this->tenant, $this->contact);
    $receipt = ($this->send)(richGatewayMessage($this->contact->id, 'smuggle', null, [
        'providerHints' => ['whatsapp_content' => mb_substr($smuggled, 0, 1900)],
        'body' => 'Plain hello',
    ]));

    $this->inTenant($this->tenant, fn () => expect(Message::query()->findOrFail($receipt->messageId)->meta)->not->toHaveKey('whatsapp_content'));
    Http::assertSent(fn (Request $request): bool => ($request['type'] ?? null) === 'text' && $request['text']['body'] === 'Plain hello');
});

it('uploads a stored header image for the sending number before it sends the template, once', function () {
    $stored = $this->inTenant($this->tenant, fn () => app(MediaGateway::class)->store(WhatsappMediaType::Image, (string) base64_decode(RICH_PNG, true), 'banner.png'))->media;
    $this->waOptIn($this->tenant, $this->contact);

    $content = WhatsappContent::template('promo', 'en', ['Rahim'], headerMedia: new MediaReference(WhatsappMediaType::Image, mediaId: $stored->id));

    $first = ($this->send)(richGatewayMessage($this->contact->id, 'promo:1', $content, ['body' => 'Promo']));
    $second = ($this->send)(richGatewayMessage($this->contact->id, 'promo:2', $content, ['body' => 'Promo']));

    expect($first->isRecorded())->toBeTrue()->and($second->isRecorded())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/109876543210/media') && $request->isMultipart());
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages')
        && $request['template']['components'][0] === ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => 'UPLOADED-1']]]]);

    $uploads = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/media'));
    expect($uploads)->toHaveCount(1);

    $this->inTenant($this->tenant, function () use ($first, $stored): void {
        expect(MediaUpload::query()->count())->toBe(1)
            // The outbox keeps the stored reference, never the provider id.
            ->and(Message::query()->findOrFail($first->messageId)->meta['whatsapp_content'])->toContain($stored->id)->not->toContain('UPLOADED-1')
            ->and(Message::query()->findOrFail($first->messageId)->status->value)->toBe('sent');
    });
});

it('sends a stored document in the open window with its caption and file name', function () {
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $stored = $this->inTenant($this->tenant, fn () => app(MediaGateway::class)->store(WhatsappMediaType::Document, $pdf, 'invoice.pdf'))->media;

    $receipt = ($this->send)(richGatewayMessage(
        $this->contact->id,
        'doc:1',
        WhatsappContent::media(new MediaReference(WhatsappMediaType::Document, mediaId: $stored->id, filename: 'invoice-42.pdf'), 'Your invoice'),
        ['purpose' => MessagingPurpose::Transactional, 'providerHints' => richOpenWindow(), 'body' => 'Invoice 42'],
    ));

    expect($receipt->isRecorded())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => ($request['type'] ?? null) === 'document'
        && $request['document'] === ['id' => 'UPLOADED-1', 'caption' => 'Your invoice', 'filename' => 'invoice-42.pdf']);
});

it('fails a message whose stored media is gone or cannot be uploaded, with an honest code', function () {
    $this->waOptIn($this->tenant, $this->contact);
    $stored = $this->inTenant($this->tenant, fn () => app(MediaGateway::class)->store(WhatsappMediaType::Image, (string) base64_decode(RICH_PNG, true)))->media;
    $content = WhatsappContent::template('promo', 'en', headerMedia: new MediaReference(WhatsappMediaType::Image, mediaId: $stored->id));

    // The provider refuses the upload.
    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*/media' => Http::response(['error' => ['code' => 100, 'message' => 'bad media']], 400)]);
    $refused = ($this->send)(richGatewayMessage($this->contact->id, 'gone:1', $content, ['body' => 'Promo']));

    // The file disappears from the media store.
    $this->inTenant($this->tenant, fn () => MediaAsset::query()->delete());
    $missing = ($this->send)(richGatewayMessage($this->contact->id, 'gone:2', $content, ['body' => 'Promo']));

    $this->inTenant($this->tenant, function () use ($refused, $missing): void {
        expect(Message::query()->findOrFail($refused->messageId)->failure_code)->toBe('media_upload_failed')
            ->and(Message::query()->findOrFail($refused->messageId)->status->value)->toBe('failed')
            ->and(Message::query()->findOrFail($missing->messageId)->failure_code)->toBe('media_not_found');
    });

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
});

it('retries a message when the media upload hits a temporary provider failure', function () {
    $this->waOptIn($this->tenant, $this->contact);
    $stored = $this->inTenant($this->tenant, fn () => app(MediaGateway::class)->store(WhatsappMediaType::Image, (string) base64_decode(RICH_PNG, true)))->media;

    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*/media' => Http::response('down', 503)]);

    $receipt = ($this->send)(richGatewayMessage(
        $this->contact->id,
        'retry:1',
        WhatsappContent::template('promo', 'en', headerMedia: new MediaReference(WhatsappMediaType::Image, mediaId: $stored->id)),
        ['body' => 'Promo'],
    ));

    $this->inTenant($this->tenant, function () use ($receipt): void {
        $message = Message::query()->findOrFail($receipt->messageId);
        expect($message->status->value)->toBe('queued')->and($message->failure_code)->toBe('provider_unavailable')->and($message->attempts)->toBe(1);
    });
});

it('does not let one workspace send another workspace\'s stored media', function () {
    $other = $this->waTenant('rich-other');
    $this->waConnectCloud($other, '555000999888');
    $foreign = $this->inTenant($other, fn () => app(MediaGateway::class)->store(WhatsappMediaType::Image, (string) base64_decode(RICH_PNG, true)))->media;

    $this->waOptIn($this->tenant, $this->contact);
    richFakeGraph();
    $receipt = ($this->send)(richGatewayMessage(
        $this->contact->id,
        'foreign:1',
        WhatsappContent::template('promo', 'en', headerMedia: new MediaReference(WhatsappMediaType::Image, mediaId: $foreign->id)),
        ['body' => 'Promo'],
    ));

    $this->inTenant($this->tenant, fn () => expect(Message::query()->findOrFail($receipt->messageId)->failure_code)->toBe('media_not_found'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
});

it('records rich content with a test-double provider without pretending it was delivered by a real one', function () {
    $tenant = $this->waTenant('rich-fake');
    $this->waConnectFake($tenant);
    $contact = $this->waContact($tenant);

    $receipt = $this->inTenant($tenant, fn () => app(ChannelGateway::class)->send(richGatewayMessage(
        $contact->id,
        'fake:1',
        WhatsappContent::buttons([['id' => 'a', 'title' => 'A']]),
        ['purpose' => MessagingPurpose::Transactional, 'providerHints' => richOpenWindow()],
    )));

    expect($receipt->isRecorded())->toBeTrue()
        ->and($this->inTenant($tenant, fn () => app(ChannelGateway::class)->availability(MarketingChannel::Whatsapp)->testDouble))->toBeTrue();
});
