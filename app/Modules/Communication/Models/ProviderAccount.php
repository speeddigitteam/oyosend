<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Enums\Channel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A messaging provider account an operator added in the Super Admin panel (an SMTP server, a Twilio account,
 * a WhatsApp Cloud API number). Platform data: central connection, no tenant scope.
 *
 * `secrets` is encrypted at rest and hidden from serialization. Only {@see CredentialResolver}
 * and the platform account manager read it; nothing returns it to a browser.
 *
 * @property string $id
 * @property Channel $channel
 * @property string $provider
 * @property string $name
 * @property bool $is_enabled
 * @property array<string, mixed>|null $settings Non-secret operator settings (host, sender, endpoint).
 * @property array<string, string>|null $secrets
 * @property list<string>|null $tenant_ids
 */
class ProviderAccount extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    public const string REF_PREFIX = 'acct_';

    protected $table = 'communication_provider_accounts';

    /** @var list<string> */
    protected $fillable = ['channel', 'provider', 'name', 'is_enabled', 'settings', 'secrets', 'tenant_ids'];

    /** @var list<string> */
    protected $hidden = ['secrets'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'tenant_ids' => 'array',
        ];
    }

    /** The name a workspace's `credential_ref` carries. */
    public function reference(): string
    {
        return self::REF_PREFIX.strtolower($this->id);
    }

    public static function idFromReference(string $reference): ?string
    {
        return preg_match('/^'.self::REF_PREFIX.'([0-9a-z]{26})$/', $reference, $match) === 1 ? strtoupper($match[1]) : null;
    }

    public function isAvailableTo(string $tenantId): bool
    {
        return $this->is_enabled && ($this->tenant_ids === null || in_array($tenantId, $this->tenant_ids, true));
    }
}
