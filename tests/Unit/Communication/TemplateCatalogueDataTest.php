<?php

declare(strict_types=1);

use App\Core\Communication\Data\Template\ProviderTemplateStatus;
use App\Core\Communication\Data\Template\TemplateButton;
use App\Core\Communication\Data\Template\TemplateCatalogueStatus;
use App\Core\Communication\Data\Template\TemplateCategory;
use App\Core\Communication\Data\Template\TemplateComponent;
use App\Core\Communication\Data\Template\TemplateDraft;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Providers\WhatsAppCloudProvider;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Template drafts are validated like Meta validates them (ADR 0022); the Cloud API template edge is exercised with
 * every HTTP call faked, so nothing here proves the adapter against live Meta.
 */

function tplConnection(): ChannelConnection
{
    return new ChannelConnection(['channel' => Channel::Whatsapp, 'provider' => 'whatsapp_cloud', 'is_enabled' => true, 'settings' => ['phone_number_id' => '109876543210', 'business_account_id' => '555000111'], 'credential_ref' => 'wa_token']);
}

beforeEach(function () {
    config(['communication.whatsapp.graph_base_url' => 'https://graph.facebook.com', 'communication.whatsapp.api_version' => 'v21.0']);
    $this->provider = new WhatsAppCloudProvider;
    $this->token = new ChannelCredentials('EAAtoken');
});

it('requires an example for every body variable and numbers them without gaps', function () {
    expect(TemplateComponent::body('Hi {{1}}, your order {{2}} shipped.', ['Rahim', '#42'])->examples)->toBe(['Rahim', '#42'])
        ->and(TemplateComponent::body('No variables here.')->examples)->toBe([]);

    foreach ([
        fn () => TemplateComponent::body('Hi {{1}}, shipped.', []),
        fn () => TemplateComponent::body('Hi {{1}}, shipped.', ['']),
        fn () => TemplateComponent::body('Hi {{1}} and {{3}}.', ['a', 'b']),
        fn () => TemplateComponent::body('{{1}} is your code.', ['1']),
        fn () => TemplateComponent::body('Your code is {{1}}', ['1']),
        fn () => TemplateComponent::body('Hi {{name}}.', ['a']),
        fn () => TemplateComponent::body(''),
        fn () => TemplateComponent::body(str_repeat('a', 1025)),
    ] as $make) {
        expect($make)->toThrow(InvalidArgumentException::class);
    }
});

it('limits header and footer text and media headers', function () {
    expect(TemplateComponent::headerText('Order {{1}}', ['#42'])->examples)->toBe(['#42'])
        ->and(TemplateComponent::headerMedia('image', 'handle-123')->headerHandle)->toBe('handle-123')
        ->and(TemplateComponent::footer('Thanks')->text)->toBe('Thanks');

    foreach ([
        fn () => TemplateComponent::headerText(str_repeat('h', 61)),
        fn () => TemplateComponent::headerText('{{1}} {{2}}', ['a', 'b']),
        fn () => TemplateComponent::headerText('Order {{1}}'),
        fn () => TemplateComponent::headerMedia('audio', 'h'),
        fn () => TemplateComponent::headerMedia('image', ''),
        fn () => TemplateComponent::footer('Hi {{1}}'),
        fn () => TemplateComponent::footer(str_repeat('f', 61)),
    ] as $make) {
        expect($make)->toThrow(InvalidArgumentException::class);
    }
});

it('limits buttons by kind and length', function () {
    $buttons = TemplateComponent::buttons([
        TemplateButton::quickReply('Stop'),
        TemplateButton::url('Track', 'https://example.com/t/{{1}}', 'https://example.com/t/42'),
        TemplateButton::phoneNumber('Call us', '+8801700000000'),
    ]);
    expect($buttons->buttons)->toHaveCount(3);

    foreach ([
        fn () => TemplateButton::quickReply(str_repeat('b', 26)),
        fn () => TemplateButton::url('Go', 'https://example.com/{{1}}'),
        fn () => TemplateButton::url('Go', 'https://example.com/{{1}}/x', 'https://example.com/1/x'),
        fn () => TemplateButton::url('Go', 'javascript:alert(1)'),
        fn () => TemplateButton::phoneNumber('Call', '12345'),
        fn () => TemplateButton::copyCode(str_repeat('c', 16)),
        fn () => TemplateComponent::buttons([]),
        fn () => TemplateComponent::buttons(array_map(fn (int $i): TemplateButton => TemplateButton::quickReply("B{$i}"), range(1, 11))),
        fn () => TemplateComponent::buttons([TemplateButton::phoneNumber('A', '+8801700000000'), TemplateButton::phoneNumber('B', '+8801700000001')]),
        fn () => TemplateComponent::buttons([TemplateButton::url('A', 'https://a.example'), TemplateButton::url('B', 'https://b.example'), TemplateButton::url('C', 'https://c.example')]),
    ] as $make) {
        expect($make)->toThrow(InvalidArgumentException::class);
    }
});

