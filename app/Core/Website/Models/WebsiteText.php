<?php

declare(strict_types=1);

namespace App\Core\Website\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use App\Core\Website\WebsiteTexts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of the website's words as the operator changed it, over its default in lang/{locale}/site*.php.
 * Written only through {@see WebsiteTexts}, which keeps the cache in step.
 *
 * @property int $id
 * @property string $locale
 * @property string $key
 * @property string $value
 * @property string|null $updated_by
 * @property Carbon $updated_at
 */
class WebsiteText extends Model
{
    use UsesCentralConnection;

    protected $fillable = ['locale', 'key', 'value', 'updated_by'];
}
