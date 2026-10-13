<?php

declare(strict_types=1);

use App\Core\Communication\Data\InboundContent;
use App\Core\Communication\Data\InboundContentKind;
use App\Modules\Communication\Channels\Data\ProviderEvent;
use App\Modules\Communication\Channels\Providers\WhatsAppCloudProvider;
use App\Modules\Communication\Enums\ProviderEventType;

/*
 * Typed inbound events from the Cloud API webhook (ADR 0022). Every field is customer input: clipped, capped,
 * never trusted. The signature is verified first, as always.
 */

/**
 * @param  list<array<string, mixed>>  $messages
 * @return list<ProviderEvent>
 */
function parseInbound(array $messages, array $extraValue = []): array
{
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '555000111',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '8801700000000', 'phone_number_id' => '109876543210'],
                    'contacts' => [['profile' => ['name' => 'Rahim'], 'wa_id' => '8801711000001']],
                    ...$extraValue,
                    ...($messages === [] ? [] : ['messages' => $messages]),
                ],
            ]],
        ]],
    ];
    $body = (string) json_encode($payload);

    return (new WhatsAppCloudProvider)->parseWebhook($body, ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')], 'app-secret');
}

function inboundMessage(string $type, array $extra, string $id = 'wamid.IN1'): array
{
    return ['from' => '8801711000001', 'id' => $id, 'timestamp' => '1700000000', 'type' => $type, ...$extra];
}

it('leaves plain text without typed content', function () {
    $event = parseInbound([inboundMessage('text', ['text' => ['body' => 'hi']])])[0];

    expect($event->type)->toBe(ProviderEventType::Inbound)->and($event->text)->toBe('hi')->and($event->content)->toBeNull();
});

it('parses a reply-button tap with its id and title and the message it answered', function () {
    $event = parseInbound([inboundMessage('interactive', [
        'context' => ['from' => '8801700000000', 'id' => 'wamid.OUT1'],
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'yes', 'title' => 'Yes, confirm']],
    ])])[0];

    expect($event->text)->toBe('Yes, confirm')
        ->and($event->content->kind)->toBe(InboundContentKind::ButtonReply)
        ->and($event->content->replyId)->toBe('yes')
        ->and($event->content->replyTitle)->toBe('Yes, confirm')
        ->and($event->content->contextMessageId)->toBe('wamid.OUT1')
        ->and($event->meta['context_message_id'])->toBe('wamid.OUT1')
        ->and($event->accounts)->toBe(['phone_number_id' => '109876543210']);
});

