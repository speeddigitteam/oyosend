<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\CampaignReports;
use App\Modules\EmailMarketing\Actions\TestSender;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Template;
use App\Modules\EmailMarketing\Support\CsvSafe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CampaignController
{
    public const string VIEW = 'email_marketing.campaigns.view';

    public const string EDIT = 'email_marketing.campaigns.edit';

    public const string DELETE = 'email_marketing.campaigns.delete';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CampaignManager $campaigns,
        private readonly CampaignReports $reports,
        private readonly TestSender $tests,
        private readonly EmailMarketingSupport $support,
        private readonly EmailMarketingPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
            'list_id' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $this->records->constrain(Campaign::query(), $actor, self::VIEW)
            ->with(['list', 'segment', 'owner.user'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['list_id'] ?? null, fn ($query, string $id) => $query->where('list_id', $id))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(function ($match) use ($term): void {
                $match->where('name', 'like', Like::contains($term))->orWhere('subject', 'like', Like::contains($term));
            }))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $counts = $this->reports->statusCounts($page->getCollection()->pluck('id')->all());

        return Inertia::render('Modules/EmailMarketing/Pages/Campaigns/Index', [
            'campaigns' => $page->through(fn (Campaign $campaign): array => $this->presenter->campaignSummary($campaign, $counts[$campaign->id] ?? [])),
            'filters' => ['status' => $filters['status'] ?? null, 'list_id' => $filters['list_id'] ?? null, 'q' => $filters['q'] ?? null],
            'statuses' => array_map(static fn (CampaignStatus $status): string => $status->value, CampaignStatus::cases()),
            'lists' => $this->support->listOptions(false),
            'canCreate' => $actor->can('email_marketing.campaigns.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $template = null;

        if (is_string($request->query('template')) && strlen((string) $request->query('template')) === 26) {
            $template = Template::query()->find((string) $request->query('template'));
        }

        return Inertia::render('Modules/EmailMarketing/Pages/Campaigns/Form', [
            'campaign' => null,
            'form' => $this->formOptions(),
            'template' => $template === null ? null : $this->presenter->template($template),
            'canSend' => $actor->can('email_marketing.campaigns.send'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $campaign = $this->campaigns->create($this->input($request), $this->support->membership($actor));
        Toast::success(__('Campaign saved as a draft.'));

        return redirect()->route('email_marketing.campaigns.show', ['campaign' => $campaign->id]);
    }

    public function show(Request $request, string $campaign): Response
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::VIEW);

        // A recipient is a person's name and address: each one only for someone who may see that contact.
        $visible = $this->visibleContacts($actor);
        $hidden = Delivery::query()->where('campaign_id', $record->id)->count()
            - Delivery::query()->where('campaign_id', $record->id)->tap($visible)->count();

        $deliveries = Delivery::query()
            ->with('contact')
            ->where('campaign_id', $record->id)
            ->tap($visible)
            ->when($request->query('delivery_status') !== null && is_string($request->query('delivery_status')), fn ($query) => $query->where('status', (string) $request->query('delivery_status')))
            ->orderBy('id')
            ->paginate(25, pageName: 'deliveries_page')
            ->withQueryString()
            ->through(fn (Delivery $delivery): array => $this->presenter->delivery($delivery));

        return Inertia::render('Modules/EmailMarketing/Pages/Campaigns/Show', [
            'campaign' => $this->presenter->campaignDetail($record, $actor),
            'deliveries' => $deliveries,
            'hiddenDeliveries' => max(0, $hidden),
            'deliveryStatus' => is_string($request->query('delivery_status')) ? $request->query('delivery_status') : null,
            'members' => $actor->can('email_marketing.campaigns.send') ? $this->support->memberOptions() : [],
            'maxTestRecipients' => (int) config('email_marketing.test_send_max_recipients', 5),
            'timezone' => $this->support->timezone(),
        ]);
    }

    public function edit(Request $request, string $campaign): Response|RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::EDIT);

        if (! $record->status->isEditable()) {
            Toast::warning(__('A campaign that has started can no longer be edited.'));

            return redirect()->route('email_marketing.campaigns.show', ['campaign' => $record->id]);
        }

        return Inertia::render('Modules/EmailMarketing/Pages/Campaigns/Form', [
            'campaign' => $this->presenter->campaignDetail($record, $actor),
            'form' => $this->formOptions(),
            'template' => null,
            'canSend' => $actor->can('email_marketing.campaigns.send'),
        ]);
    }

    public function update(Request $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::EDIT);
        $this->campaigns->update($record, $this->input($request));
        Toast::success(__('Campaign saved.'));

        return redirect()->route('email_marketing.campaigns.show', ['campaign' => $record->id]);
    }

    public function destroy(Request $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::DELETE);
        $this->campaigns->delete($record);
        Toast::success(__('Campaign deleted.'));

        return redirect()->route('email_marketing.campaigns.index');
    }

    public function duplicate(Request $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::VIEW);
        $copy = $this->campaigns->duplicate($record, $this->support->membership($actor));
        Toast::success(__('Campaign copied as a draft.'));

        return redirect()->route('email_marketing.campaigns.edit', ['campaign' => $copy->id]);
    }

    public function schedule(Request $request, string $campaign): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::VIEW);
        $data = $request->validate(['scheduled_for' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        $scheduled = $this->campaigns->schedule($record, $this->support->utc($data['scheduled_for'] ?? null));

        Toast::success($scheduled->scheduled_for !== null && $scheduled->scheduled_for->isFuture()
            ? __('Campaign scheduled.')
            : __('Campaign is going out now.'));

        return redirect()->route('email_marketing.campaigns.show', ['campaign' => $record->id]);
    }

    public function unschedule(Request $request, string $campaign): RedirectResponse
    {
        $record = $this->support->campaign($this->support->actor($request), $campaign, self::VIEW);
        $this->campaigns->unschedule($record);
        Toast::success(__('Campaign taken back to draft.'));

        return back();
    }

    public function pause(Request $request, string $campaign): RedirectResponse
    {
        $record = $this->support->campaign($this->support->actor($request), $campaign, self::VIEW);
        $this->campaigns->pause($record);
        Toast::success(__('Campaign paused.'));

        return back();
    }

    public function resume(Request $request, string $campaign): RedirectResponse
    {
        $record = $this->support->campaign($this->support->actor($request), $campaign, self::VIEW);
        $this->campaigns->resume($record);
        Toast::success(__('Campaign resumed.'));

        return back();
    }

    public function cancel(Request $request, string $campaign): RedirectResponse
    {
        $record = $this->support->campaign($this->support->actor($request), $campaign, self::VIEW);
        $this->campaigns->cancel($record);
        Toast::success(__('Campaign cancelled.'));

        return back();
    }

    public function test(Request $request, string $campaign): RedirectResponse
    {
        $record = $this->support->campaign($this->support->actor($request), $campaign, self::VIEW);
        $data = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:'.(int) config('email_marketing.test_send_max_recipients', 5)],
            'recipients.*' => ['required', 'string', 'size:26'],
        ]);

        $results = $this->tests->send($record, array_values($data['recipients']));
        $sent = count(array_filter($results, static fn (array $result): bool => $result['outcome'] === 'sent'));

        if ($sent === count($results)) {
            Toast::success(trans_choice(':count test email sent.|:count test emails sent.', $sent, ['count' => $sent]));
        } elseif ($sent > 0) {
            Toast::warning(__(':sent of :total test emails sent. The others are not contacts with email consent.', ['sent' => $sent, 'total' => count($results)]));
        } else {
            Toast::error(__('No test email was sent. A member must be a contact with email consent to receive one; use the preview instead.'));
        }

        return back();
    }

    public function export(Request $request, string $campaign): StreamedResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->campaign($actor, $campaign, self::VIEW);
        $visible = $this->visibleContacts($actor);

        $rows = (function () use ($record, $visible): \Generator {
            foreach (Delivery::query()->with('contact')->where('campaign_id', $record->id)->tap($visible)->orderBy('id')->lazyById(500) as $delivery) {
                yield [
                    $delivery->contact?->email, $delivery->variant, $delivery->status->value, $delivery->reason,
                    $delivery->sent_at?->toIso8601String(), $delivery->clicked_at?->toIso8601String(), $delivery->click_count,
                    $delivery->viewed_at?->toIso8601String(),
                ];
            }
        })();

        $csv = CsvSafe::render(['email', 'variant', 'status', 'reason', 'sent_at', 'first_click_at', 'clicks', 'first_view_at'], $rows);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'campaign-deliveries.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Narrows deliveries to those the member may see: contacts inside their `core.contacts.view` scope, plus
     * history of contacts that no longer exist (shown as "Deleted contact", with no identity).
     *
     * @return \Closure(Builder<Delivery>): void
     */
    private function visibleContacts(User $actor): \Closure
    {
        $visible = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')->select('id');

        return static function (Builder $query) use ($visible): void {
            $query->where(static fn (Builder $match) => $match
                ->whereIn('contact_id', $visible)
                ->orWhereNotIn('contact_id', Contact::query()->select('id')));
        };
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'blocks' => ['required', 'array', 'min:1', 'max:50'],
            'blocks.*' => ['array'],
            'list_id' => ['required', 'string', 'size:26'],
            'segment_id' => ['nullable', 'string', 'size:26'],
            'template_id' => ['nullable', 'string', 'size:26'],
            'track_clicks' => ['boolean'],
            'ab_enabled' => ['boolean'],
            'ab_subject_b' => ['nullable', 'string', 'max:200'],
            'ab_test_percent' => ['nullable', 'integer', 'between:10,100'],
            'ab_wait_hours' => ['nullable', 'integer', 'between:1,72'],
            'ab_metric' => ['nullable', Rule::in(['clicks', 'views'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'lists' => $this->support->listOptions(),
            'templates' => $this->support->templateOptions(),
            'editor' => $this->support->editorOptions(),
            'timezone' => $this->support->timezone(),
        ];
    }
}
