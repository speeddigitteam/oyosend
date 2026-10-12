<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Enums\AttemptOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One try at publishing a target, kept so a failed post can be explained.
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property string $tenant_id
 * @property string $target_id
 * @property int $attempt
 * @property AttemptOutcome $outcome
 * @property string|null $error_code
 * @property string|null $message
 */
class PublishAttempt extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_publish_attempts';

    /** @var list<string> */
    protected $fillable = ['target_id', 'attempt', 'outcome', 'error_code', 'message'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['outcome' => AttemptOutcome::class, 'attempt' => 'integer'];
    }
}
