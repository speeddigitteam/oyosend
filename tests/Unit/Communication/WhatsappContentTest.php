<?php

declare(strict_types=1);

use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\Whatsapp\MediaReference;
use App\Core\Communication\Data\Whatsapp\WhatsappContent;
use App\Core\Communication\Data\Whatsapp\WhatsappContentKind;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;

/*
 * The rich WhatsApp content value objects (ADR 0022): validated on construction against the platform's limits,
 * lossless through JSON, never carrying a path or a URL for media.
 */

function richMessage(?WhatsappContent $content, array $overrides = []): MarketingMessage
{
    return new MarketingMessage(...[
        'module' => 'whatsapp_marketing',
        'idempotencyKey' => 'rich:1',
        'channel' => MarketingChannel::Whatsapp,
        'contactId' => '01HZ0000000000000000000001',
        'subject' => null,
        'body' => 'Pick one',
        'whatsapp' => $content,
        ...$overrides,
    ]);
}

it('builds reply buttons within Meta limits and refuses everything outside them', function () {
    $content = WhatsappContent::buttons([['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']], 'Header', null, 'Footer');

    expect($content->kind)->toBe(WhatsappContentKind::Buttons)
        ->and($content->kind->requiresOpenWindow())->toBeTrue()
        ->and($content->spec['buttons'])->toHaveCount(2);

    $bad = [
        [[]],
        array_map(fn (int $i): array => ['id' => "b{$i}", 'title' => "B{$i}"], range(1, 4)),
        [['id' => 'a', 'title' => str_repeat('x', 21)]],
        [['id' => str_repeat('i', 257), 'title' => 'ok']],
        [['id' => 'a', 'title' => 'Same'], ['id' => 'b', 'title' => 'same']],
        [['id' => 'a', 'title' => 'One'], ['id' => 'a', 'title' => 'Two']],
    ];

    foreach ($bad as $buttons) {
        expect(fn () => WhatsappContent::buttons($buttons))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => WhatsappContent::buttons([['id' => 'a', 'title' => 'A']], str_repeat('h', 61)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::buttons([['id' => 'a', 'title' => 'A']], null, null, str_repeat('f', 61)))->toThrow(InvalidArgumentException::class);
});

it('builds list messages with at most 10 rows over at most 10 sections', function () {
    $rows = fn (int $from, int $to): array => array_map(fn (int $i): array => ['id' => "r{$i}", 'title' => "Row {$i}", 'description' => 'd'], range($from, $to));

    $content = WhatsappContent::list('Choose', [['title' => 'A', 'rows' => $rows(1, 6)], ['title' => 'B', 'rows' => $rows(7, 10)]]);

    expect($content->kind)->toBe(WhatsappContentKind::List)->and($content->spec['sections'])->toHaveCount(2);

    expect(fn () => WhatsappContent::list('Choose', [['rows' => $rows(1, 11)]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::list('Choose', [['title' => 'A', 'rows' => $rows(1, 2)], ['rows' => $rows(3, 4)]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::list(str_repeat('x', 21), [['rows' => $rows(1, 2)]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::list('Choose', [['rows' => [['id' => 'a', 'title' => str_repeat('t', 25)]]]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::list('Choose', [['rows' => [['id' => 'a', 'title' => 'T', 'description' => str_repeat('d', 73)]]]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::list('Choose', [['rows' => [['id' => 'a', 'title' => 'A'], ['id' => 'a', 'title' => 'B']]]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::list('Choose', [['title' => 'A', 'rows' => []]]))->toThrow(InvalidArgumentException::class);
});

it('accepts a call-to-action only with an absolute http(s) URL', function () {
    expect(WhatsappContent::ctaUrl('Open', 'https://example.com/pay?id=1')->kind)->toBe(WhatsappContentKind::CtaUrl);

    foreach (['javascript:alert(1)', 'ftp://example.com', '/relative', 'example.com', 'https://'.str_repeat('a', 2000).'.com'] as $url) {
        expect(fn () => WhatsappContent::ctaUrl('Open', $url))->toThrow(InvalidArgumentException::class);
    }
});

it('references media by id only and never by path or URL', function () {
    $stored = new MediaReference(WhatsappMediaType::Image, mediaId: '01HZ0000000000000000000009');
    $provider = new MediaReference(WhatsappMediaType::Document, providerMediaId: '1234567890', filename: 'invoice.pdf');

    expect($stored->toArray())->toBe(['type' => 'image', 'media_id' => '01HZ0000000000000000000009'])
        ->and($provider->toArray()['filename'])->toBe('invoice.pdf');

    foreach ([
        fn () => new MediaReference(WhatsappMediaType::Image),
        fn () => new MediaReference(WhatsappMediaType::Image, mediaId: 'a', providerMediaId: 'b'),
        fn () => new MediaReference(WhatsappMediaType::Image, mediaId: '../../etc/passwd'),
        fn () => new MediaReference(WhatsappMediaType::Image, mediaId: 'https://evil.example/x.png'),
        fn () => new MediaReference(WhatsappMediaType::Document, providerMediaId: '1', filename: '../x.pdf'),
    ] as $make) {
        expect($make)->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => WhatsappContent::media(new MediaReference(WhatsappMediaType::Audio, providerMediaId: '1'), 'caption'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::buttons([['id' => 'a', 'title' => 'A']], null, new MediaReference(WhatsappMediaType::Audio, providerMediaId: '1')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::media(new MediaReference(WhatsappMediaType::Image, providerMediaId: '1'), str_repeat('c', 1025)))->toThrow(InvalidArgumentException::class);
});

it('builds templates with header media and button parameters, replacing empty parameters', function () {
    $content = WhatsappContent::template(
        'order_update',
        'en_US',
        ["Rahim\nUddin", '', 'Order #42'],
        headerMedia: new MediaReference(WhatsappMediaType::Image, mediaId: '01HZ0000000000000000000009'),
        buttons: [['sub_type' => 'url', 'index' => 0, 'value' => 'orders/42'], ['sub_type' => 'quick_reply', 'index' => 1, 'value' => 'track']],
    );

    expect($content->kind->requiresOpenWindow())->toBeFalse()
        ->and($content->spec['body_parameters'])->toBe(['Rahim Uddin', '-', 'Order #42'])
        ->and($content->mediaReferences())->toHaveCount(1);

    foreach ([
        fn () => WhatsappContent::template('Order Update', 'en'),
        fn () => WhatsappContent::template('ok', 'english'),
        fn () => WhatsappContent::template('ok', 'en', headerText: 'x', headerMedia: new MediaReference(WhatsappMediaType::Image, providerMediaId: '1')),
        fn () => WhatsappContent::template('ok', 'en', array_fill(0, 21, 'v')),
        fn () => WhatsappContent::template('ok', 'en', buttons: [['sub_type' => 'phone', 'index' => 0, 'value' => '1']]),
        fn () => WhatsappContent::template('ok', 'en', buttons: [['sub_type' => 'copy_code', 'index' => 0, 'value' => str_repeat('c', 16)]]),
        fn () => WhatsappContent::template('ok', 'en', buttons: [['sub_type' => 'url', 'index' => 10, 'value' => 'x']]),
    ] as $make) {
        expect($make)->toThrow(InvalidArgumentException::class);
    }
});

it('builds product, product list and Flow content within the limits', function () {
    expect(WhatsappContent::product('1234567', 'sku-1')->kind)->toBe(WhatsappContentKind::Product);

    $list = WhatsappContent::productList('1234567', 'Our picks', [['title' => 'New', 'product_retailer_ids' => ['a', 'b']]]);
    expect($list->kind)->toBe(WhatsappContentKind::ProductList);

    $many = [['title' => 'All', 'product_retailer_ids' => array_map(fn (int $i): string => "sku-{$i}", range(1, 31))]];
    expect(fn () => WhatsappContent::productList('1234567', 'Our picks', $many))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::productList('1234567', '', [['title' => 'A', 'product_retailer_ids' => ['a']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::product('cat log', 'sku'))->toThrow(InvalidArgumentException::class);

    $flow = WhatsappContent::flow('99887766', 'Book now', 'tok-1', 'WELCOME', ['name' => 'Rahim', 'vip' => true]);
    expect($flow->kind)->toBe(WhatsappContentKind::Flow);

    expect(fn () => WhatsappContent::flow('99887766', 'Book now', 'tok', null, ['a' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::flow('99887766', 'Book now', 'tok', 'S', ['nested' => ['x']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::flow('99887766', 'Book now', 'tok', 'S', ['big' => str_repeat('x', 4100)]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::flow('99887766', 'A very long button label', 'tok'))->toThrow(InvalidArgumentException::class);
});

it('survives JSON and re-validates what it reads back', function () {
    $content = WhatsappContent::list('Choose', [['title' => 'A', 'rows' => [['id' => 'r1', 'title' => 'Row 1']]], ['title' => 'B', 'rows' => [['id' => 'r2', 'title' => 'Row 2', 'description' => 'x']]]], 'Head', 'Foot');

    expect(WhatsappContent::fromJson($content->toJson())->spec)->toBe($content->spec);

    $tampered = json_decode($content->toJson(), true);
    $tampered['sections'][0]['rows'][0]['title'] = str_repeat('x', 30);

    expect(fn () => WhatsappContent::fromJson((string) json_encode($tampered)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::fromJson('not json'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::fromJson('{"kind":"nope"}'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WhatsappContent::fromJson(str_repeat('a', WhatsappContent::MAX_JSON + 1)))->toThrow(InvalidArgumentException::class);
});

it('swaps stored media ids for provider ids', function () {
    $content = WhatsappContent::template('t', 'en', headerMedia: new MediaReference(WhatsappMediaType::Image, mediaId: 'M1'));
    $swapped = $content->withProviderMedia(['M1' => '555']);

    expect($swapped->mediaReferences()[0]->providerMediaId)->toBe('555')
        ->and($swapped->mediaReferences()[0]->mediaId)->toBeNull();
});

it('is accepted by a marketing message only on WhatsApp and within the body limit', function () {
    $content = WhatsappContent::buttons([['id' => 'a', 'title' => 'A']]);

    expect(richMessage($content)->whatsapp)->toBe($content)
        ->and(fn () => richMessage($content, ['channel' => MarketingChannel::Sms]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => richMessage($content, ['body' => str_repeat('b', 1025)]))->toThrow(InvalidArgumentException::class);

    expect(richMessage(null, ['senderNumberId' => '01HZ0000000000000000000002'])->senderNumberId)->toBe('01HZ0000000000000000000002')
        ->and(fn () => richMessage(null, ['senderNumberId' => 'not-an-id']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => richMessage(null, ['channel' => MarketingChannel::Email, 'subject' => 'Hi', 'senderNumberId' => '01HZ0000000000000000000002']))->toThrow(InvalidArgumentException::class);
});
