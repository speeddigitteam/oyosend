<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use App\Modules\EmailMarketing\Support\SegmentRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lists and their saved segments. */
final class ListManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{name: string, description?: string|null}  $attributes */
    public function create(array $attributes): MailingList
    {
        $this->assertFreeName(MailingList::query(), ContentBlocks::line($attributes['name']));

        return MailingList::query()->create([
            'name' => ContentBlocks::line($attributes['name']),
            'description' => $this->nullable($attributes['description'] ?? null),
            'status' => ListStatus::Active,
        ]);
    }

    /** @param  array{name?: string, description?: string|null, status?: string}  $attributes */
    public function update(MailingList $list, array $attributes): MailingList
    {
        if (isset($attributes['name'])) {
            $this->assertFreeName(MailingList::query()->whereKeyNot($list->id), ContentBlocks::line($attributes['name']));
        }

        $list->fill(array_filter([
            'name' => isset($attributes['name']) ? ContentBlocks::line($attributes['name']) : null,
            'status' => isset($attributes['status']) ? ListStatus::from($attributes['status']) : null,
        ], static fn (mixed $value): bool => $value !== null));

        if (array_key_exists('description', $attributes)) {
            $list->description = $this->nullable($attributes['description']);
        }

        $list->save();

        return $list;
    }

    /**
     * Turn the public sign-up form on or off, allow framing it, or replace its link. The link is minted the first
     * time the form is turned on and kept while it is off, so switching it back on restores the published URL;
     * `$rotate` kills the old URL (confirmation links already sent keep working).
     *
     * @throws ValidationException
     */
    public function configureSignup(MailingList $list, bool $enabled, bool $embed, bool $rotate): MailingList
    {
        if ($enabled && $list->status !== ListStatus::Active) {
            throw ValidationException::withMessages(['enabled' => __('An archived list cannot take sign-ups. Restore it first.')]);
        }

        $token = $list->signup_token;

        if ($rotate || ($enabled && $token === null)) {
            $token = bin2hex(random_bytes(16));
        }

        $list->forceFill(['signup_enabled' => $enabled, 'signup_embed' => $enabled && $embed, 'signup_token' => $token])->save();

        $this->audit->record('email_marketing.list.signup_configured', $list, [
            'enabled' => $enabled, 'embed' => $list->signup_embed, 'rotated' => $rotate,
        ], subjectLabel: $list->name);

        return $list;
    }

    /**
     * A list that was ever mailed (or feeds a sequence) is archived instead of deleted, so reports stay whole.
     *
     * @throws ValidationException
     */
    public function delete(MailingList $list): void
    {
        $used = Campaign::query()->where('list_id', $list->id)->exists() || Sequence::query()->where('list_id', $list->id)->exists();

        if ($used) {
            throw ValidationException::withMessages(['list' => __('This list is used by a campaign or a sequence. Archive it instead.')]);
        }

        DB::transaction(function () use ($list): void {
            Subscription::query()->where('list_id', $list->id)->delete();
            $list->delete();
        });
    }

    /**
     * @param  array{name: string, match: string, rules: mixed}  $attributes
     *
     * @throws ValidationException
     */
    public function createSegment(MailingList $list, array $attributes): Segment
    {
        if (Segment::query()->where('list_id', $list->id)->count() >= (int) config('email_marketing.segments_per_list', 25)) {
            throw ValidationException::withMessages(['name' => __('A list can have up to :count segments.', ['count' => (int) config('email_marketing.segments_per_list', 25)])]);
        }

        $this->assertFreeName(Segment::query()->where('list_id', $list->id), ContentBlocks::line($attributes['name']));

        return Segment::query()->create([
            'list_id' => $list->id,
            'name' => ContentBlocks::line($attributes['name']),
            'match' => $attributes['match'] === 'any' ? 'any' : 'all',
            'rules' => SegmentRules::normalize($attributes['rules']),
        ]);
    }

    /** @param  array{name: string, match: string, rules: mixed}  $attributes */
    public function updateSegment(Segment $segment, array $attributes): Segment
    {
        $this->assertFreeName(Segment::query()->where('list_id', $segment->list_id)->whereKeyNot($segment->id), ContentBlocks::line($attributes['name']));

        $segment->fill([
            'name' => ContentBlocks::line($attributes['name']),
            'match' => $attributes['match'] === 'any' ? 'any' : 'all',
            'rules' => SegmentRules::normalize($attributes['rules']),
        ])->save();

        return $segment;
    }

    /** Campaigns that used the segment keep their history; the segment is simply gone (FK is set null). */
    public function deleteSegment(Segment $segment): void
    {
        $segment->delete();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     *
     * @throws ValidationException
     */
    private function assertFreeName(Builder $query, string $name): void
    {
        if ($name === '' || $query->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => __('This name is already used.')]);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $text = ContentBlocks::line($value);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