it('validates the draft: name, language, one body and no authentication category', function () {
    $body = TemplateComponent::body('Hello.');

    expect((new TemplateDraft('order_update', 'en_US', TemplateCategory::Utility, [$body]))->ordered())->toHaveCount(1);

    foreach ([
        fn () => new TemplateDraft('Order Update', 'en', TemplateCategory::Utility, [$body]),
        fn () => new TemplateDraft('order-update', 'en', TemplateCategory::Utility, [$body]),
        fn () => new TemplateDraft('ok', 'English', TemplateCategory::Utility, [$body]),
        fn () => new TemplateDraft('ok', 'en', TemplateCategory::Authentication, [$body]),
        fn () => new TemplateDraft('ok', 'en', TemplateCategory::Utility, []),
        fn () => new TemplateDraft('ok', 'en', TemplateCategory::Utility, [$body, $body]),
        fn () => new TemplateDraft('ok', 'en', TemplateCategory::Utility, [$body, TemplateComponent::footer('a'), TemplateComponent::footer('b')]),
    ] as $make) {
        expect($make)->toThrow(InvalidArgumentException::class);
    }
});

it('orders components as the provider expects them', function () {
    $draft = new TemplateDraft('t', 'en', TemplateCategory::Marketing, [
        TemplateComponent::buttons([TemplateButton::quickReply('Stop')]),
        TemplateComponent::footer('Bye'),
        TemplateComponent::body('Hi.'),
        TemplateComponent::headerText('Sale'),
    ]);

    expect(array_map(fn (TemplateComponent $c): string => $c->type, $draft->ordered()))->toBe(['header', 'body', 'footer', 'buttons']);
});

it('lists templates with normalized components and approval status', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'data' => [
            [
                'id' => '1001', 'name' => 'order_update', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'quality_score' => ['score' => 'GREEN'],
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}', 'example' => ['header_text' => ['#42']]],
                    ['type' => 'BODY', 'text' => 'Hi {{1}}, it shipped.', 'example' => ['body_text' => [['Rahim']]]],
                    ['type' => 'FOOTER', 'text' => 'Thanks'],
                    ['type' => 'BUTTONS', 'buttons' => [
                        ['type' => 'URL', 'text' => 'Track', 'url' => 'https://example.com/t/{{1}}', 'example' => ['https://example.com/t/42']],
                        ['type' => 'QUICK_REPLY', 'text' => 'Stop'],
                        ['type' => 'OTP', 'text' => 'Skipped'],
                    ]],
                ],
            ],
            ['id' => '1002', 'name' => 'promo', 'language' => 'bn', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'INVALID_FORMAT', 'components' => []],
            ['id' => '1003', 'name' => 'old', 'language' => 'en', 'status' => 'SOMETHING_NEW', 'category' => 'MARKETING', 'rejected_reason' => 'NONE'],
            ['id' => ['bad'], 'name' => 'broken'],
        ],
        'paging' => ['cursors' => ['before' => 'b', 'after' => 'CURSOR_2'], 'next' => 'https://graph.facebook.com/next'],
    ], 200)]);

    $result = $this->provider->listTemplates(tplConnection(), $this->token, '555000111', ProviderTemplateStatus::Approved, 'order', 20, 'CURSOR_1');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/555000111/message_templates')
            && $query['status'] === 'APPROVED' && $query['name'] === 'order' && $query['limit'] === '20' && $query['after'] === 'CURSOR_1'
            && str_contains($query['fields'], 'components')
            && $request->hasHeader('Authorization', 'Bearer EAAtoken');
    });

    expect($result->status)->toBe(TemplateCatalogueStatus::Ok)
        ->and($result->templates)->toHaveCount(3)
        ->and($result->nextCursor)->toBe('CURSOR_2');

    [$first, $second, $third] = $result->templates;

    expect($first->name)->toBe('order_update')
        ->and($first->status)->toBe(ProviderTemplateStatus::Approved)
        ->and($first->category)->toBe(TemplateCategory::Utility)
        ->and($first->qualityScore)->toBe('green')
        ->and(array_map(fn (TemplateComponent $c): string => $c->type, $first->components))->toBe(['header', 'body', 'footer', 'buttons'])
        ->and($first->components[0]->examples)->toBe(['#42'])
        ->and($first->components[1]->examples)->toBe(['Rahim'])
        ->and($first->components[3]->buttons)->toHaveCount(2)
        ->and($first->components[3]->buttons[0]->url)->toBe('https://example.com/t/{{1}}')
        ->and($second->status)->toBe(ProviderTemplateStatus::Rejected)
        ->and($second->rejectedReason)->toBe('INVALID_FORMAT')
        ->and($third->status)->toBe(ProviderTemplateStatus::Unknown)
        ->and($third->rejectedReason)->toBeNull();
});

it('has no next cursor on the last page', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [], 'paging' => ['cursors' => ['after' => 'X']]], 200)]);

    expect($this->provider->listTemplates(tplConnection(), $this->token, '555000111', null, null, 50, null)->nextCursor)->toBeNull();
});

