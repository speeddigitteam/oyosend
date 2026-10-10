<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Billing\Models\UsageRecord;
use App\Core\Billing\SubscriptionManager;
use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Core\Identity\Actions\InviteMember;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Mail\InvitationMail;
use App\Core\Identity\Models\Invitation;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * Usage: what a workspace consumed (UsageMeter), what its plan allows
 * (UsageLimits), and the one limit the core enforces today - seats.
 *
 * The product has no metered limit yet, so the tests add a module that
 * declares one (`sms.messages`), the way an optional app will.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->meter = fn (Closure $callback, ?Tenant $tenant = null): mixed => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): mixed => $callback(app(UsageMeter::class)),
    );

    $this->limits = fn (Closure $callback, ?Tenant $tenant = null): mixed => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): mixed => $callback(app(UsageLimits::class)),
    );

    /** An optional app with a metered limit, and a gauge whose count the test sets. */
    $this->senders = 0;

    $this->registerSms = fn () => app(ModuleRegistry::class)->register(new ModuleManifest(
        key: 'sms',
        name: 'SMS',
        version: '1.0.0',
        limits: [
            new LimitDefinition('sms.messages'),
            new LimitDefinition('sms.senders', LimitKind::Gauge, fn (): int => $this->senders),
            new LimitDefinition('sms.templates', LimitKind::Gauge),
        ],
    ));

    /** The fields a refusal is reported on, with the message. */
    $this->refusal = function (Closure $callback): array {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return array_map(static fn (array $messages): string => $messages[0], $exception->errors());
        }

        $this->fail('The request was not refused.');
    };
});

