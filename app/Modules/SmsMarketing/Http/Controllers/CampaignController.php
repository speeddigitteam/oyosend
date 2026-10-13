<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\SmsMarketing\Actions\AudienceEstimator;
use App\Modules\SmsMarketing\Actions\CampaignManager;
use App\Modules\SmsMarketing\Actions\CampaignStats;
use App\Modules\SmsMarketing\Actions\GroupCsv;
use App\Modules\SmsMarketing\Actions\MessageComposer;
use App\Modules\SmsMarketing\Actions\SmsAnalytics;
use App\Modules\SmsMarketing\Actions\SmsSettings;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use App\Modules\SmsMarketing\Http\Requests\CampaignRequest;
use App\Modules\SmsMarketing\Http\Requests\EstimateRequest;
use App\Modules\SmsMarketing\Http\Requests\LaunchRequest;
use App\Modules\SmsMarketing\Jobs\RunCampaigns;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\Recipient;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CampaignController
{
    public const string VIEW = 'sms_marketing.campaigns.view';

    public const string EDIT = 'sms_marketing.campaigns.edit';

    public const string DELETE = 'sms_marketing.campaigns.delete';

    public const string LAUNCH = 'sms_marketing.campaigns.launch';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CampaignManager $campaigns,
        private readonly SmsSupport $support,
        private readonly SmsPresenter $presenter,
        private readonly SmsAnalytics $analytics,
        private readonly AudienceEstimator $estimator,
        private readonly MessageComposer $composer,
        private readonly ChannelGateway $gateway,
        private readonly SmsSettings $settings,
        private readonly GroupCsv $csv,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $this->records->constrain(Campaign::query(), $actor, self::VIEW)
            ->with('owner.user:id,name')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where('name', 'like', Like::contains($term)))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = CampaignStats::for($page->getCollection()->pluck('id')->all());
        $page->through(fn (Campaign $campaign): array => $this->presenter->campaignRow($campaign, $stats[$campaign->id] ?? CampaignStats::empty()));

        return Inertia::render('Modules/SmsMarketing/Pages/Campaigns/Index', [
            'campaigns' => $page,
            'filters' => ['status' => $filters['status'] ?? null, 'q' => $filters['q'] ?? null],
            'statuses' => CampaignStatus::values(),
            'standing' => $this->analytics->standing(),
            'canCreate' => $actor->can('sms_marketing.campaigns.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/SmsMarketing/Pages/Campaigns/Form', [
            'campaign' => null,
            'form' => $this->formOptions(),
            'canEstimate' => $actor->can('sms_marketing.campaigns.create'),
        ]);
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $campaign = $this->campaigns->create($request->validated(), $request->validated('group_ids'), $this->support->membership($actor));
        Toast::success(__('Campaign saved as a draft.'));

        return redirect()->route('sms_marketing.campaigns.show', ['campaign' => $campaign->id]);
    }

    public function show(Request $request, string $campaign): Response
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::VIEW);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(RecipientStatus::class)]]);

        // A recipient is a contact's name and number: each one only for someone who may see that contact.
        $visible = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')->select('id');
        $total = Recipient::query()->where('campaign_id', $record->id)->count();
        $hidden = $total - Recipient::query()->where('campaign_id', $record->id)->whereIn('contact_id', $visible)->count();

        $recipients = Recipient::query()
            ->where('campaign_id', $record->id)
            ->whereIn('contact_id', $visible)
            ->with('contact:id,first_name,last_name')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Recipient $recipient): array => $this->presenter->recipient($recipient));

        return Inertia::render('Modules/SmsMarketing/Pages/Campaigns/Show', [
            'campaign' => $this->presenter->campaignDetail($record, $actor),
            'recipients' => $recipients,
            'hiddenRecipients' => max(0, $hidden),
            'filters' => ['status' => $filters['status'] ?? null],
            'statuses' => RecipientStatus::values(),
            'sample' => $this->composer->preview($record->body)->toArray(),
            'channel' => $this->channel(),
        ]);
    }

    public function edit(Request $request, string $campaign): Response|RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::EDIT);

        if (! $record->status->isEditable()) {
            Toast::warning(__('Only a draft campaign can be edited.'));

            return redirect()->route('sms_marketing.campaigns.show', ['campaign' => $record->id]);
        }

        return Inertia::render('Modules/SmsMarketing/Pages/Campaigns/Form', [
            'campaign' => $this->presenter->campaignDetail($record, $actor),
            'form' => $this->formOptions(),
            'canEstimate' => $actor->can('sms_marketing.campaigns.create'),
        ]);
    }

    public function update(CampaignRequest $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::EDIT);
        $this->campaigns->update($record, $request->validated(), $request->validated('group_ids'));
        Toast::success(__('Campaign saved.'));

        return redirect()->route('sms_marketing.campaigns.show', ['campaign' => $record->id]);
    }

    public function destroy(Request $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::DELETE);
        $this->campaigns->delete($record);
        Toast::success(__('Campaign deleted.'));

        return redirect()->route('sms_marketing.campaigns.index');
    }

    public function launch(LaunchRequest $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::LAUNCH);
        $at = $this->support->utc($request->validated('scheduled_for'));
        $launched = $this->campaigns->launch($record, $at, $this->support->membership($actor));

        if ($launched->status === CampaignStatus::Sending) {
            RunCampaigns::dispatch();
            Toast::success(__('Campaign started. Messages go out in batches.'));
        } else {
            Toast::success(__('Campaign scheduled.'));
        }

        return redirect()->route('sms_marketing.campaigns.show', ['campaign' => $record->id]);
    }

    public function cancel(Request $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::LAUNCH);
        $this->campaigns->cancel($record);
        Toast::success(__('Campaign cancelled. Messages already handed to the provider are not recalled.'));

        return redirect()->route('sms_marketing.campaigns.show', ['campaign' => $record->id]);
    }

    public function retry(Request $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::LAUNCH);
        $count = $this->campaigns->retryFailed($record);

        if ($count === 0) {
            Toast::warning(__('No failed message can be retried.'));
        } else {
            RunCampaigns::dispatch();
            Toast::success(trans_choice(':count message put back in the queue.|:count messages put back in the queue.', $count, ['count' => $count]));
        }

        return redirect()->route('sms_marketing.campaigns.show', ['campaign' => $record->id]);
    }

    public function export(Request $request, string $campaign): StreamedResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::VIEW);
        $csv = $this->csv->exportRecipients($record->id, $actor);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'sms-campaign-'.substr($record->id, -8).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** What a draft would reach and cost. JSON for the form; nothing is saved. */
    public function estimate(EstimateRequest $request): JsonResponse
    {
        $this->support->actor($request);
        $groupIds = Group::query()->whereIn('id', (array) $request->validated('group_ids', []))->pluck('id')->all();
        $body = trim((string) $request->validated('body'));
        // Throws a validation error (422 as JSON) for an unknown variable, a stray brace or a too long text.
        $sample = $this->composer->assertValid($body, TemplatePurpose::Marketing);

        return response()->json([
            'sample' => $sample->toArray(),
            'audience' => $this->estimator->estimate(array_values($groupIds), $body),
            'suffix' => $this->composer->sampleSuffix(),
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'groups' => Group::query()->withCount('members')->orderBy('name')->get()
                ->map(fn (Group $group): array => $this->presenter->group($group))->all(),
            'templates' => Template::query()->where('is_active', true)->where('purpose', TemplatePurpose::Marketing->value)->orderBy('name')->get(['id', 'name', 'body'])
                ->map(static fn (Template $template): array => ['id' => $template->id, 'name' => $template->name, 'body' => $template->body])->all(),
            'senders' => SenderId::query()->orderBy('label')->get(['id', 'label', 'value'])
                ->map(static fn (SenderId $sender): array => ['id' => $sender->id, 'label' => $sender->label, 'value' => $sender->value])->all(),
            'defaultSenderId' => $this->settings->current()->default_sender_id,
            'optOutWording' => $this->settings->optOutWording(),
            'timezone' => $this->settings->timezone(),
            'limits' => [
                'maxBody' => (int) config('sms_marketing.max_body_length', 1000),
                'maxSegments' => (int) config('sms_marketing.max_segments', 10),
                'maxAudience' => (int) config('sms_marketing.max_audience', 50000),
            ],
            'channel' => $this->channel(),
        ];
    }

    /** @return array{state: string, reason: string|null, testDouble: bool} */
    private function channel(): array
    {
        $availability = $this->gateway->availability(MarketingChannel::Sms);

        return ['state' => $availability->state->value, 'reason' => $availability->reason, 'testDouble' => $availability->testDouble];
    }
}
