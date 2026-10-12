<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string $name
 * @property string|null $description
 * @property int $weight_bp
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewFormItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_review_form_items';

    /** @var list<string> */
    protected $fillable = ['form_id', 'name', 'description', 'weight_bp', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight_bp' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<ReviewForm, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(ReviewForm::class, 'form_id');
    }
}
