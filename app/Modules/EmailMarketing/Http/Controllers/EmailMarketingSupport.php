<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\EmailMarketing\Actions\EmailMarketingSettings;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Template;
use App\Modules\EmailMarketing\Support\Variables;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Shared helpers of the Email Marketing controllers: the actor, scoped lookups and form option lists. */
final class EmailMarketingSupport
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly EmailMarketingSettings $settings,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /** A campaign the actor may act on with `$permission`; 404 outside their record scope. */
    public function campaign(User $actor, string $id, string $permission): Campaign
    {
        $campaign = Campaign::query()->findOrFail($id);
        $this->records->authorize($actor, $permission, $campaign);

        return $campaign;
    }

    /** Local date-time of the workspace from a form, as UTC. */
    public function utc(?string $local): ?CarbonImmutable
    {
        return $local === null || $local === '' ? null : $this->settings->toUtc($local);
    }

    public function timezone(): string
    {
        return $this->settings->timezone();
    }

    /** @return list<array{id: string, name: string, status: string, segments: list<array{id: string, name: string}>}> */
    public function listOptions(bool $activeOnly = true): array
    {
        return MailingList::query()
            ->with('segments')
            ->when($activeOnly, fn ($query) => $query->where('status', ListStatus::Active->value))
            ->orderBy('name')
            ->get()
            ->map(fn (MailingList $list): array => [
                'id' => $list->id,
                'name' => $list->name,
                'status' => $list->status->value,
                'segments' => $list->segments->map(fn ($segment): array => ['id' => $segment->id, 'name' => $segment->name])->values()->all(),
            ])
            ->all();
    }

    /** @return list<array{id: string, name: string, subject: string|null, preheader: string|null}> */
    public function templateOptions(): array
    {
        return Template::query()->orderBy('name')->get()
            ->map(fn (Template $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'subject' => $template->subject,
                'preheader' => $template->preheader,
            ])->all();
    }

    /** @return list<array{id: string, name: string, email: string}> */
    public function memberOptions(): array
    {
        return TenantMembership::query()
            ->with('user')
            ->where('status', MembershipStatus::Active)
            ->get()
            ->map(fn (TenantMembership $member): array => ['id' => $member->id, 'name' => $member->user->name, 'email' => $member->user->email])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function editorOptions(): array
    {
        return [
            'variables' => Variables::ALLOWED,
            'subjectVariables' => array_values(array_diff(Variables::ALLOWED, [Variables::UNSUBSCRIBE])),
            'maxBlocks' => 50,
            'maxText' => 5000,
        ];
    }
}
