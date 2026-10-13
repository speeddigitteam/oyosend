<?php

declare(strict_types=1);

use App\Core\Communication\Data\Whatsapp\MediaReference;
use App\Core\Communication\Data\Whatsapp\WhatsappContent;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Providers\WhatsAppCloudProvider;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The Cloud API mapping of rich WhatsApp content (ADR 0022), field by field, with every HTTP call faked.
 * Nothing here reaches Meta: the shapes follow Meta's documented message objects but are not proven live.
 */

function richConnection(): ChannelConnection
{
    return new ChannelConnection(['channel' => Channel::Whatsapp, 'provider' => 'whatsapp_cloud', 'is_enabled' => true, 'settings' => ['phone_number_id' => '109876543210'], 'credential_ref' => 'wa_token']);
}

function richPayload(WhatsappContent $content, string $body = 'Hello', array $meta = [], ?string $senderAccountId = null): OutboundPayload
{
    return new OutboundPayload(
        'msg-1',
        Channel::Whatsapp,
        '+8801711000001',
        null,
        $body,
        'en',
        meta: ['whatsapp_content' => $content->toJson(), 'whatsapp_window_until' => now()->addHours(2)->toIso8601String(), ...$meta],
        senderAccountId: $senderAccountId,
    );
}

/** @return array<string, mixed> the JSON body sent to Graph */
function richSent(WhatsappContent $content, string $body = 'Hello', array $meta = [], ?string $senderAccountId = null): array
{
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RICH']]], 200)]);

    $result = (new WhatsAppCloudProvider)->send(richPayload($content, $body, $meta, $senderAccountId), richConnection(), new ChannelCredentials('EAAtoken'));

    expect($result->outcome)->toBe(SendResult::ACCEPTED)->and($result->providerMessageId)->toBe('wamid.RICH');

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = $request->data();

        return true;
    });

    return $sent;
}

beforeEach(function () {
    config(['communication.whatsapp.graph_base_url' => 'https://graph.facebook.com', 'communication.whatsapp.api_version' => 'v21.0']);
});

it('sends reply buttons with header text and footer', function () {
    $sent = richSent(WhatsappContent::buttons([['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']], 'Order 42', null, 'Reply now'), 'Confirm your order?');

    expect($sent)->toMatchArray(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '8801711000001', 'type' => 'interactive'])
        ->and($sent['interactive'])->toBe([
            'type' => 'button',
            'header' => ['type' => 'text', 'text' => 'Order 42'],
            'body' => ['text' => 'Confirm your order?'],
            'footer' => ['text' => 'Reply now'],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => 'yes', 'title' => 'Yes']],
                ['type' => 'reply', 'reply' => ['id' => 'no', 'title' => 'No']],
            ]],
        ]);
});

it('sends a sectioned list message', function () {
    $content = WhatsappContent::list('Pick a plan', [
        ['title' => 'Monthly', 'rows' => [['id' => 'm1', 'title' => 'Basic', 'description' => 'Starter']]],
        ['title' => 'Yearly', 'rows' => [['id' => 'y1', 'title' => 'Pro']]],
    ]);

    $sent = richSent($content, 'Which plan?');

    expect($sent['interactive']['type'])->toBe('list')
        ->and($sent['interactive']['action'])->toBe([
            'button' => 'Pick a plan',
            'sections' => [
                ['title' => 'Monthly', 'rows' => [['id' => 'm1', 'title' => 'Basic', 'description' => 'Starter']]],
                ['title' => 'Yearly', 'rows' => [['id' => 'y1', 'title' => 'Pro']]],
            ],
        ]);
});

it('sends a call-to-action URL button', function () {
    $sent = richSent(WhatsappContent::ctaUrl('Pay now', 'https://example.com/pay/42'), 'Your invoice is ready');

    expect($sent['interactive'])->toBe([
        'type' => 'cta_url',
        'body' => ['text' => 'Your invoice is ready'],
        'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => 'Pay now', 'url' => 'https://example.com/pay/42']],
    ]);
});

