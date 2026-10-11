<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Invoicing\Actions\InvoiceMath;
use App\Modules\Invoicing\Actions\RecurringManager;
use App\Modules\Invoicing\Enums\RecurringFrequency;
use App\Modules\Invoicing\Enums\RecurringStatus;
use App\Modules\Invoicing\Http\Requests\InvoicingRules;
use App\Modules\Invoicing\Models\RecurringTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class RecurringController
{
    public const string VIEW = 'invoicing.recurring.view';

    public const string MANAGE = 'invoicing.recurring.manage';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly RecurringManager $templates,
        private readonly InvoicingControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $templates = $this->records->constrain(RecurringTemplate::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('next_run_on')
            ->orderBy('name')
            ->paginate(25)
            ->through(fn (RecurringTemplate $template): array => $this->present($template, $actor));

        return Inertia::render('Modules/Invoicing/Pages/Recurring/Index', [
            'templates' => $templates,
            'frequencies' => array_map(static fn (RecurringFrequency $frequency): string => $frequency->value, RecurringFrequency::cases()),
            'canManage' => $actor->can(self::MANAGE),
            'form' => $actor->can(self::MANAGE) ? $this->support->formOptions($actor) : $this->support->emptyFormOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        [$attributes, $lines] = $this->validated($request, $actor);
        $this->templates->create($attributes, $lines, $this->support->membership($actor));
        Toast::success(__('Recurring invoice created.'));

        return redirect()->route('invoicing.recurring.index');
    }

    public function update(Request $request, RecurringTemplate $template): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::MANAGE, $template);
        [$attributes, $lines] = $this->validated($request, $actor);
        $this->templates->update($template, $attributes, $lines);
        Toast::success(__('Recurring invoice saved.'));

        return back();
    }

    public function status(Request $request, RecurringTemplate $template): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::MANAGE, $template);
        $input = $request->validate([
            'status' => ['required', Rule::in([RecurringStatus::Active->value, RecurringStatus::Paused->value, RecurringStatus::Ended->value])],
        ]);
        $this->templates->setStatus($template, RecurringStatus::from($input['status']));
        Toast::success(__('Recurring invoice updated.'));

        return back();
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validated(Request $request, User $actor): array
    {
        $input = $request->validate([
            ...InvoicingRules::customer(),
            'name' => ['required', 'string', 'max:160'],
            'frequency' => ['required', Rule::enum(RecurringFrequency::class)],
            'every' => ['required', 'integer', 'between:1,12'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'due_days' => ['required', 'integer', 'between:0,365'],
            'auto_issue' => ['required', 'boolean'],
            'auto_send' => ['required', 'boolean'],
            'title' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:120'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            ...InvoicingRules::lines(),
        ]);
        $customer = $this->support->customer($input, $actor);
        /** @var list<array<string, mixed>> $lines */
        $lines = $input['lines'];
        unset($input['lines']);

        return [[...$input, ...$customer], $lines];
    }

    /** @return array<string, mixed> */
    private function present(RecurringTemplate $template, User $actor): array
    {
        $total = 0;

        try {
            $total = InvoiceMath::sum(array_map(static fn (array $line): int => InvoiceMath::line(
                (int) $line['unit_price_amount'],
                (int) $line['quantity_milli'],
                isset($line['discount_amount']) ? (int) $line['discount_amount'] : null,
                isset($line['discount_bps']) ? (int) $line['discount_bps'] : null,
                (int) ($line['tax_bps'] ?? 0),
            )['line_total_amount'], $template->lines));
        } catch (\Throwable) {
            $total = 0;
        }

        return [
            'id' => $template->id,
            'name' => $template->name,
            'status' => $template->status->value,
            'frequency' => $template->frequency->value,
            'every' => $template->every,
            'currency' => $template->currency,
            'startsOn' => $template->starts_on->toDateString(),
            'nextRunOn' => $template->next_run_on?->toDateString(),
            'endsOn' => $template->ends_on?->toDateString(),
            'lastRunOn' => $template->last_run_on?->toDateString(),
            'runsCount' => $template->runs_count,
            'lastError' => $template->last_error,
            'dueDays' => $template->due_days,
            'autoIssue' => $template->auto_issue,
            'autoSend' => $template->auto_send,
            'title' => $template->title,
            'reference' => $template->reference,
            'terms' => $template->terms,
            'notes' => $template->notes,
            'contactId' => $template->contact_id,
            'companyId' => $template->company_id,
            'customer' => ['name' => $template->company->name ?? $template->contact?->fullName()],
            'totalAmount' => $total,
            'lines' => $template->lines,
            'owner' => $template->owner === null ? null : ['id' => $template->owner->id, 'name' => $template->owner->user->name],
            'editable' => $template->status !== RecurringStatus::Ended && $this->records->allows($actor, self::MANAGE, $template),
        ];
    }
}
