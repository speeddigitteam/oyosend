<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Contacts\Models\Contact;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\InvoicingSettings;
use App\Modules\Invoicing\Actions\PaymentManager;
use App\Modules\Invoicing\Actions\RecurringManager;
use App\Modules\Invoicing\Actions\RecurringRunner;
use App\Modules\Invoicing\Actions\ReminderRunner;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\RecurringStatus;
use App\Modules\Invoicing\Events\InvoiceOverdue;
use App\Modules\Invoicing\Jobs\GenerateRecurringInvoices;
use App\Modules\Invoicing\Jobs\MaintainReceivables;
use App\Modules\Invoicing\Mail\InvoiceMail;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringTemplate;
use App\Modules\Invoicing\Models\ReminderLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = $this->createTenant('inv-rec', ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    $this->entitle($this->tenant, 'invoicing');
    $this->membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->contact = $this->inTenant($this->tenant, fn () => Contact::factory()->create([
        'first_name' => 'Tania', 'last_name' => 'Haque', 'email' => 'tania@example.test',
    ]));
    $this->at = fn (string $date) => $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Dhaka'));
    $this->template = function (array $overrides = []): RecurringTemplate {
        return $this->inTenant($this->tenant, fn (): RecurringTemplate => app(RecurringManager::class)->create([
            'contact_id' => $this->contact->id,
            'name' => 'Monthly retainer',
            'frequency' => 'monthly',
            'every' => 1,
            'currency' => 'BDT',
            'starts_on' => '2026-01-31',
            'due_days' => 10,
            'auto_issue' => false,
            'auto_send' => false,
            ...$overrides,
        ], [['name' => 'Retainer', 'quantity_milli' => 1000, 'unit_price_amount' => 80_000, 'tax_bps' => 500]], $this->membership));
    };
});

