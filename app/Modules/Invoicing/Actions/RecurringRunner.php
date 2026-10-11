<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Invoicing\Enums\RecurringStatus;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Generates the invoices a recurring template is due for.
 *
 * One period produces at most one invoice: the period's source key
 * (`recurring:{template}:{date}`) is unique per workspace, so a re-run, a retried
 * job or two overlapping workers cannot bill the same period twice. A template that
 * fell behind catches up one period per loop, at most MAX_PERIODS per run.
 */
final class RecurringRunner
{
    private const int MAX_PERIODS = 12;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InvoiceDelivery $delivery,
        private readonly InvoiceManager $invoices,
        private readonly InvoicingSettings $settings,
    ) {}

    /** @return int Invoices generated in this run. */
    public function run(): int
    {
        $today = $this->settings->today();
        $generated = 0;

        RecurringTemplate::query()
            ->where('status', RecurringStatus::Active)
            ->whereNotNull('next_run_on')
            ->where('next_run_on', '<=', $today->toDateString())
            ->chunkById(50, function ($templates) use ($today, &$generated): void {
                foreach ($templates as $template) {
                    $generated += $this->runTemplate($template, $today);
                }
            });

        return $generated;
    }

    private function runTemplate(RecurringTemplate $template, CarbonImmutable $today): int
    {
        $generated = 0;

        for ($period = 0; $period < self::MAX_PERIODS; $period++) {
            $result = DB::transaction(fn (): ?array => $this->generate($template, $today));

            if ($result === null) {
                break;
            }

            [$invoice, $created, $autoIssue, $autoSend] = $result;

            if (! $created) {
                continue;
            }

            $generated++;
            $this->finish($template, $invoice, $autoIssue, $autoSend);
        }

        return $generated;
    }

    /**
     * One period inside one transaction: create the draft and advance the schedule together.
     *
     * @return array{0: Invoice, 1: bool, 2: bool, 3: bool}|null
     */
    private function generate(RecurringTemplate $template, CarbonImmutable $today): ?array
    {
        /** @var RecurringTemplate $locked */
        $locked = RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== RecurringStatus::Active || $locked->next_run_on === null
            || $locked->next_run_on->toDateString() > $today->toDateString()) {
            return null;
        }

        $runDate = $locked->next_run_on->toImmutable();

        if ($locked->ends_on !== null && $runDate->toDateString() > $locked->ends_on->toDateString()) {
            $this->end($locked);

            return null;
        }

        $key = InvoiceSources::TYPE_RECURRING.':'.$locked->id.':'.$runDate->toDateString();
        $invoice = Invoice::query()->where('source_key', $key)->first();
        $created = false;

        if ($invoice === null) {
            $invoice = $this->invoices->create([
                'contact_id' => $locked->contact_id,
                'company_id' => $locked->company_id,
                'recurring_template_id' => $locked->id,
                'currency' => $locked->currency,
                'title' => $locked->title,
                'reference' => $locked->reference,
                'issued_on' => $runDate->toDateString(),
                'due_on' => $runDate->addDays($locked->due_days)->toDateString(),
                'terms' => $locked->terms,
                'notes' => $locked->notes,
                'source_type' => InvoiceSources::TYPE_RECURRING,
                'source_id' => $locked->id,
                'source_key' => $key,
            ], $locked->lines, $locked->owner()->first());
            $created = true;
        }

        $runs = $locked->runs_count + 1;
        $next = $locked->frequency->occurrence($locked->starts_on->toImmutable(), $locked->every, $runs);
        $locked->runs_count = $runs;
        $locked->last_run_on = Carbon::parse($runDate->toDateString());
        $locked->last_error = null;
        $locked->next_run_on = Carbon::parse($next->toDateString());

        if ($locked->ends_on !== null && $next->toDateString() > $locked->ends_on->toDateString()) {
            $this->end($locked);
        } else {
            $locked->save();
        }

        if ($created) {
            $this->audit->record('invoicing.recurring.generated', $locked, ['invoice_id' => $invoice->id, 'period' => $runDate->toDateString()]);
        }

        return [$invoice, $created, $locked->auto_issue, $locked->auto_send];
    }

    private function end(RecurringTemplate $template): void
    {
        $template->status = RecurringStatus::Ended;
        $template->next_run_on = null;
        $template->save();
        $this->audit->record('invoicing.recurring.ended', $template, ['name' => $template->name]);
    }

    /** Issue and send after the period is committed, so a refusal leaves a draft rather than losing the period. */
    private function finish(RecurringTemplate $template, Invoice $invoice, bool $autoIssue, bool $autoSend): void
    {
        if (! $autoIssue) {
            return;
        }

        try {
            $issued = $this->invoices->issue($invoice);

            if ($autoSend && $issued->customer_email !== null) {
                $this->delivery->send($issued);
            }
        } catch (Throwable $exception) {
            $message = $exception instanceof ValidationException
                ? implode(' ', array_merge(...array_values($exception->errors())))
                : 'The invoice could not be issued automatically.';
            RecurringTemplate::query()->whereKey($template->id)->update(['last_error' => mb_substr($message, 0, 500)]);
        }
    }
}
