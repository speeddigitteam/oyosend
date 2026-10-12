<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Suppression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The suppression list: addresses a workspace must not message. */
final class SuppressionManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Whether a message of this purpose to the (normalized) address is blocked.
     * A marketing message is blocked by either scope, a transactional one only by `all`.
     */
    public function blocks(Channel $channel, string $address, MessagePurpose $purpose): bool
    {
        $scopes = $purpose === MessagePurpose::Marketing
            ? [SuppressionScope::All->value, SuppressionScope::Marketing->value]
            : [SuppressionScope::All->value];

        return Suppression::query()
            ->where('channel', $channel->value)
            ->where('address', $address)
            ->whereIn('scope', $scopes)
            ->exists();
    }

    /** Adds (or keeps) a suppression. Idempotent per channel, address and scope. */
    public function suppress(
        Channel $channel,
        string $address,
        SuppressionScope $scope,
        SuppressionReason $reason,
        ?string $contactId = null,
        ?string $note = null,
        ?string $createdBy = null,
    ): Suppression {
        $normalized = $channel->normalize($address)
            ?? throw ValidationException::withMessages(['address' => __('Enter a valid address for this channel.')]);

        $find = fn (): ?Suppression => Suppression::query()
            ->where('channel', $channel->value)->where('address', $normalized)->where('scope', $scope->value)->first();

        $existing = $find();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($channel, $normalized, $scope, $reason, $contactId, $note, $createdBy): Suppression {
                $suppression = Suppression::query()->create([
                    'channel' => $channel,
                    'address' => $normalized,
                    'scope' => $scope,
                    'reason' => $reason,
                    'contact_id' => $contactId,
                    'note' => $note,
                    'created_by' => $createdBy,
                ]);
                $this->audit->record('communication.suppression.added', $suppression, [
                    'channel' => $channel->value,
                    'scope' => $scope->value,
                    'reason' => $reason->value,
                ]);

                return $suppression;
            });
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new \RuntimeException('Suppression disappeared during creation.');
        }
    }

    /** Whether this kind of suppression may be lifted by hand. Complaints never are. */
    public function isLiftable(Suppression $suppression): bool
    {
        return in_array($suppression->reason, [SuppressionReason::Manual, SuppressionReason::Bounced, SuppressionReason::Invalid], true);
    }

    public function lift(Suppression $suppression): void
    {
        if (! $this->isLiftable($suppression)) {
            throw ValidationException::withMessages(['suppression' => __('This suppression cannot be removed by hand.')]);
        }

        DB::transaction(function () use ($suppression): void {
            $this->audit->record('communication.suppression.removed', $suppression, [
                'channel' => $suppression->channel->value,
                'scope' => $suppression->scope->value,
                'reason' => $suppression->reason->value,
            ]);
            $suppression->delete();
        });
    }

    /**
     * Remove a contact's own unsubscribe suppression, as part of a new opt-in.
     */
    public function liftUnsubscribe(Channel $channel, string $address): void
    {
        Suppression::query()
            ->where('channel', $channel->value)
            ->where('address', $address)
            ->where('scope', SuppressionScope::Marketing->value)
            ->where('reason', SuppressionReason::Unsubscribed->value)
            ->delete();
    }
}
