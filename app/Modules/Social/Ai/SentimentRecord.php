<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use App\Core\Ai\Text\Sentiment;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The sentiment of one audience comment. Keyed by account and the platform comment id; the comment text is
 * never stored (only its hash, so a changed comment is analysed again).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $external_comment_id
 * @property string $text_hash
 * @property Sentiment $sentiment
 * @property int|null $score_basis_points
 * @property string|null $language
 * @property string $prompt_version
 * @property Carbon|null $commented_at
 * @property Carbon $analysed_at
 */
class SentimentRecord extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_comment_sentiments';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'external_comment_id', 'text_hash', 'sentiment', 'score_basis_points', 'language',
        'prompt_version', 'commented_at', 'analysed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sentiment' => Sentiment::class,
            'score_basis_points' => 'integer',
            'commented_at' => 'datetime',
            'analysed_at' => 'datetime',
        ];
    }
}
