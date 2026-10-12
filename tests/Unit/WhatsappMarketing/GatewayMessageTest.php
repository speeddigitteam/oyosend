<?php

declare(strict_types=1);

use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\MessagingPurpose;

function waMessage(array $overrides = []): MarketingMessage
{
    return new MarketingMessage(...[
        'module' => 'whatsapp_marketing',
        'idempotencyKey' => 'msg:1',
        'channel' => MarketingChannel::Whatsapp,
        'contactId' => '01HZ0000000000000000000001',
        'subject' => null,
        'body' => 'Hello',
        ...$overrides,
    ]);
}

it('defaults to a marketing message with no provider hints', function () {
    $message = waMessage();

    expect($message->purpose)->toBe(MessagingPurpose::Marketing)->and($message->providerHints)->toBe([]);
});

it('allows a transactional reply only on WhatsApp', function () {
    expect(waMessage(['purpose' => MessagingPurpose::Transactional])->purpose)->toBe(MessagingPurpose::Transactional);

    foreach ([MarketingChannel::Email, MarketingChannel::Sms] as $channel) {
        expect(fn () => waMessage(['channel' => $channel, 'subject' => 'Hi', 'purpose' => MessagingPurpose::Transactional]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('takes provider hints only under the channel prefix, so a hint cannot override a connection setting', function () {
    expect(waMessage(['providerHints' => ['whatsapp_template' => 'order_update']])->providerHints)->toBe(['whatsapp_template' => 'order_update']);

    foreach ([
        ['phone_number_id' => '999'],
        ['sms_sender' => 'x'],
        ['marketing_module' => 'someone_else'],
        ['whatsapp_template' => ['nested']],
        ['whatsapp_template' => str_repeat('a', 2001)],
        array_fill_keys(array_map(fn (int $i): string => "whatsapp_k{$i}", range(1, 11)), 'v'),
    ] as $hints) {
        expect(fn () => waMessage(['providerHints' => $hints]))->toThrow(InvalidArgumentException::class);
    }
});
