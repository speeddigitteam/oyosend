<?php

declare(strict_types=1);

use App\Modules\Communication\Channels\Contracts\SmsChannel;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Channels\HmacSignature;
use App\Modules\Communication\Channels\ProviderRegistry;
use App\Modules\Communication\Channels\Providers\LaravelMailProvider;
use App\Modules\Communication\Channels\Providers\NullProvider;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Testing\InMemoryProvider;

it('normalizes addresses per channel', function () {
    expect(Channel::Email->normalize('  Rahim@Example.COM '))->toBe('rahim@example.com')
        ->and(Channel::Email->normalize('not an email'))->toBeNull()
        ->and(Channel::Sms->normalize('+880 1711-000000'))->toBe('+8801711000000')
        ->and(Channel::Sms->normalize('0171-1000000'))->toBe('+8801711000000')
        ->and(Channel::Whatsapp->normalize('12'))->toBeNull();
});

it('keeps credentials out of dumps, strings and serialization', function () {
    $credentials = new ChannelCredentials('super-secret-value');

    expect((string) $credentials)->not->toContain('super-secret')
        ->and(print_r($credentials, true))->not->toContain('super-secret')
        ->and(var_export(get_object_vars($credentials), true))->not->toContain('super-secret')
        ->and(fn () => serialize($credentials))->toThrow(LogicException::class)
        ->and($credentials->secret())->toBe('super-secret-value');
});

it('verifies an HMAC signature in constant time and fails closed', function () {
    $body = '{"events":[]}';
    $good = HmacSignature::sign($body, 'secret');

    HmacSignature::assertValid($body, $good, 'secret');
    HmacSignature::assertValid($body, strtoupper($good), 'secret');

    foreach ([[null, 'secret'], ['', 'secret'], [$good, 'other'], [$good, ''], ['deadbeef', 'secret']] as [$signature, $secret]) {
        expect(fn () => HmacSignature::assertValid($body, $signature, $secret))->toThrow(InvalidWebhookSignature::class);
    }

    expect(fn () => HmacSignature::assertValid($body.' ', $good, 'secret'))->toThrow(InvalidWebhookSignature::class);
});

it('reports an SMS channel with no provider as not configured and refuses to send', function () {
    $none = NullProvider::sms();
    $connection = new ChannelConnection(['channel' => Channel::Sms, 'provider' => 'none']);

    expect($none)->toBeInstanceOf(SmsChannel::class)
        ->and($none->state($connection))->toBe(ConfigurationState::NotConfigured)
        ->and($none->isTestDouble())->toBeFalse();
});

it('registers each provider once under the interface of its channel', function () {
    $registry = new ProviderRegistry;
    $registry->register(new LaravelMailProvider);
    $registry->register(NullProvider::sms());

    expect($registry->find(Channel::Email, 'mail'))->toBeInstanceOf(LaravelMailProvider::class)
        ->and($registry->fallback(Channel::Email)->key())->toBe('mail')
        ->and($registry->fallback(Channel::Sms)->key())->toBe('none')
        ->and(fn () => $registry->register(NullProvider::sms()))->toThrow(InvalidArgumentException::class)
        ->and($registry->findByKey('nope'))->toBeNull();
});

it('flags the in-memory provider as a test double', function () {
    expect(InMemoryProvider::sms()->isTestDouble())->toBeTrue()
        ->and(InMemoryProvider::sms()->key())->toBe('fake_sms');
});
