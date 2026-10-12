<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Data\InteractionEntry;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Communication\Enums\InteractionType;
use App\Modules\Communication\Events\InteractionLogged;
use App\Modules\Communication\Models\Interaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the interaction timeline. It is the implementation of the core {@see InteractionRecorder} contract and
 * also what the module's own screens and automation use.
 */
final class TimelineRecorder implements InteractionRecorder
{
    public function __construct(private readonly CommunicationAccess $access) {}

    public function record(InteractionEntry $entry): bool
    {
        if (! $this->access->entitled()) {
            return false;
        }

        return $this->write($entry) !== null;
    }

    /**
     * Writes an entry, returning it, or null when the subject does not exist or the dedupe key was used.
     * The subject is checked in the active workspace: an id from elsewhere is simply not found.
     */
    public function write(InteractionEntry $entry, ?string $messageId = null, ?string $ownerId = null): ?Interaction
    {
        $contact = $entry->contactId === null ? null : Contact::query()->find($entry->contactId);
        $company = $entry->companyId === null ? null : Company::query()->find($entry->companyId);

        if (($entry->contactId !== null && $contact === null) || ($entry->companyId !== null && $company === null)) {
            return null;
        }

        if ($entry->dedupeKey !== null && Interaction::query()->where('dedupe_key', $entry->dedupeKey)->exists()) {
            return null;
        }

        try {
            $interaction = DB::transaction(function () use ($entry, $contact, $company, $messageId, $ownerId): Interaction {
                $interaction = new Interaction([
                    'contact_id' => $contact?->id,
                    'company_id' => $company->id ?? $contact?->company_id,
                    'message_id' => $messageId,
                    'type' => InteractionType::from($entry->type),
                    'direction' => $entry->direction,
                    'summary' => $entry->summary,
                    'body' => $entry->body,
                    'occurred_at' => $entry->occurredAt ?? CarbonImmutable::now(),
                    'source_module' => $entry->sourceModule,
                    'source_type' => $entry->sourceType,
                    'source_id' => $entry->sourceId,
                    'dedupe_key' => $entry->dedupeKey,
                    'meta' => $entry->meta === [] ? null : $entry->meta,
                ]);

                if ($ownerId !== null) {
                    $owner = TenantMembership::query()->find($ownerId);
                    $interaction->assignOwner($owner);
                }

                $interaction->save();

                return $interaction;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        event(InteractionLogged::from($interaction));

        return $interaction;
    }
}