describe('the usage meter', function () {
    it('counts an event once, however often it is reported', function () {
        $counted = ($this->meter)(fn (UsageMeter $meter): array => [
            $meter->record('sms.messages', 3, 'sms:1001'),
            // A retried job, a webhook delivered twice.
            $meter->record('sms.messages', 3, 'sms:1001'),
            $meter->record('sms.messages', 30, 'sms:1001'),
            $meter->record('sms.messages', 2, 'sms:1002'),
        ]);

        expect($counted)->toBe([true, false, false, true])
            ->and(($this->meter)(fn (UsageMeter $meter): int => $meter->used('sms.messages')))->toBe(5)
            ->and(UsageRecord::forTenant($this->acme)->count())->toBe(2);
    });

    it('stores what it was told about the event', function () {
        ($this->meter)(fn (UsageMeter $meter) => $meter->record(
            'sms.messages',
            4,
            'sms:1001',
            ['campaign' => 'eid-offer', 'parts' => 4, 'unicode' => true, 'provider' => null],
            Carbon::parse('2026-03-09 23:15:00'),
        ));
        ($this->meter)(fn (UsageMeter $meter) => $meter->record('sms.messages', 1, 'sms:1002'));

        [$first, $second] = UsageRecord::forTenant($this->acme)->orderBy('id')->get()->all();

        expect($first->tenant_id)->toBe($this->acme->id)
            ->and($first->meter)->toBe('sms.messages')
            ->and($first->quantity)->toBe(4)
            ->and($first->idempotency_key)->toBe('sms:1001')
            ->and($first->meta)->toEqual(['campaign' => 'eid-offer', 'parts' => 4, 'unicode' => true, 'provider' => null])
            // When it happened, which may be before it was reported.
            ->and($first->occurred_at->toDateTimeString())->toBe('2026-03-09 23:15:00')
            ->and($second->meta)->toBeNull()
            ->and($second->occurred_at->toDateTimeString())->toBe('2026-03-10 09:00:00');
    });

    it('keeps an idempotency key unique within the workspace, whatever the meter', function () {
        $counted = ($this->meter)(fn (UsageMeter $meter): array => [
            $meter->record('sms.messages', 1, 'event:7'),
            $meter->record('ai.credits', 5, 'event:7'),
        ]);

        // Hence the convention of prefixing a key with what it counts: `sms:{id}`.
        expect($counted)->toBe([true, false])
            ->and(($this->meter)(fn (UsageMeter $meter): int => $meter->used('ai.credits')))->toBe(0);
    });

    it('counts the same key again in another workspace', function () {
        $record = fn (UsageMeter $meter): bool => $meter->record('sms.messages', 2, 'sms:1001');

        expect(($this->meter)($record))->toBeTrue()
            ->and(($this->meter)($record, $this->globex))->toBeTrue()
            ->and(($this->meter)($record, $this->globex))->toBeFalse()
            ->and(($this->meter)(fn (UsageMeter $meter): int => $meter->used('sms.messages')))->toBe(2)
            ->and(($this->meter)(fn (UsageMeter $meter): int => $meter->used('sms.messages'), $this->globex))->toBe(2);

        ($this->meter)(fn (UsageMeter $meter) => $meter->record('sms.messages', 10, 'sms:1002'), $this->globex);

        expect(($this->meter)(fn (UsageMeter $meter): int => $meter->used('sms.messages')))->toBe(2)
            ->and(($this->meter)(fn (UsageMeter $meter): int => $meter->used('sms.messages'), $this->globex))->toBe(12);
    });

    it('does not spoil the transaction a repeated event is reported in', function () {
        $counted = ($this->meter)(fn (UsageMeter $meter): array => DB::transaction(fn (): array => [
            $meter->record('sms.messages', 1, 'sms:1'),
            $meter->record('sms.messages', 1, 'sms:1'),
            $meter->record('sms.messages', 1, 'sms:2'),
        ]));

        expect($counted)->toBe([true, false, true])
            ->and(($this->meter)(fn (UsageMeter $meter): int => $meter->used('sms.messages')))->toBe(2);
    });

    it('counts nothing for a transaction that is rolled back, and the event can be reported again', function () {
        try {
            ($this->meter)(fn (UsageMeter $meter) => DB::transaction(function () use ($meter): void {
                $meter->record('sms.messages', 5, 'sms:1');

                throw new RuntimeException('The message could not be handed to the provider.');
            }));
        } catch (RuntimeException) {
            // expected
        }

        expect(($this->meter)(fn (UsageMeter $meter): int => $meter->used('sms.messages')))->toBe(0)
            ->and(($this->meter)(fn (UsageMeter $meter): bool => $meter->record('sms.messages', 5, 'sms:1')))->toBeTrue();
    });

    it('rejects a quantity that is not at least one', function (int $quantity) {
        expect(fn () => ($this->meter)(fn (UsageMeter $meter) => $meter->record('sms.messages', $quantity, 'sms:1')))
            ->toThrow(InvalidArgumentException::class, 'whole units of at least one');

        expect(UsageRecord::forTenant($this->acme)->count())->toBe(0);
    })->with([0, -1, -100, PHP_INT_MIN]);

    it('rejects an idempotency key that is empty or longer than 120 characters', function (string $key) {
        expect(fn () => ($this->meter)(fn (UsageMeter $meter) => $meter->record('sms.messages', 1, $key)))
            ->toThrow(InvalidArgumentException::class, '1 to 120 characters');

        expect(UsageRecord::forTenant($this->acme)->count())->toBe(0);
    })->with([
        'empty' => [''],
        '121 characters' => [str_repeat('k', 121)],
        'far too long' => [str_repeat('k', 5000)],
    ]);

    it('accepts a key of exactly 120 characters and tells it from its neighbour', function () {
        $key = str_repeat('k', 119);

        expect(($this->meter)(fn (UsageMeter $meter): array => [
            $meter->record('sms.messages', 1, $key.'a'),
            $meter->record('sms.messages', 1, $key.'b'),
            $meter->record('sms.messages', 1, $key.'a'),
            $meter->record('sms.messages', 1, 'x'),
        ]))->toBe([true, true, false, true]);
    });

    it('sums use between two moments: from the first, up to but not including the second', function () {
        ($this->meter)(function (UsageMeter $meter): void {
            $meter->record('sms.messages', 1, 'a', occurredAt: Carbon::parse('2026-02-28 23:59:59'));
            $meter->record('sms.messages', 10, 'b', occurredAt: Carbon::parse('2026-03-01 00:00:00'));
            $meter->record('sms.messages', 100, 'c', occurredAt: Carbon::parse('2026-03-09 12:00:00'));
            $meter->record('sms.messages', 1000, 'd', occurredAt: Carbon::parse('2026-04-01 00:00:00'));
            $meter->record('ai.credits', 7, 'e', occurredAt: Carbon::parse('2026-03-09 12:00:00'));
        });

        $march = Carbon::parse('2026-03-01 00:00:00');
        $april = Carbon::parse('2026-04-01 00:00:00');

        $used = ($this->meter)(fn (UsageMeter $meter): array => [
            'ever' => $meter->used('sms.messages'),
            'from March' => $meter->used('sms.messages', $march),
            'before March' => $meter->used('sms.messages', to: $march),
            'in March' => $meter->used('sms.messages', $march, $april),
            'before April' => $meter->used('sms.messages', to: $april),
            'from April' => $meter->used('sms.messages', $april),
            'in an empty window' => $meter->used('sms.messages', $march, $march),
            'another meter' => $meter->used('ai.credits', $march, $april),
            'a meter nothing was recorded on' => $meter->used('crm.exports'),
        ]);

        expect($used)->toBe([
            'ever' => 1111,
            'from March' => 1110,
            'before March' => 1,
            'in March' => 110,
            'before April' => 111,
            'from April' => 1000,
            'in an empty window' => 0,
            'another meter' => 7,
            'a meter nothing was recorded on' => 0,
        ]);
    });

    it('needs a workspace', function () {
        expect(fn () => app(UsageMeter::class)->record('sms.messages', 1, 'sms:1'))->toThrow(MissingTenantContextException::class)
            ->and(fn () => app(UsageMeter::class)->used('sms.messages'))->toThrow(MissingTenantContextException::class);
    });

    it('isolates usage records between tenants', function () {
        $this->assertTenantIsolated(UsageRecord::class, function (Tenant $tenant): UsageRecord {
            app(UsageMeter::class)->record('sms.messages', 3, 'sms:1001');

            return UsageRecord::query()->sole();
        });
    });
});

