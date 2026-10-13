<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\ChannelNumberDirectory;
use App\Core\Communication\Contracts\ChannelNumberRegistry;
use App\Core\Communication\Data\ChannelNumber;
use App\Core\Communication\Data\ChannelNumberOutcome;
use App\Core\Communication\Data\MarketingChannel;
use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\ProviderRegistry;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\AccountClaim;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\ChannelPhoneNumber;
use App\Modules\Communication\Models\Message;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A workspace's business numbers on a channel (ADR 0022). Implements the core
 * {@see ChannelNumberDirectory} (read) and {@see ChannelNumberRegistry} (write).
 *
 * The model, in one paragraph: one connection (provider + credential reference) owns several numbers. The
 * connection's `settings.phone_number_id` always equals its DEFAULT number's account id, so everything written
 * before numbers existed (state checks, the connection form, tests) keeps working unchanged; the numbers table
 * is the source of truth and {@see self::ensure()} reconciles a connection whose setting was written directly.
 * Every number is claimed platform-wide in `communication_account_claims` (ADR 0016), keyed by its number id,
 * so no number can be used by two workspaces.
 */
final class ChannelNumbers implements ChannelNumberDirectory, ChannelNumberRegistry
{
    public const string ACCOUNT_KEY = 'phone_number_id';

    public const string DEFAULT_LABEL = 'Main number';

    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    // ---- Directory ---------------------------------------------------------------------------------------

    public function numbers(MarketingChannel $channel): array
    {
        $connection = $this->connectionFor($channel);

        if ($connection === null) {
            return [];
        }

        $this->ensure($connection);

        return ChannelPhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get()
            ->map(fn (ChannelPhoneNumber $number): ChannelNumber => $this->present($number))
            ->all();
    }

    public function default(MarketingChannel $channel): ?ChannelNumber
    {
        $number = $this->defaultRow($channel);

        return $number === null ? null : $this->present($number);
    }

    public function find(string $numberId): ?ChannelNumber
    {
        if (! $this->access->entitled()) {
            return null;
        }

        $number = ChannelPhoneNumber::query()->whereKey($numberId)->first();

        return $number === null ? null : $this->present($number);
    }

    // ---- Used inside the communication app --------------------------------------------------------------

    /**
     * The row a send goes out from. Null `$numberId` means the default. Null result when the channel has no such
     * usable number: the caller decides whether that is "no numbers at all" or "unavailable".
     */
    public function senderRow(MarketingChannel $channel, ?string $numberId): ?ChannelPhoneNumber
    {
        if ($numberId === null) {
            return $this->defaultRow($channel);
        }

        $connection = $this->connectionFor($channel);

        if ($connection === null) {
            return null;
        }

        return ChannelPhoneNumber::query()
            ->whereKey($numberId)
            ->where('connection_id', $connection->id)
            ->where('is_active', true)
            ->first();
    }

    /** Whether the channel's connection uses per-number accounts at all (a provider with a `phone_number_id`). */
    public function supportsNumbers(MarketingChannel $channel): bool
    {
        return $this->connectionFor($channel) !== null;
    }

    /** The row of an account id of THIS workspace's WhatsApp connection (for inbound events). */
    public function rowForAccount(string $accountId): ?ChannelPhoneNumber
    {
        return ChannelPhoneNumber::query()->where('phone_number_id', $accountId)->first();
    }

    /**
     * The webhook account guard: does an event's account value belong to this connection? For the phone number id
     * any number the workspace registered counts, active or not (receipts of earlier sends still arrive).
     */
    public function acceptsAccount(ChannelConnection $connection, string $key, string $value): bool
    {
        if ($key !== self::ACCOUNT_KEY || $connection->channel !== Channel::Whatsapp) {
            return ($connection->settings[$key] ?? null) === $value;
        }

        $this->ensure($connection);

        return ChannelPhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->where('phone_number_id', $value)
            ->exists();
    }

