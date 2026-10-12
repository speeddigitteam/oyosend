<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int $scale_max
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewForm extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_review_forms';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'scale_max', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scale_max' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ReviewFormItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReviewFormItem::class, 'form_id')->orderBy('position');
    }

    public function auditLabel(): string
    {
        return (string) ($this->name);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.review_form.'.$verb;
    }
}