describe('the seat gauge', function () {
    beforeEach(function () {
        $this->seats = fn (?Tenant $tenant = null): int => ($this->limits)(fn (UsageLimits $limits): int => $limits->used('core.members'), $tenant);
    });

    it('counts everyone in the workspace', function () {
        expect(($this->seats)())->toBe(1);

        $this->addMember($this->acme, Role::ADMIN);
        $this->addMember($this->acme, Role::EMPLOYEE);
        // A suspended member still holds the seat: it is theirs when the suspension ends.
        $this->addMember($this->acme, Role::EMPLOYEE, status: MembershipStatus::Suspended);

        // Next door.
        $this->addMember($this->globex, Role::EMPLOYEE);

        expect(($this->seats)())->toBe(4)
            ->and(($this->seats)($this->globex))->toBe(2);
    });

    it('counts an invitation that can still be accepted, and no other', function () {
        $this->createInvitation($this->acme, 'pending@example.test');
        $this->createInvitation($this->acme, 'also-pending@example.test', Role::MANAGER);
        $this->createInvitation($this->acme, 'expired@example.test', attributes: ['expires_at' => now()->subMinute()]);
        $this->createInvitation($this->acme, 'withdrawn@example.test', attributes: ['revoked_at' => now()]);
        // Accepted: that person is a member now and is counted as one.
        $this->createInvitation($this->acme, 'joined@example.test', attributes: ['accepted_at' => now()]);
        $this->createInvitation($this->globex, 'elsewhere@example.test');

        expect(($this->seats)())->toBe(3)
            ->and(($this->seats)($this->globex))->toBe(2);
    });

    it('frees the seat of an invitation the moment it expires', function () {
        $this->createInvitation($this->acme, 'slow@example.test', attributes: ['expires_at' => Carbon::parse('2026-03-12 09:00:00')]);

        expect(($this->seats)())->toBe(2);

        $this->travelTo(Carbon::parse('2026-03-12 08:59:59'));

        expect(($this->seats)())->toBe(2);

        $this->travelTo(Carbon::parse('2026-03-12 09:00:00'));

        expect(($this->seats)())->toBe(1);
    });
});

