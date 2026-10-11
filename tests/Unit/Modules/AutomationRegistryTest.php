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
use App\Modules\CRM\Automation\Actions\AssignLeadAction as CrmAssignLeadAction;
use App\Modules\CRM\Automation\Actions\CreateFollowUpAction;
use App\Modules\CRM\Automation\Actions\MoveDealStageAction;
use App\Modules\CRM\Automation\Triggers\ActivityCompletedTrigger;
use App\Modules\CRM\Automation\Triggers\DealWonTrigger;
use App\Modules\CRM\Automation\Triggers\LeadCreatedTrigger as CrmLeadCreatedTrigger;
use App\Modules\CRM\Automation\Triggers\LeadQualifiedTrigger;
use App\Modules\Invoicing\Automation\Actions\CreateDraftInvoiceAction;
use App\Modules\Invoicing\Automation\Triggers\CreditNoteIssuedTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoiceIssuedTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoiceOverdueTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoicePaidTrigger;
use App\Modules\Invoicing\Automation\Triggers\PaymentReceivedTrigger;
use App\Modules\Projects\Automation\Actions\AssignTaskAction;
use App\Modules\Projects\Automation\Actions\CreateProjectAction;
use App\Modules\Projects\Automation\Actions\CreateTaskAction;
use App\Modules\Projects\Automation\Triggers\MilestoneCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\ProjectCreatedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCreatedTrigger;

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

final class ValidLeadCreatedTrigger extends BaseTestTrigger
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
    $this->registry->registerTrigger(ValidLeadCreatedTrigger::class);
    $this->registry->registerAction(AssignLeadAction::class);

    expect($this->registry->triggers())->toBe(['crm.lead.created' => ValidLeadCreatedTrigger::class])
        ->and($this->registry->actions())->toBe(['crm.lead.assign' => AssignLeadAction::class])
        ->and($this->registry->trigger('crm.lead.created'))->toBe(ValidLeadCreatedTrigger::class)
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
    $this->registry->registerTrigger(ValidLeadCreatedTrigger::class);

    expect(fn () => $this->registry->registerTrigger(DuplicateKeyTrigger::class))
        ->toThrow(InvalidArgumentException::class, 'Automation key [crm.lead.created] is registered twice');

    expect($this->registry->trigger('crm.lead.created'))->toBe(ValidLeadCreatedTrigger::class);
});

it('keeps triggers and actions in separate namespaces', function () {
    $this->registry->registerTrigger(ValidLeadCreatedTrigger::class);
    $this->registry->registerAction(SameKeyAsTriggerAction::class);

    expect($this->registry->trigger('crm.lead.created'))->toBe(ValidLeadCreatedTrigger::class)
        ->and($this->registry->action('crm.lead.created'))->toBe(SameKeyAsTriggerAction::class);
});

it('only accepts real definitions', function () {
    expect(fn () => $this->registry->registerTrigger(AssignLeadAction::class))
        ->toThrow(InvalidArgumentException::class, 'is not a trigger definition');
    expect(fn () => $this->registry->registerAction(ValidLeadCreatedTrigger::class))
        ->toThrow(InvalidArgumentException::class, 'is not an action definition');
    expect(fn () => $this->registry->registerTrigger(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->registry->registerAction('No\\Such\\Class'))
        ->toThrow(InvalidArgumentException::class);
});

describe('the application\'s own registry', function () {
    it('has the first-party triggers and actions registered from their manifests', function () {
        $registry = app(AutomationRegistry::class);

        expect($registry->triggers())->toBe([
            'core.member.joined' => MemberJoinedTrigger::class,
            'core.contact.created' => ContactCreatedTrigger::class,
            'core.company.created' => CompanyCreatedTrigger::class,
            'crm.lead.created' => CrmLeadCreatedTrigger::class,
            'crm.lead.qualified' => LeadQualifiedTrigger::class,
            'crm.deal.won' => DealWonTrigger::class,
            'crm.activity.completed' => ActivityCompletedTrigger::class,
            'projects.project.created' => ProjectCreatedTrigger::class,
            'projects.task.created' => TaskCreatedTrigger::class,
            'projects.task.completed' => TaskCompletedTrigger::class,
            'projects.milestone.completed' => MilestoneCompletedTrigger::class,
            'invoicing.invoice.issued' => InvoiceIssuedTrigger::class,
            'invoicing.invoice.paid' => InvoicePaidTrigger::class,
            'invoicing.payment.received' => PaymentReceivedTrigger::class,
            'invoicing.credit_note.issued' => CreditNoteIssuedTrigger::class,
            'invoicing.invoice.overdue' => InvoiceOverdueTrigger::class,
        ])
            ->and($registry->actions())->toBe([
                'crm.lead.assign' => CrmAssignLeadAction::class,
                'crm.activity.create_follow_up' => CreateFollowUpAction::class,
                'crm.deal.move_stage' => MoveDealStageAction::class,
                'projects.project.create' => CreateProjectAction::class,
                'projects.task.create' => CreateTaskAction::class,
                'projects.task.assign' => AssignTaskAction::class,
                'invoicing.invoice.create_draft' => CreateDraftInvoiceAction::class,
            ])
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