it('submits a template in the shape Meta expects, examples included', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '2001', 'status' => 'PENDING', 'category' => 'UTILITY'], 200)]);

    $draft = new TemplateDraft('order_update', 'en_US', TemplateCategory::Utility, [
        TemplateComponent::headerText('Order {{1}}', ['#42']),
        TemplateComponent::body('Hi {{1}}, it shipped.', ['Rahim']),
        TemplateComponent::footer('Thanks'),
        TemplateComponent::buttons([
            TemplateButton::url('Track', 'https://example.com/t/{{1}}', 'https://example.com/t/42'),
            TemplateButton::phoneNumber('Call', '+8801700000000'),
            TemplateButton::quickReply('Stop'),
            TemplateButton::copyCode('SAVE10'),
        ]),
    ], allowCategoryChange: true);

    $result = $this->provider->createTemplate(tplConnection(), $this->token, '555000111', $draft);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v21.0/555000111/message_templates'
        && $request->data() === [
            'name' => 'order_update',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}', 'example' => ['header_text' => ['#42']]],
                ['type' => 'BODY', 'text' => 'Hi {{1}}, it shipped.', 'example' => ['body_text' => [['Rahim']]]],
                ['type' => 'FOOTER', 'text' => 'Thanks'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Track', 'url' => 'https://example.com/t/{{1}}', 'example' => ['https://example.com/t/42']],
                    ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+8801700000000'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Stop'],
                    ['type' => 'COPY_CODE', 'example' => 'SAVE10'],
                ]],
            ],
            'allow_category_change' => true,
        ]);

    expect($result->status)->toBe(TemplateCatalogueStatus::Ok)
        ->and($result->template->providerTemplateId)->toBe('2001')
        ->and($result->template->status)->toBe(ProviderTemplateStatus::Pending)
        ->and($result->template->category)->toBe(TemplateCategory::Utility);
});

it('submits a media header with its example handle', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '2002', 'status' => 'PENDING'], 200)]);

    $this->provider->createTemplate(tplConnection(), $this->token, '555000111', new TemplateDraft('promo', 'en', TemplateCategory::Marketing, [
        TemplateComponent::headerMedia('image', '4::aW1hZ2U='),
        TemplateComponent::body('Big sale.'),
    ]));

    Http::assertSent(fn (Request $request): bool => $request['components'][0] === ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['4::aW1hZ2U=']]]);
});

it('says why Meta refused a template, without leaking the token', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter', 'error_user_msg' => 'A template with this name and language already exists.']], 400)]);

    $result = $this->provider->createTemplate(tplConnection(), $this->token, '555000111', new TemplateDraft('promo', 'en', TemplateCategory::Marketing, [TemplateComponent::body('Big sale.')]));

    expect($result->status)->toBe(TemplateCatalogueStatus::Rejected)
        ->and($result->reason)->toBe('provider_rejected')
        ->and($result->detail)->toBe('A template with this name and language already exists.')
        ->and(json_encode($result))->not->toContain('EAAtoken');
});

it('maps authentication, rate limit, outage and unreachable provider answers', function () {
    $cases = [
        [Http::response(['error' => ['code' => 190, 'message' => 'Token expired']], 401), TemplateCatalogueStatus::Failed, 'provider_authentication_failed'],
        [Http::response(['error' => ['code' => 80008, 'message' => 'Rate limit']], 400), TemplateCatalogueStatus::Failed, 'provider_rate_limited'],
        [Http::response('boom', 503), TemplateCatalogueStatus::Failed, 'provider_unavailable'],
        [Http::response(['error' => ['message' => 'Unknown']], 404), TemplateCatalogueStatus::NotFound, 'provider_not_found'],
    ];

    foreach ($cases as [$response, $status, $reason]) {
        Http::swap(new Factory);
        Http::fake(['graph.facebook.com/*' => $response]);
        $result = $this->provider->listTemplates(tplConnection(), $this->token, '555000111', null, null, 10, null);

        expect($result->status)->toBe($status)->and($result->reason)->toBe($reason);
    }

    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 6')]);
    $result = $this->provider->listTemplates(tplConnection(), $this->token, '555000111', null, null, 10, null);
    expect($result->status)->toBe(TemplateCatalogueStatus::Failed)->and($result->reason)->toBe('provider_unreachable');
});

it('deletes by name, optionally by provider id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    $result = $this->provider->deleteTemplate(tplConnection(), $this->token, '555000111', 'order_update', '1001');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'DELETE'
            && str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/555000111/message_templates?')
            && $query === ['name' => 'order_update', 'hsm_id' => '1001'];
    });
    expect($result->status)->toBe(TemplateCatalogueStatus::Ok);

    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => false], 200)]);
    expect($this->provider->deleteTemplate(tplConnection(), $this->token, '555000111', 'order_update', null)->status)->toBe(TemplateCatalogueStatus::Failed);
});
