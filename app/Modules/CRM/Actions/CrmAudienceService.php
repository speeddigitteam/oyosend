<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Data\AudienceSegment;
use App\Core\Crm\Data\AudienceSegmentKind;
use App\Core\Crm\Data\ContactPage;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\Tag;
use App\Modules\CRM\Models\Taggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The CRM side of {@see CrmAudience} (ADR 0023): lead status, deal stage, tag, saved segment and customer
 * lifecycle groups as pages of shared contact ids.
 *
 * Everything is read through tenant-scoped models. Saved segments run through {@see SegmentQuery}, which only
 * accepts declared filters. With a viewer, each source is narrowed with {@see RecordAccess} by the permission
 * that governs the record it comes from, so a member who may only see their own leads gets the contacts of
 * their own leads.
 */
final class CrmAudienceService implements CrmAudience
{
    private const string MODULE = 'crm';

    private const int LIST_CAP = 200;

    private const string LEADS = 'crm.leads.view';

    private const string DEALS = 'crm.deals.view';

    private const string ACTIVITIES = 'crm.activities.view';

    private const string CUSTOMERS = 'crm.customers.view';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly RecordAccess $records,
        private readonly SegmentQuery $segmentQuery,
    ) {}

    public function segments(?TenantMembership $viewer = null): array
    {
        if (! $this->available() || ! $this->viewerUsable($viewer)) {
            return [];
        }

        $segments = [];

        foreach (LeadStatus::cases() as $status) {
            $id = 'lead_status:'.$status->value;
            $segments[] = new AudienceSegment($id, __('Leads').': '.ucfirst($status->value), AudienceSegmentKind::LeadStatus, $this->size($id, $viewer));
        }

        $stages = PipelineStage::query()
            ->whereIn('pipeline_id', Pipeline::query()->where('is_active', true)->select('id'))
            ->with('pipeline:id,name')
            ->orderBy('pipeline_id')->orderBy('position')->limit(self::LIST_CAP)->get();

        foreach ($stages as $stage) {
            $id = 'deal_stage:'.$stage->id;
            $segments[] = new AudienceSegment($id, $stage->pipeline->name.': '.$stage->name, AudienceSegmentKind::DealStage, $this->size($id, $viewer));
        }

        foreach (Tag::query()->orderBy('name')->limit(self::LIST_CAP)->get() as $tag) {
            $id = 'tag:'.$tag->id;
            $segments[] = new AudienceSegment($id, $tag->name, AudienceSegmentKind::Tag, $this->size($id, $viewer));
        }

        foreach (Segment::query()->where('is_active', true)->orderBy('name')->limit(self::LIST_CAP)->get() as $saved) {
            $id = 'saved:'.$saved->id;
            $segments[] = new AudienceSegment($id, $saved->name, AudienceSegmentKind::SavedSegment, $this->size($id, $viewer));
        }

        foreach (CustomerLifecycleStage::cases() as $stage) {
            $id = 'lifecycle:'.$stage->value;
            $segments[] = new AudienceSegment($id, __('Customers').': '.ucfirst($stage->value), AudienceSegmentKind::CustomerLifecycle, $this->size($id, $viewer));
        }

        return $segments;
    }

    public function contactIds(string $segmentId, int $limit = 500, ?string $afterId = null, ?TenantMembership $viewer = null): ContactPage
    {
        if (! $this->available() || ! $this->viewerUsable($viewer)) {
            return ContactPage::empty();
        }

        $query = $this->contacts($segmentId, $viewer);

        if ($query === null) {
            return ContactPage::empty();
        }

        $limit = max(1, min($limit, self::MAX_PAGE));

        $ids = $query
            ->when($afterId !== null && $afterId !== '', fn (Builder $builder) => $builder->where('contacts.id', '>', $afterId))
            ->orderBy('contacts.id')
            ->limit($limit + 1)
            ->pluck('contacts.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $hasMore = count($ids) > $limit;
        $ids = array_values(array_slice($ids, 0, $limit));

        return new ContactPage($ids, $hasMore && $ids !== [] ? $ids[count($ids) - 1] : null);
    }

    private function available(): bool
    {
        return $this->entitlements->allows($this->context->require(), self::MODULE);
    }

    /** A viewer must be an active handle of the current workspace; anything else sees nothing. */
    private function viewerUsable(?TenantMembership $viewer): bool
    {
        return $viewer === null || ($viewer->tenant_id === $this->context->requireId() && $this->userOf($viewer) !== null);
    }

    private function userOf(TenantMembership $viewer): ?User
    {
        if ($viewer->status !== MembershipStatus::Active) {
            return null;
        }

        return $viewer->relationLoaded('user') ? $viewer->user : User::query()->find($viewer->user_id);
    }

    private function size(string $segmentId, ?TenantMembership $viewer): int
    {
        $query = $this->contacts($segmentId, $viewer);

        return $query === null ? 0 : $query->count();
    }

    /** @return Builder<Contact>|null null for an id this workspace does not know */
    private function contacts(string $segmentId, ?TenantMembership $viewer): ?Builder
    {
        [$kind, $key] = array_pad(explode(':', $segmentId, 2), 2, '');

        if ($key === '' || strlen($key) > 40) {
            return null;
        }

        /** @var list<Builder<*>>|null $sources builders that select contact ids as `id` */
        $sources = match ($kind) {
            'lead_status' => LeadStatus::tryFrom($key) === null ? null : [
                $this->viaContactId(Lead::query()->where('status', $key), self::LEADS, $viewer),
            ],
            'deal_stage' => PipelineStage::query()->whereKey($key)->exists() ? [
                $this->viaContactId(Deal::query()->where('stage_id', $key), self::DEALS, $viewer),
            ] : null,
            'tag' => $this->tagSources($key, $viewer),
            'saved' => $this->savedSources($key, $viewer),
            'lifecycle' => CustomerLifecycleStage::tryFrom($key) === null ? null : [
                $this->viaContactId(CustomerProfile::query()->where('stage', $key), self::CUSTOMERS, $viewer),
            ],
            default => null,
        };

        if ($sources === null) {
            return null;
        }

        return Contact::query()->where(function (Builder $match) use ($sources): void {
            $match->whereRaw('1 = 0');

            foreach ($sources as $source) {
                $match->orWhereIn('contacts.id', $source);
            }
        });
    }

    /**
     * @return list<Builder<*>>|null
     */
    private function tagSources(string $tagId, ?TenantMembership $viewer): ?array
    {
        if (! Tag::query()->whereKey($tagId)->exists()) {
            return null;
        }

        $tagged = static fn (CrmEntityType $type) => Taggable::query()
            ->where('tag_id', $tagId)->where('entity_type', $type->value)->select('entity_id');

        return [
            $this->viaContactId(Lead::query()->whereIn('id', $tagged(CrmEntityType::Lead)), self::LEADS, $viewer),
            $this->viaContactId(Deal::query()->whereIn('id', $tagged(CrmEntityType::Deal)), self::DEALS, $viewer),
            $this->contactSelect(Contact::query()->whereIn('id', $tagged(CrmEntityType::Contact)), $viewer),
            $this->contactSelect(Contact::query()->whereIn('company_id', $tagged(CrmEntityType::Company)), $viewer),
        ];
    }

    /**
     * @return list<Builder<*>>|null
     */
    private function savedSources(string $segmentId, ?TenantMembership $viewer): ?array
    {
        $segment = Segment::query()->where('is_active', true)->find($segmentId);

        if ($segment === null) {
            return null;
        }

        try {
            return match ($segment->entity_type) {
                CrmEntityType::Lead => [$this->viaContactId($this->segmentQuery->apply(Lead::query(), CrmEntityType::Lead, $this->filters($segment)), self::LEADS, $viewer)],
                CrmEntityType::Deal => [$this->viaContactId($this->segmentQuery->apply(Deal::query(), CrmEntityType::Deal, $this->filters($segment)), self::DEALS, $viewer)],
                CrmEntityType::Activity => [$this->viaContactId($this->segmentQuery->apply(Activity::query(), CrmEntityType::Activity, $this->filters($segment)), self::ACTIVITIES, $viewer)],
                CrmEntityType::Contact => [$this->contactSelect($this->segmentQuery->apply(Contact::query(), CrmEntityType::Contact, $this->filters($segment)), $viewer)],
                CrmEntityType::Company => [$this->contactSelect(
                    Contact::query()->whereIn('company_id', $this->segmentQuery->apply(Company::query(), CrmEntityType::Company, $this->filters($segment))->select('id')),
                    $viewer,
                )],
            };
        } catch (ValidationException) {
            // A stored segment with a filter the engine no longer allows selects nobody rather than everybody.
            return [Contact::query()->whereRaw('1 = 0')->select('contacts.id')];
        }
    }

    /** @return array<string, scalar|null> */
    private function filters(Segment $segment): array
    {
        return array_filter($segment->filters, static fn (mixed $value): bool => $value === null || is_scalar($value));
    }

    /**
     * A record query of a model with a nullable `contact_id`, narrowed by the viewer's scope, as contact ids.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function viaContactId(Builder $query, string $permission, ?TenantMembership $viewer): Builder
    {
        $user = $viewer === null ? null : $this->userOf($viewer);

        if ($user !== null) {
            $this->records->constrain($query, $user, $permission);
        }

        return $query->whereNotNull('contact_id')->select('contact_id');
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function contactSelect(Builder $query, ?TenantMembership $viewer): Builder
    {
        $user = $viewer === null ? null : $this->userOf($viewer);

        if ($user !== null) {
            $this->records->constrain($query, $user, ContactController::VIEW);
        }

        return $query->select('contacts.id');
    }
}