it('sends media messages by provider media id with caption and file name', function () {
    $image = richSent(WhatsappContent::media(new MediaReference(WhatsappMediaType::Image, providerMediaId: '111'), 'Look'), 'photo');
    expect($image['type'])->toBe('image')->and($image['image'])->toBe(['id' => '111', 'caption' => 'Look']);

    $document = richSent(WhatsappContent::media(new MediaReference(WhatsappMediaType::Document, providerMediaId: '222', filename: 'invoice.pdf'), 'Invoice'), 'doc');
    expect($document['type'])->toBe('document')->and($document['document'])->toBe(['id' => '222', 'caption' => 'Invoice', 'filename' => 'invoice.pdf']);

    $audio = richSent(WhatsappContent::media(new MediaReference(WhatsappMediaType::Audio, providerMediaId: '333')), 'voice');
    expect($audio['audio'])->toBe(['id' => '333']);

    $video = richSent(WhatsappContent::media(new MediaReference(WhatsappMediaType::Video, providerMediaId: '444')), 'clip');
    expect($video['video'])->toBe(['id' => '444']);
});

it('sends a template with header media, body parameters and button parameters at any time', function () {
    $content = WhatsappContent::template(
        'order_update',
        'en_US',
        ['Rahim', 'Order #42'],
        headerMedia: new MediaReference(WhatsappMediaType::Document, providerMediaId: '555', filename: 'receipt.pdf'),
        buttons: [['sub_type' => 'url', 'index' => 0, 'value' => 'orders/42'], ['sub_type' => 'quick_reply', 'index' => 1, 'value' => 'track'], ['sub_type' => 'copy_code', 'index' => 2, 'value' => 'SAVE10']],
    );

    // No service window: a template does not need one.
    $sent = richSent($content, 'Order update', ['whatsapp_window_until' => now()->subDay()->toIso8601String()]);

    expect($sent['type'])->toBe('template')
        ->and($sent['template']['name'])->toBe('order_update')
        ->and($sent['template']['language'])->toBe(['code' => 'en_US'])
        ->and($sent['template']['components'])->toBe([
            ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => '555', 'filename' => 'receipt.pdf']]]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Rahim'], ['type' => 'text', 'text' => 'Order #42']]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'orders/42']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1', 'parameters' => [['type' => 'payload', 'payload' => 'track']]],
            ['type' => 'button', 'sub_type' => 'copy_code', 'index' => '2', 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'SAVE10']]],
        ]);
});

it('sends a text header on a template and an image header on a button message', function () {
    $template = richSent(WhatsappContent::template('promo', 'en', ['x'], headerText: 'Big sale'), 'promo');
    expect($template['template']['components'][0])->toBe(['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Big sale']]]);

    $buttons = richSent(WhatsappContent::buttons([['id' => 'a', 'title' => 'A']], null, new MediaReference(WhatsappMediaType::Image, providerMediaId: '777')), 'Look');
    expect($buttons['interactive']['header'])->toBe(['type' => 'image', 'image' => ['id' => '777']]);
});

it('sends a product and a product list from a catalogue', function () {
    $product = richSent(WhatsappContent::product('1234567', 'sku-1', 'Free delivery'), 'Our best seller');
    expect($product['interactive'])->toBe([
        'type' => 'product',
        'body' => ['text' => 'Our best seller'],
        'footer' => ['text' => 'Free delivery'],
        'action' => ['catalog_id' => '1234567', 'product_retailer_id' => 'sku-1'],
    ]);

    $list = richSent(WhatsappContent::productList('1234567', 'Picks', [['title' => 'New', 'product_retailer_ids' => ['a', 'b']]]), 'See these');
    expect($list['interactive'])->toBe([
        'type' => 'product_list',
        'header' => ['type' => 'text', 'text' => 'Picks'],
        'body' => ['text' => 'See these'],
        'action' => ['catalog_id' => '1234567', 'sections' => [['title' => 'New', 'product_items' => [['product_retailer_id' => 'a'], ['product_retailer_id' => 'b']]]]],
    ]);
});