it('parses a list-row choice and a template quick-reply', function () {
    $list = parseInbound([inboundMessage('interactive', ['interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'm1', 'title' => 'Basic', 'description' => 'Starter']]])])[0];
    expect($list->content->kind)->toBe(InboundContentKind::ListReply)
        ->and([$list->content->replyId, $list->content->replyTitle, $list->content->replyDescription])->toBe(['m1', 'Basic', 'Starter']);

    $button = parseInbound([inboundMessage('button', ['button' => ['payload' => 'track', 'text' => 'Track order']])])[0];
    expect($button->content->kind)->toBe(InboundContentKind::TemplateButton)
        ->and($button->content->replyId)->toBe('track')
        ->and($button->text)->toBe('Track order');
});

it('parses media with type, mime, provider id, caption and file name, without bytes', function () {
    $image = parseInbound([inboundMessage('image', ['image' => ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg', 'sha256' => str_repeat('a', 64), 'caption' => 'My receipt']])])[0];

    expect($image->content->kind)->toBe(InboundContentKind::Media)
        ->and($image->content->media->type)->toBe('image')
        ->and($image->content->media->providerMediaId)->toBe('MEDIA1')
        ->and($image->content->media->mimeType)->toBe('image/jpeg')
        ->and($image->content->media->caption)->toBe('My receipt')
        ->and($image->text)->toBe('My receipt')
        ->and($image->meta['media_mime'])->toBe('image/jpeg');

    $voice = parseInbound([inboundMessage('audio', ['audio' => ['id' => 'MEDIA2', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]])])[0];
    expect($voice->content->media->voice)->toBeTrue()->and($voice->text)->toBeNull();

    $document = parseInbound([inboundMessage('document', ['document' => ['id' => 'MEDIA3', 'mime_type' => 'application/pdf', 'filename' => 'invoice.pdf']])])[0];
    expect($document->content->media->filename)->toBe('invoice.pdf');

    $broken = parseInbound([inboundMessage('image', ['image' => ['mime_type' => 'image/png']])])[0];
    expect($broken->content->kind)->toBe(InboundContentKind::Unsupported);
});

it('parses a location, rejecting impossible coordinates', function () {
    $event = parseInbound([inboundMessage('location', ['location' => ['latitude' => 23.8103, 'longitude' => 90.4125, 'name' => 'Dhaka office', 'address' => 'Gulshan 1']])])[0];

    expect($event->content->kind)->toBe(InboundContentKind::Location)
        ->and($event->content->latitude)->toBe(23.8103)
        ->and($event->content->longitude)->toBe(90.4125)
        ->and($event->content->locationName)->toBe('Dhaka office');

    $bad = parseInbound([inboundMessage('location', ['location' => ['latitude' => 123.0, 'longitude' => 90.0]])])[0];
    expect($bad->content->kind)->toBe(InboundContentKind::Unsupported);
});

it('parses a reaction and its removal', function () {
    $on = parseInbound([inboundMessage('reaction', ['reaction' => ['message_id' => 'wamid.OUT1', 'emoji' => "\u{1F44D}"]])])[0];
    $off = parseInbound([inboundMessage('reaction', ['reaction' => ['message_id' => 'wamid.OUT1']])])[0];

    expect($on->content->kind)->toBe(InboundContentKind::Reaction)
        ->and($on->content->reactionEmoji)->toBe("\u{1F44D}")
        ->and($on->content->reactedMessageId)->toBe('wamid.OUT1')
        ->and($off->content->reactionEmoji)->toBe('');
});

it('parses an order keeping prices as the provider wrote them, never as floats', function () {
    $event = parseInbound([inboundMessage('order', ['order' => [
        'catalog_id' => '1234567',
        'text' => 'Please deliver tomorrow',
        'product_items' => [
            ['product_retailer_id' => 'sku-1', 'quantity' => 2, 'item_price' => '149.50', 'currency' => 'BDT'],
            ['product_retailer_id' => 'sku-2', 'quantity' => '1', 'item_price' => 20, 'currency' => 'BDT'],
            ['quantity' => 9],
        ],
    ]])])[0];

    expect($event->content->kind)->toBe(InboundContentKind::Order)
        ->and($event->content->orderCatalogId)->toBe('1234567')
        ->and($event->content->orderNote)->toBe('Please deliver tomorrow')
        ->and($event->content->orderItems)->toBe([
            ['product_retailer_id' => 'sku-1', 'quantity' => 2, 'item_price' => '149.50', 'currency' => 'BDT'],
            ['product_retailer_id' => 'sku-2', 'quantity' => 1, 'item_price' => '20', 'currency' => 'BDT'],
        ]);
});

it('caps an order at 60 items', function () {
    $items = array_map(fn (int $i): array => ['product_retailer_id' => "sku-{$i}", 'quantity' => 1, 'item_price' => '1', 'currency' => 'BDT'], range(1, 100));
    $event = parseInbound([inboundMessage('order', ['order' => ['catalog_id' => '1', 'product_items' => $items]])])[0];

    expect($event->content->orderItems)->toHaveCount(InboundContent::MAX_ORDER_ITEMS);
});

it('parses a Flow response as untrusted JSON text with a decoded token', function () {
    $json = (string) json_encode(['flow_token' => 'tok-1', 'name' => 'Rahim', 'slot' => '10:00']);
    $event = parseInbound([inboundMessage('interactive', ['interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'body' => 'Sent', 'response_json' => $json]]])])[0];

    expect($event->content->kind)->toBe(InboundContentKind::FlowResponse)
        ->and($event->content->flowToken)->toBe('tok-1')
        ->and($event->content->flowResponseJson)->toBe($json)
        ->and($event->content->flowResponseTruncated)->toBeFalse()
        ->and($event->content->flowResponse())->toBe(['flow_token' => 'tok-1', 'name' => 'Rahim', 'slot' => '10:00'])
        ->and($event->text)->toBeNull();
});

it('truncates an oversized Flow response and refuses to decode it', function () {
    $json = (string) json_encode(['flow_token' => 'tok-1', 'notes' => str_repeat('x', 20000)]);
    $event = parseInbound([inboundMessage('interactive', ['interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'response_json' => $json]]])])[0];

    expect(strlen((string) $event->content->flowResponseJson))->toBeLessThanOrEqual(InboundContent::MAX_FLOW_JSON)
        ->and($event->content->flowResponseTruncated)->toBeTrue()
        ->and($event->content->flowToken)->toBeNull()
        ->and($event->content->flowResponse())->toBeNull();
});

it('treats malformed or non-object Flow JSON as undecodable, never as an error', function () {
    $event = parseInbound([inboundMessage('interactive', ['interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'response_json' => '[1,2,3]']]])])[0];
    expect($event->content->flowResponse())->toBeNull();

    $event = parseInbound([inboundMessage('interactive', ['interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'response_json' => '{not json']]])])[0];
    expect($event->content->flowResponse())->toBeNull()->and($event->content->flowToken)->toBeNull();
});

it('marks an unknown message type as unsupported and keeps going', function () {
    $events = parseInbound([
        inboundMessage('hologram', ['hologram' => ['x' => 1]], 'wamid.IN1'),
        inboundMessage('text', ['text' => ['body' => 'after']], 'wamid.IN2'),
    ]);

    expect($events)->toHaveCount(2)
        ->and($events[0]->content->kind)->toBe(InboundContentKind::Unsupported)
        ->and($events[1]->text)->toBe('after');
});

it('reports pricing facts of a status update', function () {
    $events = parseInbound([], ['statuses' => [[
        'id' => 'wamid.OUT1',
        'status' => 'delivered',
        'timestamp' => '1700000000',
        'recipient_id' => '8801711000001',
        'conversation' => ['id' => 'conv-1', 'origin' => ['type' => 'marketing']],
        'pricing' => ['billable' => true, 'pricing_model' => 'CBP', 'category' => 'marketing', 'type' => 'regular'],
    ]]]);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(ProviderEventType::Delivered)
        ->and($events[0]->meta)->toMatchArray([
            'billable' => true,
            'pricing_category' => 'marketing',
            'pricing_model' => 'CBP',
            'pricing_type' => 'regular',
            'conversation_id' => 'conv-1',
            'conversation_origin' => 'marketing',
        ]);
});