it('generates one draft per due period, catches up, and is idempotent when run again', function () {
    ($this->at)('2026-03-15');
    $template = ($this->template)();

    $this->inTenant($this->tenant, function () use ($template): void {
        $runner = app(RecurringRunner::class);

        expect($runner->run())->toBe(2)
            ->and($runner->run())->toBe(0);

        $invoices = Invoice::query()->where('recurring_template_id', $template->id)->orderBy('issued_on')->get();
        $fresh = $template->refresh();

        expect($invoices)->toHaveCount(2)
            ->and($invoices->pluck('issued_on')->map->toDateString()->all())->toBe(['2026-01-31', '2026-02-28'])
            ->and($invoices[0]->due_on->toDateString())->toBe('2026-02-10')
            ->and($invoices[0]->total_amount)->toBe(84_000)
            ->and($invoices[0]->status)->toBe(InvoiceStatus::Draft)
            ->and($invoices[0]->source_key)->toBe('recurring:'.$template->id.':2026-01-31')
            ->and($fresh->runs_count)->toBe(2)
            ->and($fresh->next_run_on->toDateString())->toBe('2026-03-31')
            ->and($fresh->last_run_on->toDateString())->toBe('2026-02-28');

        // The unique source key is the database-level guarantee behind that.
        expect(fn () => Invoice::query()->create([
            'currency' => 'BDT', 'status' => InvoiceStatus::Draft, 'source_key' => $invoices[0]->source_key,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    ($this->at)('2026-03-31');
    $this->inTenant($this->tenant, function () use ($template): void {
        expect(app(RecurringRunner::class)->run())->toBe(1)
            ->and($template->refresh()->next_run_on->toDateString())->toBe('2026-04-30');
    });
});

it('does not run paused templates, stops at the end date and refuses to resume an ended one', function () {
    ($this->at)('2026-03-15');
    $paused = ($this->template)(['name' => 'Paused one']);
    $ending = ($this->template)(['name' => 'Ends in February', 'ends_on' => '2026-02-28']);

    $this->inTenant($this->tenant, function () use ($paused, $ending): void {
        app(RecurringManager::class)->setStatus($paused, RecurringStatus::Paused);

        expect(app(RecurringRunner::class)->run())->toBe(2);

        $ended = $ending->refresh();
        expect($ended->status)->toBe(RecurringStatus::Ended)
            ->and($ended->next_run_on)->toBeNull()
            ->and($paused->refresh()->runs_count)->toBe(0);

        expect(fn () => app(RecurringManager::class)->setStatus($ended, RecurringStatus::Active))
            ->toThrow(ValidationException::class);

        app(RecurringManager::class)->setStatus($paused, RecurringStatus::Active);
        expect(app(RecurringRunner::class)->run())->toBe(2);
    });
});

it('issues and emails automatically when the template says so, and leaves a draft when it cannot', function () {
    Mail::fake();
    ($this->at)('2026-02-15');
    $template = ($this->template)(['auto_issue' => true, 'auto_send' => true]);

    $this->inTenant($this->tenant, function () use ($template): void {
        expect(app(RecurringRunner::class)->run())->toBe(1);

        $invoice = Invoice::query()->where('recurring_template_id', $template->id)->sole();
        expect($invoice->status)->toBe(InvoiceStatus::Issued)
            ->and($invoice->number)->toBe('INV-0001')
            ->and($invoice->sent_at)->not->toBeNull()
            ->and($invoice->issued_on->toDateString())->toBe('2026-01-31');
    });

    $this->inTenant($this->tenant, fn () => Mail::assertQueued(
        InvoiceMail::class,
        fn (InvoiceMail $mail): bool => $mail->hasTo('tania@example.test') && $mail->reminder === null,
    ));

    // Over the plan limit the period is still generated, as a draft with the reason recorded.
    $plan = $this->createFreePlan(['key' => 'no-invoices', 'modules' => ['invoicing'], 'limits' => ['invoicing.invoices_per_month' => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    ($this->at)('2026-02-28');

    $this->inTenant($this->tenant, function () use ($template): void {
        // The January invoice used the single allowance of this month; February is generated but cannot be issued.
        expect(app(RecurringRunner::class)->run())->toBe(1);

        $draft = Invoice::query()->where('recurring_template_id', $template->id)->where('issued_on', '2026-02-28')->sole();
        expect($draft->status)->toBe(InvoiceStatus::Draft)
            ->and($template->refresh()->last_error)->not->toBeNull()
            ->and($template->next_run_on->toDateString())->toBe('2026-03-31');
    });
});

it('queues the real recurring and receivables schedules for an entitled workspace only', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $app = CatalogApp::query()->where('module_key', 'invoicing')->with('currentVersion')->sole();

    expect(collect($app->currentVersion?->scheduled_jobs)->pluck('key')->all())
        ->toBe(['invoicing.recurring_invoices', 'invoicing.receivables']);

    // A workspace that holds the app through a real licence (the `entitle()` grant would make it "already included").
    // The clock moves first: a licence is only effective from the moment it was granted.
    ($this->at)('2026-03-15');
    $licensed = $this->createTenant('inv-licensed', ['timezone' => 'Asia/Dhaka']);
    $this->inTenant($licensed, fn () => app(AppStoreManager::class)->installFree($app, 'included'));
    Queue::fake();

    $this->artisan('apps:run-scheduled', ['--tenant' => $licensed->slug])->assertSuccessful();

    Queue::assertPushed(RunScheduledAppJob::class, 2);
    Queue::assertPushed(RunScheduledAppJob::class, fn (RunScheduledAppJob $job): bool => $job->definition === 'invoicing.recurring_invoices'
        && $job->displayName() === GenerateRecurringInvoices::class);
    Queue::assertPushed(RunScheduledAppJob::class, fn (RunScheduledAppJob $job): bool => $job->definition === 'invoicing.receivables'
        && $job->displayName() === MaintainReceivables::class);
});

describe('reminders and overdue', function () {
    beforeEach(function () {
        $this->dueInvoice = function (string $dueOn, array $extra = []): Invoice {
            return $this->inTenant($this->tenant, function () use ($dueOn, $extra): Invoice {
                $manager = app(InvoiceManager::class);

                return $manager->issue($manager->create([
                    'contact_id' => $this->contact->id, 'currency' => 'BDT', 'due_on' => $dueOn, ...$extra,
                ], [['name' => 'Service', 'quantity_milli' => 1000, 'unit_price_amount' => 50_000]], $this->membership));
            });
        };
        $this->enableReminders = fn () => $this->inTenant($this->tenant, fn () => app(InvoicingSettings::class)->update([
            'reminders_enabled' => true, 'reminder_before_days' => 3, 'reminder_overdue_every_days' => 7,
        ]));
        $this->remind = fn (): int => $this->inTenant($this->tenant, fn (): int => app(ReminderRunner::class)->run());
    });

    it('sends nothing unless reminders are switched on', function () {
        Mail::fake();
        ($this->at)('2026-05-01');
        ($this->dueInvoice)('2026-05-02');

        expect(($this->remind)())->toBe(0);
        Mail::assertNothingQueued();
    });

    it('sends each reminder once: before the due date, on it, then every N days overdue', function () {
        Mail::fake();
        ($this->at)('2026-05-01');
        ($this->enableReminders)();
        $invoice = ($this->dueInvoice)('2026-05-04');

        // 3 days before: the "before due" reminder, once.
        expect(($this->remind)())->toBe(1)
            ->and(($this->remind)())->toBe(0);

        ($this->at)('2026-05-04');
        expect(($this->remind)())->toBe(1);
        ($this->at)('2026-05-05');
        expect(($this->remind)())->toBe(1)->and(($this->remind)())->toBe(0);
        ($this->at)('2026-05-10');
        expect(($this->remind)())->toBe(0);
        ($this->at)('2026-05-12');
        expect(($this->remind)())->toBe(1);

        $keys = $this->inTenant($this->tenant, fn () => ReminderLog::query()->where('invoice_id', $invoice->id)->orderBy('created_at')->pluck('reminder_key')->all());
        expect($keys)->toEqualCanonicalizing(['before_due', 'on_due', 'overdue:1', 'overdue:2']);

        Mail::assertQueuedCount(4);
        Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->reminder === 'overdue');
    });

    it('skips paid invoices, voided invoices and customers without an email address', function () {
        Mail::fake();
        ($this->at)('2026-05-01');
        ($this->enableReminders)();
        $paid = ($this->dueInvoice)('2026-05-02');
        $this->inTenant($this->tenant, fn () => app(PaymentManager::class)->record($paid, ['amount' => 50_000, 'method' => 'cash']));
        $voided = ($this->dueInvoice)('2026-05-02');
        $this->inTenant($this->tenant, fn () => app(InvoiceManager::class)->void($voided, 'Mistake'));
        $nobody = $this->inTenant($this->tenant, fn () => Contact::factory()->create(['email' => null]));
        $this->inTenant($this->tenant, function () use ($nobody): void {
            $manager = app(InvoiceManager::class);
            $manager->issue($manager->create([
                'contact_id' => $nobody->id, 'currency' => 'BDT', 'due_on' => '2026-05-02',
            ], [['name' => 'Service', 'quantity_milli' => 1000, 'unit_price_amount' => 100]]));
        });

        expect(($this->remind)())->toBe(0);
    });

    it('marks past-due invoices overdue once and announces it once', function () {
        Event::fake([InvoiceOverdue::class]);
        ($this->at)('2026-05-01');
        $invoice = ($this->dueInvoice)('2026-05-03');
        ($this->at)('2026-05-09');

        $this->inTenant($this->tenant, function () use ($invoice): void {
            $manager = app(InvoiceManager::class);
            expect($manager->markOverdueInvoices())->toBe(1)
                ->and($manager->markOverdueInvoices())->toBe(0);

            $fresh = $invoice->refresh();
            expect($fresh->status)->toBe(InvoiceStatus::Overdue)
                ->and($fresh->overdue_at)->not->toBeNull();

            // An overdue invoice still takes payments and is settled by them.
            app(PaymentManager::class)->record($fresh, ['amount' => 20_000, 'method' => 'cash']);
            expect($invoice->refresh()->status)->toBe(InvoiceStatus::Overdue);
            app(PaymentManager::class)->record($fresh, ['amount' => 30_000, 'method' => 'cash']);
            expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
        });

        Event::assertDispatchedTimes(InvoiceOverdue::class, 1);
        Event::assertDispatched(InvoiceOverdue::class, fn (InvoiceOverdue $event): bool => $event->payload()['days_overdue'] === 6
            && $event->payload()['balance_amount'] === 50_000);
    });

    it('reopens a paid invoice as overdue when a payment is reversed after the due date', function () {
        ($this->at)('2026-05-01');
        $invoice = ($this->dueInvoice)('2026-05-03');
        $payment = $this->inTenant($this->tenant, fn () => app(PaymentManager::class)->record($invoice, ['amount' => 50_000, 'method' => 'cash']));
        ($this->at)('2026-05-20');

        $this->inTenant($this->tenant, function () use ($invoice, $payment): void {
            app(PaymentManager::class)->reverse($payment, 'Returned');
            expect($invoice->refresh()->status)->toBe(InvoiceStatus::Overdue);
        });
    });
});
