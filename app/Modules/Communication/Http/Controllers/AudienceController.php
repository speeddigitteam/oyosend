<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Actions\SuppressionManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Http\Requests\ConsentRequest;
use App\Modules\Communication\Http\Requests\SuppressionRequest;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Suppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Who may be marketed to (consent) and who must not be messaged at all (suppressions). */
final class AudienceController
{
    public const string VIEW = 'communication.audience.view';

    public const string CONSENT = 'communication.consent.manage';

    public const string SUPPRESSIONS = 'communication.suppressions.manage';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CommunicationControllerSupport $support,
        private readonly ConsentManager $consents,
        private readonly SuppressionManager $suppressions,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ConsentStatus::class)],
            'channel' => ['nullable', Rule::enum(Channel::class)],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $visible = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->select('contacts.id');

        $consents = Consent::query()
            ->with('contact:id,first_name,last_name,email,phone')
            ->whereIn('contact_id', $visible)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['channel']), fn ($query) => $query->where('channel', $filters['channel']))
            ->when($search !== '', fn ($query) => $query->whereHas('contact', fn ($contact) => $contact
                ->where('first_name', 'like', Like::contains($search))
                ->orWhere('last_name', 'like', Like::contains($search))
                ->orWhere('email', 'like', Like::contains($search))))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'consents_page')
            ->withQueryString()
            ->through(fn (Consent $consent): array => [
                'id' => $consent->id,
                'contact' => $consent->contact === null ? null : ['id' => $consent->contact->id, 'name' => $consent->contact->fullName()],
                'channel' => $consent->channel->value,
                'purpose' => $consent->purpose->value,
                'status' => $consent->status->value,
                'method' => $consent->method,
                'evidence' => $consent->evidence,
                'recordedAt' => $consent->recorded_at->toIso8601String(),
            ]);

        $suppressions = Suppression::query()
            ->when(isset($filters['channel']), fn ($query) => $query->where('channel', $filters['channel']))
            ->when($search !== '', fn ($query) => $query->where('address', 'like', Like::contains($search)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'suppressions_page')
            ->withQueryString()
            ->through(fn (Suppression $suppression): array => [
                'id' => $suppression->id,
                'channel' => $suppression->channel->value,
                'address' => $suppression->address,
                'scope' => $suppression->scope->value,
                'reason' => $suppression->reason->value,
                'note' => $suppression->note,
                'contactId' => $suppression->contact_id,
                'removable' => $this->suppressions->isLiftable($suppression),
                'createdAt' => $suppression->created_at->toIso8601String(),
            ]);

        return Inertia::render('Modules/Communication/Pages/Audience/Index', [
            'consents' => $consents,
            'suppressions' => $suppressions,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? null, 'channel' => $filters['channel'] ?? null],
            'contacts' => $actor->can(self::CONSENT) ? $this->support->contactOptions($actor) : [],
            'methods' => array_values(array_diff(ConsentManager::METHODS, ['unsubscribe_link', 'complaint'])),
            'channels' => Channel::values(),
            'scopes' => array_map(static fn (SuppressionScope $scope): string => $scope->value, SuppressionScope::cases()),
            'canManageConsent' => $actor->can(self::CONSENT),
            'canManageSuppressions' => $actor->can(self::SUPPRESSIONS),
        ]);
    }

    public function storeConsent(ConsentRequest $request, Contact $contact): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ContactController::VIEW, $contact);
        $input = $request->validated();

        $this->consents->record(
            $contact,
            Channel::from($input['channel']),
            ConsentStatus::from($input['status']),
            $input['method'],
            $input['evidence'] ?? null,
            $this->support->membership($actor)->id,
        );
        Toast::success(__('Consent recorded.'));

        return back();
    }

    public function storeSuppression(SuppressionRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();

        $this->suppressions->suppress(
            Channel::from($input['channel']),
            $input['address'],
            SuppressionScope::from($input['scope']),
            SuppressionReason::Manual,
            null,
            $input['note'] ?? null,
            $this->support->membership($actor)->id,
        );
        Toast::success(__('Address added to the suppression list.'));

        return back();
    }

    public function destroySuppression(Suppression $suppression): RedirectResponse
    {
        $this->suppressions->lift($suppression);
        Toast::success(__('Address removed from the suppression list.'));

        return back();
    }
}
