<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Telephony\Enums\PbxDriver;
use App\Modules\Telephony\Models\TelephonySetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The workspace's PBX connection. Secrets are write-only: an empty value on save keeps the stored one, and nothing
 * here ever returns a secret except the webhook secret, once, at the moment it is generated.
 */
final class TelephonySettings
{
    /** Fields that hold a secret: kept when the form sends them empty. */
    public const array SECRETS = ['api_secret', 'turn_credential'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function current(): TelephonySetting
    {
        $existing = TelephonySetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return TelephonySetting::query()->create(['driver' => PbxDriver::Asterisk])->refresh();
        } catch (UniqueConstraintViolationException) {
            return TelephonySetting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): TelephonySetting
    {
        foreach (self::SECRETS as $secret) {
            if (($attributes[$secret] ?? null) === null || $attributes[$secret] === '') {
                unset($attributes[$secret]);
            }
        }

        return DB::transaction(function () use ($attributes): TelephonySetting {
            $settings = $this->current();
            $settings->fill($attributes);
            $changed = array_keys($settings->getDirty());

            if ($changed !== []) {
                $settings->save();
                // The names of the changed fields only, never a value.
                $this->audit->record('telephony.settings.saved', $settings, ['changed' => $changed]);
            }

            return $settings;
        });
    }

    /** A new webhook signing secret. The plain value is returned this once, to be entered on the PBX. */
    public function rotateWebhookSecret(): string
    {
        $secret = Str::random(48);

        DB::transaction(function () use ($secret): void {
            $settings = $this->current();
            $settings->webhook_secret = $secret;
            $settings->save();
            $this->audit->record('telephony.webhook_secret.rotated', $settings);
        });

        return $secret;
    }

    /**
     * What the settings page may show: every plain field, and for each secret only whether one is stored.
     *
     * @return array<string, mixed>
     */
    public function present(TelephonySetting $settings): array
    {
        return [
            'driver' => $settings->driver->value,
            'wssUrl' => $settings->wss_url,
            'sipDomain' => $settings->sip_domain,
            'apiUrl' => $settings->api_url,
            'apiUsername' => $settings->api_username,
            'hasApiSecret' => $settings->api_secret !== null,
            'originationContext' => $settings->origination_context,
            'callerId' => $settings->caller_id,
            'stunUrls' => $settings->stun_urls ?? [],
            'turnUrl' => $settings->turn_url,
            'turnUsername' => $settings->turn_username,
            'hasTurnCredential' => $settings->turn_credential !== null,
            'hasWebhookSecret' => $settings->webhook_secret !== null,
            'softphoneReady' => $settings->softphoneReady(),
            'bridgeReady' => $settings->bridgeReady(),
        ];
    }
}
