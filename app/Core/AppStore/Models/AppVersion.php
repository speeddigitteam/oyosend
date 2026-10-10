<?php

declare(strict_types=1);

namespace App\Core\AppStore\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable published description of one compiled first-party module version.
 *
 * @property string $id
 * @property string $app_id
 * @property string $version
 * @property string $compatibility
 * @property list<string> $features
 * @property list<string> $screenshots
 * @property list<array{key: string, name: string, type: string, currency: string|null, amount: int|null, trial_days: int, public: bool}> $offers
 * @property list<string> $dependencies
 * @property list<array{name: string, group: string, scopable: bool, sensitive: bool}> $permissions
 * @property list<string> $routes
 * @property list<array{key: string, job: class-string, frequency: string}> $scheduled_jobs
 * @property list<array{key: string, label: string, type: string, required: bool, description: string|null, default: string|int|bool|null, options: array<string, string>, min: int|null, max: int|null, max_length: int}> $settings
 * @property string|null $release_notes
 * @property string $manifest_hash
 * @property Carbon|null $published_at
 */
class AppVersion extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    /** @var list<string> */
    protected $fillable = [
        'app_id', 'version', 'compatibility', 'features', 'screenshots', 'offers', 'dependencies',
        'permissions', 'routes', 'scheduled_jobs', 'settings', 'release_notes', 'manifest_hash', 'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'screenshots' => 'array',
            'offers' => 'array',
            'dependencies' => 'array',
            'permissions' => 'array',
            'routes' => 'array',
            'scheduled_jobs' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CatalogApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }
}
