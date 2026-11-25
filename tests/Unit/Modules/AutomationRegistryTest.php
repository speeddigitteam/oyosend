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
use App\Modules\Ai\Automation\Actions\GenerateTextAction;
use App\Modules\Ai\Automation\Triggers\ProposalConfirmedTrigger;
use App\Modules\Automation\Automation\Triggers\ManualTrigger;
use App\Modules\Automation\Automation\Triggers\ScheduleTrigger;
use App\Modules\Automation\Automation\Triggers\WebhookTrigger;
use App\Modules\Calendar\Automation\Triggers\CalendarEventCreatedTrigger;
use App\Modules\Communication\Automation\Actions\LogInteractionAction;
use App\Modules\Communication\Automation\Actions\SendTemplateMessageAction;
use App\Modules\Communication\Automation\Triggers\ContactUnsubscribedTrigger;
use App\Modules\Communication\Automation\Triggers\MessageBouncedTrigger;
use App\Modules\Communication\Automation\Triggers\MessageDeliveredTrigger;
use App\Modules\Communication\Automation\Triggers\MessageFailedTrigger;
use App\Modules\CRM\Automation\Actions\AssignLeadAction as CrmAssignLeadAction;
use App\Modules\CRM\Automation\Actions\CreateFollowUpAction;
use App\Modules\CRM\Automation\Actions\CreateLeadAction;
use App\Modules\CRM\Automation\Actions\MoveDealStageAction;
use App\Modules\CRM\Automation\Triggers\ActivityCompletedTrigger;
use App\Modules\CRM\Automation\Triggers\DealWonTrigger;
use App\Modules\CRM\Automation\Triggers\LeadCreatedTrigger as CrmLeadCreatedTrigger;
use App\Modules\CRM\Automation\Triggers\LeadQualifiedTrigger;
use App\Modules\CRM\Automation\Triggers\QuoteAcceptedTrigger;
use App\Modules\CRM\Automation\Triggers\SalesOrderStatusChangedTrigger;
use App\Modules\EmailMarketing\Automation\Actions\AddToListAction;
use App\Modules\EmailMarketing\Automation\Triggers\CampaignSentTrigger;
use App\Modules\EmailMarketing\Automation\Triggers\SubscriberSubscribedTrigger;
use App\Modules\Hrm\Automation\Actions\AssignManagerAction as HrmAssignManagerAction;
use App\Modules\Hrm\Automation\Actions\GrantLeaveBalanceAction;
use App\Modules\Hrm\Automation\Triggers\AttendanceDayClosedTrigger;
use App\Modules\Hrm\Automation\Triggers\EmployeeCreatedTrigger;
use App\Modules\Hrm\Automation\Triggers\EmployeeTerminatedTrigger;
use App\Modules\Hrm\Automation\Triggers\LeaveApprovedTrigger;
use App\Modules\Hrm\Automation\Triggers\LeaveRequestedTrigger;
use App\Modules\Invoicing\Automation\Actions\CreateDraftInvoiceAction;
use App\Modules\Invoicing\Automation\Triggers\CreditNoteIssuedTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoiceIssuedTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoiceOverdueTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoicePaidTrigger;
use App\Modules\Invoicing\Automation\Triggers\PaymentReceivedTrigger;
use App\Modules\Links\Automation\Triggers\LinkCreatedTrigger;
use App\Modules\MetaAds\Automation\Triggers\ConnectionNeedsReauthorizationTrigger;
use App\Modules\Notes\Automation\Triggers\NoteCreatedTrigger;
use App\Modules\Payroll\Automation\Actions\CreatePayItemAction;
use App\Modules\Payroll\Automation\Actions\PrepareRunAction;
use App\Modules\Payroll\Automation\Triggers\PayslipPaidTrigger;
use App\Modules\Payroll\Automation\Triggers\RunApprovedTrigger;
use App\Modules\Payroll\Automation\Triggers\RunReversedTrigger;
use App\Modules\Projects\Automation\Actions\AssignTaskAction;
use App\Modules\Projects\Automation\Actions\CreateProjectAction;
use App\Modules\Projects\Automation\Actions\CreateTaskAction;
use App\Modules\Projects\Automation\Triggers\MilestoneCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\ProjectCreatedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCreatedTrigger;
use App\Modules\SmsMarketing\Automation\Actions\AddContactToGroupAction;
use App\Modules\SmsMarketing\Automation\Triggers\CampaignCompletedTrigger;
use App\Modules\SmsMarketing\Automation\Triggers\CampaignLaunchedTrigger;
use App\Modules\Social\Automation\Actions\CreateDraftPostAction;
use App\Modules\Social\Automation\Triggers\AccountNeedsReauthorizationTrigger;
use App\Modules\Social\Automation\Triggers\PostApprovedTrigger;
use App\Modules\Social\Automation\Triggers\PostFailedTrigger;
use App\Modules\Social\Automation\Triggers\PostPublishedTrigger;
use App\Modules\Social\Automation\Triggers\PostScheduledTrigger;
use App\Modules\Social\Comments\Automation\CommentReceivedTrigger;
use App\Modules\Social\Comments\Automation\HideCommentAction;
use App\Modules\Social\Comments\Automation\ReplyToCommentAction;
use App\Modules\Social\LeadAds\Automation\LeadAdReceivedTrigger;
use App\Modules\Support\Automation\Actions\AddInternalNoteAction;
use App\Modules\Support\Automation\Actions\AssignTicketAction;
use App\Modules\Support\Automation\Actions\CreateTicketAction;
use App\Modules\Support\Automation\Triggers\CsatSubmittedTrigger;
use App\Modules\Support\Automation\Triggers\TicketAssignedTrigger;
use App\Modules\Support\Automation\Triggers\TicketCreatedTrigger;
use App\Modules\Support\Automation\Triggers\TicketCustomerRepliedTrigger;
use App\Modules\Support\Automation\Triggers\TicketResolvedTrigger;
use App\Modules\Support\Automation\Triggers\TicketSlaBreachedTrigger;
use App\Modules\Talent\Automation\Actions\EnrolInCourseAction;
use App\Modules\Talent\Automation\Actions\StartChecklistAction;
use App\Modules\Talent\Automation\Triggers\ApplicationStageChangedTrigger;
use App\Modules\Talent\Automation\Triggers\CandidateHiredTrigger;
use App\Modules\Talent\Automation\Triggers\CertificateExpiredTrigger;
use App\Modules\Talent\Automation\Triggers\CertificateExpiringTrigger;
use App\Modules\Talent\Automation\Triggers\ChecklistCompletedTrigger;
use App\Modules\Talent\Automation\Triggers\InterviewScheduledTrigger;
use App\Modules\Talent\Automation\Triggers\ProbationReviewDueTrigger;
use App\Modules\Talent\Automation\Triggers\ReviewCalibratedTrigger;
use App\Modules\Talent\Automation\Triggers\TrainingCompletedTrigger;
use App\Modules\Telephony\Automation\Triggers\CallEndedTrigger;
use App\Modules\WhatsappMarketing\Automation\Actions\AssignConversationAction;
use App\Modules\WhatsappMarketing\Automation\Actions\SendTemplateAction;
use App\Modules\WhatsappMarketing\Automation\Actions\TagConversationAction;
use App\Modules\WhatsappMarketing\Automation\Triggers\CampaignCompletedTrigger as WhatsappMarketingCampaignCompletedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\ConversationAssignedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\FeedbackReceivedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\MessageReadTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\MessageReceivedTrigger;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendAppointmentReminderAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendInvoiceNotificationAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendOrderConfirmationAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendPaymentReminderAction;

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
            'crm.quote.accepted' => QuoteAcceptedTrigger::class,
            'crm.sales_order.status_changed' => SalesOrderStatusChangedTrigger::class,
            'projects.project.created' => ProjectCreatedTrigger::class,
            'projects.task.created' => TaskCreatedTrigger::class,
            'projects.task.completed' => TaskCompletedTrigger::class,
            'projects.milestone.completed' => MilestoneCompletedTrigger::class,
            'invoicing.invoice.issued' => InvoiceIssuedTrigger::class,
            'invoicing.invoice.paid' => InvoicePaidTrigger::class,
            'invoicing.payment.received' => PaymentReceivedTrigger::class,
            'invoicing.credit_note.issued' => CreditNoteIssuedTrigger::class,
            'invoicing.invoice.overdue' => InvoiceOverdueTrigger::class,
            'hrm.employee.created' => EmployeeCreatedTrigger::class,
            'hrm.employee.terminated' => EmployeeTerminatedTrigger::class,
            'hrm.leave.requested' => LeaveRequestedTrigger::class,
            'hrm.leave.approved' => LeaveApprovedTrigger::class,
            'hrm.attendance.day_closed' => AttendanceDayClosedTrigger::class,
            'payroll.run.approved' => RunApprovedTrigger::class,
            'payroll.payslip.paid' => PayslipPaidTrigger::class,
            'payroll.run.reversed' => RunReversedTrigger::class,
            'talent.candidate.hired' => CandidateHiredTrigger::class,
            'talent.application.stage_changed' => ApplicationStageChangedTrigger::class,
            'talent.interview.scheduled' => InterviewScheduledTrigger::class,
            'talent.checklist.completed' => ChecklistCompletedTrigger::class,
            'talent.probation.review_due' => ProbationReviewDueTrigger::class,
            'talent.review.calibrated' => ReviewCalibratedTrigger::class,
            'talent.training.completed' => TrainingCompletedTrigger::class,
            'talent.certificate.expiring' => CertificateExpiringTrigger::class,
            'talent.certificate.expired' => CertificateExpiredTrigger::class,
            'automation.trigger.manual' => ManualTrigger::class,
            'automation.trigger.webhook' => WebhookTrigger::class,
            'automation.trigger.schedule' => ScheduleTrigger::class,
            'ai.proposal.confirmed' => ProposalConfirmedTrigger::class,
            'communication.message.delivered' => MessageDeliveredTrigger::class,
            'communication.message.failed' => MessageFailedTrigger::class,
            'communication.message.bounced' => MessageBouncedTrigger::class,
            'communication.contact.unsubscribed' => ContactUnsubscribedTrigger::class,
            'email_marketing.campaign.sent' => CampaignSentTrigger::class,
            'email_marketing.subscriber.subscribed' => SubscriberSubscribedTrigger::class,
            'social.post.scheduled' => PostScheduledTrigger::class,
            'social.post.approved' => PostApprovedTrigger::class,
            'social.post.published' => PostPublishedTrigger::class,
            'social.post.failed' => PostFailedTrigger::class,
            'social.account.needs_reauthorization' => AccountNeedsReauthorizationTrigger::class,
            'social.comment.received' => CommentReceivedTrigger::class,
            'social.lead_ad.received' => LeadAdReceivedTrigger::class,
            'meta_ads.connection.needs_reauthorization' => ConnectionNeedsReauthorizationTrigger::class,
            'sms_marketing.campaign.launched' => CampaignLaunchedTrigger::class,
            'sms_marketing.campaign.completed' => CampaignCompletedTrigger::class,
            'support.ticket.created' => TicketCreatedTrigger::class,
            'support.ticket.assigned' => TicketAssignedTrigger::class,
            'support.ticket.customer_replied' => TicketCustomerRepliedTrigger::class,
            'support.ticket.resolved' => TicketResolvedTrigger::class,
            'support.ticket.sla_breached' => TicketSlaBreachedTrigger::class,
            'support.csat.submitted' => CsatSubmittedTrigger::class,
            'whatsapp_marketing.message.received' => MessageReceivedTrigger::class,
            'whatsapp_marketing.message.read' => MessageReadTrigger::class,
            'whatsapp_marketing.conversation.assigned' => ConversationAssignedTrigger::class,
            'whatsapp_marketing.campaign.completed' => WhatsappMarketingCampaignCompletedTrigger::class,
            'whatsapp_marketing.feedback.received' => FeedbackReceivedTrigger::class,
            'notes.note.created' => NoteCreatedTrigger::class,
            'telephony.call.ended' => CallEndedTrigger::class,
            'links.link.created' => LinkCreatedTrigger::class,
            'calendar.event.created' => CalendarEventCreatedTrigger::class,
        ])
            ->and($registry->actions())->toBe([
                'crm.lead.assign' => CrmAssignLeadAction::class,
                'crm.lead.create' => CreateLeadAction::class,
                'crm.activity.create_follow_up' => CreateFollowUpAction::class,
                'crm.deal.move_stage' => MoveDealStageAction::class,
                'projects.project.create' => CreateProjectAction::class,
                'projects.task.create' => CreateTaskAction::class,
                'projects.task.assign' => AssignTaskAction::class,
                'invoicing.invoice.create_draft' => CreateDraftInvoiceAction::class,
                'hrm.leave.grant_balance' => GrantLeaveBalanceAction::class,
                'hrm.employee.assign_manager' => HrmAssignManagerAction::class,
                'payroll.item.create' => CreatePayItemAction::class,
                'payroll.run.prepare_draft' => PrepareRunAction::class,
                'talent.checklist.start' => StartChecklistAction::class,
                'talent.course.enrol' => EnrolInCourseAction::class,
                'ai.text.generate' => GenerateTextAction::class,
                'communication.message.send' => SendTemplateMessageAction::class,
                'communication.interaction.log' => LogInteractionAction::class,
                'email_marketing.subscriber.add_to_list' => AddToListAction::class,
                'social.post.create_draft' => CreateDraftPostAction::class,
                'social.comment.reply' => ReplyToCommentAction::class,
                'social.comment.hide' => HideCommentAction::class,
                'sms_marketing.group.add_contact' => AddContactToGroupAction::class,
                'support.ticket.create' => CreateTicketAction::class,
                'support.ticket.assign' => AssignTicketAction::class,
                'support.ticket.add_note' => AddInternalNoteAction::class,
                'whatsapp_marketing.template.send' => SendTemplateAction::class,
                'whatsapp_marketing.conversation.assign' => AssignConversationAction::class,
                'whatsapp_marketing.conversation.add_tag' => TagConversationAction::class,
                'whatsapp_marketing.send_order_confirmation' => SendOrderConfirmationAction::class,
                'whatsapp_marketing.send_invoice_notification' => SendInvoiceNotificationAction::class,
                'whatsapp_marketing.send_appointment_reminder' => SendAppointmentReminderAction::class,
                'whatsapp_marketing.send_payment_reminder' => SendPaymentReminderAction::class,
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
