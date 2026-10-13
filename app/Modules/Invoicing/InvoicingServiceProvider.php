<?php

declare(strict_types=1);

namespace App\Modules\Invoicing;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\InvoicingContactMergeReference;
use App\Modules\Invoicing\Automation\Actions\CreateDraftInvoiceAction;
use App\Modules\Invoicing\Automation\Triggers\CreditNoteIssuedTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoiceIssuedTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoiceOverdueTrigger;
use App\Modules\Invoicing\Automation\Triggers\InvoicePaidTrigger;
use App\Modules\Invoicing\Automation\Triggers\PaymentReceivedTrigger;
use App\Modules\Invoicing\Jobs\GenerateRecurringInvoices;
use App\Modules\Invoicing\Jobs\MaintainReceivables;
use App\Modules\Invoicing\Listeners\CreateInvoiceFromAcceptedQuote;
use App\Modules\Invoicing\Listeners\RecordBillableTime;
use App\Modules\Invoicing\Models\BillableTime;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\CreditNoteLine;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\InvoicingSetting;
use App\Modules\Invoicing\Models\Payment;
use App\Modules\Invoicing\Models\RecurringTemplate;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Reporting\ReportProviders;
use Illuminate\Support\Facades\Event;

/**
 * Customer invoicing: invoices, payments recorded by hand, credit notes, recurring billing.
 * It is the Phase 6 consumer of the CRM `crm.quote.accepted` and Projects
 * `projects.time_entry.approved` events and imports no model from either module.
 */
final class InvoicingServiceProvider extends ModuleServiceProvider
{
    /** The documented hand-off events, referenced by class name only (the classes are not imported). */
    public const string CRM_QUOTE_ACCEPTED = 'App\\Modules\\CRM\\Events\\QuoteAccepted';

    public const string PROJECTS_TIME_ENTRY_APPROVED = 'App\\Modules\\Projects\\Events\\TimeEntryApproved';

    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        $this->app->tag(InvoicingContactMergeReference::class, ContactMergeReference::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'invoicing',
            name: 'Invoicing',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('invoicing.invoices.view', 'invoicing_invoices', scopable: true),
                new PermissionDefinition('invoicing.invoices.edit', 'invoicing_invoices', scopable: true),
                new PermissionDefinition('invoicing.invoices.issue', 'invoicing_invoices', scopable: true),
                new PermissionDefinition('invoicing.invoices.send', 'invoicing_invoices', scopable: true),
                new PermissionDefinition('invoicing.invoices.void', 'invoicing_invoices', scopable: true, sensitive: true),
                new PermissionDefinition('invoicing.payments.record', 'invoicing_payments', scopable: true),
                new PermissionDefinition('invoicing.payments.reverse', 'invoicing_payments', scopable: true, sensitive: true),
                new PermissionDefinition('invoicing.credit_notes.issue', 'invoicing_credit_notes', scopable: true, sensitive: true),
                new PermissionDefinition('invoicing.recurring.view', 'invoicing_recurring', scopable: true),
                new PermissionDefinition('invoicing.recurring.manage', 'invoicing_recurring', scopable: true),
                new PermissionDefinition('invoicing.reports.view', 'invoicing_reports'),
                new PermissionDefinition('invoicing.settings.manage', 'invoicing_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('invoicingInvoices', 'invoicing.invoices.index', 'receipt', 'invoicing.invoices.view', 'apps', 20, 'invoicing.invoices.*'),
                new NavigationItem('invoicingRecurring', 'invoicing.recurring.index', 'clock', 'invoicing.recurring.view', 'apps', 21, 'invoicing.recurring.*'),
                new NavigationItem('invoicingReports', 'invoicing.reports.index', 'chart-no-axes-combined', 'invoicing.reports.view', 'apps', 22, 'invoicing.reports.*'),
                new NavigationItem('invoicingSettings', 'invoicing.settings.index', 'settings', 'invoicing.settings.manage', 'apps', 900, 'invoicing.settings.*'),
            ],
            triggers: [
                InvoiceIssuedTrigger::class,
                InvoicePaidTrigger::class,
                PaymentReceivedTrigger::class,
                CreditNoteIssuedTrigger::class,
                InvoiceOverdueTrigger::class,
            ],
            actions: [CreateDraftInvoiceAction::class],
            morphMap: [
                'invoicing_invoice' => Invoice::class,
                'invoicing_invoice_line' => InvoiceLine::class,
                'invoicing_payment' => Payment::class,
                'invoicing_credit_note' => CreditNote::class,
                'invoicing_credit_note_line' => CreditNoteLine::class,
                'invoicing_tax_rate' => TaxRate::class,
                'invoicing_setting' => InvoicingSetting::class,
                'invoicing_recurring_template' => RecurringTemplate::class,
                'invoicing_billable_time' => BillableTime::class,
            ],
            limits: [new LimitDefinition(InvoiceManager::LIMIT)],
            description: 'Issue numbered invoices and credit notes, record payments and track what customers owe.',
            category: AppCategory::Invoicing,
            icon: 'receipt',
            featured: true,
            features: [
                'Numbered invoices with exact tax and discounts',
                'Manual payments, partial payments and credit notes',
                'Recurring invoices and payment reminders',
                'Print-ready documents and receivables ageing',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Initial customer invoicing release.',
            routes: [
                'invoicing.invoices.index',
                'invoicing.invoices.create',
                'invoicing.invoices.store',
                'invoicing.invoices.show',
                'invoicing.invoices.update',
                'invoicing.invoices.destroy',
                'invoicing.invoices.issue',
                'invoicing.invoices.send',
                'invoicing.invoices.void',
                'invoicing.invoices.document',
                'invoicing.invoices.payments.store',
                'invoicing.invoices.payments.reverse',
                'invoicing.invoices.credit-notes.store',
                'invoicing.invoices.credit-notes.document',
                'invoicing.billable-time.index',
                'invoicing.billable-time.store',
                'invoicing.recurring.index',
                'invoicing.recurring.store',
                'invoicing.recurring.update',
                'invoicing.recurring.status',
                'invoicing.reports.index',
                'invoicing.settings.index',
                'invoicing.settings.update',
                'invoicing.settings.tax-rates.store',
                'invoicing.settings.tax-rates.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('invoicing.recurring_invoices', GenerateRecurringInvoices::class, ScheduledJobFrequency::Hourly),
                new ScheduledJobDefinition('invoicing.receivables', MaintainReceivables::class, ScheduledJobFrequency::Hourly),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');

        Event::listen(self::CRM_QUOTE_ACCEPTED, [CreateInvoiceFromAcceptedQuote::class, 'handle']);
        Event::listen(self::PROJECTS_TIME_ENTRY_APPROVED, [RecordBillableTime::class, 'handle']);
    }
}
