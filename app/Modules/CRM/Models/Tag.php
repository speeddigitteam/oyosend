<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string|null $colour
 */
class Tag extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_tags';

    protected $fillable = ['name', 'colour'];

    /** @return HasMany<Taggable, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Taggable::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
