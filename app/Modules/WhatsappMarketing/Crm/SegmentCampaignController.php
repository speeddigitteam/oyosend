<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Crm;

use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\CampaignManager;
use App\Modules\WhatsappMarketing\Actions\ListManager;
use App\Modules\WhatsappMarketing\Actions\VariableResolver;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lets a campaign target a CRM segment (lead status, deal stage, tag, saved segment, customer lifecycle) instead
 * of a hand-built list. The segment is resolved through the core `CrmAudience` contract for the launching member,
 * so only the contacts in that member's CRM record scope are reached. The result is frozen into an ordinary
 * contact list and the campaign is created as a normal draft: the existing recipient pipeline, and the platform's
 * consent and suppression checks, are unchanged.
 */
final class SegmentCampaignController
{
    public function __construct(
        private readonly Presenter $presenter,
        private readonly CrmSegments $segments,
        private readonly CampaignManager $campaigns,
        private readonly ListManager $lists,
    ) {}

    /** The CRM segments the member may broadcast to, for the campaign form's audience step. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->presenter->actor($request);
        $membership = $this->presenter->membership($actor);

        return response()->json(['segments' => $this->segments->forMember($membership)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $membership = $this->presenter->membership($actor);

        /** @var array{name: string, template_id: string, segment_id: string, variables?: array<array-key, mixed>} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'template_id' => ['required', 'string', 'size:26'],
            'segment_id' => ['required', 'string', 'max:120'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*.type' => ['required_with:variables', Rule::in(['field', 'text'])],
            'variables.*.field' => ['nullable', 'string', Rule::in(VariableResolver::FIELDS)],
            'variables.*.value' => ['nullable', 'string', 'max:200'],
        ]);

        $segment = $this->segments->find($input['segment_id'], $membership);

        if ($segment === null) {
            throw ValidationException::withMessages(['segment_id' => __('Choose a customer segment.')]);
        }

        // Freeze the segment (scoped to the member) into a new list, then build the campaign on it.
        $listName = Text::clean($segment->label, 90, false).' · '.Str::upper(Str::random(5));
        $list = $this->lists->save(null, ['name' => $listName, 'description' => __('From the CRM segment ":segment".', ['segment' => $segment->label])], $membership->id);
        $added = $this->segments->materialize($list, $segment->id, $membership);

        $campaign = $this->campaigns->save(null, [
            'name' => $input['name'],
            'template_id' => $input['template_id'],
            'list_id' => $list->id,
            'variables' => $input['variables'] ?? [],
        ], $membership);

        Toast::success(__('Campaign :name saved as a draft for :count contacts from ":segment".', [
            'name' => $campaign->name,
            'count' => $added,
            'segment' => $segment->label,
        ]));

        return redirect()->route('whatsapp_marketing.campaigns.show', $campaign);
    }
}