describe('what the plan allows', function () {
    beforeEach(function () {
        ($this->registerSms)();

        $this->small = $this->createPlan(['key' => 'small', 'limits' => ['core.members' => 3, 'sms.messages' => 100]]);
        $this->unlimited = $this->createPlan(['key' => 'unlimited', 'limits' => []]);
    });

    it('is not limited without a subscription', function () {
        $this->addMember($this->acme);

        expect(($this->limits)(fn (UsageLimits $limits): array => [
            $limits->limit('core.members'),
            $limits->limit('sms.messages'),
            $limits->remaining('core.members'),
            $limits->allows('core.members', 1000000),
            // What is used is known all the same.
            $limits->used('core.members'),
        ]))->toBe([null, null, null, true, 2]);

        ($this->limits)(fn (UsageLimits $limits) => $limits->ensure('core.members', 1000000));
    });

    it('is not limited in what the plan does not name', function () {
        $this->subscribeAndPay($this->acme, $this->unlimited);
        $this->subscribeAndPay($this->globex, $this->small);

        expect(($this->limits)(fn (UsageLimits $limits): array => [
            $limits->limit('core.members'),
            $limits->remaining('sms.messages'),
            $limits->allows('sms.messages', PHP_INT_MAX),
        ]))->toBe([null, null, true])
            // Another workspace's plan says nothing about this one.
            ->and(($this->limits)(fn (UsageLimits $limits): ?int => $limits->limit('core.members'), $this->globex))->toBe(3);
    });

    it('answers the maximum, what is used and what is left', function () {
        $this->subscribeAndPay($this->acme, $this->small);
        $this->addMember($this->acme);

        expect(($this->limits)(fn (UsageLimits $limits): array => [
            'limit' => $limits->limit('core.members'),
            'used' => $limits->used('core.members'),
            'remaining' => $limits->remaining('core.members'),
            'one more' => $limits->allows('core.members'),
            'two more' => $limits->allows('core.members', 2),
            'none more' => $limits->allows('core.members', 0),
        ]))->toBe(['limit' => 3, 'used' => 2, 'remaining' => 1, 'one more' => true, 'two more' => false, 'none more' => true]);
    });

    it('applies from the first day of a trial and while a payment is overdue', function () {
        $trial = $this->createPlan(['key' => 'trial', 'trial_days' => 14, 'limits' => ['core.members' => 2]]);

        $this->subscribe($this->acme, $trial);

        expect(($this->limits)(fn (UsageLimits $limits): ?int => $limits->limit('core.members')))->toBe(2);

        $this->travel(15)->days();
        $this->runBillingCycle($this->acme);

        expect(($this->limits)(fn (UsageLimits $limits): ?int => $limits->limit('core.members')))->toBe(2);
    });

    it('follows the plan as soon as it changes', function () {
        $this->subscribeAndPay($this->acme, $this->small);

        $seen = $this->inTenant($this->acme, function (): array {
            $limits = app(UsageLimits::class);
            $before = $limits->limit('core.members');

            // In the same request: what was read about the subscription is forgotten.
            app(SubscriptionManager::class)->grant($this->unlimited, BillingInterval::Monthly);

            return [$before, $limits->limit('core.members')];
        });

        expect($seen)->toBe([3, null]);
    });

    it('leaves nothing over, and never less than nothing, when more is used than the plan allows', function () {
        $this->subscribeAndPay($this->acme, $this->small);

        foreach (range(1, 4) as $ignored) {
            $this->addMember($this->acme);
        }

        // Five people on a plan for three - after a downgrade, say.
        expect(($this->limits)(fn (UsageLimits $limits): array => [
            $limits->used('core.members'),
            $limits->remaining('core.members'),
            $limits->allows('core.members'),
            $limits->allows('core.members', 0),
        ]))->toBe([5, 0, false, true]);
    });

    it('allows nothing when the plan sets a limit of zero', function () {
        $this->subscribeAndPay($this->acme, $this->createPlan(['key' => 'no-sms', 'limits' => ['sms.messages' => 0]]));

        expect(($this->limits)(fn (UsageLimits $limits): array => [
            $limits->limit('sms.messages'),
            $limits->remaining('sms.messages'),
            $limits->allows('sms.messages'),
        ]))->toBe([0, 0, false]);
    });

    it('refuses to go over the limit, on the field it is told to report on', function () {
        $this->subscribeAndPay($this->acme, $this->small);
        $this->addMember($this->acme);

        $message = 'Your plan\'s limit has been reached. Upgrade the plan to add more.';

        ($this->limits)(fn (UsageLimits $limits) => $limits->ensure('core.members'));

        expect(($this->refusal)(fn () => ($this->limits)(fn (UsageLimits $limits) => $limits->ensure('core.members', 2))))
            ->toBe(['limit' => $message]);

        $this->addMember($this->acme);

        expect(($this->refusal)(fn () => ($this->limits)(fn (UsageLimits $limits) => $limits->ensure('core.members'))))
            ->toBe(['limit' => $message])
            ->and(($this->refusal)(fn () => ($this->limits)(fn (UsageLimits $limits) => $limits->ensure('core.members', 1, 'email'))))
            ->toBe(['email' => $message]);
    });

    it('does not know a limit that no module declares', function (Closure $ask) {
        $this->subscribeAndPay($this->acme, $this->createPlan(['key' => 'typo', 'limits' => ['core.seats' => 5]]));

        expect(fn () => ($this->limits)($ask))->toThrow(InvalidArgumentException::class, 'Unknown limit [core.seats].');
    })->with([
        'limit' => [fn (UsageLimits $limits) => $limits->limit('core.seats')],
        'used' => [fn (UsageLimits $limits) => $limits->used('core.seats')],
        'remaining' => [fn (UsageLimits $limits) => $limits->remaining('core.seats')],
        'allows' => [fn (UsageLimits $limits) => $limits->allows('core.seats')],
        'ensure' => [fn (UsageLimits $limits) => $limits->ensure('core.seats')],
    ]);

    it('needs a workspace', function () {
        expect(fn () => app(UsageLimits::class)->limit('core.members'))->toThrow(MissingTenantContextException::class)
            ->and(fn () => app(UsageLimits::class)->used('core.members'))->toThrow(MissingTenantContextException::class)
            ->and(fn () => app(UsageLimits::class)->overview())->toThrow(MissingTenantContextException::class);
    });

    it('lists every registered limit with its maximum and current use', function () {
        $this->subscribeAndPay($this->acme, $this->small);
        $this->addMember($this->acme);
        $this->senders = 4;

        ($this->meter)(fn (UsageMeter $meter) => $meter->record('sms.messages', 40, 'sms:1'));

        $overview = ($this->limits)(fn (UsageLimits $limits): array => $limits->overview());

        expect(array_is_list($overview))->toBeTrue()
            ->and(collect($overview)->keyBy('key')->only(['core.members', 'sms.messages', 'sms.senders', 'sms.templates'])->all())->toBe([
                'core.members' => ['key' => 'core.members', 'kind' => 'gauge', 'limit' => 3, 'used' => 2],
                'sms.messages' => ['key' => 'sms.messages', 'kind' => 'metered', 'limit' => 100, 'used' => 40],
                'sms.senders' => ['key' => 'sms.senders', 'kind' => 'gauge', 'limit' => null, 'used' => 4],
                // A gauge nobody counts yet.
                'sms.templates' => ['key' => 'sms.templates', 'kind' => 'gauge', 'limit' => null, 'used' => 0],
            ])
            ->and(array_column($overview, 'key'))->toBe(array_keys(app(ModuleRegistry::class)->limits()));

        // The same list, with nothing to cap, for a workspace without a subscription.
        expect(collect(($this->limits)(fn (UsageLimits $limits): array => $limits->overview(), $this->globex))->pluck('limit', 'key')->all())
            ->toBe(array_fill_keys(array_keys(app(ModuleRegistry::class)->limits()), null));
    });

    it('never reports a gauge below nothing', function () {
        $this->senders = -3;

        expect(($this->limits)(fn (UsageLimits $limits): int => $limits->used('sms.senders')))->toBe(0);
    });
});

