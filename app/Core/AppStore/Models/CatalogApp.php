<?php

declare(strict_types=1);

namespace App\Core\AppStore\Models;

use App\Core\AppStore\Enums\AppCategory;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The database catalogue entry for a first-party module compiled into the build.
 *
 * @property string $id
 * @property string $module_key
 * @property string $name
 * @property string $description
 * @property AppCategory $category
 * @property string $icon
 * @property bool $is_featured
 * @property bool $is_popular
 * @property bool $is_published
 * @property string|null $current_version_id
 * @property-read AppVersion|null $currentVersion
 */
class CatalogApp extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    protected $table = 'apps';

    /** @var list<string> */
    protected $fillable = [
        'module_key', 'name', 'description', 'category', 'icon', 'is_featured', 'is_popular',
        'is_published', 'current_version_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => AppCategory::class,
            'is_featured' => 'boolean',
            'is_popular' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    /** @return BelongsTo<AppVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'current_version_id');
    }

    /** @return HasMany<AppVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AppVersion::class, 'app_id');
    }

    /** @return HasMany<AppInstallation, $this> */
    public function installations(): HasMany
    {
        return $this->hasMany(AppInstallation::class, 'app_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
