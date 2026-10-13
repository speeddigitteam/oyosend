<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Operator-set credentials for a platform-wide integration (the Social platform apps and the Meta
 * Ads app) typed into the Super Admin panel
 * (docs/adr/0029-social-and-meta-ads-panel-credentials.md, following ADR 0028). Platform data:
 * central connection, no tenant scope, one row per (group, name).
 *
 * `secrets` is encrypted at rest and hidden from serialization. Only the config overlay
 * (App\Core\Settings\IntegrationCredentials) reads it, to overlay the live config repository at
 * boot; nothing returns it to a browser or a log. `settings` holds the non-secret public
 * identifiers (client_id / app_id), which may be shown back to the operator.
 *
 * @property string $id
 * @property string $group
 * @property string $name
 * @property bool $is_enabled
 * @property array<string, mixed>|null $settings Non-secret operator settings (public ids).
 * @property array<string, string>|null $secrets
 */
class IntegrationCredential extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    protected $table = 'integration_credentials';

    /** @var list<string> */
    protected $fillable = ['group', 'name', 'is_enabled', 'settings', 'secrets'];

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
