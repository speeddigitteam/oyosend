<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\OfferStatus;
use App\Modules\Talent\Events\OfferAccepted;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Offer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offer tracking only: an amount, a proposed start date and where the offer stands. Oyosend does not
 * generate or sign employment contracts.
 */
final class OfferManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TalentSettings $settings,
    ) {}

    /** @param array{amount?: int|string|null, currency?: string|null, proposed_start_on?: string|null, expires_on?: string|null, notes?: string|null} $attributes */
    public function create(Application $application, array $attributes, ?TenantMembership $actor): Offer
    {
        return DB::transaction(function () use ($application, $attributes, $actor): Offer {
            /** @var Application $locked */
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ApplicationStatus::Open) {
                throw ValidationException::withMessages(['status' => __('Offers can only be made on an open application.')]);
            }

            $offer = new Offer([...$this->values($attributes), 'application_id' => $locked->id, 'status' => OfferStatus::Draft, 'live_marker' => 1, 'created_by' => $actor?->id]);

            try {
                $offer->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['status' => __('This application already has a live offer.')]);
            }

            return $offer;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Offer $offer, array $attributes): Offer
    {
        if ($offer->status !== OfferStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('Only a draft offer can be edited.')]);
        }

        $offer->fill($this->values($attributes))->save();

        return $offer;
    }

    public function transition(Offer $offer, OfferStatus $to, ?TenantMembership $actor): Offer
    {
        return DB::transaction(function () use ($offer, $to, $actor): Offer {
            /** @var Offer $locked */
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $application = Application::query()->findOrFail($locked->application_id);

            $allowed = match ($from) {
                OfferStatus::Draft => [OfferStatus::Sent, OfferStatus::Withdrawn],
                OfferStatus::Sent => [OfferStatus::Accepted, OfferStatus::Declined, OfferStatus::Withdrawn],
                OfferStatus::Accepted => [OfferStatus::Withdrawn],
                OfferStatus::Declined, OfferStatus::Withdrawn => [],
            };

            if (! in_array($to, $allowed, true)) {
                throw ValidationException::withMessages(['status' => __('That offer change is not allowed from the current status.')]);
            }

            if ($application->status !== ApplicationStatus::Open) {
                throw ValidationException::withMessages(['status' => __('The application is already finished.')]);
            }

            if ($to === OfferStatus::Sent) {
                $locked->sent_at = now();
            }

            if (in_array($to, [OfferStatus::Accepted, OfferStatus::Declined, OfferStatus::Withdrawn], true)) {
                $locked->decided_at = now();
            }

            if (in_array($to, [OfferStatus::Declined, OfferStatus::Withdrawn], true)) {
                $locked->live_marker = null;
            }

            $locked->status = $to;
            $locked->save();
            $this->audit->record('talent.offer.status_changed', $locked, ['from' => $from->value, 'to' => $to->value], $actor?->user);

            if ($to === OfferStatus::Accepted) {
                OfferAccepted::dispatch([
                    'offer_id' => $locked->id, 'application_id' => $locked->application_id, 'amount' => $locked->amount, 'currency' => $locked->currency,
                ]);
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function values(array $attributes): array
    {
        $amount = $attributes['amount'] ?? null;
        $hasAmount = $amount !== null && $amount !== '';

        return [
            'amount' => $hasAmount ? (int) $amount : null,
            'currency' => $hasAmount ? strtoupper((string) ($attributes['currency'] ?? $this->settings->currency())) : null,
            'proposed_start_on' => $attributes['proposed_start_on'] ?? null,
            'expires_on' => $attributes['expires_on'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ];
    }
}