describe('a metered limit', function () {
    beforeEach(function () {
        ($this->registerSms)();

        $this->plan = $this->createPlan(['key' => 'sms-100', 'limits' => ['sms.messages' => 100]]);

        $this->send = fn (int $messages, string $at, ?Tenant $tenant = null): bool => ($this->meter)(
            fn (UsageMeter $meter): bool => $meter->record('sms.messages', $messages, 'sms:'.$at, occurredAt: Carbon::parse($at)),
            $tenant,
        );

        $this->used = fn (?Tenant $tenant = null): int => ($this->limits)(fn (UsageLimits $limits): int => $limits->used('sms.messages'), $tenant);
    });

    it('counts what was used since the billing period began', function () {
        // The period starts on 10 March, 09:00.
        $this->subscribeAndPay($this->acme, $this->plan);

        ($this->send)(1, '2026-03-01 12:00:00');
        ($this->send)(2, '2026-03-10 08:59:59');
        ($this->send)(30, '2026-03-10 09:00:00');
        ($this->send)(40, '2026-03-20 18:00:00');
        // The neighbours are busy too.
        ($this->send)(99, '2026-03-20 18:00:00', $this->globex);

        $this->travelTo(Carbon::parse('2026-03-25 09:00:00'));

        expect(($this->used)())->toBe(70)
            ->and(($this->limits)(fn (UsageLimits $limits): array => [
                $limits->remaining('sms.messages'),
                $limits->allows('sms.messages', 30),
                $limits->allows('sms.messages', 31),
            ]))->toBe([30, true, false]);

        expect(($this->refusal)(fn () => ($this->limits)(fn (UsageLimits $limits) => $limits->ensure('sms.messages', 31, 'recipients'))))
            ->toHaveKey('recipients');
    });

    it('starts again from nothing with the next period', function () {
        $this->subscribeAndPay($this->acme, $this->plan);

        ($this->send)(100, '2026-03-20 18:00:00');

        $this->travelTo(Carbon::parse('2026-04-09 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect(($this->used)())->toBe(100)
            ->and(($this->limits)(fn (UsageLimits $limits): bool => $limits->allows('sms.messages')))->toBeFalse();

        // The renewal is paid two days late: the period still runs from 10 April.
        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));
        $this->runBillingCycle($this->acme);
        $this->payOpenInvoice($this->acme);

        ($this->send)(5, '2026-04-11 10:00:00');

        expect(($this->used)())->toBe(5)
            ->and(($this->limits)(fn (UsageLimits $limits): ?int => $limits->remaining('sms.messages')))->toBe(95);
    });

    it('keeps counting the running period when the next one is paid for early', function () {
        $this->subscribeAndPay($this->acme, $this->plan);

        // The whole allowance, used in March.
        ($this->send)(100, '2026-03-20 18:00:00');

        // The renewal is issued on 3 April and paid the next day. The month it buys starts on 10 April.
        $this->travelTo(Carbon::parse('2026-04-04 09:00:00'));
        $this->runBillingCycle($this->acme);
        $this->payOpenInvoice($this->acme);

        expect($this->subscriptionOf($this->acme)->current_period_start->toDateTimeString())->toBe('2026-04-10 09:00:00');

        // Until then the workspace is still in the month it used up: paying early buys no second allowance.
        expect(($this->used)())->toBe(100)
            ->and(($this->limits)(fn (UsageLimits $limits): bool => $limits->allows('sms.messages')))->toBeFalse();

        // From 10 April the new month counts.
        $this->travelTo(Carbon::parse('2026-04-10 09:00:01'));

        expect(($this->used)())->toBe(0)
            ->and(($this->limits)(fn (UsageLimits $limits): bool => $limits->allows('sms.messages', 100)))->toBeTrue();
    });

    it('counts by calendar month where there is no billing period', function (Closure $arrange) {
        $arrange->call($this);

        ($this->send)(1, '2026-02-28 23:59:59');
        ($this->send)(20, '2026-03-01 00:00:00');
        ($this->send)(300, '2026-03-09 12:00:00');

        expect(($this->used)())->toBe(320);

        $this->travelTo(Carbon::parse('2026-04-01 00:00:00'));

        expect(($this->used)())->toBe(0);
    })->with([
        'no subscription' => [fn () => null],
        'a free plan' => [fn () => $this->subscribe($this->acme, $this->createFreePlan(['limits' => ['sms.messages' => 500]]))],
        'a trial' => [fn () => $this->subscribe($this->acme, $this->createPlan(['trial_days' => 30, 'limits' => ['sms.messages' => 500]]))],
    ]);

    it('counts the year of a yearly plan', function () {
        $this->subscribeAndPay($this->acme, $this->plan, BillingInterval::Yearly);

        ($this->send)(10, '2026-03-11 00:00:00');
        ($this->send)(20, '2026-09-01 00:00:00');

        $this->travelTo(Carbon::parse('2027-02-01 00:00:00'));

        expect(($this->used)())->toBe(30);
    });
});

