<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Telephony\Models\Extension;

/**
 * What a member's own browser needs to register their softphone: the PBX's secure WebSocket, their SIP address and
 * password, and the ICE servers. Given only to that member, on request, never in shared page props, never logged
 * (ADR 0036). Null when the workspace or the member is not set up.
 */
final class SoftphoneConfig
{
    public function __construct(private readonly TelephonySettings $settings) {}

    /** @return array{wssUrl: string, sipUri: string, authorizationUser: string, password: string, iceServers: list<array<string, string>>}|null */
    public function for(TenantMembership $member): ?array
    {
        $settings = $this->settings->current();
        $extension = Extension::query()->where('membership_id', $member->id)->first();

        if (! $settings->softphoneReady() || $extension === null || $extension->sip_password === null) {
            return null;
        }

        $ice = array_map(static fn (string $url): array => ['urls' => $url], $settings->stun_urls ?? []);

        if ($settings->turn_url !== null && $settings->turn_username !== null && $settings->turn_credential !== null) {
            $ice[] = ['urls' => $settings->turn_url, 'username' => $settings->turn_username, 'credential' => $settings->turn_credential];
        }

        return [
            'wssUrl' => (string) $settings->wss_url,
            'sipUri' => 'sip:'.$extension->extension.'@'.$settings->sip_domain,
            'authorizationUser' => $extension->extension,
            'password' => $extension->sip_password,
            'iceServers' => $ice,
        ];
    }
}
