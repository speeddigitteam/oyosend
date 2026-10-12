<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\EmailMarketing\Enums\EnrollmentStatus;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Enums\SequenceStatus;
use App\Modules\EmailMarketing\Models\Enrollment;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sequences (drip and welcome series) and their steps. */
final class SequenceManager
{
    public const int MAX_STEPS = 10;

    public const int MAX_DELAY_MINUTES = 129600;

    public function __construct(
        private readonly EmailMarketingSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, list_id: string, steps?: mixed}  $input
     */
    public function create(array $input): Sequence
    {
        return DB::transaction(function () use ($input): Sequence {
            $this->assertFreeName($this->name($input['name']));
            $sequence = Sequence::query()->create([
                'name' => $this->name($input['name']),
                'description' => $this->description($input['description'] ?? null),
                'list_id' => $this->list($input['list_id'])->id,
                'status' => SequenceStatus::Draft,
            ]);

            $this->syncSteps($sequence, $input['steps'] ?? []);

            return $sequence;
        });
    }

    /**
     * @param  array{name: string, description?: string|null, list_id?: string, steps?: mixed}  $input
     */
    public function update(Sequence $sequence, array $input): Sequence
    {
        if ($sequence->status === SequenceStatus::Active) {
            throw ValidationException::withMessages(['sequence' => __('Pause the sequence before changing it.')]);
        }

        return DB::transaction(function () use ($sequence, $input): Sequence {
            $this->assertFreeName($this->name($input['name']), $sequence->id);
            $sequence->fill([
                'name' => $this->name($input['name']),
                'description' => $this->description($input['description'] ?? null),
            ]);

            if (isset($input['list_id']) && $input['list_id'] !== $sequence->list_id) {
                if (Enrollment::query()->where('sequence_id', $sequence->id)->exists()) {
                    throw ValidationException::withMessages(['list_id' => __('The list cannot change once people have been enrolled.')]);
                }

                $sequence->list_id = $this->list($input['list_id'])->id;
            }

            $sequence->save();
            $this->syncSteps($sequence, $input['steps'] ?? []);

            return $sequence;
        });
    }

    public function activate(Sequence $sequence): Sequence
    {
        if ($sequence->status === SequenceStatus::Active) {
            return $sequence;
        }

        if (! $this->settings->hasFooterAddress()) {
            throw ValidationException::withMessages(['sequence' => __('Add your postal address in the settings before sending. It is printed in the footer of every email.')]);
        }

        if (! SequenceStep::query()->where('sequence_id', $sequence->id)->exists()) {
            throw ValidationException::withMessages(['sequence' => __('Add at least one step before activating the sequence.')]);
        }

        if (MailingList::query()->find($sequence->list_id)?->status !== ListStatus::Active) {
            throw ValidationException::withMessages(['sequence' => __('The list of this sequence is archived.')]);
        }

        $sequence->fill(['status' => SequenceStatus::Active, 'activated_at' => $sequence->activated_at ?? CarbonImmutable::now()])->save();
        $this->audit->record('email_marketing.sequence.activated', $sequence);

        return $sequence;
    }

    public function pause(Sequence $sequence): Sequence
    {
        if ($sequence->status === SequenceStatus::Active) {
            $sequence->fill(['status' => SequenceStatus::Paused])->save();
            $this->audit->record('email_marketing.sequence.paused', $sequence);
        }

        return $sequence;
    }

    public function delete(Sequence $sequence): void
    {
        DB::transaction(function () use ($sequence): void {
            Enrollment::query()->where('sequence_id', $sequence->id)->where('status', EnrollmentStatus::Active->value)->update([
                'status' => EnrollmentStatus::Stopped->value, 'stopped_reason' => 'sequence_deleted', 'next_due_at' => null,
            ]);
            $sequence->delete();
        });
    }

    /**
     * Replace the steps with the given ones, keeping the ids of steps that still exist.
     *
     * @throws ValidationException
     */
    private function syncSteps(Sequence $sequence, mixed $steps): void
    {
        if (! is_array($steps) || ! array_is_list($steps) || count($steps) > self::MAX_STEPS) {
            throw ValidationException::withMessages(['steps' => __('A sequence has up to :count steps.', ['count' => self::MAX_STEPS])]);
        }

        $existing = SequenceStep::query()->where('sequence_id', $sequence->id)->get()->keyBy('id');
        $keep = [];
        $clean = [];

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                throw ValidationException::withMessages(['steps.'.$index => __('This step is not valid.')]);
            }

            $delay = (int) ($step['delay_minutes'] ?? 0);

            if ($delay < 0 || $delay > self::MAX_DELAY_MINUTES) {
                throw ValidationException::withMessages(['steps.'.$index.'.delay_minutes' => __('Wait between 0 minutes and 90 days.')]);
            }

            $subject = (string) ContentBlocks::subject($step['subject'] ?? '', 'steps.'.$index.'.subject');
            $blocks = ContentBlocks::normalize($step['blocks'] ?? null, 'steps.'.$index.'.blocks');

            $preheader = ContentBlocks::line($step['preheader'] ?? '');
            $id = isset($step['id']) && is_string($step['id']) && $existing->has($step['id']) ? $step['id'] : null;

            $clean[] = [
                'id' => $id,
                'position' => $index + 1,
                'delay_minutes' => $delay,
                'subject' => $subject,
                'preheader' => $preheader === '' ? null : mb_substr($preheader, 0, 200),
                'blocks' => $blocks,
            ];

            if ($id !== null) {
                $keep[] = $id;
            }
        }

        // Two passes: moving positions around must not collide on the (sequence, position) unique index.
        SequenceStep::query()->where('sequence_id', $sequence->id)->whereNotIn('id', $keep)->delete();
        SequenceStep::query()->where('sequence_id', $sequence->id)->update(['position' => DB::raw('position + 1000')]);

        foreach ($clean as $step) {
            $attributes = ['position' => $step['position'], 'delay_minutes' => $step['delay_minutes'], 'subject' => $step['subject'], 'preheader' => $step['preheader'], 'blocks' => $step['blocks']];

            if ($step['id'] !== null) {
                $existing[$step['id']]->forceFill($attributes)->save();
            } else {
                SequenceStep::query()->create(['sequence_id' => $sequence->id, ...$attributes]);
            }
        }
    }

    private function assertFreeName(string $name, ?string $ignore = null): void
    {
        $query = Sequence::query()->where('name', $name);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('This name is already used.')]);
        }
    }

    private function list(mixed $id): MailingList
    {
        $list = MailingList::query()->find((string) $id);

        if ($list === null || $list->status !== ListStatus::Active) {
            throw ValidationException::withMessages(['list_id' => __('Choose an active list.')]);
        }

        return $list;
    }

    private function name(mixed $name): string
    {
        $text = ContentBlocks::line($name);

        if ($text === '' || mb_strlen($text) > 150) {
            throw ValidationException::withMessages(['name' => __('A name of up to 150 characters is required.')]);
        }

        return $text;
    }

    private function description(mixed $value): ?string
    {
        $text = ContentBlocks::line($value);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
