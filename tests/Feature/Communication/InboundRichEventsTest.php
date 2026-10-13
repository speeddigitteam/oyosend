<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Communication\Data\ChannelEventType;
use App\Core\Communication\Data\InboundContent;
use App\Core\Communication\Data\InboundContentKind;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

/** Collects every channel event, like an app's listener would receive them. */
final class RichEventCollector implements ChannelEventListener
{
    /** @var list<ChannelEvent> */
    public static array $events = [];

    public function handle(ChannelEvent $event): void
    {
        self::$events[] = $event;
    }
}

function richInbound(string $type, array $extra, string $id): array
{
    return ['from' => '8801711000001', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => $type, ...$extra];
}

beforeEach(function () {
    RichEventCollector::$events = [];
    $this->app->tag([RichEventCollector::class], ChannelEventListener::TAG);
    $this->tenant = $this->waTenant('inbound-rich');
    $this->waConnectCloud($this->tenant);
    $this->deliver = fn (array $message) => $this->waWebhook($this->tenant, $this->waMessagePayload('109876543210', [$message], '8801711000001'))->assertOk();
});

it('delivers a button reply with its id, title and the message it answers to listeners', function () {
    ($this->deliver)(richInbound('interactive', [
        'context' => ['id' => 'wamid.OUT-ASK'],
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'confirm_42', 'title' => 'Confirm']],
    ], 'wamid.IN-BTN'));

    $event = RichEventCollector::$events[0];

    expect($event->type)->toBe(ChannelEventType::Inbound)
        ->and($event->text)->toBe('Confirm')
        ->and($event->content->kind)->toBe(InboundContentKind::ButtonReply)
        ->and($event->content->replyId)->toBe('confirm_42')
        ->and($event->content->contextMessageId)->toBe('wamid.OUT-ASK')
        ->and($event->numberId)->not->toBeNull()
        ->and($event->details['message_type'])->toBe('interactive')
        ->and($event->details['phone_number_id'])->toBe('109876543210');
});

it('delivers a Flow response as capped untrusted JSON and an order with decimal-string prices', function () {
    ($this->deliver)(richInbound('interactive', [
        'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'body' => 'Sent', 'response_json' => '{"flow_token":"tok-9","slot":"10:00","note":"<script>alert(1)</script>"}']],
    ], 'wamid.IN-FLOW'));
    ($this->deliver)(richInbound('order', ['order' => [
        'catalog_id' => '1234567',
        'product_items' => [['product_retailer_id' => 'sku-1', 'quantity' => 3, 'item_price' => '99.90', 'currency' => 'BDT']],
    ]], 'wamid.IN-ORDER'));

    [$flow, $order] = RichEventCollector::$events;

    expect($flow->content->kind)->toBe(InboundContentKind::FlowResponse)
        ->and($flow->content->flowToken)->toBe('tok-9')
        ->and($flow->content->flowResponse()['slot'])->toBe('10:00')
        // Delivered as data, exactly as sent: escaping is the consumer's job.
        ->and($flow->content->flowResponse()['note'])->toBe('<script>alert(1)</script>')
        ->and($order->content->kind)->toBe(InboundContentKind::Order)
        ->and($order->content->orderItems[0]['item_price'])->toBe('99.90')
        ->and($order->content->orderItems[0]['quantity'])->toBe(3);
});

it('delivers media, location and reaction facts without fetching anything', function () {
    Http::swap(new Factory);
    Http::fake();

    ($this->deliver)(richInbound('document', ['document' => ['id' => 'DOC1', 'mime_type' => 'application/pdf', 'filename' => 'cv.pdf']], 'wamid.IN-DOC'));
    ($this->deliver)(richInbound('location', ['location' => ['latitude' => 23.78, 'longitude' => 90.41, 'name' => 'Office']], 'wamid.IN-LOC'));
    ($this->deliver)(richInbound('reaction', ['reaction' => ['message_id' => 'wamid.OUT-1', 'emoji' => "\u{2764}"]], 'wamid.IN-REACT'));

    [$document, $location, $reaction] = array_map(fn (ChannelEvent $event): InboundContent => $event->content, RichEventCollector::$events);

    expect($document->media->providerMediaId)->toBe('DOC1')
        ->and($document->media->filename)->toBe('cv.pdf')
        ->and($location->latitude)->toBe(23.78)
        ->and($reaction->reactedMessageId)->toBe('wamid.OUT-1');

    // The webhook only carries metadata: downloading is an explicit, later call.
    Http::assertNothingSent();
});

it('delivers a pricing category on delivery receipts', function () {
    $this->waStatus($this->tenant, 'wamid.OUT-UNKNOWN', 'delivered', ['pricing' => ['billable' => true, 'category' => 'utility', 'pricing_model' => 'CBP']])->assertOk();

    // A receipt for a message we never sent is still a verified event for listeners; the platform changes nothing.
    $event = RichEventCollector::$events[0];

    expect($event->type)->toBe(ChannelEventType::Delivered)
        ->and($event->pricingCategory)->toBe('utility')
        ->and($event->details['pricing_category'])->toBe('utility')
        ->and($event->details['billable'])->toBeTrue()
        ->and($event->details['pricing_model'])->toBe('CBP');
});

it('still rejects an unsigned or wrongly signed delivery before reading anything', function () {
    $payload = $this->waMessagePayload('109876543210', [richInbound('text', ['text' => ['body' => 'hi']], 'wamid.IN-X')], '8801711000001');

    $this->waWebhook($this->tenant, $payload, secret: null)->assertStatus(401);
    $this->waWebhook($this->tenant, $payload, secret: 'wrong-secret')->assertStatus(401);

    expect(RichEventCollector::$events)->toBe([]);
});
