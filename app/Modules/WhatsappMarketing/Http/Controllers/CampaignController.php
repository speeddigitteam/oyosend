<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Actions\CampaignManager;
use App\Modules\WhatsappMarketing\Actions\ChannelStatus;
use App\Modules\WhatsappMarketing\Actions\TemplateParameters;
use App\Modules\WhatsappMarketing\Actions\VariableResolver;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Campaigns: a template, a list and a mapping of variables to allow-listed fields. */
final class CampaignController
{
    public function __construct(
        private readonly CampaignManager $campaigns,
        private readonly RecordAccess $records,
        private readonly ChannelStatus $channel,
        private readonly VariableResolver $variables,
        private readonly Presenter $presenter,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->presenter->actor($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(CampaignStatus::class)]]);

        $campaigns = $this->records->constrain(Campaign::query(), $actor, Permissions::CAMPAIGNS_VIEW)
            ->with('owner.user:id,name')
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Campaign $campaign): array => $this->presenter->campaign($campaign));

        return Inertia::render('Modules/WhatsappMarketing/Pages/Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => ['status' => $filters['status'] ?? null],
            'statuses' => CampaignStatus::values(),
            'channel' => $this->channel->describe(),
            'canManage' => $actor->can(Permissions::CAMPAIGNS_MANAGE),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function edit(Request $request, Campaign $campaign): Response
    {
        $actor = $this->presenter->actor($request);
        $this->records->authorize($actor, Permissions::CAMPAIGNS_MANAGE, $campaign);

        return $this->form($request, $campaign);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $campaign = $this->campaigns->save(null, $this->validated($request), $this->presenter->membership($actor));
        Toast::success(__('Campaign :name saved as a draft.', ['name' => $campaign->name]));

        return redirect()->route('whatsapp_marketing.campaigns.show', $campaign);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->records->authorize($actor, Permissions::CAMPAIGNS_MANAGE, $campaign);
        $this->campaigns->save($campaign, $this->validated($request));
        Toast::success(__('Campaign saved.'));

        return redirect()->route('whatsapp_marketing.campaigns.show', $campaign);
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        $actor = $this->presenter->actor($request);
        $this->records->authorize($actor, Permissions::CAMPAIGNS_VIEW, $campaign);
        $campaign->load('owner.user:id,name');

        /** @var array<string, int> $states */
        $states = Recipient::query()->where('campaign_id', $campaign->id)
            ->toBase()->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state')
            ->map(static fn (mixed $count): int => (int) $count)->all();

        /** @var array<string, int> $reasons */
        $reasons = Recipient::query()->where('campaign_id', $campaign->id)->whereNotNull('reason')
            ->toBase()->selectRaw('reason, count(*) as total')->groupBy('reason')->pluck('total', 'reason')
            ->map(static fn (mixed $count): int => (int) $count)->all();

        $filter = $request->query('state');
        $recipients = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->with('contact:id,first_name,last_name,phone')
            ->when(is_string($filter) && RecipientState::tryFrom($filter) !== null, fn ($query) => $query->where('state', $filter))
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Recipient $recipient): array => [
                'id' => $recipient->id,
                'contactId' => $recipient->contact_id,
                'name' => $recipient->contact?->fullName(),
                'phone' => $recipient->contact?->phone,
                'state' => $recipient->state->value,
                'reason' => $recipient->reason,
                'sentAt' => $recipient->sent_at?->toIso8601String(),
                'deliveredAt' => $recipient->delivered_at?->toIso8601String(),
                'readAt' => $recipient->read_at?->toIso8601String(),
            ]);

        $read = $states[RecipientState::Read->value] ?? 0;
        $delivered = ($states[RecipientState::Delivered->value] ?? 0) + $read;
        $sent = ($states[RecipientState::Sent->value] ?? 0) + $delivered;

        return Inertia::render('Modules/WhatsappMarketing/Pages/Campaigns/Show', [
            'campaign' => $this->presenter->campaign($campaign),
            'progress' => [
                'pending' => $states[RecipientState::Pending->value] ?? 0,
                'queued' => $states[RecipientState::Queued->value] ?? 0,
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'failed' => $states[RecipientState::Failed->value] ?? 0,
                'blocked' => $states[RecipientState::Blocked->value] ?? 0,
                'skipped' => $states[RecipientState::Skipped->value] ?? 0,
                'deliveredBps' => $sent === 0 ? null : intdiv($delivered * 10000, $sent),
                'readBps' => $delivered === 0 ? null : intdiv($read * 10000, $delivered),
            ],
            'reasons' => $reasons,
            'recipients' => $recipients,
            'stateFilter' => is_string($filter) ? $filter : null,
            'channel' => $this->channel->describe(),
            'can' => [
                'manage' => $this->records->allows($actor, Permissions::CAMPAIGNS_MANAGE, $campaign),
                'send' => $actor->can(Permissions::CAMPAIGNS_SEND),
            ],
        ]);
    }

    public function send(Request $request, Campaign $campaign): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->records->authorize($actor, Permissions::CAMPAIGNS_VIEW, $campaign);
        $input = $request->validate(['scheduled_at' => ['nullable', 'date']]);

        $at = isset($input['scheduled_at']) ? CarbonImmutable::parse($input['scheduled_at'], $this->context->require()->timezone) : null;
        $campaign = $this->campaigns->schedule($campaign, $at);
        Toast::success($campaign->status === CampaignStatus::Scheduled ? __('Campaign scheduled.') : __('Campaign is sending.'));

        return back();
    }

    public function cancel(Request $request, Campaign $campaign): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->records->authorize($actor, Permissions::CAMPAIGNS_VIEW, $campaign);
        $this->campaigns->cancel($campaign);
        Toast::success(__('Campaign cancelled.'));

        return back();
    }

    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->records->authorize($actor, Permissions::CAMPAIGNS_MANAGE, $campaign);
        $this->campaigns->delete($campaign);
        Toast::success(__('Campaign deleted.'));

        return redirect()->route('whatsapp_marketing.campaigns.index');
    }

    /** What a template would say for a sample contact: the form's live preview. */
    public function preview(Request $request): JsonResponse
    {
        $input = $request->validate([
            'template_id' => ['required', 'string', 'size:26'],
            'variables' => ['nullable', 'array'],
        ]);

        $template = Template::query()->findOrFail($input['template_id']);
        $map = $this->variables->normalizeMap((array) ($input['variables'] ?? []), $template->variable_count);
        $sample = [
            'contact.first_name' => 'Rahim', 'contact.last_name' => 'Uddin', 'contact.full_name' => 'Rahim Uddin',
            'company.name' => 'Acme Ltd', 'workspace.name' => 'Your business', 'agent.name' => 'An agent',
        ];

        return response()->json(['body' => TemplateParameters::render($template->body_text, $this->variables->parameters($map, $sample))]);
    }

    private function form(Request $request, ?Campaign $campaign): Response
    {
        $actor = $this->presenter->actor($request);

        return Inertia::render('Modules/WhatsappMarketing/Pages/Campaigns/Form', [
            'campaign' => $campaign === null ? null : $this->presenter->campaign($campaign),
            'templates' => Template::query()->where('status', TemplateStatus::Approved->value)->orderBy('name')->limit(200)->get()
                ->map(fn (Template $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'language' => $template->language,
                    'category' => $template->category->value,
                    'body' => $template->body_text,
                    'variableCount' => $template->variable_count,
                ])->all(),
            'lists' => ContactList::query()->withCount('members')->orderBy('name')->limit(500)->get()
                ->map(fn (ContactList $list): array => ['id' => $list->id, 'name' => $list->name, 'memberCount' => (int) $list->getAttribute('members_count')])->all(),
            'fields' => VariableResolver::FIELDS,
            'maxRecipients' => max(1, (int) config('whatsapp_marketing.max_recipients', 10000)),
            'canSend' => $actor->can(Permissions::CAMPAIGNS_SEND),
        ]);
    }

    /** @return array{name: string, template_id: string, list_id: string, variables?: array<array-key, mixed>} */
    private function validated(Request $request): array
    {
        /** @var array{name: string, template_id: string, list_id: string, variables?: array<array-key, mixed>} */
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'template_id' => ['required', 'string', 'size:26'],
            'list_id' => ['required', 'string', 'size:26'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*.type' => ['required_with:variables', Rule::in(['field', 'text'])],
            'variables.*.field' => ['nullable', 'string', Rule::in(VariableResolver::FIELDS)],
            'variables.*.value' => ['nullable', 'string', 'max:200'],
        ]);
    }
}
