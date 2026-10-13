<?php

declare(strict_types=1);

namespace App\Core\Billing\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment gateway's credentials an operator set in the Super Admin panel
 * (docs/adr/0028-operator-managed-integration-credentials.md). Platform data: central
 * connection, no tenant scope, one row per compiled gateway key.
 *
 * `secrets` is encrypted at rest and hidden from serialization. Only the gateway credential
 * resolver and the platform settings manager read it; nothing returns it to a browser or a log.
 *
 * @property string $id
 * @property string $gateway
 * @property bool $is_enabled
 * @property array<string, mixed>|null $settings Non-secret operator settings (sandbox).
 * @property array<string, string>|null $secrets
 */
class PaymentGatewayAccount extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    protected $table = 'payment_gateway_accounts';

    /** @var list<string> */
    protected $fillable = ['gateway', 'is_enabled', 'settings', 'secrets'];

    /** @var list<string> */
    protected $hidden = ['secrets'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'secrets' => 'encrypted:array',
        ];
    }
}