it('sends a Flow message with the screen and its data, and a draft flow when asked', function () {
    $sent = richSent(WhatsappContent::flow('99887766', 'Book now', 'tok-1', 'WELCOME', ['name' => 'Rahim']), 'Book your slot');

    expect($sent['interactive'])->toBe([
        'type' => 'flow',
        'body' => ['text' => 'Book your slot'],
        'action' => ['name' => 'flow', 'parameters' => [
            'flow_message_version' => '3',
            'flow_token' => 'tok-1',
            'flow_id' => '99887766',
            'flow_cta' => 'Book now',
            'mode' => 'published',
            'flow_action' => 'navigate',
            'flow_action_payload' => ['screen' => 'WELCOME', 'data' => ['name' => 'Rahim']],
        ]],
    ]);

    $draft = richSent(WhatsappContent::flow('99887766', 'Book now', 'tok-2', published: false), 'Book');
    expect($draft['interactive']['action']['parameters'])->not->toHaveKey('flow_action')
        ->and($draft['interactive']['action']['parameters']['mode'])->toBe('draft');
});

it('sends from the named workspace number instead of the connection default', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

    (new WhatsAppCloudProvider)->send(richPayload(WhatsappContent::buttons([['id' => 'a', 'title' => 'A']]), senderAccountId: '555500005555'), richConnection(), new ChannelCredentials('EAAtoken'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v21.0/555500005555/messages'));

    (new WhatsAppCloudProvider)->send(richPayload(WhatsappContent::buttons([['id' => 'a', 'title' => 'A']])), richConnection(), new ChannelCredentials('EAAtoken'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v21.0/109876543210/messages'));

    $bad = (new WhatsAppCloudProvider)->send(richPayload(WhatsappContent::buttons([['id' => 'a', 'title' => 'A']]), senderAccountId: '../evil'), richConnection(), new ChannelCredentials('EAAtoken'));
    expect($bad->code)->toBe('sender_number_unavailable');
});

it('refuses every free-form kind outside the open window and without the window, but not a template', function () {
    Http::fake();
    $provider = new WhatsAppCloudProvider;
    $credentials = new ChannelCredentials('EAAtoken');

    foreach ([
        WhatsappContent::buttons([['id' => 'a', 'title' => 'A']]),
        WhatsappContent::list('Go', [['rows' => [['id' => 'r', 'title' => 'R']]]]),
        WhatsappContent::ctaUrl('Open', 'https://example.com'),
        WhatsappContent::media(new MediaReference(WhatsappMediaType::Image, providerMediaId: '1')),
        WhatsappContent::product('123456', 'sku'),
        WhatsappContent::flow('123456', 'Go', 'tok'),
    ] as $content) {
        $closed = $provider->send(richPayload($content, meta: ['whatsapp_window_until' => now()->subMinute()->toIso8601String()]), richConnection(), $credentials);
        $payload = new OutboundPayload('m', Channel::Whatsapp, '+8801711000001', null, 'Hello', 'en', meta: ['whatsapp_content' => $content->toJson()]);
        $missing = $provider->send($payload, richConnection(), $credentials);

        expect($closed->outcome)->toBe(SendResult::REJECTED)->and($closed->code)->toBe('outside_customer_service_window')
            ->and($missing->code)->toBe('outside_customer_service_window');
    }

    Http::assertNothingSent();
});

it('refuses media that was never uploaded and tampered or oversized content', function () {
    Http::fake();
    $provider = new WhatsAppCloudProvider;
    $credentials = new ChannelCredentials('EAAtoken');

    $stored = WhatsappContent::media(new MediaReference(WhatsappMediaType::Image, mediaId: '01HZ0000000000000000000009'));
    expect($provider->send(richPayload($stored), richConnection(), $credentials)->code)->toBe('media_not_uploaded');

    $payload = new OutboundPayload('m', Channel::Whatsapp, '+8801711000001', null, 'Hello', 'en', meta: ['whatsapp_content' => '{"kind":"buttons","buttons":[]}', 'whatsapp_window_until' => now()->addHour()->toIso8601String()]);
    expect($provider->send($payload, richConnection(), $credentials)->code)->toBe('invalid_content');

    expect($provider->send(richPayload(WhatsappContent::buttons([['id' => 'a', 'title' => 'A']]), str_repeat('b', 1025)), richConnection(), $credentials)->code)->toBe('invalid_content');

    Http::assertNothingSent();
});

it('maps provider failures of rich messages like any other send', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)]);

    $result = (new WhatsAppCloudProvider)->send(richPayload(WhatsappContent::buttons([['id' => 'a', 'title' => 'A']])), richConnection(), new ChannelCredentials('EAAtoken'));

    expect($result->outcome)->toBe(SendResult::REJECTED)->and($result->code)->toBe('outside_customer_service_window');
});