describe('the seat limit on inviting', function () {
    beforeEach(function () {
        $this->three = $this->createPlan(['key' => 'three-seats', 'limits' => ['core.members' => 3]]);
        $this->five = $this->createPlan(['key' => 'five-seats', 'monthly_amount' => 300000, 'limits' => ['core.members' => 5]]);

        $this->subscribeAndPay($this->acme, $this->three);

        // Two seats taken: the owner and one colleague.
        $this->addMember($this->acme, Role::EMPLOYEE);

        $this->invite = fn (string $email, ?Tenant $tenant = null): Invitation => $this->inTenant(
            $tenant ?? $this->acme,
            fn (): Invitation => app(InviteMember::class)->handle(
                $this->ownerOf($tenant ?? $this->acme),
                $email,
                $this->roleOf($tenant ?? $this->acme, Role::EMPLOYEE),
            ),
        );

        $this->pending = fn (): array => Invitation::forTenant($this->acme)->pending()->orderBy('id')->pluck('email')->all();
        $this->full = ['email' => 'Your plan\'s limit has been reached. Upgrade the plan to add more.'];
    });

    it('lets the last free seat be offered, and refuses the invitation after that', function () {
        ($this->invite)('third@example.test');

        Mail::assertQueued(InvitationMail::class, 1);

        expect(($this->refusal)(fn () => ($this->invite)('fourth@example.test')))->toBe($this->full);

        expect(($this->pending)())->toBe(['third@example.test'])
            ->and(Invitation::forTenant($this->acme)->count())->toBe(1)
            ->and($this->auditTrail($this->acme, 'member.invited'))->toHaveCount(1);

        // No email went to the person who was not invited.
        Mail::assertQueued(InvitationMail::class, 1);
    });

    it('still lets an address that already holds a seat be invited again', function () {
        ($this->invite)('third@example.test');

        $again = ($this->invite)('Third@Example.test');

        expect(($this->pending)())->toBe(['third@example.test'])
            ->and($again->isPending())->toBeTrue()
            // The first invitation is withdrawn, so the address holds one seat, not two.
            ->and(Invitation::forTenant($this->acme)->whereNotNull('revoked_at')->count())->toBe(1)
            ->and(($this->limits)(fn (UsageLimits $limits): int => $limits->used('core.members')))->toBe(3)
            ->and(($this->refusal)(fn () => ($this->invite)('fourth@example.test')))->toBe($this->full);

        Mail::assertQueued(InvitationMail::class, 2);
    });

    it('gives the seat of an expired or withdrawn invitation to someone else', function (Closure $free) {
        $invitation = ($this->invite)('third@example.test');

        expect(($this->refusal)(fn () => ($this->invite)('fourth@example.test')))->toBe($this->full);

        $free->call($this, $invitation);

        ($this->invite)('fourth@example.test');

        expect(($this->pending)())->toBe(['fourth@example.test']);

        // An address whose invitation ran out holds no seat any more: it is refused like any other.
        expect(($this->refusal)(fn () => ($this->invite)('third@example.test')))->toBe($this->full);
    })->with([
        'expired' => [fn () => $this->travel(8)->days()],
        'withdrawn' => [fn (Invitation $invitation) => $invitation->update(['revoked_at' => now()])],
    ]);

    it('works again after moving to a plan with more seats', function () {
        ($this->invite)('third@example.test');

        expect(($this->refusal)(fn () => ($this->invite)('fourth@example.test')))->toBe($this->full);

        // An upgrade inside the paid month: nothing changes until its invoice is paid.
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->changePlan($this->five, BillingInterval::Monthly));

        expect(($this->refusal)(fn () => ($this->invite)('fourth@example.test')))->toBe($this->full);

        $this->payOpenInvoice($this->acme);

        ($this->invite)('fourth@example.test');
        ($this->invite)('fifth@example.test');

        expect(($this->pending)())->toBe(['third@example.test', 'fourth@example.test', 'fifth@example.test'])
            ->and(($this->refusal)(fn () => ($this->invite)('sixth@example.test')))->toBe($this->full);
    });

    it('counts the seats of this workspace only', function () {
        $this->subscribeAndPay($this->globex, $this->three);

        foreach (range(1, 2) as $ignored) {
            $this->addMember($this->globex);
        }

        expect(($this->refusal)(fn () => ($this->invite)('new@example.test', $this->globex)))->toBe($this->full);

        // acme, with one seat left, is not held back by its full neighbour.
        ($this->invite)('new@example.test');

        expect(($this->pending)())->toBe(['new@example.test']);
    });

    it('does not apply to a workspace without a subscription or on a plan without a seat limit', function () {
        $umbrella = $this->createTenant('umbrella');

        $this->subscribeAndPay($this->globex, $this->createPlan(['key' => 'open-seats', 'limits' => ['sms.messages' => 10]]));

        foreach ([$umbrella, $this->globex] as $tenant) {
            foreach (range(1, 6) as $n) {
                ($this->invite)("person{$n}@example.test", $tenant);
            }

            expect(Invitation::forTenant($tenant)->pending()->count())->toBe(6);
        }
    });

    it('holds a workspace that is already over its plan to no further invitations', function () {
        foreach (range(1, 3) as $ignored) {
            $this->addMember($this->acme);
        }

        expect(($this->limits)(fn (UsageLimits $limits): int => $limits->used('core.members')))->toBe(5)
            ->and(($this->refusal)(fn () => ($this->invite)('sixth@example.test')))->toBe($this->full);
    });
});

