<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $colour
 * @property bool $is_active
 * @property int $position
 */
class ProjectCategory extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_categories';

    protected $fillable = ['name', 'colour', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    public function auditLabel(): string
    {
        return $this->getAttribute('name');
    }
}
