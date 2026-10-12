<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\RatingParty;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $review_id
 * @property string $item_id
 * @property RatingParty $party
 * @property int $rating
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewRating extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_review_ratings';

    /** @var list<string> */
    protected $fillable = ['review_id', 'item_id', 'party', 'rating', 'comment'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'party' => RatingParty::class,
            'rating' => 'integer',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class, 'review_id');
    }

    /** @return BelongsTo<ReviewFormItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ReviewFormItem::class, 'item_id');
    }
}