/*
 * No database: a registry of its own, so nothing a test declares here leaks
 * into the application's.
 */
describe('declaring limits', function () {
    it('takes its module from the first segment of the key', function () {
        expect((new LimitDefinition('sms.messages'))->module())->toBe('sms')
            ->and((new LimitDefinition('email_marketing.sends.monthly'))->module())->toBe('email_marketing')
            ->and((new LimitDefinition('sms.messages'))->kind)->toBe(LimitKind::Metered)
            ->and((new LimitDefinition('sms.messages'))->count)->toBeNull();
    });

    it('rejects a manifest whose limit is not prefixed with its own module key', function (string $key) {
        expect(fn () => new ModuleManifest(key: 'sms', name: 'SMS', version: '1.0.0', limits: [new LimitDefinition($key)]))
            ->toThrow(InvalidArgumentException::class, "Limit [{$key}] must be prefixed with its module key [sms.].");
    })->with([
        'the key of the core' => ['core.members'],
        'the key of another module' => ['crm.leads'],
        'a longer key that starts the same' => ['smsx.messages'],
        'no prefix at all' => ['messages'],
        'another case' => ['SMS.messages'],
    ]);

    it('accepts limits that carry the module\'s prefix', function () {
        $manifest = new ModuleManifest(key: 'sms', name: 'SMS', version: '1.0.0', limits: [
            new LimitDefinition('sms.messages'),
            new LimitDefinition('sms.senders', LimitKind::Gauge, fn (): int => 0),
        ]);

        expect(array_map(fn (LimitDefinition $limit): string => $limit->key, $manifest->limits))->toBe(['sms.messages', 'sms.senders']);
    });

    it('lists the limits of every module by key, and finds one', function () {
        $registry = new ModuleRegistry;
        $registry->register(new ModuleManifest(key: 'core', name: 'Workspace', version: '1.0.0', core: true, limits: [
            new LimitDefinition('core.members', LimitKind::Gauge, fn (): int => 1),
        ]));
        $registry->register(new ModuleManifest(key: 'sms', name: 'SMS', version: '1.0.0', limits: [new LimitDefinition('sms.messages')]));
        $registry->register(new ModuleManifest(key: 'crm', name: 'CRM', version: '1.0.0'));

        expect(array_keys($registry->limits()))->toBe(['core.members', 'sms.messages'])
            ->and($registry->findLimit('sms.messages')?->kind)->toBe(LimitKind::Metered)
            ->and($registry->findLimit('core.members')?->kind)->toBe(LimitKind::Gauge)
            ->and($registry->findLimit('sms.senders'))->toBeNull()
            ->and($registry->findLimit(''))->toBeNull();
    });

    it('rejects a limit key that is declared twice', function () {
        $registry = new ModuleRegistry;
        $registry->register(new ModuleManifest(key: 'core', name: 'Workspace', version: '1.0.0', core: true));
        $registry->register(new ModuleManifest(key: 'sms', name: 'SMS', version: '1.0.0', limits: [
            new LimitDefinition('sms.messages'),
            new LimitDefinition('sms.messages', LimitKind::Gauge, fn (): int => 0),
        ]));

        expect(fn () => $registry->limits())->toThrow(InvalidArgumentException::class, 'Limit [sms.messages] is registered twice.')
            ->and(fn () => $registry->findLimit('core.members'))->toThrow(InvalidArgumentException::class, 'registered twice');
    });

    it('cannot have two modules declare the same key: each key belongs to the module it is prefixed with', function () {
        $registry = new ModuleRegistry;
        $registry->register(new ModuleManifest(key: 'sms', name: 'SMS', version: '1.0.0', limits: [new LimitDefinition('sms.messages')]));

        // Another module cannot claim `sms.messages` ...
        expect(fn () => new ModuleManifest(key: 'campaigns', name: 'Campaigns', version: '1.0.0', limits: [new LimitDefinition('sms.messages')]))
            ->toThrow(InvalidArgumentException::class, 'must be prefixed with its module key [campaigns.]');

        // ... and a second module called `sms` cannot be registered.
        expect(fn () => $registry->register(new ModuleManifest(key: 'sms', name: 'SMS again', version: '2.0.0', limits: [new LimitDefinition('sms.messages')])))
            ->toThrow(InvalidArgumentException::class, 'Module [sms] is already registered.');

        expect(array_keys($registry->limits()))->toBe(['sms.messages']);
    });

    it('declares the seat limit in the core module', function () {
        $seats = app(ModuleRegistry::class)->findLimit('core.members');

        expect($seats)->not->toBeNull()
            ->and($seats->kind)->toBe(LimitKind::Gauge)
            ->and($seats->module())->toBe('core')
            ->and($seats->count)->toBeInstanceOf(Closure::class);
    });
});
