<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

/**
 * Which notification categories a member wants in the center and by email.
 * A category with no saved preference follows `config('communication.category_defaults')`.
 */
final class NotificationPreferences
{
    /** @return list<string> */
    public function categories(): array
    {
        return array_values(array_map('strval', (array) config('communication.categories', [])));
    }

    /**
     * The matrix a member edits: every category with a boolean per channel.
     *
     * @return list<array{category: string, in_app: bool, email: bool}>
     */
    public function matrix(string $membershipId): array
    {
        $saved = $this->saved([$membershipId]);

        return array_map(fn (string $category): array => [
            'category' => $category,
            'in_app' => $saved[$membershipId][NotificationChannel::InApp->value][$category] ?? $this->default(NotificationChannel::InApp),
            'email' => $saved[$membershipId][NotificationChannel::Email->value][$category] ?? $this->default(NotificationChannel::Email),
        ], $this->categories());
    }

    /**
     * @param  array<string, array<string, bool>>  $values  category => [in_app => bool, email => bool]
     */
    public function update(string $membershipId, array $values): void
    {
        $known = $this->categories();

        DB::transaction(function () use ($membershipId, $values, $known): void {
            foreach ($values as $category => $channels) {
                if (! in_array($category, $known, true)) {
                    continue;
                }

                foreach (NotificationChannel::cases() as $channel) {
                    if (! array_key_exists($channel->value, $channels)) {
                        continue;
                    }

                    NotificationPreference::query()->updateOrCreate(
                        ['membership_id' => $membershipId, 'channel' => $channel->value, 'category' => $category],
                        ['enabled' => (bool) $channels[$channel->value]],
                    );
                }
            }
        });
    }

    public function default(NotificationChannel $channel): bool
    {
        return (bool) config('communication.category_defaults.'.$channel->value, $channel === NotificationChannel::InApp);
    }

    /**
     * Saved preferences for several members at once.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, array<string, array<string, bool>>> membership => channel => category => enabled
     */
    public function saved(array $membershipIds): array
    {
        $saved = [];

        NotificationPreference::query()->whereIn('membership_id', $membershipIds)->get()
            ->each(function (NotificationPreference $preference) use (&$saved): void {
                $saved[$preference->membership_id][$preference->channel][$preference->category] = $preference->enabled;
            });

        return $saved;
    }
}
