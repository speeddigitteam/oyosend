<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\FieldMapper;
use App\Modules\Social\LeadAds\Jobs\BackfillFacebookLeads;
use App\Modules\Social\LeadAds\Jobs\ProcessFacebookLead;
use App\Modules\Social\LeadAds\LeadAdsAvailability;
use App\Modules\Social\LeadAds\LeadAdsSetup;
use App\Modules\Social\LeadAds\LeadBackfill;
use App\Modules\Social\LeadAds\LeadStatus;
use App\Modules\Social\LeadAds\LeadTrail;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdLog;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Lead forms" page: per connected Facebook Page its connection state, forms, answer mapping and the
 * processing history, plus the manual actions (switch on, refresh forms, fetch recent leads, retry a lead).
 * Viewing needs `social.leadads.view`; every change needs `social.leadads.manage` (route middleware).
 */
final class LeadAdsController
{
    public function __construct(
        private readonly LeadAdsSetup $setup,
        private readonly LeadAdsAvailability $availability,
        private readonly LeadBackfill $backfill,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'account_id' => ['nullable', 'string', 'size:26'],
            'form_id' => ['nullable', 'string', 'size:26'],
            'lead' => ['nullable', 'string', 'size:26'],
        ]);

        $accounts = SocialAccount::query()
            ->where('platform', 'facebook')
            ->where('status', '!=', AccountStatus::Disconnected->value)
            ->orderBy('name')->get();
        /** @var array<string, LeadAdsPage> $pages */
        $pages = [];

        foreach (LeadAdsPage::query()->whereIn('account_id', $accounts->pluck('id'))->get() as $row) {
            $pages[$row->account_id] = $row;
        }

        $forms = LeadForm::query()->whereIn('account_id', $accounts->pluck('id'))->orderBy('name')->get()->groupBy('account_id');
        $counts = $this->counts($accounts->pluck('id')->all());
        $formCounts = $this->formCounts($accounts->pluck('id')->all());
        $history = $this->history($accounts->pluck('id')->all());
        $required = $this->availability->requiredScopes();

        $leads = LeadAdLead::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['account_id'] ?? null, fn ($query, string $id) => $query->where('account_id', $id))
            ->when($filters['form_id'] ?? null, fn ($query, string $id) => $query->where('form_id', $id))
            ->orderByDesc('received_at')
            ->paginate(20)
            ->withQueryString();

        $accountNames = $accounts->pluck('name', 'id');
        $formNames = LeadForm::query()->whereIn('id', $leads->getCollection()->pluck('form_id')->filter()->all())->pluck('name', 'id');

        $selected = null;

        if (isset($filters['lead'])) {
            $lead = LeadAdLead::query()->find($filters['lead']);
            $selected = $lead === null ? null : [
                'lead' => $this->leadRow($lead, $accountNames->all(), []),
                'log' => LeadAdLog::query()->where('lead_id', $lead->id)->orderBy('created_at')->orderBy('id')->limit(100)->get()
                    ->map(static fn (LeadAdLog $log): array => [
                        'id' => $log->id, 'step' => $log->step, 'code' => $log->code, 'message' => $log->message,
                        'at' => $log->created_at->toIso8601String(),
                    ])->all(),
            ];
        }

        return Inertia::render('Modules/Social/Pages/LeadAds/Index', [
            'availability' => [
                'problem' => $this->availability->operatorProblem(),
                'crmAvailable' => $this->availability->crmAvailable(),
                'requiredScopes' => $required,
                'webhookUrl' => $this->webhookUrl(),
                'backfillDays' => $this->backfill->window(null),
                'backfillMaxDays' => $this->backfill->window(PHP_INT_MAX),
            ],
            'pages' => $accounts->map(function (SocialAccount $account) use ($pages, $forms, $counts, $formCounts, $history, $required): array {
                $page = $pages[$account->id] ?? null;

                return [
                    'account' => [
                        'id' => $account->id,
                        'name' => $account->name,
                        'avatarUrl' => $account->avatar_url,
                        'status' => $account->status->value,
                        'statusReason' => $account->status_reason,
                    ],
                    'state' => $page->state ?? LeadAdsPage::OFF,
                    'pageId' => $account->external_id,
                    'subscribedAt' => $page?->subscribed_at?->toIso8601String(),
                    'missingScopes' => $page->missing_scopes ?? [],
                    // Scopes recorded when the account was connected that lack a lead permission: reconnect.
                    'needsReconnect' => array_values(array_diff($required, (array) ($account->scopes ?? []))) !== [] && $page?->granted_scopes === null,
                    'permissionsCheckedAt' => $page?->permissions_checked_at?->toIso8601String(),
                    'lastError' => $page?->last_error,
                    'lastErrorCode' => $page?->last_error_code,
                    'formsSyncedAt' => $page?->forms_synced_at?->toIso8601String(),
                    'formsSyncError' => $page?->forms_sync_error,
                    'lastBackfillAt' => $page?->last_backfill_at?->toIso8601String(),
                    'counts' => $counts[$account->id] ?? $this->emptyCounts(),
                    'history' => $history[$account->id] ?? [],
                    'forms' => ($forms->get($account->id) ?? collect())->map(fn (LeadForm $form): array => $this->formRow($form, $formCounts[$form->id] ?? $this->emptyCounts()))->values()->all(),
                ];
            })->values()->all(),
            'leads' => $leads->through(fn (LeadAdLead $lead): array => $this->leadRow($lead, $accountNames->all(), $formNames->all())),
            'filters' => [
                'status' => $filters['status'] ?? null,
                'account_id' => $filters['account_id'] ?? null,
                'form_id' => $filters['form_id'] ?? null,
            ],
            'statuses' => array_map(static fn (LeadStatus $status): string => $status->value, LeadStatus::cases()),
            'selected' => $selected,
            'canManage' => $request->user('web')?->can('social.leadads.manage') === true,
            'targets' => FieldMapper::TARGETS,
        ]);
    }

    public function enable(Request $request, string $account): RedirectResponse
    {
        $this->setup->enable($this->account($account));
        Toast::success(__('Lead capture is on for this Page. Switch on the forms you want to receive.'));

        return back();
    }

    public function disable(Request $request, string $account): RedirectResponse
    {
        $this->setup->disable($this->account($account));
        Toast::success(__('Lead capture is off for this Page. Leads already received are kept.'));

        return back();
    }

    public function refreshForms(Request $request, string $account): RedirectResponse
    {
        $account = $this->account($account);
        $count = $this->setup->syncForms($account);

        if ($count < 0) {
            Toast::error(__('The forms could not be listed. See the Page status for the reason.'));
        } else {
            Toast::success($count === 0 ? __('The Page has no lead forms.') : __('Lead forms listed: :count.', ['count' => $count]));
        }

        return back();
    }

    public function backfill(Request $request, string $account): RedirectResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $account = $this->account($account);
        $page = $this->setup->pageFor($account);

        if (! $page->isSubscribed()) {
            Toast::error(__('Switch on lead capture for this Page first.'));

            return back();
        }

        if (! LeadForm::query()->where('account_id', $account->id)->where('is_enabled', true)->exists()) {
            Toast::error(__('Switch on at least one form first.'));

            return back();
        }

        $days = $this->backfill->window(isset($data['days']) ? (int) $data['days'] : null);
        BackfillFacebookLeads::dispatch($page->id, $days);
        $this->audit->record('social.leadads.backfill_requested', $page, ['days' => $days]);
        Toast::success(__('Fetching the last :days days of leads. They appear in the list below as they arrive.', ['days' => $days]));

        return back();
    }

    public function switchForm(Request $request, string $form): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $row = LeadForm::query()->findOrFail($form);
        $this->setup->switchForm($row, (bool) $data['enabled']);

        return back();
    }

    public function saveMapping(Request $request, string $form): RedirectResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array', 'max:60'],
            'mapping.*.target' => ['required', 'string', Rule::in(FieldMapper::TARGETS)],
            'mapping.*.answer_key' => ['nullable', 'string', 'max:64'],
        ]);

        $this->setup->saveMapping(LeadForm::query()->findOrFail($form), $data['mapping']);
        Toast::success(__('Mapping saved. It applies to the next leads and to any you retry.'));

        return back();
    }

    /** What an unsaved mapping would do with an example lead. */
    public function preview(Request $request, string $form): JsonResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array', 'max:60'],
            'mapping.*.target' => ['required', 'string', Rule::in(FieldMapper::TARGETS)],
            'mapping.*.answer_key' => ['nullable', 'string', 'max:64'],
        ]);

        $row = LeadForm::query()->findOrFail($form);
        $known = array_column($row->questions ?? [], 'key');
        $draft = array_intersect_key($data['mapping'], array_flip($known));
        $described = $this->setup->describe($row, $draft);

        return response()->json(['preview' => $described['preview'], 'warnings' => $described['warnings']]);
    }

    public function retry(Request $request, string $lead): RedirectResponse
    {
        $row = LeadAdLead::query()->findOrFail($lead);

        if (! in_array($row->status, [LeadStatus::Failed, LeadStatus::Skipped, LeadStatus::WaitingForCrm, LeadStatus::WaitingForAccount, LeadStatus::Received], true)) {
            Toast::info(__('This lead is already in the CRM.'));

            return back();
        }

        $row->forceFill([
            'status' => LeadStatus::Received,
            'next_attempt_at' => null,
            'claimed_at' => null,
            'reason_code' => null,
            'reason' => null,
            // A manual retry starts the automatic retry budget again.
            'attempts' => 0,
        ])->save();

        app(LeadTrail::class)->add($row, LeadTrail::RETRY, 'manual', __('A person asked to process this lead again.'));
        $this->audit->record('social.leadads.lead_retried', null, ['lead_ad_id' => $row->id]);
        ProcessFacebookLead::dispatch($row->id, true);
        Toast::success(__('The lead is queued again.'));

        return back();
    }

    private function account(string $id): SocialAccount
    {
        $account = SocialAccount::query()->where('platform', 'facebook')->findOrFail($id);
        abort_if($account->status === AccountStatus::Disconnected, 404);

        return $account;
    }

    private function webhookUrl(): string
    {
        $slug = $this->tenants->require()->slug;

        return 'https://'.$slug.'.'.config('platform.domain').'/social/webhooks/facebook/leads';
    }

    /**
     * @param  array<string, string>  $accounts
     * @param  array<string, string>  $forms
     * @return array<string, mixed>
     */
    private function leadRow(LeadAdLead $lead, array $accounts, array $forms): array
    {
        return [
            'id' => $lead->id,
            'status' => $lead->status->value,
            'reasonCode' => $lead->reason_code,
            'reason' => $lead->reason,
            'accountId' => $lead->account_id,
            'accountName' => $accounts[$lead->account_id] ?? null,
            'formId' => $lead->form_id,
            'formName' => $lead->form_id === null ? null : ($forms[$lead->form_id] ?? null),
            'externalFormId' => $lead->external_form_id,
            'adId' => $lead->ad_id,
            'campaignRef' => $lead->campaign_ref,
            'origin' => $lead->origin,
            'attempts' => $lead->attempts,
            'receivedAt' => $lead->received_at->toIso8601String(),
            'processedAt' => $lead->processed_at?->toIso8601String(),
            'nextAttemptAt' => $lead->next_attempt_at?->toIso8601String(),
            'crmContactId' => $lead->crm_contact_id,
            'crmLeadId' => $lead->crm_lead_id,
            'canRetry' => in_array($lead->status, [LeadStatus::Failed, LeadStatus::Skipped, LeadStatus::WaitingForCrm, LeadStatus::WaitingForAccount, LeadStatus::Received], true),
        ];
    }

    /**
     * @param  array{received: int, processed: int, failed: int, waiting: int, skipped: int}  $counts
     * @return array<string, mixed>
     */
    private function formRow(LeadForm $form, array $counts): array
    {
        $described = $this->setup->describe($form);

        return [
            'id' => $form->id,
            'externalId' => $form->external_id,
            'name' => $form->name,
            'status' => $form->status,
            'isEnabled' => $form->is_enabled,
            'fetchedAt' => $form->fetched_at?->toIso8601String(),
            'lastLeadAt' => $form->last_lead_at?->toIso8601String(),
            'questions' => $form->questions ?? [],
            'mapping' => array_map(static fn (array $choice): array => ['target' => $choice['target'], 'answerKey' => $choice['answer_key']], $described['mapping']),
            'preview' => $described['preview'],
            'warnings' => $described['warnings'],
            'counts' => $counts,
        ];
    }

    /** @return array{received: int, processed: int, failed: int, waiting: int, skipped: int} */
    private function emptyCounts(): array
    {
        return ['received' => 0, 'processed' => 0, 'failed' => 0, 'waiting' => 0, 'skipped' => 0];
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, array{received: int, processed: int, failed: int, waiting: int, skipped: int}>
     */
    private function counts(array $accountIds): array
    {
        return $this->grouped('account_id', $accountIds);
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, array{received: int, processed: int, failed: int, waiting: int, skipped: int}>
     */
    private function formCounts(array $accountIds): array
    {
        return $this->grouped('form_id', $accountIds);
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, array{received: int, processed: int, failed: int, waiting: int, skipped: int}>
     */
    private function grouped(string $column, array $accountIds): array
    {
        $rows = LeadAdLead::query()
            ->whereIn('account_id', $accountIds)
            ->whereNotNull($column)
            ->select($column, 'status', DB::raw('count(*) as total'))
            ->groupBy($column, 'status')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = (string) $row->getAttribute($column);
            $result[$key] ??= $this->emptyCounts();
            $total = (int) $row->getAttribute('total');
            $status = $row->status;

            $result[$key]['received'] += $total;

            match ($status) {
                LeadStatus::Captured, LeadStatus::Duplicate => $result[$key]['processed'] += $total,
                LeadStatus::Failed => $result[$key]['failed'] += $total,
                LeadStatus::Skipped => $result[$key]['skipped'] += $total,
                default => $result[$key]['waiting'] += $total,
            };
        }

        return $result;
    }

    /**
     * The last 14 days per Page in the workspace's timezone.
     *
     * @param  list<string>  $accountIds
     * @return array<string, list<array{date: string, received: int, processed: int, failed: int}>>
     */
    private function history(array $accountIds): array
    {
        $timezone = $this->tenants->require()->timezone ?: (string) config('app.timezone', 'UTC');
        $from = now($timezone)->startOfDay()->subDays(13);
        $offset = (int) $from->utcOffset() * 60;

        $rows = LeadAdLead::query()
            ->whereIn('account_id', $accountIds)
            ->where('received_at', '>=', $from->clone()->utc())
            ->select('account_id', 'status', DB::raw('DATE(DATE_ADD(received_at, INTERVAL '.$offset.' SECOND)) as day'), DB::raw('count(*) as total'))
            ->groupBy('account_id', 'status', 'day')
            ->get();

        $days = [];

        for ($i = 0; $i < 14; $i++) {
            $days[] = $from->clone()->addDays($i)->format('Y-m-d');
        }

        $result = [];

        foreach ($accountIds as $id) {
            $result[$id] = array_map(static fn (string $day): array => ['date' => $day, 'received' => 0, 'processed' => 0, 'failed' => 0], $days);
        }

        foreach ($rows as $row) {
            $account = (string) $row->account_id;
            $index = array_search((string) $row->getAttribute('day'), $days, true);

            if ($index === false || ! isset($result[$account])) {
                continue;
            }

            $total = (int) $row->getAttribute('total');
            $status = $row->status;
            $result[$account][$index]['received'] += $total;

            if ($status->isFinal()) {
                $result[$account][$index]['processed'] += $total;
            } elseif ($status === LeadStatus::Failed) {
                $result[$account][$index]['failed'] += $total;
            }
        }

        return $result;
    }
}