    /**
     * Reconcile the numbers table with the connection's `phone_number_id` setting: the setting names the default
     * number. Creates the first number of a legacy single-number connection, and follows a changed setting
     * (replacing the only number in place, or adding/promoting one in a multi-number workspace). Idempotent.
     */
    public function ensure(ChannelConnection $connection): void
    {
        if ($connection->channel !== Channel::Whatsapp) {
            return;
        }

        $value = $connection->settings[self::ACCOUNT_KEY] ?? null;

        if (! is_string($value) || preg_match('/^\d{5,32}$/', $value) !== 1) {
            return;
        }

        $default = ChannelPhoneNumber::query()->where('connection_id', $connection->id)->where('is_default', true)->first();

        if ($default !== null && $default->phone_number_id === $value) {
            return;
        }

        try {
            DB::transaction(function () use ($connection, $value): void {
                // Serialise on the connection row (the same lock registry changes take).
                ChannelConnection::query()->whereKey($connection->id)->lockForUpdate()->value('id');
                $numbers = ChannelPhoneNumber::query()->where('connection_id', $connection->id)->get();
                $current = $numbers->firstWhere('is_default', true);

                if ($current !== null && $current->phone_number_id === $value) {
                    return;
                }

                $existing = $numbers->firstWhere('phone_number_id', $value);

                if ($existing instanceof ChannelPhoneNumber) {
                    $existing->forceFill(['is_active' => true])->save();
                    $this->promote($existing, $connection);

                    return;
                }

                if ($numbers->count() === 1 && $current instanceof ChannelPhoneNumber) {
                    // The legacy form's "change the number" of a single-number workspace replaces it.
                    AccountClaim::query()->where('number_id', $current->id)->delete();
                    $current->forceFill(['phone_number_id' => $value])->save();
                    $this->link($current, $connection);

                    return;
                }

                $number = ChannelPhoneNumber::query()->create([
                    'connection_id' => $connection->id,
                    'channel' => Channel::Whatsapp->value,
                    'phone_number_id' => $value,
                    'label' => $this->freeLabel($numbers->count() === 0 ? self::DEFAULT_LABEL : 'Number '.$value),
                    'is_active' => true,
                    'is_default' => false,
                ]);
                $this->link($number, $connection);
                $this->promote($number, $connection);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request reconciled it first.
        }
    }

    // ---- Registry ----------------------------------------------------------------------------------------

    public function register(MarketingChannel $channel, string $accountId, string $label, ?string $displayNumber = null, bool $makeDefault = false): ChannelNumberOutcome
    {
        if (! $this->access->entitled()) {
            return ChannelNumberOutcome::refused('module_unavailable');
        }

        if ($channel !== MarketingChannel::Whatsapp) {
            return ChannelNumberOutcome::refused('unsupported_channel');
        }

        $connection = $this->connectionFor($channel);

        if ($connection === null) {
            return ChannelNumberOutcome::refused('not_connected');
        }

        if (! $this->providerHasNumbers($connection)) {
            return ChannelNumberOutcome::refused('provider_not_configured');
        }

        $accountId = trim($accountId);
        $label = trim($label);
        $display = $displayNumber === null ? null : trim($displayNumber);

        if (preg_match('/^\d{5,32}$/', $accountId) !== 1) {
            return ChannelNumberOutcome::refused('invalid_account_id');
        }

        if ($label === '' || mb_strlen($label) > 60 || ($display !== null && $display !== '' && preg_match('/^[0-9+() -]{3,32}$/', $display) !== 1)) {
            return ChannelNumberOutcome::refused('invalid_label');
        }

        $display = $display === '' ? null : $display;
        $this->ensure($connection);

        try {
            $number = DB::transaction(function () use ($connection, $accountId, $label, $display, $makeDefault): ChannelPhoneNumber|string {
                ChannelConnection::query()->whereKey($connection->id)->lockForUpdate()->value('id');

                if (ChannelPhoneNumber::query()->where('phone_number_id', $accountId)->exists()
                    || AccountClaim::query()->where('provider', $connection->provider)->where('account_key', self::ACCOUNT_KEY)->where('account_value', $accountId)->exists()) {
                    return 'account_in_use';
                }

                if (ChannelPhoneNumber::query()->where('label', $label)->exists()) {
                    return 'label_in_use';
                }

                $first = ! ChannelPhoneNumber::query()->where('connection_id', $connection->id)->exists();
                $number = ChannelPhoneNumber::query()->create([
                    'connection_id' => $connection->id,
                    'channel' => Channel::Whatsapp->value,
                    'phone_number_id' => $accountId,
                    'display_number' => $display,
                    'label' => $label,
                    'is_active' => true,
                    'is_default' => false,
                ]);

                if (! $this->link($number, $connection)) {
                    // The platform-wide registry says another workspace holds it: roll the row back.
                    throw new DomainException('account_in_use');
                }

                if ($first || $makeDefault) {
                    $this->promote($number, $connection);
                }

                $this->audit->record('communication.number.registered', $number, [
                    'channel' => Channel::Whatsapp->value,
                    'label' => $label,
                    'default' => $first || $makeDefault,
                ]);

                return $number;
            });
        } catch (UniqueConstraintViolationException|DomainException) {
            return ChannelNumberOutcome::refused('account_in_use');
        }

        if (is_string($number)) {
            return ChannelNumberOutcome::refused($number);
        }

        return ChannelNumberOutcome::ok($this->present($number->refresh()));
    }

    public function update(string $numberId, ?string $label = null, ?string $displayNumber = null, ?bool $active = null): ChannelNumberOutcome
    {
        if (! $this->access->entitled()) {
            return ChannelNumberOutcome::refused('module_unavailable');
        }

        $label = $label === null ? null : trim($label);
        $display = $displayNumber === null ? null : trim($displayNumber);

        if ($label !== null && ($label === '' || mb_strlen($label) > 60)) {
            return ChannelNumberOutcome::refused('invalid_label');
        }

        if ($display !== null && $display !== '' && preg_match('/^[0-9+() -]{3,32}$/', $display) !== 1) {
            return ChannelNumberOutcome::refused('invalid_label');
        }

        $result = DB::transaction(function () use ($numberId, $label, $display, $active): ChannelPhoneNumber|string {
            $number = ChannelPhoneNumber::query()->whereKey($numberId)->lockForUpdate()->first();

            if ($number === null) {
                return 'not_found';
            }

            if ($active === false && $number->is_default) {
                return 'is_default';
            }

            if ($label !== null && $label !== $number->label
                && ChannelPhoneNumber::query()->where('label', $label)->whereKeyNot($number->id)->exists()) {
                return 'label_in_use';
            }

            if ($label !== null) {
                $number->label = $label;
            }

            if ($display !== null) {
                $number->display_number = $display === '' ? null : $display;
            }

            if ($active !== null) {
                $number->is_active = $active;
            }

            $changes = array_keys($number->getDirty());

            if ($changes !== []) {
                $number->save();
                $this->audit->record('communication.number.updated', $number, ['fields' => $changes]);
            }

            return $number;
        });

        return is_string($result) ? ChannelNumberOutcome::refused($result) : ChannelNumberOutcome::ok($this->present($result));
    }

    public function makeDefault(string $numberId): ChannelNumberOutcome
    {
        if (! $this->access->entitled()) {
            return ChannelNumberOutcome::refused('module_unavailable');
        }

        $result = DB::transaction(function () use ($numberId): ChannelPhoneNumber|string {
            $number = ChannelPhoneNumber::query()->whereKey($numberId)->first();

            if ($number === null) {
                return 'not_found';
            }

            $connection = ChannelConnection::query()->whereKey($number->connection_id)->lockForUpdate()->first();

            if ($connection === null) {
                return 'not_found';
            }

            if (! $number->is_active) {
                return 'inactive';
            }

            if (! $number->is_default) {
                $this->promote($number, $connection);
                $this->audit->record('communication.number.default_changed', $number);
            }

            return $number->refresh();
        });

        return is_string($result) ? ChannelNumberOutcome::refused($result) : ChannelNumberOutcome::ok($this->present($result));
    }

    public function remove(string $numberId): ChannelNumberOutcome
    {
        if (! $this->access->entitled()) {
            return ChannelNumberOutcome::refused('module_unavailable');
        }

        $result = DB::transaction(function () use ($numberId): ChannelPhoneNumber|string {
            $number = ChannelPhoneNumber::query()->whereKey($numberId)->first();

            if ($number === null) {
                return 'not_found';
            }

            $connection = ChannelConnection::query()->whereKey($number->connection_id)->lockForUpdate()->first();

            if ($connection === null) {
                return 'not_found';
            }

            $others = ChannelPhoneNumber::query()->where('connection_id', $connection->id)->whereKeyNot($number->id)->exists();

            if ($number->is_default && $others) {
                return 'is_default';
            }

            if (Message::query()->where('sender_number_id', $number->id)->exists()) {
                return 'in_use';
            }

            $this->audit->record('communication.number.removed', $number, ['label' => $number->label]);
            $number->delete();

            if (! $others) {
                // The last number: the connection has no phone number id any more, so it is not configured.
                $settings = $connection->settings ?? [];
                unset($settings[self::ACCOUNT_KEY]);
                $connection->forceFill(['settings' => $settings === [] ? null : $settings])->save();
            }

            return $number;
        });

        return is_string($result) ? ChannelNumberOutcome::refused($result) : ChannelNumberOutcome::ok($this->present($result));
    }

    // ---- Internals ---------------------------------------------------------------------------------------

    private function connectionFor(MarketingChannel $channel): ?ChannelConnection
    {
        if ($channel !== MarketingChannel::Whatsapp || ! $this->access->entitled()) {
            return null;
        }

        $connection = ChannelConnection::query()->where('channel', Channel::Whatsapp->value)->first();

        return $connection !== null && $this->providerHasNumbers($connection) ? $connection : null;
    }

    private function providerHasNumbers(ChannelConnection $connection): bool
    {
        $provider = $this->providers->find($connection->channel, $connection->provider);

        return $provider instanceof ChannelProvider && $this->accountField($provider);
    }

    private function accountField(ChannelProvider $provider): bool
    {
        foreach ($provider->fields() as $field) {
            if ($field['key'] === self::ACCOUNT_KEY && ($field['account'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function defaultRow(MarketingChannel $channel): ?ChannelPhoneNumber
    {
        $connection = $this->connectionFor($channel);

        if ($connection === null) {
            return null;
        }

        $this->ensure($connection);

        return ChannelPhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Make `$number` the connection's default and mirror its account id into the legacy setting. Called inside a
     * transaction that holds the connection row lock.
     */
    private function promote(ChannelPhoneNumber $number, ChannelConnection $connection): void
    {
        ChannelPhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->whereKeyNot($number->id)
            ->where('is_default', true)
            ->update(['is_default' => false, 'default_key' => null]);

        $number->forceFill(['is_default' => true, 'default_key' => 1])->save();

        $settings = $connection->settings ?? [];

        if (($settings[self::ACCOUNT_KEY] ?? null) !== $number->phone_number_id) {
            $settings[self::ACCOUNT_KEY] = $number->phone_number_id;
            $connection->forceFill(['settings' => $settings])->save();
        }
    }

    /**
     * Hold the platform-wide claim of a number's account id (ADR 0016) and point it at the number row. False
     * when another connection holds it. An existing claim of this connection (the connection form's own) is
     * adopted, not duplicated.
     */
    private function link(ChannelPhoneNumber $number, ChannelConnection $connection): bool
    {
        $claim = AccountClaim::query()
            ->where('provider', $connection->provider)
            ->where('account_key', self::ACCOUNT_KEY)
            ->where('account_value', $number->phone_number_id)
            ->first();

        if ($claim !== null) {
            if ($claim->connection_id !== $connection->id) {
                return false;
            }

            if ($claim->number_id !== $number->id) {
                $claim->forceFill(['number_id' => $number->id])->save();
            }

            return true;
        }

        try {
            DB::transaction(static fn () => AccountClaim::query()->create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'provider' => $connection->provider,
                'account_key' => self::ACCOUNT_KEY,
                'account_value' => $number->phone_number_id,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function freeLabel(string $wanted): string
    {
        $label = mb_substr($wanted, 0, 60);
        $suffix = 2;

        while (ChannelPhoneNumber::query()->where('label', $label)->exists()) {
            $label = mb_substr($wanted, 0, 55).' '.$suffix++;
        }

        return $label;
    }

    private function present(ChannelPhoneNumber $number): ChannelNumber
    {
        return new ChannelNumber(
            $number->id,
            MarketingChannel::Whatsapp,
            $number->phone_number_id,
            $number->display_number,
            $number->label,
            $number->is_default,
            $number->is_active,
        );
    }
}
