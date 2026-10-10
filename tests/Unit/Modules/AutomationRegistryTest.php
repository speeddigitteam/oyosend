<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Automation\Contracts\TriggerDefinition;
use App\Core\Automation\Triggers\MemberJoinedTrigger;
use App\Core\Contacts\Automation\CompanyCreatedTrigger;
use App\Core\Contacts\Automation\ContactCreatedTrigger;
use App\Core\Events\DomainEvent;
use App\Core\Identity\Events\MemberJoined;
use Tests\Support\Fixtures\Crm\LeadCreatedTrigger;

/*
 * Small definitions, declared inline: each one is wrong (or right) in exactly one way.
 */
abstract class BaseTestTrigger implements TriggerDefinition
{
    public static function label(): string
    {
        return 'label';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function event(): ?string
    {
        return null;
    }

    public static function requiredPermission(): ?string
    {
        return null;
    }

    public static function inputSchema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return [];
    }
}

final class WronglyPrefixedTrigger extends BaseTestTrigger
{
    public static function key(): string
    {
        return 'sales.lead.created';
    }
}

final class LookalikePrefixTrigger extends BaseTestTrigger
{
    public static function key(): string
    {
        // Starts with the module key, but is not namespaced under it.
        return 'crmx.lead.created';
    }
}

final class DuplicateKeyTrigger extends BaseTestTrigger
{
    public static function key(): string
    {
        return 'crm.lead.created';
    }
}

final class AssignLeadAction implements ActionDefinition
{
    public static function key(): string
    {
        return 'crm.lead.assign';
    }

    public static function label(): string
    {
        return 'label';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function requiredPermission(): ?string
    {
        return 'crm.leads.edit';
    }

    public static function inputSchema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return [];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return [];
    }
}

final class SameKeyAsTriggerAction implements ActionDefinition
{
    public static function key(): string
    {
        return 'crm.lead.created';
    }

    public static function label(): string
    {
        return 'label';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function requiredPermission(): ?string
    {
        return null;
    }

    public static function inputSchema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return [];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return [];
    }
}

beforeEach(function () {
    $this->registry = new AutomationRegistry;
});

it('registers triggers and actions by key', function () {
    $this->registry->registerTrigger(LeadCreatedTrigger::class);
    $this->registry->registerAction(AssignLeadAction::class);

    expect($this->registry->triggers())->toBe(['crm.lead.created' => LeadCreatedTrigger::class])
        ->and($this->registry->actions())->toBe(['crm.lead.assign' => AssignLeadAction::class])
        ->and($this->registry->trigger('crm.lead.created'))->toBe(LeadCreatedTrigger::class)
        ->and($this->registry->action('crm.lead.assign'))->toBe(AssignLeadAction::class)
        ->and($this->registry->trigger('crm.lead.assign'))->toBeNull()
        ->and($this->registry->action('crm.lead.created'))->toBeNull();
});

it('requires a key to be namespaced under the module that owns it', function (string $definition) {
    expect(fn () => $this->registry->registerTrigger($definition))
        ->toThrow(InvalidArgumentException::class, 'must be prefixed with its module key [crm.]');

    expect($this->registry->triggers())->toBe([]);
})->with([
    'another module\'s prefix' => [WronglyPrefixedTrigger::class],
    'a prefix that merely starts the same' => [LookalikePrefixTrigger::class],
]);

it('refuses to register the same key twice', function () {
    $this->registry->registerTrigger(LeadCreatedTrigger::class);

    expect(fn () => $this->registry->registerTrigger(DuplicateKeyTrigger::class))
        ->toThrow(InvalidArgumentException::class, 'Automation key [crm.lead.created] is registered twice');

    expect($this->registry->trigger('crm.lead.created'))->toBe(LeadCreatedTrigger::class);
});

it('keeps triggers and actions in separate namespaces', function () {
    $this->registry->registerTrigger(LeadCreatedTrigger::class);
    $this->registry->registerAction(SameKeyAsTriggerAction::class);

    expect($this->registry->trigger('crm.lead.created'))->toBe(LeadCreatedTrigger::class)
        ->and($this->registry->action('crm.lead.created'))->toBe(SameKeyAsTriggerAction::class);
});

it('only accepts real definitions', function () {
    expect(fn () => $this->registry->registerTrigger(AssignLeadAction::class))
        ->toThrow(InvalidArgumentException::class, 'is not a trigger definition');
    expect(fn () => $this->registry->registerAction(LeadCreatedTrigger::class))
        ->toThrow(InvalidArgumentException::class, 'is not an action definition');
    expect(fn () => $this->registry->registerTrigger(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->registry->registerAction('No\\Such\\Class'))
        ->toThrow(InvalidArgumentException::class);
});

describe('the application\'s own registry', function () {
    it('has the core trigger registered from the core manifest', function () {
        $registry = app(AutomationRegistry::class);

        expect($registry->triggers())->toBe([
            'core.member.joined' => MemberJoinedTrigger::class,
            'core.contact.created' => ContactCreatedTrigger::class,
            'core.company.created' => CompanyCreatedTrigger::class,
        ])
            ->and($registry->actions())->toBe([])
            ->and(app(AutomationRegistry::class))->toBe($registry);
    });

    it('describes "member joined" consistently with the event that fires it', function () {
        $event = MemberJoinedTrigger::event();

        expect(MemberJoinedTrigger::key())->toBe('core.member.joined')
            ->and(MemberJoinedTrigger::module())->toBe('core')
            ->and(MemberJoinedTrigger::requiredPermission())->toBe('core.members.view')
            ->and($event)->toBe(MemberJoined::class)
            ->and(is_subclass_of($event, DomainEvent::class))->toBeTrue()
            // The trigger and its event carry the same stable name.
            ->and($event::name())->toBe(MemberJoinedTrigger::key())
            ->and(MemberJoinedTrigger::inputSchema()['type'])->toBe('object')
            ->and(MemberJoinedTrigger::outputSchema()['required'])->toBe(['membership_id', 'user_id', 'name', 'email', 'role'])
            ->and(array_keys(MemberJoinedTrigger::outputSchema()['properties']))->toBe(MemberJoinedTrigger::outputSchema()['required']);
    });
});
