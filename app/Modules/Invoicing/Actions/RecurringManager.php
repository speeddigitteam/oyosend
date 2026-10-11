<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Invoicing\Enums\RecurringFrequency;
use App\Modules\Invoicing\Enums\RecurringStatus;
use App\Modules\Invoicing\Models\RecurringTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecurringManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InvoiceLines $lineBuilder,
        private readonly InvoiceManager $invoices,
        private readonly InvoicingSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $attributes, array $lines, ?TenantMembership $owner = null): RecurringTemplate
    {
        $data = $this->validated($attributes, $lines);

        return DB::transaction(function () use ($data, $owner): RecurringTemplate {
            $template = new RecurringTemplate([
                ...$data,
                'status' => RecurringStatus::Active,
                'next_run_on' => $data['starts_on'],
                'runs_count' => 0,
            ]);

            if ($owner !== null) {
                $template->assignOwner($owner);
            }

            $template->save();

            return $template;
        });
    }

    /**
     * Editing a template changes future invoices only. Invoices already generated are
     * independent documents.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(RecurringTemplate $template, array $attributes, array $lines): RecurringTemplate
    {
        $data = $this->validated($attributes, $lines);

        return DB::transaction(function () use ($template, $data): RecurringTemplate {
            $locked = $this->lock($template);

            if ($locked->status === RecurringStatus::Ended) {
                throw ValidationException::withMessages(['template' => __('An ended recurring invoice cannot be edited.')]);
            }

            // The schedule is anchored on the start date: keep what was already generated.
            if ($locked->runs_count > 0) {
                unset($data['starts_on']);
            } else {
                $locked->next_run_on = $data['starts_on'];
            }

            $locked->fill($data)->save();

            return $locked;
        });
    }

    public function setStatus(RecurringTemplate $template, RecurringStatus $status): RecurringTemplate
    {
        return DB::transaction(function () use ($template, $status): RecurringTemplate {
            $locked = $this->lock($template);

            if ($locked->status === $status) {
                return $locked;
            }

            if ($locked->status === RecurringStatus::Ended) {
                throw ValidationException::withMessages(['status' => __('An ended recurring invoice cannot be resumed.')]);
            }

            if ($status === RecurringStatus::Active) {
                if ($locked->next_run_on === null) {
                    throw ValidationException::withMessages(['status' => __('This recurring invoice has no further runs.')]);
                }
            }

            $locked->status = $status;
            $locked->save();
            $this->audit->record('invoicing.recurring.'.$status->value, $locked, ['name' => $locked->name]);

            return $locked;
        });
    }

    private function lock(RecurringTemplate $template): RecurringTemplate
    {
        /** @var RecurringTemplate */
        return RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function validated(array $attributes, array $lines): array
    {
        $this->invoices->customer($attributes['contact_id'] ?? null, $attributes['company_id'] ?? null);
        $currency = strtoupper(trim((string) ($attributes['currency'] ?? '')));

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw ValidationException::withMessages(['currency' => __('Choose a three-letter currency code.')]);
        }

        $frequency = RecurringFrequency::tryFrom((string) ($attributes['frequency'] ?? ''))
            ?? throw ValidationException::withMessages(['frequency' => __('Choose how often to invoice.')]);
        $every = (int) ($attributes['every'] ?? 1);

        if ($every < 1 || $every > 12) {
            throw ValidationException::withMessages(['every' => __('The interval must be between 1 and 12.')]);
        }

        $startsOn = CarbonImmutable::parse((string) $attributes['starts_on'])->startOfDay();
        $endsOn = ($attributes['ends_on'] ?? null) === null ? null : CarbonImmutable::parse((string) $attributes['ends_on'])->startOfDay();

        if ($endsOn !== null && $endsOn->lt($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => __('The end date cannot be before the start date.')]);
        }

        return [
            'contact_id' => $attributes['contact_id'] ?? null,
            'company_id' => $attributes['company_id'] ?? null,
            'name' => trim((string) $attributes['name']),
            'frequency' => $frequency,
            'every' => $every,
            'currency' => $currency,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn?->toDateString(),
            'due_days' => (int) ($attributes['due_days'] ?? $this->settings->current()->default_due_days),
            'auto_issue' => (bool) ($attributes['auto_issue'] ?? false),
            'auto_send' => (bool) ($attributes['auto_send'] ?? false),
            'title' => $attributes['title'] ?? null,
            'reference' => $attributes['reference'] ?? null,
            'terms' => $attributes['terms'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'lines' => $this->lineBuilder->normalize($lines),
        ];
    }
}
